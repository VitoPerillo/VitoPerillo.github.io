<?php
if (!defined('ABSPATH')) { exit; }

final class MR_Bridge_Plugin_Updater_V1 {
    const REST_NAMESPACE = 'mr-bridge/v1';
    const PRODUCTION_HOME = 'https://www.yoganostress.it';
    const OIDC_REPOSITORY = 'VitoPerillo/yoganostress-wordpress-bridge';
    const OIDC_REPOSITORY_ID = '1390938875';
    const OIDC_OWNER_ID = '317205417';
    const OIDC_AUD = 'https://www.yoganostress.it/mr-bridge-plugin-updates';
    const OIDC_REF = 'refs/heads/main';
    const OIDC_WORKFLOW_REF = 'VitoPerillo/yoganostress-wordpress-bridge/.github/workflows/mr-plugin-update-production.yml@refs/heads/main';
    const REQUESTS_OPTION = 'mr_bridge_plugin_update_requests_v1';
    const LOCK_KEY = 'mr_bridge_plugin_update_lock_v1';
    const LOCK_TTL = 900;
    const MAX_REQUESTS = 100;
    const MAX_BACKUPS = 12;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/plugin-updates/list', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'list_updates'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/plugin-updates/apply', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'apply_update'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/plugin-updates/rollback', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rollback_update'),
            'permission_callback' => '__return_true',
        ));
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
            return new WP_Error('mr_plugin_update_jwks_http', 'JWKS GitHub non disponibile.', array('status' => 503));
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data) || empty($data['keys'])) {
            return new WP_Error('mr_plugin_update_jwks_invalid', 'JWKS GitHub non valido.', array('status' => 503));
        }
        set_transient('mr_bridge_github_jwks', $data, HOUR_IN_SECONDS);
        return $data;
    }

    private static function verify_oidc() {
        if (class_exists('MR_Bridge_Deploy_V1') && MR_Bridge_Deploy_V1::environment() !== 'production') {
            return new WP_Error('mr_plugin_update_production_only', 'Aggiornamenti plugin abilitati solo sul sito produzione riconosciuto.', array('status' => 403));
        }
        $direct = class_exists('MR_Bridge_Autonomous_V1') ? MR_Bridge_Autonomous_V1::verify_global('maintenance:write') : null;
        if ($direct !== null) { return $direct; }

        $auth = self::auth_header();
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return new WP_Error('mr_plugin_update_oidc_missing', 'Token OIDC mancante.', array('status' => 401));
        }
        $parts = explode('.', trim($m[1]));
        if (count($parts) !== 3) {
            return new WP_Error('mr_plugin_update_oidc_bad_token', 'JWT non valido.', array('status' => 401));
        }

        $header_raw = self::b64url_decode($parts[0]);
        $payload_raw = self::b64url_decode($parts[1]);
        $sig = self::b64url_decode($parts[2]);
        if ($header_raw === false || $payload_raw === false || $sig === false) {
            return new WP_Error('mr_plugin_update_oidc_decode', 'JWT non decodificabile.', array('status' => 401));
        }
        $header = json_decode($header_raw, true);
        $claims = json_decode($payload_raw, true);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            return new WP_Error('mr_plugin_update_oidc_header', 'Header JWT non valido.', array('status' => 401));
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
            return new WP_Error('mr_plugin_update_oidc_key', 'Chiave OIDC GitHub non verificabile.', array('status' => 401));
        }
        if (openssl_verify($parts[0] . '.' . $parts[1], $sig, $cert, OPENSSL_ALGO_SHA256) !== 1) {
            delete_transient('mr_bridge_github_jwks');
            return new WP_Error('mr_plugin_update_oidc_signature', 'Firma OIDC GitHub non valida.', array('status' => 401));
        }

        $now = time();
        $aud = $claims['aud'] ?? '';
        $expected_aud = untrailingslashit(home_url('/')) . '/mr-bridge-plugin-updates';
        $aud_ok = is_array($aud) ? in_array($expected_aud, $aud, true) : hash_equals($expected_aud, (string) $aud);
        $checks = array(
            'iss' => (($claims['iss'] ?? '') === 'https://token.actions.githubusercontent.com'),
            'aud' => $aud_ok,
            'exp' => (!empty($claims['exp']) && (int) $claims['exp'] >= $now - 30),
            'nbf' => (empty($claims['nbf']) || (int) $claims['nbf'] <= $now + 30),
            'iat' => (!empty($claims['iat']) && (int) $claims['iat'] <= $now + 30 && (int) $claims['iat'] >= $now - 900),
            'repository' => (($claims['repository'] ?? '') === self::OIDC_REPOSITORY),
            'repository_id' => ((string) ($claims['repository_id'] ?? '') === self::OIDC_REPOSITORY_ID),
            'repository_owner_id' => ((string) ($claims['repository_owner_id'] ?? '') === self::OIDC_OWNER_ID),
            'actor_id' => ((string) ($claims['actor_id'] ?? '') === self::OIDC_OWNER_ID),
            'ref' => (($claims['ref'] ?? '') === self::OIDC_REF),
            'workflow_ref' => (($claims['workflow_ref'] ?? '') === self::OIDC_WORKFLOW_REF),
            'event_name' => (($claims['event_name'] ?? '') === 'push'),
        );
        foreach ($checks as $name => $ok) {
            if (!$ok) {
                return new WP_Error('mr_plugin_update_oidc_claim_' . $name, 'Claim OIDC rifiutato: ' . $name, array('status' => 403));
            }
        }
        return $claims;
    }

    private static function require_plugin_api() {
        if (!function_exists('get_plugins') || !function_exists('is_plugin_active') || !function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        if (!function_exists('wp_update_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
    }

    private static function read_json(WP_REST_Request $request) {
        $body = $request->get_json_params();
        return is_array($body) ? $body : array();
    }

    private static function is_bridge_plugin($plugin) {
        return $plugin === 'yoganostress-bridge/yoganostress-bridge.php';
    }

    private static function update_row($plugin, $plugins, $updates) {
        if (!isset($plugins[$plugin]) || !isset($updates[$plugin])) { return null; }
        $u = $updates[$plugin];
        $package = is_object($u) ? (string) ($u->package ?? '') : '';
        $host = $package !== '' ? (string) wp_parse_url($package, PHP_URL_HOST) : '';
        return array(
            'plugin' => $plugin,
            'name' => (string) ($plugins[$plugin]['Name'] ?? $plugin),
            'current_version' => (string) ($plugins[$plugin]['Version'] ?? ''),
            'new_version' => is_object($u) ? (string) ($u->new_version ?? '') : '',
            'active' => is_plugin_active($plugin),
            'package_available' => $package !== '',
            'package_https' => $package !== '' && strpos($package, 'https://') === 0,
            'package_host' => $host,
            'generic_update_allowed' => !self::is_bridge_plugin($plugin),
            'generic_update_block_reason' => self::is_bridge_plugin($plugin) ? 'MR Bridge usa il canale self-deploy dedicato.' : '',
        );
    }

    public static function list_updates(WP_REST_Request $request) {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        self::require_plugin_api();

        wp_update_plugins();
        $plugins = get_plugins();
        $transient = get_site_transient('update_plugins');
        $updates = is_object($transient) && is_array($transient->response ?? null) ? $transient->response : array();
        $rows = array();
        foreach (array_keys($updates) as $plugin) {
            $row = self::update_row($plugin, $plugins, $updates);
            if ($row) { $rows[] = $row; }
        }

        MR_Bridge::log('plugin_updates_listed', array('count' => count($rows)));
        return rest_ensure_response(array(
            'ok' => true,
            'environment' => 'production',
            'count' => count($rows),
            'updates' => $rows,
            'bulk_update' => false,
            'one_plugin_per_request' => true,
        ));
    }

    private static function request_store() {
        $items = get_option(self::REQUESTS_OPTION, array());
        return is_array($items) ? $items : array();
    }

    private static function request_lookup($request_id) {
        foreach (self::request_store() as $row) {
            if (($row['request_id'] ?? '') === $request_id) { return $row; }
        }
        return null;
    }

    private static function request_save($row) {
        $items = self::request_store();
        $items[] = $row;
        if (count($items) > self::MAX_REQUESTS) { $items = array_slice($items, -self::MAX_REQUESTS); }
        update_option(self::REQUESTS_OPTION, $items, false);
    }

    private static function backup_root() {
        $root = trailingslashit(WP_CONTENT_DIR) . 'mr-bridge-plugin-update-backups';
        if (!is_dir($root) && !wp_mkdir_p($root)) {
            return new WP_Error('mr_plugin_update_backup_root', 'Cartella backup plugin non creabile.', array('status' => 500));
        }
        if (!is_file($root . '/index.php')) { @file_put_contents($root . '/index.php', "<?php\n// Silence is golden.\n"); }
        if (!is_file($root . '/.htaccess')) { @file_put_contents($root . '/.htaccess', "Deny from all\n"); }
        if (!is_file($root . '/web.config')) {
            @file_put_contents($root . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*"/></authorization></system.webServer></configuration>');
        }
        return $root;
    }

    private static function remove_tree($path) {
        if (!file_exists($path)) { return true; }
        if (is_file($path) || is_link($path)) { return @unlink($path); }
        $items = scandir($path);
        if ($items === false) { return false; }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') { continue; }
            if (!self::remove_tree($path . DIRECTORY_SEPARATOR . $item)) { return false; }
        }
        return @rmdir($path);
    }

    private static function copy_tree($src, $dst) {
        if (is_link($src)) { return false; }
        if (is_file($src)) {
            $parent = dirname($dst);
            if (!is_dir($parent) && !wp_mkdir_p($parent)) { return false; }
            return @copy($src, $dst);
        }
        if (!is_dir($src)) { return false; }
        if (!is_dir($dst) && !wp_mkdir_p($dst)) { return false; }
        $items = scandir($src);
        if ($items === false) { return false; }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') { continue; }
            if (!self::copy_tree($src . DIRECTORY_SEPARATOR . $item, $dst . DIRECTORY_SEPARATOR . $item)) { return false; }
        }
        return true;
    }

    private static function backup_plugin($plugin, $current_version, $request_id) {
        $root = self::backup_root();
        if (is_wp_error($root)) { return $root; }

        $dir = dirname($plugin);
        $single_file = ($dir === '.' || $dir === DIRECTORY_SEPARATOR);
        $slug = sanitize_key(str_replace(array('/', '\\', '.php'), array('-', '-', ''), $plugin));
        $backup_id = gmdate('YmdHis') . '-' . $slug . '-' . substr(hash('sha256', $request_id), 0, 10);
        $backup_dir = trailingslashit($root) . $backup_id;
        if (!wp_mkdir_p($backup_dir)) {
            return new WP_Error('mr_plugin_update_backup_dir', 'Cartella backup plugin non creabile.', array('status' => 500));
        }

        $source = $single_file ? WP_PLUGIN_DIR . '/' . $plugin : WP_PLUGIN_DIR . '/' . $dir;
        $payload = $backup_dir . '/payload';
        if (!self::copy_tree($source, $payload)) {
            self::remove_tree($backup_dir);
            return new WP_Error('mr_plugin_update_backup_copy', 'Backup del plugin fallito.', array('status' => 500));
        }

        $manifest = array(
            'backup_id' => $backup_id,
            'created_at' => current_time('mysql', true),
            'request_id' => $request_id,
            'plugin' => $plugin,
            'current_version' => $current_version,
            'active' => is_plugin_active($plugin),
            'single_file' => $single_file,
            'source_rel' => $single_file ? $plugin : $dir,
        );
        if (@file_put_contents($backup_dir . '/manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            self::remove_tree($backup_dir);
            return new WP_Error('mr_plugin_update_backup_manifest', 'Manifest backup non scrivibile.', array('status' => 500));
        }

        self::prune_backups($root);
        return array('backup_id' => $backup_id, 'backup_dir' => $backup_dir, 'manifest' => $manifest);
    }

    private static function prune_backups($root) {
        $dirs = glob(trailingslashit($root) . '*', GLOB_ONLYDIR);
        if (!is_array($dirs) || count($dirs) <= self::MAX_BACKUPS) { return; }
        usort($dirs, function($a, $b) { return filemtime($b) <=> filemtime($a); });
        foreach (array_slice($dirs, self::MAX_BACKUPS) as $dir) { self::remove_tree($dir); }
    }

    private static function find_backup($backup_id) {
        if (!preg_match('/^[a-z0-9-]{20,180}$/', (string) $backup_id)) {
            return new WP_Error('mr_plugin_update_backup_id', 'Backup ID non valido.', array('status' => 400));
        }
        $root = self::backup_root();
        if (is_wp_error($root)) { return $root; }
        $dir = trailingslashit($root) . $backup_id;
        $manifest_path = $dir . '/manifest.json';
        if (!is_dir($dir) || !is_file($manifest_path)) {
            return new WP_Error('mr_plugin_update_backup_missing', 'Backup plugin non trovato.', array('status' => 404));
        }
        $manifest = json_decode((string) @file_get_contents($manifest_path), true);
        if (!is_array($manifest) || ($manifest['backup_id'] ?? '') !== $backup_id || empty($manifest['plugin'])) {
            return new WP_Error('mr_plugin_update_backup_invalid', 'Manifest backup plugin non valido.', array('status' => 409));
        }
        return array('backup_id' => $backup_id, 'backup_dir' => $dir, 'manifest' => $manifest);
    }

    private static function rollback_backup($backup) {
        $m = $backup['manifest'];
        $rel = (string) $m['source_rel'];
        $target = WP_PLUGIN_DIR . '/' . $rel;
        $payload = $backup['backup_dir'] . '/payload';

        if (file_exists($target) && !self::remove_tree($target)) {
            return new WP_Error('mr_plugin_update_rollback_remove', 'Rollback: rimozione versione aggiornata fallita.', array('status' => 500));
        }
        if (!self::copy_tree($payload, $target)) {
            return new WP_Error('mr_plugin_update_rollback_copy', 'Rollback: ripristino file fallito.', array('status' => 500));
        }

        if (!empty($m['active'])) {
            $act = activate_plugin((string) $m['plugin'], '', false, true);
            if (is_wp_error($act)) {
                return new WP_Error('mr_plugin_update_rollback_activate', 'Rollback file riuscito ma riattivazione plugin fallita.', array('status' => 500));
            }
        }
        return true;
    }

    private static function acquire_lock($request_id) {
        $lock = get_transient(self::LOCK_KEY);
        if (is_array($lock) && !empty($lock['request_id']) && $lock['request_id'] !== $request_id) {
            return new WP_Error('mr_plugin_update_locked', 'Un altro aggiornamento plugin è già in corso.', array('status' => 409));
        }
        set_transient(self::LOCK_KEY, array('request_id' => $request_id, 'at' => time()), self::LOCK_TTL);
        return true;
    }

    private static function release_lock($request_id) {
        $lock = get_transient(self::LOCK_KEY);
        if (is_array($lock) && ($lock['request_id'] ?? '') === $request_id) { delete_transient(self::LOCK_KEY); }
    }

    public static function rollback_update(WP_REST_Request $request) {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        self::require_plugin_api();

        $data = self::read_json($request);
        $request_id = sanitize_text_field((string) ($data['request_id'] ?? ''));
        $backup_id = sanitize_text_field((string) ($data['backup_id'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._:-]{16,96}$/', $request_id)) {
            return new WP_Error('mr_plugin_update_request_id', 'request_id non valido.', array('status' => 400));
        }
        if (($data['confirm'] ?? '') !== 'ROLLBACK-PLUGIN:' . $backup_id) {
            return new WP_Error('mr_plugin_update_confirm', 'Conferma rollback plugin non valida.', array('status' => 400));
        }

        $previous = self::request_lookup($request_id);
        if (is_array($previous)) {
            if (($previous['action'] ?? '') !== 'rollback' || ($previous['backup_id'] ?? '') !== $backup_id) {
                return new WP_Error('mr_plugin_update_idempotency_conflict', 'request_id già utilizzato con parametri diversi.', array('status' => 409));
            }
            return rest_ensure_response(array('ok' => true, 'idempotent' => true, 'result' => $previous));
        }

        $backup = self::find_backup($backup_id);
        if (is_wp_error($backup)) { return $backup; }
        $plugin = (string) ($backup['manifest']['plugin'] ?? '');
        if (self::is_bridge_plugin($plugin)) {
            return new WP_Error('mr_plugin_update_bridge_denied', 'Il rollback di MR Bridge usa il canale self-deploy dedicato.', array('status' => 403));
        }

        $lock = self::acquire_lock($request_id);
        if (is_wp_error($lock)) { return $lock; }
        try {
            $rb = self::rollback_backup($backup);
            if (is_wp_error($rb)) { return $rb; }
            wp_clean_plugins_cache(true);
            $plugins = get_plugins();
            $installed = (string) ($plugins[$plugin]['Version'] ?? '');
            $expected = (string) ($backup['manifest']['current_version'] ?? '');
            if ($installed !== $expected) {
                return new WP_Error('mr_plugin_update_rollback_verify', 'Rollback eseguito ma la versione ripristinata non coincide con il backup.', array('status' => 500));
            }

            $row = array(
                'request_id' => $request_id,
                'action' => 'rollback',
                'backup_id' => $backup_id,
                'plugin' => $plugin,
                'installed_version' => $installed,
                'active' => is_plugin_active($plugin),
                'completed_at' => current_time('mysql', true),
            );
            self::request_save($row);
            MR_Bridge::log('plugin_update_manual_rollback', $row);
            return rest_ensure_response(array('ok' => true, 'idempotent' => false, 'result' => $row));
        } finally {
            self::release_lock($request_id);
        }
    }

    public static function apply_update(WP_REST_Request $request) {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        if (is_multisite()) {
            return new WP_Error('mr_plugin_update_multisite', 'Aggiornamenti plugin non abilitati su multisite in RC12.', array('status' => 403));
        }
        self::require_plugin_api();

        $data = self::read_json($request);
        $request_id = sanitize_text_field((string) ($data['request_id'] ?? ''));
        $plugin = plugin_basename((string) ($data['plugin'] ?? ''));
        $expected_current = sanitize_text_field((string) ($data['expected_current_version'] ?? ''));
        $expected_new = sanitize_text_field((string) ($data['expected_new_version'] ?? ''));
        $confirm = (string) ($data['confirm'] ?? '');

        if (!preg_match('/^[A-Za-z0-9._:-]{16,96}$/', $request_id)) {
            return new WP_Error('mr_plugin_update_request_id', 'request_id non valido.', array('status' => 400));
        }
        if ($plugin === '' || strpos($plugin, '..') !== false) {
            return new WP_Error('mr_plugin_update_plugin', 'Plugin non valido.', array('status' => 400));
        }
        if (self::is_bridge_plugin($plugin)) {
            return new WP_Error('mr_plugin_update_bridge_denied', 'MR Bridge deve essere aggiornato tramite il canale self-deploy dedicato.', array('status' => 403));
        }
        if ($expected_current === '' || $expected_new === '') {
            return new WP_Error('mr_plugin_update_versions', 'Versioni attesa corrente e nuova obbligatorie.', array('status' => 400));
        }
        if ($confirm !== 'UPDATE:' . $plugin . ':' . $expected_current . '->' . $expected_new) {
            return new WP_Error('mr_plugin_update_confirm', 'Conferma aggiornamento non valida.', array('status' => 400));
        }

        $previous = self::request_lookup($request_id);
        if (is_array($previous)) {
            $same = ($previous['plugin'] ?? '') === $plugin
                && ($previous['expected_current_version'] ?? '') === $expected_current
                && ($previous['expected_new_version'] ?? '') === $expected_new;
            if (!$same) {
                return new WP_Error('mr_plugin_update_idempotency_conflict', 'request_id già utilizzato con parametri diversi.', array('status' => 409));
            }
            return rest_ensure_response(array('ok' => true, 'idempotent' => true, 'result' => $previous));
        }

        $lock = self::acquire_lock($request_id);
        if (is_wp_error($lock)) { return $lock; }

        try {
            wp_update_plugins();
            $plugins = get_plugins();
            if (!isset($plugins[$plugin])) {
                return new WP_Error('mr_plugin_update_not_installed', 'Plugin non installato.', array('status' => 404));
            }
            $current = (string) ($plugins[$plugin]['Version'] ?? '');
            if ($current !== $expected_current) {
                return new WP_Error('mr_plugin_update_current_conflict', 'La versione installata è cambiata rispetto al preflight.', array('status' => 409, 'current_version' => $current));
            }

            $transient = get_site_transient('update_plugins');
            $updates = is_object($transient) && is_array($transient->response ?? null) ? $transient->response : array();
            if (!isset($updates[$plugin]) || !is_object($updates[$plugin])) {
                return new WP_Error('mr_plugin_update_not_available', 'Nessun aggiornamento disponibile per il plugin.', array('status' => 409));
            }
            $u = $updates[$plugin];
            $new = (string) ($u->new_version ?? '');
            $package = (string) ($u->package ?? '');
            if ($new !== $expected_new) {
                return new WP_Error('mr_plugin_update_new_conflict', 'La versione disponibile è cambiata rispetto al preflight.', array('status' => 409, 'new_version' => $new));
            }
            if ($package === '' || strpos($package, 'https://') !== 0 || !wp_http_validate_url($package)) {
                return new WP_Error('mr_plugin_update_package', 'Pacchetto di aggiornamento HTTPS non valido o non disponibile.', array('status' => 409));
            }

            $backup = self::backup_plugin($plugin, $current, $request_id);
            if (is_wp_error($backup)) { return $backup; }
            $was_active = is_plugin_active($plugin);

            $skin = new Automatic_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader($skin);
            $result = $upgrader->upgrade($plugin, array('clear_update_cache' => true));

            if (is_wp_error($result) || $result !== true) {
                $rb = self::rollback_backup($backup);
                MR_Bridge::log('plugin_update_failed', array(
                    'plugin' => $plugin,
                    'from' => $current,
                    'to' => $new,
                    'request_id' => $request_id,
                    'rollback_ok' => !is_wp_error($rb),
                ));
                if (is_wp_error($rb)) { return $rb; }
                return new WP_Error('mr_plugin_update_failed', 'Aggiornamento fallito; rollback automatico eseguito.', array('status' => 500));
            }

            wp_clean_plugins_cache(true);
            $plugins_after = get_plugins();
            $installed = (string) ($plugins_after[$plugin]['Version'] ?? '');
            if ($installed !== $expected_new) {
                $rb = self::rollback_backup($backup);
                if (is_wp_error($rb)) { return $rb; }
                return new WP_Error('mr_plugin_update_verify_version', 'Versione post-update inattesa; rollback automatico eseguito.', array('status' => 500, 'installed_version' => $installed));
            }

            if ($was_active && !is_plugin_active($plugin)) {
                $act = activate_plugin($plugin, '', false, true);
                if (is_wp_error($act)) {
                    $rb = self::rollback_backup($backup);
                    if (is_wp_error($rb)) { return $rb; }
                    return new WP_Error('mr_plugin_update_reactivate', 'Plugin non riattivabile dopo update; rollback automatico eseguito.', array('status' => 500));
                }
            }

            $probe_url = add_query_arg('mr_probe', rawurlencode($request_id), rest_url('mr-bridge/v1/status'));
            $probe = wp_remote_get($probe_url, array(
                'timeout' => 15,
                'redirection' => 2,
                'sslverify' => true,
                'headers' => array('Cache-Control' => 'no-cache'),
                'user-agent' => 'MR-Bridge-Plugin-Updater/' . (class_exists('MR_Bridge') ? MR_Bridge::VERSION : 'unknown'),
            ));
            $probe_ok = !is_wp_error($probe) && (int) wp_remote_retrieve_response_code($probe) === 200;
            if ($probe_ok) {
                $probe_data = json_decode((string) wp_remote_retrieve_body($probe), true);
                $probe_ok = is_array($probe_data) && !empty($probe_data['ok']);
            }
            if (!$probe_ok) {
                $rb = self::rollback_backup($backup);
                if (is_wp_error($rb)) { return $rb; }
                return new WP_Error('mr_plugin_update_loopback_failed', 'Il sito non ha superato il controllo post-update; rollback automatico eseguito.', array('status' => 500));
            }

            $row = array(
                'request_id' => $request_id,
                'plugin' => $plugin,
                'name' => (string) ($plugins_after[$plugin]['Name'] ?? $plugin),
                'expected_current_version' => $expected_current,
                'expected_new_version' => $expected_new,
                'installed_version' => $installed,
                'active' => is_plugin_active($plugin),
                'backup_id' => (string) $backup['backup_id'],
                'completed_at' => current_time('mysql', true),
                'package_host' => (string) wp_parse_url($package, PHP_URL_HOST),
            );
            self::request_save($row);
            MR_Bridge::log('plugin_update_success', $row);

            return rest_ensure_response(array('ok' => true, 'idempotent' => false, 'result' => $row));
        } finally {
            self::release_lock($request_id);
        }
    }
}

MR_Bridge_Plugin_Updater_V1::init();

