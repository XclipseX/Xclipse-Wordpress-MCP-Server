<?php
/**
 * Plugin Name: Xclipse MCP Lite
 * Version:     1.0.0
 * Description: Lightweight WordPress REST API bridge for Xclipse WordPress MCP Server (Core Lite — 18 tools: site info, posts, pages, media, categories, tags, comments).
 * Author:      Xclipse
 * License:     MIT
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
defined('ABSPATH') || exit;

define('XCLIPSE_MCP_VERSION', '1.0.0');

// ─── Activation ──────────────────────────────────────────────────────────────
register_activation_hook(__FILE__, function () {
    if (!get_option('xclipse_mcp_api_key')) {
        update_option('xclipse_mcp_api_key', bin2hex(random_bytes(32)));
    }
});

// ─── Minimal Admin Settings Page (Settings -> Xclipse MCP Lite) ──────────────
add_action('admin_menu', function () {
    add_options_page(
        'Xclipse MCP Lite',
        'Xclipse MCP Lite',
        'manage_options',
        'xclipse-mcp-lite',
        'xclipse_lite_settings_page'
    );
});

function xclipse_lite_settings_page() {
    if (!current_user_can('manage_options')) return;

    if (isset($_POST['xclipse_regen_key']) && check_admin_referer('xclipse_lite_regen')) {
        update_option('xclipse_mcp_api_key', bin2hex(random_bytes(32)));
        echo '<div class="notice notice-success"><p>API Key regenerated.</p></div>';
    }

    $api_key  = (string) get_option('xclipse_mcp_api_key', '');
    $site_url = home_url();
    ?>
    <div class="wrap">
        <h1>Xclipse MCP Lite <small style="font-weight:normal;color:#666;">v<?php echo esc_html(XCLIPSE_MCP_VERSION); ?></small></h1>
        <p>Lightweight WordPress REST bridge for <strong>Xclipse WordPress MCP Server (18 Core Tools)</strong>.</p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Site URL (<code>XCLIPSE_URL</code>)</th>
                <td><input type="text" class="regular-text code" readonly value="<?php echo esc_attr($site_url); ?>" onclick="this.select();" /></td>
            </tr>
            <tr>
                <th scope="row">API Key (<code>XCLIPSE_API_KEY</code>)</th>
                <td>
                    <input type="text" class="large-text code" readonly value="<?php echo esc_attr($api_key); ?>" onclick="this.select();" />
                    <form method="post" style="margin-top:8px;">
                        <?php wp_nonce_field('xclipse_lite_regen'); ?>
                        <button type="submit" name="xclipse_regen_key" class="button" onclick="return confirm('Regenerate API key? Existing MCP clients will need the new key.');">Regenerate API Key</button>
                    </form>
                </td>
            </tr>
        </table>
        <hr />
        <p>Need <strong>390+ tools</strong> (WooCommerce, Rank Math SEO, Elementor, Flatsome UX, LiteSpeed Cache, ACF, OAuth 2.1, Safety Mode)?
            <a href="https://www.agensi.io/r/XCLIPEWPMCPSRVR?to=/skills/xclipse-wordpress-mcp-390-tools-oauth-2-1" target="_blank" rel="noopener noreferrer">Upgrade to Xclipse WordPress MCP Pro &rarr;</a>
        </p>
    </div>
    <?php
}

// ─── PHP 7.4 Polyfills ───────────────────────────────────────────────────────
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

// ─── Auth ────────────────────────────────────────────────────────────────────
function xclipse_auth(WP_REST_Request $req) {
    $expected = (string) get_option('xclipse_mcp_api_key', '');
    $item_key = (string) $req->get_header('X-MCP-Key');
    if ($item_key === '' || $expected === '') {
        return new WP_Error('unauthorized', 'Invalid key', ['status' => 401]);
    }
    return hash_equals($expected, $item_key)
        ? true
        : new WP_Error('unauthorized', 'Invalid key', ['status' => 401]);
}

function xclipse_ping(WP_REST_Request $req): array {
    return ['ok' => true, 'api_v' => XCLIPSE_MCP_VERSION, 'edition' => 'lite', 'time' => time()];
}

// ─── Helpers ─────────────────────────────────────────────────────────────────
function xclipse_author_info(int $user_id): ?array {
    if (!$user_id) return null;
    $user = get_userdata($user_id);
    if (!$user) return null;
    return [
        'id'         => $user->ID,
        'name'       => $user->display_name,
        'login'      => $user->user_login,
        'email'      => $user->user_email,
        'role'       => implode(',', $user->roles),
        'registered' => substr($user->user_registered, 0, 10),
    ];
}

function xclipse_compact(array $payload_data, int $max_str = 0): array {
    $out = [];
    foreach ($payload_data as $item_key => $value) {
        if ($value === null || $value === '' || $value === [] || $value === false) continue;
        if ($max_str && is_string($value) && mb_strlen($value) > $max_str) {
            $value = mb_substr($value, 0, $max_str) . '…';
        }
        $out[$item_key] = $value;
    }
    return $out;
}

function xclipse_parse_fields(string $raw): array {
    if (!$raw) return [];
    return array_filter(array_map('trim', explode(',', $raw)));
}

function xclipse_filter_fields(array $item, array $fields): array {
    if (empty($fields)) return $item;
    return array_intersect_key($item, array_flip($fields));
}

function xclipse_ok(array $data): WP_REST_Response {
    return rest_ensure_response($data);
}

function xclipse_err(string $code, string $msg, int $status = 404): WP_Error {
    return new WP_Error($code, $msg, ['status' => $status]);
}

// ─── SEO meta helper (Rank Math / Yoast optional passthrough) ────────────────
function xclipse_seo(int $id): array {
    global $wpdb;
    if (defined('RANK_MATH_VERSION')) {
        $keys = [
            'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword',
            'rank_math_seo_score', 'rank_math_robots', 'rank_math_canonical_url',
        ];
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key IN ({$placeholders}) ORDER BY umeta_id DESC",
            array_merge([$id], $keys)
        ), ARRAY_A);
        $meta = [];
        foreach ($rows as $row) {
            if (!isset($meta[$row['meta_key']])) $meta[$row['meta_key']] = $row['meta_value'];
        }
        return array_filter([
            'p'         => 'rm',
            't'         => $meta['rank_math_title']         ?? null,
            'd'         => $meta['rank_math_description']   ?? null,
            'k'         => $meta['rank_math_focus_keyword'] ?? null,
            's'         => $meta['rank_math_seo_score']     ?? null,
            'r'         => $meta['rank_math_robots']        ?? null,
            'canonical' => $meta['rank_math_canonical_url'] ?? null,
        ], fn($value) => $value !== '' && $value !== null && $value !== false);
    }
    return array_filter([
        'p' => 'yoast',
        't' => get_post_meta($id, '_yoast_wpseo_title',    true),
        'd' => get_post_meta($id, '_yoast_wpseo_metadesc', true),
        'k' => get_post_meta($id, '_yoast_wpseo_focuskw',  true),
    ], fn($value) => $value !== '');
}

function xclipse_save_seo(int $id, array $payload): void {
    global $wpdb;
    if (defined('RANK_MATH_VERSION')) {
        $map = [
            'seo_title' => 'rank_math_title',
            'seo_desc'  => 'rank_math_description',
            'focus_kw'  => 'rank_math_focus_keyword',
            'canonical' => 'rank_math_canonical_url',
        ];
        foreach ($map as $param => $meta_key) {
            if (!isset($payload[$param])) continue;
            $val = ($param === 'canonical')
                ? esc_url_raw($payload[$param])
                : sanitize_text_field($payload[$param]);
            $wpdb->delete($wpdb->postmeta, ['post_id' => $id, 'meta_key' => $meta_key]);
            if ($val !== '') {
                $wpdb->insert($wpdb->postmeta, [
                    'post_id'    => $id,
                    'meta_key'   => $meta_key,
                    'meta_value' => $val,
                ]);
            }
        }
        wp_cache_delete($id, 'post_meta');
    } else {
        if (isset($payload['seo_title'])) update_post_meta($id, '_yoast_wpseo_title',    sanitize_text_field($payload['seo_title']));
        if (isset($payload['seo_desc']))  update_post_meta($id, '_yoast_wpseo_metadesc', sanitize_text_field($payload['seo_desc']));
        if (isset($payload['focus_kw']))  update_post_meta($id, '_yoast_wpseo_focuskw',  sanitize_text_field($payload['focus_kw']));
    }
}

// ─── Load 6 Core Modules ─────────────────────────────────────────────────────
require_once __DIR__ . '/includes/site.php';
require_once __DIR__ . '/includes/posts.php';
require_once __DIR__ . '/includes/pages.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/taxonomy.php';
require_once __DIR__ . '/includes/comments.php';

// ─── Cache headers for MCP API responses ─────────────────────────────────────
add_filter('rest_post_dispatch', function ($response, $server, $request) {
    if ($response instanceof WP_HTTP_Response && str_starts_with($request->get_route(), '/xclipse-mcp/v1')) {
        $response->header('Cache-Control', 'no-store, private');
    }
    return $response;
}, 10, 3);

// ─── Register Core REST Routes ───────────────────────────────────────────────
add_action('rest_api_init', function () {
    $ns   = 'xclipse-mcp/v1';
    $auth = 'xclipse_auth';
    $req  = fn($route, $method, $cb) => register_rest_route($ns, $route, [
        'methods'             => $method,
        'callback'            => $cb,
        'permission_callback' => $auth,
    ]);

    // Site Info
    $req('/info',              'GET',    'xclipse_info');
    $req('/ping',              'GET',    'xclipse_ping');

    // Posts
    $req('/posts',             'GET',    'xclipse_posts_list');
    $req('/posts',             'POST',   'xclipse_post_create');
    $req('/posts/(?P<id>\d+)', 'GET',    'xclipse_post_get');
    $req('/posts/(?P<id>\d+)', 'PUT',    'xclipse_post_update');
    $req('/posts/(?P<id>\d+)', 'DELETE', 'xclipse_post_delete');

    // Pages
    $req('/pages',             'GET',    'xclipse_pages_list');
    $req('/pages',             'POST',   'xclipse_page_create');
    $req('/pages/(?P<id>\d+)', 'GET',    'xclipse_page_get');
    $req('/pages/(?P<id>\d+)', 'PUT',    'xclipse_page_update');
    $req('/pages/(?P<id>\d+)', 'DELETE', 'xclipse_page_delete');

    // Media
    $req('/media',             'GET',    'xclipse_media_list');
    $req('/media',             'POST',   'xclipse_media_upload');
    $req('/media/(?P<id>\d+)', 'GET',    'xclipse_media_get');

    // Categories & Tags
    $req('/categories',        'GET',    'xclipse_cats');
    $req('/tags',              'GET',    'xclipse_tags');

    // Comments
    $req('/comments',              'GET', 'xclipse_comments_list');
    $req('/comments/(?P<id>\d+)',  'PUT', 'xclipse_comment_update');
});
