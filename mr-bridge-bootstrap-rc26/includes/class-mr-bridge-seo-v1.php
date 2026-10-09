<?php
if (!defined('ABSPATH')) { exit; }

final class MR_Bridge_SEO_V1 {
    const REST_NAMESPACE = 'mr-bridge/v1';
    const MAX_LIMIT = 200;

    public static function init() {
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/seo/inventory', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'inventory'),
            'permission_callback' => array(__CLASS__, 'can_manage'),
            'args' => array(
                'q' => array('required' => false, 'type' => 'string'),
                'type' => array('required' => false, 'type' => 'string', 'default' => 'any'),
                'status' => array('required' => false, 'type' => 'string', 'default' => 'publish'),
                'limit' => array('required' => false, 'type' => 'integer', 'default' => 100),
                'offset' => array('required' => false, 'type' => 'integer', 'default' => 0),
            ),
        ));
    }

    public static function can_manage() {
        return get_option('mr_bridge_enabled', '1') === '1' && current_user_can('manage_options');
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

    private static function seo_snapshot($post_id, $permalink) {
        $plugin = self::active_seo_plugin();

        $yoast_title = (string) get_post_meta($post_id, '_yoast_wpseo_title', true);
        $yoast_description = (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
        $yoast_focus = (string) get_post_meta($post_id, '_yoast_wpseo_focuskw', true);
        $yoast_canonical = (string) get_post_meta($post_id, '_yoast_wpseo_canonical', true);
        $yoast_noindex = (string) get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true);
        $yoast_nofollow = (string) get_post_meta($post_id, '_yoast_wpseo_meta-robots-nofollow', true);

        $rank_title = (string) get_post_meta($post_id, 'rank_math_title', true);
        $rank_description = (string) get_post_meta($post_id, 'rank_math_description', true);
        $rank_focus = (string) get_post_meta($post_id, 'rank_math_focus_keyword', true);
        $rank_canonical = (string) get_post_meta($post_id, 'rank_math_canonical_url', true);
        $rank_robots = get_post_meta($post_id, 'rank_math_robots', true);
        if (!is_array($rank_robots)) { $rank_robots = array(); }

        if ($plugin === 'yoast') {
            $canonical = $yoast_canonical !== '' ? $yoast_canonical : $permalink;
            $indexable = $yoast_noindex !== '1';
            $follow = $yoast_nofollow !== '1';
            return array(
                'plugin' => 'yoast',
                'title' => $yoast_title,
                'description' => $yoast_description,
                'focus_keyword' => $yoast_focus,
                'canonical' => $canonical,
                'custom_canonical' => $yoast_canonical,
                'indexable' => $indexable,
                'follow' => $follow,
                'robots_noindex_raw' => $yoast_noindex,
                'robots_nofollow_raw' => $yoast_nofollow,
            );
        }

        if ($plugin === 'rank-math') {
            $canonical = $rank_canonical !== '' ? $rank_canonical : $permalink;
            return array(
                'plugin' => 'rank-math',
                'title' => $rank_title,
                'description' => $rank_description,
                'focus_keyword' => $rank_focus,
                'canonical' => $canonical,
                'custom_canonical' => $rank_canonical,
                'indexable' => !in_array('noindex', $rank_robots, true),
                'follow' => !in_array('nofollow', $rank_robots, true),
                'robots' => array_values($rank_robots),
            );
        }

        return array(
            'plugin' => 'none',
            'title' => '',
            'description' => '',
            'focus_keyword' => '',
            'canonical' => $permalink,
            'custom_canonical' => '',
            'indexable' => true,
            'follow' => true,
        );
    }

    private static function matches_terms($post, $terms) {
        if (empty($terms)) { return true; }
        $haystack = strtolower((string) $post->post_name . ' ' . (string) $post->post_title);
        foreach ($terms as $term) {
            if ($term !== '' && strpos($haystack, $term) !== false) { return true; }
        }
        return false;
    }

    public static function inventory(WP_REST_Request $request) {
        $type = sanitize_key((string) $request->get_param('type'));
        if (!in_array($type, array('any', 'post', 'page'), true)) {
            return new WP_Error('mr_seo_bad_type', 'Tipo contenuto non valido.', array('status' => 400));
        }

        $status = sanitize_key((string) $request->get_param('status'));
        $allowed_statuses = array('publish', 'draft', 'private', 'pending', 'future', 'any');
        if (!in_array($status, $allowed_statuses, true)) {
            return new WP_Error('mr_seo_bad_status', 'Stato contenuto non valido.', array('status' => 400));
        }

        $limit = max(1, min(self::MAX_LIMIT, (int) $request->get_param('limit')));
        $offset = max(0, (int) $request->get_param('offset'));

        $q = strtolower(sanitize_text_field((string) $request->get_param('q')));
        $raw_terms = preg_split('/[|,]+/', $q);
        $terms = array();
        foreach ((array) $raw_terms as $term) {
            $term = trim($term);
            if ($term !== '') { $terms[] = $term; }
        }
        $terms = array_values(array_unique($terms));

        $post_types = $type === 'any' ? array('post', 'page') : array($type);
        $post_status = $status === 'any' ? array('publish', 'draft', 'private', 'pending', 'future') : array($status);

        $query = new WP_Query(array(
            'post_type' => $post_types,
            'post_status' => $post_status,
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'all',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ));

        $matched = array();
        foreach ((array) $query->posts as $post) {
            if (!self::matches_terms($post, $terms)) { continue; }
            $permalink = get_permalink($post);
            $plain = trim(wp_strip_all_tags(strip_shortcodes((string) $post->post_content)));
            $matched[] = array(
                'id' => (int) $post->ID,
                'type' => (string) $post->post_type,
                'status' => (string) $post->post_status,
                'slug' => (string) $post->post_name,
                'title' => (string) $post->post_title,
                'permalink' => (string) $permalink,
                'modified_gmt' => (string) $post->post_modified_gmt,
                'parent' => (int) $post->post_parent,
                'content_chars' => strlen($plain),
                'seo' => self::seo_snapshot((int) $post->ID, (string) $permalink),
            );
        }

        $total = count($matched);
        $items = array_slice($matched, $offset, $limit);

        if (class_exists('MR_Bridge') && method_exists('MR_Bridge', 'log')) {
            MR_Bridge::log('seo_inventory_read', array(
                'q' => implode('|', $terms),
                'type' => $type,
                'status' => $status,
                'total' => $total,
                'returned' => count($items),
            ));
        }

        return rest_ensure_response(array(
            'ok' => true,
            'read_only' => true,
            'seo_plugin' => self::active_seo_plugin(),
            'query_terms' => $terms,
            'type' => $type,
            'status' => $status,
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'items' => $items,
        ));
    }
}

MR_Bridge_SEO_V1::init();

