<?php
/**
 * Plugin Name: YNS Direct Bridge
 * Description: Canale diretto Yoganostress per manutenzione WordPress via GitHub Actions OIDC, senza WPVibe e senza password persistenti.
 * Version: 0.2.0
 * Author: Yoganostress
 */

if (!defined('ABSPATH')) { exit; }

define('YNS_DIRECT_VERSION', '0.2.0');
define('YNS_DIRECT_AUD', 'https://www.yoganostress.it/yns-direct');
define('YNS_DIRECT_REPOSITORY', 'VitoPerillo/VitoPerillo.github.io');
define('YNS_DIRECT_REPOSITORY_ID', '1368437653');
define('YNS_DIRECT_OWNER_ID', '317205417');
define('YNS_DIRECT_REF', 'refs/heads/main');
define('YNS_DIRECT_WORKFLOW_REF', 'VitoPerillo/VitoPerillo.github.io/.github/workflows/yns-live-direct.yml@refs/heads/main');

function yns_direct_b64url_decode($value) {
    $value = strtr($value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad) { $value .= str_repeat('=', 4 - $pad); }
    return base64_decode($value, true);
}

function yns_direct_auth_header() {
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return trim((string) $_SERVER['HTTP_AUTHORIZATION']);
    }
    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $k => $v) {
            if (strtolower($k) === 'authorization') { return trim((string) $v); }
        }
    }
    return '';
}

function yns_direct_get_jwks() {
    $cached = get_transient('yns_direct_github_jwks');
    if (is_array($cached) && !empty($cached['keys'])) { return $cached; }

    $res = wp_remote_get('https://token.actions.githubusercontent.com/.well-known/jwks', [
        'timeout' => 10,
        'redirection' => 2,
        'headers' => ['Accept' => 'application/json'],
    ]);
    if (is_wp_error($res)) { return $res; }
    if ((int) wp_remote_retrieve_response_code($res) !== 200) {
        return new WP_Error('yns_direct_jwks_http', 'Impossibile leggere le chiavi OIDC GitHub.', ['status' => 503]);
    }
    $data = json_decode(wp_remote_retrieve_body($res), true);
    if (!is_array($data) || empty($data['keys'])) {
        return new WP_Error('yns_direct_jwks_invalid', 'JWKS GitHub non valido.', ['status' => 503]);
    }
    set_transient('yns_direct_github_jwks', $data, HOUR_IN_SECONDS);
    return $data;
}

function yns_direct_verify_oidc() {
    $auth = yns_direct_auth_header();
    if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        return new WP_Error('yns_direct_no_token', 'Token OIDC mancante.', ['status' => 401]);
    }

    $jwt = trim($m[1]);
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return new WP_Error('yns_direct_bad_token', 'JWT non valido.', ['status' => 401]);
    }

    $header_raw = yns_direct_b64url_decode($parts[0]);
    $payload_raw = yns_direct_b64url_decode($parts[1]);
    $sig = yns_direct_b64url_decode($parts[2]);
    if ($header_raw === false || $payload_raw === false || $sig === false) {
        return new WP_Error('yns_direct_bad_encoding', 'JWT non decodificabile.', ['status' => 401]);
    }

    $header = json_decode($header_raw, true);
    $claims = json_decode($payload_raw, true);
    if (!is_array($header) || !is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
        return new WP_Error('yns_direct_bad_header', 'Header JWT non valido.', ['status' => 401]);
    }

    $jwks = yns_direct_get_jwks();
    if (is_wp_error($jwks)) { return $jwks; }

    $cert = null;
    foreach ($jwks['keys'] as $key) {
        if (($key['kid'] ?? '') === $header['kid'] && !empty($key['x5c'][0])) {
            $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($key['x5c'][0], 64, "\n") . "-----END CERTIFICATE-----\n";
            break;
        }
    }
    if (!$cert || !function_exists('openssl_verify')) {
        delete_transient('yns_direct_github_jwks');
        return new WP_Error('yns_direct_key_missing', 'Chiave GitHub OIDC non disponibile.', ['status' => 401]);
    }

    $verified = openssl_verify($parts[0] . '.' . $parts[1], $sig, $cert, OPENSSL_ALGO_SHA256);
    if ($verified !== 1) {
        delete_transient('yns_direct_github_jwks');
        return new WP_Error('yns_direct_bad_signature', 'Firma OIDC non valida.', ['status' => 401]);
    }

    $now = time();
    $aud = $claims['aud'] ?? '';
    $aud_ok = is_array($aud) ? in_array(YNS_DIRECT_AUD, $aud, true) : hash_equals(YNS_DIRECT_AUD, (string) $aud);

    $checks = [
        'iss' => (($claims['iss'] ?? '') === 'https://token.actions.githubusercontent.com'),
        'aud' => $aud_ok,
        'exp' => (!empty($claims['exp']) && (int) $claims['exp'] >= $now - 30),
        'nbf' => (empty($claims['nbf']) || (int) $claims['nbf'] <= $now + 30),
        'repository' => (($claims['repository'] ?? '') === YNS_DIRECT_REPOSITORY),
        'repository_id' => ((string) ($claims['repository_id'] ?? '') === YNS_DIRECT_REPOSITORY_ID),
        'repository_owner_id' => ((string) ($claims['repository_owner_id'] ?? '') === YNS_DIRECT_OWNER_ID),
        'ref' => (($claims['ref'] ?? '') === YNS_DIRECT_REF),
        'workflow_ref' => (($claims['workflow_ref'] ?? '') === YNS_DIRECT_WORKFLOW_REF),
        'event_name' => (($claims['event_name'] ?? '') === 'push'),
    ];

    foreach ($checks as $name => $ok) {
        if (!$ok) {
            return new WP_Error('yns_direct_claim_' . $name, 'Claim OIDC rifiutato: ' . $name, ['status' => 403]);
        }
    }

    return $claims;
}

function yns_direct_preview($value, $needle, $radius = 180) {
    $pos = stripos($value, $needle);
    if ($pos === false) { return ''; }
    $start = max(0, $pos - $radius);
    return substr($value, $start, $radius * 2 + strlen($needle));
}

function yns_direct_scan($needle) {
    global $wpdb;
    $needle = (string) $needle;
    if ($needle === '' || strlen($needle) > 500) {
        return new WP_Error('yns_direct_bad_needle', 'Needle non valido.', ['status' => 400]);
    }

    $like = '%' . $wpdb->esc_like($needle) . '%';
    $out = ['posts' => [], 'meta' => [], 'options' => []];

    $posts = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_type, post_status, post_title, post_content
         FROM {$wpdb->posts}
         WHERE post_status <> 'trash'
           AND post_type IN ('page','post')
           AND post_content LIKE %s
         ORDER BY ID DESC LIMIT 100",
        $like
    ), ARRAY_A);
    foreach ($posts as $row) {
        $out['posts'][] = [
            'id' => (int) $row['ID'],
            'post_type' => $row['post_type'],
            'status' => $row['post_status'],
            'title' => $row['post_title'],
            'preview' => yns_direct_preview($row['post_content'], $needle),
        ];
    }

    $meta = $wpdb->get_results($wpdb->prepare(
        "SELECT post_id, meta_key, meta_value
         FROM {$wpdb->postmeta}
         WHERE meta_value LIKE %s
         ORDER BY meta_id DESC LIMIT 100",
        $like
    ), ARRAY_A);
    foreach ($meta as $row) {
        $out['meta'][] = [
            'post_id' => (int) $row['post_id'],
            'meta_key' => $row['meta_key'],
            'serialized' => is_serialized($row['meta_value']),
            'preview' => yns_direct_preview($row['meta_value'], $needle),
        ];
    }

    $options = $wpdb->get_results($wpdb->prepare(
        "SELECT option_name, option_value
         FROM {$wpdb->options}
         WHERE option_value LIKE %s
         ORDER BY option_id DESC LIMIT 100",
        $like
    ), ARRAY_A);
    foreach ($options as $row) {
        $out['options'][] = [
            'option_name' => $row['option_name'],
            'serialized' => is_serialized($row['option_value']),
            'preview' => yns_direct_preview($row['option_value'], $needle),
        ];
    }

    return $out;
}

function yns_direct_remove_block_containing($post_id, $needle) {
    $post_id = absint($post_id);
    $needle = (string) $needle;
    $post = get_post($post_id);
    if (!$post || !in_array($post->post_type, ['page','post'], true)) {
        return new WP_Error('yns_direct_post_missing', 'Pagina/post non trovato.', ['status' => 404]);
    }
    if ($needle === '' || strpos($post->post_content, $needle) === false) {
        return new WP_Error('yns_direct_no_match', 'Marker non trovato nel contenuto.', ['status' => 404]);
    }

    $content = $post->post_content;
    $quoted = preg_quote($needle, '~');

    $patterns = [
        '~<!--\s+wp:paragraph\b.*?-->\s*<p\b[^>]*>.*?' . $quoted . '.*?</p>\s*<!--\s+/wp:paragraph\s+-->~is',
        '~<!--\s+wp:html\b.*?-->.*?' . $quoted . '.*?<!--\s+/wp:html\s+-->~is',
        '~<p\b[^>]*>.*?' . $quoted . '.*?</p>~is',
    ];

    $removed = 0;
    foreach ($patterns as $pattern) {
        $new = preg_replace($pattern, '', $content, 1, $count);
        if ($count > 0) {
            $content = $new;
            $removed += $count;
            break;
        }
    }

    if ($removed === 0) {
        return new WP_Error('yns_direct_block_not_isolated', 'Il marker esiste ma il blocco non è isolabile in sicurezza.', ['status' => 409]);
    }

    if (function_exists('wp_save_post_revision')) { wp_save_post_revision($post_id); }
    $result = wp_update_post([
        'ID' => $post_id,
        'post_content' => $content,
    ], true);
    if (is_wp_error($result)) { return $result; }

    clean_post_cache($post_id);
    return ['post_id' => $post_id, 'removed_blocks' => $removed];
}

function yns_direct_replace_exact($post_id, $old, $new, $replace_all = false) {
    $post_id = absint($post_id);
    $post = get_post($post_id);
    if (!$post || !in_array($post->post_type, ['page','post'], true)) {
        return new WP_Error('yns_direct_post_missing', 'Pagina/post non trovato.', ['status' => 404]);
    }
    $old = (string) $old;
    $new = (string) $new;
    if ($old === '') {
        return new WP_Error('yns_direct_old_empty', 'Stringa old vuota.', ['status' => 400]);
    }
    $count = substr_count($post->post_content, $old);
    if ($count === 0) {
        return new WP_Error('yns_direct_no_match', 'Stringa non trovata.', ['status' => 404]);
    }
    if (!$replace_all && $count !== 1) {
        return new WP_Error('yns_direct_multiple_matches', 'La stringa compare più volte; operazione rifiutata.', ['status' => 409, 'matches' => $count]);
    }

    $content = $replace_all ? str_replace($old, $new, $post->post_content, $replaced) : preg_replace('/' . preg_quote($old, '/') . '/', str_replace(['\\','$'], ['\\\\','\\$'], $new), $post->post_content, 1, $replaced);
    if (function_exists('wp_save_post_revision')) { wp_save_post_revision($post_id); }
    $result = wp_update_post(['ID' => $post_id, 'post_content' => $content], true);
    if (is_wp_error($result)) { return $result; }
    clean_post_cache($post_id);
    return ['post_id' => $post_id, 'replaced' => (int) $replaced];
}


function yns_direct_sync_assistant() {
    global $wpdb;

    $source = 'https://raw.githubusercontent.com/VitoPerillo/VitoPerillo.github.io/main/ops/yns-assistant/current.php';
    $res = wp_remote_get($source, [
        'timeout' => 20,
        'redirection' => 2,
        'headers' => [
            'Accept' => 'text/plain',
            'Cache-Control' => 'no-cache',
            'User-Agent' => 'Yoganostress-Direct/' . YNS_DIRECT_VERSION,
        ],
    ]);
    if (is_wp_error($res)) { return $res; }
    if ((int) wp_remote_retrieve_response_code($res) !== 200) {
        return new WP_Error('yns_direct_assistant_fetch', 'Impossibile leggere il file canonico assistente.', ['status' => 503]);
    }

    $code = (string) wp_remote_retrieve_body($res);
    if (strlen($code) < 1000 || strlen($code) > 100000 || strpos($code, 'YNS_Benessere_') === false) {
        return new WP_Error('yns_direct_assistant_invalid', 'File assistente non valido.', ['status' => 409]);
    }
    if (preg_match('~<\?php|shell_exec\s*\(|system\s*\(|passthru\s*\(|proc_open\s*\(|popen\s*\(~i', $code)) {
        return new WP_Error('yns_direct_assistant_unsafe', 'File assistente rifiutato dal controllo di sicurezza.', ['status' => 409]);
    }

    $table = $wpdb->prefix . 'snippets';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
        return new WP_Error('yns_direct_snippets_table_missing', 'Tabella Code Snippets non trovata.', ['status' => 500]);
    }

    $cols = $wpdb->get_col("DESCRIBE {$table}", 0);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", 7), ARRAY_A);
    if (!$row) {
        return new WP_Error('yns_direct_snippet_missing', 'Snippet assistente ID 7 non trovato.', ['status' => 404]);
    }

    $data = ['code' => $code];
    $formats = ['%s'];
    if (in_array('name', $cols, true)) { $data['name'] = 'Yoganostress Assistente Benessere V0.5.3'; $formats[] = '%s'; }
    if (in_array('desc', $cols, true)) { $data['desc'] = 'Assistente Yoganostress sincronizzato dal canale diretto GitHub.'; $formats[] = '%s'; }
    if (in_array('description', $cols, true)) { $data['description'] = 'Assistente Yoganostress sincronizzato dal canale diretto GitHub.'; $formats[] = '%s'; }
    if (in_array('scope', $cols, true)) { $data['scope'] = 'global'; $formats[] = '%s'; }
    if (in_array('priority', $cols, true)) { $data['priority'] = 10; $formats[] = '%d'; }
    if (in_array('active', $cols, true)) { $data['active'] = 1; $formats[] = '%d'; }
    if (in_array('modified', $cols, true)) { $data['modified'] = current_time('mysql', true); $formats[] = '%s'; }

    $updated = $wpdb->update($table, $data, ['id' => 7], $formats, ['%d']);
    if ($updated === false) {
        return new WP_Error('yns_direct_snippet_update_failed', 'Aggiornamento dello snippet assistente fallito.', ['status' => 500]);
    }

    wp_cache_flush();
    return [
        'snippet_id' => 7,
        'bytes' => strlen($code),
        'sha256' => hash('sha256', $code),
        'updated' => (int) $updated,
        'source' => $source,
    ];
}

function yns_direct_cache_purge() {
    wp_cache_flush();
    if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
    if (function_exists('litespeed_purge_all')) { litespeed_purge_all(); }
    do_action('litespeed_purge_all');
    do_action('w3tc_flush_all');
    return ['purged' => true];
}

add_action('rest_api_init', function () {
    register_rest_route('yns-direct/v1', '/health', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function () {
            return rest_ensure_response([
                'ok' => true,
                'version' => YNS_DIRECT_VERSION,
                'site' => home_url('/'),
                'time' => gmdate('c'),
            ]);
        },
    ]);

    register_rest_route('yns-direct/v1', '/command', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            $claims = yns_direct_verify_oidc();
            if (is_wp_error($claims)) { return $claims; }

            $body = $request->get_json_params();
            if (!is_array($body)) {
                return new WP_Error('yns_direct_bad_json', 'Payload JSON non valido.', ['status' => 400]);
            }

            $action = sanitize_key($body['action'] ?? '');
            switch ($action) {
                case 'health':
                    $result = ['ok' => true, 'version' => YNS_DIRECT_VERSION];
                    break;
                case 'scan':
                    $result = yns_direct_scan($body['needle'] ?? '');
                    break;
                case 'remove_block_containing':
                    $result = yns_direct_remove_block_containing($body['post_id'] ?? 0, $body['needle'] ?? '');
                    break;
                case 'replace_exact':
                    $result = yns_direct_replace_exact($body['post_id'] ?? 0, $body['old'] ?? '', $body['new'] ?? '', !empty($body['replace_all']));
                    break;
                case 'sync_assistant':
                    $result = yns_direct_sync_assistant();
                    break;
                case 'cache_purge':
                    $result = yns_direct_cache_purge();
                    break;
                default:
                    return new WP_Error('yns_direct_action_denied', 'Azione non consentita.', ['status' => 400]);
            }

            if (is_wp_error($result)) { return $result; }
            return rest_ensure_response([
                'ok' => true,
                'action' => $action,
                'result' => $result,
                'run_id' => $claims['run_id'] ?? null,
                'actor_id' => $claims['actor_id'] ?? null,
            ]);
        },
    ]);
});
