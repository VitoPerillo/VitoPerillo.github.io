<?php
if (!defined('ABSPATH')) { exit; }

final class MR_Bridge_Deploy_V1 {
    const STAGING_HOME = 'https://www.yoganostress.it/staging-gestionale';
    const PRODUCTION_HOME = 'https://www.yoganostress.it';
    const OIDC_AUD = 'https://www.yoganostress.it/staging-gestionale/mr-bridge';
    const PRODUCTION_OIDC_AUD = 'https://www.yoganostress.it/mr-bridge-self-update';
    const OIDC_REPOSITORY = 'VitoPerillo/yoganostress-wordpress-bridge';
    const OIDC_REPOSITORY_ID = '1390938875';
    const OIDC_OWNER_ID = '317205417';
    const OIDC_REF = 'refs/heads/mr-bridge-stabilization-rc16';
    const OIDC_WORKFLOW_REF = 'VitoPerillo/yoganostress-wordpress-bridge/.github/workflows/mr-bridge-canonical-staging.yml@refs/heads/mr-bridge-stabilization-rc16';
    const PRODUCTION_OIDC_REF = 'refs/heads/main';
    const PRODUCTION_OIDC_WORKFLOW_REF = 'VitoPerillo/yoganostress-wordpress-bridge/.github/workflows/mr-bridge-self-update-production.yml@refs/heads/main';
    const CHUNK_BYTES = 262144;
    const LOCK_TTL = 600;
    const REQUESTS_OPTION = 'mr_bridge_requests_v1';
    const ATTEST_OPTION = 'mr_bridge_staging_attestations_v1';
    const BACKUP_RETENTION_PER_SLUG = 5;
    const TEMP_MAX_AGE = 86400;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/deploy/health', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'health'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/deploy/preflight', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'preflight'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/deploy/auth-check', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'auth_check'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/deploy/upload/start', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'upload_start'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/deploy/upload/chunk', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'upload_chunk'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/deploy/upload/finalize', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'upload_finalize'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/deploy/rollback', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rollback'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(MR_Bridge::REST_NAMESPACE, '/deploy/attestations', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'attestations'),
            'permission_callback' => array('MR_Bridge', 'can_manage'),
        ));
    }

    public static function environment() {
        $home = untrailingslashit(home_url('/'));
        if ($home === self::STAGING_HOME) { return 'staging'; }
        if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'staging') { return 'staging'; }
        return 'production';
    }

    private static function oidc_expected() {
        $env = self::environment();
        if ($env === 'staging') {
            return array(
                'aud' => self::OIDC_AUD,
                'ref' => self::OIDC_REF,
                'workflow_ref' => self::OIDC_WORKFLOW_REF,
            );
        }
        if ($env === 'production') {
            return array(
                'aud' => untrailingslashit(home_url('/')) . '/mr-bridge-self-update',
                'ref' => self::PRODUCTION_OIDC_REF,
                'workflow_ref' => self::PRODUCTION_OIDC_WORKFLOW_REF,
            );
        }
        return null;
    }

    private static function mutation_slug_allowed($slug) {
        $env = self::environment();
        if ($env === 'staging') { return isset(self::plugin_specs()[$slug]); }
        if ($env === 'production') { return in_array($slug, array('yoganostress-bridge','yoganostress-prenotazioni'), true); }
        return false;
    }

    public static function plugin_specs() {
        return array(
            'yns-whatsapp-api' => array(
                'main' => 'yns-whatsapp-api/yns-whatsapp-api.php',
                'name' => 'YNS WhatsApp API',
                'max_bytes' => 2 * 1024 * 1024,
            ),
            'yoganostress-prenotazioni' => array(
                'main' => 'yoganostress-prenotazioni/yoganostress-prenotazioni.php',
                'name' => 'Yoganostress Prenotazioni',
                'max_bytes' => 12 * 1024 * 1024,
            ),
            'yoganostress-bridge' => array(
                'main' => 'yoganostress-bridge/yoganostress-bridge.php',
                'name' => 'MR Bridge',
                'max_bytes' => 2 * 1024 * 1024,
            ),
        );
    }

    public static function health() {
        return rest_ensure_response(array(
            'ok' => in_array(self::environment(), array('staging','production'), true),
            'bridge' => 'mr-bridge',
            'version' => MR_Bridge::VERSION,
            'environment' => self::environment(),
            'auth' => self::environment() === 'staging' ? 'github-oidc-staging' : (self::environment() === 'production' ? 'github-oidc-self-update-only' : 'disabled'),
            'sha256_required' => true,
            'chunk_bytes' => self::CHUNK_BYTES,
            'backup' => true,
            'rollback' => true,
            'locking' => true,
            'idempotency' => true,
        ));
    }

    public static function preflight() {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        $tmp = self::temp_root();
        $bak = self::backup_root();
        $out = array(
            'ok' => true,
            'bridge' => 'mr-bridge',
            'version' => MR_Bridge::VERSION,
            'environment' => self::environment(),
            'staging_only_mutations' => false,
            'production_mutation_scope' => self::environment() === 'production' ? 'mr-bridge-self-update-only' : 'n/a',
            'ziparchive' => class_exists('ZipArchive'),
            'openssl_verify' => function_exists('openssl_verify'),
            'plugin_dir_writable' => is_writable(WP_PLUGIN_DIR),
            'content_dir_writable' => is_writable(WP_CONTENT_DIR),
            'temp_dir_ready' => !is_wp_error($tmp) && is_dir($tmp) && is_writable($tmp),
            'backup_dir_ready' => !is_wp_error($bak) && is_dir($bak) && is_writable($bak),
            'backup_count' => self::count_backup_dirs(),
            'backup_retention_per_slug' => self::BACKUP_RETENTION_PER_SLUG,
            'temp_max_age_seconds' => self::TEMP_MAX_AGE,
            'self_update_allowlisted' => isset(self::plugin_specs()['yoganostress-bridge']),
            'external_zip_host_required' => false,
            'wpvibe_required' => false,
            'remote_desktop_required' => false,
        );
        foreach (array('ziparchive','openssl_verify','plugin_dir_writable','content_dir_writable','temp_dir_ready','backup_dir_ready','self_update_allowlisted') as $k) {
            if (empty($out[$k])) { $out['ok'] = false; }
        }
        return rest_ensure_response($out);
    }

    private static function b64url_decode($value) {
        $value = strtr((string) $value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad) { $value .= str_repeat('=', 4 - $pad); }
        return base64_decode($value, true);
    }

    private static function auth_header() {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return trim((string) $_SERVER['HTTP_AUTHORIZATION']);
        }
        if (function_exists('apache_request_headers')) {
            foreach ((array) apache_request_headers() as $k => $v) {
                if (strtolower((string) $k) === 'authorization') { return trim((string) $v); }
            }
        }
        return '';
    }

    private static function github_jwks() {
        $cached = get_transient('mr_bridge_github_jwks');
        if (is_array($cached) && !empty($cached['keys'])) { return $cached; }
        $res = wp_remote_get('https://token.actions.githubusercontent.com/.well-known/jwks', array(
            'timeout' => 10,
            'redirection' => 2,
            'headers' => array('Accept' => 'application/json'),
        ));
        if (is_wp_error($res)) { return $res; }
        if ((int) wp_remote_retrieve_response_code($res) !== 200) {
            return new WP_Error('mr_oidc_jwks_http', 'JWKS GitHub non disponibile.', array('status' => 503));
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data) || empty($data['keys'])) {
            return new WP_Error('mr_oidc_jwks_invalid', 'JWKS GitHub non valido.', array('status' => 503));
        }
        set_transient('mr_bridge_github_jwks', $data, HOUR_IN_SECONDS);
        return $data;
    }

    private static function verify_oidc() {
        $direct = class_exists('MR_Bridge_Autonomous_V1') ? MR_Bridge_Autonomous_V1::verify_global('deploy:execute') : null;
        if ($direct !== null) { return $direct; }
        $expected = self::oidc_expected();
        if (!$expected) {
            return new WP_Error('mr_unknown_environment', 'Ambiente MR Bridge non riconosciuto.', array('status' => 403));
        }
        $auth = self::auth_header();
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return new WP_Error('mr_oidc_missing', 'Token OIDC mancante.', array('status' => 401));
        }
        $parts = explode('.', trim($m[1]));
        if (count($parts) !== 3) {
            return new WP_Error('mr_oidc_bad_token', 'JWT non valido.', array('status' => 401));
        }
        $header_raw = self::b64url_decode($parts[0]);
        $payload_raw = self::b64url_decode($parts[1]);
        $sig = self::b64url_decode($parts[2]);
        if ($header_raw === false || $payload_raw === false || $sig === false) {
            return new WP_Error('mr_oidc_bad_encoding', 'JWT non decodificabile.', array('status' => 401));
        }
        $header = json_decode($header_raw, true);
        $claims = json_decode($payload_raw, true);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            return new WP_Error('mr_oidc_bad_header', 'Header JWT non valido.', array('status' => 401));
        }
        $jwks = self::github_jwks();
        if (is_wp_error($jwks)) { return $jwks; }
        $cert = null;
        foreach ((array) $jwks['keys'] as $key) {
            if (($key['kid'] ?? '') === $header['kid'] && !empty($key['x5c'][0])) {
                $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($key['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
                break;
            }
        }
        if (!$cert || !function_exists('openssl_verify')) {
            delete_transient('mr_bridge_github_jwks');
            return new WP_Error('mr_oidc_key_missing', 'Chiave GitHub OIDC non disponibile.', array('status' => 401));
        }
        if (openssl_verify($parts[0] . '.' . $parts[1], $sig, $cert, OPENSSL_ALGO_SHA256) !== 1) {
            delete_transient('mr_bridge_github_jwks');
            return new WP_Error('mr_oidc_bad_signature', 'Firma OIDC non valida.', array('status' => 401));
        }
        $now = time();
        $aud = $claims['aud'] ?? '';
        $aud_ok = is_array($aud) ? in_array($expected['aud'], $aud, true) : hash_equals($expected['aud'], (string) $aud);
        $checks = array(
            'iss' => (($claims['iss'] ?? '') === 'https://token.actions.githubusercontent.com'),
            'aud' => $aud_ok,
            'exp' => (!empty($claims['exp']) && (int) $claims['exp'] >= $now - 30),
            'nbf' => (empty($claims['nbf']) || (int) $claims['nbf'] <= $now + 30),
            'iat' => (!empty($claims['iat']) && (int) $claims['iat'] <= $now + 30 && (int) $claims['iat'] >= $now - 900),
            'repository' => (($claims['repository'] ?? '') === self::OIDC_REPOSITORY),
            'repository_id' => ((string) ($claims['repository_id'] ?? '') === self::OIDC_REPOSITORY_ID),
            'repository_owner_id' => ((string) ($claims['repository_owner_id'] ?? '') === self::OIDC_OWNER_ID),
            'ref' => (($claims['ref'] ?? '') === $expected['ref']),
            'workflow_ref' => (($claims['workflow_ref'] ?? '') === $expected['workflow_ref']),
            'event_name' => in_array((string) ($claims['event_name'] ?? ''), array('push', 'workflow_dispatch'), true),
        );
        foreach ($checks as $name => $ok) {
            if (!$ok) {
                return new WP_Error('mr_oidc_claim_' . $name, 'Claim OIDC rifiutato: ' . $name, array('status' => 403));
            }
        }
        return $claims;
    }

    public static function authorize_staging_automation() {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        if (self::environment() !== 'staging') {
            return new WP_Error('mr_staging_only', 'Capability disponibile esclusivamente sullo staging.', array('status' => 403));
        }
        return $claims;
    }

    public static function authorize_production_automation() {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        if (self::environment() !== 'production') {
            return new WP_Error('mr_production_only', 'Capability disponibile esclusivamente in produzione.', array('status' => 403));
        }
        return $claims;
    }

    public static function auth_check() {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        MR_Bridge::log('oidc_auth_check', array(
            'run_id' => (string) ($claims['run_id'] ?? ''),
            'actor_id' => (string) ($claims['actor_id'] ?? ''),
        ));
        return rest_ensure_response(array(
            'ok' => true,
            'environment' => self::environment(),
            'repository' => self::OIDC_REPOSITORY,
            'run_id' => $claims['run_id'] ?? null,
            'actor_id' => $claims['actor_id'] ?? null,
        ));
    }

    private static function request_id($body) {
        $id = isset($body['request_id']) ? (string) $body['request_id'] : '';
        if (!preg_match('/^[A-Za-z0-9._:-]{16,96}$/', $id)) {
            return new WP_Error('mr_request_id_invalid', 'request_id non valido.', array('status' => 400));
        }
        return $id;
    }

    private static function request_store() {
        $data = get_option(self::REQUESTS_OPTION, array());
        return is_array($data) ? $data : array();
    }

    private static function request_get($id) {
        $data = self::request_store();
        return isset($data[$id]) && is_array($data[$id]) ? $data[$id] : null;
    }

    private static function request_put($id, $value) {
        $data = self::request_store();
        $data[$id] = $value;
        if (count($data) > 100) {
            foreach (array_keys($data) as $key) {
                if (count($data) <= 100) { break; }
                $status = is_array($data[$key] ?? null) ? (string) ($data[$key]['status'] ?? '') : '';
                if (in_array($status, array('complete','failed'), true)) { unset($data[$key]); }
            }
        }
        update_option(self::REQUESTS_OPTION, $data, false);
    }

    private static function lock_key($slug) {
        return 'mr_bridge_lock_' . substr(hash('sha256', $slug), 0, 24);
    }

    private static function acquire_lock($slug, $request_id) {
        $key = self::lock_key($slug);
        $current = get_transient($key);
        if (is_array($current) && !empty($current['request_id']) && $current['request_id'] !== $request_id) {
            return new WP_Error('mr_lock_busy', 'Plugin già in lavorazione.', array('status' => 409));
        }
        set_transient($key, array('request_id' => $request_id, 'created_at' => time()), self::LOCK_TTL);
        return true;
    }

    private static function refresh_lock($slug, $request_id) {
        $key = self::lock_key($slug);
        $current = get_transient($key);
        if (is_array($current) && !empty($current['request_id']) && $current['request_id'] !== $request_id) {
            return new WP_Error('mr_lock_lost', 'Lock deploy acquisito da un altro processo.', array('status' => 409));
        }
        set_transient($key, array('request_id' => $request_id, 'created_at' => time()), self::LOCK_TTL);
        return true;
    }

    private static function release_lock($slug, $request_id) {
        $key = self::lock_key($slug);
        $current = get_transient($key);
        if (!is_array($current) || ($current['request_id'] ?? '') === $request_id) { delete_transient($key); }
    }

    private static function temp_root() {
        $root = WP_CONTENT_DIR . '/mr-bridge-tmp';
        if (!is_dir($root) && !wp_mkdir_p($root)) {
            return new WP_Error('mr_tmp_failed', 'Impossibile creare directory temporanea.', array('status' => 500));
        }
        if (!file_exists($root . '/index.php')) { @file_put_contents($root . '/index.php', "<?php\n// Silence is golden.\n"); }
        if (!file_exists($root . '/.htaccess')) { @file_put_contents($root . '/.htaccess', "Deny from all\n"); }
        return $root;
    }

    private static function backup_root() {
        $root = WP_CONTENT_DIR . '/mr-bridge-backups';
        if (!is_dir($root) && !wp_mkdir_p($root)) {
            return new WP_Error('mr_backup_root_failed', 'Impossibile creare directory backup.', array('status' => 500));
        }
        if (!file_exists($root . '/index.php')) { @file_put_contents($root . '/index.php', "<?php\n// Silence is golden.\n"); }
        if (!file_exists($root . '/.htaccess')) { @file_put_contents($root . '/.htaccess', "Deny from all\n"); }
        return $root;
    }

    private static function count_backup_dirs() {
        $root = self::backup_root();
        if (is_wp_error($root) || !is_dir($root)) { return 0; }
        $count = 0;
        foreach ((array) scandir($root) as $name) {
            if ($name === '.' || $name === '..') { continue; }
            if (is_dir($root . '/' . $name) && strpos($name, 'mrbk-') === 0) { $count++; }
        }
        return $count;
    }

    private static function cleanup_stale_temp() {
        $root = self::temp_root();
        if (is_wp_error($root) || !is_dir($root)) { return 0; }
        $removed = 0;
        foreach ((array) scandir($root) as $name) {
            if ($name === '.' || $name === '..' || strpos($name, 'mrup-') !== 0) { continue; }
            $path = $root . '/' . $name;
            if (!is_dir($path)) { continue; }
            $manifest = self::read_json($path . '/manifest.json');
            $last = is_array($manifest) && !empty($manifest['updated_at_unix'])
                ? (int) $manifest['updated_at_unix']
                : (int) (@filemtime($path) ?: 0);
            if ($last && (time() - $last) > self::TEMP_MAX_AGE && self::remove_tree($path)) { $removed++; }
        }
        return $removed;
    }

    private static function prune_backups($slug) {
        $root = self::backup_root();
        if (is_wp_error($root) || !is_dir($root)) { return 0; }
        $items = array();
        foreach ((array) scandir($root) as $name) {
            if ($name === '.' || $name === '..' || strpos($name, 'mrbk-') !== 0) { continue; }
            $dir = $root . '/' . $name;
            if (!is_dir($dir)) { continue; }
            $m = self::read_json($dir . '/manifest.json');
            if (!is_array($m) || ($m['slug'] ?? '') !== $slug) { continue; }
            $items[] = array('dir' => $dir, 'time' => (int) (@filemtime($dir . '/manifest.json') ?: 0));
        }
        usort($items, function($a,$b){ return $b['time'] <=> $a['time']; });
        $removed = 0;
        foreach (array_slice($items, self::BACKUP_RETENTION_PER_SLUG) as $item) {
            if (self::remove_tree($item['dir'])) { $removed++; }
        }
        return $removed;
    }

    private static function remove_tree($dir) {
        if (!is_dir($dir)) { return true; }
        $items = scandir($dir);
        if ($items === false) { return false; }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') { continue; }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                if (!self::remove_tree($path)) { return false; }
            } else {
                if (!@unlink($path)) { return false; }
            }
        }
        return @rmdir($dir);
    }

    private static function write_json($path, $data) {
        return @file_put_contents($path, wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    }

    private static function read_json($path) {
        if (!is_file($path)) { return null; }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private static function upload_dir($upload_id) {
        if (!preg_match('/^mrup-[a-z0-9-]{12,80}$/', (string) $upload_id)) { return null; }
        $root = self::temp_root();
        if (is_wp_error($root)) { return $root; }
        return $root . '/' . $upload_id;
    }

    public static function upload_start(WP_REST_Request $request) {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        $body = $request->get_json_params();
        if (!is_array($body)) { return new WP_Error('mr_bad_json', 'Payload JSON non valido.', array('status' => 400)); }
        self::cleanup_stale_temp();
        $request_id = self::request_id($body);
        if (is_wp_error($request_id)) { return $request_id; }
        $slug = sanitize_key($body['slug'] ?? '');
        $specs = self::plugin_specs();
        if (!isset($specs[$slug]) || !self::mutation_slug_allowed($slug)) {
            return new WP_Error('mr_slug_denied', 'Slug non autorizzato per questo ambiente.', array('status' => 403));
        }
        $sha = strtolower(trim((string) ($body['sha256'] ?? '')));
        $version = sanitize_text_field((string) ($body['version'] ?? ''));
        $total = (int) ($body['total_size'] ?? 0);
        $activate = array_key_exists('activate', $body) ? (bool) $body['activate'] : true;
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) { return new WP_Error('mr_sha_invalid', 'SHA-256 non valido.', array('status' => 400)); }
        if ($version === '' || strlen($version) > 64) { return new WP_Error('mr_version_invalid', 'Versione non valida.', array('status' => 400)); }
        if ($total < 1 || $total > (int) $specs[$slug]['max_bytes']) { return new WP_Error('mr_size_denied', 'Dimensione pacchetto non consentita.', array('status' => 413)); }

        $fingerprint = hash('sha256', wp_json_encode(array($slug, $sha, $version, $total, $activate)));
        $existing = self::request_get($request_id);
        if ($existing) {
            if (($existing['fingerprint'] ?? '') !== $fingerprint) {
                return new WP_Error('mr_idempotency_conflict', 'request_id già usato con payload diverso.', array('status' => 409));
            }
            $existing_upload_id = (string) ($existing['upload_id'] ?? '');
            $resume = array('received'=>0,'next_index'=>0,'upload_status'=>(string)($existing['status']??''));
            $existing_dir = self::upload_dir($existing_upload_id);
            if ($existing_dir && !is_wp_error($existing_dir)) {
                $existing_manifest = self::read_json($existing_dir . '/manifest.json');
                if (is_array($existing_manifest)) {
                    $resume['received'] = (int) ($existing_manifest['received'] ?? 0);
                    $resume['next_index'] = (int) ($existing_manifest['next_index'] ?? 0);
                    $resume['upload_status'] = (string) ($existing_manifest['status'] ?? $resume['upload_status']);
                }
            }
            return rest_ensure_response(array(
                'ok' => true,
                'idempotent' => true,
                'upload_id' => $existing_upload_id,
                'chunk_bytes' => self::CHUNK_BYTES,
                'received' => $resume['received'],
                'next_index' => $resume['next_index'],
                'upload_status' => $resume['upload_status'],
                'request' => $existing,
            ));
        }
        $lock = self::acquire_lock($slug, $request_id);
        if (is_wp_error($lock)) { return $lock; }

        $upload_id = 'mrup-' . gmdate('YmdHis') . '-' . strtolower(wp_generate_password(10, false, false));
        $dir = self::upload_dir($upload_id);
        if (is_wp_error($dir)) { self::release_lock($slug, $request_id); return $dir; }
        if (!wp_mkdir_p($dir)) { self::release_lock($slug, $request_id); return new WP_Error('mr_upload_dir_failed', 'Directory upload non creata.', array('status' => 500)); }
        $manifest = array(
            'schema' => 1,
            'upload_id' => $upload_id,
            'request_id' => $request_id,
            'slug' => $slug,
            'version' => $version,
            'sha256' => $sha,
            'total_size' => $total,
            'received' => 0,
            'next_index' => 0,
            'last_chunk_index' => -1,
            'last_chunk_sha256' => '',
            'activate' => $activate,
            'status' => 'uploading',
            'created_at' => current_time('mysql', true),
            'updated_at_unix' => time(),
            'run_id' => (string) ($claims['run_id'] ?? ''),
            'actor_id' => (string) ($claims['actor_id'] ?? ''),
        );
        if (!self::write_json($dir . '/manifest.json', $manifest)) {
            self::release_lock($slug, $request_id);
            self::remove_tree($dir);
            return new WP_Error('mr_manifest_failed', 'Manifest upload non scritto.', array('status' => 500));
        }
        @file_put_contents($dir . '/package.zip', '');
        self::request_put($request_id, array('status' => 'uploading', 'fingerprint' => $fingerprint, 'upload_id' => $upload_id, 'slug' => $slug, 'version' => $version));
        MR_Bridge::log('deploy_upload_started', array('slug' => $slug, 'version' => $version, 'sha256' => $sha, 'request_id' => $request_id, 'run_id' => $manifest['run_id']));
        return rest_ensure_response(array('ok' => true, 'upload_id' => $upload_id, 'chunk_bytes' => self::CHUNK_BYTES));
    }

    public static function upload_chunk(WP_REST_Request $request) {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        $body = $request->get_json_params();
        if (!is_array($body)) { return new WP_Error('mr_bad_json', 'Payload JSON non valido.', array('status' => 400)); }
        $upload_id = sanitize_text_field((string) ($body['upload_id'] ?? ''));
        $dir = self::upload_dir($upload_id);
        if ($dir === null || is_wp_error($dir)) { return new WP_Error('mr_upload_id_invalid', 'upload_id non valido.', array('status' => 400)); }
        $manifest = self::read_json($dir . '/manifest.json');
        if (!$manifest || ($manifest['status'] ?? '') !== 'uploading') { return new WP_Error('mr_upload_missing', 'Upload non disponibile.', array('status' => 404)); }
        if (!self::mutation_slug_allowed(sanitize_key((string) ($manifest['slug'] ?? '')))) {
            return new WP_Error('mr_slug_denied', 'Slug non autorizzato per questo ambiente.', array('status' => 403));
        }
        $index = (int) ($body['index'] ?? -1);
        $chunk = base64_decode((string) ($body['data_base64'] ?? ''), true);
        if ($chunk === false || strlen($chunk) < 1 || strlen($chunk) > self::CHUNK_BYTES) { return new WP_Error('mr_chunk_invalid', 'Chunk non valido.', array('status' => 400)); }
        $chunk_sha = strtolower((string) ($body['chunk_sha256'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $chunk_sha) || !hash_equals($chunk_sha, hash('sha256', $chunk))) {
            return new WP_Error('mr_chunk_sha', 'SHA chunk non valido.', array('status' => 409));
        }
        if ($index === (int) ($manifest['last_chunk_index'] ?? -2) && !empty($manifest['last_chunk_sha256']) && hash_equals((string) $manifest['last_chunk_sha256'], $chunk_sha)) {
            return rest_ensure_response(array(
                'ok' => true, 'idempotent' => true, 'upload_id' => $upload_id,
                'received' => (int) $manifest['received'], 'next_index' => (int) $manifest['next_index'],
            ));
        }
        if ($index !== (int) $manifest['next_index']) { return new WP_Error('mr_chunk_order', 'Indice chunk inatteso.', array('status' => 409)); }
        $lock = self::refresh_lock((string) $manifest['slug'], (string) $manifest['request_id']);
        if (is_wp_error($lock)) { return $lock; }
        if ((int) $manifest['received'] + strlen($chunk) > (int) $manifest['total_size']) {
            return new WP_Error('mr_chunk_overflow', 'Upload oltre la dimensione dichiarata.', array('status' => 409));
        }
        $path = $dir . '/package.zip';
        $fh = @fopen($path, 'c+b');
        if (!$fh || !flock($fh, LOCK_EX)) {
            if (is_resource($fh)) { fclose($fh); }
            return new WP_Error('mr_chunk_write', 'File upload non bloccabile.', array('status' => 500));
        }
        $offset = (int) $manifest['received'];
        $ok = (fseek($fh, $offset) === 0) && (fwrite($fh, $chunk) === strlen($chunk));
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        if (!$ok) { return new WP_Error('mr_chunk_write', 'Scrittura chunk fallita.', array('status' => 500)); }
        $manifest['received'] = $offset + strlen($chunk);
        $manifest['next_index'] = $index + 1;
        $manifest['last_chunk_index'] = $index;
        $manifest['last_chunk_sha256'] = $chunk_sha;
        $manifest['updated_at_unix'] = time();
        if (!self::write_json($dir . '/manifest.json', $manifest)) {
            return new WP_Error('mr_manifest_failed', 'Aggiornamento manifest upload fallito.', array('status' => 500));
        }
        return rest_ensure_response(array('ok' => true, 'upload_id' => $upload_id, 'received' => $manifest['received'], 'next_index' => $manifest['next_index']));
    }

    private static function validate_zip($zip_path, $slug, $spec) {
        if (!class_exists('ZipArchive')) { return new WP_Error('mr_zip_missing', 'ZipArchive non disponibile.', array('status' => 500)); }
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) { return new WP_Error('mr_zip_open', 'ZIP non valido.', array('status' => 409)); }
        if ($zip->numFiles < 1 || $zip->numFiles > 3000) { $zip->close(); return new WP_Error('mr_zip_entries', 'Numero file ZIP non consentito.', array('status' => 409)); }
        $main_found = false;
        $uncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            $name = isset($st['name']) ? (string) $st['name'] : '';
            $size = isset($st['size']) ? (int) $st['size'] : 0;
            $uncompressed += max(0, $size);
            if ($uncompressed > max(20 * 1024 * 1024, ((int) $spec['max_bytes']) * 8)) { $zip->close(); return new WP_Error('mr_zip_bomb', 'ZIP espanso troppo grande.', array('status' => 409)); }
            $opsys = 0; $attr = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                $mode = (($attr >> 16) & 0xF000);
                if ($mode === 0xA000) {
                    $zip->close();
                    return new WP_Error('mr_zip_symlink', 'Symlink ZIP non consentito.', array('status' => 409));
                }
            }
            if ($name === '' || strpos($name, "\0") !== false || strpos($name, '../') !== false || strpos($name, '..\\') !== false || $name[0] === '/' || strpos($name, $slug . '/') !== 0) {
                $zip->close();
                return new WP_Error('mr_zip_path', 'Percorso ZIP non autorizzato.', array('status' => 409));
            }
            if ($name === (string) $spec['main']) { $main_found = true; }
        }
        if (!$main_found) { $zip->close(); return new WP_Error('mr_main_missing', 'Main file plugin assente.', array('status' => 409)); }
        return $zip;
    }

    private static function read_backup_manifest($backup_id) {
        if (!preg_match('/^mrbk-[a-z0-9-]{12,100}$/', (string) $backup_id)) {
            return new WP_Error('mr_backup_id_invalid', 'Backup ID non valido.', array('status' => 400));
        }
        $root = self::backup_root();
        if (is_wp_error($root)) { return $root; }
        $dir = $root . '/' . $backup_id;
        $data = self::read_json($dir . '/manifest.json');
        if (!$data) { return new WP_Error('mr_backup_missing', 'Backup non trovato.', array('status' => 404)); }
        return array($dir, $data);
    }

    private static function invalidate_runtime_cache($dir) {
        clearstatcache(true);
        if (function_exists('wp_clean_plugins_cache')) { wp_clean_plugins_cache(true); }
        if (function_exists('opcache_invalidate') && is_dir($dir)) {
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($it as $file) {
                    if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                        @opcache_invalidate($file->getPathname(), true);
                    }
                }
            } catch (Throwable $e) {
                MR_Bridge::log('runtime_cache_invalidate_warning', array('dir'=>basename((string)$dir)));
            }
        }
        clearstatcache(true, $dir);
    }

    private static function rollback_internal($backup_id, $automatic = false) {
        if (!function_exists('activate_plugin') || !function_exists('is_plugin_active')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $loaded = self::read_backup_manifest($backup_id);
        if (is_wp_error($loaded)) { return $loaded; }
        list($dir, $m) = $loaded;
        $slug = sanitize_key((string) ($m['slug'] ?? ''));
        if (($m['status'] ?? '') === 'rolled_back') {
            return array('ok' => true, 'slug' => $slug, 'backup_id' => $backup_id, 'rolled_back' => true, 'idempotent' => true);
        }
        $specs = self::plugin_specs();
        if (!isset($specs[$slug])) { return new WP_Error('mr_backup_slug_denied', 'Backup fuori allowlist.', array('status' => 403)); }
        $target = WP_PLUGIN_DIR . '/' . $slug;
        $main = (string) $specs[$slug]['main'];
        if (is_plugin_active($main)) { deactivate_plugins($main, true, false); }
        if (is_dir($target) && !self::remove_tree($target)) { return new WP_Error('mr_rollback_remove', 'Rimozione versione corrente fallita.', array('status' => 500)); }
        if (!empty($m['existed'])) {
            if (!is_dir($dir . '/plugin') || !@rename($dir . '/plugin', $target)) { return new WP_Error('mr_rollback_restore', 'Ripristino backup fallito.', array('status' => 500)); }
            self::invalidate_runtime_cache($target);
            if (!empty($m['was_active'])) {
                $act = activate_plugin($main, '', false, true);
                if (is_wp_error($act)) { return $act; }
            }
        }
        $m['status'] = 'rolled_back';
        $m['rolled_back_at'] = current_time('mysql', true);
        $m['automatic'] = (bool) $automatic;
        self::write_json($dir . '/manifest.json', $m);
        MR_Bridge::log('plugin_rollback', array('slug' => $slug, 'backup_id' => $backup_id, 'automatic' => (bool) $automatic, 'request_id' => (string) ($m['request_id'] ?? '')));
        return array('ok' => true, 'slug' => $slug, 'backup_id' => $backup_id, 'rolled_back' => true);
    }

    private static function tree_sha256($dir) {
        $base = realpath($dir);
        if (!$base) { return ''; }
        $files = array();
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && !$file->isLink()) { $files[] = $file->getPathname(); }
        }
        sort($files, SORT_STRING);
        $ctx = hash_init('sha256');
        foreach ($files as $path) {
            $rel = str_replace('\\', '/', substr($path, strlen($base) + 1));
            $data = (string) file_get_contents($path);
            hash_update($ctx, $rel . "\0" . strlen($data) . "\0" . $data);
        }
        return hash_final($ctx);
    }

    private static function deploy_from_upload($dir, $m) {
        if (!function_exists('activate_plugin') || !function_exists('get_plugins') || !function_exists('get_plugin_data')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $slug = (string) $m['slug'];
        $specs = self::plugin_specs();
        $spec = $specs[$slug];
        $zip_path = $dir . '/package.zip';
        if ((int) $m['received'] !== (int) $m['total_size'] || filesize($zip_path) !== (int) $m['total_size']) {
            return new WP_Error('mr_upload_incomplete', 'Upload incompleto.', array('status' => 409));
        }
        $actual_sha = hash_file('sha256', $zip_path);
        if (!hash_equals((string) $m['sha256'], $actual_sha)) {
            return new WP_Error('mr_package_sha', 'SHA-256 pacchetto non corrispondente.', array('status' => 409));
        }
        $zip = self::validate_zip($zip_path, $slug, $spec);
        if (is_wp_error($zip)) { return $zip; }
        $extract = $dir . '/extract';
        if (!wp_mkdir_p($extract) || !$zip->extractTo($extract)) { $zip->close(); return new WP_Error('mr_extract', 'Estrazione ZIP fallita.', array('status' => 500)); }
        $zip->close();
        $new_root = $extract . '/' . $slug;
        $main_new = $new_root . '/' . basename((string) $spec['main']);
        if (!is_file($main_new)) { return new WP_Error('mr_extracted_main', 'Main file estratto assente.', array('status' => 409)); }
        $data = get_plugin_data($main_new, false, false);
        if (trim((string) ($data['Name'] ?? '')) !== (string) $spec['name']) { return new WP_Error('mr_plugin_name', 'Plugin Name inatteso.', array('status' => 409)); }
        if (trim((string) ($data['Version'] ?? '')) !== (string) $m['version']) { return new WP_Error('mr_plugin_version', 'Versione plugin inattesa.', array('status' => 409)); }

        $backup_root = self::backup_root();
        if (is_wp_error($backup_root)) { return $backup_root; }
        $backup_id = 'mrbk-' . gmdate('YmdHis') . '-' . strtolower(wp_generate_password(8, false, false));
        $backup_dir = $backup_root . '/' . $backup_id;
        if (!wp_mkdir_p($backup_dir)) { return new WP_Error('mr_backup_create', 'Creazione backup fallita.', array('status' => 500)); }
        $target = WP_PLUGIN_DIR . '/' . $slug;
        $main = (string) $spec['main'];
        $existed = is_dir($target);
        $was_active = is_plugin_active($main);
        $manifest = array(
            'schema' => 1,
            'backup_id' => $backup_id,
            'slug' => $slug,
            'created_at' => current_time('mysql', true),
            'request_id' => (string) $m['request_id'],
            'existed' => $existed,
            'was_active' => $was_active,
            'incoming_version' => (string) $m['version'],
            'incoming_sha256' => $actual_sha,
            'status' => 'prepared',
        );
        if (!self::write_json($backup_dir . '/manifest.json', $manifest)) { return new WP_Error('mr_backup_manifest', 'Manifest backup fallito.', array('status' => 500)); }

        if ($existed) {
            if ($was_active) { deactivate_plugins($main, true, false); }
            if (!@rename($target, $backup_dir . '/plugin')) { return new WP_Error('mr_backup_move', 'Backup plugin esistente fallito.', array('status' => 500)); }
        }
        if (!@rename($new_root, $target)) {
            if ($existed && is_dir($backup_dir . '/plugin')) { @rename($backup_dir . '/plugin', $target); }
            return new WP_Error('mr_atomic_swap', 'Installazione atomica fallita.', array('status' => 500));
        }
        self::remove_tree($extract);

        if (!empty($m['activate'])) {
            $act = activate_plugin($main, '', false, true);
            if (is_wp_error($act)) {
                self::rollback_internal($backup_id, true);
                return new WP_Error('mr_activate', 'Attivazione fallita; rollback automatico eseguito.', array('status' => 500));
            }
        }

        self::invalidate_runtime_cache($target);
        $installed = get_plugins('/' . $slug);
        $rel = substr($main, strlen($slug) + 1);
        $seen = $installed[$rel] ?? null;
        $active_now = is_plugin_active($main);
        if (!is_array($seen) || trim((string) ($seen['Version'] ?? '')) !== (string) $m['version'] || (!empty($m['activate']) && !$active_now)) {
            self::rollback_internal($backup_id, true);
            return new WP_Error('mr_verify_failed', 'Verify post-deploy fallito; rollback automatico eseguito.', array('status' => 500));
        }

        if (self::environment() === 'production' && $slug === 'yoganostress-bridge') {
            $probe_url = add_query_arg('mr_self_update_probe', rawurlencode((string) $m['request_id']), rest_url(MR_Bridge::REST_NAMESPACE . '/status'));
            $probe = wp_remote_get($probe_url, array(
                'timeout' => 20,
                'redirection' => 2,
                'sslverify' => true,
                'headers' => array('Cache-Control' => 'no-cache', 'Pragma' => 'no-cache'),
                'user-agent' => 'MR-Bridge-Self-Update/' . (string) $m['version'],
            ));
            $probe_ok = !is_wp_error($probe) && (int) wp_remote_retrieve_response_code($probe) === 200;
            if ($probe_ok) {
                $probe_data = json_decode((string) wp_remote_retrieve_body($probe), true);
                $probe_ok = is_array($probe_data)
                    && !empty($probe_data['ok'])
                    && (string) ($probe_data['version'] ?? '') === (string) $m['version'];
            }
            if (!$probe_ok) {
                self::rollback_internal($backup_id, true);
                return new WP_Error('mr_self_update_probe_failed', 'La nuova versione di MR Bridge non ha superato il controllo live; rollback automatico eseguito.', array('status' => 500));
            }
        }

        $tree = self::tree_sha256($target);
        $manifest['status'] = 'deployed';
        $manifest['deployed_at'] = current_time('mysql', true);
        $manifest['tree_sha256'] = $tree;
        self::write_json($backup_dir . '/manifest.json', $manifest);

        $attest = get_option(self::ATTEST_OPTION, array());
        if (!is_array($attest)) { $attest = array(); }
        $key = $slug . ':' . $actual_sha;
        $attest[$key] = array(
            'environment' => self::environment(),
            'slug' => $slug,
            'version' => (string) $m['version'],
            'sha256' => $actual_sha,
            'tree_sha256' => $tree,
            'verified_at' => current_time('mysql', true),
            'request_id' => (string) $m['request_id'],
            'run_id' => (string) ($m['run_id'] ?? ''),
        );
        if (count($attest) > 100) { $attest = array_slice($attest, -100, null, true); }
        update_option(self::ATTEST_OPTION, $attest, false);

        $pruned_backups = self::prune_backups($slug);

        MR_Bridge::log('plugin_deploy_success', array(
            'slug' => $slug,
            'version' => (string) $m['version'],
            'sha256' => $actual_sha,
            'tree_sha256' => $tree,
            'backup_id' => $backup_id,
            'request_id' => (string) $m['request_id'],
        ));

        return array(
            'ok' => true,
            'slug' => $slug,
            'version' => (string) $m['version'],
            'sha256' => $actual_sha,
            'tree_sha256' => $tree,
            'backup_id' => $backup_id,
            'active' => $active_now,
            'attested' => true,
            'environment' => self::environment(),
            'pruned_backups' => $pruned_backups,
        );
    }

    public static function upload_finalize(WP_REST_Request $request) {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        $body = $request->get_json_params();
        if (!is_array($body)) { return new WP_Error('mr_bad_json', 'Payload JSON non valido.', array('status' => 400)); }
        $upload_id = sanitize_text_field((string) ($body['upload_id'] ?? ''));
        $dir = self::upload_dir($upload_id);
        if ($dir === null || is_wp_error($dir)) { return new WP_Error('mr_upload_id_invalid', 'upload_id non valido.', array('status' => 400)); }
        $m = self::read_json($dir . '/manifest.json');
        if (!$m) { return new WP_Error('mr_upload_missing', 'Upload non disponibile.', array('status' => 404)); }
        if (!self::mutation_slug_allowed(sanitize_key((string) ($m['slug'] ?? '')))) {
            return new WP_Error('mr_slug_denied', 'Slug non autorizzato per questo ambiente.', array('status' => 403));
        }
        if (($body['confirm'] ?? '') !== 'DEPLOY:' . $m['slug'] . ':' . $m['sha256']) { return new WP_Error('mr_confirm', 'Conferma deploy non valida.', array('status' => 400)); }
        if (($m['status'] ?? '') === 'complete' && is_array($m['result'] ?? null)) {
            return rest_ensure_response(array('ok' => true, 'idempotent' => true, 'result' => $m['result'], 'run_id' => $claims['run_id'] ?? null));
        }
        if (($m['status'] ?? '') !== 'uploading') { return new WP_Error('mr_upload_missing', 'Upload non disponibile.', array('status' => 404)); }
        $lock = self::refresh_lock((string) $m['slug'], (string) $m['request_id']);
        if (is_wp_error($lock)) { return $lock; }

        $result = self::deploy_from_upload($dir, $m);
        if (is_wp_error($result)) {
            self::release_lock((string) $m['slug'], (string) $m['request_id']);
            self::request_put((string) $m['request_id'], array('status' => 'failed', 'slug' => (string) $m['slug'], 'version' => (string) $m['version'], 'error' => $result->get_error_code()));
            MR_Bridge::log('plugin_deploy_failed', array('slug' => (string) $m['slug'], 'request_id' => (string) $m['request_id'], 'error' => $result->get_error_code()));
            return $result;
        }
        $m['status'] = 'complete';
        $m['result'] = $result;
        self::write_json($dir . '/manifest.json', $m);
        self::request_put((string) $m['request_id'], array('status' => 'complete', 'slug' => (string) $m['slug'], 'version' => (string) $m['version'], 'result' => $result));
        self::release_lock((string) $m['slug'], (string) $m['request_id']);
        return rest_ensure_response(array('ok' => true, 'result' => $result, 'run_id' => $claims['run_id'] ?? null));
    }

    public static function rollback(WP_REST_Request $request) {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        $body = $request->get_json_params();
        if (!is_array($body)) { return new WP_Error('mr_bad_json', 'Payload JSON non valido.', array('status' => 400)); }
        $request_id = self::request_id($body);
        if (is_wp_error($request_id)) { return $request_id; }
        $backup_id = sanitize_text_field((string) ($body['backup_id'] ?? ''));
        if (($body['confirm'] ?? '') !== 'ROLLBACK:' . $backup_id) { return new WP_Error('mr_confirm', 'Conferma rollback non valida.', array('status' => 400)); }
        $fingerprint = hash('sha256', 'rollback:' . $backup_id);
        $existing = self::request_get($request_id);
        if ($existing) {
            if (($existing['fingerprint'] ?? '') !== $fingerprint) {
                return new WP_Error('mr_idempotency_conflict', 'request_id già usato con rollback diverso.', array('status' => 409));
            }
            if (($existing['status'] ?? '') === 'complete' && is_array($existing['result'] ?? null)) {
                return rest_ensure_response(array('ok' => true, 'idempotent' => true, 'result' => $existing['result'], 'run_id' => $claims['run_id'] ?? null));
            }
        }
        $loaded = self::read_backup_manifest($backup_id);
        if (is_wp_error($loaded)) { return $loaded; }
        $slug = sanitize_key((string) ($loaded[1]['slug'] ?? ''));
        if (!self::mutation_slug_allowed($slug)) {
            return new WP_Error('mr_slug_denied', 'Rollback non autorizzato per questo ambiente.', array('status' => 403));
        }
        $lock = self::acquire_lock($slug, $request_id);
        if (is_wp_error($lock)) { return $lock; }
        self::request_put($request_id, array('status' => 'running', 'action' => 'rollback', 'fingerprint' => $fingerprint, 'backup_id' => $backup_id));
        $result = self::rollback_internal($backup_id, false);
        self::release_lock($slug, $request_id);
        if (is_wp_error($result)) {
            self::request_put($request_id, array('status' => 'failed', 'action' => 'rollback', 'fingerprint' => $fingerprint, 'backup_id' => $backup_id, 'error' => $result->get_error_code()));
            return $result;
        }
        self::request_put($request_id, array('status' => 'complete', 'action' => 'rollback', 'fingerprint' => $fingerprint, 'backup_id' => $backup_id, 'result' => $result));
        return rest_ensure_response(array('ok' => true, 'result' => $result, 'run_id' => $claims['run_id'] ?? null));
    }

    public static function validate_local_package($zip_path, $slug, $version, $sha256) {
        $slug = sanitize_key((string) $slug);
        $version = sanitize_text_field((string) $version);
        $sha256 = strtolower(trim((string) $sha256));
        $specs = self::plugin_specs();
        if (!isset($specs[$slug])) { return new WP_Error('mr_local_slug', 'Plugin fuori allowlist.', array('status'=>403)); }
        if (!is_file($zip_path)) { return new WP_Error('mr_local_missing', 'Pacchetto locale non trovato.', array('status'=>404)); }
        $size = filesize($zip_path);
        if ($size < 1 || $size > (int) $specs[$slug]['max_bytes']) { return new WP_Error('mr_local_size', 'Dimensione pacchetto non consentita.', array('status'=>413)); }
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) { return new WP_Error('mr_local_sha', 'SHA-256 non valido.', array('status'=>400)); }
        $actual = hash_file('sha256', $zip_path);
        if (!hash_equals($sha256, $actual)) { return new WP_Error('mr_local_sha_mismatch', 'SHA-256 pacchetto non corrispondente.', array('status'=>409)); }
        $zip = self::validate_zip($zip_path, $slug, $specs[$slug]);
        if (is_wp_error($zip)) { return $zip; }
        $main_raw = $zip->getFromName((string) $specs[$slug]['main']);
        $zip->close();
        if ($main_raw === false) { return new WP_Error('mr_local_main', 'Main file plugin non leggibile.', array('status'=>409)); }
        $tmp = wp_tempnam('mr-bridge-main');
        if (!$tmp || @file_put_contents($tmp, $main_raw) === false) { return new WP_Error('mr_local_tmp', 'Impossibile validare il main file.', array('status'=>500)); }
        if (!function_exists('get_plugin_data')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $data = get_plugin_data($tmp, false, false);
        @unlink($tmp);
        if (trim((string)($data['Name'] ?? '')) !== (string)$specs[$slug]['name']) { return new WP_Error('mr_local_name', 'Plugin Name inatteso.', array('status'=>409)); }
        if (trim((string)($data['Version'] ?? '')) !== $version) { return new WP_Error('mr_local_version', 'Versione plugin inattesa.', array('status'=>409)); }
        return array('ok'=>true,'slug'=>$slug,'version'=>$version,'sha256'=>$actual,'bytes'=>(int)$size);
    }

    public static function deploy_local_package($zip_path, $slug, $version, $sha256, $request_id, $activate = true) {
        $slug = sanitize_key((string)$slug);
        $request_id = (string)$request_id;
        $version = sanitize_text_field((string)$version);
        $sha256 = strtolower(trim((string)$sha256));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,96}$/', $request_id)) { return new WP_Error('mr_local_request', 'request_id non valido.', array('status'=>400)); }
        if (!self::mutation_slug_allowed($slug)) { return new WP_Error('mr_slug_denied', 'Slug non autorizzato per questo ambiente.', array('status'=>403)); }
        $valid = self::validate_local_package($zip_path, $slug, $version, $sha256);
        if (is_wp_error($valid)) { return $valid; }

        $fingerprint = hash('sha256', wp_json_encode(array('local',$slug,$version,$sha256,(bool)$activate)));
        $existing = self::request_get($request_id);
        if ($existing) {
            if (($existing['fingerprint'] ?? '') !== $fingerprint) { return new WP_Error('mr_idempotency_conflict', 'request_id già usato con payload diverso.', array('status'=>409)); }
            if (($existing['status'] ?? '') === 'complete' && is_array($existing['result'] ?? null)) {
                $result = $existing['result']; $result['idempotent'] = true; return $result;
            }
        }
        $lock = self::acquire_lock($slug, $request_id);
        if (is_wp_error($lock)) { return $lock; }

        $upload_id = 'mrup-' . gmdate('YmdHis') . '-' . strtolower(wp_generate_password(10,false,false));
        $dir = self::upload_dir($upload_id);
        if ($dir === null || is_wp_error($dir) || !wp_mkdir_p($dir)) {
            self::release_lock($slug,$request_id);
            return new WP_Error('mr_local_upload_dir','Directory temporanea non creata.',array('status'=>500));
        }
        $target = $dir . '/package.zip';
        if (!@copy($zip_path,$target)) {
            self::release_lock($slug,$request_id); self::remove_tree($dir);
            return new WP_Error('mr_local_copy','Copia pacchetto nel deploy temporaneo fallita.',array('status'=>500));
        }
        $size = filesize($target);
        $manifest = array(
            'schema'=>1,'upload_id'=>$upload_id,'request_id'=>$request_id,'slug'=>$slug,'version'=>$version,'sha256'=>$sha256,
            'total_size'=>$size,'received'=>$size,'next_index'=>1,'last_chunk_index'=>0,'last_chunk_sha256'=>$sha256,
            'activate'=>(bool)$activate,'status'=>'uploading','created_at'=>current_time('mysql',true),'updated_at_unix'=>time(),
            'run_id'=>'direct-local','actor_id'=>'mr-autonomous',
        );
        if (!self::write_json($dir.'/manifest.json',$manifest)) {
            self::release_lock($slug,$request_id); self::remove_tree($dir);
            return new WP_Error('mr_local_manifest','Manifest deploy locale non scritto.',array('status'=>500));
        }
        self::request_put($request_id,array('status'=>'running','fingerprint'=>$fingerprint,'upload_id'=>$upload_id,'slug'=>$slug,'version'=>$version));
        $result = self::deploy_from_upload($dir,$manifest);
        if (is_wp_error($result)) {
            self::release_lock($slug,$request_id);
            self::request_put($request_id,array('status'=>'failed','fingerprint'=>$fingerprint,'slug'=>$slug,'version'=>$version,'error'=>$result->get_error_code()));
            return $result;
        }
        self::request_put($request_id,array('status'=>'complete','fingerprint'=>$fingerprint,'slug'=>$slug,'version'=>$version,'result'=>$result));
        self::release_lock($slug,$request_id);
        return $result;
    }

    public static function rollback_local($backup_id, $request_id) {
        $backup_id = sanitize_text_field((string)$backup_id);
        $request_id = (string)$request_id;
        if (!preg_match('/^[A-Za-z0-9._:-]{16,96}$/', $request_id)) { return new WP_Error('mr_local_request', 'request_id non valido.', array('status'=>400)); }
        $loaded = self::read_backup_manifest($backup_id);
        if (is_wp_error($loaded)) { return $loaded; }
        $slug = sanitize_key((string)($loaded[1]['slug'] ?? ''));
        if (!self::mutation_slug_allowed($slug)) { return new WP_Error('mr_slug_denied', 'Rollback non autorizzato per questo ambiente.', array('status'=>403)); }
        $fingerprint = hash('sha256','local-rollback:'.$backup_id);
        $existing = self::request_get($request_id);
        if ($existing) {
            if (($existing['fingerprint'] ?? '') !== $fingerprint) { return new WP_Error('mr_idempotency_conflict','request_id già usato con rollback diverso.',array('status'=>409)); }
            if (($existing['status'] ?? '') === 'complete' && is_array($existing['result'] ?? null)) {
                $result=$existing['result']; $result['idempotent']=true; return $result;
            }
        }
        $lock = self::acquire_lock($slug,$request_id);
        if (is_wp_error($lock)) { return $lock; }
        self::request_put($request_id,array('status'=>'running','fingerprint'=>$fingerprint,'action'=>'local_rollback','backup_id'=>$backup_id));
        $result = self::rollback_internal($backup_id,false);
        self::release_lock($slug,$request_id);
        if (is_wp_error($result)) {
            self::request_put($request_id,array('status'=>'failed','fingerprint'=>$fingerprint,'action'=>'local_rollback','backup_id'=>$backup_id,'error'=>$result->get_error_code()));
            return $result;
        }
        self::request_put($request_id,array('status'=>'complete','fingerprint'=>$fingerprint,'action'=>'local_rollback','backup_id'=>$backup_id,'result'=>$result));
        return $result;
    }

    public static function attestations() {
        return rest_ensure_response(array(
            'environment' => self::environment(),
            'attestations' => (array) get_option(self::ATTEST_OPTION, array()),
        ));
    }
}

