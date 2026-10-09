<?php
if (!defined('ABSPATH')) { exit; }

final class MR_Bridge_Content_V1 {
    const REST_NAMESPACE = 'mr-bridge/v1';
    const STAGING_HOME = 'https://www.yoganostress.it/staging-gestionale';
    const PRODUCTION_HOME = 'https://www.yoganostress.it';
    const OIDC_REPOSITORY = 'VitoPerillo/yoganostress-wordpress-bridge';
    const OIDC_REPOSITORY_ID = '1390938875';
    const OIDC_OWNER_ID = '317205417';
    const STAGING_REF = 'refs/heads/mr-bridge-1.0-rc12-unified';
    const PRODUCTION_REF = 'refs/heads/main';
    const STAGING_WORKFLOW_REF = 'VitoPerillo/yoganostress-wordpress-bridge/.github/workflows/mr-content-staging.yml@refs/heads/mr-bridge-1.0-rc12-unified';
    const PRODUCTION_WORKFLOW_REF = 'VitoPerillo/yoganostress-wordpress-bridge/.github/workflows/mr-content-production.yml@refs/heads/main';
    const REQUESTS_OPTION = 'mr_bridge_content_requests_v1';
    const BACKUPS_OPTION = 'mr_bridge_content_backups_v1';
    const MAX_REQUESTS = 100;
    const MAX_BACKUPS = 12;
    const MAX_CONTENT_BYTES = 786432;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/content/status', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'status'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/content/get', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'get_content'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/content/upsert', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'upsert'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/content/rollback', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'rollback'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function environment() {
        $home = untrailingslashit(home_url('/'));
        if ($home === self::STAGING_HOME) { return 'staging'; }
        if (function_exists('wp_get_environment_type') && wp_get_environment_type() === 'staging') { return 'staging'; }
        return 'production';
    }

    public static function status() {
        $env = self::environment();
        return rest_ensure_response(array(
            'ok' => in_array($env, array('staging', 'production'), true),
            'bridge' => 'mr-bridge',
            'version' => class_exists('MR_Bridge') ? MR_Bridge::VERSION : '',
            'environment' => $env,
            'content_mutations' => in_array($env, array('staging', 'production'), true),
            'auth' => 'github-oidc',
            'allowed_post_types' => array('post', 'page', 'tribe_events'),
            'allowed_statuses' => array('draft', 'publish'),
            'arbitrary_php' => false,
            'arbitrary_sql' => false,
            'arbitrary_shell' => false,
        ));
    }

    public static function get_content(WP_REST_Request $request) {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }

        $data = self::request_json($request);
        $post_type = sanitize_key((string) ($data['post_type'] ?? ''));
        if (!in_array($post_type, array('post', 'page', 'tribe_events'), true)) {
            return new WP_Error('mr_content_bad_post_type', 'Tipo contenuto non consentito.', array('status' => 400));
        }

        $post = null;
        $post_id = (int) ($data['post_id'] ?? 0);
        if ($post_id > 0) {
            $candidate = get_post($post_id);
            if ($candidate && $candidate->post_type === $post_type) { $post = $candidate; }
        } else {
            $slug = sanitize_title((string) ($data['slug'] ?? ''));
            if ($slug !== '') { $post = get_page_by_path($slug, OBJECT, $post_type); }
        }
        if (!$post) {
            return new WP_Error('mr_content_post_not_found', 'Contenuto richiesto non trovato.', array('status' => 404));
        }

        $raw = (string) $post->post_content;
        $out = array(
            'ok' => true,
            'environment' => self::environment(),
            'post' => array(
                'id' => (int) $post->ID,
                'post_type' => (string) $post->post_type,
                'status' => (string) $post->post_status,
                'slug' => (string) $post->post_name,
                'title' => (string) $post->post_title,
                'excerpt' => (string) $post->post_excerpt,
                'content' => $raw,
                'content_sha256' => hash('sha256', $raw),
                'post_date' => (string) $post->post_date,
                'post_date_gmt' => (string) $post->post_date_gmt,
                'modified_gmt' => (string) $post->post_modified_gmt,
                'featured_image_id' => (int) get_post_thumbnail_id($post->ID),
                'permalink' => (string) get_permalink($post->ID),
            ),
        );
        $out['post']['seo'] = self::seo_snapshot($post->ID);
        if ($post_type === 'tribe_events') {
            $out['post']['event'] = array(
                'start_date' => (string) get_post_meta($post->ID, '_EventStartDate', true),
                'end_date' => (string) get_post_meta($post->ID, '_EventEndDate', true),
                'timezone' => (string) get_post_meta($post->ID, '_EventTimezone', true),
                'venue_id' => (int) get_post_meta($post->ID, '_EventVenueID', true),
            );
        }

        if (class_exists('MR_Bridge') && method_exists('MR_Bridge', 'log')) {
            MR_Bridge::log('content_read', array(
                'post_id' => (int) $post->ID,
                'post_type' => $post_type,
                'workflow_run_id' => (string) ($claims['run_id'] ?? ''),
            ));
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
            return new WP_Error('mr_content_jwks_http', 'JWKS GitHub non disponibile.', array('status' => 503));
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data) || empty($data['keys'])) {
            return new WP_Error('mr_content_jwks_invalid', 'JWKS GitHub non valido.', array('status' => 503));
        }
        set_transient('mr_bridge_github_jwks', $data, HOUR_IN_SECONDS);
        return $data;
    }

    private static function expected_oidc() {
        $env = self::environment();
        if ($env === 'staging') {
            return array(
                'aud' => self::STAGING_HOME . '/mr-bridge-content',
                'ref' => self::STAGING_REF,
                'workflow_ref' => self::STAGING_WORKFLOW_REF,
            );
        }
        if ($env === 'production') {
            return array(
                'aud' => untrailingslashit(home_url('/')) . '/mr-bridge-content',
                'ref' => self::PRODUCTION_REF,
                'workflow_ref' => self::PRODUCTION_WORKFLOW_REF,
            );
        }
        return null;
    }

    private static function verify_oidc() {
        $expected = self::expected_oidc();
        if (!$expected) {
            return new WP_Error('mr_content_unknown_environment', 'Ambiente MR Bridge non riconosciuto.', array('status' => 403));
        }
        $direct = class_exists('MR_Bridge_Autonomous_V1') ? MR_Bridge_Autonomous_V1::verify_global('content:write') : null;
        if ($direct !== null) { return $direct; }
        $auth = self::auth_header();
        if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
            return new WP_Error('mr_content_oidc_missing', 'Token OIDC mancante.', array('status' => 401));
        }
        $parts = explode('.', trim($m[1]));
        if (count($parts) !== 3) {
            return new WP_Error('mr_content_oidc_bad_token', 'JWT non valido.', array('status' => 401));
        }
        $header_raw = self::b64url_decode($parts[0]);
        $payload_raw = self::b64url_decode($parts[1]);
        $sig = self::b64url_decode($parts[2]);
        if ($header_raw === false || $payload_raw === false || $sig === false) {
            return new WP_Error('mr_content_oidc_bad_encoding', 'JWT non decodificabile.', array('status' => 401));
        }
        $header = json_decode($header_raw, true);
        $claims = json_decode($payload_raw, true);
        if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            return new WP_Error('mr_content_oidc_bad_header', 'Header JWT non valido.', array('status' => 401));
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
            return new WP_Error('mr_content_oidc_key', 'Chiave OIDC GitHub non verificabile.', array('status' => 401));
        }
        $signed = $parts[0] . '.' . $parts[1];
        $verified = openssl_verify($signed, $sig, $cert, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            delete_transient('mr_bridge_github_jwks');
            return new WP_Error('mr_content_oidc_signature', 'Firma OIDC GitHub non valida.', array('status' => 401));
        }

        $now = time();
        $aud = $claims['aud'] ?? '';
        $aud_ok = is_array($aud) ? in_array($expected['aud'], $aud, true) : hash_equals($expected['aud'], (string) $aud);
        $event = (string) ($claims['event_name'] ?? '');
        $checks = array(
            ($claims['iss'] ?? '') === 'https://token.actions.githubusercontent.com',
            $aud_ok,
            (string) ($claims['repository'] ?? '') === self::OIDC_REPOSITORY,
            (string) ($claims['repository_id'] ?? '') === self::OIDC_REPOSITORY_ID,
            (string) ($claims['repository_owner_id'] ?? '') === self::OIDC_OWNER_ID,
            (string) ($claims['actor_id'] ?? '') === self::OIDC_OWNER_ID,
            (string) ($claims['ref'] ?? '') === $expected['ref'],
            (string) ($claims['workflow_ref'] ?? '') === $expected['workflow_ref'],
            in_array($event, array('push', 'workflow_dispatch'), true),
            !empty($claims['exp']) && (int) $claims['exp'] >= ($now - 30),
            empty($claims['nbf']) || (int) $claims['nbf'] <= ($now + 30),
            !empty($claims['iat']) && (int) $claims['iat'] >= ($now - 900) && (int) $claims['iat'] <= ($now + 120),
        );
        foreach ($checks as $ok) {
            if (!$ok) {
                return new WP_Error('mr_content_oidc_claims', 'Claim OIDC GitHub non autorizzati.', array('status' => 403));
            }
        }
        return $claims;
    }

    private static function request_json(WP_REST_Request $request) {
        $data = $request->get_json_params();
        return is_array($data) ? $data : array();
    }

    private static function request_id_valid($request_id) {
        return is_string($request_id) && preg_match('/^[A-Za-z0-9._:-]{16,96}$/', $request_id);
    }

    private static function fingerprint($data) {
        $copy = $data;
        unset($copy['confirm']);
        if (isset($copy['category_ids']) && is_array($copy['category_ids'])) {
            $copy['category_ids'] = array_values(array_map('intval', $copy['category_ids']));
            sort($copy['category_ids']);
        }
        if (isset($copy['tag_ids']) && is_array($copy['tag_ids'])) {
            $copy['tag_ids'] = array_values(array_map('intval', $copy['tag_ids']));
            sort($copy['tag_ids']);
        }
        ksort($copy);
        return hash('sha256', wp_json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function request_lookup($request_id, $fingerprint) {
        $items = get_option(self::REQUESTS_OPTION, array());
        if (!is_array($items) || empty($items[$request_id])) { return null; }
        $row = $items[$request_id];
        if (!hash_equals((string) ($row['fingerprint'] ?? ''), $fingerprint)) {
            return new WP_Error('mr_content_idempotency_conflict', 'request_id già usato con payload differente.', array('status' => 409));
        }
        return $row;
    }

    private static function request_store($request_id, $fingerprint, $result) {
        $items = get_option(self::REQUESTS_OPTION, array());
        if (!is_array($items)) { $items = array(); }
        $items[$request_id] = array(
            'time' => current_time('mysql', true),
            'fingerprint' => $fingerprint,
            'result' => $result,
        );
        if (count($items) > self::MAX_REQUESTS) {
            $items = array_slice($items, -self::MAX_REQUESTS, null, true);
        }
        update_option(self::REQUESTS_OPTION, $items, false);
    }

    private static function sanitize_content($html) {
        $allowed = wp_kses_allowed_html('post');
        $allowed['style'] = array('type' => true, 'media' => true);
        $allowed['iframe'] = array(
            'src' => true, 'title' => true, 'width' => true, 'height' => true,
            'loading' => true, 'allow' => true, 'allowfullscreen' => true,
            'referrerpolicy' => true, 'frameborder' => true, 'class' => true,
        );
        return wp_kses((string) $html, $allowed, array('http', 'https', 'mailto', 'tel'));
    }

    private static function valid_term_ids($ids, $taxonomy) {
        $out = array();
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($id > 0 && term_exists($id, $taxonomy)) { $out[] = $id; }
        }
        return array_values(array_unique($out));
    }

    private static function valid_attachment_id($id) {
        $id = (int) $id;
        if ($id <= 0) { return 0; }
        $post = get_post($id);
        return ($post && $post->post_type === 'attachment') ? $id : 0;
    }

    private static function event_date_valid($value, $timezone) {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) { return false; }
        try {
            $tz = new DateTimeZone($timezone);
            $dt = DateTime::createFromFormat('!Y-m-d H:i:s', $value, $tz);
            return $dt && $dt->format('Y-m-d H:i:s') === $value;
        } catch (Exception $e) {
            return false;
        }
    }

    private static function active_seo_plugin() {
        if (!function_exists('is_plugin_active')) { require_once ABSPATH . 'wp-admin/includes/plugin.php'; }
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
            '_yoast_wpseo_title' => get_post_meta($post_id, '_yoast_wpseo_title', true),
            '_yoast_wpseo_metadesc' => get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
            '_yoast_wpseo_focuskw' => get_post_meta($post_id, '_yoast_wpseo_focuskw', true),
            '_yoast_wpseo_canonical' => get_post_meta($post_id, '_yoast_wpseo_canonical', true),
            '_yoast_wpseo_meta-robots-noindex' => get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true),
            '_yoast_wpseo_meta-robots-nofollow' => get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true),
            'rank_math_title' => get_post_meta($post_id, 'rank_math_title', true),
            'rank_math_description' => get_post_meta($post_id, 'rank_math_description', true),
            'rank_math_focus_keyword' => get_post_meta($post_id, 'rank_math_focus_keyword', true),
            'rank_math_canonical_url' => get_post_meta($post_id, 'rank_math_canonical_url', true),
            'rank_math_robots' => get_post_meta($post_id, 'rank_math_robots', true),
        );
    }

    private static function seo_requested($data) {
        foreach (array('seo_title','seo_description','focus_keyword','canonical','indexable','follow') as $key) {
            if (array_key_exists($key, $data)) { return true; }
        }
        return false;
    }

    private static function apply_seo($post_id, $data) {
        if (!self::seo_requested($data)) { return true; }
        $plugin = self::active_seo_plugin();
        if ($plugin === 'none') {
            return new WP_Error('mr_content_seo_plugin_missing', 'Nessun plugin SEO supportato attivo.', array('status' => 409));
        }

        if ($plugin === 'yoast') {
            $map = array(
                'seo_title' => '_yoast_wpseo_title',
                'seo_description' => '_yoast_wpseo_metadesc',
                'focus_keyword' => '_yoast_wpseo_focuskw',
                'canonical' => '_yoast_wpseo_canonical',
            );
            foreach ($map as $field => $meta_key) {
                if (array_key_exists($field, $data)) {
                    $value = $field === 'canonical' ? esc_url_raw((string) $data[$field], array('http','https')) : sanitize_text_field((string) $data[$field]);
                    if ($value === '') { delete_post_meta($post_id, $meta_key); }
                    else { update_post_meta($post_id, $meta_key, $value); }
                }
            }
            if (array_key_exists('indexable', $data)) {
                $noindex = (bool) $data['indexable'] ? '0' : '1';
                update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', $noindex);
            }
            if (array_key_exists('follow', $data)) {
                $nofollow = (bool) $data['follow'] ? '0' : '1';
                update_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', $nofollow);
            }
            return true;
        }

        $map = array(
            'seo_title' => 'rank_math_title',
            'seo_description' => 'rank_math_description',
            'focus_keyword' => 'rank_math_focus_keyword',
            'canonical' => 'rank_math_canonical_url',
        );
        foreach ($map as $field => $meta_key) {
            if (array_key_exists($field, $data)) {
                $value = $field === 'canonical' ? esc_url_raw((string) $data[$field], array('http','https')) : sanitize_text_field((string) $data[$field]);
                if ($value === '') { delete_post_meta($post_id, $meta_key); }
                else { update_post_meta($post_id, $meta_key, $value); }
            }
        }
        if (array_key_exists('indexable', $data) || array_key_exists('follow', $data)) {
            $robots = get_post_meta($post_id, 'rank_math_robots', true);
            if (!is_array($robots)) { $robots = array(); }
            $robots = array_values(array_diff($robots, array('index','noindex','follow','nofollow')));
            $robots[] = array_key_exists('indexable', $data) ? ((bool) $data['indexable'] ? 'index' : 'noindex') : 'index';
            $robots[] = array_key_exists('follow', $data) ? ((bool) $data['follow'] ? 'follow' : 'nofollow') : 'follow';
            update_post_meta($post_id, 'rank_math_robots', array_values(array_unique($robots)));
        }
        return true;
    }

    private static function restore_seo_snapshot($post_id, $snapshot) {
        if (!is_array($snapshot)) { return; }
        foreach ($snapshot as $meta_key => $meta_value) {
            if ($meta_key === 'plugin') { continue; }
            if ($meta_value === '' || $meta_value === null || $meta_value === array()) {
                delete_post_meta($post_id, $meta_key);
            } else {
                update_post_meta($post_id, $meta_key, $meta_value);
            }
        }
    }

    private static function snapshot($post) {
        $post_id = (int) $post->ID;
        $thumb = (int) get_post_thumbnail_id($post_id);
        $snapshot = array(
            'id' => $post_id,
            'post_type' => (string) $post->post_type,
            'post_status' => (string) $post->post_status,
            'post_name' => (string) $post->post_name,
            'post_title' => (string) $post->post_title,
            'post_content' => (string) $post->post_content,
            'post_excerpt' => (string) $post->post_excerpt,
            'post_date' => (string) $post->post_date,
            'post_date_gmt' => (string) $post->post_date_gmt,
            'featured_image_id' => $thumb,
            'seo' => self::seo_snapshot($post_id),
        );
        if ($post->post_type === 'post') {
            $snapshot['category_ids'] = wp_get_post_terms($post_id, 'category', array('fields' => 'ids'));
            $snapshot['tag_ids'] = wp_get_post_terms($post_id, 'post_tag', array('fields' => 'ids'));
        } elseif ($post->post_type === 'tribe_events') {
            $snapshot['category_ids'] = wp_get_post_terms($post_id, 'tribe_events_cat', array('fields' => 'ids'));
            $snapshot['event'] = array(
                '_EventStartDate' => (string) get_post_meta($post_id, '_EventStartDate', true),
                '_EventEndDate' => (string) get_post_meta($post_id, '_EventEndDate', true),
                '_EventTimezone' => (string) get_post_meta($post_id, '_EventTimezone', true),
                '_EventVenueID' => (int) get_post_meta($post_id, '_EventVenueID', true),
                '_EventAllDay' => (string) get_post_meta($post_id, '_EventAllDay', true),
                '_EventShowMap' => (string) get_post_meta($post_id, '_EventShowMap', true),
                '_EventShowMapLink' => (string) get_post_meta($post_id, '_EventShowMapLink', true),
            );
        }
        return $snapshot;
    }

    private static function backup_store($post, $request_id) {
        $items = get_option(self::BACKUPS_OPTION, array());
        if (!is_array($items)) { $items = array(); }
        $backup_id = 'content-' . gmdate('YmdHis') . '-' . substr(hash('sha256', $request_id . microtime(true)), 0, 12);
        $items[] = array(
            'backup_id' => $backup_id,
            'created_at' => current_time('mysql', true),
            'request_id' => $request_id,
            'created_new' => false,
            'post' => self::snapshot($post),
        );
        if (count($items) > self::MAX_BACKUPS) { $items = array_slice($items, -self::MAX_BACKUPS); }
        update_option(self::BACKUPS_OPTION, $items, false);
        return $backup_id;
    }

    private static function backup_store_new($post_id, $post_type, $request_id) {
        $items = get_option(self::BACKUPS_OPTION, array());
        if (!is_array($items)) { $items = array(); }
        $backup_id = 'content-' . gmdate('YmdHis') . '-' . substr(hash('sha256', $request_id . microtime(true)), 0, 12);
        $items[] = array(
            'backup_id' => $backup_id,
            'created_at' => current_time('mysql', true),
            'request_id' => $request_id,
            'created_new' => true,
            'post' => array('id' => (int) $post_id, 'post_type' => (string) $post_type),
        );
        if (count($items) > self::MAX_BACKUPS) { $items = array_slice($items, -self::MAX_BACKUPS); }
        update_option(self::BACKUPS_OPTION, $items, false);
        return $backup_id;
    }

    private static function find_backup($backup_id) {
        foreach ((array) get_option(self::BACKUPS_OPTION, array()) as $row) {
            if (is_array($row) && hash_equals((string) ($row['backup_id'] ?? ''), (string) $backup_id)) { return $row; }
        }
        return null;
    }

    private static function event_schedule_valid($post_id, $data) {
        $start = (string) get_post_meta($post_id, '_EventStartDate', true);
        $end = (string) get_post_meta($post_id, '_EventEndDate', true);
        $timezone = (string) get_post_meta($post_id, '_EventTimezone', true);
        return $start === (string) $data['start_date']
            && $end === (string) $data['end_date']
            && ($timezone === '' || $timezone === (string) $data['timezone']);
    }

    private static function event_orm_args($data, $postarr) {
        $args = array(
            'title' => (string) ($postarr['post_title'] ?? ''),
            'description' => (string) ($postarr['post_content'] ?? ''),
            'excerpt' => (string) ($postarr['post_excerpt'] ?? ''),
            'status' => (string) ($postarr['post_status'] ?? 'draft'),
            'slug' => (string) ($postarr['post_name'] ?? ''),
            'start_date' => (string) $data['start_date'],
            'end_date' => (string) $data['end_date'],
            'timezone' => (string) $data['timezone'],
            'show_map' => true,
            'show_map_link' => true,
        );
        if (!empty($data['venue_id'])) { $args['venue'] = (int) $data['venue_id']; }
        return $args;
    }

    private static function event_write($post_id, $data, $postarr) {
        if (!function_exists('tribe_events')) {
            return new WP_Error('mr_content_tec_orm_missing', 'The Events Calendar ORM non disponibile.', array('status' => 500));
        }

        $args = self::event_orm_args($data, $postarr);
        $desired_slug = (string) ($postarr['post_name'] ?? '');

        // Healthy existing TEC event: update in place and verify the schedule.
        if ($post_id > 0 && self::event_schedule_valid($post_id, $data)) {
            try {
                $result = tribe_events()
                    ->where('id', (int) $post_id)
                    ->set_args($args)
                    ->save();
            } catch (Throwable $e) {
                return new WP_Error('mr_content_tec_orm_exception', 'Aggiornamento evento TEC fallito.', array('status' => 500));
            }
            clean_post_cache((int) $post_id);
            if (!$result || !self::event_schedule_valid($post_id, $data)) {
                return new WP_Error('mr_content_tec_schedule_failed', 'The Events Calendar non ha mantenuto correttamente date e orari.', array('status' => 500));
            }
            return (int) $post_id;
        }

        // New event, or legacy/broken event with missing TEC date metadata:
        // create through the current TEC ORM, verify, then atomically replace the broken record.
        $create_args = $args;
        if ($post_id > 0) {
            $create_args['slug'] = sanitize_title($desired_slug . '-repair-' . substr(hash('sha256', $post_id . microtime(true)), 0, 8));
        }

        try {
            $created = tribe_events()->set_args($create_args)->create();
        } catch (Throwable $e) {
            return new WP_Error('mr_content_tec_orm_exception', 'Creazione evento TEC fallita.', array('status' => 500));
        }

        $new_id = is_object($created) && isset($created->ID) ? (int) $created->ID : (int) $created;
        if (!$created || $new_id <= 0 || !get_post($new_id)) {
            return new WP_Error('mr_content_tec_orm_failed', 'The Events Calendar non ha creato correttamente l’evento.', array('status' => 500));
        }

        clean_post_cache($new_id);
        if (!self::event_schedule_valid($new_id, $data)) {
            wp_delete_post($new_id, true);
            return new WP_Error('mr_content_tec_schedule_failed', 'The Events Calendar non ha registrato correttamente date e orari.', array('status' => 500));
        }

        if ($post_id > 0) {
            $old = get_post((int) $post_id);
            if (!$old) {
                wp_delete_post($new_id, true);
                return new WP_Error('mr_content_old_event_missing', 'Evento precedente non trovato durante la riparazione.', array('status' => 409));
            }

            $deleted = wp_delete_post((int) $post_id, true);
            if (!$deleted) {
                wp_delete_post($new_id, true);
                return new WP_Error('mr_content_old_event_delete_failed', 'Impossibile sostituire il vecchio evento.', array('status' => 500));
            }

            $renamed = wp_update_post(array(
                'ID' => $new_id,
                'post_name' => $desired_slug,
            ), true);
            if (is_wp_error($renamed)) {
                return $renamed;
            }
            clean_post_cache($new_id);
        }

        return $new_id;
    }

    private static function response_payload($post_id, $backup_id, $created, $request_id) {
        $post = get_post($post_id);
        return array(
            'ok' => true,
            'request_id' => $request_id,
            'environment' => self::environment(),
            'created' => (bool) $created,
            'backup_id' => $backup_id,
            'post' => array(
                'id' => (int) $post_id,
                'post_type' => (string) $post->post_type,
                'status' => (string) $post->post_status,
                'slug' => (string) $post->post_name,
                'title' => (string) $post->post_title,
                'permalink' => (string) get_permalink($post_id),
                'modified_gmt' => (string) $post->post_modified_gmt,
            ),
        );
    }

    public static function upsert(WP_REST_Request $request) {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }

        $data = self::request_json($request);
        $env = self::environment();
        if (($data['target'] ?? '') !== $env) {
            return new WP_Error('mr_content_wrong_target', 'Target della richiesta non corrisponde al sito.', array('status' => 409));
        }
        $expected_confirm = $env === 'production' ? 'PUBLISH:PRODUCTION' : 'PUBLISH:STAGING';
        if (($data['confirm'] ?? '') !== $expected_confirm) {
            return new WP_Error('mr_content_confirmation_required', 'Conferma esplicita non valida.', array('status' => 400));
        }

        $request_id = (string) ($data['request_id'] ?? '');
        if (!self::request_id_valid($request_id)) {
            return new WP_Error('mr_content_bad_request_id', 'request_id non valido.', array('status' => 400));
        }
        $fingerprint = self::fingerprint($data);
        $previous = self::request_lookup($request_id, $fingerprint);
        if (is_wp_error($previous)) { return $previous; }
        if (is_array($previous) && !empty($previous['result'])) {
            return rest_ensure_response($previous['result']);
        }

        $post_type = sanitize_key((string) ($data['post_type'] ?? ''));
        if (!in_array($post_type, array('post', 'page', 'tribe_events'), true)) {
            return new WP_Error('mr_content_bad_post_type', 'Tipo contenuto non consentito.', array('status' => 400));
        }
        $status = sanitize_key((string) ($data['status'] ?? 'draft'));
        if (!in_array($status, array('draft', 'publish'), true)) {
            return new WP_Error('mr_content_bad_status', 'Stato non consentito.', array('status' => 400));
        }
        $title = sanitize_text_field((string) ($data['title'] ?? ''));
        if ($title === '' || strlen($title) > 250) {
            return new WP_Error('mr_content_bad_title', 'Titolo mancante o troppo lungo.', array('status' => 400));
        }
        $content_raw = (string) ($data['content'] ?? '');
        if (strlen($content_raw) > self::MAX_CONTENT_BYTES) {
            return new WP_Error('mr_content_too_large', 'Contenuto oltre il limite consentito.', array('status' => 413));
        }
        $slug = sanitize_title((string) ($data['slug'] ?? $title));
        if ($slug === '') {
            return new WP_Error('mr_content_bad_slug', 'Slug non valido.', array('status' => 400));
        }

        if ($post_type === 'tribe_events') {
            $timezone = (string) ($data['timezone'] ?? 'Europe/Rome');
            if (!self::event_date_valid((string) ($data['start_date'] ?? ''), $timezone) ||
                !self::event_date_valid((string) ($data['end_date'] ?? ''), $timezone)) {
                return new WP_Error('mr_content_bad_event_date', 'Date evento non valide.', array('status' => 400));
            }
            if ((string) $data['end_date'] < (string) $data['start_date']) {
                return new WP_Error('mr_content_event_range', 'La fine evento precede l’inizio.', array('status' => 400));
            }
        }

        $existing = null;
        $requested_id = (int) ($data['post_id'] ?? 0);
        if ($requested_id > 0) {
            $candidate = get_post($requested_id);
            if (!$candidate || $candidate->post_type !== $post_type) {
                return new WP_Error('mr_content_post_not_found', 'Contenuto richiesto non trovato.', array('status' => 404));
            }
            $existing = $candidate;
        } else {
            $candidate = get_page_by_path($slug, OBJECT, $post_type);
            if ($candidate) { $existing = $candidate; }
        }

        if (self::seo_requested($data) && self::active_seo_plugin() === 'none') {
            return new WP_Error('mr_content_seo_plugin_missing', 'Nessun plugin SEO supportato attivo.', array('status' => 409));
        }

        if ($post_type === 'page' && $existing) {
            $expected_hash = strtolower(trim((string) ($data['expected_content_sha256'] ?? '')));
            if (!preg_match('/^[a-f0-9]{64}$/', $expected_hash)) {
                return new WP_Error('mr_content_page_hash_required', 'Per aggiornare una pagina esistente è richiesto expected_content_sha256.', array('status' => 400));
            }
            $current_hash = hash('sha256', (string) $existing->post_content);
            if (!hash_equals($current_hash, $expected_hash)) {
                return new WP_Error('mr_content_page_conflict', 'La pagina è cambiata dopo il readback; aggiornamento rifiutato.', array(
                    'status' => 409,
                    'current_content_sha256' => $current_hash,
                    'modified_gmt' => (string) $existing->post_modified_gmt,
                ));
            }
        }

        $backup_id = '';
        if ($existing) { $backup_id = self::backup_store($existing, $request_id); }

        $postarr = array(
            'post_title' => $title,
            'post_content' => self::sanitize_content($content_raw),
            'post_excerpt' => sanitize_textarea_field((string) ($data['excerpt'] ?? '')),
            'post_status' => $status,
            'post_name' => $slug,
        );

        // Existing content must keep its editorial publication date unless an
        // explicit, strictly validated replacement is supplied.
        if ($existing && in_array($post_type, array('post', 'page'), true)) {
            $postarr['post_date'] = (string) $existing->post_date;
            $postarr['post_date_gmt'] = (string) $existing->post_date_gmt;
        }
        foreach (array('post_date', 'post_date_gmt') as $date_field) {
            if (array_key_exists($date_field, $data)) {
                $date_value = trim((string) $data[$date_field]);
                if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date_value)) {
                    return new WP_Error('mr_content_bad_publish_date', 'Data editoriale non valida.', array('status' => 400));
                }
                $dt = DateTime::createFromFormat('!Y-m-d H:i:s', $date_value, new DateTimeZone('UTC'));
                if (!$dt || $dt->format('Y-m-d H:i:s') !== $date_value) {
                    return new WP_Error('mr_content_bad_publish_date', 'Data editoriale non valida.', array('status' => 400));
                }
                $postarr[$date_field] = $date_value;
            }
        }
        if (in_array($post_type, array('post', 'page'), true)) {
            $postarr['post_type'] = $post_type;
            if ($existing) { $postarr['ID'] = (int) $existing->ID; }
            $result = wp_insert_post($postarr, true);
        } else {
            $result = self::event_write($existing ? (int) $existing->ID : 0, $data, $postarr);
        }
        if (is_wp_error($result)) { return $result; }

        $post_id = (int) $result;
        $created = !$existing;
        if ($created) { $backup_id = self::backup_store_new($post_id, $post_type, $request_id); }

        if ($post_type === 'post') {
            if (isset($data['category_ids'])) {
                wp_set_post_terms($post_id, self::valid_term_ids($data['category_ids'], 'category'), 'category', false);
            }
            if (isset($data['tag_ids'])) {
                wp_set_post_terms($post_id, self::valid_term_ids($data['tag_ids'], 'post_tag'), 'post_tag', false);
            }
        } else {
            if (isset($data['category_ids'])) {
                wp_set_post_terms($post_id, self::valid_term_ids($data['category_ids'], 'tribe_events_cat'), 'tribe_events_cat', false);
            }
        }

        if (array_key_exists('featured_image_id', $data)) {
            $image_id = self::valid_attachment_id($data['featured_image_id']);
            if ($image_id > 0) { set_post_thumbnail($post_id, $image_id); }
            elseif ((int) $data['featured_image_id'] === 0) { delete_post_thumbnail($post_id); }
        }

        $seo_result = self::apply_seo($post_id, $data);
        if (is_wp_error($seo_result)) {
            if ($backup_id !== '') {
                $backup = self::find_backup($backup_id);
                if (is_array($backup) && empty($backup['created_new'])) {
                    self::restore_seo_snapshot($post_id, (array) (($backup['post'] ?? array())['seo'] ?? array()));
                }
            }
            return $seo_result;
        }

        clean_post_cache($post_id);
        $payload = self::response_payload($post_id, $backup_id, $created, $request_id);
        self::request_store($request_id, $fingerprint, $payload);
        if (class_exists('MR_Bridge') && method_exists('MR_Bridge', 'log')) {
            MR_Bridge::log('content_upsert', array(
                'request_id' => $request_id,
                'environment' => $env,
                'post_id' => $post_id,
                'post_type' => $post_type,
                'status' => $status,
                'created' => $created,
                'backup_id' => $backup_id,
                'workflow_run_id' => (string) ($claims['run_id'] ?? ''),
            ));
        }
        return rest_ensure_response($payload);
    }

    public static function rollback(WP_REST_Request $request) {
        $claims = self::verify_oidc();
        if (is_wp_error($claims)) { return $claims; }
        $data = self::request_json($request);
        $env = self::environment();
        if (($data['target'] ?? '') !== $env) {
            return new WP_Error('mr_content_wrong_target', 'Target della richiesta non corrisponde al sito.', array('status' => 409));
        }
        $expected_confirm = $env === 'production' ? 'ROLLBACK:PRODUCTION' : 'ROLLBACK:STAGING';
        if (($data['confirm'] ?? '') !== $expected_confirm) {
            return new WP_Error('mr_content_confirmation_required', 'Conferma rollback non valida.', array('status' => 400));
        }
        $backup_id = sanitize_text_field((string) ($data['backup_id'] ?? ''));
        $backup = self::find_backup($backup_id);
        if (!$backup) {
            return new WP_Error('mr_content_backup_not_found', 'Backup contenuto non trovato.', array('status' => 404));
        }

        $snap = (array) ($backup['post'] ?? array());
        $post_id = (int) ($snap['id'] ?? 0);
        if ($post_id <= 0) {
            return new WP_Error('mr_content_bad_backup', 'Backup contenuto non valido.', array('status' => 500));
        }

        if (!empty($backup['created_new'])) {
            $post = get_post($post_id);
            if ($post) { wp_trash_post($post_id); }
            $result = array('ok' => true, 'backup_id' => $backup_id, 'rolled_back' => true, 'action' => 'trashed_created_post', 'post_id' => $post_id);
        } else {
            $postarr = array(
                'ID' => $post_id,
                'post_status' => (string) $snap['post_status'],
                'post_name' => (string) $snap['post_name'],
                'post_title' => (string) $snap['post_title'],
                'post_content' => (string) $snap['post_content'],
                'post_excerpt' => (string) $snap['post_excerpt'],
            );
            if (!empty($snap['post_date'])) { $postarr['post_date'] = (string) $snap['post_date']; }
            if (!empty($snap['post_date_gmt'])) { $postarr['post_date_gmt'] = (string) $snap['post_date_gmt']; }
            $updated = wp_update_post($postarr, true);
            if (is_wp_error($updated)) { return $updated; }

            if (($snap['post_type'] ?? '') === 'post') {
                wp_set_post_terms($post_id, array_map('intval', (array) ($snap['category_ids'] ?? array())), 'category', false);
                wp_set_post_terms($post_id, array_map('intval', (array) ($snap['tag_ids'] ?? array())), 'post_tag', false);
            } elseif (($snap['post_type'] ?? '') === 'tribe_events') {
                wp_set_post_terms($post_id, array_map('intval', (array) ($snap['category_ids'] ?? array())), 'tribe_events_cat', false);
                foreach ((array) ($snap['event'] ?? array()) as $meta_key => $meta_value) {
                    update_post_meta($post_id, $meta_key, $meta_value);
                }
            }

            $thumb = (int) ($snap['featured_image_id'] ?? 0);
            if ($thumb > 0) { set_post_thumbnail($post_id, $thumb); } else { delete_post_thumbnail($post_id); }
            self::restore_seo_snapshot($post_id, (array) ($snap['seo'] ?? array()));
            clean_post_cache($post_id);
            $result = array(
                'ok' => true,
                'backup_id' => $backup_id,
                'rolled_back' => true,
                'action' => 'restored_previous_state',
                'post_id' => $post_id,
                'permalink' => (string) get_permalink($post_id),
            );
        }

        if (class_exists('MR_Bridge') && method_exists('MR_Bridge', 'log')) {
            MR_Bridge::log('content_rollback', array(
                'environment' => $env,
                'backup_id' => $backup_id,
                'post_id' => $post_id,
                'workflow_run_id' => (string) ($claims['run_id'] ?? ''),
            ));
        }
        return rest_ensure_response($result);
    }
}

MR_Bridge_Content_V1::init();

