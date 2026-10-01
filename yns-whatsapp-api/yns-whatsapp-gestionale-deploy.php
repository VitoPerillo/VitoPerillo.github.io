<?php
if (!defined('ABSPATH')) { exit; }

final class YNS_WhatsApp_Gestionale_Deploy {
    const NS='yns-whatsapp/v1';
    const STAGING_HOME='https://www.yoganostress.it/staging-gestionale';
    const AUD='https://www.yoganostress.it/staging-gestionale/yns-wa-gestionale-deploy';
    const REPOSITORY='VitoPerillo/yoganostress-wordpress-bridge';
    const REPOSITORY_ID='1390938875';
    const OWNER_ID='317205417';
    const REF='refs/heads/yns-whatsapp-api';
    const WORKFLOW_REF='VitoPerillo/yoganostress-wordpress-bridge/.github/workflows/yns-gestionale-autonomous-staging.yml@refs/heads/yns-whatsapp-api';
    const TARGET_SLUG='yoganostress-prenotazioni';
    const TARGET_MAIN='yoganostress-prenotazioni/yoganostress-prenotazioni.php';

    public static function init() {
        add_action('rest_api_init',[__CLASS__,'routes']);
    }

    public static function routes() {
        register_rest_route(self::NS,'/staging/gestionale-deploy',[
            'methods'=>'POST',
            'callback'=>[__CLASS__,'command'],
            'permission_callback'=>'__return_true',
        ]);
    }

    private static function staging_only() {
        return untrailingslashit(home_url('/'))===self::STAGING_HOME;
    }

    private static function b64url_decode($v) {
        $v=strtr((string)$v,'-_','+/');
        $pad=strlen($v)%4;
        if($pad)$v.=str_repeat('=',4-$pad);
        return base64_decode($v,true);
    }

    private static function auth_header() {
        if(!empty($_SERVER['HTTP_AUTHORIZATION']))return trim((string)$_SERVER['HTTP_AUTHORIZATION']);
        if(function_exists('apache_request_headers')){
            foreach((array)apache_request_headers() as $k=>$v){
                if(strtolower((string)$k)==='authorization')return trim((string)$v);
            }
        }
        return '';
    }

    private static function jwks() {
        $cached=get_transient('yns_wa_gest_deploy_jwks');
        if(is_array($cached)&&!empty($cached['keys']))return $cached;
        $res=wp_remote_get('https://token.actions.githubusercontent.com/.well-known/jwks',[
            'timeout'=>10,'redirection'=>2,'headers'=>['Accept'=>'application/json']
        ]);
        if(is_wp_error($res))return $res;
        if((int)wp_remote_retrieve_response_code($res)!==200)return new WP_Error('yns_gest_jwks_http','JWKS GitHub non disponibile.',['status'=>503]);
        $data=json_decode(wp_remote_retrieve_body($res),true);
        if(!is_array($data)||empty($data['keys']))return new WP_Error('yns_gest_jwks_invalid','JWKS GitHub non valido.',['status'=>503]);
        set_transient('yns_wa_gest_deploy_jwks',$data,HOUR_IN_SECONDS);
        return $data;
    }

    private static function verify_oidc() {
        if(!self::staging_only())return new WP_Error('yns_gest_staging_only','Deploy disponibile solo staging.',['status'=>403]);
        $auth=self::auth_header();
        if(!preg_match('/^Bearer\s+(.+)$/i',$auth,$m))return new WP_Error('yns_gest_auth_missing','OIDC mancante.',['status'=>401]);
        $parts=explode('.',trim($m[1]));
        if(count($parts)!==3)return new WP_Error('yns_gest_jwt_invalid','JWT non valido.',['status'=>401]);
        $head=json_decode(self::b64url_decode($parts[0]),true);
        $claims=json_decode(self::b64url_decode($parts[1]),true);
        $sig=self::b64url_decode($parts[2]);
        if(!is_array($head)||!is_array($claims)||($head['alg']??'')!=='RS256'||empty($head['kid'])||$sig===false){
            return new WP_Error('yns_gest_jwt_header','JWT non valido.',['status'=>401]);
        }
        $jwks=self::jwks();
        if(is_wp_error($jwks))return $jwks;
        $cert=null;
        foreach((array)$jwks['keys'] as $key){
            if(($key['kid']??'')===$head['kid']&&!empty($key['x5c'][0])){
                $cert="-----BEGIN CERTIFICATE-----\n".chunk_split($key['x5c'][0],64,"\n")."-----END CERTIFICATE-----\n";
                break;
            }
        }
        if(!$cert||!function_exists('openssl_verify')||openssl_verify($parts[0].'.'.$parts[1],$sig,$cert,OPENSSL_ALGO_SHA256)!==1){
            return new WP_Error('yns_gest_signature','Firma OIDC non valida.',['status'=>401]);
        }
        $now=time();
        $aud=$claims['aud']??'';
        $aud_ok=is_array($aud)?in_array(self::AUD,$aud,true):hash_equals(self::AUD,(string)$aud);
        $checks=[
            'iss'=>(($claims['iss']??'')==='https://token.actions.githubusercontent.com'),
            'aud'=>$aud_ok,
            'exp'=>(!empty($claims['exp'])&&(int)$claims['exp']>=$now-30),
            'nbf'=>(empty($claims['nbf'])||(int)$claims['nbf']<=$now+30),
            'repository'=>(($claims['repository']??'')===self::REPOSITORY),
            'repository_id'=>((string)($claims['repository_id']??'')===self::REPOSITORY_ID),
            'owner_id'=>((string)($claims['repository_owner_id']??'')===self::OWNER_ID),
            'ref'=>(($claims['ref']??'')===self::REF),
            'workflow_ref'=>(($claims['workflow_ref']??'')===self::WORKFLOW_REF),
            'event_name'=>(($claims['event_name']??'')==='push'),
        ];
        foreach($checks as $name=>$ok)if(!$ok)return new WP_Error('yns_gest_claim_'.$name,'Claim OIDC rifiutato: '.$name,['status'=>403]);
        return $claims;
    }

    private static function remove_tree($dir) {
        if(!is_dir($dir))return true;
        $items=scandir($dir);
        if($items===false)return false;
        foreach($items as $item){
            if($item==='.'||$item==='..')continue;
            $path=$dir.DIRECTORY_SEPARATOR.$item;
            if(is_dir($path)&&!is_link($path)){
                if(!self::remove_tree($path))return false;
            }else{
                if(!@unlink($path))return false;
            }
        }
        return @rmdir($dir);
    }

    private static function backup_root() {
        $root=WP_CONTENT_DIR.'/yns-gestionale-deploy-backups';
        if(!is_dir($root)&&!wp_mkdir_p($root))return new WP_Error('yns_gest_backup_root','Directory backup non disponibile.',['status'=>500]);
        if(!file_exists($root.'/index.php'))@file_put_contents($root.'/index.php',"<?php\n// Silence is golden.\n");
        if(!file_exists($root.'/.htaccess'))@file_put_contents($root.'/.htaccess',"Deny from all\n");
        return $root;
    }

    private static function manifest_file($backup_id) {
        return WP_CONTENT_DIR.'/yns-gestionale-deploy-backups/'.$backup_id.'/manifest.json';
    }

    private static function save_manifest($dir,$data) {
        return @file_put_contents($dir.'/manifest.json',wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false;
    }

    private static function deploy($body) {
        if(!self::staging_only())return new WP_Error('yns_gest_staging_only','Deploy disponibile solo staging.',['status'=>403]);
        $slug=sanitize_key($body['slug']??'');
        $version=trim((string)($body['version']??''));
        $sha=strtolower(trim((string)($body['sha256']??'')));
        $b64=(string)($body['zip_base64']??'');
        if($slug!==self::TARGET_SLUG)return new WP_Error('yns_gest_slug_denied','Slug non autorizzato.',['status'=>403]);
        if(!preg_match('/^0\.4\.25-security-GO-LIVE-RC2-WA[0-9]+$/',$version)){
            return new WP_Error('yns_gest_version_denied','Versione non autorizzata.',['status'=>403]);
        }
        if(!preg_match('/^[a-f0-9]{64}$/',$sha))return new WP_Error('yns_gest_sha_invalid','SHA-256 non valido.',['status'=>400]);
        if($b64===''||strlen($b64)>3*1024*1024)return new WP_Error('yns_gest_package_size','Pacchetto non valido.',['status'=>409]);
        $raw=base64_decode($b64,true);
        if($raw===false||strlen($raw)>2*1024*1024||!hash_equals($sha,hash('sha256',$raw))){
            return new WP_Error('yns_gest_package_hash','Pacchetto o SHA-256 non valido.',['status'=>409]);
        }
        if(!class_exists('ZipArchive'))return new WP_Error('yns_gest_zip_missing','ZipArchive non disponibile.',['status'=>500]);

        $tmp_root=WP_CONTENT_DIR.'/yns-gestionale-deploy-tmp';
        if(!is_dir($tmp_root)&&!wp_mkdir_p($tmp_root))return new WP_Error('yns_gest_tmp_root','Directory temporanea non disponibile.',['status'=>500]);
        $token=strtolower(wp_generate_password(10,false,false));
        $zip_path=$tmp_root.'/gest-'.$token.'.zip';
        $extract=$tmp_root.'/gest-'.$token;
        if(@file_put_contents($zip_path,$raw,LOCK_EX)===false||!wp_mkdir_p($extract)){
            @unlink($zip_path);self::remove_tree($extract);
            return new WP_Error('yns_gest_tmp_write','Preparazione pacchetto fallita.',['status'=>500]);
        }

        $zip=new ZipArchive();
        if($zip->open($zip_path)!==true){
            @unlink($zip_path);self::remove_tree($extract);
            return new WP_Error('yns_gest_zip_open','ZIP non valido.',['status'=>409]);
        }
        $main_found=false;
        for($i=0;$i<$zip->numFiles;$i++){
            $name=(string)$zip->getNameIndex($i);
            if($name===''||strpos($name,"\0")!==false||strpos($name,'../')!==false||strpos($name,'..\\')!==false||$name[0]==='/'||strpos($name,self::TARGET_SLUG.'/')!==0){
                $zip->close();@unlink($zip_path);self::remove_tree($extract);
                return new WP_Error('yns_gest_zip_path','Struttura ZIP non autorizzata.',['status'=>409]);
            }
            if($name===self::TARGET_MAIN)$main_found=true;
        }
        if(!$main_found||!$zip->extractTo($extract)){
            $zip->close();@unlink($zip_path);self::remove_tree($extract);
            return new WP_Error('yns_gest_zip_extract','Main file assente o estrazione fallita.',['status'=>409]);
        }
        $zip->close();@unlink($zip_path);

        $incoming=$extract.'/'.self::TARGET_SLUG;
        $main=$incoming.'/yoganostress-prenotazioni.php';
        $header=is_file($main)?(string)file_get_contents($main):'';
        if(strpos($header,'Plugin Name: Yoganostress Prenotazioni')===false||strpos($header,'Version: '.$version)===false||strpos($header,"define('YNS_VERSION', '".$version."');")===false){
            self::remove_tree($extract);
            return new WP_Error('yns_gest_header','Header/versione plugin non valida.',['status'=>409]);
        }
        if(strpos($header,'wp-config.php')!==false){
            self::remove_tree($extract);
            return new WP_Error('yns_gest_header_guard','Contenuto plugin non valido.',['status'=>409]);
        }

        if(!function_exists('is_plugin_active'))require_once ABSPATH.'wp-admin/includes/plugin.php';
        $target=WP_PLUGIN_DIR.'/'.self::TARGET_SLUG;
        if(!is_dir($target)){
            self::remove_tree($extract);
            return new WP_Error('yns_gest_target_missing','Gestionale staging non trovato.',['status'=>404]);
        }
        $was_active=is_plugin_active(self::TARGET_MAIN);
        if(!$was_active){
            self::remove_tree($extract);
            return new WP_Error('yns_gest_inactive','Gestionale staging non attivo: deploy rifiutato.',['status'=>409]);
        }

        $backup_root=self::backup_root();
        if(is_wp_error($backup_root)){self::remove_tree($extract);return $backup_root;}
        $backup_id='yns-gest-'.gmdate('YmdHis').'-'.strtolower(wp_generate_password(6,false,false));
        $backup_dir=$backup_root.'/'.$backup_id;
        if(!wp_mkdir_p($backup_dir)){self::remove_tree($extract);return new WP_Error('yns_gest_backup_create','Creazione backup fallita.',['status'=>500]);}

        $old_main=$target.'/yoganostress-prenotazioni.php';
        $old_sha=is_file($old_main)?hash_file('sha256',$old_main):null;
        $manifest=[
            'schema'=>1,'backup_id'=>$backup_id,'slug'=>self::TARGET_SLUG,
            'created_at'=>current_time('mysql',true),'old_main_sha256'=>$old_sha,
            'incoming_sha256'=>$sha,'incoming_version'=>$version,'was_active'=>true,'status'=>'prepared',
        ];
        if(!self::save_manifest($backup_dir,$manifest)){
            self::remove_tree($extract);self::remove_tree($backup_dir);
            return new WP_Error('yns_gest_manifest','Scrittura manifest fallita.',['status'=>500]);
        }

        if(!@rename($target,$backup_dir.'/plugin')){
            self::remove_tree($extract);
            return new WP_Error('yns_gest_backup_move','Backup atomico fallito.',['status'=>500]);
        }
        if(!@rename($incoming,$target)){
            @rename($backup_dir.'/plugin',$target);
            self::remove_tree($extract);
            return new WP_Error('yns_gest_deploy_move','Deploy atomico fallito; build precedente ripristinata.',['status'=>500]);
        }
        self::remove_tree($extract);

        $written=$target.'/yoganostress-prenotazioni.php';
        if(!is_file($written)||strpos((string)file_get_contents($written),'Version: '.$version)===false){
            self::remove_tree($target);
            @rename($backup_dir.'/plugin',$target);
            return new WP_Error('yns_gest_readback','Readback fallito; rollback automatico eseguito.',['status'=>500]);
        }
        $manifest['status']='deployed';
        $manifest['deployed_at']=current_time('mysql',true);
        $manifest['new_main_sha256']=hash_file('sha256',$written);
        self::save_manifest($backup_dir,$manifest);

        return [
            'ok'=>true,'environment'=>'staging','slug'=>self::TARGET_SLUG,
            'version'=>$version,'sha256'=>$sha,'backup_id'=>$backup_id,
            'was_active'=>true,'readback'=>true,
        ];
    }

    private static function rollback($backup_id) {
        if(!self::staging_only())return new WP_Error('yns_gest_staging_only','Rollback disponibile solo staging.',['status'=>403]);
        if(!preg_match('/^yns-gest-[a-z0-9._-]+$/',(string)$backup_id))return new WP_Error('yns_gest_backup_id','Backup ID non valido.',['status'=>400]);
        $root=self::backup_root();
        if(is_wp_error($root))return $root;
        $dir=$root.'/'.$backup_id;
        $manifest_file=$dir.'/manifest.json';
        $saved=$dir.'/plugin';
        if(!is_file($manifest_file)||!is_dir($saved))return new WP_Error('yns_gest_backup_missing','Backup non trovato o già consumato.',['status'=>404]);
        $manifest=json_decode((string)file_get_contents($manifest_file),true);
        if(!is_array($manifest)||($manifest['slug']??'')!==self::TARGET_SLUG)return new WP_Error('yns_gest_backup_invalid','Manifest backup non valido.',['status'=>409]);
        $target=WP_PLUGIN_DIR.'/'.self::TARGET_SLUG;
        $failed=$dir.'/failed-'.gmdate('YmdHis');
        if(is_dir($target)&&!@rename($target,$failed))return new WP_Error('yns_gest_rollback_current','Impossibile mettere da parte la build corrente.',['status'=>500]);
        if(!@rename($saved,$target)){
            if(is_dir($failed))@rename($failed,$target);
            return new WP_Error('yns_gest_rollback_restore','Ripristino backup fallito.',['status'=>500]);
        }
        self::remove_tree($failed);
        $manifest['status']='rolled_back';
        $manifest['rolled_back_at']=current_time('mysql',true);
        self::save_manifest($dir,$manifest);
        return ['ok'=>true,'environment'=>'staging','backup_id'=>$backup_id,'rolled_back'=>true];
    }

    public static function command(WP_REST_Request $request) {
        $claims=self::verify_oidc();
        if(is_wp_error($claims))return $claims;
        $body=$request->get_json_params();
        if(!is_array($body))return new WP_Error('yns_gest_json','Payload JSON non valido.',['status'=>400]);
        $action=sanitize_key($body['action']??'');
        if($action==='status'){
            if(!function_exists('is_plugin_active'))require_once ABSPATH.'wp-admin/includes/plugin.php';
            $main=WP_PLUGIN_DIR.'/'.self::TARGET_SLUG.'/yoganostress-prenotazioni.php';
            $admin=WP_PLUGIN_DIR.'/'.self::TARGET_SLUG.'/admin/class-yns-admin.php';
            $admin_raw=is_file($admin)?(string)file_get_contents($admin):'';
            $markers=[
                'Consenso comunicazioni',
                'yns_whatsapp_request_optin',
                'yns_whatsapp_revoke_consent',
                '/yns-whatsapp/v1/consent/customer/',
            ];
            $marker_ok=true;
            foreach($markers as $marker)if(strpos($admin_raw,$marker)===false){$marker_ok=false;break;}
            $result=[
                'ok'=>true,'environment'=>'staging','allowlist'=>[self::TARGET_SLUG],
                'sha256_required'=>true,'backup'=>true,'rollback'=>true,
                'plugin_active'=>is_plugin_active(self::TARGET_MAIN),
                'runtime_version'=>defined('YNS_VERSION')?(string)YNS_VERSION:null,
                'main_file_present'=>is_file($main),
                'whatsapp_profile_ui'=>$marker_ok,
            ];
        }elseif($action==='deploy'){
            $result=self::deploy($body);
        }elseif($action==='rollback'){
            $result=self::rollback((string)($body['backup_id']??''));
        }else{
            return new WP_Error('yns_gest_action','Azione non consentita.',['status'=>400]);
        }
        if(is_wp_error($result))return $result;
        return new WP_REST_Response([
            'ok'=>true,'action'=>$action,'result'=>$result,
            'run_id'=>$claims['run_id']??null,'actor_id'=>$claims['actor_id']??null,
        ],200);
    }
}
YNS_WhatsApp_Gestionale_Deploy::init();
