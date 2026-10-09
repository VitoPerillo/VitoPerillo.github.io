<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Staging-only Yoganostress E2E fixture lifecycle.
 *
 * This capability deliberately exposes no arbitrary SQL, shell or table selector.
 * It can only create, inspect and tear down a tracked synthetic YNS customer fixture.
 * First-hop runtime reload is validated by the canonical self-update gate.
 */
final class MR_Bridge_YNS_E2E_V1 {
    const STORE_OPTION = 'mr_bridge_yns_e2e_fixtures_v1';
    const EXPECTED_YNS_VERSION = '0.4.26-GO-LIVE-MINIMUM-RC4';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/qa/yns-e2e/create', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'create_fixture'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/qa/yns-e2e/status', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'fixture_status'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/qa/yns-e2e/teardown', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'teardown_fixture'),
            'permission_callback' => '__return_true',
        ));
    }

    private static function authorize() {
        $claims = MR_Bridge_Deploy_V1::authorize_staging_automation();
        if (is_wp_error($claims)) { return $claims; }
        if (!class_exists('YNS_DB') || !class_exists('YNS_Auth_Service') || !class_exists('YNS_Booking_Service')
            || !class_exists('YNS_Entitlement_Service') || !class_exists('YNS_Security')) {
            return new WP_Error('mr_yns_missing', 'Gestionale Yoganostress non disponibile.', array('status' => 409));
        }
        if (!defined('YNS_VERSION') || (string) YNS_VERSION !== self::EXPECTED_YNS_VERSION) {
            return new WP_Error('mr_yns_version', 'Versione gestionale non compatibile con il gate E2E.', array(
                'status' => 409,
                'expected' => self::EXPECTED_YNS_VERSION,
                'actual' => defined('YNS_VERSION') ? (string) YNS_VERSION : '',
            ));
        }
        return $claims;
    }

    private static function body(WP_REST_Request $request) {
        $body = $request->get_json_params();
        return is_array($body) ? $body : array();
    }

    private static function store() {
        $rows = get_option(self::STORE_OPTION, array());
        return is_array($rows) ? $rows : array();
    }

    private static function save_store($rows) {
        update_option(self::STORE_OPTION, is_array($rows) ? $rows : array(), false);
    }

    private static function fixture_id($value) {
        $value = strtolower(sanitize_text_field((string) $value));
        return preg_match('/^ynse2e-[a-f0-9]{16}$/', $value) ? $value : '';
    }

    private static function table($name) {
        return YNS_DB::table($name);
    }

    private static function choose_session() {
        global $wpdb;
        $sessions = self::table('sessions');
        $activities = self::table('activities');
        $types = self::table('activity_types');
        $reservations = self::table('reservations');
        $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Rome');
        $from = (new DateTimeImmutable('now', $tz))->modify('+36 hours')->format('Y-m-d H:i:s');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id,s.activity_id,s.starts_at,s.ends_at,s.capacity,a.name,a.mode,at.slug activity_type,
                (SELECT COUNT(*) FROM {$reservations} r WHERE r.session_id=s.id AND r.status='BOOKED') booked
             FROM {$sessions} s
             JOIN {$activities} a ON a.id=s.activity_id
             JOIN {$types} at ON at.id=a.activity_type_id
             WHERE s.status='OPEN'
               AND s.session_kind<>'appointment'
               AND a.active=1
               AND at.slug='course'
               AND a.mode IN ('presence','hybrid')
               AND s.starts_at>=%s
             ORDER BY s.starts_at ASC
             LIMIT 60",
            $from
        ), ARRAY_A);
        foreach ((array) $rows as $row) {
            $capacity = $row['capacity'] === null ? null : (int) $row['capacity'];
            if ($capacity !== null && (int) $row['booked'] >= $capacity) { continue; }
            if (YNS_Booking_Service::is_late_cancel((string) $row['starts_at'])) { continue; }
            return $row;
        }
        return new WP_Error('mr_yns_no_session', 'Nessuna pratica futura idonea per il test E2E.', array('status' => 409));
    }

    private static function choose_plan($session_id, $customer_id) {
        $plans = YNS_Entitlement_Service::available_plans_for_session((int) $session_id, (int) $customer_id);
        if (!$plans) {
            return new WP_Error('mr_yns_no_plan', 'Nessun carnet compatibile disponibile per la fixture E2E.', array('status' => 409));
        }
        $priority = array(
            '10 Esperienze di gruppo',
            '1 Esperienza di gruppo',
            '4 Esperienze di gruppo',
            '8 Esperienze di gruppo',
            '20 Esperienze di gruppo',
            'Promo 12 Esperienze di gruppo entro 3 mesi',
            'Promo 24 Esperienze di gruppo entro 3 mesi',
            'Promo 24 Esperienze di gruppo entro 6 mesi',
        );
        foreach ($priority as $wanted) {
            foreach ($plans as $plan) {
                if ((string) ($plan['name'] ?? '') === $wanted) { return $plan; }
            }
        }
        foreach ($plans as $plan) {
            $name = (string) ($plan['name'] ?? '');
            if ($name !== 'Esperienza di gruppo di prova' && $name !== 'Seconda esperienza di prova') { return $plan; }
        }
        return $plans[0];
    }

    public static function create_fixture(WP_REST_Request $request) {
        $auth = self::authorize();
        if (is_wp_error($auth)) { return $auth; }
        $body = self::body($request);
        if (($body['confirm'] ?? '') !== 'CREATE:YNS-PHASE0-E2E') {
            return new WP_Error('mr_yns_confirm', 'Conferma fixture E2E non valida.', array('status' => 400));
        }
        $request_id = sanitize_text_field((string) ($body['request_id'] ?? ''));
        $package_mode = sanitize_key((string) ($body['package_mode'] ?? 'assigned'));
        if (!in_array($package_mode, array('assigned','none'), true)) {
            return new WP_Error('mr_yns_package_mode', 'package_mode E2E non valido.', array('status' => 400));
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{16,96}$/', $request_id)) {
            return new WP_Error('mr_yns_request', 'request_id E2E non valido.', array('status' => 400));
        }

        $store = self::store();
        foreach ($store as $existing) {
            if (is_array($existing) && ($existing['request_id'] ?? '') === $request_id && !empty($existing['fixture_id'])) {
                return rest_ensure_response(array(
                    'ok' => true,
                    'idempotent' => true,
                    'fixture' => self::public_fixture($existing, ''),
                ));
            }
        }

        $session = self::choose_session();
        if (is_wp_error($session)) { return $session; }

        global $wpdb;
        $seed = hash('sha256', $request_id . '|' . wp_generate_uuid4());
        $fixture_id = 'ynse2e-' . substr($seed, 0, 16);
        $email = 'ynstest-phase0-' . substr($seed, 0, 12) . '@example.com';
        $phone_digits = str_pad((string) (hexdec(substr($seed, 0, 7)) % 10000000), 7, '0', STR_PAD_LEFT);
        $phone = '+39333' . $phone_digits;
        $now = YNS_DB::now_mysql();
        $today = current_time('Y-m-d');
        $expires = (new DateTimeImmutable($today, function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('Europe/Rome')))
            ->modify('+364 days')->format('Y-m-d');

        $customers = self::table('customers');
        $compliance = self::table('compliance');
        $packages = self::table('customer_packages');
        $challenges = self::table('auth_challenges');

        $wpdb->query('START TRANSACTION');
        try {
            $ok = $wpdb->insert($customers, array(
                'wp_user_id' => null,
                'first_name' => 'YNSTEST',
                'last_name' => 'Phase0 ' . strtoupper(substr($seed, 0, 6)),
                'email' => $email,
                'phone' => $phone,
                'reminder_minutes' => 120,
                'active' => 1,
                'email_verified_at' => $now,
                'privacy_accepted_at' => $now,
                'onboarding_completed_at' => $now,
                'profile_json' => wp_json_encode(array()),
                'created_at' => $now,
                'updated_at' => $now,
            ));
            if ($ok === false || !$wpdb->insert_id) { throw new RuntimeException('Creazione cliente fixture fallita.'); }
            $customer_id = (int) $wpdb->insert_id;

            $compliance_ids = array();
            foreach (array(
                array('annual_membership', 'Iscrizione test MR E2E'),
                array('registration_form', 'Modulo test MR E2E'),
                array('medical_certificate', 'Certificato test MR E2E'),
            ) as $row) {
                $ok = $wpdb->insert($compliance, array(
                    'customer_id' => $customer_id,
                    'type' => $row[0],
                    'starts_on' => $today,
                    'expires_on' => $expires,
                    'status' => 'ACTIVE',
                    'notes' => $row[1] . ' ' . $fixture_id,
                    'created_at' => $now,
                ));
                if ($ok === false || !$wpdb->insert_id) { throw new RuntimeException('Creazione compliance fixture fallita.'); }
                $compliance_ids[] = (int) $wpdb->insert_id;
            }

            $plan = self::choose_plan((int) $session['id'], $customer_id);
            if (is_wp_error($plan)) { throw new RuntimeException($plan->get_error_message()); }
            $plan_id = (int) ($plan['id'] ?? 0);
            if ($plan_id <= 0) { throw new RuntimeException('Carnet fixture non valido.'); }

            $package_id = 0;
            if ($package_mode === 'assigned') {
                $ok = $wpdb->insert($packages, array(
                    'customer_id' => $customer_id,
                    'package_plan_id' => $plan_id,
                    'status' => 'PENDING',
                    'activation_date' => null,
                    'expiry_date' => null,
                    'activation_locked' => 0,
                    'assigned_at' => $now,
                    'payment_status' => 'PAID',
                    'paid_at' => $now,
                    'payment_note' => 'MR E2E fixture preassegnata',
                    'manual_expiry_override' => null,
                    'closed_at' => null,
                    'notes' => 'MR E2E fixture ' . $fixture_id,
                ));
                if ($ok === false || !$wpdb->insert_id) { throw new RuntimeException('Creazione carnet fixture fallita.'); }
                $package_id = (int) $wpdb->insert_id;
            }

            $magic_token = YNS_Security::random_token(32);
            $magic_hash = YNS_Security::hmac($magic_token, 'magic-link');
            $return_path = '/prenotazioni-yoganostress/?yns_view=presence&yns_session=' . (int) $session['id'];
            $ok = $wpdb->insert($challenges, array(
                'customer_id' => $customer_id,
                'wp_user_id' => null,
                'email' => $email,
                'code_hash' => '',
                'token_hash' => $magic_hash,
                'purpose' => 'client_login_unified',
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS),
                'attempts' => 0,
                'status' => 'PENDING',
                'consumed_at' => null,
                'request_ip_hash' => hash('sha256', 'mr-e2e|' . $fixture_id),
                'return_path' => $return_path,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ));
            if ($ok === false || !$wpdb->insert_id) { throw new RuntimeException('Creazione accesso fixture fallita.'); }
            $challenge_id = (int) $wpdb->insert_id;

            $fixture = array(
                'fixture_id' => $fixture_id,
                'request_id' => $request_id,
                'customer_id' => $customer_id,
                'package_id' => $package_id,
                'package_mode' => $package_mode,
                'plan_id' => $plan_id,
                'plan_name' => (string) ($plan['name'] ?? ''),
                'session_id' => (int) $session['id'],
                'session_starts_at' => (string) $session['starts_at'],
                'challenge_id' => $challenge_id,
                'compliance_ids' => $compliance_ids,
                'email' => $email,
                'created_at' => current_time('mysql', true),
            );
            $store[$fixture_id] = $fixture;
            self::save_store($store);
            $wpdb->query('COMMIT');

            $magic_url = add_query_arg('token', rawurlencode($magic_token), home_url('/yns-magic-login/'));
            MR_Bridge::log('yns_e2e_fixture_created', array(
                'fixture_id' => $fixture_id,
                'customer_id' => $customer_id,
                'package_id' => $package_id,
                'package_mode' => $package_mode,
                'session_id' => (int) $session['id'],
                'plan_id' => $plan_id,
            ));
            return rest_ensure_response(array(
                'ok' => true,
                'idempotent' => false,
                'fixture' => self::public_fixture($fixture, $magic_url),
            ));
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mr_yns_fixture_create', $e->getMessage(), array('status' => 500));
        }
    }

    private static function public_fixture($fixture, $magic_url) {
        return array(
            'fixture_id' => (string) ($fixture['fixture_id'] ?? ''),
            'customer_id' => (int) ($fixture['customer_id'] ?? 0),
            'package_id' => (int) ($fixture['package_id'] ?? 0),
            'package_mode' => (string) ($fixture['package_mode'] ?? 'assigned'),
            'plan_id' => (int) ($fixture['plan_id'] ?? 0),
            'plan_name' => (string) ($fixture['plan_name'] ?? ''),
            'session_id' => (int) ($fixture['session_id'] ?? 0),
            'session_starts_at' => (string) ($fixture['session_starts_at'] ?? ''),
            'booking_url' => home_url('/prenotazioni-yoganostress/?yns_view=presence&yns_session=' . (int) ($fixture['session_id'] ?? 0)),
            'magic_url' => $magic_url,
        );
    }

    private static function load_fixture($fixture_id) {
        $fixture_id = self::fixture_id($fixture_id);
        if ($fixture_id === '') { return new WP_Error('mr_yns_fixture_id', 'fixture_id non valido.', array('status' => 400)); }
        $store = self::store();
        if (empty($store[$fixture_id]) || !is_array($store[$fixture_id])) {
            return new WP_Error('mr_yns_fixture_missing', 'Fixture E2E non trovata.', array('status' => 404));
        }
        return $store[$fixture_id];
    }

    public static function fixture_status(WP_REST_Request $request) {
        $auth = self::authorize();
        if (is_wp_error($auth)) { return $auth; }
        $body = self::body($request);
        $fixture = self::load_fixture($body['fixture_id'] ?? '');
        if (is_wp_error($fixture)) { return $fixture; }

        global $wpdb;
        $customer_id = (int) $fixture['customer_id'];
        $package_id = (int) $fixture['package_id'];
        $session_id = (int) $fixture['session_id'];

        $customer_exists = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table('customers') . ' WHERE id=%d AND email LIKE %s',
            $customer_id, 'ynstest-phase0-%@example.com'
        ));
        if ($package_id > 0) {
            $package = $wpdb->get_row($wpdb->prepare(
                'SELECT id,status,activation_date,expiry_date,package_plan_id,payment_status,paid_at FROM ' . self::table('customer_packages') . ' WHERE id=%d AND customer_id=%d',
                $package_id, $customer_id
            ), ARRAY_A);
        } else {
            $package = $wpdb->get_row($wpdb->prepare(
                'SELECT id,status,activation_date,expiry_date,package_plan_id,payment_status,paid_at FROM ' . self::table('customer_packages') . ' WHERE customer_id=%d AND package_plan_id=%d ORDER BY id DESC LIMIT 1',
                $customer_id, (int) ($fixture['plan_id'] ?? 0)
            ), ARRAY_A);
            if ($package) { $package_id = (int) $package['id']; }
        }
        $reservation = $wpdb->get_row($wpdb->prepare(
            'SELECT id,status,customer_package_id,active_key,booked_at,cancelled_at FROM ' . self::table('reservations') . ' WHERE customer_id=%d AND session_id=%d ORDER BY id DESC LIMIT 1',
            $customer_id, $session_id
        ), ARRAY_A);
        $ledger_net = 0;$events = array();
        if ($package_id > 0) {
            $ledger_net = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COALESCE(SUM(amount),0) FROM ' . self::table('credit_ledger') . ' WHERE customer_id=%d AND customer_package_id=%d',
                $customer_id, $package_id
            ));
            $events = $wpdb->get_results($wpdb->prepare(
                'SELECT event_type,COUNT(*) count,SUM(amount) amount FROM ' . self::table('credit_ledger') . ' WHERE customer_id=%d AND customer_package_id=%d GROUP BY event_type',
                $customer_id, $package_id
            ), ARRAY_A);
        }
        $event_map = array();
        foreach ((array) $events as $row) {
            $event_map[(string) $row['event_type']] = array('count' => (int) $row['count'], 'amount' => (int) $row['amount']);
        }

        $plans_error_audit = null;
        $audit_row = $wpdb->get_row($wpdb->prepare(
            "SELECT action,after_payload,created_at FROM " . self::table('audit_log') . " WHERE customer_id=%d AND action='PLANS_RESOLUTION_FAILED' ORDER BY id DESC LIMIT 1",
            $customer_id
        ), ARRAY_A);
        if ($audit_row) {
            $decoded = !empty($audit_row['after_payload']) ? json_decode((string) $audit_row['after_payload'], true) : null;
            $plans_error_audit = array(
                'action' => (string) $audit_row['action'],
                'after' => is_array($decoded) ? $decoded : $audit_row['after_payload'],
                'created_at' => (string) $audit_row['created_at'],
            );
        }

        $plans_probe = array('ok' => false, 'has_compatible_package' => null, 'plan_count' => 0, 'error' => null);
        try {
            $session = $wpdb->get_row($wpdb->prepare(
                'SELECT * FROM ' . self::table('sessions') . ' WHERE id=%d AND status=\'OPEN\' LIMIT 1',
                $session_id
            ), ARRAY_A);
            if (!$session) {
                throw new RuntimeException('Sessione E2E non disponibile per il probe carnet.');
            }
            $existing_package = YNS_Entitlement_Service::find_package($customer_id, $session, false);
            $available_plans = YNS_Entitlement_Service::available_plans_for_session($session_id, $customer_id);
            $plans_probe = array(
                'ok' => true,
                'has_compatible_package' => $existing_package !== null,
                'customer_package_id' => $existing_package ? (int) $existing_package : null,
                'plan_count' => count((array) $available_plans),
                'plan_ids' => array_values(array_map(static function($p){ return (int)($p['id'] ?? 0); }, (array) $available_plans)),
            );
        } catch (Throwable $e) {
            $plans_probe = array(
                'ok' => false,
                'has_compatible_package' => null,
                'plan_count' => 0,
                'error' => get_class($e) . ': ' . $e->getMessage(),
            );
        }

        return rest_ensure_response(array(
            'ok' => true,
            'fixture_id' => (string) $fixture['fixture_id'],
            'customer_exists' => $customer_exists === 1,
            'customer_id' => $customer_id,
            'package_mode' => (string) ($fixture['package_mode'] ?? 'assigned'),
            'package_id' => $package_id,
            'package' => $package ?: null,
            'plans_probe' => $plans_probe,
            'plans_error_audit' => $plans_error_audit,
            'session_id' => $session_id,
            'session_starts_at' => (string) $fixture['session_starts_at'],
            'reservation' => $reservation ?: null,
            'ledger_net' => $ledger_net,
            'ledger_events' => $event_map,
        ));
    }

    private static function delete_query($sql, $label, &$deleted) {
        global $wpdb;
        $result = $wpdb->query($sql);
        if ($result === false) { throw new RuntimeException('Teardown fallito: ' . $label); }
        $deleted[$label] = (int) $result;
    }

    public static function teardown_fixture(WP_REST_Request $request) {
        $auth = self::authorize();
        if (is_wp_error($auth)) { return $auth; }
        $body = self::body($request);
        $fixture_id = self::fixture_id($body['fixture_id'] ?? '');
        if ($fixture_id === '' || ($body['confirm'] ?? '') !== 'TEARDOWN:' . $fixture_id) {
            return new WP_Error('mr_yns_teardown_confirm', 'Conferma teardown non valida.', array('status' => 400));
        }
        $fixture = self::load_fixture($fixture_id);
        if (is_wp_error($fixture)) { return $fixture; }

        global $wpdb;
        $customer_id = (int) $fixture['customer_id'];
        $deleted = array();
        $wpdb->query('START TRANSACTION');
        try {
            $orders = self::table('orders');
            $payments = self::table('payments');
            self::delete_query($wpdb->prepare(
                "DELETE p FROM {$payments} p INNER JOIN {$orders} o ON o.id=p.order_id WHERE o.customer_id=%d",
                $customer_id
            ), 'payments', $deleted);
            self::delete_query($wpdb->prepare('DELETE FROM ' . self::table('notifications') . ' WHERE customer_id=%d', $customer_id), 'notifications', $deleted);
            self::delete_query($wpdb->prepare('DELETE FROM ' . self::table('secretary_alerts') . ' WHERE customer_id=%d', $customer_id), 'secretary_alerts', $deleted);
            self::delete_query($wpdb->prepare('DELETE FROM ' . self::table('push_subscriptions') . ' WHERE customer_id=%d', $customer_id), 'push_subscriptions', $deleted);
            self::delete_query($wpdb->prepare('DELETE FROM ' . self::table('resource_access') . ' WHERE customer_id=%d', $customer_id), 'resource_access', $deleted);
            self::delete_query($wpdb->prepare('DELETE FROM ' . self::table('device_tokens') . ' WHERE customer_id=%d', $customer_id), 'device_tokens', $deleted);
            self::delete_query($wpdb->prepare('DELETE FROM ' . self::table('auth_challenges') . ' WHERE customer_id=%d', $customer_id), 'auth_challenges', $deleted);
            self::delete_query($wpdb->prepare('DELETE FROM ' . self::table('credit_ledger') . ' WHERE customer_id=%d', $customer_id), 'credit_ledger', $deleted);
            self::delete_query($wpdb->prepare('DELETE FROM ' . self::table('reservations') . ' WHERE customer_id=%d', $customer_id), 'reservations', $deleted);
            self::delete_query($wpdb->prepare('DELETE FROM ' . self::table('customer_packages') . ' WHERE customer_id=%d', $customer_id), 'customer_packages', $deleted);
            self::delete_query($wpdb->prepare('DELETE FROM ' . self::table('compliance') . ' WHERE customer_id=%d', $customer_id), 'compliance', $deleted);
            self::delete_query($wpdb->prepare("DELETE FROM {$orders} WHERE customer_id=%d", $customer_id), 'orders', $deleted);
            self::delete_query($wpdb->prepare(
                'DELETE FROM ' . self::table('customers') . ' WHERE id=%d AND email LIKE %s',
                $customer_id, 'ynstest-phase0-%@example.com'
            ), 'customers', $deleted);

            $remaining = array(
                'customers' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('customers') . ' WHERE id=%d', $customer_id)),
                'compliance' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('compliance') . ' WHERE customer_id=%d', $customer_id)),
                'packages' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('customer_packages') . ' WHERE customer_id=%d', $customer_id)),
                'reservations' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('reservations') . ' WHERE customer_id=%d', $customer_id)),
                'ledger' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('credit_ledger') . ' WHERE customer_id=%d', $customer_id)),
                'auth_challenges' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('auth_challenges') . ' WHERE customer_id=%d', $customer_id)),
                'device_tokens' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('device_tokens') . ' WHERE customer_id=%d', $customer_id)),
                'notifications' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . self::table('notifications') . ' WHERE customer_id=%d', $customer_id)),
                'orders' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$orders} WHERE customer_id=%d", $customer_id)),
            );
            foreach ($remaining as $count) {
                if ((int) $count !== 0) { throw new RuntimeException('Teardown incompleto: rimangono righe operative.'); }
            }
            $wpdb->query('COMMIT');

            $store = self::store();
            unset($store[$fixture_id]);
            self::save_store($store);
            MR_Bridge::log('yns_e2e_fixture_torn_down', array(
                'fixture_id' => $fixture_id,
                'customer_id' => $customer_id,
                'deleted' => $deleted,
                'audit_rows_retained' => true,
            ));
            return rest_ensure_response(array(
                'ok' => true,
                'fixture_id' => $fixture_id,
                'deleted' => $deleted,
                'remaining' => $remaining,
                'audit_rows_retained' => true,
            ));
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('mr_yns_teardown', $e->getMessage(), array('status' => 500));
        }
    }
}
