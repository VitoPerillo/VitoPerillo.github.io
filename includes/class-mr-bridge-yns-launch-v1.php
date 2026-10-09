<?php
if (!defined('ABSPATH')) { exit; }

final class MR_Bridge_YNS_Launch_V1 {
    const REST_NAMESPACE = 'mr-bridge/v1';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/yns-launch/status', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'status'),'permission_callback'=>'__return_true'
        ));
        register_rest_route(self::REST_NAMESPACE, '/yns-launch/bootstrap', array(
            'methods'=>'POST','callback'=>array(__CLASS__,'bootstrap'),'permission_callback'=>'__return_true'
        ));
    }

    private static function auth() {
        $claims = MR_Bridge_Deploy_V1::authorize_production_automation();
        if (is_wp_error($claims)) { return $claims; }
        if (!class_exists('YNS_DB') || !class_exists('YNS_Activity_Service')) {
            return new WP_Error('mr_yns_launch_missing','Gestionale Yoganostress non disponibile.',array('status'=>409));
        }
        if (!defined('YNS_VERSION') || YNS_VERSION !== '0.4.26-GO-LIVE-MINIMUM-RC4') {
            return new WP_Error('mr_yns_launch_version','Versione gestionale non autorizzata al lancio.',array(
                'status'=>409,'actual'=>defined('YNS_VERSION')?YNS_VERSION:''
            ));
        }
        return $claims;
    }

    private static function body(WP_REST_Request $request) {
        $body=$request->get_json_params();
        return is_array($body)?$body:array();
    }

    private static function counts() {
        global $wpdb;
        $rules=YNS_DB::table('recurrence_rules');
        $sessions=YNS_DB::table('sessions');
        $activities=YNS_DB::table('activities');
        return array(
            'active_rules'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$rules} WHERE active=1"),
            'future_group_sessions'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$sessions} s JOIN {$activities} a ON a.id=s.activity_id WHERE s.status='OPEN' AND s.starts_at>=NOW() AND s.session_kind<>'appointment' AND a.active=1"),
        );
    }

    public static function status(WP_REST_Request $request) {
        $auth=self::auth(); if(is_wp_error($auth))return $auth;
        return rest_ensure_response(array('ok'=>true,'version'=>YNS_VERSION,'counts'=>self::counts()));
    }

    private static function ensure_teacher() {
        global $wpdb; $t=YNS_DB::table('teachers'); $now=YNS_DB::now_mysql();
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE first_name=%s AND last_name=%s AND active=1 ORDER BY id ASC LIMIT 1",'Vito','Perillo'));
        if($id)return $id;
        $ok=$wpdb->insert($t,array('first_name'=>'Vito','last_name'=>'Perillo','email'=>null,'phone'=>null,'photo_url'=>null,'wp_user_id'=>null,'active'=>1,'created_at'=>$now,'updated_at'=>$now));
        if($ok===false||!$wpdb->insert_id)throw new RuntimeException('Creazione insegnante fallita.');
        return (int)$wpdb->insert_id;
    }

    private static function ensure_location($name,$address='') {
        global $wpdb; $t=YNS_DB::table('locations'); $now=YNS_DB::now_mysql();
        $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE name=%s AND active=1 ORDER BY id ASC LIMIT 1",$name));
        if($id)return $id;
        $ok=$wpdb->insert($t,array('parent_id'=>null,'name'=>$name,'address'=>$address,'latitude'=>null,'longitude'=>null,'active'=>1,'created_at'=>$now,'updated_at'=>$now));
        if($ok===false||!$wpdb->insert_id)throw new RuntimeException('Creazione sede fallita: '.$name);
        return (int)$wpdb->insert_id;
    }

    public static function bootstrap(WP_REST_Request $request) {
        $auth=self::auth(); if(is_wp_error($auth))return $auth;
        $body=self::body($request);
        if(($body['confirm']??'')!=='BOOTSTRAP:YNS-MINIMUM-V1') {
            return new WP_Error('mr_yns_launch_confirm','Conferma bootstrap non valida.',array('status'=>400));
        }
        $before=self::counts();
        if($before['active_rules']>0 || $before['future_group_sessions']>0) {
            return rest_ensure_response(array('ok'=>true,'idempotent'=>true,'bootstrapped'=>false,'reason'=>'calendar_already_present','counts'=>$before));
        }
        global $wpdb;
        $type=YNS_DB::table('activity_types');
        $course=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$type} WHERE slug=%s AND active=1 LIMIT 1",'course'));
        if(!$course)return new WP_Error('mr_yns_launch_course_type','Tipo corso non disponibile.',array('status'=>409));

        try {
            $teacher=self::ensure_teacher();
            $indoor=self::ensure_location('Sede Yoganostress','Roma – Monteverde/Gianicolense');
            $outdoor=self::ensure_location('Villa Pamphili – Vivi Bistrot','Villa Doria Pamphilj, Roma');
            $today=current_time('Y-m-d');
            $slots=array(
                array('Hatha Yoga',1,'10:00',$indoor), array('Hatha Yoga',1,'13:15',$indoor),
                array('Hatha Yoga',2,'17:30',$indoor), array('Hatha Yoga',2,'19:00',$indoor),
                array('Hatha Yoga',3,'10:00',$outdoor), array('Hatha Yoga',3,'13:30',$indoor),
                array('Hatha Yoga',4,'17:30',$indoor), array('Yoga Nidra',4,'19:00',$indoor),
                array('Yoga Nidra',5,'11:15',$indoor), array('Hatha Yoga',5,'13:30',$indoor),
                array('Hatha Yoga',6,'10:00',$outdoor)
            );
            $created=array();
            foreach($slots as $slot){
                $created[]=YNS_Activity_Service::create_recurring(array(
                    'activity_type_id'=>$course,'name'=>$slot[0],'short_description'=>'','description'=>'',
                    'mode'=>'presence','capacity'=>20,'unlimited'=>0,'teacher_id'=>$teacher,'location_id'=>$slot[3],
                    'weekday'=>$slot[1],'local_time'=>$slot[2],'duration_minutes'=>60,'start_date'=>$today,'end_date'=>null
                ));
            }
            $after=self::counts();
            if($after['active_rules']!==11 || $after['future_group_sessions']<11) {
                return new WP_Error('mr_yns_launch_verify','Bootstrap calendario incompleto.',array('status'=>500,'counts'=>$after));
            }
            MR_Bridge::log('yns_minimum_calendar_bootstrapped',array('rules'=>11,'future_sessions'=>$after['future_group_sessions']));
            return rest_ensure_response(array('ok'=>true,'bootstrapped'=>true,'created_activities'=>$created,'counts'=>$after));
        } catch(Throwable $e) {
            return new WP_Error('mr_yns_launch_failed',$e->getMessage(),array('status'=>500));
        }
    }
}
