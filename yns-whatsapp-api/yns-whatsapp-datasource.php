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
    }

    public static function admin_only() {
        return current_user_can('manage_options');
    }

    private static function safe_identifier($name) {
        return preg_match('/^[A-Za-z0-9_]+$/', (string)$name) ? (string)$name : '';
    }

    private static function candidate_table($table) {
        $t = strtolower((string)$table);
        foreach (['yns','yoganostress','booking','prenot','session','waitlist','carnet','subscription','membership'] as $needle) {
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

    public static function resolve_recipients($rows, $segment) {
        if (is_array($rows) && !empty($rows)) return $rows;
        return [];
    }
}
YNS_WhatsApp_Datasource::init();
