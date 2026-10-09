<?php
if (!defined('ABSPATH')) { exit; }

/**
 * MR Bridge autonomous transport + local package registry.
 * WordPress stores public keys only. GitHub OIDC remains a fallback.
 */
final class MR_Bridge_Autonomous_V1 {
    const REST_NAMESPACE = 'mr-bridge/v1';
    const CLIENTS_OPTION = 'mr_bridge_direct_clients_v1';
    const REQUESTS_OPTION = 'mr_bridge_autonomous_requests_v1';
    const JOBS_OPTION = 'mr_bridge_autonomous_jobs_v1';
    const NONCE_TTL = 600;
    const CLOCK_SKEW = 300;
    const CHUNK_BYTES = 262144;
    const MAX_PACKAGES_PER_SLUG = 10;
    const MAX_JOBS = 200;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('mr_bridge_autonomous_job', array(__CLASS__, 'run_job'), 10, 1);
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/autonomous/status', array(
            'methods'=>'GET','callback'=>array(__CLASS__,'status'),'permission_callback'=>array(__CLASS__,'can_read'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/autonomous/clients', array(
            'methods'=>'GET','callback'=>array(__CLASS__,'clients_list'),'permission_callback'=>array('MR_Bridge','can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/autonomous/clients/register', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'client_register'),'permission_callback'=>array('MR_Bridge','can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/autonomous/clients/revoke', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'client_revoke'),'permission_callback'=>array('MR_Bridge','can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/autonomous/packages', array(
            'methods'=>'GET','callback'=>array(__CLASS__,'packages_list'),'permission_callback'=>array(__CLASS__,'can_read'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/autonomous/packages/start', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'package_start'),'permission_callback'=>array(__CLASS__,'can_packages_write'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/autonomous/packages/chunk', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'package_chunk'),'permission_callback'=>array(__CLASS__,'can_packages_write'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/autonomous/packages/finalize', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'package_finalize'),'permission_callback'=>array(__CLASS__,'can_packages_write'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/autonomous/deploy', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'deploy_registered'),'permission_callback'=>array(__CLASS__,'can_deploy'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/autonomous/rollback', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'rollback_registered'),'permission_callback'=>array(__CLASS__,'can_deploy'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/autonomous/jobs', array(
            array('methods'=>'GET','callback'=>array(__CLASS__,'jobs_list'),'permission_callback'=>array(__CLASS__,'can_read')),
            array('methods'=>'POST','callback'=>array(__CLASS__,'job_add'),'permission_callback'=>array(__CLASS__,'can_jobs_write')),
        ));
        register_rest_route(self::REST_NAMESPACE, '/autonomous/jobs/run', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'job_run_endpoint'),'permission_callback'=>array(__CLASS__,'can_jobs_write'),
        ));
    }

    private static function scopes_allowed() {
        return array('read','packages:write','deploy:execute','content:write','media:write','maintenance:write','jobs:write','secrets:write','providers:write');
    }

    private static function clients() {
        $rows=get_option(self::CLIENTS_OPTION,array());
        return is_array($rows)?$rows:array();
    }

    private static function save_clients($rows) {
        update_option(self::CLIENTS_OPTION,is_array($rows)?$rows:array(),false);
    }

    private static function client_id($value) {
        $id=strtolower(trim((string)$value));
        return preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/',$id)?$id:'';
    }

    private static function direct_headers_present() {
        return !empty($_SERVER['HTTP_X_MR_CLIENT'])||!empty($_SERVER['HTTP_X_MR_SIGNATURE'])
            ||!empty($_SERVER['HTTP_X_MR_TIMESTAMP'])||!empty($_SERVER['HTTP_X_MR_NONCE']);
    }

    private static function request_route($request=null) {
        if($request instanceof WP_REST_Request){return (string)$request->get_route();}
        $uri=isset($_SERVER['REQUEST_URI'])?(string)$_SERVER['REQUEST_URI']:'';
        $path=(string)parse_url($uri,PHP_URL_PATH);
        $needle='/wp-json';$pos=strpos($path,$needle);
        if($pos!==false){$path=substr($path,$pos+strlen($needle));}
        return $path?:'/';
    }

    private static function request_method($request=null) {
        if($request instanceof WP_REST_Request){return strtoupper((string)$request->get_method());}
        return strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    }

    private static function request_body($request=null) {
        if($request instanceof WP_REST_Request){return (string)$request->get_body();}
        return (string)file_get_contents('php://input');
    }

    private static function nonce_key($client_id,$nonce) {
        return 'mr_bridge_nonce_'.substr(hash('sha256',$client_id."\n".$nonce),0,36);
    }

    private static function canonical($method,$route,$timestamp,$nonce,$body) {
        return strtoupper((string)$method)."\n".(string)$route."\n".(string)$timestamp."\n".(string)$nonce."\n".hash('sha256',(string)$body);
    }

    public static function verify_global($scope,$request=null) {
        if(!self::direct_headers_present()){return null;}
        if(get_option(MR_Bridge::ENABLED_OPTION,'1')!=='1'){return new WP_Error('mr_direct_disabled','Bridge disabilitato.',array('status'=>403));}
        if(($request instanceof WP_REST_Request && !empty($request->get_query_params())) || (!($request instanceof WP_REST_Request) && !empty($_SERVER['QUERY_STRING']))){return new WP_Error('mr_direct_unsigned_query','Parametri query non ammessi con firma v1.',array('status'=>400));}
        if(!function_exists('sodium_crypto_sign_verify_detached')){
            return new WP_Error('mr_direct_sodium_missing','Ed25519 non disponibile sul server.',array('status'=>503));
        }
        $client_id=self::client_id($_SERVER['HTTP_X_MR_CLIENT']??'');
        $timestamp=(string)($_SERVER['HTTP_X_MR_TIMESTAMP']??'');
        $nonce=(string)($_SERVER['HTTP_X_MR_NONCE']??'');
        $signature_b64=trim((string)($_SERVER['HTTP_X_MR_SIGNATURE']??''));
        if($client_id===''||!preg_match('/^[0-9]{10}$/',$timestamp)){
            return new WP_Error('mr_direct_headers','Header firma diretta non validi.',array('status'=>401));
        }
        if(!preg_match('/^[A-Za-z0-9._:-]{20,96}$/',$nonce)){
            return new WP_Error('mr_direct_nonce','Nonce firma diretta non valido.',array('status'=>401));
        }
        $ts=(int)$timestamp;
        if(abs(time()-$ts)>self::CLOCK_SKEW){
            return new WP_Error('mr_direct_clock','Firma diretta scaduta o clock non sincronizzato.',array('status'=>401));
        }
        $clients=self::clients();
        $client=isset($clients[$client_id])&&is_array($clients[$client_id])?$clients[$client_id]:null;
        if(!$client||!empty($client['revoked_at'])){
            return new WP_Error('mr_direct_client','Client diretto non autorizzato.',array('status'=>403));
        }
        $scopes=isset($client['scopes'])&&is_array($client['scopes'])?$client['scopes']:array();
        if(!in_array((string)$scope,$scopes,true)){
            return new WP_Error('mr_direct_scope','Scope diretto non autorizzato.',array('status'=>403));
        }
        $nonce_key=self::nonce_key($client_id,$nonce);
        $nonce_seen=get_option($nonce_key,null);
        if($nonce_seen!==null){
            if((time()-(int)$nonce_seen)<=self::NONCE_TTL){
                return new WP_Error('mr_direct_replay','Nonce già utilizzato.',array('status'=>409));
            }
            delete_option($nonce_key);
        }
        $public_key=base64_decode((string)($client['public_key_base64']??''),true);
        $signature=base64_decode($signature_b64,true);
        if($public_key===false||strlen($public_key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            ||$signature===false||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES){
            return new WP_Error('mr_direct_key','Chiave o firma diretta non valida.',array('status'=>401));
        }
        $method=self::request_method($request);
        $route=self::request_route($request);
        $body=self::request_body($request);
        $message=self::canonical($method,$route,$timestamp,$nonce,$body);
        if(!sodium_crypto_sign_verify_detached($signature,$message,$public_key)){
            return new WP_Error('mr_direct_signature','Firma diretta non valida.',array('status'=>401));
        }
        if(!add_option($nonce_key,time(),'no')){
            return new WP_Error('mr_direct_replay','Nonce già utilizzato.',array('status'=>409));
        }
        $claims=array(
            'auth_mode'=>'mr-direct-ed25519','client_id'=>$client_id,'actor_id'=>$client_id,
            'run_id'=>'direct:'.substr(hash('sha256',$client_id.':'.$nonce),0,20),'scope'=>(string)$scope,
        );
        if(class_exists('MR_Bridge')){
            MR_Bridge::log('direct_auth_ok',array('client_id'=>$client_id,'scope'=>(string)$scope,'route'=>$route,'method'=>$method));
        }
        return $claims;
    }

    private static function manage_or_signed($scope,$request=null) {
        if(class_exists('MR_Bridge')&&MR_Bridge::can_manage()){return true;}
        $claims=self::verify_global($scope,$request);
        return $claims===null?new WP_Error('mr_direct_missing','Autenticazione diretta richiesta.',array('status'=>401))
            :(is_wp_error($claims)?$claims:true);
    }

    public static function can_read($request){return self::manage_or_signed('read',$request);}
    public static function can_packages_write($request){return self::manage_or_signed('packages:write',$request);}
    public static function can_deploy($request){return self::manage_or_signed('deploy:execute',$request);}
    public static function can_jobs_write($request){
        if(MR_Bridge::can_manage()){return true;}
        $result=self::manage_or_signed('jobs:write',$request);
        if(is_wp_error($result)){return $result;}
        $rows=self::clients();$id=self::client_id($_SERVER['HTTP_X_MR_CLIENT']??'');
        if(!in_array('deploy:execute',(array)($rows[$id]['scopes']??array()),true)){
            return new WP_Error('mr_direct_job_deploy_scope','Scope deploy richiesto per i job.',array('status'=>403));
        }
        return true;
    }

    public static function clients_list() {
        $out=array();
        foreach(self::clients() as $id=>$row){
            $out[]=array(
                'client_id'=>$id,'label'=>(string)($row['label']??''),'scopes'=>array_values((array)($row['scopes']??array())),
                'created_at'=>(string)($row['created_at']??''),'revoked_at'=>(string)($row['revoked_at']??''),
                'public_key_sha256'=>(string)($row['public_key_sha256']??''),
            );
        }
        return rest_ensure_response(array('ok'=>true,'clients'=>$out));
    }

    public static function client_register(WP_REST_Request $request) {
        if(!function_exists('sodium_crypto_sign_verify_detached')){
            return new WP_Error('mr_direct_sodium_missing','Ed25519 non disponibile sul server.',array('status'=>503));
        }
        $body=(array)$request->get_json_params();
        $client_id=self::client_id($body['client_id']??'');
        $public_b64=trim((string)($body['public_key_base64']??''));
        $label=sanitize_text_field((string)($body['label']??$client_id));
        $key=base64_decode($public_b64,true);
        if($client_id===''||$key===false||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES){
            return new WP_Error('mr_direct_register','Client ID o chiave pubblica Ed25519 non validi.',array('status'=>400));
        }
        $requested=isset($body['scopes'])&&is_array($body['scopes'])?$body['scopes']:self::scopes_allowed();
        $scopes=array_values(array_intersect(self::scopes_allowed(),array_map('strval',$requested)));
        if(empty($scopes)){return new WP_Error('mr_direct_scopes','Almeno uno scope autorizzato è obbligatorio.',array('status'=>400));}
        $fingerprint=hash('sha256',$key);
        if(($body['confirm']??'')!=='REGISTER-DIRECT:'.$client_id.':'.$fingerprint){
            return new WP_Error('mr_direct_confirm','Conferma registrazione client non valida.',array('status'=>400));
        }
        $rows=self::clients();
        if(isset($rows[$client_id])&&empty($rows[$client_id]['revoked_at'])){
            return new WP_Error('mr_direct_exists','Client ID già registrato.',array('status'=>409));
        }
        $rows[$client_id]=array(
            'label'=>$label,'public_key_base64'=>base64_encode($key),'public_key_sha256'=>$fingerprint,
            'scopes'=>$scopes,'created_at'=>current_time('mysql',true),'revoked_at'=>'',
        );
        self::save_clients($rows);
        MR_Bridge::log('direct_client_registered',array('client_id'=>$client_id,'public_key_sha256'=>$fingerprint,'scopes'=>$scopes));
        return rest_ensure_response(array(
            'ok'=>true,'client_id'=>$client_id,'public_key_sha256'=>$fingerprint,'scopes'=>$scopes,'private_key_stored_on_server'=>false,
        ));
    }

    public static function client_revoke(WP_REST_Request $request) {
        $body=(array)$request->get_json_params();
        $client_id=self::client_id($body['client_id']??'');
        if($client_id===''||($body['confirm']??'')!=='REVOKE-DIRECT:'.$client_id){
            return new WP_Error('mr_direct_revoke','Conferma revoca client non valida.',array('status'=>400));
        }
        $rows=self::clients();
        if(!isset($rows[$client_id])){return new WP_Error('mr_direct_missing_client','Client non trovato.',array('status'=>404));}
        $rows[$client_id]['revoked_at']=current_time('mysql',true);
        self::save_clients($rows);
        MR_Bridge::log('direct_client_revoked',array('client_id'=>$client_id));
        return rest_ensure_response(array('ok'=>true,'client_id'=>$client_id,'revoked'=>true));
    }

    private static function registry_root() {
        $root=trailingslashit(WP_CONTENT_DIR).'mr-bridge-registry';
        if(!is_dir($root)&&!wp_mkdir_p($root)){
            return new WP_Error('mr_registry_root','Registro pacchetti non creabile.',array('status'=>500));
        }
        $guards=array(
            'index.php'=>"<?php\n// Silence is golden.\n",
            '.htaccess'=>"Deny from all\n",
            'web.config'=>'<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*"/></authorization></system.webServer></configuration>',
        );
        foreach($guards as $name=>$contents){if(!file_exists($root.'/'.$name)){@file_put_contents($root.'/'.$name,$contents);}}
        foreach(array('incoming','packages') as $sub){
            if(!is_dir($root.'/'.$sub)&&!wp_mkdir_p($root.'/'.$sub)){
                return new WP_Error('mr_registry_subdir','Cartella registro pacchetti non creabile.',array('status'=>500));
            }
        }
        return $root;
    }

    private static function package_specs() {
        return class_exists('MR_Bridge_Deploy_V1')?MR_Bridge_Deploy_V1::plugin_specs():array();
    }

    private static function request_id($value) {
        $id=(string)$value;
        return preg_match('/^[A-Za-z0-9._:-]{16,96}$/',$id)?$id:'';
    }

    private static function requests() {
        $rows=get_option(self::REQUESTS_OPTION,array());
        return is_array($rows)?$rows:array();
    }

    private static function save_request($request_id,$row) {
        $rows=self::requests();$rows[$request_id]=$row;
        if(count($rows)>200){$rows=array_slice($rows,-200,null,true);}
        update_option(self::REQUESTS_OPTION,$rows,false);
    }

    private static function upload_dir($upload_id) {
        if(!preg_match('/^mraup-[a-z0-9-]{12,80}$/',(string)$upload_id)){return null;}
        $root=self::registry_root();if(is_wp_error($root)){return $root;}
        return $root.'/incoming/'.$upload_id;
    }

    private static function package_dir($package_id) {
        if(!preg_match('/^mrpkg-[a-z0-9-]{16,180}$/',(string)$package_id)){return null;}
        $root=self::registry_root();if(is_wp_error($root)){return $root;}
        return $root.'/packages/'.$package_id;
    }

    private static function read_json($path) {
        if(!is_file($path)){return null;}
        $data=json_decode((string)@file_get_contents($path),true);
        return is_array($data)?$data:null;
    }

    private static function write_json($path,$data) {
        return @file_put_contents($path,wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false;
    }

    private static function remove_tree($path) {
        if(!is_dir($path)){return !file_exists($path);}
        $items=scandir($path);if(!is_array($items)){return false;}
        foreach($items as $item){
            if($item==='.'||$item==='..'){continue;}
            $child=$path.'/'.$item;
            if(is_link($child)||is_file($child)){if(!@unlink($child)){return false;}}
            elseif(is_dir($child)&&!self::remove_tree($child)){return false;}
        }
        return @rmdir($path);
    }

    public static function package_start(WP_REST_Request $request) {
        $body=(array)$request->get_json_params();
        $request_id=self::request_id($body['request_id']??'');
        $slug=sanitize_key((string)($body['slug']??''));
        $version=sanitize_text_field((string)($body['version']??''));
        $sha=strtolower(trim((string)($body['sha256']??'')));
        $total=(int)($body['total_size']??0);
        $specs=self::package_specs();
        if($request_id===''){return new WP_Error('mr_registry_request','request_id non valido.',array('status'=>400));}
        if(!isset($specs[$slug])){return new WP_Error('mr_registry_slug','Plugin fuori allowlist.',array('status'=>403));}
        if($version===''||strlen($version)>64){return new WP_Error('mr_registry_version','Versione pacchetto non valida.',array('status'=>400));}
        if(!preg_match('/^[a-f0-9]{64}$/',$sha)){return new WP_Error('mr_registry_sha','SHA-256 pacchetto non valido.',array('status'=>400));}
        if($total<1||$total>(int)$specs[$slug]['max_bytes']){return new WP_Error('mr_registry_size','Dimensione pacchetto non consentita.',array('status'=>413));}
        $fingerprint=hash('sha256',wp_json_encode(array($slug,$version,$sha,$total)));
        $requests=self::requests();
        if(isset($requests[$request_id])){
            $old=$requests[$request_id];
            if(($old['fingerprint']??'')!==$fingerprint){
                return new WP_Error('mr_registry_idempotency','request_id già usato con pacchetto diverso.',array('status'=>409));
            }
            return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'upload_id'=>(string)($old['upload_id']??''),'chunk_bytes'=>self::CHUNK_BYTES));
        }
        $upload_id='mraup-'.gmdate('YmdHis').'-'.strtolower(wp_generate_password(10,false,false));
        $dir=self::upload_dir($upload_id);
        if($dir===null||is_wp_error($dir)||!wp_mkdir_p($dir)){
            return new WP_Error('mr_registry_upload_dir','Cartella upload registro non creabile.',array('status'=>500));
        }
        $manifest=array(
            'schema'=>1,'upload_id'=>$upload_id,'request_id'=>$request_id,'slug'=>$slug,'version'=>$version,'sha256'=>$sha,
            'total_size'=>$total,'received'=>0,'next_index'=>0,'last_chunk_index'=>-1,'last_chunk_sha256'=>'',
            'status'=>'uploading','created_at'=>current_time('mysql',true),
        );
        if(!self::write_json($dir.'/manifest.json',$manifest)||@file_put_contents($dir.'/package.zip','')===false){
            self::remove_tree($dir);return new WP_Error('mr_registry_manifest','Manifest registro non scrivibile.',array('status'=>500));
        }
        self::save_request($request_id,array('status'=>'uploading','fingerprint'=>$fingerprint,'upload_id'=>$upload_id,'slug'=>$slug,'version'=>$version));
        MR_Bridge::log('registry_upload_started',array('slug'=>$slug,'version'=>$version,'sha256'=>$sha,'request_id'=>$request_id));
        return rest_ensure_response(array('ok'=>true,'upload_id'=>$upload_id,'chunk_bytes'=>self::CHUNK_BYTES));
    }

    public static function package_chunk(WP_REST_Request $request) {
        $body=(array)$request->get_json_params();
        $upload_id=sanitize_text_field((string)($body['upload_id']??''));
        $dir=self::upload_dir($upload_id);
        if($dir===null||is_wp_error($dir)){return new WP_Error('mr_registry_upload_id','upload_id non valido.',array('status'=>400));}
        $m=self::read_json($dir.'/manifest.json');
        if(!$m||($m['status']??'')!=='uploading'){return new WP_Error('mr_registry_upload_missing','Upload registro non disponibile.',array('status'=>404));}
        $index=(int)($body['index']??-1);
        $chunk=base64_decode((string)($body['data_base64']??''),true);
        $chunk_sha=strtolower(trim((string)($body['chunk_sha256']??'')));
        if($chunk===false||strlen($chunk)<1||strlen($chunk)>self::CHUNK_BYTES){return new WP_Error('mr_registry_chunk','Chunk non valido.',array('status'=>400));}
        if(!preg_match('/^[a-f0-9]{64}$/',$chunk_sha)||!hash_equals($chunk_sha,hash('sha256',$chunk))){
            return new WP_Error('mr_registry_chunk_sha','SHA chunk non valido.',array('status'=>409));
        }
        if($index===(int)($m['last_chunk_index']??-2)&&hash_equals((string)($m['last_chunk_sha256']??''),$chunk_sha)){
            return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'received'=>(int)$m['received'],'next_index'=>(int)$m['next_index']));
        }
        if($index!==(int)$m['next_index']){return new WP_Error('mr_registry_chunk_order','Indice chunk inatteso.',array('status'=>409));}
        if((int)$m['received']+strlen($chunk)>(int)$m['total_size']){return new WP_Error('mr_registry_chunk_overflow','Upload oltre dimensione dichiarata.',array('status'=>409));}
        $fh=@fopen($dir.'/package.zip','c+b');
        if(!$fh||!flock($fh,LOCK_EX)){if(is_resource($fh)){fclose($fh);}return new WP_Error('mr_registry_chunk_write','Pacchetto non bloccabile.',array('status'=>500));}
        $offset=(int)$m['received'];$ok=fseek($fh,$offset)===0&&fwrite($fh,$chunk)===strlen($chunk);
        fflush($fh);flock($fh,LOCK_UN);fclose($fh);
        if(!$ok){return new WP_Error('mr_registry_chunk_write','Scrittura chunk fallita.',array('status'=>500));}
        $m['received']=$offset+strlen($chunk);$m['next_index']=$index+1;$m['last_chunk_index']=$index;$m['last_chunk_sha256']=$chunk_sha;
        $m['updated_at']=current_time('mysql',true);
        if(!self::write_json($dir.'/manifest.json',$m)){return new WP_Error('mr_registry_manifest','Aggiornamento manifest fallito.',array('status'=>500));}
        return rest_ensure_response(array('ok'=>true,'received'=>$m['received'],'next_index'=>$m['next_index']));
    }

    public static function package_finalize(WP_REST_Request $request) {
        $body=(array)$request->get_json_params();$upload_id=sanitize_text_field((string)($body['upload_id']??''));
        $dir=self::upload_dir($upload_id);
        if($dir===null||is_wp_error($dir)){return new WP_Error('mr_registry_upload_id','upload_id non valido.',array('status'=>400));}
        $m=self::read_json($dir.'/manifest.json');
        if(!$m){return new WP_Error('mr_registry_upload_missing','Upload registro non disponibile.',array('status'=>404));}
        if(($body['confirm']??'')!=='REGISTER:'.$m['slug'].':'.$m['sha256']){
            return new WP_Error('mr_registry_confirm','Conferma registrazione pacchetto non valida.',array('status'=>400));
        }
        if(($m['status']??'')==='registered'&&!empty($m['package_id'])){
            return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'package_id'=>$m['package_id']));
        }
        $zip=$dir.'/package.zip';
        if((int)$m['received']!==(int)$m['total_size']||!is_file($zip)||filesize($zip)!==(int)$m['total_size']){
            return new WP_Error('mr_registry_incomplete','Upload pacchetto incompleto.',array('status'=>409));
        }
        $actual=hash_file('sha256',$zip);
        if(!hash_equals((string)$m['sha256'],$actual)){return new WP_Error('mr_registry_sha_mismatch','SHA-256 pacchetto non corrispondente.',array('status'=>409));}
        $valid=MR_Bridge_Deploy_V1::validate_local_package($zip,(string)$m['slug'],(string)$m['version'],(string)$m['sha256']);
        if(is_wp_error($valid)){return $valid;}
        $package_id='mrpkg-'.gmdate('YmdHis').'-'.sanitize_key((string)$m['slug']).'-'.substr($actual,0,12);
        $target=self::package_dir($package_id);
        if($target===null||is_wp_error($target)){return new WP_Error('mr_registry_package_id','Package ID non valido.',array('status'=>500));}
        if(is_dir($target)){
            $old=self::read_json($target.'/manifest.json');
            if(is_array($old)&&hash_equals((string)($old['sha256']??''),$actual)){
                return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'package_id'=>$package_id,'package'=>$old));
            }
            return new WP_Error('mr_registry_collision','Collisione Package ID.',array('status'=>409));
        }
        $m['status']='registered';$m['package_id']=$package_id;$m['registered_at']=current_time('mysql',true);$m['validation']=$valid;
        if(!self::write_json($dir.'/manifest.json',$m)){return new WP_Error('mr_registry_manifest','Manifest finale non scrivibile.',array('status'=>500));}
        if(!@rename($dir,$target)){return new WP_Error('mr_registry_atomic','Registrazione atomica pacchetto fallita.',array('status'=>500));}
        self::save_request((string)$m['request_id'],array(
            'status'=>'registered','fingerprint'=>hash('sha256',wp_json_encode(array($m['slug'],$m['version'],$m['sha256'],$m['total_size']))),
            'upload_id'=>$upload_id,'package_id'=>$package_id,'slug'=>$m['slug'],'version'=>$m['version'],
        ));
        self::prune_packages((string)$m['slug']);
        MR_Bridge::log('registry_package_registered',array('package_id'=>$package_id,'slug'=>$m['slug'],'version'=>$m['version'],'sha256'=>$actual));
        return rest_ensure_response(array('ok'=>true,'package_id'=>$package_id,'package'=>$m));
    }

    private static function package_load($package_id) {
        $dir=self::package_dir($package_id);
        if($dir===null||is_wp_error($dir)||!is_dir($dir)){return new WP_Error('mr_registry_package_missing','Pacchetto non trovato.',array('status'=>404));}
        $m=self::read_json($dir.'/manifest.json');
        if(!is_array($m)||($m['package_id']??'')!==$package_id||!is_file($dir.'/package.zip')){
            return new WP_Error('mr_registry_package_invalid','Pacchetto registrato non valido.',array('status'=>409));
        }
        $actual=hash_file('sha256',$dir.'/package.zip');
        if(!hash_equals((string)($m['sha256']??''),$actual)){return new WP_Error('mr_registry_package_tampered','Pacchetto registrato modificato.',array('status'=>409));}
        return array($dir,$m);
    }

    private static function list_package_rows() {
        $root=self::registry_root();if(is_wp_error($root)){return array();}
        $base=$root.'/packages';$rows=array();
        foreach((array)scandir($base) as $name){
            if($name==='.'||$name==='..'||strpos($name,'mrpkg-')!==0){continue;}
            $m=self::read_json($base.'/'.$name.'/manifest.json');if(is_array($m)){$rows[]=$m;}
        }
        usort($rows,function($a,$b){return strcmp((string)($b['registered_at']??''),(string)($a['registered_at']??''));});
        return $rows;
    }

    private static function prune_packages($slug) {
        $rows=array();
        foreach(self::list_package_rows() as $m){if(($m['slug']??'')===$slug&&!empty($m['package_id'])){$rows[]=$m;}}
        foreach(array_slice($rows,self::MAX_PACKAGES_PER_SLUG) as $m){
            $dir=self::package_dir((string)$m['package_id']);if(is_string($dir)&&is_dir($dir)){self::remove_tree($dir);}
        }
    }

    public static function packages_list() {
        return rest_ensure_response(array('ok'=>true,'packages'=>self::list_package_rows(),'retention_per_slug'=>self::MAX_PACKAGES_PER_SLUG));
    }

    private static function execute_deploy_payload($body) {
        $request_id=self::request_id($body['request_id']??'');$package_id=sanitize_text_field((string)($body['package_id']??''));
        if($request_id===''){return new WP_Error('mr_autonomous_request','request_id non valido.',array('status'=>400));}
        $loaded=self::package_load($package_id);if(is_wp_error($loaded)){return $loaded;}list($dir,$m)=$loaded;
        if(($body['confirm']??'')!=='DEPLOY-REGISTERED:'.$package_id.':'.$m['sha256']){
            return new WP_Error('mr_autonomous_confirm','Conferma deploy registrato non valida.',array('status'=>400));
        }
        $activate=array_key_exists('activate',$body)?(bool)$body['activate']:true;
        $result=MR_Bridge_Deploy_V1::deploy_local_package($dir.'/package.zip',(string)$m['slug'],(string)$m['version'],(string)$m['sha256'],$request_id,$activate);
        if(is_wp_error($result)){return $result;}
        MR_Bridge::log('autonomous_registered_deploy',array('package_id'=>$package_id,'slug'=>$m['slug'],'version'=>$m['version'],'sha256'=>$m['sha256'],'request_id'=>$request_id));
        return $result;
    }

    public static function deploy_registered(WP_REST_Request $request) {
        $result=self::execute_deploy_payload((array)$request->get_json_params());
        return is_wp_error($result)?$result:rest_ensure_response($result);
    }

    private static function execute_rollback_payload($body) {
        $request_id=self::request_id($body['request_id']??'');$backup_id=sanitize_text_field((string)($body['backup_id']??''));
        if($request_id===''||($body['confirm']??'')!=='ROLLBACK-REGISTERED:'.$backup_id){
            return new WP_Error('mr_autonomous_rollback_confirm','Conferma rollback registrato non valida.',array('status'=>400));
        }
        $result=MR_Bridge_Deploy_V1::rollback_local($backup_id,$request_id);
        if(is_wp_error($result)){return $result;}
        MR_Bridge::log('autonomous_registered_rollback',array('backup_id'=>$backup_id,'request_id'=>$request_id));
        return $result;
    }

    public static function rollback_registered(WP_REST_Request $request) {
        $result=self::execute_rollback_payload((array)$request->get_json_params());
        return is_wp_error($result)?$result:rest_ensure_response($result);
    }

    private static function jobs() {
        $rows=get_option(self::JOBS_OPTION,array());return is_array($rows)?$rows:array();
    }

    private static function save_jobs($rows) {
        if(count($rows)>self::MAX_JOBS){$rows=array_slice($rows,-self::MAX_JOBS,null,true);}
        update_option(self::JOBS_OPTION,$rows,false);
    }

    public static function jobs_list() {
        $rows=self::jobs();return rest_ensure_response(array('ok'=>true,'jobs'=>array_values(array_reverse($rows,true))));
    }

    public static function job_add(WP_REST_Request $request) {
        if(!add_option('mr_autonomous_jobs_lock',time(),'','no')){return new WP_Error('mr_autonomous_jobs_busy','Coda occupata.',array('status'=>409));}
        try{return self::job_add_unlocked($request);}finally{delete_option('mr_autonomous_jobs_lock');}
    }

    private static function job_add_unlocked(WP_REST_Request $request) {
        $body=(array)$request->get_json_params();$type=sanitize_key((string)($body['type']??''));$request_id=self::request_id($body['request_id']??'');
        if(!in_array($type,array('deploy','rollback'),true)){return new WP_Error('mr_autonomous_job_type','Tipo job non autorizzato.',array('status'=>403));}
        if($request_id===''){return new WP_Error('mr_autonomous_job_request','request_id non valido.',array('status'=>400));}
        $jobs=self::jobs();
        foreach($jobs as $row){if(($row['request_id']??'')===$request_id){return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'job'=>$row));}}
        $payload=isset($body['payload'])&&is_array($body['payload'])?$body['payload']:array();
        $when=(int)($body['not_before']??time());if($when<time()){$when=time();}
        if($when>time()+7*DAY_IN_SECONDS){return new WP_Error('mr_autonomous_job_time','Job pianificabile al massimo 7 giorni avanti.',array('status'=>400));}
        $id='mraj-'.gmdate('YmdHis').'-'.strtolower(wp_generate_password(8,false,false));
        $job=array('id'=>$id,'request_id'=>$request_id,'type'=>$type,'payload'=>$payload,'status'=>'queued','created_at'=>current_time('mysql',true),'not_before'=>$when);
        $job['owner_client']=MR_Bridge::can_manage()?'':self::client_id($_SERVER['HTTP_X_MR_CLIENT']??'');
        $owner=$job['owner_client'];$clients=self::clients();
        $job['owner_key_sha256']=$owner===''?'':hash('sha256',base64_decode((string)($clients[$owner]['public_key_base64']??'')));
        $jobs[$id]=$job;self::save_jobs($jobs);
        if(!wp_next_scheduled('mr_bridge_autonomous_job',array($id))){wp_schedule_single_event(max(time()+1,$when),'mr_bridge_autonomous_job',array($id));}
        MR_Bridge::log('autonomous_job_queued',array('job_id'=>$id,'type'=>$type,'request_id'=>$request_id,'not_before'=>$when));
        return rest_ensure_response(array('ok'=>true,'job'=>$job,'scheduled'=>true));
    }

    public static function job_run_endpoint(WP_REST_Request $request) {
        $body=(array)$request->get_json_params();$job_id=sanitize_text_field((string)($body['job_id']??''));
        if(($body['confirm']??'')!=='RUN:'.$job_id){return new WP_Error('mr_autonomous_job_confirm','Conferma avvio job non valida.',array('status'=>400));}
        self::run_job($job_id);$jobs=self::jobs();
        if(!isset($jobs[$job_id])){return new WP_Error('mr_autonomous_job_missing','Job non trovato.',array('status'=>404));}
        return rest_ensure_response(array('ok'=>true,'job'=>$jobs[$job_id]));
    }

    public static function run_job($job_id) {
        $lock='mr_autonomous_jobs_lock';
        if(!add_option($lock,time(),'','no')){return;}
        try{self::run_job_unlocked($job_id);}finally{delete_option($lock);}
    }

    private static function run_job_unlocked($job_id) {
        $jobs=self::jobs();
        if(!isset($jobs[$job_id])||($jobs[$job_id]['status']??'')!=='queued'){return;}
        $owner=(string)($jobs[$job_id]['owner_client']??'');$clients=self::clients();
        $allowed=get_option(MR_Bridge::ENABLED_OPTION,'1')==='1';
        if($owner!==''){
            $client=$clients[$owner]??array();$scopes=(array)($client['scopes']??array());
            $allowed=$allowed&&!empty($client)&&empty($client['revoked_at'])&&in_array('jobs:write',$scopes,true)&&in_array('deploy:execute',$scopes,true)
                &&hash_equals((string)($jobs[$job_id]['owner_key_sha256']??''),hash('sha256',base64_decode((string)($client['public_key_base64']??''))));
        }elseif(!array_key_exists('owner_client',$jobs[$job_id])){$allowed=false;}
        if(!$allowed){
            $jobs[$job_id]['status']='failed';$jobs[$job_id]['error_code']='authorization_revoked';self::save_jobs($jobs);return;
        }
        if((int)($jobs[$job_id]['not_before']??0)>time()){
            wp_schedule_single_event((int)$jobs[$job_id]['not_before'],'mr_bridge_autonomous_job',array($job_id));return;
        }
        $jobs[$job_id]['status']='running';$jobs[$job_id]['started_at']=current_time('mysql',true);self::save_jobs($jobs);
        try{
            $type=(string)$jobs[$job_id]['type'];$payload=(array)$jobs[$job_id]['payload'];
            $result=$type==='deploy'?self::execute_deploy_payload($payload):self::execute_rollback_payload($payload);
            $jobs=self::jobs();
            if(is_wp_error($result)){$jobs[$job_id]['status']='failed';$jobs[$job_id]['error_code']=$result->get_error_code();}
            else{$jobs[$job_id]['status']='complete';$jobs[$job_id]['result']=$result;}
            $jobs[$job_id]['completed_at']=current_time('mysql',true);self::save_jobs($jobs);
            MR_Bridge::log('autonomous_job_finished',array('job_id'=>$job_id,'type'=>$type,'status'=>$jobs[$job_id]['status']));
        }catch(Throwable $e){
            $jobs=self::jobs();$jobs[$job_id]['status']='failed';$jobs[$job_id]['error_code']='exception';
            $jobs[$job_id]['completed_at']=current_time('mysql',true);self::save_jobs($jobs);
            MR_Bridge::log('autonomous_job_failed',array('job_id'=>$job_id,'type'=>(string)($jobs[$job_id]['type']??'')));
        }
    }

    public static function status() {
        $active=0;foreach(self::clients() as $row){if(is_array($row)&&empty($row['revoked_at'])){$active++;}}
        $root=self::registry_root();$jobs=self::jobs();$queued=0;$running=0;$failed=0;
        foreach($jobs as $j){$s=(string)($j['status']??'');if($s==='queued'){$queued++;}elseif($s==='running'){$running++;}elseif($s==='failed'){$failed++;}}
        return rest_ensure_response(array(
            'ok'=>!is_wp_error($root)&&function_exists('sodium_crypto_sign_verify_detached'),
            'version'=>class_exists('MR_Bridge')?MR_Bridge::VERSION:'',
            'environment'=>class_exists('MR_Bridge_Deploy_V1')?MR_Bridge_Deploy_V1::environment():'',
            'direct_auth'=>array(
                'mode'=>'ed25519-public-key','available'=>function_exists('sodium_crypto_sign_verify_detached'),'active_clients'=>$active,
                'server_stores_private_keys'=>false,'clock_skew_seconds'=>self::CLOCK_SKEW,'replay_window_seconds'=>self::NONCE_TTL,
            ),
            'registry'=>array(
                'ready'=>!is_wp_error($root),'package_count'=>count(self::list_package_rows()),'sha256_required'=>true,
                'atomic_registration'=>true,'retention_per_slug'=>self::MAX_PACKAGES_PER_SLUG,
            ),
            'jobs'=>array('queued'=>$queued,'running'=>$running,'failed'=>$failed,'scheduler'=>'wp-cron-single-event'),
            'github'=>array('required_for_normal_signed_requests'=>false,'kept_as_fallback'=>true),
            'arbitrary_php'=>false,'arbitrary_sql'=>false,'arbitrary_shell'=>false,
        ));
    }
}

