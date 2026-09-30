<?php
if (!defined('ABSPATH')) { exit; }

final class YNS_WhatsApp_Lists {
    const NS = 'yns-whatsapp/v1';
    const PREVIEW_TTL = 900;
    const MAX_RECIPIENTS = 500;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
        add_action('yns_wa_status_updated', [__CLASS__, 'status_updated'], 10, 3);
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $campaigns = $wpdb->prefix . 'yns_wa_campaigns';
        $recipients = $wpdb->prefix . 'yns_wa_campaign_recipients';

        dbDelta("CREATE TABLE $campaigns (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id VARCHAR(191) NOT NULL,
            preview_token VARCHAR(191) NOT NULL,
            segment_json LONGTEXT NULL,
            template_json LONGTEXT NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'sending',
            eligible_count INT UNSIGNED NOT NULL DEFAULT 0,
            excluded_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY campaign_id (campaign_id),
            KEY status (status)
        ) $charset;");

        dbDelta("CREATE TABLE $recipients (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            campaign_id VARCHAR(191) NOT NULL,
            contact_id VARCHAR(191) NULL,
            recipient VARCHAR(32) NOT NULL,
            meta_message_id VARCHAR(191) NULL,
            status VARCHAR(32) NOT NULL,
            error_text TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY campaign_recipient (campaign_id, recipient),
            KEY meta_message_id (meta_message_id),
            KEY campaign_status (campaign_id, status)
        ) $charset;");
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/lists/preview', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'preview'], 'permission_callback' => [__CLASS__, 'authorize']
        ]);
        register_rest_route(self::NS, '/lists/send', [
            'methods' => 'POST', 'callback' => [__CLASS__, 'send'], 'permission_callback' => [__CLASS__, 'authorize']
        ]);
        register_rest_route(self::NS, '/campaigns/(?P<id>[A-Za-z0-9_-]+)', [
            'methods' => 'GET', 'callback' => [__CLASS__, 'campaign'], 'permission_callback' => [__CLASS__, 'authorize']
        ]);
    }

    public static function authorize(WP_REST_Request $request) {
        $expected = defined('YNS_WA_API_KEY') ? (string)YNS_WA_API_KEY : (string)get_option('yns_wa_api_key', '');
        if ($expected === '') return false;
        $given = (string)$request->get_header('x-yns-api-key');
        if ($given === '') {
            $auth = (string)$request->get_header('authorization');
            if (stripos($auth, 'Bearer ') === 0) $given = trim(substr($auth, 7));
        }
        return $given !== '' && hash_equals($expected, $given);
    }

    private static function bool_value($v) {
        return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'yes';
    }

    private static function normalize_phone($phone) {
        $p = preg_replace('/\D+/', '', (string)$phone);
        return (strlen($p) >= 8 && strlen($p) <= 15) ? $p : '';
    }

    private static function scalar_match($actual, $expected) {
        if ($expected === null || $expected === '') return true;
        if (is_array($expected)) return in_array((string)$actual, array_map('strval', $expected), true);
        return (string)$actual === (string)$expected;
    }

    private static function filter_recipients(array $rows, array $segment) {
        $eligible = [];
        $excluded = ['no_consent'=>0, 'duplicate'=>0, 'invalid_phone'=>0, 'segment_mismatch'=>0];
        $seen = [];
        $expires_days = array_key_exists('expires_within_days', $segment) ? (int)$segment['expires_within_days'] : null;
        $now = time();

        foreach ($rows as $raw) {
            if (!is_array($raw)) { $excluded['segment_mismatch']++; continue; }
            $matches = self::scalar_match($raw['course'] ?? '', $segment['course'] ?? null)
                && self::scalar_match($raw['weekday'] ?? '', $segment['weekday'] ?? null)
                && self::scalar_match($raw['time'] ?? '', $segment['time'] ?? null)
                && self::scalar_match($raw['event_id'] ?? '', $segment['event_id'] ?? null)
                && self::scalar_match(self::bool_value($raw['waitlist'] ?? false) ? '1' : '0', isset($segment['waitlist']) ? (self::bool_value($segment['waitlist']) ? '1' : '0') : null)
                && self::scalar_match($raw['plan'] ?? '', $segment['plan'] ?? null);
            if (!$matches) { $excluded['segment_mismatch']++; continue; }

            if ($expires_days !== null) {
                $t = !empty($raw['expires_at']) ? strtotime((string)$raw['expires_at']) : false;
                if (!$t || $t < $now || $t > ($now + $expires_days * DAY_IN_SECONDS)) { $excluded['segment_mismatch']++; continue; }
            }
            if (!self::bool_value($raw['consent_whatsapp'] ?? false)) { $excluded['no_consent']++; continue; }
            $phone = self::normalize_phone($raw['phone'] ?? ($raw['to'] ?? ''));
            if ($phone === '') { $excluded['invalid_phone']++; continue; }
            if (isset($seen[$phone])) { $excluded['duplicate']++; continue; }
            $seen[$phone] = true;
            $eligible[] = [
                'contact_id' => sanitize_text_field((string)($raw['contact_id'] ?? ($raw['id'] ?? $phone))),
                'name' => sanitize_text_field((string)($raw['name'] ?? '')),
                'phone' => $phone,
                'params' => array_values(array_map('strval', is_array($raw['params'] ?? null) ? $raw['params'] : [])),
            ];
        }
        return [$eligible, $excluded];
    }

    private static function resolve_recipients(array $segment, array $provided) {
        if ($provided) return $provided;
        $rows = apply_filters('yns_wa_list_recipients', [], $segment);
        return is_array($rows) ? $rows : [];
    }

    private static function mask_phone($p) {
        $p = (string)$p;
        return strlen($p) < 6 ? '***' : substr($p, 0, 3) . '***' . substr($p, -3);
    }

    private static function allowed_template_kind($kind) {
        return in_array($kind, ['booking_confirmed','booking_reminder','booking_cancelled','waitlist_available','membership_expiring','list_announcement'], true);
    }

    public static function preview(WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (!is_array($body)) $body = [];
        $segment = is_array($body['segment'] ?? null) ? $body['segment'] : [];
        $template = is_array($body['template'] ?? null) ? $body['template'] : [];
        $kind = sanitize_key((string)($template['kind'] ?? ''));
        if (!self::allowed_template_kind($kind)) return new WP_Error('template_kind_required', 'Template non valido', ['status'=>400]);

        $provided = is_array($body['recipients'] ?? null) ? $body['recipients'] : [];
        $rows = self::resolve_recipients($segment, $provided);
        [$eligible, $excluded] = self::filter_recipients($rows, $segment);
        if (count($eligible) > self::MAX_RECIPIENTS) return new WP_Error('bulk_recipient_limit_exceeded', 'Lista oltre il limite di sicurezza', ['status'=>400]);

        $token = wp_generate_uuid4();
        $snapshot = [
            'created_at'=>time(), 'expires_at'=>time()+self::PREVIEW_TTL, 'segment'=>$segment,
            'template'=>$template, 'eligible'=>$eligible, 'excluded'=>$excluded,
        ];
        set_transient('yns_wa_preview_' . str_replace('-', '_', $token), $snapshot, self::PREVIEW_TTL);
        do_action('yns_wa_list_preview_created', $token, $snapshot);

        $sample = [];
        foreach (array_slice($eligible, 0, 10) as $r) $sample[] = ['contact_id'=>$r['contact_id'],'name'=>$r['name'],'phone'=>self::mask_phone($r['phone'])];
        return new WP_REST_Response([
            'ok'=>true, 'preview_token'=>$token, 'expires_at'=>gmdate('c', $snapshot['expires_at']),
            'eligible_count'=>count($eligible), 'excluded_count'=>array_sum($excluded), 'excluded'=>$excluded,
            'sample'=>$sample, 'template'=>['kind'=>$kind,'language'=>sanitize_text_field((string)($template['language'] ?? 'it'))],
        ], 200);
    }

    private static function load_preview($token) {
        $key = 'yns_wa_preview_' . str_replace('-', '_', $token);
        $snapshot = get_transient($key);
        if (!is_array($snapshot)) return new WP_Error('preview_not_found_or_expired', 'Anteprima non trovata o scaduta', ['status'=>400]);
        return $snapshot;
    }

    public static function send(WP_REST_Request $request) {
        global $wpdb;
        $body = $request->get_json_params();
        if (!is_array($body)) $body = [];
        $token = sanitize_text_field((string)($body['preview_token'] ?? ''));
        if ($token === '') return new WP_Error('preview_token_required', 'Preview token richiesto', ['status'=>400]);
        if ((string)($body['confirm'] ?? '') !== 'SEND:' . $token) return new WP_Error('preview_confirmation_required', 'Conferma anteprima richiesta', ['status'=>400]);
        $snapshot = self::load_preview($token);
        if (is_wp_error($snapshot)) return $snapshot;

        $campaign_id = sanitize_key((string)($body['campaign_id'] ?? ''));
        if ($campaign_id === '') $campaign_id = 'cmp_' . str_replace('-', '', wp_generate_uuid4());
        $campaigns = $wpdb->prefix . 'yns_wa_campaigns';
        $recipients_table = $wpdb->prefix . 'yns_wa_campaign_recipients';
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $campaigns WHERE campaign_id=%s", $campaign_id), ARRAY_A);
        if ($existing) return self::campaign_response($campaign_id);

        $now = current_time('mysql', true);
        $wpdb->insert($campaigns, [
            'campaign_id'=>$campaign_id, 'preview_token'=>$token,
            'segment_json'=>wp_json_encode($snapshot['segment']), 'template_json'=>wp_json_encode($snapshot['template']),
            'status'=>'sending', 'eligible_count'=>count($snapshot['eligible']), 'excluded_json'=>wp_json_encode($snapshot['excluded']),
            'created_at'=>$now, 'updated_at'=>$now,
        ]);

        foreach ($snapshot['eligible'] as $r) {
            $params = !empty($r['params']) ? $r['params'] : (is_array($snapshot['template']['params'] ?? null) ? $snapshot['template']['params'] : []);
            $idem = 'bulk:' . $campaign_id . ':' . $r['phone'] . ':' . sanitize_key((string)$snapshot['template']['kind']);
            $result = yns_whatsapp_send_event((string)$snapshot['template']['kind'], $r['phone'], $params, $idem);
            $status = 'failed'; $meta_id = null; $error = null;
            if (is_wp_error($result)) {
                $error = $result->get_error_message();
            } else {
                $data = $result instanceof WP_REST_Response ? $result->get_data() : $result;
                if (is_array($data)) {
                    $status = !empty($data['dry_run']) ? 'dry_run' : (string)($data['status'] ?? 'sent');
                    $meta_id = !empty($data['meta_message_id']) ? sanitize_text_field($data['meta_message_id']) : null;
                }
            }
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $recipients_table (campaign_id,contact_id,recipient,meta_message_id,status,error_text,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,%s,%s)",
                $campaign_id, $r['contact_id'], $r['phone'], $meta_id, $status, $error, $now, $now
            ));
        }
        $wpdb->update($campaigns, ['status'=>'completed','updated_at'=>current_time('mysql', true)], ['campaign_id'=>$campaign_id]);
        do_action('yns_wa_list_campaign_sent', $campaign_id, $snapshot);
        return self::campaign_response($campaign_id, 202);
    }

    public static function campaign(WP_REST_Request $request) {
        return self::campaign_response(sanitize_key((string)$request['id']));
    }

    private static function campaign_response($campaign_id, $status_code = 200) {
        global $wpdb;
        $campaigns = $wpdb->prefix . 'yns_wa_campaigns';
        $recipients = $wpdb->prefix . 'yns_wa_campaign_recipients';
        $c = $wpdb->get_row($wpdb->prepare("SELECT * FROM $campaigns WHERE campaign_id=%s", $campaign_id), ARRAY_A);
        if (!$c) return new WP_Error('campaign_not_found', 'Campagna non trovata', ['status'=>404]);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS n FROM $recipients WHERE campaign_id=%s GROUP BY status", $campaign_id), ARRAY_A);
        $counts = [];
        foreach ($rows as $row) $counts[$row['status']] = (int)$row['n'];
        return new WP_REST_Response([
            'ok'=>true, 'campaign_id'=>$campaign_id, 'status'=>$c['status'], 'eligible_count'=>(int)$c['eligible_count'],
            'counts'=>$counts, 'excluded'=>json_decode((string)$c['excluded_json'], true) ?: [],
            'segment'=>json_decode((string)$c['segment_json'], true) ?: [], 'template'=>json_decode((string)$c['template_json'], true) ?: [],
            'created_at'=>$c['created_at'], 'updated_at'=>$c['updated_at'],
        ], $status_code);
    }

    public static function status_updated($message_id, $status, $payload) {
        global $wpdb;
        if (!$message_id) return;
        $wpdb->update($wpdb->prefix . 'yns_wa_campaign_recipients', [
            'status'=>sanitize_key((string)$status), 'updated_at'=>current_time('mysql', true)
        ], ['meta_message_id'=>sanitize_text_field((string)$message_id)]);
    }
}
YNS_WhatsApp_Lists::init();
