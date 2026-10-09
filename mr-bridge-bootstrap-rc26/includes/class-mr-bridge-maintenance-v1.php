<?php
if (!defined('ABSPATH')) { exit; }

final class MR_Bridge_Maintenance_V1 {
    const REST_NAMESPACE = 'mr-bridge/v1';
    const PRODUCTION_HOME = 'https://www.yoganostress.it';
    const OIDC_REPOSITORY = 'VitoPerillo/yoganostress-wordpress-bridge';
    const OIDC_REPOSITORY_ID = '1390938875';
    const OIDC_OWNER_ID = '317205417';
    const OIDC_AUD = 'https://www.yoganostress.it/mr-bridge-maintenance';
    const OIDC_REF = 'refs/heads/main';
    const OIDC_WORKFLOW_REF = 'VitoPerillo/yoganostress-wordpress-bridge/.github/workflows/mr-maintenance-production.yml@refs/heads/main';
    const POLICY_OPTION = 'mr_bridge_maintenance_policy_v1';
    const REQUESTS_OPTION = 'mr_bridge_maintenance_requests_v1';
    const BACKUP_ROOT_NAME = 'mr-bridge-maintenance-backups';
    const LOCK_KEY = 'mr_bridge_maintenance_lock_v1';
    const MAX_REQUESTS = 100;
    const MAX_BACKUPS = 6;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/maintenance/status', array(
            'methods'=>'POST',
            'callback'=>array(__CLASS__,'status'),
            'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/maintenance/storage', array(
            'methods'=>'POST',
            'callback'=>array(__CLASS__,'storage_scan'),
            'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/maintenance/storage/cleanup', array(
            'methods'=>'POST',
            'callback'=>array(__CLASS__,'storage_cleanup'),
            'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/maintenance/policy', array(
            'methods'=>'POST',
            'callback'=>array(__CLASS__,'policy_update'),
            'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/maintenance/theme/apply', array(
            'methods'=>'POST',
            'callback'=>array(__CLASS__,'theme_apply'),
            'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/maintenance/theme/rollback', array(
            'methods'=>'POST',
            'callback'=>array(__CLASS__,'theme_rollback_endpoint'),
            'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/maintenance/core/apply', array(
            'methods'=>'POST',
            'callback'=>array(__CLASS__,'core_apply'),
            'permission_callback'=>'__return_true',
        ));
    }

    private static function defaults() {
        return array(
            'theme_updates_enabled'=>false,
            'core_updates_enabled'=>false,
            'core_backup_max_age_seconds'=>86400,
            'one_update_at_a_time'=>true,
        );
    }

    private static function policy() {
        $p=get_option(self::POLICY_OPTION,array());
        return array_merge(self::defaults(),is_array($p)?$p:array());
    }

    private static function b64url_decode($value) {
        $value=strtr((string)$value,'-_','+/');
        $pad=strlen($value)%4;
        if($pad){$value.=str_repeat('=',4-$pad);}
        return base64_decode($value,true);
    }

    private static function auth_header() {
        if(!empty($_SERVER['HTTP_AUTHORIZATION'])){return trim((string)$_SERVER['HTTP_AUTHORIZATION']);}
        if(function_exists('apache_request_headers')){
            foreach((array)apache_request_headers() as $k=>$v){
                if(strtolower((string)$k)==='authorization'){return trim((string)$v);}
            }
        }
        return '';
    }

    private static function github_jwks() {
        $cached=get_transient('mr_bridge_github_jwks');
        if(is_array($cached)&&!empty($cached['keys'])){return $cached;}
        $res=wp_remote_get('https://token.actions.githubusercontent.com/.well-known/jwks',array(
            'timeout'=>10,'redirection'=>2,'headers'=>array('Accept'=>'application/json'),
        ));
        if(is_wp_error($res)){return $res;}
        if((int)wp_remote_retrieve_response_code($res)!==200){
            return new WP_Error('mr_maintenance_jwks_http','JWKS GitHub non disponibile.',array('status'=>503));
        }
        $data=json_decode(wp_remote_retrieve_body($res),true);
        if(!is_array($data)||empty($data['keys'])){
            return new WP_Error('mr_maintenance_jwks_invalid','JWKS GitHub non valido.',array('status'=>503));
        }
        set_transient('mr_bridge_github_jwks',$data,HOUR_IN_SECONDS);
        return $data;
    }

    private static function verify_oidc() {
        if(untrailingslashit(home_url('/'))!==self::PRODUCTION_HOME){
            return new WP_Error('mr_maintenance_production_only','Manutenzione abilitata solo sul sito produzione riconosciuto.',array('status'=>403));
        }
        $direct=class_exists('MR_Bridge_Autonomous_V1')?MR_Bridge_Autonomous_V1::verify_global('maintenance:write'):null;
        if($direct!==null){return $direct;}
        $auth=self::auth_header();
        if(!preg_match('/^Bearer\s+(.+)$/i',$auth,$m)){
            return new WP_Error('mr_maintenance_oidc_missing','Token OIDC mancante.',array('status'=>401));
        }
        $parts=explode('.',trim($m[1]));
        if(count($parts)!==3){return new WP_Error('mr_maintenance_oidc_bad_token','JWT non valido.',array('status'=>401));}
        $header_raw=self::b64url_decode($parts[0]);$payload_raw=self::b64url_decode($parts[1]);$sig=self::b64url_decode($parts[2]);
        if($header_raw===false||$payload_raw===false||$sig===false){return new WP_Error('mr_maintenance_oidc_decode','JWT non decodificabile.',array('status'=>401));}
        $header=json_decode($header_raw,true);$claims=json_decode($payload_raw,true);
        if(!is_array($header)||!is_array($claims)||($header['alg']??'')!=='RS256'||empty($header['kid'])){
            return new WP_Error('mr_maintenance_oidc_header','Header JWT non valido.',array('status'=>401));
        }
        $jwks=self::github_jwks();if(is_wp_error($jwks)){return $jwks;}
        $cert=null;
        foreach((array)$jwks['keys'] as $key){
            if(($key['kid']??'')===$header['kid']&&!empty($key['x5c'][0])){
                $cert="-----BEGIN CERTIFICATE-----\n".chunk_split($key['x5c'][0],64,"\n")."-----END CERTIFICATE-----\n";break;
            }
        }
        if(!$cert||!function_exists('openssl_verify')){
            delete_transient('mr_bridge_github_jwks');
            return new WP_Error('mr_maintenance_oidc_key','Chiave OIDC GitHub non verificabile.',array('status'=>401));
        }
        if(openssl_verify($parts[0].'.'.$parts[1],$sig,$cert,OPENSSL_ALGO_SHA256)!==1){
            delete_transient('mr_bridge_github_jwks');
            return new WP_Error('mr_maintenance_oidc_signature','Firma OIDC GitHub non valida.',array('status'=>401));
        }
        $now=time();$aud=$claims['aud']??'';
        $expected_aud=untrailingslashit(home_url('/')).'/mr-bridge-maintenance';
        $aud_ok=is_array($aud)?in_array($expected_aud,$aud,true):hash_equals($expected_aud,(string)$aud);
        $checks=array(
            'iss'=>(($claims['iss']??'')==='https://token.actions.githubusercontent.com'),
            'aud'=>$aud_ok,
            'exp'=>(!empty($claims['exp'])&&(int)$claims['exp']>=$now-30),
            'nbf'=>(empty($claims['nbf'])||(int)$claims['nbf']<=$now+30),
            'iat'=>(!empty($claims['iat'])&&(int)$claims['iat']<=$now+30&&(int)$claims['iat']>=$now-900),
            'repository'=>(($claims['repository']??'')===self::OIDC_REPOSITORY),
            'repository_id'=>((string)($claims['repository_id']??'')===self::OIDC_REPOSITORY_ID),
            'repository_owner_id'=>((string)($claims['repository_owner_id']??'')===self::OIDC_OWNER_ID),
            'actor_id'=>((string)($claims['actor_id']??'')===self::OIDC_OWNER_ID),
            'ref'=>(($claims['ref']??'')===self::OIDC_REF),
            'workflow_ref'=>(($claims['workflow_ref']??'')===self::OIDC_WORKFLOW_REF),
            'event_name'=>(($claims['event_name']??'')==='push'),
        );
        foreach($checks as $name=>$ok){if(!$ok){return new WP_Error('mr_maintenance_oidc_claim_'.$name,'Claim OIDC rifiutato: '.$name,array('status'=>403));}}
        return $claims;
    }

    private static function request_json(WP_REST_Request $request) {
        $d=$request->get_json_params();
        return is_array($d)?$d:array();
    }

    private static function request_id_valid($id) {
        return is_string($id)&&preg_match('/^[A-Za-z0-9._:-]{16,96}$/',$id);
    }

    private static function request_store() {
        $x=get_option(self::REQUESTS_OPTION,array());
        return is_array($x)?$x:array();
    }

    private static function request_lookup($id) {
        foreach(self::request_store() as $row){if(($row['request_id']??'')===$id){return $row;}}
        return null;
    }

    private static function request_save($row) {
        $x=self::request_store();$x[]=$row;
        if(count($x)>self::MAX_REQUESTS){$x=array_slice($x,-self::MAX_REQUESTS);}
        update_option(self::REQUESTS_OPTION,$x,false);
    }

    private static function acquire_lock($request_id) {
        $lock=get_transient(self::LOCK_KEY);
        if(is_array($lock)&&!empty($lock['request_id'])&&$lock['request_id']!==$request_id){
            return new WP_Error('mr_maintenance_locked','Un’altra operazione di manutenzione è già in corso.',array('status'=>409));
        }
        set_transient(self::LOCK_KEY,array('request_id'=>$request_id,'time'=>time()),20*MINUTE_IN_SECONDS);
        return true;
    }

    private static function release_lock($request_id) {
        $lock=get_transient(self::LOCK_KEY);
        if(is_array($lock)&&($lock['request_id']??'')===$request_id){delete_transient(self::LOCK_KEY);}
    }

    private static function require_update_api() {
        if(!function_exists('get_core_updates')){require_once ABSPATH.'wp-admin/includes/update.php';}
        if(!function_exists('get_themes')){require_once ABSPATH.'wp-admin/includes/theme.php';}
        if(!class_exists('Theme_Upgrader')||!class_exists('Core_Upgrader')){require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';}
    }

    private static function official_core_offer() {
        self::require_update_api();
        wp_version_check(array(),true);
        $offers=get_core_updates(array('dismissed'=>false));
        if(!is_array($offers)){return null;}
        foreach($offers as $offer){
            if(!is_object($offer)||($offer->response??'')!=='upgrade'){continue;}
            $package=(string)($offer->download??($offer->packages->full??''));
            $host=(string)wp_parse_url($package,PHP_URL_HOST);
            if($package===''||strpos($package,'https://')!==0||!wp_http_validate_url($package)){continue;}
            if($host!=='downloads.wordpress.org' && substr($host,-14)!=='.wordpress.org'){continue;}
            return $offer;
        }
        return null;
    }

    public static function status(WP_REST_Request $request) {
        $claims=self::verify_oidc();if(is_wp_error($claims)){return $claims;}
        self::require_update_api();
        wp_update_themes();
        $themes=wp_get_themes();
        $t=get_site_transient('update_themes');
        $updates=is_object($t)&&is_array($t->response??null)?$t->response:array();
        $theme_rows=array();
        foreach($updates as $stylesheet=>$u){
            if(!isset($themes[$stylesheet])){continue;}
            $theme_rows[]=array(
                'stylesheet'=>$stylesheet,
                'name'=>$themes[$stylesheet]->get('Name'),
                'current_version'=>$themes[$stylesheet]->get('Version'),
                'new_version'=>(string)($u['new_version']??''),
                'package_available'=>!empty($u['package']),
                'package_host'=>!empty($u['package'])?(string)wp_parse_url($u['package'],PHP_URL_HOST):'',
                'active'=>wp_get_theme()->get_stylesheet()===$stylesheet,
            );
        }
        $offer=self::official_core_offer();
        $core=array(
            'current_version'=>get_bloginfo('version'),
            'update_available'=>(bool)$offer,
            'new_version'=>$offer?(string)($offer->current??''):'',
            'package_host'=>$offer?(string)wp_parse_url((string)($offer->download??($offer->packages->full??'')),PHP_URL_HOST):'',
        );
        $receipt=class_exists('MR_Bridge_Backup_V1')?MR_Bridge_Backup_V1::latest_receipt((int)self::policy()['core_backup_max_age_seconds']):null;
        MR_Bridge::log('maintenance_status_read',array('theme_updates'=>count($theme_rows),'core_update'=>(bool)$offer));
        return rest_ensure_response(array(
            'ok'=>true,
            'policy'=>self::policy(),
            'core'=>$core,
            'themes'=>$theme_rows,
            'recent_backup_receipt'=>$receipt,
        ));
    }

    public static function storage_scan(WP_REST_Request $request) {
        $claims=self::verify_oidc();if(is_wp_error($claims)){return $claims;}
        $d=self::request_json($request);
        $threshold=(int)($d['min_bytes']??(100*1024*1024));
        if($threshold<10*1024*1024){$threshold=10*1024*1024;}
        if($threshold>5*1024*1024*1024){$threshold=5*1024*1024*1024;}
        $limit=(int)($d['limit']??100);$limit=max(1,min(200,$limit));
        $root=realpath(ABSPATH);
        if(!$root){return new WP_Error('mr_storage_root','Root WordPress non disponibile.',array('status'=>500));}
        $root=wp_normalize_path($root);
        $rows=array();$scanned=0;$bytes=0;
        try{
            $it=new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach($it as $file){
                if($scanned>=250000){break;}
                $scanned++;
                if($file->isLink()||!$file->isFile()){continue;}
                $size=(int)$file->getSize();$bytes+=$size;
                if($size<$threshold){continue;}
                $full=wp_normalize_path($file->getPathname());
                if(strpos($full,$root.'/')!==0){continue;}
                $rel=ltrim(substr($full,strlen($root)),'/');
                $lower=strtolower($rel);
                $classification='review';
                $safe=false;
                if(strpos($lower,'wp-content/cache/')===0||strpos($lower,'wp-content/upgrade/')===0){
                    $classification='cache_or_upgrade_temp';$safe=true;
                }elseif(
                    preg_match('/\.(zip|tar|tgz|gz|sql|bak|old)$/i',$rel)
                    && preg_match('#^wp-content/(updraft|ai1wm-backups|wpvividbackups|backups?|backup[^/]*)/#i',$rel)
                ){
                    $classification='backup_archive';$safe=true;
                }elseif(preg_match('/\.log$/i',$rel)){
                    $classification='log_file';$safe=false;
                }elseif(strpos($lower,'wp-content/uploads/')===0){
                    $classification='media_upload';$safe=false;
                }elseif(strpos($lower,'wp-content/plugins/')===0||strpos($lower,'wp-content/themes/')===0){
                    $classification='code_file';$safe=false;
                }
                $rows[]=array(
                    'path'=>$rel,
                    'bytes'=>$size,
                    'modified_gmt'=>gmdate('c',(int)$file->getMTime()),
                    'classification'=>$classification,
                    'safe_cleanup_candidate'=>$safe,
                );
            }
        }catch(Throwable $e){
            return new WP_Error('mr_storage_scan_failed','Scansione storage non completata.',array('status'=>500));
        }
        usort($rows,function($a,$b){return $b['bytes']<=>$a['bytes'];});
        $rows=array_slice($rows,0,$limit);
        MR_Bridge::log('maintenance_storage_scan',array('threshold'=>$threshold,'scanned'=>$scanned,'matches'=>count($rows)));
        return rest_ensure_response(array(
            'ok'=>true,
            'root'=>'wordpress',
            'scanned_files'=>$scanned,
            'scanned_bytes'=>$bytes,
            'min_bytes'=>$threshold,
            'truncated'=>$scanned>=250000,
            'items'=>$rows,
            'deletion_performed'=>false,
        ));
    }

    private static function storage_cleanup_class($rel) {
        $rel=ltrim(wp_normalize_path((string)$rel),'/');
        $lower=strtolower($rel);
        if(strpos($lower,'wp-content/cache/')===0||strpos($lower,'wp-content/upgrade/')===0){
            return 'cache_or_upgrade_temp';
        }
        if(
            preg_match('/\.(zip|tar|tgz|gz|sql|bak|old)$/i',$rel)
            && preg_match('#^wp-content/(updraft|ai1wm-backups|wpvividbackups|backups?|backup[^/]*)/#i',$rel)
        ){
            return 'backup_archive';
        }
        return '';
    }

    public static function storage_cleanup(WP_REST_Request $request) {
        $claims=self::verify_oidc();if(is_wp_error($claims)){return $claims;}
        $d=self::request_json($request);
        $request_id=(string)($d['request_id']??'');
        if(!self::request_id_valid($request_id)){
            return new WP_Error('mr_storage_cleanup_request_id','request_id non valido.',array('status'=>400));
        }
        $rel=ltrim(wp_normalize_path((string)($d['path']??'')),'/');
        $expected_bytes=(int)($d['expected_bytes']??-1);
        $expected_mtime=(int)($d['expected_mtime_unix']??-1);
        $expected_sha=strtolower(trim((string)($d['expected_sha256']??'')));
        if($rel===''||strpos($rel,'..')!==false||$expected_bytes<0||$expected_mtime<0||!preg_match('/^[a-f0-9]{64}$/',$expected_sha)){
            return new WP_Error('mr_storage_cleanup_input','Parametri cleanup non validi.',array('status'=>400));
        }
        $class=self::storage_cleanup_class($rel);
        if($class===''){
            return new WP_Error('mr_storage_cleanup_denied','Percorso fuori allowlist cleanup.',array('status'=>403));
        }
        $root=realpath(ABSPATH);
        if(!$root){return new WP_Error('mr_storage_root','Root WordPress non disponibile.',array('status'=>500));}
        $root=wp_normalize_path($root);
        $full=wp_normalize_path($root.'/'.$rel);
        $real=realpath($full);
        if($real===false||!is_file($real)||is_link($real)){
            return new WP_Error('mr_storage_cleanup_missing','File cleanup non trovato o non valido.',array('status'=>404));
        }
        $real=wp_normalize_path($real);
        if(strpos($real,$root.'/')!==0){
            return new WP_Error('mr_storage_cleanup_escape','Percorso cleanup fuori root WordPress.',array('status'=>403));
        }
        $actual_rel=ltrim(substr($real,strlen($root)),'/');
        if($actual_rel!==$rel||self::storage_cleanup_class($actual_rel)!==$class){
            return new WP_Error('mr_storage_cleanup_mismatch','Percorso cleanup non coincide con il preflight.',array('status'=>409));
        }
        $bytes=(int)filesize($real);
        $mtime=(int)filemtime($real);
        if($bytes!==$expected_bytes||$mtime!==$expected_mtime){
            return new WP_Error('mr_storage_cleanup_conflict','Il file è cambiato dopo il preflight.',array(
                'status'=>409,'current_bytes'=>$bytes,'current_mtime_unix'=>$mtime
            ));
        }
        $sha=hash_file('sha256',$real);
        if(!is_string($sha)||!hash_equals($expected_sha,$sha)){
            return new WP_Error('mr_storage_cleanup_hash_conflict','SHA-256 del file cambiato dopo il preflight.',array('status'=>409));
        }
        $confirm='DELETE-SAFE-STORAGE:'.$expected_sha.':'.$expected_bytes.':'.$rel;
        if(($d['confirm']??'')!==$confirm){
            return new WP_Error('mr_storage_cleanup_confirm','Conferma cleanup non valida.',array('status'=>400));
        }

        $previous=self::request_lookup($request_id);
        if(is_array($previous)){
            if(($previous['action']??'')!=='storage_cleanup'||($previous['path']??'')!==$rel||($previous['sha256']??'')!==$expected_sha){
                return new WP_Error('mr_storage_cleanup_idempotency','request_id già usato con parametri diversi.',array('status'=>409));
            }
            return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'result'=>$previous));
        }

        $lock=self::acquire_lock($request_id);if(is_wp_error($lock)){return $lock;}
        try{
            if(!@unlink($real)){
                return new WP_Error('mr_storage_cleanup_delete_failed','Eliminazione file non riuscita.',array('status'=>500));
            }
            clearstatcache(true,$real);
            if(file_exists($real)){
                return new WP_Error('mr_storage_cleanup_verify_failed','Il file risulta ancora presente dopo il cleanup.',array('status'=>500));
            }
            $row=array(
                'request_id'=>$request_id,
                'action'=>'storage_cleanup',
                'path'=>$rel,
                'classification'=>$class,
                'bytes_freed'=>$expected_bytes,
                'sha256'=>$expected_sha,
                'completed_at'=>current_time('mysql',true),
            );
            self::request_save($row);
            MR_Bridge::log('maintenance_storage_cleanup',$row);
            return rest_ensure_response(array('ok'=>true,'idempotent'=>false,'result'=>$row));
        }finally{
            self::release_lock($request_id);
        }
    }

    public static function policy_update(WP_REST_Request $request) {
        $claims=self::verify_oidc();if(is_wp_error($claims)){return $claims;}
        $d=self::request_json($request);
        if(($d['confirm']??'')!=='MAINTENANCE-POLICY'){return new WP_Error('mr_maintenance_confirm','Conferma policy non valida.',array('status'=>400));}
        $p=self::policy();
        foreach(array('theme_updates_enabled','core_updates_enabled') as $k){
            if(array_key_exists($k,$d)){$p[$k]=(bool)$d[$k];}
        }
        if(array_key_exists('core_backup_max_age_seconds',$d)){
            $age=(int)$d['core_backup_max_age_seconds'];
            if($age<3600||$age>604800){return new WP_Error('mr_maintenance_backup_age','Finestra backup non valida.',array('status'=>400));}
            $p['core_backup_max_age_seconds']=$age;
        }
        update_option(self::POLICY_OPTION,$p,false);
        MR_Bridge::log('maintenance_policy_updated',array('policy'=>$p,'run_id'=>(string)($claims['run_id']??'')));
        return rest_ensure_response(array('ok'=>true,'policy'=>$p));
    }

    private static function backup_root() {
        $root=trailingslashit(WP_CONTENT_DIR).self::BACKUP_ROOT_NAME;
        if(!is_dir($root)&&!wp_mkdir_p($root)){return new WP_Error('mr_maintenance_backup_root','Cartella backup manutenzione non creabile.',array('status'=>500));}
        if(!is_file($root.'/index.php')){@file_put_contents($root.'/index.php',"<?php\n// Silence is golden.\n");}
        if(!is_file($root.'/.htaccess')){@file_put_contents($root.'/.htaccess',"Deny from all\n");}
        return $root;
    }

    private static function remove_tree($path) {
        if(!file_exists($path)){return true;}
        if(is_link($path)){return @unlink($path);}
        if(is_file($path)){return @unlink($path);}
        $items=scandir($path);if($items===false){return false;}
        foreach($items as $item){if($item==='.'||$item==='..'){continue;}if(!self::remove_tree($path.DIRECTORY_SEPARATOR.$item)){return false;}}
        return @rmdir($path);
    }

    private static function copy_tree($src,$dst) {
        if(is_link($src)){return false;}
        if(is_file($src)){
            $parent=dirname($dst);if(!is_dir($parent)&&!wp_mkdir_p($parent)){return false;}
            return @copy($src,$dst);
        }
        if(!is_dir($src)){return false;}
        if(!is_dir($dst)&&!wp_mkdir_p($dst)){return false;}
        $items=scandir($src);if($items===false){return false;}
        foreach($items as $item){if($item==='.'||$item==='..'){continue;}if(!self::copy_tree($src.DIRECTORY_SEPARATOR.$item,$dst.DIRECTORY_SEPARATOR.$item)){return false;}}
        return true;
    }

    private static function prune_backups($root) {
        $dirs=glob(trailingslashit($root).'*',GLOB_ONLYDIR);
        if(!is_array($dirs)||count($dirs)<=self::MAX_BACKUPS){return;}
        usort($dirs,function($a,$b){return filemtime($b)<=>filemtime($a);});
        foreach(array_slice($dirs,self::MAX_BACKUPS) as $dir){self::remove_tree($dir);}
    }

    private static function theme_backup($stylesheet,$version,$request_id) {
        $theme=wp_get_theme($stylesheet);
        if(!$theme->exists()){return new WP_Error('mr_theme_missing','Tema non trovato.',array('status'=>404));}
        $src=$theme->get_stylesheet_directory();
        $root=self::backup_root();if(is_wp_error($root)){return $root;}
        $id='theme-'.gmdate('YmdHis').'-'.sanitize_key($stylesheet).'-'.substr(hash('sha256',$request_id),0,8);
        $dir=trailingslashit($root).$id;
        if(!wp_mkdir_p($dir)||!self::copy_tree($src,$dir.'/payload')){
            self::remove_tree($dir);
            return new WP_Error('mr_theme_backup_failed','Backup tema fallito.',array('status'=>500));
        }
        $m=array('backup_id'=>$id,'stylesheet'=>$stylesheet,'version'=>$version,'active'=>wp_get_theme()->get_stylesheet()===$stylesheet);
        @file_put_contents($dir.'/manifest.json',wp_json_encode($m,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        self::prune_backups($root);
        return array('backup_id'=>$id,'dir'=>$dir,'manifest'=>$m);
    }

    private static function theme_backup_find($backup_id) {
        if (!preg_match('/^theme-[a-z0-9-]{16,180}$/', (string)$backup_id)) {
            return new WP_Error('mr_theme_backup_id','Backup tema non valido.',array('status'=>400));
        }
        $root=self::backup_root();if(is_wp_error($root)){return $root;}
        $dir=trailingslashit($root).$backup_id;
        $manifest_path=$dir.'/manifest.json';
        if(!is_dir($dir)||!is_file($manifest_path)){
            return new WP_Error('mr_theme_backup_missing','Backup tema non trovato.',array('status'=>404));
        }
        $m=json_decode((string)@file_get_contents($manifest_path),true);
        if(!is_array($m)||($m['backup_id']??'')!==$backup_id||empty($m['stylesheet'])){
            return new WP_Error('mr_theme_backup_invalid','Manifest backup tema non valido.',array('status'=>409));
        }
        return array('backup_id'=>$backup_id,'dir'=>$dir,'manifest'=>$m);
    }

    private static function theme_rollback($backup) {
        $stylesheet=(string)$backup['manifest']['stylesheet'];
        $target=trailingslashit(get_theme_root()).$stylesheet;
        if(file_exists($target)&&!self::remove_tree($target)){return new WP_Error('mr_theme_rollback_remove','Rollback tema: rimozione versione aggiornata fallita.',array('status'=>500));}
        if(!self::copy_tree($backup['dir'].'/payload',$target)){return new WP_Error('mr_theme_rollback_copy','Rollback tema: ripristino fallito.',array('status'=>500));}
        wp_clean_themes_cache(true);
        return true;
    }

    private static function loopback_ok() {
        $url=add_query_arg('mr_maintenance_probe',time(),rest_url('mr-bridge/v1/status'));
        $r=wp_remote_get($url,array('timeout'=>20,'redirection'=>2,'sslverify'=>true,'headers'=>array('Cache-Control'=>'no-cache')));
        if(is_wp_error($r)||(int)wp_remote_retrieve_response_code($r)!==200){return false;}
        $d=json_decode((string)wp_remote_retrieve_body($r),true);
        return is_array($d)&&!empty($d['ok']);
    }

    public static function theme_rollback_endpoint(WP_REST_Request $request) {
        $claims=self::verify_oidc();if(is_wp_error($claims)){return $claims;}
        $d=self::request_json($request);
        $rid=(string)($d['request_id']??'');
        if(!self::request_id_valid($rid)){return new WP_Error('mr_maintenance_request_id','request_id non valido.',array('status'=>400));}
        $backup_id=sanitize_text_field((string)($d['backup_id']??''));
        if(($d['confirm']??'')!=='ROLLBACK-THEME:'.$backup_id){return new WP_Error('mr_maintenance_confirm','Conferma rollback tema non valida.',array('status'=>400));}
        $prev=self::request_lookup($rid);
        if($prev){
            if(($prev['type']??'')!=='theme_rollback'||($prev['backup_id']??'')!==$backup_id){
                return new WP_Error('mr_maintenance_idempotency_conflict','request_id già utilizzato con parametri diversi.',array('status'=>409));
            }
            return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'result'=>$prev));
        }
        $backup=self::theme_backup_find($backup_id);if(is_wp_error($backup)){return $backup;}
        $lock=self::acquire_lock($rid);if(is_wp_error($lock)){return $lock;}
        try{
            $rb=self::theme_rollback($backup);if(is_wp_error($rb)){return $rb;}
            $stylesheet=(string)$backup['manifest']['stylesheet'];
            $expected=(string)$backup['manifest']['version'];
            $theme=wp_get_theme($stylesheet);
            if(!$theme->exists()||(string)$theme->get('Version')!==$expected){
                return new WP_Error('mr_theme_rollback_verify','Rollback tema eseguito ma versione ripristinata inattesa.',array('status'=>500));
            }
            if(!self::loopback_ok()){
                return new WP_Error('mr_theme_rollback_loopback','Tema ripristinato ma controllo live non verde.',array('status'=>500));
            }
            $row=array('request_id'=>$rid,'type'=>'theme_rollback','backup_id'=>$backup_id,'stylesheet'=>$stylesheet,'installed_version'=>$expected,'completed_at'=>current_time('mysql',true));
            self::request_save($row);MR_Bridge::log('theme_manual_rollback',$row);
            return rest_ensure_response(array('ok'=>true,'idempotent'=>false,'result'=>$row));
        }finally{self::release_lock($rid);}
    }

    public static function theme_apply(WP_REST_Request $request) {
        $claims=self::verify_oidc();if(is_wp_error($claims)){return $claims;}
        $p=self::policy();if(empty($p['theme_updates_enabled'])){return new WP_Error('mr_theme_updates_disabled','Aggiornamenti tema disabilitati dalla policy RC12.',array('status'=>403));}
        $d=self::request_json($request);
        $rid=(string)($d['request_id']??'');if(!self::request_id_valid($rid)){return new WP_Error('mr_maintenance_request_id','request_id non valido.',array('status'=>400));}
        $stylesheet=sanitize_text_field((string)($d['stylesheet']??''));
        $cur=sanitize_text_field((string)($d['expected_current_version']??''));
        $new=sanitize_text_field((string)($d['expected_new_version']??''));
        if(($d['confirm']??'')!=='THEME:'.$stylesheet.':'.$cur.'->'.$new){return new WP_Error('mr_maintenance_confirm','Conferma tema non valida.',array('status'=>400));}
        $prev=self::request_lookup($rid);if($prev){return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'result'=>$prev));}
        $lock=self::acquire_lock($rid);if(is_wp_error($lock)){return $lock;}
        try{
            self::require_update_api();wp_update_themes();
            $theme=wp_get_theme($stylesheet);if(!$theme->exists()){return new WP_Error('mr_theme_missing','Tema non trovato.',array('status'=>404));}
            if((string)$theme->get('Version')!==$cur){return new WP_Error('mr_theme_version_conflict','Versione tema installata cambiata.',array('status'=>409));}
            $t=get_site_transient('update_themes');$updates=is_object($t)&&is_array($t->response??null)?$t->response:array();
            if(empty($updates[$stylesheet])){return new WP_Error('mr_theme_update_missing','Nessun aggiornamento tema disponibile.',array('status'=>409));}
            $u=$updates[$stylesheet];$available=(string)($u['new_version']??'');$package=(string)($u['package']??'');
            if($available!==$new){return new WP_Error('mr_theme_new_conflict','Versione tema disponibile cambiata.',array('status'=>409));}
            if($package===''||strpos($package,'https://')!==0||!wp_http_validate_url($package)){return new WP_Error('mr_theme_package','Pacchetto tema HTTPS non valido.',array('status'=>409));}
            $backup=self::theme_backup($stylesheet,$cur,$rid);if(is_wp_error($backup)){return $backup;}
            $upgrader=new Theme_Upgrader(new Automatic_Upgrader_Skin());
            $result=$upgrader->upgrade($stylesheet,array('clear_update_cache'=>true));
            wp_clean_themes_cache(true);
            $after=wp_get_theme($stylesheet);
            $ok=($result===true)&&$after->exists()&&((string)$after->get('Version')===$new)&&self::loopback_ok();
            if(!$ok){
                $rb=self::theme_rollback($backup);if(is_wp_error($rb)){return $rb;}
                return new WP_Error('mr_theme_update_failed','Aggiornamento tema non verificato; rollback automatico eseguito.',array('status'=>500));
            }
            $row=array('request_id'=>$rid,'type'=>'theme','stylesheet'=>$stylesheet,'from'=>$cur,'to'=>$new,'backup_id'=>$backup['backup_id'],'completed_at'=>current_time('mysql',true));
            self::request_save($row);MR_Bridge::log('theme_update_success',$row);
            return rest_ensure_response(array('ok'=>true,'idempotent'=>false,'result'=>$row));
        }finally{self::release_lock($rid);}
    }

    public static function core_apply(WP_REST_Request $request) {
        $claims=self::verify_oidc();if(is_wp_error($claims)){return $claims;}
        $p=self::policy();if(empty($p['core_updates_enabled'])){return new WP_Error('mr_core_updates_disabled','Aggiornamenti WordPress core disabilitati dalla policy RC12 finché il gate staging non viene validato.',array('status'=>403));}
        $d=self::request_json($request);
        $rid=(string)($d['request_id']??'');if(!self::request_id_valid($rid)){return new WP_Error('mr_maintenance_request_id','request_id non valido.',array('status'=>400));}
        $cur=sanitize_text_field((string)($d['expected_current_version']??''));
        $new=sanitize_text_field((string)($d['expected_new_version']??''));
        $receipt_sha=strtolower(trim((string)($d['backup_manifest_sha256']??'')));
        if(!preg_match('/^[a-f0-9]{64}$/',$receipt_sha)){return new WP_Error('mr_core_backup_receipt','SHA ricevuta backup obbligatorio.',array('status'=>400));}
        if(($d['confirm']??'')!=='CORE:'.$cur.'->'.$new.':'.$receipt_sha){return new WP_Error('mr_maintenance_confirm','Conferma core non valida.',array('status'=>400));}
        $prev=self::request_lookup($rid);if($prev){return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'result'=>$prev));}

        $receipt=class_exists('MR_Bridge_Backup_V1')?MR_Bridge_Backup_V1::latest_receipt((int)$p['core_backup_max_age_seconds']):null;
        if(!$receipt||!hash_equals((string)($receipt['manifest_sha256']??''),$receipt_sha)){
            return new WP_Error('mr_core_recent_backup_required','Serve una ricevuta di backup locale completo e recente prima dell’aggiornamento WordPress.',array('status'=>409));
        }
        if(get_bloginfo('version')!==$cur){return new WP_Error('mr_core_version_conflict','Versione WordPress corrente cambiata.',array('status'=>409));}

        $lock=self::acquire_lock($rid);if(is_wp_error($lock)){return $lock;}
        try{
            $offer=self::official_core_offer();
            if(!$offer||(string)($offer->current??'')!==$new){return new WP_Error('mr_core_offer_conflict','Versione WordPress disponibile diversa da quella attesa.',array('status'=>409));}
            $upgrader=new Core_Upgrader(new Automatic_Upgrader_Skin());
            $result=$upgrader->upgrade($offer,array('pre_check_md5'=>true,'attempt_rollback'=>true,'do_rollback'=>true));
            if(is_wp_error($result)||$result===false){
                return new WP_Error('mr_core_update_failed','Aggiornamento WordPress core fallito. Verificare il sito e usare il backup locale se necessario.',array('status'=>500));
            }
            if(!self::loopback_ok()){
                return new WP_Error('mr_core_loopback_failed','WordPress aggiornato ma il controllo live non è verde. Non eseguire altre modifiche e usare il backup locale se necessario.',array('status'=>500));
            }
            $row=array('request_id'=>$rid,'type'=>'core','from'=>$cur,'to'=>$new,'backup_manifest_sha256'=>$receipt_sha,'completed_at'=>current_time('mysql',true));
            self::request_save($row);MR_Bridge::log('core_update_success',$row);
            return rest_ensure_response(array('ok'=>true,'idempotent'=>false,'result'=>$row));
        }finally{self::release_lock($rid);}
    }
}

MR_Bridge_Maintenance_V1::init();

