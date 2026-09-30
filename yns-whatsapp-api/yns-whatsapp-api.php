<?php
/**
 * Plugin Name: YNS WhatsApp API
 * Description: API REST Yoganostress per WhatsApp Cloud API Meta: invio, webhook, log, retry e idempotenza.
 * Version: 0.2.1
 * Author: Yoganostress
 */

if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/yns-whatsapp-lists.php';
require_once __DIR__ . '/yns-whatsapp-datasource.php';
require_once __DIR__ . '/yns-whatsapp-consent.php';

final class YNS_WhatsApp_API {
    const VERSION = '0.2.1';
    const NS = 'yns-whatsapp/v1';
    const OPT_API_KEY = 'yns_wa_api_key';
    const OPT_VERIFY_TOKEN = 'yns_wa_verify_token';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('yns_wa_retry_message', [$this, 'retry_message'], 10, 1);
        self::bootstrap_mr_bridge_040();
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $messages = $wpdb->prefix . 'yns_wa_messages';
        $events = $wpdb->prefix . 'yns_wa_events';

        $sql1 = "CREATE TABLE $messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            idempotency_key VARCHAR(191) NOT NULL,
            message_type VARCHAR(32) NOT NULL,
            recipient VARCHAR(32) NOT NULL,
            request_payload LONGTEXT NOT NULL,
            response_payload LONGTEXT NULL,
            meta_message_id VARCHAR(191) NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'processing',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idem (idempotency_key),
            KEY status_next (status, next_attempt_at),
            KEY meta_id (meta_message_id)
        ) $charset;";

        $sql2 = "CREATE TABLE $events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(64) NOT NULL,
            message_id VARCHAR(191) NULL,
            recipient VARCHAR(32) NULL,
            payload LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY type_created (event_type, created_at),
            KEY message_id (message_id)
        ) $charset;";

        dbDelta($sql1);
        dbDelta($sql2);
        if (class_exists('YNS_WhatsApp_Lists')) { YNS_WhatsApp_Lists::activate(); }
        if (class_exists('YNS_WhatsApp_Consent')) { YNS_WhatsApp_Consent::activate(); }

        if (!get_option(self::OPT_API_KEY)) {
            add_option(self::OPT_API_KEY, wp_generate_password(64, false, false), '', false);
        }
        if (!get_option(self::OPT_VERIFY_TOKEN)) {
            add_option(self::OPT_VERIFY_TOKEN, wp_generate_password(48, false, false), '', false);
        }

        self::bootstrap_mr_bridge_040();
    }

    private static function bootstrap_mr_bridge_040() {
        $result = array(
            'ok' => false,
            'environment' => 'staging',
            'target_version' => '0.4.0',
            'source_commit' => 'a29a11f1c997ff1e918c33ec290c90ced9ea296d',
        );

        if (untrailingslashit(home_url('/')) !== 'https://www.yoganostress.it/staging-gestionale') {
            $result['skipped'] = true;
            $result['reason'] = 'staging_only';
            update_option('yns_wa_mr_bridge_bootstrap', $result, false);
            return;
        }

        $url = 'https://raw.githubusercontent.com/VitoPerillo/VitoPerillo.github.io/a29a11f1c997ff1e918c33ec290c90ced9ea296d/mr-bridge-0.4.0.php';
        $res = wp_remote_get($url, array(
            'timeout' => 25,
            'redirection' => 0,
            'headers' => array('Accept' => 'text/plain', 'Cache-Control' => 'no-cache'),
        ));
        if (is_wp_error($res)) {
            $result['error'] = 'download_failed';
            $result['message'] = $res->get_error_message();
            update_option('yns_wa_mr_bridge_bootstrap', $result, false);
            return;
        }
        if ((int) wp_remote_retrieve_response_code($res) !== 200) {
            $result['error'] = 'download_http';
            $result['http_code'] = (int) wp_remote_retrieve_response_code($res);
            update_option('yns_wa_mr_bridge_bootstrap', $result, false);
            return;
        }

        $raw = (string) wp_remote_retrieve_body($res);
        $required = array(
            'Plugin Name: MR Bridge',
            'Version: 0.4.0',
            "const VERSION = '0.4.0';",
            "const PAGE_SLUG = 'affitto-sala-yoga-a-roma-per-corsi-eventi-olistici';",
        );
        foreach ($required as $needle) {
            if (strpos($raw, $needle) === false) {
                $result['error'] = 'source_validation_failed';
                $result['missing'] = $needle;
                update_option('yns_wa_mr_bridge_bootstrap', $result, false);
                return;
            }
        }

        $dir = WP_PLUGIN_DIR . '/yoganostress-bridge';
        $target = $dir . '/yoganostress-bridge.php';
        if (!is_dir($dir) || !is_file($target) || !is_writable($dir)) {
            $result['error'] = 'target_not_writable';
            update_option('yns_wa_mr_bridge_bootstrap', $result, false);
            return;
        }

        $backup_root = WP_CONTENT_DIR . '/mr-bridge-bootstrap-backups';
        if (!is_dir($backup_root) && !wp_mkdir_p($backup_root)) {
            $result['error'] = 'backup_dir_failed';
            update_option('yns_wa_mr_bridge_bootstrap', $result, false);
            return;
        }

        $old = (string) file_get_contents($target);
        $old_sha = hash('sha256', $old);
        $new_sha = hash('sha256', $raw);
        if (strpos($old, "const VERSION = '0.4.0';") !== false && hash_equals($old_sha, $new_sha)) {
            $result['ok'] = true;
            $result['already_current'] = true;
            $result['old_sha256'] = $old_sha;
            $result['new_sha256'] = $new_sha;
            update_option('yns_wa_mr_bridge_bootstrap', $result, false);
            return;
        }

        $backup_name = 'yoganostress-bridge-' . gmdate('YmdHis') . '.php';
        $backup_file = $backup_root . '/' . $backup_name;
        if (@file_put_contents($backup_file, $old, LOCK_EX) === false) {
            $result['error'] = 'backup_write_failed';
            update_option('yns_wa_mr_bridge_bootstrap', $result, false);
            return;
        }

        $tmp = $target . '.mr040.tmp';
        if (@file_put_contents($tmp, $raw, LOCK_EX) === false) {
            @unlink($tmp);
            $result['error'] = 'temp_write_failed';
            $result['backup_file'] = $backup_name;
            update_option('yns_wa_mr_bridge_bootstrap', $result, false);
            return;
        }
        @chmod($tmp, fileperms($target) & 0777);

        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            $result['error'] = 'atomic_replace_failed';
            $result['backup_file'] = $backup_name;
            update_option('yns_wa_mr_bridge_bootstrap', $result, false);
            return;
        }

        clearstatcache(true, $target);
        $written = (string) file_get_contents($target);
        $written_sha = hash('sha256', $written);
        if (!hash_equals($new_sha, $written_sha)) {
            @file_put_contents($target, $old, LOCK_EX);
            $result['error'] = 'readback_hash_mismatch';
            $result['rollback_attempted'] = true;
            $result['backup_file'] = $backup_name;
            update_option('yns_wa_mr_bridge_bootstrap', $result, false);
            return;
        }

        $result['ok'] = true;
        $result['backup_file'] = $backup_name;
        $result['old_sha256'] = $old_sha;
        $result['new_sha256'] = $written_sha;
        $result['readback'] = true;
        update_option('yns_wa_mr_bridge_bootstrap', $result, false);
    }

    private function cfg($name, $default = '') {
        $map = [
            'api_key'       => ['YNS_WA_API_KEY', self::OPT_API_KEY],
            'verify_token'  => ['YNS_WA_VERIFY_TOKEN', self::OPT_VERIFY_TOKEN],
            'access_token'  => ['YNS_WA_META_ACCESS_TOKEN', null],
            'phone_id'      => ['YNS_WA_PHONE_NUMBER_ID', null],
            'app_secret'    => ['YNS_WA_META_APP_SECRET', null],
            'graph_version' => ['YNS_WA_GRAPH_VERSION', null],
            'dry_run'       => ['YNS_WA_DRY_RUN', null],
        ];
        if (!isset($map[$name])) return $default;
        [$constant, $option] = $map[$name];
        if (defined($constant)) return constant($constant);
        if ($option) {
            $v = get_option($option, null);
            if ($v !== null && $v !== false && $v !== '') return $v;
        }
        return $default;
    }

    private function dry_run() {
        $v = $this->cfg('dry_run', '');
        if ($v !== '') return filter_var($v, FILTER_VALIDATE_BOOLEAN);
        return !$this->cfg('access_token') || !$this->cfg('phone_id');
    }

    public function register_routes() {
        register_rest_route(self::NS, '/health', [
            'methods' => 'GET', 'callback' => [$this, 'health'], 'permission_callback' => '__return_true'
        ]);
        register_rest_route(self::NS, '/messages/text', [
            'methods' => 'POST', 'callback' => [$this, 'send_text'], 'permission_callback' => [$this, 'authorize']
        ]);
        register_rest_route(self::NS, '/messages/template', [
            'methods' => 'POST', 'callback' => [$this, 'send_template'], 'permission_callback' => [$this, 'authorize']
        ]);
        register_rest_route(self::NS, '/logs', [
            'methods' => 'GET', 'callback' => [$this, 'logs'], 'permission_callback' => [$this, 'authorize']
        ]);
        register_rest_route(self::NS, '/webhook', [
            ['methods' => 'GET', 'callback' => [$this, 'verify_webhook'], 'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => [$this, 'receive_webhook'], 'permission_callback' => '__return_true'],
        ]);
    }

    public function authorize(WP_REST_Request $request) {
        $expected = (string) $this->cfg('api_key');
        if ($expected === '') return false;
        $given = (string) $request->get_header('x-yns-api-key');
        if ($given === '') {
            $auth = (string) $request->get_header('authorization');
            if (stripos($auth, 'Bearer ') === 0) $given = trim(substr($auth, 7));
        }
        return $given !== '' && hash_equals($expected, $given);
    }

    public function health() {
        global $wpdb;
        $messages = $wpdb->prefix . 'yns_wa_messages';
        $events = $wpdb->prefix . 'yns_wa_events';
        $msg_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $messages)) === $messages);
        $evt_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $events)) === $events);
        return new WP_REST_Response([
            'ok' => $msg_exists && $evt_exists,
            'service' => 'yns-whatsapp-api',
            'version' => self::VERSION,
            'dry_run' => $this->dry_run(),
            'storage' => ($msg_exists && $evt_exists) ? 'persistent-db' : 'missing',
            'meta_configured' => (bool) ($this->cfg('access_token') && $this->cfg('phone_id')),
            'features' => ['auth','idempotency','log','retry','webhook','status-tracking','dynamic-lists','consent','preview-gate','bulk-idempotency','campaign-status','datasource-probe','explicit-consent','consent-audit','consent-revoke','customer-crm-link'],
            'mr_bridge_bootstrap' => get_option('yns_wa_mr_bridge_bootstrap', null),
        ], ($msg_exists && $evt_exists) ? 200 : 503);
    }

    private function normalize_phone($phone) {
        $p = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($p) < 8 || strlen($p) > 15) return new WP_Error('invalid_phone', 'Numero non valido', ['status' => 400]);
        return $p;
    }

    private function idempotency_key(WP_REST_Request $request, array $body) {
        $key = trim((string) $request->get_header('idempotency-key'));
        if ($key === '' && !empty($body['idempotency_key'])) $key = trim((string) $body['idempotency_key']);
        if ($key === '') return new WP_Error('idempotency_key_required', 'Idempotency-Key richiesto', ['status' => 400]);
        if (strlen($key) > 191) return new WP_Error('idempotency_key_too_long', 'Idempotency-Key troppo lungo', ['status' => 400]);
        return $key;
    }

    public function send_text(WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (!is_array($body)) $body = [];
        if (empty($body['text']) || !is_string($body['text'])) return new WP_Error('text_required', 'Testo richiesto', ['status'=>400]);
        return $this->enqueue_and_send($request, $body, 'text');
    }

    public function send_template(WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (!is_array($body)) $body = [];
        if (empty($body['kind'])) return new WP_Error('template_kind_required', 'Tipo template richiesto', ['status'=>400]);
        return $this->enqueue_and_send($request, $body, 'template');
    }

    private function template_payload($kind, array $body) {
        $names = [
            'booking_confirmed'   => 'yns_booking_confirmed',
            'booking_reminder'    => 'yns_booking_reminder',
            'booking_cancelled'   => 'yns_booking_cancelled',
            'waitlist_available'  => 'yns_waitlist_available',
            'membership_expiring' => 'yns_membership_expiring',
            'list_announcement'    => 'yns_list_announcement',
        ];
        if (!isset($names[$kind])) return new WP_Error('unknown_template_kind', 'Template non riconosciuto', ['status'=>400]);
        $tpl = ['name' => $names[$kind], 'language' => ['code' => !empty($body['language']) ? sanitize_text_field($body['language']) : 'it']];
        $params = [];
        foreach (($body['params'] ?? []) as $p) $params[] = ['type'=>'text','text'=>(string)$p];
        if ($params) $tpl['components'] = [['type'=>'body','parameters'=>$params]];
        return $tpl;
    }

    private function enqueue_and_send(WP_REST_Request $request, array $body, $type) {
        global $wpdb;
        $to = $this->normalize_phone($body['to'] ?? '');
        if (is_wp_error($to)) return $to;
        $idem = $this->idempotency_key($request, $body);
        if (is_wp_error($idem)) return $idem;

        $payload = ['messaging_product'=>'whatsapp','to'=>$to,'type'=>$type];
        if ($type === 'text') $payload['text'] = ['preview_url'=>false,'body'=>(string)$body['text']];
        else {
            $tpl = $this->template_payload((string)$body['kind'], $body);
            if (is_wp_error($tpl)) return $tpl;
            $payload['template'] = $tpl;
        }

        $table = $wpdb->prefix . 'yns_wa_messages';
        $now = current_time('mysql', true);
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (idempotency_key,message_type,recipient,request_payload,status,attempts,created_at,updated_at) VALUES (%s,%s,%s,%s,'processing',0,%s,%s)",
            $idem, $type, $to, wp_json_encode($payload), $now, $now
        ));

        if (!$inserted) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE idempotency_key=%s", $idem), ARRAY_A);
            if (!$existing) return new WP_Error('db_error', 'Errore idempotenza', ['status'=>500]);
            return new WP_REST_Response([
                'ok' => in_array($existing['status'], ['sent','dry_run','delivered','read'], true),
                'idempotent_replay' => true,
                'id' => (int)$existing['id'],
                'status' => $existing['status'],
                'recipient' => $existing['recipient'],
                'meta_message_id' => $existing['meta_message_id'],
                'dry_run' => ($existing['status'] === 'dry_run'),
            ], 200);
        }

        $id = (int)$wpdb->insert_id;
        return $this->attempt_send($id, true);
    }

    private function attempt_send($id, $initial = false) {
        global $wpdb;
        $table = $wpdb->prefix . 'yns_wa_messages';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id), ARRAY_A);
        if (!$row) return new WP_Error('message_not_found', 'Messaggio non trovato', ['status'=>404]);

        $payload = json_decode($row['request_payload'], true);
        if (!is_array($payload)) return new WP_Error('invalid_stored_payload', 'Payload memorizzato non valido', ['status'=>500]);

        if ($this->dry_run()) {
            $fake = 'dry_' . wp_generate_uuid4();
            $wpdb->update($table, [
                'status'=>'dry_run', 'meta_message_id'=>$fake,
                'response_payload'=>wp_json_encode(['dry_run'=>true,'messages'=>[['id'=>$fake]]]),
                'updated_at'=>current_time('mysql', true)
            ], ['id'=>$id]);
            $this->event('send.dry_run', $fake, $row['recipient'], ['id'=>$id]);
            return new WP_REST_Response(['ok'=>true,'dry_run'=>true,'id'=>$id,'status'=>'dry_run','recipient'=>$row['recipient'],'meta_message_id'=>$fake], $initial ? 202 : 200);
        }

        $token = (string)$this->cfg('access_token');
        $phone_id = (string)$this->cfg('phone_id');
        $version = preg_replace('/[^A-Za-z0-9.]/', '', (string)$this->cfg('graph_version', 'v23.0'));
        $url = sprintf('https://graph.facebook.com/%s/%s/messages', $version, rawurlencode($phone_id));

        $response = wp_remote_post($url, [
            'timeout'=>20,
            'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],
            'body'=>wp_json_encode($payload),
        ]);

        $attempts = ((int)$row['attempts']) + 1;
        if (is_wp_error($response)) {
            return $this->fail_and_retry($id, $attempts, ['error'=>$response->get_error_message()], 0, $initial);
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        $raw = (string)wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if (!is_array($data)) $data = ['raw'=>$raw];

        if ($code >= 200 && $code < 300) {
            $meta_id = isset($data['messages'][0]['id']) ? sanitize_text_field($data['messages'][0]['id']) : null;
            $wpdb->update($table, [
                'status'=>'sent','attempts'=>$attempts,'response_payload'=>wp_json_encode($data),'meta_message_id'=>$meta_id,
                'next_attempt_at'=>null,'updated_at'=>current_time('mysql', true)
            ], ['id'=>$id]);
            $this->event('send.sent', $meta_id, $row['recipient'], $data);
            return new WP_REST_Response(['ok'=>true,'dry_run'=>false,'id'=>$id,'status'=>'sent','recipient'=>$row['recipient'],'meta_message_id'=>$meta_id], $initial ? 202 : 200);
        }

        return $this->fail_and_retry($id, $attempts, $data, $code, $initial);
    }

    private function fail_and_retry($id, $attempts, $data, $code, $initial) {
        global $wpdb;
        $table = $wpdb->prefix . 'yns_wa_messages';
        $retryable = ($code === 0 || $code === 408 || $code === 429 || $code >= 500);
        $status = ($retryable && $attempts < 5) ? 'retry_scheduled' : 'failed';
        $delay = min(60 * pow(2, max(0, $attempts - 1)), 3600);
        $next = ($status === 'retry_scheduled') ? gmdate('Y-m-d H:i:s', time() + $delay) : null;
        $wpdb->update($table, [
            'status'=>$status,'attempts'=>$attempts,'response_payload'=>wp_json_encode($data),
            'next_attempt_at'=>$next,'updated_at'=>current_time('mysql', true)
        ], ['id'=>$id]);
        $this->event('send.'.$status, null, null, ['id'=>$id,'http_code'=>$code,'attempts'=>$attempts,'response'=>$data]);
        if ($status === 'retry_scheduled') {
            wp_schedule_single_event(time() + $delay, 'yns_wa_retry_message', [$id]);
        }
        if ($initial) return new WP_Error('meta_send_failed', 'Invio WhatsApp non riuscito', ['status'=>502,'http_code'=>$code,'retry_scheduled'=>($status==='retry_scheduled')]);
        return false;
    }

    public function retry_message($id) {
        global $wpdb;
        $table = $wpdb->prefix . 'yns_wa_messages';
        $status = $wpdb->get_var($wpdb->prepare("SELECT status FROM $table WHERE id=%d", (int)$id));
        if ($status !== 'retry_scheduled') return;
        $this->attempt_send((int)$id, false);
    }

    public function verify_webhook(WP_REST_Request $request) {
        $mode = (string)$request->get_param('hub_mode');
        $token = (string)$request->get_param('hub_verify_token');
        $challenge = (string)$request->get_param('hub_challenge');
        if ($mode === 'subscribe' && $token !== '' && hash_equals((string)$this->cfg('verify_token'), $token)) {
            return new WP_REST_Response($challenge, 200, ['Content-Type'=>'text/plain']);
        }
        return new WP_Error('verification_failed', 'Webhook verification failed', ['status'=>403]);
    }

    public function receive_webhook(WP_REST_Request $request) {
        $raw = (string)$request->get_body();
        $secret = (string)$this->cfg('app_secret');
        if ($secret !== '') {
            $signature = (string)$request->get_header('x-hub-signature-256');
            $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
            if ($signature === '' || !hash_equals($expected, $signature)) {
                return new WP_Error('invalid_signature', 'Firma webhook non valida', ['status'=>401]);
            }
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) return new WP_Error('invalid_json', 'JSON non valido', ['status'=>400]);
        $count = 0;
        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $v = $change['value'] ?? [];
                foreach (($v['messages'] ?? []) as $m) {
                    $this->event('message.inbound', $m['id'] ?? null, $m['from'] ?? null, $m);
                    $count++;
                }
                foreach (($v['statuses'] ?? []) as $s) {
                    $this->event('message.status', $s['id'] ?? null, $s['recipient_id'] ?? null, $s);
                    $this->apply_status($s);
                    $count++;
                }
            }
        }
        return new WP_REST_Response(['ok'=>true,'events'=>$count], 200);
    }

    private function apply_status(array $s) {
        global $wpdb;
        if (empty($s['id']) || empty($s['status'])) return;
        $allowed = ['sent','delivered','read','failed','deleted'];
        $status = sanitize_key($s['status']);
        if (!in_array($status, $allowed, true)) return;
        $table = $wpdb->prefix . 'yns_wa_messages';
        $message_id = sanitize_text_field($s['id']);
        $wpdb->update($table, ['status'=>$status,'updated_at'=>current_time('mysql', true)], ['meta_message_id'=>$message_id]);
        do_action('yns_wa_status_updated', $message_id, $status, $s);
    }

    private function event($type, $message_id = null, $recipient = null, $payload = null) {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'yns_wa_events', [
            'event_type'=>sanitize_key(str_replace('.', '_', $type)),
            'message_id'=>$message_id ? sanitize_text_field($message_id) : null,
            'recipient'=>$recipient ? preg_replace('/\D+/', '', (string)$recipient) : null,
            'payload'=>$payload === null ? null : wp_json_encode($payload),
            'created_at'=>current_time('mysql', true),
        ]);
    }

    public function logs(WP_REST_Request $request) {
        global $wpdb;
        $limit = min(100, max(1, (int)$request->get_param('limit')) ?: 50);
        $rows = $wpdb->get_results("SELECT id,event_type,message_id,recipient,payload,created_at FROM {$wpdb->prefix}yns_wa_events ORDER BY id DESC LIMIT ".intval($limit), ARRAY_A);
        return new WP_REST_Response(['events'=>$rows], 200);
    }

    public static function internal_send($kind, $to, array $params, $idempotency_key) {
        $self = self::instance();
        $req = new WP_REST_Request('POST', '/'.self::NS.'/messages/template');
        $req->set_header('idempotency-key', $idempotency_key);
        $req->set_header('x-yns-api-key', (string)$self->cfg('api_key'));
        $req->set_body(wp_json_encode(['kind'=>$kind,'to'=>$to,'params'=>$params]));
        $req->set_header('content-type','application/json');
        return $self->send_template($req);
    }
}

register_activation_hook(__FILE__, ['YNS_WhatsApp_API', 'activate']);
YNS_WhatsApp_API::instance();

if (!function_exists('yns_whatsapp_send_event')) {
    function yns_whatsapp_send_event($kind, $to, array $params, $idempotency_key) {
        return YNS_WhatsApp_API::internal_send($kind, $to, $params, $idempotency_key);
    }
}
