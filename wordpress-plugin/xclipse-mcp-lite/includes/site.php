<?php
/** Site info, settings, themes, plugins */
defined('ABSPATH') || exit;

// INFO
function xclipse_info() {
    return xclipse_ok([
        'name'    => get_bloginfo('name'),
        'url'     => home_url(),
        'wp'      => get_bloginfo('version'),
        'php'     => PHP_VERSION,
        'lang'    => get_locale(),
        'tz'      => get_option('timezone_string') ?: get_option('gmt_offset'),
        'woo'     => class_exists('WooCommerce') ? WC()->version : null,
        'rm'      => defined('RANK_MATH_VERSION') ? RANK_MATH_VERSION : null,
        'burst'   => defined('BURST_VERSION') ? BURST_VERSION : null,
        'sitekit' => defined('GOOGLESITEKIT_VERSION') ? GOOGLESITEKIT_VERSION : null,
        'theme'   => wp_get_theme()->get('Name'),
        'api_v'   => XCLIPSE_MCP_VERSION,
        'lscache' => defined('LSCWP_V') ? LSCWP_V : null,
        'tools'   => ['serp/rank', 'serp/overview', 'serp/rank-bulk', 'site-speed', 'url-inspect',
                      'lscache/purge-all', 'lscache/purge-url', 'lscache/purge-tag', 'lscache/status',
                      'lscache/object-cache', 'lscache/cdn', 'lscache/critical-css', 'lscache/image-optm', 'lscache/crawl',
                      'rich-results', 'schema-validate',
                      'rankmath/tax/{taxonomy}', 'rankmath/tax/{taxonomy}/{term_id}', 'rankmath/tax/bulk'],
    ]);
}

// STATS
function xclipse_stats(WP_REST_Request $request) {
    $period = $request['period'] ?? '30d';
    $days   = $period === '7d' ? 7 : ($period === '90d' ? 90 : 30);
    $after = date('Y-m-d', strtotime("-{$days} days"));
    $str     = ['posts' => (int)wp_count_posts('post')->publish, 'pages' => (int)wp_count_posts('page')->publish, 'from' => $after];
    if (class_exists('WooCommerce')) {
        foreach (['pending','processing','completed','cancelled'] as $st)
            $str['orders'][$st] = (int)wc_orders_count($st);
        global $wpdb;
        $str['revenue'] = (float)($wpdb->get_var($wpdb->prepare(
            "SELECT SUM(m.meta_value) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id
             WHERE m.meta_key='_order_total' AND p.post_type='shop_order'
             AND p.post_status IN('wc-completed','wc-processing') AND p.post_date>=%s", $after
        )) ?? 0);
        $str['products'] = (int)wp_count_posts('product')->publish;
        $top = wc_get_products(['limit' => 5, 'orderby' => 'popularity', 'order' => 'DESC', 'return' => 'objects']);
        $str['top'] = array_map(fn($params) => ['id' => $params->get_id(), 'name' => $params->get_name(), 'sales' => (int)get_post_meta($params->get_id(), 'total_sales', true)], $top);
    }
    return xclipse_ok($str);
}

// THEMES
function xclipse_themes() {
    $args  = wp_get_theme();
    $all = wp_get_themes();
    return xclipse_ok(['active' => ['name' => $args->get('Name'), 'version' => $args->get('Version'), 'author' => $args->get('Author')],
        'installed' => array_map(fn($term) => ['name' => $term->get('Name'), 'version' => $term->get('Version'), 'active' => $term->get_stylesheet() === $args->get_stylesheet()],
        array_values($all))]);
}

// PLUGINS
function xclipse_plugins() {
    if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $all    = get_plugins();
    $active = get_option('active_plugins', []);
    $list   = [];
    foreach ($all as $field => $idx) $list[] = ['name' => $idx['Name'], 'ver' => $idx['Version'], 'active' => in_array($field, $active), 'file' => $field];
    usort($list, fn($args, $body) => $body['active'] <=> $args['active']);
    return xclipse_ok(['total' => count($list), 'active' => count(array_filter($list, fn($params) => $params['active'])), 'list' => $list]);
}

// WP options that may be read/written over MCP
const XCLIPSE_OPTS = [
    'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email',
    'blogpublic', 'default_category', 'default_comment_status',
    'posts_per_page', 'date_format', 'time_format', 'timezone_string',
    'permalink_structure', 'upload_path', 'upload_url_path',
];

function xclipse_settings_get(WP_REST_Request $request) {
    $keys = $request['keys'] ? array_intersect(array_map('sanitize_key', explode(',', $request['keys'])), XCLIPSE_OPTS) : XCLIPSE_OPTS;
    $out  = [];
    foreach ($keys as $key) $out[$key] = get_option($key);
    return xclipse_ok(['settings' => $out]);
}

function xclipse_settings_set(WP_REST_Request $request) {
    $body = $request->get_json_params();
    $ok = []; $skip = [];
    foreach (($body['settings'] ?? []) as $key => $value) {
        if (!in_array(sanitize_key($key), XCLIPSE_OPTS)) { $skip[] = $key; continue; }
        update_option(sanitize_key($key), sanitize_text_field((string)$value));
        $ok[$key] = get_option($key);
    }
    return xclipse_ok(['updated' => $ok, 'skipped' => $skip]);
}