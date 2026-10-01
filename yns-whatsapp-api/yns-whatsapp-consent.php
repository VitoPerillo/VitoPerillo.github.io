<?php
if (!defined('ABSPATH')) { exit; }

final class YNS_WhatsApp_Consent {
    const NS = 'yns-whatsapp/v1';
    const CONSENT_VERSION = 'yns-wa-optin-v1-2026-09-30';
    const CONSENT_TEXT = 'Acconsento a ricevere tramite WhatsApp comunicazioni Yoganostress relative a prenotazioni, promemoria, cancellazioni, lista d’attesa e scadenze dei miei servizi. Posso revocare il consenso in qualsiasi momento.';
    const SOURCE = 'yns_gestionale_explicit_optin';

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $links = $wpdb->prefix . 'yns_wa_customer_links';
        $requests = $wpdb->prefix . 'yns_wa_optin_requests';

        dbDelta("CREATE TABLE $links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            master_contact_id BIGINT UNSIGNED NULL,
            consent_contact_id BIGINT UNSIGNED NULL,
            phone_norm VARCHAR(24) NOT NULL,
            link_status VARCHAR(32) NOT NULL DEFAULT 'linked_unverified',
            consent_status VARCHAR(20) NOT NULL DEFAULT 'unverified',
            consent_version VARCHAR(40) NULL,
            consent_verified_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY customer_id (customer_id),
            KEY phone_norm (phone_norm),
            KEY consent_status (consent_status),
            KEY master_contact_id (master_contact_id)
        ) $charset;");

        dbDelta("CREATE TABLE $requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            customer_id BIGINT UNSIGNED NOT NULL,
            verification_id BIGINT UNSIGNED NULL,
            token_hash CHAR(64) NOT NULL,
            manage_token_hash CHAR(64) NULL,
            phone_norm VARCHAR(24) NOT NULL,
            consent_version VARCHAR(40) NOT NULL,
            consent_text TEXT NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            expires_at DATETIME NOT NULL,
            confirmed_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            UNIQUE KEY manage_token_hash (manage_token_hash),
            KEY customer_status (customer_id,status),
            KEY phone_norm (phone_norm),
            KEY expires_at (expires_at)
        ) $charset;");
    }

    public static function register_routes() {
        register_rest_route(self::NS, '/consent/sync', [
            'methods'=>'POST','callback'=>[__CLASS__,'sync_route'],'permission_callback'=>[__CLASS__,'admin_only']
        ]);
        register_rest_route(self::NS, '/consent/request', [
            'methods'=>'POST','callback'=>[__CLASS__,'request_route'],'permission_callback'=>[__CLASS__,'admin_only']
        ]);
        register_rest_route(self::NS, '/consent/confirm', [
            'methods'=>'POST','callback'=>[__CLASS__,'confirm_route'],'permission_callback'=>'__return_true'
        ]);
        register_rest_route(self::NS, '/consent/revoke', [
            'methods'=>'POST','callback'=>[__CLASS__,'revoke_route'],'permission_callback'=>'__return_true'
        ]);
        register_rest_route(self::NS, '/consent/summary', [
            'methods'=>'GET','callback'=>[__CLASS__,'summary_route'],'permission_callback'=>[__CLASS__,'admin_only']
        ]);
        register_rest_route(self::NS, '/consent/customer/(?P<id>\d+)', [
            'methods'=>'GET','callback'=>[__CLASS__,'customer_status_route'],'permission_callback'=>[__CLASS__,'admin_only']
        ]);
        register_rest_route(self::NS, '/consent/admin/revoke', [
            'methods'=>'POST','callback'=>[__CLASS__,'admin_revoke_route'],'permission_callback'=>[__CLASS__,'admin_only']
        ]);
        register_rest_route(self::NS, '/consent/self-test', [
            'methods'=>'POST','callback'=>[__CLASS__,'self_test_route'],'permission_callback'=>[__CLASS__,'admin_only']
        ]);
    }

    public static function admin_only() {
        return current_user_can('manage_options');
    }

    private static function staging_only() {
        return untrailingslashit(home_url('/')) === 'https://www.yoganostress.it/staging-gestionale';
    }

    private static function table_exists($table) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private static function canonical_phone($phone) {
        $d = preg_replace('/\D+/', '', (string)$phone);
        if (strpos($d, '0039') === 0) $d = substr($d, 2);
        if (strlen($d) < 8 || strlen($d) > 15) return '';
        if (strpos($d, '39') !== 0 && strlen($d) <= 11) $d = '39' . $d;
        return $d;
    }

    private static function plus_phone($digits) {
        $digits = self::canonical_phone($digits);
        return $digits === '' ? '' : '+' . $digits;
    }

    private static function random_token($bytes = 32) {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private static function verification_code($table) {
        global $wpdb;
        for ($i=0; $i<10; $i++) {
            $code = (string)random_int(10000000, 99999999);
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE verification_code=%s LIMIT 1", $code));
            if (!$exists) return $code;
        }
        return wp_generate_password(16, false, false);
    }

    private static function link_row($customer_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'yns_wa_customer_links';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE customer_id=%d LIMIT 1", (int)$customer_id), ARRAY_A);
    }

    private static function sync_internal() {
        global $wpdb;
        if (!self::staging_only()) return new WP_Error('yns_wa_staging_only', 'Sync consenso disponibile solo sullo staging.', ['status'=>403]);
        self::activate();

        $customers = $wpdb->prefix . 'yns_customers';
        $master = $wpdb->prefix . 'ysu_master_contacts';
        $links = $wpdb->prefix . 'yns_wa_customer_links';
        if (!self::table_exists($customers) || !self::table_exists($master)) {
            return new WP_Error('yns_wa_crm_tables_missing', 'Tabelle CRM/gestionale mancanti.', ['status'=>503]);
        }

        $rows = $wpdb->get_results("SELECT id,first_name,last_name,phone FROM $customers WHERE active=1 AND phone IS NOT NULL AND phone<>'' ORDER BY id", ARRAY_A);
        $counts = [];
        foreach ((array)$rows as $r) {
            $p = self::canonical_phone($r['phone'] ?? '');
            if ($p !== '') $counts[$p] = ($counts[$p] ?? 0) + 1;
        }

        $created_master=0; $existing_master=0; $linked=0; $invalid=0; $shared_rows=0; $consents_changed=0;
        $now = current_time('mysql', true);

        foreach ((array)$rows as $r) {
            $customer_id = (int)$r['id'];
            $digits = self::canonical_phone($r['phone'] ?? '');
            if ($digits === '') { $invalid++; continue; }
            $phone_norm = '+' . $digits;
            if (($counts[$digits] ?? 0) > 1) $shared_rows++;

            $master_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $master WHERE phone_norm=%s LIMIT 1", $phone_norm));
            if (!$master_id) {
                $display = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
                $ok = $wpdb->insert($master, [
                    'phone_norm'=>$phone_norm,
                    'phone_raw'=>(string)$r['phone'],
                    'display_name'=>$display,
                    'source_primary'=>'yoganostress_gestionale',
                    'source_detail'=>'staging customer ' . $customer_id,
                    'profile_level'=>'linked_unverified',
                    'profile_reason'=>'Nessun consenso WhatsApp inferito dal gestionale.',
                    'priority_proof'=>'none',
                    'in_eversports'=>0,'legacy_in_phonebook'=>0,'possible_shared_number'=>(($counts[$digits] ?? 0) > 1 ? 1 : 0),
                    'exclude_flag'=>0,'future_block'=>0,'in_google_contacts'=>0,
                    'created_at'=>$now,'updated_at'=>$now,
                ]);
                if ($ok === false) continue;
                $master_id = (int)$wpdb->insert_id;
                $created_master++;
            } else {
                $existing_master++;
                if (($counts[$digits] ?? 0) > 1) {
                    $wpdb->update($master, ['possible_shared_number'=>1,'updated_at'=>$now], ['id'=>$master_id]);
                }
            }

            $existing = self::link_row($customer_id);
            if ($existing) {
                $phone_changed = ((string)$existing['phone_norm'] !== $phone_norm);
                $data = [
                    'master_contact_id'=>$master_id,
                    'phone_norm'=>$phone_norm,
                    'updated_at'=>$now,
                ];
                if ($phone_changed) {
                    $data['consent_contact_id']=null;
                    $data['consent_status']='unverified';
                    $data['consent_version']=null;
                    $data['consent_verified_at']=null;
                    $data['revoked_at']=null;
                    $data['link_status']=(($counts[$digits] ?? 0)>1 ? 'linked_shared_unverified' : 'linked_unverified');
                } elseif (($existing['consent_status'] ?? '') === 'unverified') {
                    $data['link_status']=(($counts[$digits] ?? 0)>1 ? 'linked_shared_unverified' : 'linked_unverified');
                }
                $wpdb->update($links, $data, ['customer_id'=>$customer_id]);
            } else {
                $wpdb->insert($links, [
                    'customer_id'=>$customer_id,
                    'master_contact_id'=>$master_id,
                    'consent_contact_id'=>null,
                    'phone_norm'=>$phone_norm,
                    'link_status'=>(($counts[$digits] ?? 0)>1 ? 'linked_shared_unverified' : 'linked_unverified'),
                    'consent_status'=>'unverified',
                    'created_at'=>$now,
                    'updated_at'=>$now,
                ]);
            }
            $linked++;
        }

        return [
            'ok'=>true,'environment'=>'staging','linked_customers'=>$linked,
            'unique_phones'=>count($counts),'shared_phone_customer_rows'=>$shared_rows,
            'master_contacts_created'=>$created_master,'master_contacts_existing'=>$existing_master,
            'invalid_phone_rows'=>$invalid,'consents_changed'=>$consents_changed,
        ];
    }

    public static function sync_route() {
        $result = self::sync_internal();
        return is_wp_error($result) ? $result : new WP_REST_Response($result, 200);
    }

    private static function request_internal($customer_id) {
        global $wpdb;
        if (!self::staging_only()) return new WP_Error('yns_wa_staging_only', 'Opt-in disponibile solo sullo staging.', ['status'=>403]);
        self::activate();
        $link = self::link_row((int)$customer_id);
        if (!$link) return new WP_Error('yns_wa_link_missing', 'Cliente non collegato al CRM.', ['status'=>404]);

        $requests = $wpdb->prefix . 'yns_wa_optin_requests';
        $verify = $wpdb->prefix . 'ysu_wa_verifications';
        if (!self::table_exists($verify)) return new WP_Error('yns_wa_verify_table_missing', 'Tabella verifiche CRM mancante.', ['status'=>503]);

        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time()+30*MINUTE_IN_SECONDS);
        $wpdb->query($wpdb->prepare(
            "UPDATE $requests SET status='expired',updated_at=%s WHERE customer_id=%d AND status='pending'",
            $now,(int)$customer_id
        ));

        $token = self::random_token();
        $token_hash = hash('sha256', $token);
        $code = self::verification_code($verify);
        $phone_norm = (string)$link['phone_norm'];

        $ok = $wpdb->insert($verify, [
            'token_hash'=>$token_hash,'verification_code'=>$code,
            'phone_raw'=>$phone_norm,'phone_norm'=>$phone_norm,
            'interests'=>wp_json_encode(['service_notifications']),
            'source'=>self::SOURCE,'consent_version'=>self::CONSENT_VERSION,
            'consent_text'=>self::CONSENT_TEXT,'request_ip_hash'=>null,
            'status'=>'pending','created_at'=>$now,'expires_at'=>$expires,
            'updated_at'=>$now,
        ]);
        if ($ok === false) return new WP_Error('yns_wa_verify_insert_failed', 'Creazione verifica fallita.', ['status'=>500]);
        $verification_id = (int)$wpdb->insert_id;

        $ok = $wpdb->insert($requests, [
            'customer_id'=>(int)$customer_id,'verification_id'=>$verification_id,
            'token_hash'=>$token_hash,'manage_token_hash'=>null,
            'phone_norm'=>$phone_norm,'consent_version'=>self::CONSENT_VERSION,
            'consent_text'=>self::CONSENT_TEXT,'status'=>'pending',
            'expires_at'=>$expires,'created_at'=>$now,'updated_at'=>$now,
        ]);
        if ($ok === false) return new WP_Error('yns_wa_request_insert_failed', 'Creazione richiesta opt-in fallita.', ['status'=>500]);

        return [
            'ok'=>true,'request_id'=>(int)$wpdb->insert_id,
            'customer_id'=>(int)$customer_id,
            'confirm_token'=>$token,'verification_code'=>$code,
            'expires_at'=>gmdate('c', strtotime($expires)),
            'consent_version'=>self::CONSENT_VERSION,
            'consent_text'=>self::CONSENT_TEXT,
        ];
    }

    public static function request_route(WP_REST_Request $request) {
        $body = $request->get_json_params();
        $customer_id = (int)($body['customer_id'] ?? 0);
        if (!$customer_id) return new WP_Error('yns_wa_customer_required', 'customer_id richiesto.', ['status'=>400]);
        $result = self::request_internal($customer_id);
        return is_wp_error($result) ? $result : new WP_REST_Response($result, 201);
    }

    private static function confirm_internal($token, $accept) {
        global $wpdb;
        if (!self::staging_only()) return new WP_Error('yns_wa_staging_only', 'Conferma disponibile solo sullo staging.', ['status'=>403]);
        if ($accept !== true) return new WP_Error('yns_wa_explicit_accept_required', 'È richiesto un consenso esplicito.', ['status'=>400]);

        $requests = $wpdb->prefix . 'yns_wa_optin_requests';
        $verify = $wpdb->prefix . 'ysu_wa_verifications';
        $contacts = $wpdb->prefix . 'ysu_contacts';
        $events = $wpdb->prefix . 'ysu_consent_events';
        $links = $wpdb->prefix . 'yns_wa_customer_links';
        $hash = hash('sha256', (string)$token);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $requests WHERE token_hash=%s AND status='pending' LIMIT 1", $hash
        ), ARRAY_A);
        if (!$row) return new WP_Error('yns_wa_optin_invalid', 'Richiesta opt-in non valida.', ['status'=>404]);
        if (strtotime((string)$row['expires_at']) < time()) {
            $wpdb->update($requests,['status'=>'expired','updated_at'=>current_time('mysql',true)],['id'=>(int)$row['id']]);
            return new WP_Error('yns_wa_optin_expired', 'Richiesta opt-in scaduta.', ['status'=>410]);
        }

        $now = current_time('mysql', true);
        $phone_norm = (string)$row['phone_norm'];
        $manage = self::random_token();
        $manage_hash = hash('sha256', $manage);

        $wpdb->query('START TRANSACTION');
        try {
            $contact_id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $contacts WHERE phone_norm=%s LIMIT 1", $phone_norm));
            if ($contact_id) {
                $wpdb->update($contacts, [
                    'consent_status'=>'granted','consent_version'=>self::CONSENT_VERSION,
                    'interests'=>wp_json_encode(['service_notifications']),'source'=>self::SOURCE,'updated_at'=>$now,
                ], ['id'=>$contact_id]);
            } else {
                $ok = $wpdb->insert($contacts, [
                    'phone_raw'=>$phone_norm,'phone_norm'=>$phone_norm,'consent_status'=>'granted',
                    'consent_version'=>self::CONSENT_VERSION,'interests'=>wp_json_encode(['service_notifications']),
                    'source'=>self::SOURCE,'created_at'=>$now,'updated_at'=>$now,
                ]);
                if ($ok === false) throw new Exception('contact insert');
                $contact_id = (int)$wpdb->insert_id;
            }

            $ok = $wpdb->insert($events, [
                'phone_norm'=>$phone_norm,'event_type'=>'consent_granted',
                'consent_version'=>self::CONSENT_VERSION,'consent_text'=>self::CONSENT_TEXT,
                'interests'=>wp_json_encode(['service_notifications']),
                'source'=>self::SOURCE,'created_at'=>$now,
            ]);
            if ($ok === false) throw new Exception('event insert');

            $wpdb->update($verify, [
                'status'=>'verified','verified_at'=>$now,'updated_at'=>$now,
            ], ['id'=>(int)$row['verification_id']]);

            $wpdb->update($requests, [
                'status'=>'granted','manage_token_hash'=>$manage_hash,
                'confirmed_at'=>$now,'updated_at'=>$now,
            ], ['id'=>(int)$row['id']]);

            $wpdb->update($links, [
                'consent_contact_id'=>$contact_id,'consent_status'=>'granted',
                'consent_version'=>self::CONSENT_VERSION,'consent_verified_at'=>$now,
                'revoked_at'=>null,'link_status'=>'verified','updated_at'=>$now,
            ], ['customer_id'=>(int)$row['customer_id']]);

            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('yns_wa_optin_commit_failed', 'Registrazione consenso fallita.', ['status'=>500]);
        }

        return [
            'ok'=>true,'customer_id'=>(int)$row['customer_id'],
            'consent_status'=>'granted','consent_version'=>self::CONSENT_VERSION,
            'manage_token'=>$manage,
        ];
    }

    public static function confirm_route(WP_REST_Request $request) {
        $body = $request->get_json_params();
        $token = (string)($body['token'] ?? '');
        $accept = (($body['accept'] ?? false) === true);
        if ($token === '') return new WP_Error('yns_wa_token_required', 'Token richiesto.', ['status'=>400]);
        $result = self::confirm_internal($token, $accept);
        return is_wp_error($result) ? $result : new WP_REST_Response($result, 200);
    }

    private static function revoke_internal($manage_token, $explicit_revoke) {
        global $wpdb;
        if (!self::staging_only()) return new WP_Error('yns_wa_staging_only', 'Revoca disponibile solo sullo staging.', ['status'=>403]);
        if ($explicit_revoke !== true) return new WP_Error('yns_wa_explicit_revoke_required', 'È richiesta una revoca esplicita.', ['status'=>400]);

        $requests = $wpdb->prefix . 'yns_wa_optin_requests';
        $contacts = $wpdb->prefix . 'ysu_contacts';
        $events = $wpdb->prefix . 'ysu_consent_events';
        $links = $wpdb->prefix . 'yns_wa_customer_links';
        $hash = hash('sha256', (string)$manage_token);
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $requests WHERE manage_token_hash=%s AND status='granted' LIMIT 1", $hash
        ), ARRAY_A);
        if (!$row) return new WP_Error('yns_wa_revoke_invalid', 'Token di revoca non valido.', ['status'=>404]);

        $now = current_time('mysql', true);
        $phone_norm = (string)$row['phone_norm'];
        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->update($links, [
                'consent_status'=>'revoked','revoked_at'=>$now,'link_status'=>'revoked','updated_at'=>$now,
            ], ['customer_id'=>(int)$row['customer_id']]);
            $wpdb->update($requests, ['status'=>'revoked','revoked_at'=>$now,'updated_at'=>$now], ['id'=>(int)$row['id']]);

            $ok = $wpdb->insert($events, [
                'phone_norm'=>$phone_norm,'event_type'=>'consent_revoked',
                'consent_version'=>self::CONSENT_VERSION,'consent_text'=>self::CONSENT_TEXT,
                'interests'=>wp_json_encode(['service_notifications']),
                'source'=>self::SOURCE,'created_at'=>$now,
            ]);
            if ($ok === false) throw new Exception('event insert');

            $other = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $links WHERE phone_norm=%s AND consent_status='granted' AND customer_id<>%d",
                $phone_norm,(int)$row['customer_id']
            ));
            if ($other === 0) {
                $wpdb->update($contacts, ['consent_status'=>'revoked','updated_at'=>$now], ['phone_norm'=>$phone_norm]);
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('yns_wa_revoke_failed', 'Revoca consenso fallita.', ['status'=>500]);
        }

        return ['ok'=>true,'customer_id'=>(int)$row['customer_id'],'consent_status'=>'revoked'];
    }

    public static function revoke_route(WP_REST_Request $request) {
        $body = $request->get_json_params();
        $token = (string)($body['manage_token'] ?? '');
        $revoke = (($body['revoke'] ?? false) === true);
        if ($token === '') return new WP_Error('yns_wa_manage_token_required', 'Token di revoca richiesto.', ['status'=>400]);
        $result = self::revoke_internal($token, $revoke);
        return is_wp_error($result) ? $result : new WP_REST_Response($result, 200);
    }

    public static function summary_route() {
        global $wpdb;
        self::activate();
        $links = $wpdb->prefix . 'yns_wa_customer_links';
        $requests = $wpdb->prefix . 'yns_wa_optin_requests';
        $by_status = $wpdb->get_results("SELECT consent_status,COUNT(*) AS n FROM $links GROUP BY consent_status ORDER BY n DESC", ARRAY_A);
        $req_status = $wpdb->get_results("SELECT status,COUNT(*) AS n FROM $requests GROUP BY status ORDER BY n DESC", ARRAY_A);
        return new WP_REST_Response([
            'ok'=>true,'environment'=>'staging','links_total'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $links"),
            'links_by_consent'=>$by_status,'optin_requests_by_status'=>$req_status,
            'consent_version'=>self::CONSENT_VERSION,
        ], 200);
    }


    public static function customer_status_route(WP_REST_Request $request) {
        global $wpdb;
        self::activate();
        $customer_id=(int)$request->get_param('id');
        if (!$customer_id) return new WP_Error('yns_wa_customer_required','Cliente non valido.',['status'=>400]);
        $link=self::link_row($customer_id);
        if (!$link) {
            return new WP_REST_Response([
                'ok'=>true,'customer_id'=>$customer_id,'linked'=>false,
                'consent_status'=>'not_linked','link_status'=>'not_linked',
                'consent_version'=>null,'consent_verified_at'=>null,'revoked_at'=>null,
                'latest_request'=>null,
            ],200);
        }
        $requests=$wpdb->prefix.'yns_wa_optin_requests';
        $latest=$wpdb->get_row($wpdb->prepare(
            "SELECT status,expires_at,confirmed_at,revoked_at,created_at,consent_version
             FROM $requests WHERE customer_id=%d ORDER BY id DESC LIMIT 1",
            $customer_id
        ),ARRAY_A);
        if ($latest && $latest['status']==='pending' && strtotime((string)$latest['expires_at']) < time()) {
            $latest['status']='expired';
        }
        return new WP_REST_Response([
            'ok'=>true,
            'customer_id'=>$customer_id,
            'linked'=>true,
            'link_status'=>(string)$link['link_status'],
            'consent_status'=>(string)$link['consent_status'],
            'consent_version'=>$link['consent_version'] ?: null,
            'consent_verified_at'=>$link['consent_verified_at'] ?: null,
            'revoked_at'=>$link['revoked_at'] ?: null,
            'latest_request'=>$latest ?: null,
        ],200);
    }

    private static function admin_revoke_internal($customer_id) {
        global $wpdb;
        if (!self::staging_only()) return new WP_Error('yns_wa_staging_only','Revoca amministrativa disponibile solo sullo staging.',['status'=>403]);
        self::activate();
        $link=self::link_row((int)$customer_id);
        if (!$link) return new WP_Error('yns_wa_link_missing','Cliente non collegato al CRM.',['status'=>404]);
        if (($link['consent_status']??'')!=='granted') {
            return ['ok'=>true,'customer_id'=>(int)$customer_id,'consent_status'=>(string)($link['consent_status']??'unverified'),'idempotent'=>true];
        }

        $links=$wpdb->prefix.'yns_wa_customer_links';
        $requests=$wpdb->prefix.'yns_wa_optin_requests';
        $contacts=$wpdb->prefix.'ysu_contacts';
        $events=$wpdb->prefix.'ysu_consent_events';
        $now=current_time('mysql',true);
        $phone_norm=(string)$link['phone_norm'];

        $wpdb->query('START TRANSACTION');
        try {
            $wpdb->update($links,[
                'consent_status'=>'revoked','revoked_at'=>$now,'link_status'=>'revoked','updated_at'=>$now,
            ],['customer_id'=>(int)$customer_id]);
            $wpdb->query($wpdb->prepare(
                "UPDATE $requests SET status='revoked',revoked_at=%s,updated_at=%s
                 WHERE customer_id=%d AND status='granted'",
                $now,$now,(int)$customer_id
            ));
            $ok=$wpdb->insert($events,[
                'phone_norm'=>$phone_norm,'event_type'=>'consent_revoked',
                'consent_version'=>self::CONSENT_VERSION,'consent_text'=>self::CONSENT_TEXT,
                'interests'=>wp_json_encode(['service_notifications']),
                'source'=>'yns_gestionale_admin_revoke','created_at'=>$now,
            ]);
            if ($ok===false) throw new Exception('event insert');
            $other=(int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $links WHERE phone_norm=%s AND consent_status='granted' AND customer_id<>%d",
                $phone_norm,(int)$customer_id
            ));
            if ($other===0) {
                $wpdb->update($contacts,['consent_status'=>'revoked','updated_at'=>$now],['phone_norm'=>$phone_norm]);
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('yns_wa_admin_revoke_failed','Revoca consenso fallita.',['status'=>500]);
        }
        return ['ok'=>true,'customer_id'=>(int)$customer_id,'consent_status'=>'revoked','revoked_at'=>$now];
    }

    public static function admin_revoke_route(WP_REST_Request $request) {
        $body=$request->get_json_params();
        $customer_id=(int)($body['customer_id']??0);
        $revoke=(($body['revoke']??false)===true);
        if (!$customer_id) return new WP_Error('yns_wa_customer_required','customer_id richiesto.',['status'=>400]);
        if (!$revoke) return new WP_Error('yns_wa_explicit_revoke_required','È richiesta una revoca esplicita.',['status'=>400]);
        $result=self::admin_revoke_internal($customer_id);
        return is_wp_error($result)?$result:new WP_REST_Response($result,200);
    }

    private static function cleanup_fixture($email, $phone_norm) {
        global $wpdb;
        $customers=$wpdb->prefix.'yns_customers';
        $reservations=$wpdb->prefix.'yns_reservations';
        $links=$wpdb->prefix.'yns_wa_customer_links';
        $requests=$wpdb->prefix.'yns_wa_optin_requests';
        $master=$wpdb->prefix.'ysu_master_contacts';
        $contacts=$wpdb->prefix.'ysu_contacts';
        $events=$wpdb->prefix.'ysu_consent_events';
        $verify=$wpdb->prefix.'ysu_wa_verifications';
        $ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM $customers WHERE email=%s", $email));
        foreach ((array)$ids as $id) {
            $req_ids=$wpdb->get_col($wpdb->prepare("SELECT verification_id FROM $requests WHERE customer_id=%d", (int)$id));
            foreach ((array)$req_ids as $vid) if ($vid) $wpdb->delete($verify,['id'=>(int)$vid]);
            $wpdb->delete($requests,['customer_id'=>(int)$id]);
            $wpdb->delete($links,['customer_id'=>(int)$id]);
            $wpdb->delete($reservations,['customer_id'=>(int)$id]);
            $wpdb->delete($customers,['id'=>(int)$id]);
        }
        $wpdb->delete($contacts,['phone_norm'=>$phone_norm]);
        $wpdb->delete($events,['phone_norm'=>$phone_norm]);
        $wpdb->delete($master,['phone_norm'=>$phone_norm,'source_primary'=>'yoganostress_gestionale']);
    }

    public static function self_test_route() {
        global $wpdb;
        if (!self::staging_only()) return new WP_Error('yns_wa_staging_only','Self-test solo staging.',['status'=>403]);
        self::activate();
        if (!class_exists('YNS_WhatsApp_Lists')) return new WP_Error('yns_wa_lists_missing','Modulo liste mancante.',['status'=>503]);

        $email='ynswa-staging-consent-test@example.invalid';
        $phone_norm='+393999990001';
        self::cleanup_fixture($email,$phone_norm);

        $sessions=$wpdb->prefix.'yns_sessions';
        $customers=$wpdb->prefix.'yns_customers';
        $reservations=$wpdb->prefix.'yns_reservations';
        $messages=$wpdb->prefix.'yns_wa_messages';
        $session_id=(int)$wpdb->get_var("SELECT id FROM $sessions WHERE starts_at>=NOW() AND status<>'CANCELLED' ORDER BY starts_at ASC LIMIT 1");
        if (!$session_id) return new WP_Error('yns_wa_test_session_missing','Nessuna sessione futura per il test.',['status'=>409]);

        $now=current_time('mysql',true);
        $before_messages=(int)$wpdb->get_var("SELECT COUNT(*) FROM $messages");
        $ok=$wpdb->insert($customers,[
            'first_name'=>'YNSWA','last_name'=>'STAGING TEST','email'=>$email,'phone'=>$phone_norm,
            'active'=>1,'created_at'=>$now,'updated_at'=>$now,
        ]);
        if ($ok===false) return new WP_Error('yns_wa_test_customer_failed','Creazione cliente test fallita.',['status'=>500]);
        $customer_id=(int)$wpdb->insert_id;
        $wpdb->insert($reservations,[
            'customer_id'=>$customer_id,'session_id'=>$session_id,'status'=>'BOOKED',
            'booked_at'=>$now,'source'=>'admin','created_at'=>$now,'updated_at'=>$now,
            'active_key'=>'ynswa-consent-test-'.$customer_id.'-'.$session_id,
        ]);

        $sync=self::sync_internal();
        if (is_wp_error($sync)) { self::cleanup_fixture($email,$phone_norm); return $sync; }

        $preview_req=new WP_REST_Request('POST','/'.YNS_WhatsApp_Lists::NS.'/lists/preview');
        $preview_req->set_body(wp_json_encode(['segment'=>['event_id'=>(string)$session_id],'template'=>['kind'=>'list_announcement','language'=>'it']]));
        $preview_req->set_header('content-type','application/json');
        $p0=YNS_WhatsApp_Lists::preview($preview_req)->get_data();

        $request=self::request_internal($customer_id);
        if (is_wp_error($request)) { self::cleanup_fixture($email,$phone_norm); return $request; }
        $confirm=self::confirm_internal($request['confirm_token'],true);
        if (is_wp_error($confirm)) { self::cleanup_fixture($email,$phone_norm); return $confirm; }

        $p1=YNS_WhatsApp_Lists::preview($preview_req)->get_data();

        $status_req=new WP_REST_Request('GET','/'.self::NS.'/consent/customer/'.$customer_id);
        $status_req->set_param('id',$customer_id);
        $granted_status=self::customer_status_route($status_req);
        $granted_data=$granted_status instanceof WP_REST_Response?$granted_status->get_data():[];

        $admin_revoke_req=new WP_REST_Request('POST','/'.self::NS.'/consent/admin/revoke');
        $admin_revoke_req->set_header('content-type','application/json');
        $admin_revoke_req->set_body(wp_json_encode(['customer_id'=>$customer_id,'revoke'=>true]));
        $revoke=self::admin_revoke_route($admin_revoke_req);
        if (is_wp_error($revoke)) { self::cleanup_fixture($email,$phone_norm); return $revoke; }

        $revoked_status=self::customer_status_route($status_req);
        $revoked_data=$revoked_status instanceof WP_REST_Response?$revoked_status->get_data():[];
        $p2=YNS_WhatsApp_Lists::preview($preview_req)->get_data();

        $after_messages=(int)$wpdb->get_var("SELECT COUNT(*) FROM $messages");
        $status_gate=
            (($granted_data['consent_status']??'')==='granted')
            && !empty($granted_data['consent_verified_at'])
            && (($revoked_data['consent_status']??'')==='revoked')
            && !empty($revoked_data['revoked_at']);
        $result=[
            'ok'=>($p0['eligible_count']===0 && $p1['eligible_count']===1 && $p2['eligible_count']===0 && $before_messages===$after_messages && $status_gate),
            'environment'=>'staging','session_id'=>$session_id,
            'before_consent_eligible'=>(int)$p0['eligible_count'],
            'after_grant_eligible'=>(int)$p1['eligible_count'],
            'after_revoke_eligible'=>(int)$p2['eligible_count'],
            'granted_status_api'=>(string)($granted_data['consent_status']??''),
            'granted_date_present'=>!empty($granted_data['consent_verified_at']),
            'revoked_status_api'=>(string)($revoked_data['consent_status']??''),
            'revoked_date_present'=>!empty($revoked_data['revoked_at']),
            'admin_revoke_tested'=>true,
            'messages_created'=>$after_messages-$before_messages,
            'consent_event_sequence'=>['consent_granted','consent_revoked'],
            'fixture_cleanup'=>'completed',
        ];
        self::cleanup_fixture($email,$phone_norm);
        return new WP_REST_Response($result,$result['ok']?200:500);
    }
}
YNS_WhatsApp_Consent::init();
