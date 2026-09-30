<?php
if (!defined('ABSPATH')) { exit; }

final class YNS_WhatsApp_Datasource {
    const NS = 'yns-whatsapp/v1';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_filter('yns_wa_list_recipients', [__CLASS__, 'resolve_recipients'], 10, 2);
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/data-model', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'data_model'],
            'permission_callback' => [__CLASS__, 'admin_only'],
        ]);
        register_rest_route(self::NS, '/join-summary', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'join_summary'],
            'permission_callback' => [__CLASS__, 'admin_only'],
        ]);
        register_rest_route(self::NS, '/admin/lists/preview', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'admin_preview'],
            'permission_callback' => [__CLASS__, 'admin_only'],
        ]);
    }

    public static function admin_only() {
        return current_user_can('manage_options');
    }

    public static function admin_preview(WP_REST_Request $request) {
        if (!class_exists('YNS_WhatsApp_Lists')) {
            return new WP_Error('yns_wa_lists_unavailable', 'Modulo liste non disponibile', ['status'=>503]);
        }
        return YNS_WhatsApp_Lists::preview($request);
    }

    private static function safe_identifier($name) {
        return preg_match('/^[A-Za-z0-9_]+$/', (string)$name) ? (string)$name : '';
    }

    private static function table_exists($table) {
        global $wpdb;
        $table = self::safe_identifier($table);
        if ($table === '') return false;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function candidate_table($table) {
        $t = strtolower((string)$table);
        foreach (['yns','ysu','yoganostress','booking','prenot','session','waitlist','carnet','subscription','membership','consent','whatsapp'] as $needle) {
            if (strpos($t, $needle) !== false) return true;
        }
        return false;
    }

    private static function columns($table) {
        global $wpdb;
        $table = self::safe_identifier($table);
        if ($table === '') return [];
        $rows = $wpdb->get_results("SHOW COLUMNS FROM $table", ARRAY_A);
        $out = [];
        foreach ((array)$rows as $r) {
            $out[] = [
                'field' => $r['Field'] ?? '',
                'type' => $r['Type'] ?? '',
                'null' => $r['Null'] ?? '',
                'key' => $r['Key'] ?? '',
                'default' => $r['Default'] ?? null,
                'extra' => $r['Extra'] ?? '',
            ];
        }
        return $out;
    }

    private static function table_count($table) {
        global $wpdb;
        $table = self::safe_identifier($table);
        if ($table === '') return null;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
    }

    private static function meta_keys($table, $key_col) {
        global $wpdb;
        $table = self::safe_identifier($table);
        $key_col = self::safe_identifier($key_col);
        if ($table === '' || $key_col === '') return [];
        $like = [
            '%phone%','%tel%','%whatsapp%','%consent%','%privacy%',
            '%plan%','%package%','%carnet%','%subscription%','%membership%',
            '%expiry%','%expire%','%scaden%','%certificate%','%medical%',
            '%first_name%','%last_name%'
        ];
        $clauses = array_fill(0, count($like), "$key_col LIKE %s");
        $sql = "SELECT $key_col AS meta_key, COUNT(*) AS n FROM $table WHERE (" . implode(' OR ', $clauses) . ") GROUP BY $key_col ORDER BY n DESC LIMIT 200";
        $rows = $wpdb->get_results($wpdb->prepare($sql, $like), ARRAY_A);
        return array_map(function($r){
            return ['key'=>(string)$r['meta_key'], 'count'=>(int)$r['n']];
        }, (array)$rows);
    }

    private static function digits($phone) {
        $d = preg_replace('/\D+/', '', (string)$phone);
        if (strpos($d, '0039') === 0) $d = substr($d, 2);
        return $d;
    }

    private static function phone_keys($phone) {
        $d = self::digits($phone);
        if ($d === '') return [];
        $keys = [$d];
        if (strpos($d, '39') === 0 && strlen($d) >= 11) {
            $keys[] = substr($d, 2);
        } elseif (strlen($d) >= 9 && strlen($d) <= 11) {
            $keys[] = '39' . $d;
        }
        return array_values(array_unique(array_filter($keys)));
    }

    private static function text_norm($value) {
        $value = remove_accents((string)$value);
        $value = strtolower(trim($value));
        return preg_replace('/\s+/', ' ', $value);
    }

    private static function consent_map() {
        global $wpdb;
        $table = $wpdb->prefix . 'ysu_contacts';
        if (!self::table_exists($table)) return [];
        $rows = $wpdb->get_results("SELECT id, phone_norm, consent_status FROM $table WHERE consent_status='granted'", ARRAY_A);
        $map = [];
        foreach ((array)$rows as $row) {
            $canonical = self::digits($row['phone_norm'] ?? '');
            if ($canonical === '') continue;
            $entry = ['phone'=>$canonical, 'contact_id'=>(string)($row['id'] ?? '')];
            foreach (self::phone_keys($row['phone_norm'] ?? '') as $key) $map[$key] = $entry;
        }
        return $map;
    }

    private static function customers() {
        global $wpdb;
        $table = $wpdb->prefix . 'yns_customers';
        if (!self::table_exists($table)) return [];
        $consents = self::consent_map();
        $rows = $wpdb->get_results(
            "SELECT id, first_name, last_name, phone, active FROM $table WHERE active=1 AND phone IS NOT NULL AND phone<>''",
            ARRAY_A
        );
        $out = [];
        foreach ((array)$rows as $row) {
            $match = null;
            foreach (self::phone_keys($row['phone'] ?? '') as $key) {
                if (isset($consents[$key])) { $match = $consents[$key]; break; }
            }
            $phone = $match ? $match['phone'] : self::digits($row['phone'] ?? '');
            $out[(int)$row['id']] = [
                'contact_id' => 'customer:' . (int)$row['id'],
                'name' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
                'phone' => $phone,
                'consent_whatsapp' => (bool)$match,
                'crm_contact_id' => $match ? $match['contact_id'] : null,
            ];
        }
        return $out;
    }

    private static function schedule_rows() {
        global $wpdb;
        $r = $wpdb->prefix . 'yns_reservations';
        $s = $wpdb->prefix . 'yns_sessions';
        $a = $wpdb->prefix . 'yns_activities';
        $t = $wpdb->prefix . 'yns_activity_types';
        foreach ([$r,$s,$a,$t] as $table) if (!self::table_exists($table)) return [];
        return (array)$wpdb->get_results(
            "SELECT r.customer_id,r.status AS reservation_status,r.session_id,
                    s.activity_id,s.starts_at,s.session_kind,s.status AS session_status,
                    a.name AS activity_name,at.slug AS activity_type
             FROM $r r
             INNER JOIN $s s ON s.id=r.session_id
             INNER JOIN $a a ON a.id=s.activity_id
             LEFT JOIN $t at ON at.id=a.activity_type_id
             WHERE r.status IN ('BOOKED','WAITLIST')
               AND s.starts_at>=NOW()
               AND s.status<>'CANCELLED'
             ORDER BY s.starts_at ASC",
            ARRAY_A
        );
    }

    private static function package_rows() {
        global $wpdb;
        $cp = $wpdb->prefix . 'yns_customer_packages';
        $p = $wpdb->prefix . 'yns_package_plans';
        foreach ([$cp,$p] as $table) if (!self::table_exists($table)) return [];
        return (array)$wpdb->get_results(
            "SELECT cp.customer_id,cp.package_plan_id,cp.status,cp.expiry_date,p.name AS plan_name
             FROM $cp cp
             INNER JOIN $p p ON p.id=cp.package_plan_id
             WHERE cp.status='ACTIVE'
             ORDER BY cp.customer_id,cp.expiry_date ASC",
            ARRAY_A
        );
    }

    private static function segment_bool($value) {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'yes';
    }

    private static function weekday_number($value) {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) {
            $n = (int)$value;
            return ($n >= 1 && $n <= 7) ? $n : null;
        }
        $map = [
            'lunedi'=>1,'lunedi\''=>1,'monday'=>1,
            'martedi'=>2,'martedi\''=>2,'tuesday'=>2,
            'mercoledi'=>3,'mercoledi\''=>3,'wednesday'=>3,
            'giovedi'=>4,'giovedi\''=>4,'thursday'=>4,
            'venerdi'=>5,'venerdi\''=>5,'friday'=>5,
            'sabato'=>6,'saturday'=>6,
            'domenica'=>7,'sunday'=>7,
        ];
        $key = self::text_norm($value);
        return $map[$key] ?? null;
    }

    private static function schedule_match($row, $segment) {
        if (array_key_exists('course', $segment) && $segment['course'] !== '') {
            $expected = (string)$segment['course'];
            if (ctype_digit($expected)) {
                if ((string)($row['activity_id'] ?? '') !== $expected) return false;
            } else {
                $name = self::text_norm($row['activity_name'] ?? '');
                $needle = self::text_norm($expected);
                if ($needle === '' || strpos($name, $needle) === false) return false;
            }
        }
        if (array_key_exists('weekday', $segment) && $segment['weekday'] !== '') {
            $wanted = self::weekday_number($segment['weekday']);
            $actual = (int)date('N', strtotime((string)($row['starts_at'] ?? '')));
            if (!$wanted || $actual !== $wanted) return false;
        }
        if (array_key_exists('time', $segment) && $segment['time'] !== '') {
            $wanted = substr((string)$segment['time'], 0, 5);
            $actual = date('H:i', strtotime((string)($row['starts_at'] ?? '')));
            if ($wanted !== $actual) return false;
        }
        if (array_key_exists('event_id', $segment) && $segment['event_id'] !== '') {
            $wanted = (string)$segment['event_id'];
            if ((string)($row['session_id'] ?? '') !== $wanted && (string)($row['activity_id'] ?? '') !== $wanted) return false;
        }
        if (array_key_exists('waitlist', $segment)) {
            $want_waitlist = self::segment_bool($segment['waitlist']);
            $is_waitlist = (($row['reservation_status'] ?? '') === 'WAITLIST');
            if ($want_waitlist !== $is_waitlist) return false;
        }
        return true;
    }

    private static function package_match($row, $segment) {
        if (array_key_exists('plan', $segment) && $segment['plan'] !== '') {
            $expected = (string)$segment['plan'];
            if (ctype_digit($expected)) {
                if ((string)($row['package_plan_id'] ?? '') !== $expected) return false;
            } else {
                $name = self::text_norm($row['plan_name'] ?? '');
                $needle = self::text_norm($expected);
                if ($needle === '' || strpos($name, $needle) === false) return false;
            }
        }
        if (array_key_exists('expires_within_days', $segment)) {
            $days = max(0, (int)$segment['expires_within_days']);
            $t = !empty($row['expiry_date']) ? strtotime((string)$row['expiry_date']) : false;
            $now = current_time('timestamp', true);
            if (!$t || $t < $now || $t > ($now + $days * DAY_IN_SECONDS)) return false;
        }
        return true;
    }

    private static function expiry_type_blocked($segment) {
        $type = self::text_norm($segment['expiry_type'] ?? '');
        if ($type === '') return false;
        return in_array($type, [
            'asc','asc insurance','asc_insurance','assicurazione','insurance',
            'tessera','tessera asc','tessera_asc','asc assicurazione'
        ], true);
    }

    public static function data_model() {
        global $wpdb;
        $all = $wpdb->get_col('SHOW TABLES');
        $tables = [];
        foreach ((array)$all as $table) {
            if (!self::candidate_table($table)) continue;
            $tables[] = [
                'table' => $table,
                'rows' => self::table_count($table),
                'columns' => self::columns($table),
            ];
        }

        $routes = rest_get_server()->get_routes();
        $result = [
            'ok' => true,
            'read_only' => true,
            'site' => home_url('/'),
            'plugin_version' => class_exists('YNS_WhatsApp_API') ? YNS_WhatsApp_API::VERSION : null,
            'wordpress_prefix' => $wpdb->prefix,
            'candidate_tables' => $tables,
            'user_meta_keys' => self::meta_keys($wpdb->usermeta, 'meta_key'),
            'post_meta_keys' => self::meta_keys($wpdb->postmeta, 'meta_key'),
            'known_runtime' => [
                'has_yns_sessions_route' => isset($routes['/yns/v1/sessions']),
                'has_yns_me_route' => isset($routes['/yns/v1/me']),
            ],
        ];
        return new WP_REST_Response($result, 200);
    }

    public static function join_summary() {
        $customers = self::customers();
        $schedule = self::schedule_rows();
        $packages = self::package_rows();
        $consented = 0;
        foreach ($customers as $c) if (!empty($c['consent_whatsapp'])) $consented++;
        $booked = 0; $waitlist = 0;
        foreach ($schedule as $row) {
            if (($row['reservation_status'] ?? '') === 'WAITLIST') $waitlist++;
            else $booked++;
        }
        return new WP_REST_Response([
            'ok'=>true,
            'read_only'=>true,
            'customers_with_phone'=>count($customers),
            'customers_with_whatsapp_consent_match'=>$consented,
            'future_booked_rows'=>$booked,
            'future_waitlist_rows'=>$waitlist,
            'active_package_rows'=>count($packages),
            'consent_policy'=>'fail_closed_ysu_contacts_granted',
            'blocked_customer_expiry_types'=>['asc','insurance','tessera_asc'],
        ], 200);
    }

    public static function resolve_recipients($rows, $segment) {
        if (is_array($rows) && !empty($rows)) return $rows;
        if (!is_array($segment)) $segment = [];
        if (self::expiry_type_blocked($segment)) return [];

        $customers = self::customers();
        if (!$customers) return [];

        $need_schedule = false;
        foreach (['course','weekday','time','event_id','waitlist'] as $key) {
            if (array_key_exists($key, $segment)) { $need_schedule = true; break; }
        }
        $need_package = array_key_exists('plan', $segment)
            || array_key_exists('expires_within_days', $segment)
            || (!empty($segment['expiry_type']) && self::text_norm($segment['expiry_type']) === 'package');

        $schedule_by_customer = [];
        if ($need_schedule) {
            foreach (self::schedule_rows() as $row) {
                $cid = (int)($row['customer_id'] ?? 0);
                if ($cid) $schedule_by_customer[$cid][] = $row;
            }
        }

        $package_by_customer = [];
        if ($need_package) {
            foreach (self::package_rows() as $row) {
                $cid = (int)($row['customer_id'] ?? 0);
                if ($cid) $package_by_customer[$cid][] = $row;
            }
        }

        $out = [];
        foreach ($customers as $cid => $customer) {
            $sched = null;
            if ($need_schedule) {
                foreach (($schedule_by_customer[$cid] ?? []) as $candidate) {
                    if (self::schedule_match($candidate, $segment)) { $sched = $candidate; break; }
                }
                if (!$sched) continue;
            }

            $pkg = null;
            if ($need_package) {
                foreach (($package_by_customer[$cid] ?? []) as $candidate) {
                    if (self::package_match($candidate, $segment)) { $pkg = $candidate; break; }
                }
                if (!$pkg) continue;
            }

            $row = $customer;
            $row['course'] = array_key_exists('course', $segment) ? (string)$segment['course'] : (string)($sched['activity_name'] ?? '');
            $row['weekday'] = array_key_exists('weekday', $segment) ? (string)$segment['weekday'] : ($sched ? (string)date('N', strtotime($sched['starts_at'])) : '');
            $row['time'] = array_key_exists('time', $segment) ? (string)$segment['time'] : ($sched ? date('H:i', strtotime($sched['starts_at'])) : '');
            $row['event_id'] = array_key_exists('event_id', $segment) ? (string)$segment['event_id'] : (string)($sched['session_id'] ?? '');
            $row['waitlist'] = $sched ? (($sched['reservation_status'] ?? '') === 'WAITLIST') : false;
            $row['plan'] = array_key_exists('plan', $segment) ? (string)$segment['plan'] : (string)($pkg['plan_name'] ?? '');
            $row['expires_at'] = (string)($pkg['expiry_date'] ?? '');
            $row['params'] = [];
            $out[] = $row;
        }
        return $out;
    }
}
YNS_WhatsApp_Datasource::init();
