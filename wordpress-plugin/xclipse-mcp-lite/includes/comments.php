<?php
/** Comments: list, get, update, delete */
defined('ABSPATH') || exit;

// COMMENTS
function xclipse_comments_list(WP_REST_Request $request) {
    $args = ['number' => min((int)($request['per_page'] ?? 20), 50), 'offset' => ((int)($request['page'] ?? 1) - 1) * (int)($request['per_page'] ?? 20), 'status' => $request['status'] ?? 'hold'];
    if ($request['post_id']) $args['post_id'] = (int)$request['post_id'];
    $comments = get_comments($args);
    $total    = get_comments(['count' => true] + $args);
    return xclipse_ok(['total' => $total, 'comments' => array_map(fn($entry) => ['id' => (int)$entry->comment_ID, 'post_id' => (int)$entry->comment_post_ID,
        'post' => get_the_title($entry->comment_post_ID), 'author' => $entry->comment_author, 'email' => $entry->comment_author_email,
        'date' => substr($entry->comment_date, 0, 16), 'content' => mb_substr($entry->comment_content, 0, 200), 'st' => $entry->comment_approved === '1' ? 'approved' : ($entry->comment_approved === '0' ? 'pending' : 'spam')],
        $comments)]);
}

function xclipse_comment_get(WP_REST_Request $request) {
    $entry = get_comment((int)$request['id']);
    if (!$entry) return xclipse_err('not_found', 'Không tìm thấy comment');
    return xclipse_ok(['id' => (int)$entry->comment_ID, 'post_id' => (int)$entry->comment_post_ID, 'post' => get_the_title($entry->comment_post_ID),
        'author' => $entry->comment_author, 'email' => $entry->comment_author_email, 'url' => $entry->comment_author_url,
        'date' => $entry->comment_date, 'content' => $entry->comment_content, 'status' => $entry->comment_approved === '1' ? 'approved' : ($entry->comment_approved === '0' ? 'pending' : $entry->comment_approved)]);
}

function xclipse_comment_update(WP_REST_Request $request) {
    $id = (int)$request['id'];
    $body  = $request->get_json_params();
    $data  = ['comment_ID' => $id];
    if (isset($body['content'])) $data['comment_content'] = sanitize_textarea_field($body['content']);
    if (isset($body['status'])) {
        $map = ['approved' => 1, 'pending' => 0, 'spam' => 'spam', 'trash' => 'trash'];
        wp_set_comment_status($id, $map[$body['status']] ?? $body['status']);
    }
    if (count($data) > 1) wp_update_comment($data);
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_comment_delete(WP_REST_Request $request) {
    wp_delete_comment((int)$request['id'], (bool)($request['force'] ?? false));
    return xclipse_ok(['ok' => true, 'id' => (int)$request['id']]);
}