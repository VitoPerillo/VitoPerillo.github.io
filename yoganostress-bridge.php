<?php
/**
 * Plugin Name: MR Bridge
 * Description: Ponte operativo sicuro e riutilizzabile per WordPress via REST API.
 * Version: 1.0.0-rc26-production-yns-launch
 * Author: MR Bridge
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/class-mr-bridge-deploy-v1.php';
require_once __DIR__ . '/includes/class-mr-bridge-social-v1.php';
require_once __DIR__ . '/includes/class-mr-bridge-ops-v1.php';
require_once __DIR__ . '/includes/class-mr-bridge-seo-v1.php';
require_once __DIR__ . '/includes/class-mr-bridge-content-v1.php';
require_once __DIR__ . '/includes/class-mr-bridge-backup-v1.php';
require_once __DIR__ . '/includes/class-mr-bridge-plugin-updater-v1.php';
require_once __DIR__ . '/includes/class-mr-bridge-maintenance-v1.php';
require_once __DIR__ . '/includes/class-mr-bridge-media-v1.php';
require_once __DIR__ . '/includes/class-mr-bridge-autonomous-v1.php';
require_once __DIR__ . '/includes/class-mr-bridge-cloud-control-v1.php';
require_once __DIR__ . '/includes/class-mr-bridge-yns-e2e-v1.php';
require_once __DIR__ . '/includes/class-mr-bridge-yns-launch-v1.php';

final class MR_Bridge {
    const VERSION = '1.0.0-rc26-production-yns-launch';
    const REST_NAMESPACE = 'mr-bridge/v1';
    const ENABLED_OPTION = 'mr_bridge_enabled';
    const LOG_OPTION = 'mr_bridge_audit_log';
    const AUDIT_ANCHOR_OPTION = 'mr_bridge_audit_anchor';
    const LEGACY_AUDIT_ARCHIVE_OPTION = 'mr_bridge_audit_legacy_archive_v1';
    const PAGE_BACKUPS_OPTION = 'mr_bridge_page_backups';
    const PAGE_SLUG = 'affitto-sala-yoga-a-roma-per-corsi-eventi-olistici';
    const MAX_AUDIT_EVENTS = 500;
    const CONTRACT_VERSION = 2;
    const AUDIT_LOCK_OPTION = 'mr_bridge_audit_lock_v1';

    public static function init() {
        self::migrate_legacy_audit();
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));
    }

    public static function activate() {
        if (get_option(self::ENABLED_OPTION, null) === null) {
            add_option(self::ENABLED_OPTION, '1', '', false);
        }
        self::migrate_legacy_audit();
    }

    private static function migrate_legacy_audit() {
        $log = get_option(self::LOG_OPTION, array());
        if (!is_array($log) || empty($log)) { return; }
        $needs = false;
        foreach ($log as $event) {
            if (!is_array($event) || !isset($event['current_hash']) || !array_key_exists('prev_hash', $event)) {
                $needs = true;
                break;
            }
        }
        if (!$needs) {
            if (get_option(self::AUDIT_ANCHOR_OPTION, null) === null) {
                $first = reset($log);
                update_option(self::AUDIT_ANCHOR_OPTION, is_array($first) ? (string) ($first['prev_hash'] ?? '') : '', false);
            }
            return;
        }
        if (get_option(self::LEGACY_AUDIT_ARCHIVE_OPTION, null) === null) {
            add_option(self::LEGACY_AUDIT_ARCHIVE_OPTION, array(
                'archived_at' => current_time('mysql', true),
                'source_count' => count($log),
                'events' => $log,
            ), '', false);
        }
        update_option(self::LOG_OPTION, array(), false);
        update_option(self::AUDIT_ANCHOR_OPTION, '', false);
        self::log('audit_legacy_migrated', array('legacy_count' => count($log)));
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/status', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'status'),
            'permission_callback' => array('MR_Bridge_Autonomous_V1', 'can_read'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/contract', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'contract'),
            'permission_callback' => '__return_true',
        ));
        register_rest_route(self::REST_NAMESPACE, '/health', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'health'),
            'permission_callback' => array('MR_Bridge_Autonomous_V1', 'can_read'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/plugins', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'plugins'),
            'permission_callback' => array('MR_Bridge_Autonomous_V1', 'can_read'),
        ));
        register_rest_route(self::REST_NAMESPACE, '/audit', array(
            'methods' => 'GET', 'callback' => array(__CLASS__, 'audit'),
            'permission_callback' => array('MR_Bridge_Autonomous_V1', 'can_read'),
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
    }

    public static function can_manage() {
        return self::enabled() && current_user_can('manage_options');
    }

    private static function enabled() {
        return get_option(self::ENABLED_OPTION, '1') === '1';
    }

    private static function canonical_json($value) {
        return wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function acquire_audit_lock() {
        $token = wp_generate_uuid4();
        for ($i = 0; $i < 25; $i++) {
            if (add_option(self::AUDIT_LOCK_OPTION, array('token'=>$token,'time'=>time()), '', false)) { return $token; }
            $current = get_option(self::AUDIT_LOCK_OPTION, array());
            if (is_array($current) && !empty($current['time']) && (time() - (int) $current['time']) > 15) {
                delete_option(self::AUDIT_LOCK_OPTION);
                continue;
            }
            usleep(20000);
        }
        return '';
    }

    private static function release_audit_lock($token) {
        $current = get_option(self::AUDIT_LOCK_OPTION, array());
        if (is_array($current) && isset($current['token']) && hash_equals((string)$current['token'], (string)$token)) {
            delete_option(self::AUDIT_LOCK_OPTION);
        }
    }

    public static function log($action, $meta = array()) {
        $lock_token = self::acquire_audit_lock();
        $log = get_option(self::LOG_OPTION, array());
        if (!is_array($log)) { $log = array(); }
        $prev = '';
        if (!empty($log)) {
            $last = end($log);
            if (is_array($last) && isset($last['current_hash'])) { $prev = (string) $last['current_hash']; }
            reset($log);
        }
        $event = array(
            'time' => current_time('mysql', true),
            'environment' => MR_Bridge_Deploy_V1::environment(),
            'user_id' => get_current_user_id(),
            'action' => sanitize_key($action),
            'meta' => is_array($meta) ? $meta : array(),
            'prev_hash' => $prev,
        );
        $event['current_hash'] = hash('sha256', $prev . "\n" . self::canonical_json($event));
        $log[] = $event;
        if (count($log) > self::MAX_AUDIT_EVENTS) {
            $log = array_slice($log, -self::MAX_AUDIT_EVENTS);
            $anchor = isset($log[0]['prev_hash']) ? (string) $log[0]['prev_hash'] : '';
            update_option(self::AUDIT_ANCHOR_OPTION, $anchor, false);
        }
        update_option(self::LOG_OPTION, $log, false);
        if ($lock_token !== '') { self::release_audit_lock($lock_token); }
    }

    private static function verify_audit_chain($log) {
        $prev = (string) get_option(self::AUDIT_ANCHOR_OPTION, '');
        if (empty($log)) { return $prev === ''; }
        foreach ((array) $log as $event) {
            if (!is_array($event) || !isset($event['current_hash'])) { return false; }
            if ((string) ($event['prev_hash'] ?? '') !== $prev) { return false; }
            $copy = $event;
            $current = (string) $copy['current_hash'];
            unset($copy['current_hash']);
            $expected = hash('sha256', $prev . "\n" . self::canonical_json($copy));
            if (!hash_equals($expected, $current)) { return false; }
            $prev = $current;
        }
        return true;
    }

    public static function status() {
        self::log('status_read');
        return rest_ensure_response(array(
            'ok' => true,
            'bridge' => 'mr-bridge',
            'version' => self::VERSION,
            'site' => home_url('/'),
            'enabled' => self::enabled(),
            'environment' => MR_Bridge_Deploy_V1::environment(),
            'capabilities' => array(
                'status',
                'health',
                'plugin_inventory',
                'audit_hash_chain',
                'social_plugin_audit',
                'allowlisted_page_read',
                'allowlisted_page_backup',
                'allowlisted_page_update',
                'allowlisted_page_rollback',
                'github_oidc_staging',
                'allowlisted_plugin_upload',
                'sha256_verify',
                'atomic_deploy',
                'backup',
                'rollback',
                'locking',
                'idempotency',
                'staging_attestation',
                'social_engagement_status',
                'social_engagement_settings',
                'social_redteam',
                'social_queue',
                'social_batch_queue',
                'social_dispatch_adapter',
                'social_audit_log',
                'ops_status',
                'allowlisted_plugin_state',
                'cache_flush',
                'rewrite_flush',
                'allowlisted_options',
                'internal_job_queue',
                'self_update',
                'deploy_preflight',
                'backup_retention_cleanup',
                'stale_temp_cleanup',
                'api_contract_discovery',
                'network_retry_idempotency',
                'deploy_lock_renewal',
                'audit_anchor_retention',
                'self_deactivation_guard',
                'legacy_audit_migration',
                'audit_write_serialization',
                'zip_symlink_rejection',
                'active_upload_freshness',
                'inflight_request_retention',
                'wordpress_runtime_ci_gate',
                'seo_inventory_read_only',
                'direct_ed25519_auth',
                'encrypted_secret_vault',
                'allowlisted_cloudflare_provider',
                'allowlisted_worker_secret_write',
                'local_package_registry',
                'registered_package_deploy',
                'autonomous_job_scheduler',
                'github_fallback_only',
                'github_oidc_content_staging_production',
                'allowlisted_article_upsert',
                'allowlisted_event_upsert',
                'content_backup',
                'content_rollback',
                'local_backup_browser_pull',
                'local_backup_file_chunks_sha256',
                'local_backup_database_chunks_sha256',
                'local_backup_database_stability_check',
                'local_backup_resume',
                'local_backup_automatic_retry',
                'windows_weekly_backup_scheduler',
                'safe_storage_cleanup',
                'backup_scoped_token',
                'staging_yns_e2e_fixture_lifecycle',
            ),
            'page_allowlist' => array(self::PAGE_SLUG),
        ));
    }

    public static function contract() {
        return rest_ensure_response(array(
            'ok' => true,
            'bridge' => 'mr-bridge',
            'version' => self::VERSION,
            'contract_version' => self::CONTRACT_VERSION,
            'namespace' => self::REST_NAMESPACE,
            'environment' => MR_Bridge_Deploy_V1::environment(),
            'routes' => array(
                '/deploy/upload/start' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_environment_bound_deploy',
                    'required' => array('request_id','slug','version','sha256','total_size'),
                    'optional' => array('activate'),
                ),
                '/deploy/upload/chunk' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_environment_bound_deploy',
                    'required' => array('upload_id','index','data_base64','chunk_sha256'),
                ),
                '/deploy/upload/finalize' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_environment_bound_deploy',
                    'required' => array('upload_id','confirm'),
                    'confirm_format' => 'DEPLOY:{slug}:{sha256}',
                ),
                '/deploy/rollback' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_environment_bound_deploy',
                    'required' => array('request_id','backup_id','confirm'),
                    'confirm_format' => 'ROLLBACK:{backup_id}',
                ),
                '/ops/plugin/state' => array(
                    'method' => 'POST',
                    'auth' => 'wordpress_manage_options',
                    'required' => array('plugin','state','confirm'),
                    'state_values' => array('active','inactive'),
                ),
                '/ops/cache/flush' => array(
                    'method' => 'POST',
                    'auth' => 'wordpress_manage_options',
                    'required' => array('confirm'),
                    'confirm_value' => 'FLUSH:CACHE',
                ),
                '/ops/rewrite/flush' => array(
                    'method' => 'POST',
                    'auth' => 'wordpress_manage_options',
                    'required' => array('confirm'),
                    'confirm_value' => 'FLUSH:REWRITE',
                ),
                '/ops/jobs' => array(
                    'method' => 'POST',
                    'auth' => 'wordpress_manage_options',
                    'required' => array('type','request_id'),
                    'type_values' => array('cache_flush','rewrite_flush','health_snapshot'),
                ),
                '/seo/inventory' => array(
                    'method' => 'GET',
                    'auth' => 'wordpress_manage_options',
                    'required' => array(),
                    'optional' => array('q','type','status','limit','offset'),
                    'read_only' => true,
                ),
                '/content/status' => array(
                    'method' => 'GET',
                    'auth' => 'public_read_only',
                    'required' => array(),
                    'read_only' => true,
                ),
                '/content/get' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_environment_bound',
                    'required' => array('post_type'),
                    'optional' => array('post_id','slug'),
                    'post_type_values' => array('post','page','tribe_events'),
                    'read_only' => true,
                ),
                '/content/upsert' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_environment_bound',
                    'required' => array('target','request_id','post_type','title','content','slug','status','confirm'),
                    'optional' => array('post_id','excerpt','category_ids','tag_ids','featured_image_id','seo_title','seo_description','focus_keyword','canonical','indexable','follow','expected_content_sha256','post_date','post_date_gmt','start_date','end_date','timezone','venue_id'),
                    'post_type_values' => array('post','page','tribe_events'),
                    'status_values' => array('draft','publish'),
                    'confirm_values' => array('PUBLISH:STAGING','PUBLISH:PRODUCTION'),
                ),
                '/content/rollback' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_environment_bound',
                    'required' => array('target','backup_id','confirm'),
                    'confirm_values' => array('ROLLBACK:STAGING','ROLLBACK:PRODUCTION'),
                ),
                '/backup/info' => array(
                    'method' => 'GET',
                    'auth' => 'wordpress_or_backup_token',
                    'read_only' => true,
                ),
                '/backup/list' => array(
                    'method' => 'GET',
                    'auth' => 'wordpress_or_backup_token',
                    'required' => array(),
                    'optional' => array('path'),
                    'read_only' => true,
                ),
                '/backup/file' => array(
                    'method' => 'GET',
                    'auth' => 'wordpress_or_backup_token',
                    'required' => array('path','offset','length'),
                    'read_only' => true,
                ),
                '/backup/db/tables' => array(
                    'method' => 'GET',
                    'auth' => 'wordpress_or_backup_token',
                    'read_only' => true,
                ),
                '/backup/db/schema' => array(
                    'method' => 'GET',
                    'auth' => 'wordpress_or_backup_token',
                    'required' => array('table'),
                    'read_only' => true,
                ),
                '/backup/db/state' => array(
                    'method' => 'GET',
                    'auth' => 'wordpress_or_backup_token',
                    'required' => array('table'),
                    'read_only' => true,
                ),
                '/backup/db/chunk' => array(
                    'method' => 'GET',
                    'auth' => 'wordpress_or_backup_token',
                    'required' => array('table','offset','limit'),
                    'read_only' => true,
                ),
                '/backup/client/windows' => array(
                    'method' => 'POST',
                    'auth' => 'wordpress_manage_options',
                    'read_only' => false,
                    'effect' => 'rotate_backup_scoped_token_and_download_client',
                ),
                '/backup/token/revoke' => array(
                    'method' => 'POST',
                    'auth' => 'wordpress_manage_options',
                    'read_only' => false,
                    'effect' => 'revoke_backup_scoped_token',
                ),
                '/backup/receipt' => array(
                    'method' => 'POST',
                    'auth' => 'wordpress_or_backup_token',
                    'required' => array('format','completed_at','manifest_sha256','files','bytes','db_tables','db_sql_bytes'),
                    'effect' => 'record_completed_local_backup_receipt',
                ),
                '/backup/receipts' => array(
                    'method' => 'GET',
                    'auth' => 'wordpress_manage_options',
                    'read_only' => true,
                ),
                '/plugin-updates/list' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound',
                    'read_only' => true,
                    'effect' => 'list_available_plugin_updates',
                ),
                '/plugin-updates/apply' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound',
                    'required' => array('request_id','plugin','expected_current_version','expected_new_version','confirm'),
                    'confirm_format' => 'UPDATE:{plugin}:{current}->{new}',
                    'one_plugin_per_request' => true,
                    'automatic_backup' => true,
                    'automatic_rollback' => true,
                    'mr_bridge_self_update' => false,
                ),
                '/plugin-updates/rollback' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound',
                    'required' => array('request_id','backup_id','confirm'),
                    'confirm_format' => 'ROLLBACK-PLUGIN:{backup_id}',
                    'mr_bridge_self_update' => false,
                ),
                '/media/get' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound',
                    'read_only' => true,
                ),
                '/media/import' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound',
                    'source' => 'https_remote_only',
                    'allowed_mime' => array('image/jpeg','image/png','image/webp','image/gif','application/pdf'),
                    'max_bytes' => 15728640,
                    'rollback' => true,
                ),
                '/media/update' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound',
                    'metadata_only' => true,
                    'conflict_hash_required' => true,
                    'rollback' => true,
                ),
                '/media/rollback' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound',
                    'rollback' => true,
                ),
                '/cloud-control/status' => array(
                    'method' => 'GET',
                    'auth' => 'wordpress_manage_options_or_direct_read',
                    'read_only' => true,
                    'secret_values_returned' => false,
                ),
                '/cloud-control/vault/put' => array(
                    'method' => 'POST',
                    'auth' => 'wordpress_manage_options_or_direct_secrets_write',
                    'required' => array('name','value','confirm'),
                    'secret_name_allowlist' => array('cloudflare_api_token','stripe_secret_key'),
                    'secret_values_returned' => false,
                    'encrypted_at_rest' => true,
                ),
                '/cloud-control/vault/delete' => array(
                    'method' => 'POST',
                    'auth' => 'wordpress_manage_options_or_direct_secrets_write',
                    'required' => array('name','confirm'),
                    'secret_name_allowlist' => array('cloudflare_api_token','stripe_secret_key'),
                ),
                '/cloud-control/cloudflare/configure' => array(
                    'method' => 'POST',
                    'auth' => 'wordpress_manage_options_or_direct_providers_write',
                    'required' => array('account_id','confirm'),
                    'worker_allowlist' => array('professione-smart'),
                ),
                '/cloud-control/cloudflare/verify' => array(
                    'method' => 'POST',
                    'auth' => 'wordpress_manage_options_or_direct_providers_write',
                    'read_only_provider_call' => true,
                    'worker_allowlist' => array('professione-smart'),
                ),
                '/cloud-control/cloudflare/worker-secret' => array(
                    'method' => 'POST',
                    'auth' => 'wordpress_manage_options_or_direct_providers_write',
                    'required' => array('binding','source_secret','confirm'),
                    'worker_allowlist' => array('professione-smart'),
                    'binding_allowlist' => array('STRIPE_SECRET_KEY'),
                    'secret_values_returned' => false,
                ),
                '/maintenance/status' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound',
                    'read_only' => true,
                ),
                '/maintenance/storage' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound_or_direct',
                    'read_only' => true,
                ),
                '/maintenance/storage/cleanup' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound_or_direct',
                    'required' => array('request_id','path','expected_bytes','expected_mtime_unix','expected_sha256','confirm'),
                    'effect' => 'delete_only_preflighted_cache_upgrade_or_known_backup_archive',
                    'fail_closed' => true,
                ),
                '/maintenance/policy' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound',
                    'required' => array('confirm'),
                    'confirm_value' => 'MAINTENANCE-POLICY',
                ),
                '/maintenance/theme/apply' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound',
                    'policy_gated' => true,
                    'automatic_backup' => true,
                    'automatic_rollback' => true,
                ),
                '/maintenance/core/apply' => array(
                    'method' => 'POST',
                    'auth' => 'github_oidc_production_workflow_bound',
                    'policy_gated' => true,
                    'recent_local_backup_receipt_required' => true,
                ),
            ),
            'limits' => array(
                'chunk_bytes' => MR_Bridge_Deploy_V1::CHUNK_BYTES,
                'request_id_pattern' => '^[A-Za-z0-9._:-]{16,96}$',
                'backup_retention_per_slug' => MR_Bridge_Deploy_V1::BACKUP_RETENTION_PER_SLUG,
            ),
            'policy' => array(
                'arbitrary_php' => false,
                'arbitrary_sql' => false,
                'arbitrary_shell' => false,
                'production_mutations' => 'named_allowlisted_only',
                'production_mutation_classes' => array('content','media','plugin_updates','maintenance_policy','theme_updates','core_updates','safe_storage_cleanup','bridge_self_update'),
                'production_content_mutations' => true,
                'production_content_post_types' => array('post','page','tribe_events'),
                'production_content_requires_github_oidc' => true,
                'production_plugin_updates' => true,
                'production_plugin_updates_requires_github_oidc' => true,
                'production_plugin_updates_one_at_a_time' => true,
                'production_plugin_updates_automatic_backup' => true,
                'production_plugin_updates_automatic_rollback' => true,
                'mr_bridge_self_update_uses_dedicated_deploy' => true,
                'external_zip_host_required' => false,
                'github_required_for_normal_ops' => false,
                'direct_auth_mode' => 'ed25519-public-key',
                'server_stores_direct_private_keys' => false,
                'local_backup_server_archive_created' => false,
                'local_backup_scope' => 'wordpress_files_and_database',
                'local_backup_resume' => true,
                'local_backup_automatic_retry' => true,
                'windows_weekly_scheduler' => true,
                'backup_token_scope' => 'backup_read_and_receipt',
                'theme_updates_policy_gated' => true,
                'core_updates_policy_gated' => true,
                'core_updates_require_recent_local_backup_receipt' => true,
                'storage_audit_read_only' => true,
                'storage_cleanup_named_allowlist_only' => true,
                'storage_cleanup_requires_sha256_size_mtime_confirmation' => true,
                'media_remote_import' => true,
                'media_metadata_update' => true,
                'media_svg_upload' => false,
                'encrypted_secret_vault' => true,
                'vault_secret_values_returned' => false,
                'cloudflare_provider_allowlisted_only' => true,
                'cloudflare_worker_allowlist' => array('professione-smart'),
                'cloudflare_binding_allowlist' => array('STRIPE_SECRET_KEY'),
                'arbitrary_external_provider_requests' => false,
            ),
        ));
    }

    public static function health() {
        global $wpdb;

        $free = @disk_free_space(WP_CONTENT_DIR);
        $total = @disk_total_space(WP_CONTENT_DIR);

        $db_ok = false;
        try {
            $db_ok = ((string) $wpdb->get_var('SELECT 1') === '1');
        } catch (Throwable $e) {
            $db_ok = false;
        }

        $loopback_ok = false;
        $loopback_status = null;
        $loopback_error = '';
        $loopback_url = rest_url(self::REST_NAMESPACE . '/contract');
        $loopback = wp_remote_get($loopback_url, array(
            'timeout' => 4,
            'redirection' => 1,
            'sslverify' => true,
            'headers' => array('Cache-Control' => 'no-cache'),
        ));
        if (is_wp_error($loopback)) {
            $loopback_error = sanitize_text_field($loopback->get_error_code());
        } else {
            $loopback_status = (int) wp_remote_retrieve_response_code($loopback);
            $loopback_ok = ($loopback_status >= 200 && $loopback_status < 300);
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $critical = array(
            'mr_bridge' => is_plugin_active('yoganostress-bridge/yoganostress-bridge.php'),
            'events_calendar' => is_plugin_active('the-events-calendar/the-events-calendar.php'),
            'yoganostress_prenotazioni' => is_plugin_active('yoganostress-prenotazioni/yoganostress-prenotazioni.php'),
            'yns_whatsapp_api' => is_plugin_active('yns-whatsapp-api/yns-whatsapp-api.php'),
        );

        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $alternate_cron = defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON;
        $autonomous_next = wp_next_scheduled('mr_bridge_autonomous_run_job');

        $opcache_enabled = function_exists('opcache_get_status');
        $opcache_active = false;
        if ($opcache_enabled) {
            $op = @opcache_get_status(false);
            $opcache_active = is_array($op) && !empty($op['opcache_enabled']);
        }

        $out = array(
            'ok' => true,
            'version' => self::VERSION,
            'environment' => MR_Bridge_Deploy_V1::environment(),
            'wordpress' => get_bloginfo('version'),
            'php' => PHP_VERSION,
            'ziparchive' => class_exists('ZipArchive'),
            'openssl_verify' => function_exists('openssl_verify'),
            'sodium' => function_exists('sodium_crypto_sign_verify_detached'),
            'plugin_dir_writable' => is_writable(WP_PLUGIN_DIR),
            'content_dir_writable' => is_writable(WP_CONTENT_DIR),
            'disk_free_bytes' => $free === false ? null : (int) $free,
            'disk_total_bytes' => $total === false ? null : (int) $total,
            'database_ok' => $db_ok,
            'loopback_ok' => $loopback_ok,
            'loopback_http_status' => $loopback_status,
            'loopback_error_code' => $loopback_error,
            'cron' => array(
                'disabled' => (bool) $cron_disabled,
                'alternate' => (bool) $alternate_cron,
                'autonomous_next_gmt' => $autonomous_next ? gmdate('c', (int) $autonomous_next) : '',
            ),
            'cache' => array(
                'external_object_cache' => function_exists('wp_using_ext_object_cache') ? (bool) wp_using_ext_object_cache() : false,
                'opcache_available' => $opcache_enabled,
                'opcache_active' => $opcache_active,
            ),
            'critical_plugins' => $critical,
            'oidc_mode' => MR_Bridge_Deploy_V1::environment() === 'staging' ? 'github-oidc-staging' : 'github-oidc-production-named-actions',
            'direct_auth_available' => function_exists('sodium_crypto_sign_verify_detached'),
        );

        $out['ok'] = $out['ziparchive']
            && $out['openssl_verify']
            && $out['sodium']
            && $out['plugin_dir_writable']
            && $out['content_dir_writable']
            && $out['database_ok']
            && $out['loopback_ok']
            && $critical['mr_bridge'];

        self::log('health_read', array(
            'ok' => $out['ok'],
            'database_ok' => $db_ok,
            'loopback_ok' => $loopback_ok,
            'disk_free_bytes' => $out['disk_free_bytes'],
            'cron_disabled' => (bool) $cron_disabled,
        ));
        return rest_ensure_response($out);
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
        $events = (array) get_option(self::LOG_OPTION, array());
        return rest_ensure_response(array(
            'chain_valid' => self::verify_audit_chain($events),
            'anchor_hash' => (string) get_option(self::AUDIT_ANCHOR_OPTION, ''),
            'events' => array_reverse($events),
        ));
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
        if (!$slug && is_array($request->get_json_params())) {
            $body = $request->get_json_params();
            $slug = sanitize_title((string) ($body['slug'] ?? ''));
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

    private static function save_backup($post, $reason) {
        $items = self::backup_store();
        $backup_id = 'mr-page-' . gmdate('YmdHis') . '-' . strtolower(wp_generate_password(6, false, false));
        $items[] = array(
            'backup_id' => $backup_id,
            'created_at' => current_time('mysql', true),
            'reason' => sanitize_key($reason),
            'page' => self::page_payload($post, true),
        );
        if (count($items) > 10) { $items = array_slice($items, -10); }
        update_option(self::PAGE_BACKUPS_OPTION, $items, false);
        self::log('page_backup_created', array(
            'slug' => self::PAGE_SLUG,
            'backup_id' => $backup_id,
            'reason' => sanitize_key($reason),
            'content_sha256' => hash('sha256', (string) $post->post_content),
        ));
        return $backup_id;
    }

    private static function find_backup($backup_id) {
        foreach (self::backup_store() as $item) {
            if (isset($item['backup_id']) && hash_equals((string) $item['backup_id'], (string) $backup_id)) {
                return $item;
            }
        }
        return new WP_Error('mr_page_backup_missing', 'Backup non trovato.', array('status' => 404));
    }

    private static function validate_content($content) {
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

    private static function update_seo($post_id, $seo_title, $meta_description, $focus_keyword) {
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

    private static function restore_seo($post_id, $seo) {
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

    public static function page_read(WP_REST_Request $request) {
        $slug = self::page_slug_from_request($request);
        if (is_wp_error($slug)) { return $slug; }
        $post = self::get_allowed_page($slug);
        if (is_wp_error($post)) { return $post; }
        self::log('page_read', array('slug' => $slug, 'content_sha256' => hash('sha256', (string) $post->post_content)));
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
        if (!is_array($body)) { return new WP_Error('mr_bad_json', 'Payload JSON non valido.', array('status' => 400)); }
        $slug = self::page_slug_from_request($request);
        if (is_wp_error($slug)) { return $slug; }
        if (($body['confirm'] ?? '') !== 'BACKUP:' . $slug) {
            return new WP_Error('mr_page_confirm_required', 'Conferma backup non valida.', array('status' => 400));
        }
        $post = self::get_allowed_page($slug);
        if (is_wp_error($post)) { return $post; }
        $backup_id = self::save_backup($post, 'manual');
        return rest_ensure_response(array('ok' => true, 'backup_id' => $backup_id, 'page' => self::page_payload($post, false)));
    }

    public static function page_update(WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (!is_array($body)) { return new WP_Error('mr_bad_json', 'Payload JSON non valido.', array('status' => 400)); }
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
        $valid = self::validate_content($content);
        if (is_wp_error($valid)) { return $valid; }

        $new_hash = hash('sha256', $content);
        $old_hash = hash('sha256', (string) $post->post_content);
        $seo_requested = array_key_exists('seo_title', $body) || array_key_exists('meta_description', $body) || array_key_exists('focus_keyword', $body);
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

        $backup_id = self::save_backup($post, 'pre_update');

        $result = wp_update_post(wp_slash(array(
            'ID' => (int) $post->ID,
            'post_content' => $content,
        )), true);
        if (is_wp_error($result)) { return $result; }

        $seo_plugin = self::active_seo_plugin();
        if ($seo_requested) {
            $seo_result = self::update_seo(
                $post->ID,
                (string) ($body['seo_title'] ?? ''),
                (string) ($body['meta_description'] ?? ''),
                (string) ($body['focus_keyword'] ?? '')
            );
            if (is_wp_error($seo_result)) {
                $backup = self::find_backup($backup_id);
                if (!is_wp_error($backup)) {
                    wp_update_post(wp_slash(array('ID' => (int) $post->ID, 'post_content' => (string) $backup['page']['content'])));
                    self::restore_seo($post->ID, (array) ($backup['page']['seo'] ?? array()));
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
        if (!is_array($body)) { return new WP_Error('mr_bad_json', 'Payload JSON non valido.', array('status' => 400)); }
        $backup_id = sanitize_text_field((string) ($body['backup_id'] ?? ''));
        if ($backup_id === '' || ($body['confirm'] ?? '') !== 'ROLLBACK:' . $backup_id) {
            return new WP_Error('mr_page_confirm_required', 'Conferma rollback non valida.', array('status' => 400));
        }
        $backup = self::find_backup($backup_id);
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
        self::restore_seo($post->ID, (array) ($backup['page']['seo'] ?? array()));
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
}
MR_Bridge::init();
MR_Bridge_Deploy_V1::init();
MR_Bridge_Social_V1::init();
MR_Bridge_Ops_V1::init();
MR_Bridge_Autonomous_V1::init();
MR_Bridge_Cloud_Control_V1::init();
MR_Bridge_YNS_E2E_V1::init();
MR_Bridge_YNS_Launch_V1::init();

