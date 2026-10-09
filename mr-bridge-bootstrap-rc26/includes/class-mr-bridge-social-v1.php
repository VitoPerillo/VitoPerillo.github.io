<?php
if (!defined('ABSPATH')) { exit; }

final class MR_Bridge_Social_V1 {
    const QUEUE_OPTION = 'mr_bridge_social_queue';
    const LOG_OPTION = 'mr_bridge_social_audit_log';
    const SETTINGS_OPTION = 'mr_bridge_social_settings';
    const DAILY_OPTION = 'mr_bridge_social_daily_counts';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        $perm = array('MR_Bridge', 'can_manage');
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/social/status', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'status'), 'permission_callback' => $perm,
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/social/settings', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'settings_get'), 'permission_callback' => $perm),
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'settings_update'), 'permission_callback' => $perm),
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/social/redteam', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'redteam_endpoint'), 'permission_callback' => $perm,
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/social/queue', array(
            array('methods' => 'GET', 'callback' => array(__CLASS__, 'queue_get'), 'permission_callback' => $perm),
            array('methods' => 'POST', 'callback' => array(__CLASS__, 'queue_add'), 'permission_callback' => $perm),
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/social/queue/batch', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'queue_batch'), 'permission_callback' => $perm,
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/social/dispatch', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'dispatch'), 'permission_callback' => $perm,
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/social/audit', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'audit'), 'permission_callback' => $perm,
        ));
    }

    private static function defaults() {
        return array(
            'enabled' => true,
            'dry_run' => true,
            'min_daily_comments' => 7,
            'max_daily_comments' => 15,
            'allowed_networks' => array('facebook', 'instagram'),
            'max_comment_length' => 500,
            'dispatch_mode' => 'adapter_only',
        );
    }

    private static function settings() {
        $settings = get_option(self::SETTINGS_OPTION, array());
        if (!is_array($settings)) { $settings = array(); }
        return array_merge(self::defaults(), $settings);
    }

    private static function log_event($action, $item = array(), $meta = array()) {
        $log = get_option(self::LOG_OPTION, array());
        if (!is_array($log)) { $log = array(); }
        $target_url = isset($item['target_url']) ? esc_url_raw($item['target_url']) : '';
        $log[] = array(
            'time' => current_time('mysql', true),
            'user_id' => get_current_user_id(),
            'action' => sanitize_key($action),
            'item_id' => isset($item['id']) ? sanitize_text_field($item['id']) : '',
            'network' => isset($item['network']) ? sanitize_key($item['network']) : '',
            'target_host' => $target_url ? strtolower((string) wp_parse_url($target_url, PHP_URL_HOST)) : '',
            'comment' => isset($item['comment']) ? sanitize_textarea_field($item['comment']) : '',
            'meta' => is_array($meta) ? $meta : array(),
        );
        if (count($log) > 200) { $log = array_slice($log, -200); }
        update_option(self::LOG_OPTION, $log, false);
        MR_Bridge::log('social_' . sanitize_key($action), array(
            'item_id' => isset($item['id']) ? sanitize_text_field($item['id']) : '',
            'network' => isset($item['network']) ? sanitize_key($item['network']) : '',
        ));
    }

    public static function status() {
        $settings = self::settings();
        $queue = get_option(self::QUEUE_OPTION, array());
        if (!is_array($queue)) { $queue = array(); }
        $counts = array('ready' => 0, 'sent' => 0, 'failed' => 0);
        foreach ($queue as $item) {
            $s = isset($item['status']) ? $item['status'] : '';
            if (isset($counts[$s])) { $counts[$s]++; }
        }
        $date_key = gmdate('Y-m-d');
        $daily = get_option(self::DAILY_OPTION, array());
        if (!is_array($daily)) { $daily = array(); }
        return rest_ensure_response(array(
            'ok' => true,
            'settings' => $settings,
            'queue' => $counts,
            'sent_today' => isset($daily[$date_key]) ? (int) $daily[$date_key] : 0,
            'adapter_available' => (bool) has_filter('mr_bridge_social_comment_dispatch'),
            'dispatch_policy' => 'trusted_adapter_only',
        ));
    }

    public static function settings_get() {
        return rest_ensure_response(array('settings' => self::settings()));
    }

    public static function settings_update(WP_REST_Request $request) {
        $current = self::settings();
        $params = (array) $request->get_json_params();
        if (array_key_exists('enabled', $params)) { $current['enabled'] = rest_sanitize_boolean($params['enabled']); }
        if (array_key_exists('dry_run', $params)) { $current['dry_run'] = rest_sanitize_boolean($params['dry_run']); }
        if (array_key_exists('min_daily_comments', $params)) { $current['min_daily_comments'] = max(1, min(15, absint($params['min_daily_comments']))); }
        if (array_key_exists('max_daily_comments', $params)) { $current['max_daily_comments'] = max(1, min(15, absint($params['max_daily_comments']))); }
        if ($current['min_daily_comments'] > $current['max_daily_comments']) {
            return new WP_Error('mr_social_invalid_limits', 'min_daily_comments cannot exceed max_daily_comments.', array('status' => 400));
        }
        if (isset($params['allowed_networks']) && is_array($params['allowed_networks'])) {
            $allowed = array();
            foreach ($params['allowed_networks'] as $network) {
                $network = sanitize_key($network);
                if (in_array($network, array('facebook', 'instagram'), true)) { $allowed[] = $network; }
            }
            $current['allowed_networks'] = array_values(array_unique($allowed));
        }
        update_option(self::SETTINGS_OPTION, $current, false);
        self::log_event('settings_updated', array(), array(
            'enabled' => $current['enabled'], 'dry_run' => $current['dry_run'],
            'min_daily_comments' => $current['min_daily_comments'], 'max_daily_comments' => $current['max_daily_comments'],
        ));
        return rest_ensure_response(array('settings' => $current));
    }

    private static function target_allowed($target_url, $network) {
        $target_url = esc_url_raw($target_url);
        if (!$target_url || strtolower((string) wp_parse_url($target_url, PHP_URL_SCHEME)) !== 'https') { return false; }
        $host = strtolower((string) wp_parse_url($target_url, PHP_URL_HOST));
        $allowed_hosts = array('facebook.com','www.facebook.com','m.facebook.com','instagram.com','www.instagram.com');
        if (!in_array($host, $allowed_hosts, true)) { return false; }
        if ($network === 'facebook' && strpos($host, 'facebook.com') === false) { return false; }
        if ($network === 'instagram' && strpos($host, 'instagram.com') === false) { return false; }
        return true;
    }

    private static function normalize_comment($comment) {
        $comment = trim(wp_strip_all_tags((string) $comment));
        return (string) preg_replace('/\s+/u', ' ', $comment);
    }

    private static function is_duplicate($comment) {
        $fingerprint = hash('sha256', mb_strtolower(self::normalize_comment($comment), 'UTF-8'));
        $queue = get_option(self::QUEUE_OPTION, array());
        if (!is_array($queue)) { return false; }
        foreach ($queue as $item) {
            if (isset($item['fingerprint']) && hash_equals($item['fingerprint'], $fingerprint)) { return true; }
        }
        return false;
    }

    private static function redteam($network, $target_url, $comment) {
        $settings = self::settings();
        $network = sanitize_key($network);
        $comment = self::normalize_comment($comment);
        $reasons = array(); $warnings = array(); $score = 100;
        if (!in_array($network, $settings['allowed_networks'], true)) { $reasons[] = 'network_not_allowed'; }
        if (!self::target_allowed($target_url, $network)) { $reasons[] = 'target_not_allowed'; }
        $length = mb_strlen($comment, 'UTF-8');
        if ($length < 20) { $reasons[] = 'comment_too_short'; }
        if ($length > (int) $settings['max_comment_length']) { $reasons[] = 'comment_too_long'; }
        if (preg_match('/https?:\/\/|www\./iu', $comment)) { $reasons[] = 'contains_link'; }
        foreach (array('prenota','compra','acquista','offerta','sconto','whatsapp','link in bio','scrivimi in privato','scrivici in privato','yoganostress.it','prima esperienza') as $pattern) {
            if (mb_stripos($comment, $pattern, 0, 'UTF-8') !== false) { $reasons[] = 'promotion_signal:' . sanitize_key(str_replace(' ', '_', $pattern)); }
        }
        if (preg_match('/€|\bEUR\b|\beuro\b/iu', $comment)) { $reasons[] = 'price_signal'; }
        foreach (array('ho provato','abbiamo provato','ho partecipato','abbiamo partecipato','ci siamo stati','sono stato','sono stata') as $pattern) {
            if (mb_stripos($comment, $pattern, 0, 'UTF-8') !== false) { $warnings[] = 'first_person_experience_claim'; $score -= 20; break; }
        }
        if (preg_match('/([!?\.])\1{2,}/u', $comment)) { $warnings[] = 'excessive_punctuation'; $score -= 10; }
        if (self::is_duplicate($comment)) { $reasons[] = 'duplicate_comment'; }
        $reasons = array_values(array_unique($reasons));
        $warnings = array_values(array_unique($warnings));
        if (!empty($reasons)) { $score = min($score, 50); }
        $score = max(0, min(100, $score));
        return array('pass' => empty($reasons) && $score >= 80, 'score' => $score, 'reasons' => $reasons, 'warnings' => $warnings, 'normalized_comment' => $comment);
    }

    public static function redteam_endpoint(WP_REST_Request $request) {
        $p = (array) $request->get_json_params();
        $network = isset($p['network']) ? sanitize_key($p['network']) : '';
        $target_url = isset($p['target_url']) ? esc_url_raw($p['target_url']) : '';
        $comment = isset($p['comment']) ? (string) $p['comment'] : '';
        $report = self::redteam($network, $target_url, $comment);
        self::log_event('redteam_checked', array('network'=>$network,'target_url'=>$target_url,'comment'=>$comment), array('pass'=>$report['pass'],'score'=>$report['score'],'reasons'=>$report['reasons']));
        return rest_ensure_response($report);
    }

    private static function build_item($params) {
        $network = isset($params['network']) ? sanitize_key($params['network']) : '';
        $target_url = isset($params['target_url']) ? esc_url_raw($params['target_url']) : '';
        $comment = isset($params['comment']) ? (string) $params['comment'] : '';
        $report = self::redteam($network, $target_url, $comment);
        if (!$report['pass']) {
            return new WP_Error('mr_social_redteam_rejected', 'Comment rejected by Red Team.', array('status'=>400,'report'=>$report));
        }
        $normalized = $report['normalized_comment'];
        return array(
            'id'=>wp_generate_uuid4(),'created_at'=>current_time('mysql', true),'network'=>$network,'target_url'=>$target_url,
            'account'=>isset($params['account'])?sanitize_text_field($params['account']):'',
            'topic'=>isset($params['topic'])?sanitize_text_field($params['topic']):'',
            'comment'=>$normalized,'fingerprint'=>hash('sha256', mb_strtolower($normalized,'UTF-8')),
            'redteam_score'=>$report['score'],'redteam_warnings'=>$report['warnings'],'status'=>'ready','attempts'=>0,'external_id'=>'','last_error'=>'',
        );
    }

    private static function store_queue($queue) {
        if (count($queue)>200) { $queue=array_slice($queue,-200); }
        update_option(self::QUEUE_OPTION,array_values($queue),false);
    }

    public static function queue_get(WP_REST_Request $request) {
        $queue=get_option(self::QUEUE_OPTION,array()); if(!is_array($queue)){$queue=array();}
        $status=sanitize_key((string)$request->get_param('status'));
        if($status){$queue=array_values(array_filter($queue,function($item)use($status){return isset($item['status'])&&$item['status']===$status;}));}
        return rest_ensure_response(array('items'=>array_reverse($queue)));
    }

    public static function queue_add(WP_REST_Request $request) {
        $settings=self::settings(); if(!$settings['enabled']){return new WP_Error('mr_social_disabled','Social Engagement is disabled.',array('status'=>403));}
        $item=self::build_item((array)$request->get_json_params()); if(is_wp_error($item)){return $item;}
        $queue=get_option(self::QUEUE_OPTION,array()); if(!is_array($queue)){$queue=array();} $queue[]=$item; self::store_queue($queue);
        self::log_event('queue_added',$item,array('redteam_score'=>$item['redteam_score']));
        return rest_ensure_response(array('item'=>$item));
    }

    public static function queue_batch(WP_REST_Request $request) {
        $settings=self::settings(); if(!$settings['enabled']){return new WP_Error('mr_social_disabled','Social Engagement is disabled.',array('status'=>403));}
        $p=(array)$request->get_json_params(); $items=isset($p['items'])&&is_array($p['items'])?$p['items']:array();
        if(empty($items)||count($items)>15){return new WP_Error('mr_social_invalid_batch','Batch must contain between 1 and 15 items.',array('status'=>400));}
        $queue=get_option(self::QUEUE_OPTION,array()); if(!is_array($queue)){$queue=array();} $accepted=array(); $rejected=array();
        foreach($items as $index=>$params_item){
            $item=self::build_item((array)$params_item);
            if(is_wp_error($item)){$data=$item->get_error_data();$rejected[]=array('index'=>$index,'code'=>$item->get_error_code(),'report'=>isset($data['report'])?$data['report']:array());continue;}
            $queue[]=$item;$accepted[]=$item;self::log_event('queue_added',$item,array('batch'=>true,'redteam_score'=>$item['redteam_score']));
        }
        self::store_queue($queue);
        return rest_ensure_response(array('accepted'=>$accepted,'rejected'=>$rejected,'accepted_count'=>count($accepted),'rejected_count'=>count($rejected)));
    }

    private static function increment_daily() {
        $key=gmdate('Y-m-d');$daily=get_option(self::DAILY_OPTION,array());if(!is_array($daily)){$daily=array();}
        $daily[$key]=isset($daily[$key])?((int)$daily[$key]+1):1;
        if(count($daily)>31){ksort($daily);$daily=array_slice($daily,-31,null,true);}
        update_option(self::DAILY_OPTION,$daily,false);return (int)$daily[$key];
    }

    public static function dispatch(WP_REST_Request $request) {
        $settings=self::settings();
        if(!$settings['enabled']){return new WP_Error('mr_social_disabled','Social Engagement is disabled.',array('status'=>403));}
        if($settings['dry_run']){return new WP_Error('mr_social_dry_run','Dry-run is enabled. No external comment was sent.',array('status'=>409));}
        $p=(array)$request->get_json_params();$id=isset($p['id'])?sanitize_text_field($p['id']):'';
        if(!$id){return new WP_Error('mr_social_missing_id','Queue item id is required.',array('status'=>400));}
        $key=gmdate('Y-m-d');$daily=get_option(self::DAILY_OPTION,array());if(!is_array($daily)){$daily=array();}
        if((isset($daily[$key])?(int)$daily[$key]:0)>=(int)$settings['max_daily_comments']){return new WP_Error('mr_social_daily_limit','Daily social comment limit reached.',array('status'=>429));}
        $queue=get_option(self::QUEUE_OPTION,array());if(!is_array($queue)){$queue=array();}$found=null;
        foreach($queue as $i=>$item){if(isset($item['id'])&&hash_equals((string)$item['id'],$id)){$found=$i;break;}}
        if($found===null){return new WP_Error('mr_social_not_found','Queue item not found.',array('status'=>404));}
        $item=$queue[$found];
        if(!isset($item['status'])||$item['status']!=='ready'){return new WP_Error('mr_social_invalid_status','Only ready items can be dispatched.',array('status'=>409));}
        $report=self::redteam($item['network'],$item['target_url'],$item['comment']);
        if(!$report['pass']){$queue[$found]['status']='failed';$queue[$found]['last_error']='redteam_recheck_failed';self::store_queue($queue);self::log_event('dispatch_blocked_redteam',$item,array('report'=>$report));return new WP_Error('mr_social_redteam_recheck_failed','Comment failed Red Team re-check before dispatch.',array('status'=>409,'report'=>$report));}
        if(!has_filter('mr_bridge_social_comment_dispatch')){return new WP_Error('mr_social_adapter_unavailable','No trusted social comment adapter is registered.',array('status'=>501));}
        $queue[$found]['attempts']=isset($queue[$found]['attempts'])?((int)$queue[$found]['attempts']+1):1;
        $result=apply_filters('mr_bridge_social_comment_dispatch',null,$item);
        if(!is_array($result)||empty($result['success'])){$queue[$found]['status']='failed';$queue[$found]['last_error']=is_array($result)&&isset($result['error'])?sanitize_text_field($result['error']):'adapter_failed';self::store_queue($queue);self::log_event('dispatch_failed',$queue[$found],array('error'=>$queue[$found]['last_error']));return new WP_Error('mr_social_dispatch_failed','Trusted adapter did not confirm delivery.',array('status'=>502));}
        $queue[$found]['status']='sent';$queue[$found]['sent_at']=current_time('mysql',true);$queue[$found]['external_id']=isset($result['external_id'])?sanitize_text_field($result['external_id']):'';$queue[$found]['last_error']='';self::store_queue($queue);
        $count=self::increment_daily();self::log_event('dispatch_sent',$queue[$found],array('sent_today'=>$count,'adapter'=>isset($result['adapter'])?sanitize_key($result['adapter']):'trusted_adapter'));
        return rest_ensure_response(array('ok'=>true,'item'=>$queue[$found],'sent_today'=>$count));
    }

    public static function audit() {
        return rest_ensure_response(array('events'=>array_reverse((array)get_option(self::LOG_OPTION,array()))));
    }
}

