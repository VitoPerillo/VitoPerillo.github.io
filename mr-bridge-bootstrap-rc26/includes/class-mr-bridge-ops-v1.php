<?php
if (!defined('ABSPATH')) { exit; }

final class MR_Bridge_Ops_V1 {
    const SETTINGS_OPTION = 'mr_bridge_ops_settings';
    const JOBS_OPTION = 'mr_bridge_jobs_v1';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('mr_bridge_run_job', array(__CLASS__, 'run_job'), 10, 1);
    }

    public static function register_routes() {
        $perm = array('MR_Bridge', 'can_manage');
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/ops/status', array(
            'methods'=>'GET','callback'=>array(__CLASS__,'status'),'permission_callback'=>$perm,
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/ops/plugin/state', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'plugin_state'),'permission_callback'=>$perm,
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/ops/cache/flush', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'cache_flush'),'permission_callback'=>$perm,
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/ops/rewrite/flush', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'rewrite_flush'),'permission_callback'=>$perm,
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/ops/options', array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'options_get'),'permission_callback'=>$perm),
            array('methods'=>'POST','callback'=>array(__CLASS__,'options_update'),'permission_callback'=>$perm),
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/ops/jobs', array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'jobs_get'),'permission_callback'=>$perm),
            array('methods'=>'POST','callback'=>array(__CLASS__,'jobs_add'),'permission_callback'=>$perm),
        ));
    }

    private static function plugin_allowlist() {
        return array(
            'yoganostress-bridge/yoganostress-bridge.php',
            'yns-whatsapp-api/yns-whatsapp-api.php',
            'yoganostress-prenotazioni/yoganostress-prenotazioni.php',
        );
    }

    private static function option_allowlist() {
        return array(
            'mr_bridge_ops_settings',
        );
    }

    public static function status() {
        if (!function_exists('wp_next_scheduled')) { require_once ABSPATH . WPINC . '/cron.php'; }
        $cron = _get_cron_array();
        $pending = 0;
        foreach ((array)$cron as $hooks) {
            foreach ((array)$hooks as $events) { $pending += count((array)$events); }
        }
        $data=array(
            'ok'=>true,
            'environment'=>MR_Bridge_Deploy_V1::environment(),
            'version'=>MR_Bridge::VERSION,
            'wordpress'=>get_bloginfo('version'),
            'php'=>PHP_VERSION,
            'memory_limit'=>ini_get('memory_limit'),
            'max_execution_time'=>(int)ini_get('max_execution_time'),
            'upload_max_filesize'=>ini_get('upload_max_filesize'),
            'post_max_size'=>ini_get('post_max_size'),
            'disk_free_bytes'=>@disk_free_space(WP_CONTENT_DIR) ?: null,
            'cron_disabled'=>defined('DISABLE_WP_CRON') ? (bool)DISABLE_WP_CRON : false,
            'cron_events'=>$pending,
            'rest_url'=>rest_url(MR_Bridge::REST_NAMESPACE . '/'),
            'home_url'=>home_url('/'),
            'plugin_dir_writable'=>is_writable(WP_PLUGIN_DIR),
            'content_dir_writable'=>is_writable(WP_CONTENT_DIR),
            'self_update_supported'=>true,
            'external_zip_host_required'=>false,
            'remote_desktop_required'=>false,
            'wpvibe_required'=>false,
            'job_execution_mode'=>'synchronous_persistent',
        );
        MR_Bridge::log('ops_status_read', array('cron_events'=>$pending));
        return rest_ensure_response($data);
    }

    public static function plugin_state(WP_REST_Request $request) {
        if (!function_exists('activate_plugin') || !function_exists('is_plugin_active')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $body=(array)$request->get_json_params();
        $plugin=sanitize_text_field((string)($body['plugin']??''));
        $state=sanitize_key((string)($body['state']??''));
        if (!in_array($plugin,self::plugin_allowlist(),true)) {
            return new WP_Error('mr_ops_plugin_denied','Plugin fuori allowlist.',array('status'=>403));
        }
        if (!in_array($state,array('active','inactive'),true)) {
            return new WP_Error('mr_ops_state_invalid','Stato plugin non valido.',array('status'=>400));
        }
        if ($plugin==='yoganostress-bridge/yoganostress-bridge.php' && $state==='inactive') {
            return new WP_Error('mr_ops_self_deactivate_denied','MR Bridge non può disattivare se stesso via API.',array('status'=>409));
        }
        if (($body['confirm']??'') !== strtoupper($state).':'.$plugin) {
            return new WP_Error('mr_ops_confirm','Conferma non valida.',array('status'=>400));
        }
        $before=is_plugin_active($plugin);
        if ($state==='active' && !$before) {
            $r=activate_plugin($plugin,'',false,true);
            if (is_wp_error($r)) { return $r; }
        } elseif ($state==='inactive' && $before) {
            deactivate_plugins($plugin,true,false);
        }
        $after=is_plugin_active($plugin);
        MR_Bridge::log('ops_plugin_state',array('plugin'=>$plugin,'before'=>$before,'after'=>$after));
        return rest_ensure_response(array('ok'=>true,'plugin'=>$plugin,'active'=>$after));
    }

    public static function cache_flush(WP_REST_Request $request) {
        $body=(array)$request->get_json_params();
        if (($body['confirm']??'')!=='FLUSH:CACHE') {
            return new WP_Error('mr_ops_confirm','Conferma non valida.',array('status'=>400));
        }
        $done=array();
        if (function_exists('wp_cache_flush')) { wp_cache_flush(); $done[]='wp_object_cache'; }
        if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); $done[]='wp_rocket'; }
        if (function_exists('w3tc_flush_all')) { w3tc_flush_all(); $done[]='w3_total_cache'; }
        do_action('mr_bridge_cache_flush');
        MR_Bridge::log('ops_cache_flush',array('handlers'=>$done));
        return rest_ensure_response(array('ok'=>true,'handlers'=>$done));
    }

    public static function rewrite_flush(WP_REST_Request $request) {
        $body=(array)$request->get_json_params();
        if (($body['confirm']??'')!=='FLUSH:REWRITE') {
            return new WP_Error('mr_ops_confirm','Conferma non valida.',array('status'=>400));
        }
        flush_rewrite_rules(false);
        MR_Bridge::log('ops_rewrite_flush');
        return rest_ensure_response(array('ok'=>true));
    }

    public static function options_get(WP_REST_Request $request) {
        $names=$request->get_param('names');
        if (is_string($names)) { $names=array_filter(array_map('trim',explode(',',$names))); }
        if (!is_array($names)) { $names=self::option_allowlist(); }
        $out=array();
        foreach ($names as $name) {
            $name=sanitize_key($name);
            if (!in_array($name,self::option_allowlist(),true)) { continue; }
            $value=get_option($name,null);
            if ($name==='mr_bridge_ops_settings' && is_array($value)) {
                foreach ($value as $k=>$v) {
                    if (preg_match('/secret|token|password|key/i',(string)$k)) { $value[$k]='[redacted]'; }
                }
            }
            $out[$name]=$value;
        }
        MR_Bridge::log('ops_options_read',array('names'=>array_keys($out)));
        return rest_ensure_response(array('ok'=>true,'options'=>$out));
    }

    public static function options_update(WP_REST_Request $request) {
        $body=(array)$request->get_json_params();
        $name=sanitize_key((string)($body['name']??''));
        if (!in_array($name,self::option_allowlist(),true)) {
            return new WP_Error('mr_ops_option_denied','Opzione fuori allowlist.',array('status'=>403));
        }
        if (($body['confirm']??'')!=='OPTION:'.$name) {
            return new WP_Error('mr_ops_confirm','Conferma non valida.',array('status'=>400));
        }
        $before=get_option($name,null);
        $value=$body['value']??null;
        update_option($name,$value,false);
        $after=get_option($name,null);
        MR_Bridge::log('ops_option_update',array('name'=>$name,'before_sha256'=>hash('sha256',wp_json_encode($before)),'after_sha256'=>hash('sha256',wp_json_encode($after))));
        return rest_ensure_response(array('ok'=>true,'name'=>$name));
    }

    private static function jobs_store() {
        $jobs=get_option(self::JOBS_OPTION,array());
        return is_array($jobs)?$jobs:array();
    }

    private static function jobs_save($jobs) {
        if (count($jobs)>100) { $jobs=array_slice($jobs,-100,null,true); }
        update_option(self::JOBS_OPTION,$jobs,false);
    }

    public static function jobs_get(WP_REST_Request $request) {
        $jobs=self::jobs_store();
        return rest_ensure_response(array('ok'=>true,'jobs'=>array_values(array_reverse($jobs,true))));
    }

    public static function jobs_add(WP_REST_Request $request) {
        $body=(array)$request->get_json_params();
        $type=sanitize_key((string)($body['type']??''));
        if (!in_array($type,array('cache_flush','rewrite_flush','health_snapshot'),true)) {
            return new WP_Error('mr_ops_job_denied','Tipo job non autorizzato.',array('status'=>403));
        }
        $request_id=sanitize_text_field((string)($body['request_id']??''));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,96}$/',$request_id)) {
            return new WP_Error('mr_ops_request_id','request_id non valido.',array('status'=>400));
        }
        $jobs=self::jobs_store();
        foreach ($jobs as $j) {
            if (($j['request_id']??'')===$request_id) { return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'job'=>$j)); }
        }
        $id='mrjob-'.gmdate('YmdHis').'-'.strtolower(wp_generate_password(6,false,false));
        $job=array('id'=>$id,'request_id'=>$request_id,'type'=>$type,'status'=>'queued','created_at'=>current_time('mysql',true));
        $jobs[$id]=$job; self::jobs_save($jobs);
        MR_Bridge::log('ops_job_queued',array('job_id'=>$id,'type'=>$type,'request_id'=>$request_id));
        self::run_job($id);
        $jobs=self::jobs_store();
        $job=isset($jobs[$id])?$jobs[$id]:$job;
        return rest_ensure_response(array('ok'=>true,'execution_mode'=>'synchronous_persistent','job'=>$job));
    }

    public static function run_job($id) {
        $jobs=self::jobs_store();
        if (!isset($jobs[$id]) || ($jobs[$id]['status']??'')!=='queued') { return; }
        $jobs[$id]['status']='running'; $jobs[$id]['started_at']=current_time('mysql',true); self::jobs_save($jobs);
        try {
            $type=$jobs[$id]['type'];
            if ($type==='cache_flush') {
                if (function_exists('wp_cache_flush')) { wp_cache_flush(); }
            } elseif ($type==='rewrite_flush') {
                flush_rewrite_rules(false);
            } elseif ($type==='health_snapshot') {
                $jobs[$id]['snapshot']=array('wordpress'=>get_bloginfo('version'),'php'=>PHP_VERSION,'disk_free_bytes'=>@disk_free_space(WP_CONTENT_DIR) ?: null);
            }
            $jobs[$id]['status']='complete';
            $jobs[$id]['completed_at']=current_time('mysql',true);
            MR_Bridge::log('ops_job_complete',array('job_id'=>$id,'type'=>$type));
        } catch (Throwable $e) {
            $jobs[$id]['status']='failed';
            $jobs[$id]['error_code']='exception';
            $jobs[$id]['completed_at']=current_time('mysql',true);
            MR_Bridge::log('ops_job_failed',array('job_id'=>$id,'type'=>$jobs[$id]['type']));
        }
        self::jobs_save($jobs);
    }
}

