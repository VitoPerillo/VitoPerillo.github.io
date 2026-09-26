<?php
/**
 * Plugin Name: Yoganostress CRM - WhatsApp Bridge
 * Description: Ponte minimo CRM Yoganostress -> Kapso per lista WA-01, gate WA-OK e approvazione unica.
 * Version: 1.0.0
 * Author: Yoganostress
 */
if (!defined('ABSPATH')) exit;

final class YNS_WA_Bridge {
    const OPT='yns_wa_bridge_settings';
    const TEST='yns_wa_bridge_last_test';
    const LAST='yns_wa_bridge_last_broadcast';
    const API='https://api.kapso.ai/platform/v1';

    static function init(){
        add_action('admin_menu',[__CLASS__,'menu'],99);
        add_action('admin_post_yns_wa_save',[__CLASS__,'save']);
        add_action('admin_post_yns_wa_test',[__CLASS__,'test_admin']);
        add_action('admin_post_yns_wa_approve',[__CLASS__,'approve_admin']);
        add_action('rest_api_init',[__CLASS__,'routes']);
    }
    static function menu(){ add_submenu_page('ysu-crm','Broadcast WhatsApp','Broadcast WhatsApp','manage_options','yns-wa-broadcast',[__CLASS__,'page']); }
    static function can(){ return current_user_can('manage_options'); }
    static function routes(){
        register_rest_route('yns-wa/v1','/status',['methods'=>'GET','callback'=>[__CLASS__,'rest_status'],'permission_callback'=>[__CLASS__,'can']]);
        register_rest_route('yns-wa/v1','/approve',['methods'=>'POST','callback'=>[__CLASS__,'rest_approve'],'permission_callback'=>[__CLASS__,'can']]);
        register_rest_route('yns-wa/v1','/test',['methods'=>'POST','callback'=>[__CLASS__,'rest_test'],'permission_callback'=>[__CLASS__,'can']]);
    }
    static function settings(){ return wp_parse_args((array)get_option(self::OPT,[]),['enabled'=>0,'key'=>'','phone_id'=>'','template_id'=>'']); }
    static function crypt($plain,$decrypt=false){
        if(!function_exists('openssl_encrypt')) return '';
        $key=hash('sha256',wp_salt('auth'),true);
        if(!$decrypt){ $iv=random_bytes(12);$tag='';$c=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag); return $c===false?'':base64_encode($iv.$tag.$c); }
        $raw=base64_decode($plain,true); if($raw===false||strlen($raw)<29)return ''; $iv=substr($raw,0,12);$tag=substr($raw,12,16);$c=substr($raw,28);$p=openssl_decrypt($c,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag); return is_string($p)?$p:'';
    }
    static function api_key(){ $s=self::settings(); return empty($s['key'])?'':self::crypt($s['key'],true); }
    static function rows(){
        global $wpdb; $c=$wpdb->prefix.'ysu_contacts';$m=$wpdb->prefix.'ysu_master_contacts';
        return $wpdb->get_results("SELECT c.phone_norm,COALESCE(m.display_name,'') display_name FROM $c c LEFT JOIN $m m ON m.phone_norm=c.phone_norm WHERE c.consent_status='granted' AND COALESCE(m.exclude_flag,0)=0 AND COALESCE(m.future_block,0)=0 AND c.phone_norm<>'+390000000000' AND LOWER(TRIM(COALESCE(m.display_name,''))) NOT IN ('vito','perillo vito') ORDER BY c.updated_at DESC");
    }
    static function api($method,$path,$body=null){
        $k=self::api_key(); if($k==='') return new WP_Error('kapso_key','API key Kapso mancante');
        $a=['method'=>$method,'timeout'=>30,'headers'=>['X-API-Key'=>$k,'Content-Type'=>'application/json']]; if($body!==null)$a['body']=wp_json_encode($body);
        $r=wp_remote_request(self::API.$path,$a); if(is_wp_error($r))return $r; $code=(int)wp_remote_retrieve_response_code($r);$j=json_decode((string)wp_remote_retrieve_body($r),true);
        if($code<200||$code>=300)return new WP_Error('kapso_http_'.$code,'Kapso HTTP '.$code,['status'=>502]); return is_array($j)?$j:[];
    }
    static function test(){
        $s=self::settings(); if(empty($s['phone_id']))return new WP_Error('phone','Phone Number ID mancante');
        $r=self::api('GET','/whatsapp/phone_numbers'); if(is_wp_error($r))return $r; $match=null;
        foreach(($r['data']??[]) as $i){$id=(string)($i['phone_number_id']??($i['id']??''));if($id===(string)$s['phone_id']){$match=$i;break;}}
        if(!$match)return new WP_Error('not_found','Phone Number ID non trovato in Kapso');
        $co=!empty($match['is_coexistence']);$st=strtoupper((string)($match['status']??''));$ok=$co&&$st==='CONNECTED';
        update_option(self::TEST,['ok'=>$ok,'coexistence'=>$co,'status'=>$st,'phone_id'=>$s['phone_id'],'at'=>time()],false);
        return $ok?['ok'=>true,'status'=>$st,'coexistence'=>true]:new WP_Error('gate','Serve CONNECTED + Coexistence');
    }
    static function ready(){
        $s=self::settings();$t=(array)get_option(self::TEST,[]);
        return !empty($s['enabled'])&&self::api_key()!==''&&!empty($s['phone_id'])&&!empty($s['template_id'])&&!empty($t['ok'])&&!empty($t['coexistence'])&&($t['status']??'')==='CONNECTED'&&($t['phone_id']??'')===$s['phone_id']&&((int)($t['at']??0)>time()-DAY_IN_SECONDS);
    }
    static function schedule($when=''){
        if(!self::ready())return new WP_Error('not_ready','Kapso non è ancora pronto: configurazione/test CONNECTED + Coexistence mancanti',['status'=>409]);
        $rows=self::rows(); if(!$rows)return new WP_Error('empty','WA-01 non ha destinatari WA-OK',['status'=>409]);
        if($when==='')$when=gmdate('c',time()+600); try{$d=new DateTimeImmutable($when);}catch(Exception $e){return new WP_Error('time','Orario non valido');}
        if($d->getTimestamp()<=time()+60)return new WP_Error('time','Scegli un orario almeno 60 secondi nel futuro');$when=$d->format(DateTimeInterface::ATOM);
        $s=self::settings();$cr=self::api('POST','/whatsapp/broadcasts',['whatsapp_broadcast'=>['name'=>'Yoganostress WA-01 '.gmdate('Y-m-d H:i'),'phone_number_id'=>$s['phone_id'],'whatsapp_template_id'=>$s['template_id']]]); if(is_wp_error($cr))return $cr;
        $id=(string)($cr['data']['id']??''); if($id==='')return new WP_Error('no_id','Kapso non ha restituito broadcast_id');
        $rec=[];foreach($rows as $r)$rec[]=['phone_number'=>$r->phone_norm,'components'=>[]];
        $ad=self::api('POST','/whatsapp/broadcasts/'.rawurlencode($id).'/recipients',['whatsapp_broadcast'=>['recipients'=>$rec]]);if(is_wp_error($ad))return $ad;
        $sc=self::api('POST','/whatsapp/broadcasts/'.rawurlencode($id).'/schedule',['scheduled_at'=>$when]);if(is_wp_error($sc))return $sc;
        $out=['ok'=>true,'code'=>'WA-01','broadcast_id'=>$id,'scheduled_at'=>$when,'recipients'=>count($rows)];update_option(self::LAST,$out,false);return $out;
    }
    static function save(){if(!self::can())wp_die('Non autorizzato');check_admin_referer('yns_wa_save');$o=self::settings();$k=trim((string)wp_unslash($_POST['api_key']??''));if($k!=='')$o['key']=self::crypt($k);$o['phone_id']=sanitize_text_field(wp_unslash($_POST['phone_id']??''));$o['template_id']=sanitize_text_field(wp_unslash($_POST['template_id']??''));$o['enabled']=!empty($_POST['enabled'])?1:0;update_option(self::OPT,$o,false);wp_safe_redirect(admin_url('admin.php?page=yns-wa-broadcast&saved=1'));exit;}
    static function test_admin(){if(!self::can())wp_die('Non autorizzato');check_admin_referer('yns_wa_test');$r=self::test();set_transient('yns_wa_notice',is_wp_error($r)?$r->get_error_message():'Kapso CONNECTED + Coexistence: OK',60);wp_safe_redirect(admin_url('admin.php?page=yns-wa-broadcast'));exit;}
    static function approve_admin(){if(!self::can())wp_die('Non autorizzato');check_admin_referer('yns_wa_approve');$r=self::schedule(sanitize_text_field(wp_unslash($_POST['scheduled_at']??'')));set_transient('yns_wa_notice',is_wp_error($r)?$r->get_error_message():'WA-01 programmato: '.$r['recipients'].' destinatari',60);wp_safe_redirect(admin_url('admin.php?page=yns-wa-broadcast'));exit;}
    static function rest_status(){return rest_ensure_response(['code'=>'WA-01','eligible'=>count(self::rows()),'kapso_ready'=>self::ready(),'last'=>get_option(self::LAST,[])]);}
    static function rest_test(){ $r=self::test();return is_wp_error($r)?$r:rest_ensure_response($r); }
    static function rest_approve(WP_REST_Request $q){$r=self::schedule(sanitize_text_field((string)$q->get_param('scheduled_at')));return is_wp_error($r)?$r:rest_ensure_response($r);}
    static function page(){
        if(!self::can())return;$s=self::settings();$rows=self::rows();$n=get_transient('yns_wa_notice');delete_transient('yns_wa_notice');$def=wp_date('Y-m-d\\TH:i',time()+600);
        echo '<div class="wrap"><h1>Yoganostress CRM — Broadcast WhatsApp</h1>';if($n)echo '<div class="notice notice-info"><p>'.esc_html($n).'</p></div>';
        echo '<div style="background:#fff;border:1px solid #dcdcde;padding:18px;max-width:900px"><h2>WA-01 — '.(self::ready()?'PRONTO ALLA PROGRAMMAZIONE':'LISTA PRONTA / KAPSO DA COLLEGARE').'</h2><p><strong>Destinatari WA-OK reali:</strong> '.count($rows).'</p><table class="widefat striped"><thead><tr><th>Nome</th><th>Numero</th><th>Stato</th></tr></thead><tbody>';
        foreach($rows as $r)echo '<tr><td>'.esc_html($r->display_name?:'—').'</td><td>'.esc_html($r->phone_norm).'</td><td><strong style="color:#18794e">WA-OK</strong></td></tr>';if(!$rows)echo '<tr><td colspan="3">Nessun destinatario idoneo</td></tr>';echo '</tbody></table>';
        echo '<h3>Collegamento Kapso</h3><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="yns_wa_save">';wp_nonce_field('yns_wa_save');
        echo '<p>API key <input type="password" name="api_key" value="" placeholder="lascia vuoto per non cambiarla" class="regular-text"></p><p>Phone Number ID <input name="phone_id" value="'.esc_attr($s['phone_id']).'" class="regular-text"></p><p>Template ID <input name="template_id" value="'.esc_attr($s['template_id']).'" class="regular-text"></p><p><label><input type="checkbox" name="enabled" value="1" '.checked(1,(int)$s['enabled'],false).'> Abilita invii</label></p>';submit_button('Salva collegamento');echo '</form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:inline-block;margin-right:12px"><input type="hidden" name="action" value="yns_wa_test">';wp_nonce_field('yns_wa_test');submit_button('TEST CONNECTED + COEXISTENCE','secondary','submit',false);echo '</form>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="margin-top:18px"><input type="hidden" name="action" value="yns_wa_approve">';wp_nonce_field('yns_wa_approve');echo '<label><strong>Programma:</strong> <input type="datetime-local" name="scheduled_at" value="'.esc_attr($def).'"></label> ';submit_button('APPROVA E PROGRAMMA WA-01','primary','submit',false,['disabled'=>!self::ready()||!$rows]);echo '</form><p><small>Il pulsante ricontrolla WA-OK, esclusioni e blocchi nel momento dell’approvazione.</small></p></div></div>';
    }
}
YNS_WA_Bridge::init();
