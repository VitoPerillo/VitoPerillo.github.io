<?php
if (!defined('ABSPATH')) { exit; }

final class YNS_WhatsApp_Source_Export {
    const NS='yns-whatsapp/v1';
    const STAGING_HOME='https://www.yoganostress.it/staging-gestionale';
    const AUD='https://www.yoganostress.it/staging-gestionale/yns-wa-source-export';
    const REPOSITORY='VitoPerillo/yoganostress-wordpress-bridge';
    const REPOSITORY_ID='1390938875';
    const OWNER_ID='317205417';
    const REF='refs/heads/yns-whatsapp-api';
    const WORKFLOW_REF='VitoPerillo/yoganostress-wordpress-bridge/.github/workflows/yns-whatsapp-recover-gestionale.yml@refs/heads/yns-whatsapp-api';
    const TARGET_SLUG='yoganostress-prenotazioni';

    public static function init() {
        add_action('rest_api_init',[__CLASS__,'routes']);
    }

    public static function routes() {
        register_rest_route(self::NS,'/staging/source-export',[
            'methods'=>'POST',
            'callback'=>[__CLASS__,'export'],
            'permission_callback'=>'__return_true',
        ]);
    }

    private static function staging_only() {
        return untrailingslashit(home_url('/'))===self::STAGING_HOME;
    }

    private static function b64url_decode($v) {
        $v=strtr((string)$v,'-_','+/');
        $pad=strlen($v)%4;
        if ($pad) $v.=str_repeat('=',4-$pad);
        return base64_decode($v,true);
    }

    private static function auth_header() {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return trim((string)$_SERVER['HTTP_AUTHORIZATION']);
        if (function_exists('apache_request_headers')) {
            foreach ((array)apache_request_headers() as $k=>$v) {
                if (strtolower((string)$k)==='authorization') return trim((string)$v);
            }
        }
        return '';
    }

    private static function jwks() {
        $cached=get_transient('yns_wa_source_jwks');
        if (is_array($cached)&&!empty($cached['keys'])) return $cached;
        $res=wp_remote_get('https://token.actions.githubusercontent.com/.well-known/jwks',[
            'timeout'=>10,'redirection'=>2,'headers'=>['Accept'=>'application/json']
        ]);
        if (is_wp_error($res)) return $res;
        if ((int)wp_remote_retrieve_response_code($res)!==200) {
            return new WP_Error('yns_source_jwks_http','JWKS GitHub non disponibile.',['status'=>503]);
        }
        $data=json_decode(wp_remote_retrieve_body($res),true);
        if (!is_array($data)||empty($data['keys'])) {
            return new WP_Error('yns_source_jwks_invalid','JWKS GitHub non valido.',['status'=>503]);
        }
        set_transient('yns_wa_source_jwks',$data,HOUR_IN_SECONDS);
        return $data;
    }

    private static function verify_oidc() {
        if (!self::staging_only()) return new WP_Error('yns_source_staging_only','Export disponibile solo staging.',['status'=>403]);
        $auth=self::auth_header();
        if (!preg_match('/^Bearer\s+(.+)$/i',$auth,$m)) {
            return new WP_Error('yns_source_auth_missing','OIDC mancante.',['status'=>401]);
        }
        $parts=explode('.',trim($m[1]));
        if (count($parts)!==3) return new WP_Error('yns_source_jwt_invalid','JWT non valido.',['status'=>401]);
        $head=json_decode(self::b64url_decode($parts[0]),true);
        $claims=json_decode(self::b64url_decode($parts[1]),true);
        $sig=self::b64url_decode($parts[2]);
        if (!is_array($head)||!is_array($claims)||($head['alg']??'')!=='RS256'||empty($head['kid'])||$sig===false) {
            return new WP_Error('yns_source_jwt_header','JWT non valido.',['status'=>401]);
        }
        $jwks=self::jwks();
        if (is_wp_error($jwks)) return $jwks;
        $cert=null;
        foreach ((array)$jwks['keys'] as $key) {
            if (($key['kid']??'')===$head['kid']&&!empty($key['x5c'][0])) {
                $cert="-----BEGIN CERTIFICATE-----\n".chunk_split($key['x5c'][0],64,"\n")."-----END CERTIFICATE-----\n";
                break;
            }
        }
        if (!$cert||!function_exists('openssl_verify')||openssl_verify($parts[0].'.'.$parts[1],$sig,$cert,OPENSSL_ALGO_SHA256)!==1) {
            return new WP_Error('yns_source_signature','Firma OIDC non valida.',['status'=>401]);
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
        foreach($checks as $name=>$ok) if(!$ok) return new WP_Error('yns_source_claim_'.$name,'Claim OIDC rifiutato: '.$name,['status'=>403]);
        return $claims;
    }

    private static function add_dir(ZipArchive $zip,$root,$base,&$count) {
        $items=scandir($root);
        if ($items===false) return false;
        foreach($items as $item) {
            if ($item==='.'||$item==='..') continue;
            $path=$root.DIRECTORY_SEPARATOR.$item;
            $rel=ltrim(str_replace('\\','/',substr($path,strlen($base))),'/');
            if (is_link($path)) continue;
            if (is_dir($path)) {
                $zip->addEmptyDir($rel);
                if (!self::add_dir($zip,$path,$base,$count)) return false;
            } elseif (is_file($path)) {
                if (!$zip->addFile($path,$rel)) return false;
                $count++;
            }
        }
        return true;
    }

    public static function export(WP_REST_Request $request) {
        $claims=self::verify_oidc();
        if (is_wp_error($claims)) return $claims;
        $body=$request->get_json_params();
        $slug=sanitize_key($body['slug']??'');
        if ($slug!==self::TARGET_SLUG) return new WP_Error('yns_source_slug_denied','Slug non autorizzato.',['status'=>403]);
        $root=WP_PLUGIN_DIR.'/'.self::TARGET_SLUG;
        if (!is_dir($root)) return new WP_Error('yns_source_missing','Plugin staging non trovato.',['status'=>404]);
        if (!class_exists('ZipArchive')) return new WP_Error('yns_source_zip_missing','ZipArchive non disponibile.',['status'=>500]);

        $tmp=wp_tempnam('yns-source-export.zip');
        if (!$tmp) return new WP_Error('yns_source_tmp','File temporaneo non disponibile.',['status'=>500]);
        $zip=new ZipArchive();
        if ($zip->open($tmp,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) {
            @unlink($tmp); return new WP_Error('yns_source_zip_open','Creazione ZIP fallita.',['status'=>500]);
        }
        $count=0;
        $ok=self::add_dir($zip,$root,dirname($root),$count);
        $zip->close();
        if (!$ok||!is_file($tmp)) {
            @unlink($tmp); return new WP_Error('yns_source_zip_build','Creazione ZIP fallita.',['status'=>500]);
        }
        $raw=(string)file_get_contents($tmp);
        @unlink($tmp);
        if ($raw===''||strlen($raw)>5*1024*1024) return new WP_Error('yns_source_size','ZIP sorgente non valido.',['status'=>409]);

        return new WP_REST_Response([
            'ok'=>true,
            'environment'=>'staging',
            'slug'=>self::TARGET_SLUG,
            'file_count'=>$count,
            'size'=>strlen($raw),
            'sha256'=>hash('sha256',$raw),
            'zip_base64'=>base64_encode($raw),
            'run_id'=>$claims['run_id']??null,
        ],200);
    }
}
YNS_WhatsApp_Source_Export::init();
