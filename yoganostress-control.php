<?php
/**
 * Plugin Name: Yoganostress Control Bridge
 * Description: Zero-cost, allowlisted GitHub command bridge for safe Yoganostress maintenance.
 * Version: 1.1.0
 * Author: Yoganostress
 */

if (!defined('ABSPATH')) { exit; }

define('YNS_CONTROL_VERSION', '1.1.0');
define('YNS_CONTROL_MANIFEST', 'https://raw.githubusercontent.com/VitoPerillo/VitoPerillo.github.io/yoganostress-control/command.json');
define('YNS_CONTROL_SOURCE', 'https://raw.githubusercontent.com/VitoPerillo/VitoPerillo.github.io/yoganostress-control/yoganostress-control.php');
define('YNS_CONTROL_SITE', 'https://www.yoganostress.it');

function yns_control_safe_result($action_id, $op, $ok, $message, $data = array()) {
    return array(
        'id' => sanitize_key((string)$action_id),
        'op' => sanitize_key((string)$op),
        'ok' => (bool)$ok,
        'message' => sanitize_text_field((string)$message),
        'data' => is_array($data) ? $data : array(),
    );
}

function yns_control_normalize_path($path) {
    $path = '/' . ltrim((string)$path, '/');
    $path = strtok($path, '?');
    return $path ?: '/';
}

function yns_control_target_allowed($target) {
    if (!is_string($target) || $target === '') return false;
    if (str_starts_with($target, '/')) return true;
    $parts = wp_parse_url($target);
    if (!$parts || empty($parts['host'])) return false;
    return in_array(strtolower($parts['host']), array('yoganostress.it','www.yoganostress.it'), true);
}

function yns_control_redirects() {
    $map = get_option('yns_control_redirects', array());
    return is_array($map) ? $map : array();
}

add_action('template_redirect', function () {
    if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
    $path = yns_control_normalize_path($_SERVER['REQUEST_URI'] ?? '/');
    $map = yns_control_redirects();
    if (empty($map[$path]) || !is_array($map[$path])) return;
    $target = $map[$path]['target'] ?? '';
    $status = (int)($map[$path]['status'] ?? 301);
    if (!yns_control_target_allowed($target) || !in_array($status, array(301,302,307,308), true)) return;
    wp_safe_redirect($target, $status, 'Yoganostress Control Bridge');
    exit;
}, 1);

function yns_control_book_meta_snapshot($post_id) {
    $p = get_post($post_id);
    if (!$p || $p->post_type !== 'libro') return new WP_Error('not_libro','Libro not found');
    $all = get_post_meta($post_id);
    $out = array();
    foreach ($all as $key => $values) {
        if (strpos((string)$key, 'ybc_') !== 0) continue;
        if (preg_match('/(secret|token|password|passwd|auth|nonce|api[_-]?key|private|credential)/i', (string)$key)) continue;
        $raw = isset($values[0]) ? $values[0] : '';
        $value = maybe_unserialize($raw);
        if (is_array($value) || is_object($value)) {
            $value = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $value = (string)$value;
        }
        if (strlen($value) > 8000) $value = substr($value,0,8000).'...[truncated]';
        $out[(string)$key] = $value;
    }
    ksort($out);
    return $out;
}

function yns_control_apply_action($a) {
    $id = $a['id'] ?? '';
    $op = sanitize_key($a['op'] ?? '');

    if (!$id || !$op) return yns_control_safe_result($id, $op, false, 'Missing id/op');

    if ($op === 'trash_post') {
        $post_id = absint($a['post_id'] ?? 0);
        $p = get_post($post_id);
        if (!$p || $p->post_type === 'revision') return yns_control_safe_result($id,$op,false,'Post not found');
        if ($p->post_status === 'trash') return yns_control_safe_result($id,$op,true,'Already trashed',array('post_id'=>$post_id));
        $r = wp_trash_post($post_id);
        return yns_control_safe_result($id,$op,(bool)$r,$r?'Moved to trash':'Trash failed',array('post_id'=>$post_id));
    }

    if ($op === 'set_draft') {
        $post_id = absint($a['post_id'] ?? 0);
        $p = get_post($post_id);
        if (!$p || $p->post_type === 'revision') return yns_control_safe_result($id,$op,false,'Post not found');
        $r = wp_update_post(array('ID'=>$post_id,'post_status'=>'draft'), true);
        $ok = !is_wp_error($r);
        return yns_control_safe_result($id,$op,$ok,$ok?'Set to draft':$r->get_error_message(),array('post_id'=>$post_id));
    }

    if ($op === 'replace_post_text') {
        $post_id = absint($a['post_id'] ?? 0);
        $field = sanitize_key($a['field'] ?? 'post_content');
        if (!in_array($field,array('post_content','post_excerpt','post_title'),true)) return yns_control_safe_result($id,$op,false,'Field not allowed');
        $old = (string)($a['old'] ?? '');
        $new = (string)($a['new'] ?? '');
        $replace_all = !empty($a['replace_all']);
        if ($old === '') return yns_control_safe_result($id,$op,false,'Empty old text');
        $p = get_post($post_id);
        if (!$p) return yns_control_safe_result($id,$op,false,'Post not found');
        $value = (string)$p->$field;
        $count = substr_count($value,$old);
        if ($count === 0) return yns_control_safe_result($id,$op,false,'Text not found',array('matches'=>0));
        if (!$replace_all && $count !== 1) return yns_control_safe_result($id,$op,false,'Expected exactly one match',array('matches'=>$count));
        $updated = $replace_all ? str_replace($old,$new,$value,$replaced) : preg_replace('/'.preg_quote($old,'/').'/',str_replace('$','\\$', $new),$value,1,$replaced);
        $r = wp_update_post(array('ID'=>$post_id,$field=>$updated), true);
        $ok = !is_wp_error($r);
        return yns_control_safe_result($id,$op,$ok,$ok?'Text replaced':$r->get_error_message(),array('post_id'=>$post_id,'replaced'=>(int)$replaced));
    }

    if ($op === 'redirect_set') {
        $from = yns_control_normalize_path($a['from'] ?? '');
        $target = (string)($a['target'] ?? '');
        $status = (int)($a['status'] ?? 301);
        if ($from === '/' || !yns_control_target_allowed($target) || !in_array($status,array(301,302,307,308),true)) return yns_control_safe_result($id,$op,false,'Invalid redirect');
        $map = yns_control_redirects();
        $map[$from] = array('target'=>$target,'status'=>$status,'updated'=>gmdate('c'));
        update_option('yns_control_redirects',$map,false);
        return yns_control_safe_result($id,$op,true,'Redirect saved',array('from'=>$from,'target'=>$target,'status'=>$status));
    }

    if ($op === 'redirect_remove') {
        $from = yns_control_normalize_path($a['from'] ?? '');
        $map = yns_control_redirects();
        if (isset($map[$from])) { unset($map[$from]); update_option('yns_control_redirects',$map,false); }
        return yns_control_safe_result($id,$op,true,'Redirect removed',array('from'=>$from));
    }

    if ($op === 'media_trash') {
        $post_id = absint($a['attachment_id'] ?? 0);
        $p = get_post($post_id);
        if (!$p || $p->post_type !== 'attachment') return yns_control_safe_result($id,$op,false,'Attachment not found');
        $r = wp_trash_post($post_id);
        return yns_control_safe_result($id,$op,(bool)$r,$r?'Attachment moved to trash':'Trash failed',array('attachment_id'=>$post_id));
    }

    if ($op === 'book_meta_list') {
        $post_id = absint($a['post_id'] ?? 0);
        $snapshot = yns_control_book_meta_snapshot($post_id);
        if (is_wp_error($snapshot)) return yns_control_safe_result($id,$op,false,$snapshot->get_error_message(),array('post_id'=>$post_id));
        return yns_control_safe_result($id,$op,true,'Book meta listed',array('post_id'=>$post_id,'meta'=>$snapshot));
    }

    if ($op === 'cache_flush') {
        $ok = wp_cache_flush();
        return yns_control_safe_result($id,$op,(bool)$ok,'Object cache flush requested');
    }

    if ($op === 'self_update') {
        $expected = strtolower((string)($a['sha256'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/',$expected)) return yns_control_safe_result($id,$op,false,'Invalid SHA256');
        $r = wp_remote_get(YNS_CONTROL_SOURCE,array('timeout'=>15,'redirection'=>2,'user-agent'=>'Yoganostress-Control/'.YNS_CONTROL_VERSION));
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) return yns_control_safe_result($id,$op,false,'Source fetch failed');
        $body = wp_remote_retrieve_body($r);
        if (hash('sha256',$body) !== $expected || strpos($body,'Plugin Name: Yoganostress Control Bridge') === false) return yns_control_safe_result($id,$op,false,'Source verification failed');
        $file = __FILE__;
        if (!is_writable($file)) return yns_control_safe_result($id,$op,false,'Plugin file not writable');
        $tmp = $file.'.tmp';
        if (file_put_contents($tmp,$body,LOCK_EX) === false) return yns_control_safe_result($id,$op,false,'Temp write failed');
        if (!@rename($tmp,$file)) { @unlink($tmp); return yns_control_safe_result($id,$op,false,'Atomic replace failed'); }
        return yns_control_safe_result($id,$op,true,'Bridge updated',array('sha256'=>$expected));
    }

    return yns_control_safe_result($id,$op,false,'Operation not allowed');
}

function yns_control_poll_and_apply() {
    if (get_transient('yns_control_lock')) return array('status'=>'locked');
    set_transient('yns_control_lock',1,60);

    $last = (int)get_option('yns_control_last_revision',0);
    $r = wp_remote_get(YNS_CONTROL_MANIFEST,array('timeout'=>15,'redirection'=>2,'user-agent'=>'Yoganostress-Control/'.YNS_CONTROL_VERSION,'headers'=>array('Cache-Control'=>'no-cache')));
    if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) {
        delete_transient('yns_control_lock');
        return array('status'=>'fetch_failed','last_revision'=>$last);
    }
    $m = json_decode(wp_remote_retrieve_body($r),true);
    if (!is_array($m) || ($m['site'] ?? '') !== YNS_CONTROL_SITE || !isset($m['revision']) || !is_int($m['revision']) || !isset($m['actions']) || !is_array($m['actions'])) {
        delete_transient('yns_control_lock');
        return array('status'=>'invalid_manifest','last_revision'=>$last);
    }
    $rev = (int)$m['revision'];
    if ($rev <= $last) {
        delete_transient('yns_control_lock');
        return array('status'=>'up_to_date','last_revision'=>$last);
    }
    if (!empty($m['expires_at']) && strtotime((string)$m['expires_at']) < time()) {
        delete_transient('yns_control_lock');
        return array('status'=>'expired','last_revision'=>$last,'manifest_revision'=>$rev);
    }
    if (count($m['actions']) > 25) {
        delete_transient('yns_control_lock');
        return array('status'=>'too_many_actions','last_revision'=>$last,'manifest_revision'=>$rev);
    }

    $seen=array(); $results=array();
    foreach ($m['actions'] as $a) {
        $aid = sanitize_key((string)($a['id'] ?? ''));
        if (!$aid || isset($seen[$aid])) { $results[]=yns_control_safe_result($aid,$a['op']??'',false,'Duplicate/missing action id'); continue; }
        $seen[$aid]=1;
        $results[]=yns_control_apply_action($a);
    }

    update_option('yns_control_last_revision',$rev,false);
    update_option('yns_control_last_result',array('revision'=>$rev,'applied_at'=>gmdate('c'),'results'=>$results),false);
    delete_transient('yns_control_lock');
    return array('status'=>'applied','revision'=>$rev,'results'=>$results);
}

add_action('rest_api_init', function () {
    register_rest_route('yns-control/v1','/status',array(
        'methods'=>'GET',
        'permission_callback'=>'__return_true',
        'callback'=>function(){
            $run = yns_control_poll_and_apply();
            $last = get_option('yns_control_last_result',array());
            return rest_ensure_response(array(
                'ok'=>true,
                'bridge_version'=>YNS_CONTROL_VERSION,
                'site'=>YNS_CONTROL_SITE,
                'last_revision'=>(int)get_option('yns_control_last_revision',0),
                'poll_status'=>$run['status'] ?? 'unknown',
                'last_result'=>$last,
                'redirect_count'=>count(yns_control_redirects())
            ));
        }
    ));
});

add_filter('cron_schedules', function($s){
    if (!isset($s['yns_control_5m'])) $s['yns_control_5m']=array('interval'=>300,'display'=>'YNS Control every 5 minutes');
    return $s;
});
add_action('yns_control_cron','yns_control_poll_and_apply');

register_activation_hook(__FILE__, function(){
    if (!wp_next_scheduled('yns_control_cron')) wp_schedule_event(time()+60,'yns_control_5m','yns_control_cron');
});
register_deactivation_hook(__FILE__, function(){
    $ts=wp_next_scheduled('yns_control_cron');
    if ($ts) wp_unschedule_event($ts,'yns_control_cron');
});
