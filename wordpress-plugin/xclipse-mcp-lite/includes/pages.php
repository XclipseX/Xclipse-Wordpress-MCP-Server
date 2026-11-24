<?php
/** Pages CRUD + bulk operations */
defined('ABSPATH') || exit;

// PAGES
function xclipse_pages_list(WP_REST_Request $request) {
    $status = sanitize_text_field($request['status'] ?? 'publish');
    $args   = [
        'number'      => min((int)($request['per_page'] ?? 30), 100),
        'post_status' => $status === 'any' ? ['publish','draft','private'] : $status,
        'sort_column' => $request['orderby'] ?? 'post_date',
        'sort_order'  => strtoupper($request['order'] ?? 'DESC'),
    ];
    if (!empty($request['slug']))      $args['post_name__in'] = [sanitize_title($request['slug'])];
    if (!empty($request['search']))    $args['search']        = sanitize_text_field($request['search']);
    if (!empty($request['author_id'])) $args['authors']       = (int)$request['author_id'];
    $pages  = get_pages($args);
    $fields = xclipse_parse_fields($request['fields'] ?? '');
    return xclipse_ok(array_map(fn($params) => xclipse_filter_fields(xclipse_compact([
        'id'          => $params->ID,
        'title'       => $params->post_title,
        'slug'        => $params->post_name,
        'st'          => $params->post_status,
        'url'         => get_permalink($params->ID),
        'parent'      => $params->post_parent ?: null,
        'created_at'  => $params->post_date,
        'modified_at' => $params->post_modified,
        'author'      => xclipse_author_info((int)$params->post_author),
    ]), $fields), $pages ?: []));
}

function xclipse_page_get(WP_REST_Request $request) {
    $params = get_post((int)$request['id']);
    if (!$params || $params->post_type !== 'page') return xclipse_err('not_found', 'Không tìm thấy trang');
    // Page template
    $template = get_page_template_slug($params->ID);
    // Featured image
    $thumb = get_the_post_thumbnail_url($params->ID, 'medium') ?: null;
    return xclipse_ok(xclipse_compact([
        'id'          => $params->ID,
        'title'       => $params->post_title,
        'slug'        => $params->post_name,
        'status'      => $params->post_status,
        'created_at'  => $params->post_date,
        'modified_at' => $params->post_modified,
        'url'         => get_permalink($params->ID),
        'parent'      => $params->post_parent ?: null,
        'order'       => (int)$params->menu_order,
        'template'    => $template ?: 'default',
        'excerpt'     => $params->post_excerpt,
        'content'     => $params->post_content,
        'thumb'       => $thumb,
        'author'      => xclipse_author_info((int)$params->post_author),
        'seo'         => xclipse_seo($params->ID),
    ]));
}

function xclipse_page_create(WP_REST_Request $request) {
    $body  = $request->get_json_params();
    $data  = [
        'post_type'    => 'page',
        'post_title'   => sanitize_text_field($body['title'] ?? ''),
        'post_content' => wp_kses_post($body['content'] ?? ''),
        'post_excerpt' => sanitize_text_field($body['excerpt'] ?? ''),
        'post_status'  => sanitize_text_field($body['status'] ?? 'draft'),
        'post_name'    => sanitize_title($body['slug'] ?? ''),
        'post_parent'  => (int)($body['parent'] ?? 0),
        'menu_order'   => (int)($body['order'] ?? 0),
        'post_author'  => (int)($body['author_id'] ?? get_current_user_id()),
    ];
    $id = wp_insert_post($data, true);
    if (is_wp_error($id)) return $id;
    // Template
    if (!empty($body['template'])) update_post_meta($id, '_wp_page_template', sanitize_text_field($body['template']));
    // Featured image
    if (!empty($body['thumbnail_id'])) set_post_thumbnail($id, (int)$body['thumbnail_id']);
    // SEO
    xclipse_save_seo($id, $body);
    return xclipse_ok(['id' => $id, 'url' => get_permalink($id), 'status' => get_post_status($id)]);
}

function xclipse_page_update(WP_REST_Request $request) {
    $id = (int)$request['id'];
    $params  = get_post($id);
    if (!$params || $params->post_type !== 'page') return xclipse_err('not_found', 'Không tìm thấy trang');
    $body  = $request->get_json_params();
    $data  = ['ID' => $id];
    if (isset($body['title']))     $data['post_title']   = sanitize_text_field($body['title']);
    if (isset($body['content']))   $data['post_content'] = wp_kses_post($body['content']);
    if (isset($body['excerpt']))   $data['post_excerpt'] = sanitize_text_field($body['excerpt']);
    if (isset($body['status']))    $data['post_status']  = sanitize_text_field($body['status']);
    if (isset($body['slug']))      $data['post_name']    = sanitize_title($body['slug']);
    if (isset($body['parent']))    $data['post_parent']  = (int)$body['parent'];
    if (isset($body['order']))     $data['menu_order']   = (int)$body['order'];
    if (isset($body['author_id'])) $data['post_author']  = (int)$body['author_id'];
    $res = wp_update_post($data, true);
    if (is_wp_error($res)) return $res;
    if (isset($body['template']))     update_post_meta($id, '_wp_page_template', sanitize_text_field($body['template']));
    if (isset($body['thumbnail_id'])) {
        $body['thumbnail_id'] ? set_post_thumbnail($id, (int)$body['thumbnail_id']) : delete_post_thumbnail($id);
    }
    xclipse_save_seo($id, $body);
    return xclipse_ok([
        'ok'  => true,
        'id'  => $id,
        'url' => get_permalink($id),
    ]);
}

function xclipse_page_delete(WP_REST_Request $request) {
    $id    = (int)$request['id'];
    $force = (bool)($request->get_param('force') ?? false);
    $params     = get_post($id);
    if (!$params || $params->post_type !== 'page') return xclipse_err('not_found', 'Không tìm thấy trang');
    $force ? wp_delete_post($id, true) : wp_trash_post($id);
    return xclipse_ok(['ok' => true, 'id' => $id, 'action' => $force ? 'deleted' : 'trashed']);
}

function xclipse_pages_bulk_update(WP_REST_Request $request) {
    $body        = $request->get_json_params();
    $ids      = array_map('intval', (array)($body['ids'] ?? []));
    $field    = sanitize_text_field($body['field'] ?? '');
    $value    = $body['value'] ?? null;
    $dry_run  = !empty($body['dry_run']);

    $allowed = ['status', 'author_id', 'parent', 'template'];
    if (!$field || !in_array($field, $allowed, true)) {
        return xclipse_err('invalid_field', 'field phải là: ' . implode(', ', $allowed));
    }
    if (empty($ids)) return xclipse_err('ids_required', 'Thiếu ids');

    $results = [];
    foreach ($ids as $id) {
        $params = get_post($id);
        if (!$params || $params->post_type !== 'page') {
            $results[] = ['id' => $id, 'status' => 'not_found'];
            continue;
        }
        if (!$dry_run) {
            if ($field === 'template') {
                update_post_meta($id, '_wp_page_template', sanitize_text_field((string)$value));
            } else {
                $map = ['status' => 'post_status', 'author_id' => 'post_author', 'parent' => 'post_parent'];
                wp_update_post(['ID' => $id, $map[$field] => sanitize_text_field((string)$value)]);
            }
        }
        $results[] = ['id' => $id, 'title' => $params->post_title, 'status' => $dry_run ? 'would_update' : 'updated'];
    }
    if (!$dry_run) xclipse_changelog_add('pages_bulk_update', "Set {$field}={$value} on " . count($ids) . ' pages', $ids);
    return xclipse_ok(['dry_run' => $dry_run, 'count' => count($results), 'results' => $results]);
}

// MENUS
// BULK OPERATIONS

/**
 * Bulk-update several posts at once.
 * Body: { "items": [{"id":123,"title":"...","content":"...","status":"...","meta":{}},…] }
 * Each item needs an "id". Only the provided fields are updated.
 */
function xclipse_bulk_posts(WP_REST_Request $request) {
    $body        = $request->get_json_params();
    $items    = $body['items'] ?? [];
    $dry_run  = !empty($body['dry_run']);
    if (empty($items) || !is_array($items))
        return xclipse_err('empty', 'items phải là array [{id, title?, content?, status?, meta?},…]');
    $ok = []; $fail = [];
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        if (!$id || !get_post($id)) { $fail[] = ['id' => $id, 'error' => 'not_found']; continue; }
        if ($dry_run) { $ok[] = $id; continue; }
        $data = ['ID' => $id];
        if (isset($item['title']))      $data['post_title']    = sanitize_text_field($item['title']);
        if (isset($item['content']))    $data['post_content']  = wp_kses_post($item['content']);
        if (isset($item['excerpt']))    $data['post_excerpt']  = sanitize_textarea_field($item['excerpt']);
        if (isset($item['status']))     $data['post_status']   = sanitize_key($item['status']);
        if (isset($item['slug']))       $data['post_name']     = sanitize_title($item['slug']);
        if (isset($item['date']))       $data['post_date']     = sanitize_text_field($item['date']);
        if (isset($item['categories'])) $data['post_category'] = array_map('intval', (array)$item['categories']);
        if (isset($item['tags']))       $data['tags_input']    = is_array($item['tags']) ? implode(',', $item['tags']) : $item['tags'];
        $res = wp_update_post($data, true);
        if (is_wp_error($res)) { $fail[] = ['id' => $id, 'error' => $res->get_error_message()]; continue; }
        if (isset($item['meta']) && is_array($item['meta'])) {
            foreach ($item['meta'] as $key => $val)
                update_post_meta($id, sanitize_key($key), is_array($val) ? $val : sanitize_text_field((string)$val));
        }
        if (isset($item['seo_title'])) update_post_meta($id, 'rank_math_title',           sanitize_text_field($item['seo_title']));
        if (isset($item['seo_desc']))  update_post_meta($id, 'rank_math_description',      sanitize_textarea_field($item['seo_desc']));
        if (isset($item['focus_kw']))  update_post_meta($id, 'rank_math_focus_keyword',    sanitize_text_field($item['focus_kw']));
        $ok[] = $id;
    }
    if (!$dry_run && $ok) xclipse_changelog_add('bulk_posts_update', 'Updated ' . count($ok) . ' posts', $ok);
    return xclipse_ok(['dry_run' => $dry_run, 'ok' => $ok, 'fail' => $fail, 'count' => count($ok)]);
}