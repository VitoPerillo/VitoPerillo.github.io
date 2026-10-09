<?php
if (!defined('ABSPATH')) { exit; }

final class MR_Bridge_Media_V1 {
    const REST_NAMESPACE = 'mr-bridge/v1';
    const PRODUCTION_HOME = 'https://www.yoganostress.it';
    const OIDC_REPOSITORY = 'VitoPerillo/yoganostress-wordpress-bridge';
    const OIDC_REPOSITORY_ID = '1390938875';
    const OIDC_OWNER_ID = '317205417';
    const OIDC_AUD = 'https://www.yoganostress.it/mr-bridge-media';
    const OIDC_REF = 'refs/heads/main';
    const OIDC_WORKFLOW_REF = 'VitoPerillo/yoganostress-wordpress-bridge/.github/workflows/mr-media-production.yml@refs/heads/main';
    const REQUESTS_OPTION = 'mr_bridge_media_requests_v1';
    const BACKUPS_OPTION = 'mr_bridge_media_backups_v1';
    const MAX_REQUESTS = 100;
    const MAX_BACKUPS = 30;
    const MAX_BYTES = 15728640; // 15 MiB

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/media/get', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'get_media'),'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/media/import', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'import_media'),'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/media/update', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'update_media'),'permission_callback'=>'__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/media/rollback', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'rollback'),'permission_callback'=>'__return_true',
        ));
    }

    private static function b64url_decode($value) {
        $value=strtr((string)$value,'-_','+/');$pad=strlen($value)%4;if($pad){$value.=str_repeat('=',4-$pad);}
        return base64_decode($value,true);
    }

    private static function auth_header() {
        if(!empty($_SERVER['HTTP_AUTHORIZATION'])){return trim((string)$_SERVER['HTTP_AUTHORIZATION']);}
        if(function_exists('apache_request_headers')){foreach((array)apache_request_headers() as $k=>$v){if(strtolower((string)$k)==='authorization'){return trim((string)$v);}}}
        return '';
    }

    private static function github_jwks() {
        $cached=get_transient('mr_bridge_github_jwks');if(is_array($cached)&&!empty($cached['keys'])){return $cached;}
        $res=wp_remote_get('https://token.actions.githubusercontent.com/.well-known/jwks',array('timeout'=>10,'redirection'=>2,'headers'=>array('Accept'=>'application/json')));
        if(is_wp_error($res)){return $res;}
        if((int)wp_remote_retrieve_response_code($res)!==200){return new WP_Error('mr_media_jwks_http','JWKS GitHub non disponibile.',array('status'=>503));}
        $data=json_decode(wp_remote_retrieve_body($res),true);
        if(!is_array($data)||empty($data['keys'])){return new WP_Error('mr_media_jwks_invalid','JWKS GitHub non valido.',array('status'=>503));}
        set_transient('mr_bridge_github_jwks',$data,HOUR_IN_SECONDS);return $data;
    }

    private static function verify_oidc() {
        if(untrailingslashit(home_url('/'))!==self::PRODUCTION_HOME){return new WP_Error('mr_media_production_only','Media bridge disponibile solo sul sito produzione riconosciuto.',array('status'=>403));}
        $direct=class_exists('MR_Bridge_Autonomous_V1')?MR_Bridge_Autonomous_V1::verify_global('media:write'):null;
        if($direct!==null){return $direct;}
        $auth=self::auth_header();
        if(!preg_match('/^Bearer\s+(.+)$/i',$auth,$m)){return new WP_Error('mr_media_oidc_missing','Token OIDC mancante.',array('status'=>401));}
        $parts=explode('.',trim($m[1]));if(count($parts)!==3){return new WP_Error('mr_media_oidc_bad_token','JWT non valido.',array('status'=>401));}
        $header_raw=self::b64url_decode($parts[0]);$payload_raw=self::b64url_decode($parts[1]);$sig=self::b64url_decode($parts[2]);
        if($header_raw===false||$payload_raw===false||$sig===false){return new WP_Error('mr_media_oidc_decode','JWT non decodificabile.',array('status'=>401));}
        $header=json_decode($header_raw,true);$claims=json_decode($payload_raw,true);
        if(!is_array($header)||!is_array($claims)||($header['alg']??'')!=='RS256'||empty($header['kid'])){return new WP_Error('mr_media_oidc_header','Header JWT non valido.',array('status'=>401));}
        $jwks=self::github_jwks();if(is_wp_error($jwks)){return $jwks;}
        $cert=null;foreach((array)$jwks['keys'] as $key){if(($key['kid']??'')===$header['kid']&&!empty($key['x5c'][0])){$cert="-----BEGIN CERTIFICATE-----\n".chunk_split($key['x5c'][0],64,"\n")."-----END CERTIFICATE-----\n";break;}}
        if(!$cert||!function_exists('openssl_verify')){delete_transient('mr_bridge_github_jwks');return new WP_Error('mr_media_oidc_key','Chiave OIDC GitHub non verificabile.',array('status'=>401));}
        if(openssl_verify($parts[0].'.'.$parts[1],$sig,$cert,OPENSSL_ALGO_SHA256)!==1){delete_transient('mr_bridge_github_jwks');return new WP_Error('mr_media_oidc_signature','Firma OIDC GitHub non valida.',array('status'=>401));}
        $now=time();$aud=$claims['aud']??'';$expected_aud=untrailingslashit(home_url('/')).'/mr-bridge-media';$aud_ok=is_array($aud)?in_array($expected_aud,$aud,true):hash_equals($expected_aud,(string)$aud);
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
        foreach($checks as $name=>$ok){if(!$ok){return new WP_Error('mr_media_oidc_claim_'.$name,'Claim OIDC rifiutato: '.$name,array('status'=>403));}}
        return $claims;
    }

    private static function data(WP_REST_Request $request){$d=$request->get_json_params();return is_array($d)?$d:array();}
    private static function request_id_valid($id){return is_string($id)&&preg_match('/^[A-Za-z0-9._:-]{16,96}$/',$id);}

    private static function store(){$x=get_option(self::REQUESTS_OPTION,array());return is_array($x)?$x:array();}
    private static function lookup($id){foreach(self::store() as $r){if(($r['request_id']??'')===$id){return $r;}}return null;}
    private static function save_request($r){$x=self::store();$x[]=$r;if(count($x)>self::MAX_REQUESTS){$x=array_slice($x,-self::MAX_REQUESTS);}update_option(self::REQUESTS_OPTION,$x,false);}

    private static function backup_store($row){
        $x=get_option(self::BACKUPS_OPTION,array());if(!is_array($x)){$x=array();}$x[]=$row;if(count($x)>self::MAX_BACKUPS){$x=array_slice($x,-self::MAX_BACKUPS);}update_option(self::BACKUPS_OPTION,$x,false);
    }
    private static function backup_find($id){foreach((array)get_option(self::BACKUPS_OPTION,array()) as $r){if(is_array($r)&&hash_equals((string)($r['backup_id']??''),(string)$id)){return $r;}}return null;}

    private static function attachment_payload($id) {
        $p=get_post($id);
        if(!$p||$p->post_type!=='attachment'){return null;}
        return array(
            'id'=>(int)$id,
            'title'=>(string)$p->post_title,
            'caption'=>(string)$p->post_excerpt,
            'description'=>(string)$p->post_content,
            'alt_text'=>(string)get_post_meta($id,'_wp_attachment_image_alt',true),
            'mime_type'=>(string)$p->post_mime_type,
            'url'=>(string)wp_get_attachment_url($id),
            'file'=>(string)get_attached_file($id),
            'parent'=>(int)$p->post_parent,
        );
    }

    public static function get_media(WP_REST_Request $request) {
        $claims=self::verify_oidc();if(is_wp_error($claims)){return $claims;}
        $d=self::data($request);$id=(int)($d['attachment_id']??0);$p=self::attachment_payload($id);
        if(!$p){return new WP_Error('mr_media_missing','Allegato non trovato.',array('status'=>404));}
        $safe=self::public_payload($p);
        $safe['metadata_sha256']=self::metadata_sha256($p);
        MR_Bridge::log('media_read',array('attachment_id'=>$id));
        return rest_ensure_response(array('ok'=>true,'media'=>$safe));
    }

    private static function metadata_sha256($payload) {
        if (!is_array($payload)) { return ''; }
        return hash('sha256', wp_json_encode(array(
            (string)($payload['title']??''),
            (string)($payload['caption']??''),
            (string)($payload['description']??''),
            (string)($payload['alt_text']??''),
            (int)($payload['parent']??0),
        ), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    }

    private static function public_payload($payload) {
        if (!is_array($payload)) { return null; }
        $safe=$payload;
        $safe['file_basename']=basename((string)($safe['file']??''));
        unset($safe['file']);
        return $safe;
    }

    private static function allowed_mime($mime) {
        return in_array($mime,array('image/jpeg','image/png','image/webp','image/gif','application/pdf'),true);
    }

    public static function import_media(WP_REST_Request $request) {
        $claims=self::verify_oidc();if(is_wp_error($claims)){return $claims;}
        $d=self::data($request);$rid=(string)($d['request_id']??'');
        if(!self::request_id_valid($rid)){return new WP_Error('mr_media_request_id','request_id non valido.',array('status'=>400));}
        $prev=self::lookup($rid);if($prev){return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'result'=>$prev));}
        $url=esc_url_raw((string)($d['source_url']??''),array('https'));
        if(!$url||strpos($url,'https://')!==0||!wp_http_validate_url($url)){return new WP_Error('mr_media_url','URL media HTTPS non valido.',array('status'=>400));}
        if(($d['confirm']??'')!=='IMPORT:'.hash('sha256',$url)){return new WP_Error('mr_media_confirm','Conferma import media non valida.',array('status'=>400));}
        if(!function_exists('download_url')){require_once ABSPATH.'wp-admin/includes/file.php';}
        if(!function_exists('media_handle_sideload')){require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';}
        $tmp=download_url($url,30,false);
        if(is_wp_error($tmp)){return $tmp;}
        try{
            $size=@filesize($tmp);if(!$size||$size>self::MAX_BYTES){return new WP_Error('mr_media_size','Media oltre il limite consentito.',array('status'=>413));}
            $type=wp_check_filetype_and_ext($tmp,basename((string)wp_parse_url($url,PHP_URL_PATH)));
            $mime=(string)($type['type']??'');$ext=(string)($type['ext']??'');
            if(!$mime||!$ext||!self::allowed_mime($mime)){return new WP_Error('mr_media_type','Tipo media non consentito.',array('status'=>415));}
            $name=sanitize_file_name((string)($d['filename']??basename((string)wp_parse_url($url,PHP_URL_PATH))));
            if($name===''||pathinfo($name,PATHINFO_EXTENSION)===''){$name='mr-media-'.gmdate('YmdHis').'.'.$ext;}
            $file=array('name'=>$name,'tmp_name'=>$tmp,'size'=>$size,'error'=>0,'type'=>$mime);
            $post=array(
                'post_title'=>sanitize_text_field((string)($d['title']??pathinfo($name,PATHINFO_FILENAME))),
                'post_excerpt'=>sanitize_textarea_field((string)($d['caption']??'')),
                'post_content'=>wp_kses_post((string)($d['description']??'')),
            );
            $parent=(int)($d['parent_post_id']??0);if($parent>0&&!get_post($parent)){return new WP_Error('mr_media_parent','Contenuto genitore non trovato.',array('status'=>404));}
            $id=media_handle_sideload($file,$parent,$post['post_title'],$post);
            if(is_wp_error($id)){return $id;}
            $tmp='';
            if(array_key_exists('alt_text',$d)){update_post_meta($id,'_wp_attachment_image_alt',sanitize_text_field((string)$d['alt_text']));}
            $backup_id='media-'.gmdate('YmdHis').'-'.substr(hash('sha256',$rid),0,12);
            self::backup_store(array('backup_id'=>$backup_id,'request_id'=>$rid,'created_new'=>true,'attachment_id'=>(int)$id,'created_at'=>current_time('mysql',true)));
            $payload=self::attachment_payload($id);$safe=self::public_payload($payload);
            $row=array('request_id'=>$rid,'action'=>'import','backup_id'=>$backup_id,'attachment_id'=>(int)$id,'mime_type'=>$mime,'url'=>$safe['url'],'completed_at'=>current_time('mysql',true));
            self::save_request($row);MR_Bridge::log('media_import_success',$row);
            return rest_ensure_response(array('ok'=>true,'idempotent'=>false,'result'=>$row,'media'=>$safe));
        }finally{if($tmp&&is_file($tmp)){@unlink($tmp);}}
    }

    public static function update_media(WP_REST_Request $request) {
        $claims=self::verify_oidc();if(is_wp_error($claims)){return $claims;}
        $d=self::data($request);$rid=(string)($d['request_id']??'');
        if(!self::request_id_valid($rid)){return new WP_Error('mr_media_request_id','request_id non valido.',array('status'=>400));}
        $prev=self::lookup($rid);if($prev){return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'result'=>$prev));}
        $id=(int)($d['attachment_id']??0);$before=self::attachment_payload($id);
        if(!$before){return new WP_Error('mr_media_missing','Allegato non trovato.',array('status'=>404));}
        $expected=self::metadata_sha256($before);
        if(($d['expected_metadata_sha256']??'')!==$expected){return new WP_Error('mr_media_conflict','Metadati media cambiati dopo il readback.',array('status'=>409,'current_metadata_sha256'=>$expected));}
        if(($d['confirm']??'')!=='UPDATE-MEDIA:'.$id){return new WP_Error('mr_media_confirm','Conferma aggiornamento media non valida.',array('status'=>400));}
        $backup_id='media-'.gmdate('YmdHis').'-'.substr(hash('sha256',$rid),0,12);
        self::backup_store(array('backup_id'=>$backup_id,'request_id'=>$rid,'created_new'=>false,'attachment_id'=>$id,'snapshot'=>$before,'created_at'=>current_time('mysql',true)));
        $post=array('ID'=>$id);
        if(array_key_exists('title',$d)){$post['post_title']=sanitize_text_field((string)$d['title']);}
        if(array_key_exists('caption',$d)){$post['post_excerpt']=sanitize_textarea_field((string)$d['caption']);}
        if(array_key_exists('description',$d)){$post['post_content']=wp_kses_post((string)$d['description']);}
        if(count($post)>1){$u=wp_update_post(wp_slash($post),true);if(is_wp_error($u)){return $u;}}
        if(array_key_exists('alt_text',$d)){update_post_meta($id,'_wp_attachment_image_alt',sanitize_text_field((string)$d['alt_text']));}
        if(array_key_exists('parent_post_id',$d)){
            $parent=(int)$d['parent_post_id'];if($parent>0&&!get_post($parent)){return new WP_Error('mr_media_parent','Contenuto genitore non trovato.',array('status'=>404));}
            wp_update_post(array('ID'=>$id,'post_parent'=>$parent));
        }
        $row=array('request_id'=>$rid,'action'=>'update','backup_id'=>$backup_id,'attachment_id'=>$id,'completed_at'=>current_time('mysql',true));
        self::save_request($row);MR_Bridge::log('media_update_success',$row);
        return rest_ensure_response(array('ok'=>true,'result'=>$row,'media'=>self::public_payload(self::attachment_payload($id))));
    }

    public static function rollback(WP_REST_Request $request) {
        $claims=self::verify_oidc();if(is_wp_error($claims)){return $claims;}
        $d=self::data($request);$backup_id=sanitize_text_field((string)($d['backup_id']??''));
        if(($d['confirm']??'')!=='ROLLBACK-MEDIA:'.$backup_id){return new WP_Error('mr_media_confirm','Conferma rollback media non valida.',array('status'=>400));}
        $b=self::backup_find($backup_id);if(!$b){return new WP_Error('mr_media_backup_missing','Backup media non trovato.',array('status'=>404));}
        $id=(int)($b['attachment_id']??0);
        if(!empty($b['created_new'])){
            $p=get_post($id);if($p&&$p->post_type==='attachment'){wp_delete_attachment($id,true);}
            MR_Bridge::log('media_rollback',array('backup_id'=>$backup_id,'attachment_id'=>$id,'action'=>'delete_created'));
            return rest_ensure_response(array('ok'=>true,'rolled_back'=>true,'action'=>'delete_created','attachment_id'=>$id));
        }
        $snap=(array)($b['snapshot']??array());$p=get_post($id);
        if(!$p||$p->post_type!=='attachment'){return new WP_Error('mr_media_missing','Allegato da ripristinare non trovato.',array('status'=>404));}
        $u=wp_update_post(wp_slash(array('ID'=>$id,'post_title'=>(string)$snap['title'],'post_excerpt'=>(string)$snap['caption'],'post_content'=>(string)$snap['description'],'post_parent'=>(int)$snap['parent'])),true);
        if(is_wp_error($u)){return $u;}
        update_post_meta($id,'_wp_attachment_image_alt',(string)$snap['alt_text']);
        MR_Bridge::log('media_rollback',array('backup_id'=>$backup_id,'attachment_id'=>$id,'action'=>'restore_metadata'));
        return rest_ensure_response(array('ok'=>true,'rolled_back'=>true,'action'=>'restore_metadata','attachment_id'=>$id));
    }
}

MR_Bridge_Media_V1::init();

