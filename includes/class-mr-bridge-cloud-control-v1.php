<?php
if (!defined('ABSPATH')) { exit; }

/**
 * MR Bridge Cloud Control v1
 *
 * Secure, allowlisted external-provider control plane for Professione Smart.
 * Secrets are encrypted at rest and are never returned by REST responses.
 */
final class MR_Bridge_Cloud_Control_V1 {
    const REST_NAMESPACE = 'mr-bridge/v1';
    const VAULT_OPTION = 'mr_bridge_vault_v1';
    const CONFIG_OPTION = 'mr_bridge_cloud_control_v1';
    const VAULT_VERSION = 1;
    const CLOUDFLARE_API = 'https://api.cloudflare.com/client/v4';
    const PROJECT = 'professione-smart';
    const WORKER_NAME = 'professione-smart';
    const VITO_AI_PROJECT = 'vito-ai';
    const VITO_AI_WORKER_NAME = 'vito-ai';

    private static function worker_allowlist() {
        return array(self::WORKER_NAME, self::VITO_AI_WORKER_NAME);
    }

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        add_action('admin_menu', array(__CLASS__, 'admin_menu'));
        add_action('admin_post_mr_bridge_cloud_control_save', array(__CLASS__, 'admin_save'));
        add_action('admin_post_mr_bridge_cloud_control_push', array(__CLASS__, 'admin_push'));
    }

    public static function admin_menu() {
        add_management_page(
            'MR Bridge Cloud Control',
            'MR Bridge Cloud Control',
            'manage_options',
            'mr-bridge-cloud-control',
            array(__CLASS__, 'admin_page')
        );
    }

    private static function admin_redirect($notice) {
        wp_safe_redirect(add_query_arg(array(
            'page' => 'mr-bridge-cloud-control',
            'mr_notice' => rawurlencode((string) $notice),
        ), admin_url('tools.php')));
        exit;
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) { wp_die('Non autorizzato.'); }
        $cfg = self::config();
        $vault = self::vault_metadata();
        $notice = isset($_GET['mr_notice']) ? sanitize_text_field(wp_unslash($_GET['mr_notice'])) : '';
        echo '<div class="wrap"><h1>MR Bridge Cloud Control</h1>';
        echo '<p>Vault cifrato per Professione Smart. I valori segreti non vengono mai mostrati dopo il salvataggio.</p>';
        if ($notice !== '') { echo '<div class="notice notice-info"><p>' . esc_html($notice) . '</p></div>'; }
        echo '<h2>Stato</h2><table class="widefat striped" style="max-width:900px"><tbody>';
        echo '<tr><td>Cloudflare API token</td><td>' . (!empty($vault['cloudflare_api_token']['present']) ? '<strong>Configurato</strong>' : 'Mancante') . '</td></tr>';
        echo '<tr><td>Stripe restricted LIVE key</td><td>' . (!empty($vault['stripe_secret_key']['present']) ? '<strong>Configurata</strong>' : 'Mancante') . '</td></tr>';
        echo '<tr><td>Cloudflare account ID</td><td>' . (!empty($cfg['cloudflare_account_id']) ? '<strong>Configurato</strong>' : 'Mancante') . '</td></tr>';
        echo '<tr><td>Worker</td><td><code>' . esc_html(self::WORKER_NAME) . '</code></td></tr>';
        echo '</tbody></table>';

        echo '<h2>Configura credenziali</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" autocomplete="off">';
        wp_nonce_field('mr_bridge_cloud_control_save');
        echo '<input type="hidden" name="action" value="mr_bridge_cloud_control_save">';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="mr_cf_account">Cloudflare account ID</label></th><td><input class="regular-text code" id="mr_cf_account" name="cloudflare_account_id" type="text" value="" placeholder="32 caratteri" autocomplete="off"><p class="description">Lascia vuoto per non modificarlo.</p></td></tr>';
        echo '<tr><th><label for="mr_cf_token">Cloudflare API token</label></th><td><input class="regular-text code" id="mr_cf_token" name="cloudflare_api_token" type="password" value="" autocomplete="new-password"><p class="description">Token limitato a Workers Scripts Write. Lascia vuoto per non modificarlo.</p></td></tr>';
        echo '<tr><th><label for="mr_stripe_key">Stripe restricted LIVE key</label></th><td><input class="regular-text code" id="mr_stripe_key" name="stripe_secret_key" type="password" value="" autocomplete="new-password" placeholder="rk_live_..."><p class="description">Solo chiavi ristrette LIVE <code>rk_live_...</code>.</p></td></tr>';
        echo '</tbody></table>';
        submit_button('Salva nel Vault cifrato');
        echo '</form>';

        echo '<h2>Pubblica su Cloudflare</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mr_bridge_cloud_control_push');
        echo '<input type="hidden" name="action" value="mr_bridge_cloud_control_push">';
        submit_button('Verifica Cloudflare e imposta STRIPE_SECRET_KEY', 'primary', 'submit', false);
        echo '</form></div>';
    }

    public static function admin_save() {
        if (!current_user_can('manage_options')) { wp_die('Non autorizzato.'); }
        check_admin_referer('mr_bridge_cloud_control_save');

        $account_id = self::account_id(isset($_POST['cloudflare_account_id']) ? wp_unslash($_POST['cloudflare_account_id']) : '');
        if ($account_id !== '') {
            $cfg = self::config();
            $cfg['cloudflare_account_id'] = $account_id;
            $cfg['worker_name'] = self::WORKER_NAME;
            $cfg['updated_at'] = current_time('mysql', true);
            self::save_config($cfg);
            MR_Bridge::log('cloudflare_configured_admin', array(
                'account_id_sha256_prefix' => substr(hash('sha256', $account_id), 0, 12),
                'worker' => self::WORKER_NAME,
            ));
        }

        $changed = array();
        $incoming = array(
            'cloudflare_api_token' => isset($_POST['cloudflare_api_token']) ? (string) wp_unslash($_POST['cloudflare_api_token']) : '',
            'stripe_secret_key' => isset($_POST['stripe_secret_key']) ? (string) wp_unslash($_POST['stripe_secret_key']) : '',
        );
        foreach ($incoming as $name => $value) {
            if ($value === '') { continue; }
            if ($name === 'stripe_secret_key' && !preg_match('/^rk_live_[A-Za-z0-9_]+$/', $value)) {
                self::admin_redirect('Chiave Stripe rifiutata: usa una restricted LIVE key rk_live_....');
            }
            if (strlen($value) > 8192) { self::admin_redirect('Valore segreto troppo lungo.'); }
            $encrypted = self::encrypt_value($value);
            if (is_wp_error($encrypted)) { self::admin_redirect($encrypted->get_error_message()); }
            $rows = self::vault_rows();
            $rows[$name] = $encrypted;
            update_option(self::VAULT_OPTION, $rows, false);
            $changed[] = $name;
            MR_Bridge::log('vault_secret_put_admin', array(
                'name' => $name,
                'sha256_prefix' => substr(hash('sha256', $value), 0, 12),
            ));
            if (function_exists('sodium_memzero')) { sodium_memzero($value); }
        }
        self::admin_redirect(empty($changed) && $account_id === '' ? 'Nessuna modifica.' : 'Configurazione salvata in sicurezza.');
    }

    public static function admin_push() {
        if (!current_user_can('manage_options')) { wp_die('Non autorizzato.'); }
        check_admin_referer('mr_bridge_cloud_control_push');
        $cfg = self::config();
        $account_id = self::account_id($cfg['cloudflare_account_id'] ?? '');
        if ($account_id === '') { self::admin_redirect('Manca il Cloudflare account ID.'); }
        $verify = self::cloudflare_request('GET', '/accounts/' . rawurlencode($account_id) . '/workers/scripts/' . rawurlencode(self::WORKER_NAME) . '/secrets');
        if (is_wp_error($verify)) { self::admin_redirect($verify->get_error_message()); }
        $secret = self::decrypt_value('stripe_secret_key');
        if (is_wp_error($secret)) { self::admin_redirect($secret->get_error_message()); }
        $result = self::cloudflare_request('PUT', '/accounts/' . rawurlencode($account_id) . '/workers/scripts/' . rawurlencode(self::WORKER_NAME) . '/secrets', array(
            'name' => 'STRIPE_SECRET_KEY',
            'text' => $secret,
            'type' => 'secret_text',
        ));
        if (function_exists('sodium_memzero')) { sodium_memzero($secret); }
        if (is_wp_error($result)) { self::admin_redirect($result->get_error_message()); }
        MR_Bridge::log('cloudflare_worker_secret_put_admin', array(
            'project' => self::PROJECT,
            'worker' => self::WORKER_NAME,
            'binding' => 'STRIPE_SECRET_KEY',
        ));
        self::admin_redirect('STRIPE_SECRET_KEY impostata sul Worker Professione Smart.');
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/cloud-control/status', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'status'),
            'permission_callback' => array(__CLASS__, 'can_read'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/cloud-control/vault/put', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'vault_put'),
            'permission_callback' => array(__CLASS__, 'can_secrets_write'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/cloud-control/vault/delete', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'vault_delete'),
            'permission_callback' => array(__CLASS__, 'can_secrets_write'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/cloud-control/cloudflare/configure', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'cloudflare_configure'),
            'permission_callback' => array(__CLASS__, 'can_provider_write'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/cloud-control/cloudflare/verify', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'cloudflare_verify'),
            'permission_callback' => array(__CLASS__, 'can_provider_write'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/cloud-control/cloudflare/verify-readonly', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'cloudflare_verify'),
            'permission_callback' => array(__CLASS__, 'can_read'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/cloud-control/cloudflare/inventory-readonly', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'cloudflare_inventory'),
            'permission_callback' => array(__CLASS__, 'can_read'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/cloud-control/cloudflare/worker-secret', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'cloudflare_worker_secret'),
            'permission_callback' => array(__CLASS__, 'can_provider_write'),
        ));
    }

    public static function can_read($request) {
        if (MR_Bridge::can_manage()) { return true; }
        $direct = MR_Bridge_Autonomous_V1::verify_global('read', $request);
        if ($direct !== null) { return is_wp_error($direct) ? $direct : true; }
        $oidc = MR_Bridge_Deploy_V1::authorize_staging_automation();
        return is_wp_error($oidc) ? $oidc : true;
    }

    public static function can_secrets_write($request) {
        if (MR_Bridge::can_manage()) { return true; }
        return MR_Bridge_Autonomous_V1::verify_global('secrets:write', $request);
    }

    public static function can_provider_write($request) {
        if (MR_Bridge::can_manage()) { return true; }
        return MR_Bridge_Autonomous_V1::verify_global('providers:write', $request);
    }

    private static function allowed_secret_names() {
        return array('cloudflare_api_token', 'stripe_secret_key');
    }

    private static function validate_secret_name($name) {
        $name = strtolower(trim((string) $name));
        return in_array($name, self::allowed_secret_names(), true) ? $name : '';
    }

    private static function vault_key() {
        if (!function_exists('sodium_crypto_secretbox')) {
            return new WP_Error('mr_vault_sodium_missing', 'libsodium non disponibile.', array('status' => 503));
        }
        $material = '';
        foreach (array('AUTH_KEY','SECURE_AUTH_KEY','LOGGED_IN_KEY','NONCE_KEY','AUTH_SALT','SECURE_AUTH_SALT') as $constant) {
            if (defined($constant)) { $material .= constant($constant) . "\n"; }
        }
        if ($material === '') {
            return new WP_Error('mr_vault_key_material_missing', 'Materiale chiave WordPress non disponibile.', array('status' => 503));
        }
        if (function_exists('hash_hkdf')) {
            return hash_hkdf('sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'mr-bridge-vault-v1', home_url('/'));
        }
        return substr(hash('sha256', 'mr-bridge-vault-v1|' . home_url('/') . '|' . $material, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private static function vault_rows() {
        $rows = get_option(self::VAULT_OPTION, array());
        return is_array($rows) ? $rows : array();
    }

    private static function encrypt_value($value) {
        $key = self::vault_key();
        if (is_wp_error($key)) { return $key; }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox((string) $value, $nonce, $key);
        sodium_memzero($key);
        return array(
            'v' => self::VAULT_VERSION,
            'nonce' => base64_encode($nonce),
            'cipher' => base64_encode($cipher),
            'sha256' => hash('sha256', (string) $value),
            'updated_at' => current_time('mysql', true),
        );
    }

    private static function decrypt_value($name) {
        $name = self::validate_secret_name($name);
        if ($name === '') { return new WP_Error('mr_vault_name_denied', 'Secret fuori allowlist.', array('status' => 403)); }
        $rows = self::vault_rows();
        $row = isset($rows[$name]) && is_array($rows[$name]) ? $rows[$name] : null;
        if (!$row) { return new WP_Error('mr_vault_secret_missing', 'Secret non configurato.', array('status' => 409)); }
        $nonce = base64_decode((string) ($row['nonce'] ?? ''), true);
        $cipher = base64_decode((string) ($row['cipher'] ?? ''), true);
        if ($nonce === false || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES || $cipher === false) {
            return new WP_Error('mr_vault_corrupt', 'Vault non leggibile.', array('status' => 500));
        }
        $key = self::vault_key();
        if (is_wp_error($key)) { return $key; }
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        sodium_memzero($key);
        if ($plain === false) { return new WP_Error('mr_vault_decrypt_failed', 'Decrypt vault fallita.', array('status' => 500)); }
        return $plain;
    }

    private static function vault_metadata() {
        $out = array();
        foreach (self::vault_rows() as $name => $row) {
            if (!in_array($name, self::allowed_secret_names(), true) || !is_array($row)) { continue; }
            $out[$name] = array(
                'present' => true,
                'sha256_prefix' => substr((string) ($row['sha256'] ?? ''), 0, 12),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            );
        }
        foreach (self::allowed_secret_names() as $name) {
            if (!isset($out[$name])) { $out[$name] = array('present' => false); }
        }
        return $out;
    }

    private static function config() {
        $cfg = get_option(self::CONFIG_OPTION, array());
        return is_array($cfg) ? $cfg : array();
    }

    private static function save_config($cfg) {
        update_option(self::CONFIG_OPTION, is_array($cfg) ? $cfg : array(), false);
    }

    private static function account_id($value) {
        $id = trim((string) $value);
        return preg_match('/^[a-f0-9]{32}$/i', $id) ? strtolower($id) : '';
    }

    private static function cloudflare_request($method, $path, $body = null) {
        $token = self::decrypt_value('cloudflare_api_token');
        if (is_wp_error($token)) { return $token; }
        $url = self::CLOUDFLARE_API . $path;
        $args = array(
            'method' => strtoupper((string) $method),
            'timeout' => 15,
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
        );
        if ($body !== null) { $args['body'] = wp_json_encode($body); }
        $response = wp_remote_request($url, $args);
        sodium_memzero($token);
        if (is_wp_error($response)) {
            return new WP_Error('mr_cloudflare_transport', 'Cloudflare non raggiungibile.', array('status' => 502));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $payload = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($payload)) { $payload = array(); }
        if ($status < 200 || $status >= 300 || empty($payload['success'])) {
            $code = isset($payload['errors'][0]['code']) ? (string) $payload['errors'][0]['code'] : 'unknown';
            return new WP_Error('mr_cloudflare_api', 'Cloudflare API ha rifiutato la richiesta.', array('status' => 502, 'provider_code' => $code));
        }
        return $payload;
    }

    public static function status() {
        $cfg = self::config();
        return rest_ensure_response(array(
            'ok' => true,
            'module' => 'cloud-control-v1',
            'project' => self::PROJECT,
            'worker_allowlist' => self::worker_allowlist(),
            'vault' => self::vault_metadata(),
            'cloudflare' => array(
                'configured' => !empty($cfg['cloudflare_account_id']),
                'account_id_sha256_prefix' => !empty($cfg['cloudflare_account_id']) ? substr(hash('sha256', (string) $cfg['cloudflare_account_id']), 0, 12) : '',
                'worker' => self::WORKER_NAME,
            ),
            'vito_ai' => array(
                'project' => self::VITO_AI_PROJECT,
                'worker' => self::VITO_AI_WORKER_NAME,
                'deploy_enabled' => false,
            ),
            'arbitrary_provider_requests' => false,
            'secret_values_returned' => false,
        ));
    }

    public static function vault_put(WP_REST_Request $request) {
        $body = (array) $request->get_json_params();
        $name = self::validate_secret_name($body['name'] ?? '');
        $value = isset($body['value']) ? (string) $body['value'] : '';
        if ($name === '' || $value === '' || strlen($value) > 8192) {
            return new WP_Error('mr_vault_put_invalid', 'Secret non valido.', array('status' => 400));
        }
        $sha = hash('sha256', $value);
        if (($body['confirm'] ?? '') !== 'PUT-VAULT:' . $name . ':' . $sha) {
            return new WP_Error('mr_vault_confirm', 'Conferma vault non valida.', array('status' => 400));
        }
        if ($name === 'stripe_secret_key' && !preg_match('/^rk_live_[A-Za-z0-9_]+$/', $value)) {
            return new WP_Error('mr_vault_stripe_key', 'Usa una restricted Stripe LIVE key rk_live_....', array('status' => 400));
        }
        $encrypted = self::encrypt_value($value);
        if (is_wp_error($encrypted)) { return $encrypted; }
        $rows = self::vault_rows();
        $rows[$name] = $encrypted;
        update_option(self::VAULT_OPTION, $rows, false);
        MR_Bridge::log('vault_secret_put', array('name' => $name, 'sha256_prefix' => substr($sha, 0, 12)));
        return rest_ensure_response(array('ok' => true, 'name' => $name, 'sha256_prefix' => substr($sha, 0, 12), 'stored_encrypted' => true));
    }

    public static function vault_delete(WP_REST_Request $request) {
        $body = (array) $request->get_json_params();
        $name = self::validate_secret_name($body['name'] ?? '');
        if ($name === '' || ($body['confirm'] ?? '') !== 'DELETE-VAULT:' . $name) {
            return new WP_Error('mr_vault_delete_invalid', 'Conferma vault non valida.', array('status' => 400));
        }
        $rows = self::vault_rows();
        unset($rows[$name]);
        update_option(self::VAULT_OPTION, $rows, false);
        MR_Bridge::log('vault_secret_deleted', array('name' => $name));
        return rest_ensure_response(array('ok' => true, 'name' => $name, 'deleted' => true));
    }

    public static function cloudflare_configure(WP_REST_Request $request) {
        $body = (array) $request->get_json_params();
        $account_id = self::account_id($body['account_id'] ?? '');
        if ($account_id === '' || ($body['confirm'] ?? '') !== 'CONFIGURE-CLOUDFLARE:' . $account_id . ':' . self::WORKER_NAME) {
            return new WP_Error('mr_cloudflare_config_invalid', 'Configurazione Cloudflare non valida.', array('status' => 400));
        }
        $cfg = self::config();
        $cfg['cloudflare_account_id'] = $account_id;
        $cfg['worker_name'] = self::WORKER_NAME;
        $cfg['updated_at'] = current_time('mysql', true);
        self::save_config($cfg);
        MR_Bridge::log('cloudflare_configured', array('account_id_sha256_prefix' => substr(hash('sha256', $account_id), 0, 12), 'worker' => self::WORKER_NAME));
        return rest_ensure_response(array('ok' => true, 'worker' => self::WORKER_NAME, 'account_id_sha256_prefix' => substr(hash('sha256', $account_id), 0, 12)));
    }

    public static function cloudflare_verify() {
        $cfg = self::config();
        $account_id = self::account_id($cfg['cloudflare_account_id'] ?? '');
        if ($account_id === '') { return new WP_Error('mr_cloudflare_not_configured', 'Account Cloudflare non configurato.', array('status' => 409)); }
        $payload = self::cloudflare_request('GET', '/accounts/' . rawurlencode($account_id) . '/workers/scripts/' . rawurlencode(self::WORKER_NAME) . '/secrets');
        if (is_wp_error($payload)) { return $payload; }
        $names = array();
        foreach ((array) ($payload['result'] ?? array()) as $row) {
            if (is_array($row) && !empty($row['name'])) { $names[] = sanitize_key((string) $row['name']); }
        }
        MR_Bridge::log('cloudflare_verify', array('worker' => self::WORKER_NAME, 'secret_count' => count($names)));
        return rest_ensure_response(array('ok' => true, 'worker' => self::WORKER_NAME, 'secret_names' => array_values($names)));
    }

    public static function cloudflare_inventory() {
        $cfg = self::config();
        $account_id = self::account_id($cfg['cloudflare_account_id'] ?? '');
        if ($account_id === '') { return new WP_Error('mr_cloudflare_not_configured', 'Account Cloudflare non configurato.', array('status' => 409)); }
        $payload = self::cloudflare_request('GET', '/accounts/' . rawurlencode($account_id) . '/workers/scripts');
        if (is_wp_error($payload)) { return $payload; }
        $found = array();
        foreach (self::worker_allowlist() as $name) { $found[$name] = false; }
        foreach ((array) ($payload['result'] ?? array()) as $row) {
            if (!is_array($row)) { continue; }
            $name = (string) ($row['id'] ?? $row['name'] ?? '');
            if (array_key_exists($name, $found)) { $found[$name] = true; }
        }
        MR_Bridge::log('cloudflare_inventory_read', array('allowlisted_workers' => array_keys($found), 'found' => $found));
        return rest_ensure_response(array(
            'ok' => true,
            'allowlisted_workers' => array_keys($found),
            'found' => $found,
            'vito_ai_deploy_enabled' => false,
            'secret_values_returned' => false,
        ));
    }

    public static function cloudflare_worker_secret(WP_REST_Request $request) {
        $body = (array) $request->get_json_params();
        $binding = strtoupper(trim((string) ($body['binding'] ?? '')));
        $source = self::validate_secret_name($body['source_secret'] ?? '');
        if ($binding !== 'STRIPE_SECRET_KEY' || $source !== 'stripe_secret_key') {
            return new WP_Error('mr_cloudflare_binding_denied', 'Binding Cloudflare fuori allowlist.', array('status' => 403));
        }
        if (($body['confirm'] ?? '') !== 'PUT-WORKER-SECRET:' . self::PROJECT . ':' . self::WORKER_NAME . ':' . $binding) {
            return new WP_Error('mr_cloudflare_confirm', 'Conferma Worker secret non valida.', array('status' => 400));
        }
        $cfg = self::config();
        $account_id = self::account_id($cfg['cloudflare_account_id'] ?? '');
        if ($account_id === '') { return new WP_Error('mr_cloudflare_not_configured', 'Account Cloudflare non configurato.', array('status' => 409)); }
        $secret = self::decrypt_value($source);
        if (is_wp_error($secret)) { return $secret; }
        $payload = self::cloudflare_request('PUT', '/accounts/' . rawurlencode($account_id) . '/workers/scripts/' . rawurlencode(self::WORKER_NAME) . '/secrets', array(
            'name' => $binding,
            'text' => $secret,
            'type' => 'secret_text',
        ));
        sodium_memzero($secret);
        if (is_wp_error($payload)) { return $payload; }
        MR_Bridge::log('cloudflare_worker_secret_put', array('project' => self::PROJECT, 'worker' => self::WORKER_NAME, 'binding' => $binding, 'source' => $source));
        return rest_ensure_response(array('ok' => true, 'project' => self::PROJECT, 'worker' => self::WORKER_NAME, 'binding' => $binding, 'secret_value_returned' => false));
    }
}
