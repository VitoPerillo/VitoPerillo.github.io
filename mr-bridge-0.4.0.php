<?php
/**
 * Plugin Name: MR Bridge
 * Description: Ponte operativo sicuro e riutilizzabile per WordPress via REST API.
 * Version: 0.4.0
 * Author: MR Bridge
 */
if (!defined('ABSPATH')) { exit; }

final class MR_Bridge {
    const VERSION = '0.4.0';
    const REST_NAMESPACE = 'mr-bridge/v1';
    const ENABLED_OPTION = 'mr_bridge_enabled';
    const LOG_OPTION = 'mr_bridge_audit_log';
    const PAGE_BACKUPS_OPTION = 'mr_bridge_page_backups';
    const PAGE_SLUG = 'affitto-sala-yoga-a-roma-per-corsi-eventi-olistici';
    const STAGING_HOME = 'https://www.yoganostress.it/staging-gestionale';
    const OIDC_AUD = 'https://www.yoganostress.it/staging-gestionale/mr-bridge';
    const OIDC_REPOSITORY = 'VitoPerillo/yoganostress-wordpress-bridge';
    const OIDC_REPOSITORY_ID = '1390938875';
    const OIDC_OWNER_ID = '317205417';
    const OIDC_REF = 'refs/heads/yns-whatsapp-api';
    const OIDC_WORKFLOW_REF = 'VitoPerillo/yoganostress-wordpress-bridge/.github/workflows/yns-whatsapp-autonomous-staging.yml@refs/heads/yns-whatsapp-api';
    const WA_SLUG = 'yns-whatsapp-api';
    const WA_MAIN = 'yns-whatsapp-api/yns-whatsapp-api.php';
    const WA_PACKAGE_URL = 'https://raw.githubusercontent.com/VitoPerillo/VitoPerillo.github.io/yns-whatsapp-api-deploy/yns-whatsapp-api.zip';

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
    }

    public static function activate() {
        if (get_option(self::ENABLED_OPTION, null) === null) {
            add_option(self::ENABLED_OPTION, '1', '', false);
        }
        if (get_option(self::PAGE_BACKUPS_OPTION, null) === null) {
            add_option(self::PAGE_BACKUPS_OPTION, array(), '', false);
        }
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/status', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'status'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/plugins', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'plugins'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/audit', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'audit'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/social-audit', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'social_audit'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/page', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'page_read'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/page/backups', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'page_backups'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/page/backup', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'page_backup'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/page/update', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'page_update'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/page/rollback', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'page_rollback'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/staging-deploy/health', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'staging_deploy_health'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/staging-deploy/command', array(
            'methods' => 'POST', 'callback' => array(__CLASS__, 'staging_deploy_command'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function can_manage() {
        return self::enabled() && current_user_can('manage_options');
    }

    private static function enabled() {
        return get_option(self::ENABLED_OPTION, '1') === '1';
    }

    private static function log($action, $meta = array()) {
        $log = get_option(self::LOG_OPTION, array());
        if (!is_array($log)) { $log = array(); }
        $log[] = array(
            'time' => current_time('mysql', true),
            'user_id' => get_current_user_id(),
            'action' => sanitize_key($action),
            'meta' => $meta,
        );
        if (count($log) > 100) { $log = array_slice($log, -100); }
        update_option(self::LOG_OPTION, $log, false);
    }

    public static function status() {
        self::log('status_read');
        return rest_ensure_response(array(
            'ok' => true,
            'bridge' => 'mr-bridge',
            'version' => self::VERSION,
            'site' => home_url('/'),
            'enabled' => self::enabled(),
            'capabilities' => array(
                'status',
                'plugin_inventory',
                'audit_log',
                'social_plugin_audit',
                'staging_oidc_deploy',
                'allowlisted_page_read',
                'allowlisted_page_backup',
                'allowlisted_page_update',
                'allowlisted_page_rollback'
            ),
            'page_allowlist' => array(self::PAGE_SLUG),
        ));
    }

    public static function plugins() {
        if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $active = (array) get_option('active_plugins', array());
        $items = array();
        foreach (get_plugins() as $file => $data) {
            $items[] = array(
                'file' => $file,
                'name' => isset($data['Name']) ? $data['Name'] : $file,
                'version' => isset($data['Version']) ? $data['Version'] : '',
                'active' => in_array($file, $active, true),
            );
        }
        self::log('plugin_inventory_read', array('count' => count($items)));
        return rest_ensure_response(array('plugins' => $items));
    }

    public static function audit() {
        return rest_ensure_response(array('events' => array_reverse((array) get_option(self::LOG_OPTION, array()))));
    }

    public static function social_audit() {
        if (!function_exists('get_plugins')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
        $plugins = get_plugins();
        $active = (array) get_option('active_plugins', array());
        $matches = array();
        foreach ($plugins as $file => $data) {
            $haystack = strtolower($file . ' ' . (isset($data['Name']) ? $data['Name'] : ''));
            if (strpos($haystack, 'fs-poster') !== false || strpos($haystack, 'fs poster') !== false || strpos($haystack, 'jetpack') !== false) {
                $matches[] = array(
                    'file' => $file,
                    'name' => isset($data['Name']) ? $data['Name'] : $file,
                    'version' => isset($data['Version']) ? $data['Version'] : '',
                    'active' => in_array($file, $active, true),
                );
            }
        }
        self::log('social_plugin_audit', array('matches' => count($matches)));
        return rest_ensure_response(array(
            'read_only' => true,
            'plugins' => $matches,
            'note' => 'No SEO, content, indexing or plugin settings are modified by this endpoint.',
        ));
    }


    private static function page_slug_from_request(WP_REST_Request $request) {
        $slug = sanitize_title((string) $request->get_param('slug'));
        if (!$slug) {
            $body = $request->get_json_params();
            if (is_array($body)) {
                $slug = sanitize_title((string) ($body['slug'] ?? ''));
            }
        }
        if ($slug !== self::PAGE_SLUG) {
            return new WP_Error('mr_page_slug_denied', 'Pagina non autorizzata.', array('status' => 403));
        }
        return $slug;
    }

    private static function get_allowed_page($slug) {
        if ($slug !== self::PAGE_SLUG) {
            return new WP_Error('mr_page_slug_denied', 'Pagina non autorizzata.', array('status' => 403));
        }
        $post = get_page_by_path($slug, OBJECT, 'page');
        if (!$post || $post->post_type !== 'page') {
            return new WP_Error('mr_page_not_found', 'Pagina autorizzata non trovata.', array('status' => 404));
        }
        return $post;
    }

    private static function active_seo_plugin() {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (is_plugin_active('wordpress-seo/wp-seo.php') || is_plugin_active('wordpress-seo-premium/wp-seo-premium.php')) {
            return 'yoast';
        }
        if (is_plugin_active('seo-by-rank-math/rank-math.php')) {
            return 'rank-math';
        }
        return 'none';
    }

    private static function seo_snapshot($post_id) {
        return array(
            'plugin' => self::active_seo_plugin(),
            'yoast_title' => (string) get_post_meta($post_id, '_yoast_wpseo_title', true),
            'yoast_description' => (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
            'yoast_focus_keyword' => (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true),
            'rank_math_title' => (string) get_post_meta($post_id, 'rank_math_title', true),
            'rank_math_description' => (string) get_post_meta($post_id, 'rank_math_description', true),
            'rank_math_focus_keyword' => (string) get_post_meta($post_id, 'rank_math_focus_keyword', true),
        );
    }

    private static function page_payload($post, $include_content = true) {
        $data = array(
            'id' => (int) $post->ID,
            'slug' => (string) $post->post_name,
            'status' => (string) $post->post_status,
            'title' => (string) $post->post_title,
            'excerpt' => (string) $post->post_excerpt,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'permalink' => get_permalink($post),
            'content_sha256' => hash('sha256', (string) $post->post_content),
            'seo' => self::seo_snapshot($post->ID),
        );
        if ($include_content) {
            $data['content'] = (string) $post->post_content;
        }
        return $data;
    }

    private static function backup_store() {
        $items = get_option(self::PAGE_BACKUPS_OPTION, array());
        return is_array($items) ? $items : array();
    }

    private static function save_page_backup($post, $reason) {
        $items = self::backup_store();
        $backup_id = 'mr-page-' . gmdate('YmdHis') . '-' . strtolower(wp_generate_password(6, false, false));
        $items[] = array(
            'backup_id' => $backup_id,
            'created_at' => current_time('mysql', true),
            'reason' => sanitize_key($reason),
            'page' => self::page_payload($post, true),
        );
        if (count($items) > 10) {
            $items = array_slice($items, -10);
        }
        update_option(self::PAGE_BACKUPS_OPTION, $items, false);
        self::log('page_backup_created', array(
            'slug' => self::PAGE_SLUG,
            'backup_id' => $backup_id,
            'reason' => sanitize_key($reason),
            'content_sha256' => hash('sha256', (string) $post->post_content),
        ));
        return $backup_id;
    }

    private static function find_page_backup($backup_id) {
        foreach (self::backup_store() as $item) {
            if (!empty($item['backup_id']) && hash_equals((string) $item['backup_id'], (string) $backup_id)) {
                return $item;
            }
        }
        return new WP_Error('mr_page_backup_missing', 'Backup non trovato.', array('status' => 404));
    }

    private static function validate_page_content($content) {
        if (!is_string($content) || trim($content) === '') {
            return new WP_Error('mr_page_content_empty', 'Contenuto pagina vuoto.', array('status' => 400));
        }
        if (strlen($content) > 250000) {
            return new WP_Error('mr_page_content_too_large', 'Contenuto pagina troppo grande.', array('status' => 413));
        }
        $blocked = array('<?', '<script', 'javascript:', 'onerror=', 'onload=');
        $lower = strtolower($content);
        foreach ($blocked as $needle) {
            if (strpos($lower, $needle) !== false) {
                return new WP_Error('mr_page_content_denied', 'Contenuto non consentito dal bridge.', array('status' => 400, 'pattern' => $needle));
            }
        }
        return true;
    }

    private static function update_page_seo($post_id, $seo_title, $meta_description, $focus_keyword) {
        $plugin = self::active_seo_plugin();
        if ($plugin === 'yoast') {
            update_post_meta($post_id, '_yoast_wpseo_title', sanitize_text_field($seo_title));
            update_post_meta($post_id, '_yoast_wpseo_metadesc', sanitize_text_field($meta_description));
            update_post_meta($post_id, '_yoast_wpseo_focuskw', sanitize_text_field($focus_keyword));
        } elseif ($plugin === 'rank-math') {
            update_post_meta($post_id, 'rank_math_title', sanitize_text_field($seo_title));
            update_post_meta($post_id, 'rank_math_description', sanitize_text_field($meta_description));
            update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field($focus_keyword));
        } else {
            return new WP_Error('mr_page_seo_plugin_missing', 'Nessun plugin SEO supportato attivo.', array('status' => 409));
        }
        return $plugin;
    }

    private static function restore_page_seo($post_id, $seo) {
        $map = array(
            '_yoast_wpseo_title' => 'yoast_title',
            '_yoast_wpseo_metadesc' => 'yoast_description',
            '_yoast_wpseo_focuskw' => 'yoast_focus_keyword',
            'rank_math_title' => 'rank_math_title',
            'rank_math_description' => 'rank_math_description',
            'rank_math_focus_keyword' => 'rank_math_focus_keyword',
        );
        foreach ($map as $meta_key => $snapshot_key) {
            if (array_key_exists($snapshot_key, (array) $seo)) {
                update_post_meta($post_id, $meta_key, (string) $seo[$snapshot_key]);
            }
        }
    }

    private static function synthetic_request($method, $body) {
        $request = new WP_REST_Request($method, '/');
        $request->set_header('content-type', 'application/json');
        $request->set_body(wp_json_encode((array) $body));
        foreach ((array) $body as $key => $value) {
            $request->set_param($key, $value);
        }
        return $request;
    }

    private static function unwrap_response($result) {
        if ($result instanceof WP_REST_Response) {
            return $result->get_data();
        }
        return $result;
    }

    public static function page_read(WP_REST_Request $request) {
        $slug = self::page_slug_from_request($request);
        if (is_wp_error($slug)) { return $slug; }
        $post = self::get_allowed_page($slug);
        if (is_wp_error($post)) { return $post; }
        self::log('page_read', array(
            'slug' => $slug,
            'content_sha256' => hash('sha256', (string) $post->post_content),
        ));
        return rest_ensure_response(array('ok' => true, 'page' => self::page_payload($post, true)));
    }

    public static function page_backups(WP_REST_Request $request) {
        $slug = self::page_slug_from_request($request);
        if (is_wp_error($slug)) { return $slug; }
        $items = array();
        foreach (array_reverse(self::backup_store()) as $item) {
            if (($item['page']['slug'] ?? '') !== $slug) { continue; }
            $items[] = array(
                'backup_id' => $item['backup_id'] ?? '',
                'created_at' => $item['created_at'] ?? '',
                'reason' => $item['reason'] ?? '',
                'content_sha256' => $item['page']['content_sha256'] ?? '',
                'modified_gmt' => $item['page']['modified_gmt'] ?? '',
            );
        }
        return rest_ensure_response(array('ok' => true, 'backups' => $items));
    }

    public static function page_backup(WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new WP_Error('mr_bad_json', 'Payload JSON non valido.', array('status' => 400));
        }
        $slug = self::page_slug_from_request($request);
        if (is_wp_error($slug)) { return $slug; }
        if (($body['confirm'] ?? '') !== 'BACKUP:' . $slug) {
            return new WP_Error('mr_page_confirm_required', 'Conferma backup non valida.', array('status' => 400));
        }
        $post = self::get_allowed_page($slug);
        if (is_wp_error($post)) { return $post; }
        $backup_id = self::save_page_backup($post, 'manual');
        return rest_ensure_response(array(
            'ok' => true,
            'backup_id' => $backup_id,
            'page' => self::page_payload($post, false),
        ));
    }

    public static function page_update(WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new WP_Error('mr_bad_json', 'Payload JSON non valido.', array('status' => 400));
        }
        $slug = self::page_slug_from_request($request);
        if (is_wp_error($slug)) { return $slug; }
        if (($body['confirm'] ?? '') !== 'UPDATE:' . $slug) {
            return new WP_Error('mr_page_confirm_required', 'Conferma aggiornamento non valida.', array('status' => 400));
        }
        $post = self::get_allowed_page($slug);
        if (is_wp_error($post)) { return $post; }

        $expected_modified_gmt = (string) ($body['expected_modified_gmt'] ?? '');
        if ($expected_modified_gmt === '' || !hash_equals((string) $post->post_modified_gmt, $expected_modified_gmt)) {
            return new WP_Error('mr_page_conflict', 'La pagina è cambiata dopo il readback; aggiornamento rifiutato.', array(
                'status' => 409,
                'current_modified_gmt' => (string) $post->post_modified_gmt,
            ));
        }

        $content = isset($body['content']) ? (string) $body['content'] : '';
        $valid = self::validate_page_content($content);
        if (is_wp_error($valid)) { return $valid; }

        $new_hash = hash('sha256', $content);
        $old_hash = hash('sha256', (string) $post->post_content);
        $seo_requested = array_key_exists('seo_title', $body)
            || array_key_exists('meta_description', $body)
            || array_key_exists('focus_keyword', $body);
        $dry_run = !empty($body['dry_run']);

        if ($dry_run) {
            return rest_ensure_response(array(
                'ok' => true,
                'dry_run' => true,
                'slug' => $slug,
                'would_change_content' => !hash_equals($old_hash, $new_hash),
                'old_content_sha256' => $old_hash,
                'new_content_sha256' => $new_hash,
                'seo_plugin' => self::active_seo_plugin(),
                'seo_requested' => $seo_requested,
            ));
        }

        if ($seo_requested && self::active_seo_plugin() === 'none') {
            return new WP_Error('mr_page_seo_plugin_missing', 'Nessun plugin SEO supportato attivo.', array('status' => 409));
        }

        $backup_id = self::save_page_backup($post, 'pre_update');
        $result = wp_update_post(wp_slash(array(
            'ID' => (int) $post->ID,
            'post_content' => $content,
        )), true);
        if (is_wp_error($result)) { return $result; }

        $seo_plugin = self::active_seo_plugin();
        if ($seo_requested) {
            $seo_result = self::update_page_seo(
                $post->ID,
                (string) ($body['seo_title'] ?? ''),
                (string) ($body['meta_description'] ?? ''),
                (string) ($body['focus_keyword'] ?? '')
            );
            if (is_wp_error($seo_result)) {
                $backup = self::find_page_backup($backup_id);
                if (!is_wp_error($backup)) {
                    wp_update_post(wp_slash(array(
                        'ID' => (int) $post->ID,
                        'post_content' => (string) $backup['page']['content'],
                    )));
                    self::restore_page_seo($post->ID, (array) ($backup['page']['seo'] ?? array()));
                }
                return $seo_result;
            }
            $seo_plugin = $seo_result;
        }

        clean_post_cache($post->ID);
        $updated = get_post($post->ID);
        self::log('page_update', array(
            'slug' => $slug,
            'backup_id' => $backup_id,
            'old_content_sha256' => $old_hash,
            'new_content_sha256' => hash('sha256', (string) $updated->post_content),
            'seo_plugin' => $seo_plugin,
        ));

        return rest_ensure_response(array(
            'ok' => true,
            'backup_id' => $backup_id,
            'page' => self::page_payload($updated, false),
        ));
    }

    public static function page_rollback(WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new WP_Error('mr_bad_json', 'Payload JSON non valido.', array('status' => 400));
        }
        $backup_id = sanitize_text_field((string) ($body['backup_id'] ?? ''));
        if ($backup_id === '' || ($body['confirm'] ?? '') !== 'ROLLBACK:' . $backup_id) {
            return new WP_Error('mr_page_confirm_required', 'Conferma rollback non valida.', array('status' => 400));
        }
        $backup = self::find_page_backup($backup_id);
        if (is_wp_error($backup)) { return $backup; }
        if (($backup['page']['slug'] ?? '') !== self::PAGE_SLUG) {
            return new WP_Error('mr_page_backup_denied', 'Backup fuori allowlist.', array('status' => 403));
        }

        $post = self::get_allowed_page(self::PAGE_SLUG);
        if (is_wp_error($post)) { return $post; }

        $result = wp_update_post(wp_slash(array(
            'ID' => (int) $post->ID,
            'post_content' => (string) $backup['page']['content'],
            'post_title' => (string) $backup['page']['title'],
            'post_excerpt' => (string) $backup['page']['excerpt'],
        )), true);
        if (is_wp_error($result)) { return $result; }
        self::restore_page_seo($post->ID, (array) ($backup['page']['seo'] ?? array()));
        clean_post_cache($post->ID);
        $restored = get_post($post->ID);
        self::log('page_rollback', array(
            'slug' => self::PAGE_SLUG,
            'backup_id' => $backup_id,
            'content_sha256' => hash('sha256', (string) $restored->post_content),
        ));

        return rest_ensure_response(array(
            'ok' => true,
            'rolled_back' => true,
            'backup_id' => $backup_id,
            'page' => self::page_payload($restored, false),
        ));
    }

    private static function staging_only() {
        return untrailingslashit(home_url('/')) === self::STAGING_HOME;
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
            $headers = apache_request_headers();
            foreach ((array) $headers as $k => $v) {
                if (strtolower((string) $k) === 'authorization') {
                    return trim((string) $v);
                }
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
        if (!self::staging_only()) {
            return new WP_Error('mr_staging_only', 'Canale deploy disponibile solo sullo staging.', array('status' => 403));
        }
        $auth = self::auth_header();
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return new WP_Error('mr_oidc_missing', 'Token OIDC mancante.', array('status' => 401));
        }
        $jwt = trim($m[1]);
        $parts = explode('.', $jwt);
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
        $verified = openssl_verify($parts[0] . '.' . $parts[1], $sig, $cert, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            delete_transient('mr_bridge_github_jwks');
            return new WP_Error('mr_oidc_bad_signature', 'Firma OIDC non valida.', array('status' => 401));
        }
        $now = time();
        $aud = $claims['aud'] ?? '';
        $aud_ok = is_array($aud) ? in_array(self::OIDC_AUD, $aud, true) : hash_equals(self::OIDC_AUD, (string) $aud);
        $checks = array(
            'iss' => (($claims['iss'] ?? '') === 'https://token.actions.githubusercontent.com'),
            'aud' => $aud_ok,
            'exp' => (!empty($claims['exp']) && (int) $claims['exp'] >= $now - 30),
            'nbf' => (empty($claims['nbf']) || (int) $claims['nbf'] <= $now + 30),
            'repository' => (($claims['repository'] ?? '') === self::OIDC_REPOSITORY),
            'repository_id' => ((string) ($claims['repository_id'] ?? '') === self::OIDC_REPOSITORY_ID),
            'repository_owner_id' => ((string) ($claims['repository_owner_id'] ?? '') === self::OIDC_OWNER_ID),
            'ref' => (($claims['ref'] ?? '') === self::OIDC_REF),
            'workflow_ref' => (($claims['workflow_ref'] ?? '') === self::OIDC_WORKFLOW_REF),
            'event_name' => (($claims['event_name'] ?? '') === 'push'),
        );
        foreach ($checks as $name => $ok) {
            if (!$ok) {
                return new WP_Error('mr_oidc_claim_' . $name, 'Claim OIDC rifiutato: ' . $name, array('status' => 403));
            }
        }
        return $claims;
    }

    private static function backup_root() {
        $root = WP_CONTENT_DIR . '/mr-bridge-backups';
        if (!is_dir($root) && !wp_mkdir_p($root)) {
            return new WP_Error('mr_backup_root_failed', 'Impossibile creare directory backup.', array('status' => 500));
        }
        if (!file_exists($root . '/index.php')) {
            @file_put_contents($root . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!file_exists($root . '/.htaccess')) {
            @file_put_contents($root . '/.htaccess', "Deny from all\n");
        }
        return $root;
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

    private static function write_manifest($backup_dir, $data) {
        return (bool) @file_put_contents(
            $backup_dir . '/manifest.json',
            wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private static function read_manifest($backup_id) {
        if (!preg_match('/^yns-wa-[a-z0-9._-]+$/', (string) $backup_id)) {
            return new WP_Error('mr_backup_id_invalid', 'Backup ID non valido.', array('status' => 400));
        }
        $root = self::backup_root();
        if (is_wp_error($root)) { return $root; }
        $dir = $root . '/' . $backup_id;
        $file = $dir . '/manifest.json';
        if (!is_file($file)) {
            return new WP_Error('mr_backup_missing', 'Backup non trovato.', array('status' => 404));
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || ($data['slug'] ?? '') !== self::WA_SLUG) {
            return new WP_Error('mr_backup_invalid', 'Manifest backup non valido.', array('status' => 409));
        }
        return array($dir, $data);
    }

    private static function rollback_internal($backup_id, $automatic = false) {
        if (!function_exists('deactivate_plugins') || !function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $manifest = self::read_manifest($backup_id);
        if (is_wp_error($manifest)) { return $manifest; }
        list($backup_dir, $data) = $manifest;
        $target = WP_PLUGIN_DIR . '/' . self::WA_SLUG;
        if (is_plugin_active(self::WA_MAIN)) {
            deactivate_plugins(self::WA_MAIN, true, false);
        }
        if (is_dir($target) && !self::remove_tree($target)) {
            return new WP_Error('mr_rollback_remove_failed', 'Impossibile rimuovere il deploy corrente.', array('status' => 500));
        }
        if (!empty($data['existed'])) {
            $saved = $backup_dir . '/plugin';
            if (!is_dir($saved) || !@rename($saved, $target)) {
                return new WP_Error('mr_rollback_restore_failed', 'Impossibile ripristinare il backup.', array('status' => 500));
            }
            if (!empty($data['was_active'])) {
                $act = activate_plugin(self::WA_MAIN, '', false, false);
                if (is_wp_error($act)) { return $act; }
            }
        }
        $data['status'] = 'rolled_back';
        $data['rolled_back_at'] = current_time('mysql', true);
        $data['automatic'] = (bool) $automatic;
        self::write_manifest($backup_dir, $data);
        self::log('staging_deploy_rollback', array('backup_id' => $backup_id, 'automatic' => (bool) $automatic));
        return array('ok' => true, 'backup_id' => $backup_id, 'rolled_back' => true);
    }

    private static function deploy_whatsapp($body) {
        if (!self::staging_only()) {
            return new WP_Error('mr_staging_only', 'Deploy rifiutato fuori dallo staging.', array('status' => 403));
        }
        $slug = sanitize_key($body['slug'] ?? '');
        $url = esc_url_raw($body['package_url'] ?? '');
        $sha = strtolower(trim((string) ($body['sha256'] ?? '')));
        $version = trim((string) ($body['version'] ?? ''));
        if ($slug !== self::WA_SLUG) {
            return new WP_Error('mr_slug_denied', 'Slug non autorizzato.', array('status' => 403));
        }
        if ($url !== self::WA_PACKAGE_URL) {
            return new WP_Error('mr_package_url_denied', 'Package URL non autorizzato.', array('status' => 403));
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
            return new WP_Error('mr_sha_invalid', 'SHA-256 non valido.', array('status' => 400));
        }
        if ($version !== '0.2.1') {
            return new WP_Error('mr_version_denied', 'Versione non autorizzata.', array('status' => 403));
        }

        $res = wp_remote_get($url, array(
            'timeout' => 30,
            'redirection' => 0,
            'limit_response_size' => 2 * 1024 * 1024,
            'headers' => array('Accept' => 'application/zip', 'Cache-Control' => 'no-cache'),
        ));
        if (is_wp_error($res)) { return $res; }
        if ((int) wp_remote_retrieve_response_code($res) !== 200) {
            return new WP_Error('mr_package_http', 'Download pacchetto fallito.', array('status' => 502));
        }
        $raw = (string) wp_remote_retrieve_body($res);
        if ($raw === '' || strlen($raw) > 2 * 1024 * 1024) {
            return new WP_Error('mr_package_size', 'Dimensione pacchetto non valida.', array('status' => 409));
        }
        $actual_sha = hash('sha256', $raw);
        if (!hash_equals($sha, $actual_sha)) {
            return new WP_Error('mr_package_sha_mismatch', 'SHA-256 pacchetto non corrispondente.', array('status' => 409));
        }
        if (!class_exists('ZipArchive')) {
            return new WP_Error('mr_zip_missing', 'ZipArchive non disponibile.', array('status' => 500));
        }

        $tmp_root = WP_CONTENT_DIR . '/mr-bridge-tmp';
        if (!is_dir($tmp_root) && !wp_mkdir_p($tmp_root)) {
            return new WP_Error('mr_tmp_failed', 'Impossibile creare directory temporanea.', array('status' => 500));
        }
        $token = strtolower(wp_generate_password(10, false, false));
        $zip_path = $tmp_root . '/yns-wa-' . $token . '.zip';
        $extract_dir = $tmp_root . '/yns-wa-' . $token;
        if (@file_put_contents($zip_path, $raw) === false || !wp_mkdir_p($extract_dir)) {
            return new WP_Error('mr_tmp_write_failed', 'Impossibile preparare il pacchetto.', array('status' => 500));
        }

        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            @unlink($zip_path);
            return new WP_Error('mr_zip_open_failed', 'ZIP non valido.', array('status' => 409));
        }
        $main_found = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '' || strpos($name, "\0") !== false || strpos($name, '../') !== false || strpos($name, '..\\') !== false || $name[0] === '/' || strpos($name, self::WA_SLUG . '/') !== 0) {
                $zip->close();
                @unlink($zip_path);
                self::remove_tree($extract_dir);
                return new WP_Error('mr_zip_path_denied', 'Struttura ZIP non autorizzata.', array('status' => 409));
            }
            if ($name === self::WA_MAIN) { $main_found = true; }
        }
        if (!$main_found || !$zip->extractTo($extract_dir)) {
            $zip->close();
            @unlink($zip_path);
            self::remove_tree($extract_dir);
            return new WP_Error('mr_zip_extract_failed', 'Main file assente o estrazione fallita.', array('status' => 409));
        }
        $zip->close();
        @unlink($zip_path);

        $new_root = $extract_dir . '/' . self::WA_SLUG;
        $main = $new_root . '/yns-whatsapp-api.php';
        $header = is_file($main) ? (string) file_get_contents($main) : '';
        if (strpos($header, 'Plugin Name: YNS WhatsApp API') === false || strpos($header, 'Version: 0.2.1') === false) {
            self::remove_tree($extract_dir);
            return new WP_Error('mr_plugin_header_invalid', 'Header plugin non valido.', array('status' => 409));
        }

        if (!function_exists('get_plugins') || !function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $backup_root = self::backup_root();
        if (is_wp_error($backup_root)) {
            self::remove_tree($extract_dir);
            return $backup_root;
        }
        $backup_id = 'yns-wa-' . gmdate('YmdHis') . '-' . strtolower(wp_generate_password(6, false, false));
        $backup_dir = $backup_root . '/' . $backup_id;
        if (!wp_mkdir_p($backup_dir)) {
            self::remove_tree($extract_dir);
            return new WP_Error('mr_backup_create_failed', 'Creazione backup fallita.', array('status' => 500));
        }
        $target = WP_PLUGIN_DIR . '/' . self::WA_SLUG;
        $existed = is_dir($target);
        $was_active = is_plugin_active(self::WA_MAIN);
        $manifest = array(
            'schema' => 1,
            'backup_id' => $backup_id,
            'slug' => self::WA_SLUG,
            'created_at' => current_time('mysql', true),
            'existed' => $existed,
            'was_active' => $was_active,
            'incoming_version' => $version,
            'incoming_sha256' => $actual_sha,
            'status' => 'prepared',
        );
        if (!self::write_manifest($backup_dir, $manifest)) {
            self::remove_tree($extract_dir);
            self::remove_tree($backup_dir);
            return new WP_Error('mr_manifest_failed', 'Scrittura manifest backup fallita.', array('status' => 500));
        }

        if ($existed) {
            if ($was_active) { deactivate_plugins(self::WA_MAIN, true, false); }
            if (!@rename($target, $backup_dir . '/plugin')) {
                self::remove_tree($extract_dir);
                return new WP_Error('mr_backup_move_failed', 'Backup plugin esistente fallito.', array('status' => 500));
            }
        }
        if (!@rename($new_root, $target)) {
            if ($existed && is_dir($backup_dir . '/plugin')) { @rename($backup_dir . '/plugin', $target); }
            self::remove_tree($extract_dir);
            return new WP_Error('mr_deploy_move_failed', 'Installazione atomica fallita.', array('status' => 500));
        }
        self::remove_tree($extract_dir);

        $act = activate_plugin(self::WA_MAIN, '', false, true);
        if (is_wp_error($act)) {
            self::rollback_internal($backup_id, true);
            return new WP_Error('mr_activate_failed', 'Attivazione fallita; rollback automatico eseguito.', array('status' => 500, 'reason' => $act->get_error_message()));
        }

        if (!class_exists('YNS_WhatsApp_API')) {
            self::rollback_internal($backup_id, true);
            return new WP_Error('mr_health_class_missing', 'Classe WhatsApp assente; rollback automatico eseguito.', array('status' => 500));
        }
        YNS_WhatsApp_API::activate();
        $health = YNS_WhatsApp_API::instance()->health();
        $health_data = ($health instanceof WP_REST_Response) ? $health->get_data() : null;
        $healthy = is_array($health_data)
            && !empty($health_data['ok'])
            && ($health_data['version'] ?? '') === $version
            && !empty($health_data['dry_run'])
            && empty($health_data['meta_configured']);
        if (!$healthy) {
            $diagnostic = array(
                'status' => 500,
                'health' => is_array($health_data) ? $health_data : null,
            );
            self::rollback_internal($backup_id, true);
            return new WP_Error('mr_health_failed', 'Health-check dry-run fallito; rollback automatico eseguito.', $diagnostic);
        }

        $manifest['status'] = 'deployed';
        $manifest['deployed_at'] = current_time('mysql', true);
        $manifest['health'] = $health_data;
        self::write_manifest($backup_dir, $manifest);
        self::log('staging_deploy_success', array('slug' => self::WA_SLUG, 'version' => $version, 'sha256' => $actual_sha, 'backup_id' => $backup_id));
        return array(
            'ok' => true,
            'environment' => 'staging',
            'slug' => self::WA_SLUG,
            'version' => $version,
            'sha256' => $actual_sha,
            'backup_id' => $backup_id,
            'active' => is_plugin_active(self::WA_MAIN),
            'health' => $health_data,
        );
    }

    public static function staging_deploy_health() {
        return rest_ensure_response(array(
            'ok' => self::staging_only(),
            'bridge' => 'mr-bridge',
            'version' => self::VERSION,
            'environment' => self::staging_only() ? 'staging' : 'denied',
            'auth' => 'github-oidc',
            'allowlist' => array(self::WA_SLUG),
            'sha256_required' => true,
            'backup' => true,
            'rollback' => true,
        ));
    }

    public static function staging_deploy_command(WP_REST_Request $request) {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new WP_Error('mr_bad_json', 'Payload JSON non valido.', array('status' => 400));
        }
        $action = sanitize_key($body['action'] ?? '');
        if ($action === 'deploy') {
            $result = self::deploy_whatsapp($body);
        } elseif ($action === 'rollback') {
            $result = self::rollback_internal((string) ($body['backup_id'] ?? ''), false);
        } elseif ($action === 'page_read') {
            $body['slug'] = self::PAGE_SLUG;
            $result = self::page_read(self::synthetic_request('GET', $body));
        } elseif ($action === 'page_backup') {
            $body['slug'] = self::PAGE_SLUG;
            $result = self::page_backup(self::synthetic_request('POST', $body));
        } elseif ($action === 'page_update') {
            $body['slug'] = self::PAGE_SLUG;
            $result = self::page_update(self::synthetic_request('POST', $body));
        } elseif ($action === 'page_rollback') {
            $body['slug'] = self::PAGE_SLUG;
            $result = self::page_rollback(self::synthetic_request('POST', $body));
        } elseif ($action === 'status') {
            $result = array(
                'ok' => true,
                'environment' => 'staging',
                'plugin_allowlist' => array(self::WA_SLUG),
                'page_allowlist' => array(self::PAGE_SLUG),
            );
        } else {
            return new WP_Error('mr_action_denied', 'Azione non consentita.', array('status' => 400));
        }
        if (is_wp_error($result)) { return $result; }
        $result = self::unwrap_response($result);
        return rest_ensure_response(array(
            'ok' => true,
            'action' => $action,
            'result' => $result,
            'run_id' => $claims['run_id'] ?? null,
            'actor_id' => $claims['actor_id'] ?? null,
        ));
    }

}
MR_Bridge::init();
