<?php
/** Media upload, list, get, update, delete */
defined('ABSPATH') || exit;

// MEDIA
function xclipse_media_list(WP_REST_Request $request) {
    $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => min((int)($request['per_page'] ?? 20),
        50), 'paged' => (int)($request['page'] ?? 1), 's' => $request['search'] ?? '', 'post_mime_type' => $request['mime'] ?? 'image',
        'orderby' => 'date', 'order' => 'DESC']);
    return xclipse_ok(['total' => $query->found_posts, 'items' => array_map(fn($params) => ['id' => $params->ID, 'title' => $params->post_title,
        'url' => wp_get_attachment_url($params->ID), 'alt' => get_post_meta($params->ID, '_wp_attachment_image_alt', true),
        'date' => substr($params->post_date, 0, 10)], $query->posts)]);
}

function xclipse_media_get(WP_REST_Request $request) {
    $params = get_post((int)$request['id']);
    if (!$params || $params->post_type !== 'attachment') return xclipse_err('not_found', 'Không tìm thấy');
    $match = wp_get_attachment_metadata($params->ID);
    return xclipse_ok(xclipse_compact(['id' => $params->ID, 'title' => $params->post_title, 'caption' => $params->post_excerpt,
        'alt' => get_post_meta($params->ID, '_wp_attachment_image_alt', true), 'url' => wp_get_attachment_url($params->ID),
        'mime' => $params->post_mime_type, 'date' => substr($params->post_date, 0, 10), 'w' => $match['width'] ?? null, 'h' => $match['height'] ?? null,
        'size' => $match['filesize'] ?? null]));
}

function xclipse_media_upload(WP_REST_Request $request) {
    $body = $request->get_json_params();
    if (empty($body['url'])) return xclipse_err('missing', 'Cần url', 400);
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $tmp  = download_url(esc_url_raw($body['url']));
    if (is_wp_error($tmp)) return $tmp;
    $file = ['name' => sanitize_file_name(basename(parse_url($body['url'], PHP_URL_PATH)) ?: 'upload.jpg'), 'tmp_name' => $tmp];
    $id   = media_handle_sideload($file, (int)($body['post_id'] ?? 0), $body['title'] ?? null);
    if (is_wp_error($id)) { @unlink($tmp); return $id; }
    if (!empty($body['alt'])) update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($body['alt']));
    return xclipse_ok(['ok' => true, 'id' => $id, 'url' => wp_get_attachment_url($id)]);
}

function xclipse_media_update(WP_REST_Request $request) {
    $id = (int)$request['id'];
    $body  = $request->get_json_params();
    $payload_data  = ['ID' => $id];
    if (isset($body['title']))       $payload_data['post_title']   = sanitize_text_field($body['title']);
    if (isset($body['caption']))     $payload_data['post_excerpt'] = sanitize_text_field($body['caption']);
    if (isset($body['description'])) $payload_data['post_content'] = sanitize_text_field($body['description']);
    wp_update_post($payload_data);
    if (isset($body['alt'])) update_post_meta($id, '_wp_attachment_image_alt', sanitize_text_field($body['alt']));
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_media_delete(WP_REST_Request $request) {
    wp_delete_attachment((int)$request['id'], (bool)($request['force'] ?? false));
    return xclipse_ok(['ok' => true, 'id' => (int)$request['id']]);
}

// ── Base64 image upload ──
function xclipse_media_upload_base64(WP_REST_Request $request) {
    $body = $request->get_json_params();
    $body = is_array($body) ? $body : [];
    $data = (string) ($body['data'] ?? '');
    $name = sanitize_file_name((string) ($body['filename'] ?? 'upload.jpg'));
    if ($data === '') return xclipse_err('bad_request', 'Thiếu "data" (base64)', 400);
    if (preg_match('/^data:[^;]+;base64,/', $data)) $data = preg_replace('/^data:[^;]+;base64,/', '', $data);
    $bin = xclipse_b64_decode($data);
    if ($bin === false || $bin === '') return xclipse_err('bad_base64', 'base64 không hợp lệ', 400);
    if (strlen($bin) > 15 * 1024 * 1024) return xclipse_err('too_large', 'Tối đa 15MB', 413);

    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
    $ft = wp_check_filetype($name);
    if (empty($ft['type']) || !in_array($ft['type'], $allowed_types, true))
        return xclipse_err('bad_type', 'Chỉ cho phép JPEG/PNG/GIF/WebP/PDF', 400);
    // Verify the real file content matches the extension (blocks SVG/polyglots/renamed scripts).
    require_once ABSPATH . 'wp-admin/includes/file.php';
    $tmp_check = wp_tempnam($name);
    file_put_contents($tmp_check, $bin);
    $real = wp_check_filetype_and_ext($tmp_check, $name);
    $sniff = function_exists('finfo_open') ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp_check) : '';
    @unlink($tmp_check);
    if ((!empty($real['type']) && $real['type'] !== $ft['type']) || ($sniff !== '' && $sniff !== $ft['type']))
        return xclipse_err('bad_type', 'Nội dung file không khớp với đuôi filename', 400);

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $up = wp_upload_bits($name, null, $bin);
    if (!empty($up['error'])) return xclipse_err('upload_failed', $up['error'], 500);

    $att = wp_insert_attachment([
        'post_mime_type' => $ft['type'],
        'post_title'     => sanitize_text_field($body['title'] ?? pathinfo($name, PATHINFO_FILENAME)),
        'post_status'    => 'inherit',
    ], $up['file'], (int) ($body['post_id'] ?? 0));
    if (is_wp_error($att)) return xclipse_err('insert_failed', $att->get_error_message(), 500);

    wp_update_attachment_metadata($att, wp_generate_attachment_metadata($att, $up['file']));
    if (!empty($body['alt'])) update_post_meta($att, '_wp_attachment_image_alt', sanitize_text_field($body['alt']));
    return xclipse_ok(['id' => $att, 'url' => wp_get_attachment_url($att), 'mime' => $ft['type']]);
}
