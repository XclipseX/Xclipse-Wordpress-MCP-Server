<?php
/** Posts CRUD + audit_post_performance */
defined('ABSPATH') || exit;

// POSTS — compact list, full detail
function xclipse_posts_list(WP_REST_Request $request) {
    $args = [
        'post_type'      => 'post',
        'post_status'    => $request['status'] ?? 'publish',
        'posts_per_page' => min((int)($request['per_page'] ?? 10), 50),
        'paged'          => (int)($request['page'] ?? 1),
        's'              => sanitize_text_field($request['search'] ?? ''),
        'cat'            => (int)($request['cat'] ?? 0) ?: null,
        'orderby'        => $request['orderby'] ?? 'date',
        'order'          => strtoupper($request['order'] ?? 'DESC'),
    ];
    if (!empty($request['author_id'])) $args['author'] = (int)$request['author_id'];
    $query      = new WP_Query($args);
    $fields = xclipse_parse_fields($request['fields'] ?? '');
    return xclipse_ok([
        'total' => $query->found_posts,
        'pages' => $query->max_num_pages,
        'posts' => array_map(fn($params) => xclipse_filter_fields(xclipse_compact([
            'id'         => $params->ID,
            'title'      => $params->post_title,
            'slug'       => $params->post_name,
            'st'         => $params->post_status,
            'created_at' => $params->post_date,
            'modified_at'=> $params->post_modified,
            'url'        => get_permalink($params->ID),
            'cats'       => wp_get_post_categories($params->ID, ['fields' => 'names']),
            'author'     => xclipse_author_info((int)$params->post_author),
        ]), $fields), $query->posts),
    ]);
}

function xclipse_post_get(WP_REST_Request $request) {
    $params = get_post((int)$request['id']);
    if (!$params) return xclipse_err('not_found', 'Không tìm thấy bài viết');
    return xclipse_ok(xclipse_compact([
        'id'      => $params->ID,
        'title'   => $params->post_title,
        'slug'    => $params->post_name,
        'status'  => $params->post_status,
        'date'    => $params->post_date,
        'modified'=> $params->post_modified,
        'url'     => get_permalink($params->ID),
        'excerpt' => $params->post_excerpt,
        'content' => $params->post_content,
        'cats'    => wp_get_post_categories($params->ID, ['fields' => 'names']),
        'tags'    => wp_get_post_tags($params->ID, ['fields' => 'names']),
        'thumb'   => get_the_post_thumbnail_url($params->ID, 'medium') ?: null,
        'seo'     => xclipse_seo($params->ID),
    ]));
}

function xclipse_post_create(WP_REST_Request $request) {
    $body  = $request->get_json_params();
    $id = wp_insert_post([
        'post_title'   => sanitize_text_field($body['title'] ?? ''),
        'post_content' => wp_kses_post($body['content'] ?? ''),
        'post_excerpt' => sanitize_text_field($body['excerpt'] ?? ''),
        'post_status'  => sanitize_text_field($body['status'] ?? 'draft'),
        'post_name'    => sanitize_title($body['slug'] ?? ''),
        'tags_input'   => $body['tags'] ?? [],
    ], true);
    if (is_wp_error($id)) return $id;
    if (!empty($body['categories'])) wp_set_post_categories($id, array_map('absint', $body['categories']));
    xclipse_save_seo($id, $body);
    return xclipse_ok(['id' => $id, 'url' => get_permalink($id), 'status' => get_post_status($id)]);
}

function xclipse_post_update(WP_REST_Request $request) {
    $id = (int)$request['id'];
    $body  = $request->get_json_params();
    $data  = ['ID' => $id];
    if (isset($body['title']))   $data['post_title']   = sanitize_text_field($body['title']);
    if (isset($body['content'])) $data['post_content'] = wp_kses_post($body['content']);
    if (isset($body['excerpt'])) $data['post_excerpt'] = sanitize_text_field($body['excerpt']);
    if (isset($body['status']))  $data['post_status']  = sanitize_text_field($body['status']);
    if (isset($body['slug']))    $data['post_name']    = sanitize_title($body['slug']);
    $res = wp_update_post($data, true);
    if (is_wp_error($res)) return $res;
    if (isset($body['categories'])) wp_set_post_categories($id, array_map('absint', $body['categories']));
    if (isset($body['tags']))       wp_set_post_tags($id, $body['tags']);
    xclipse_save_seo($id, $body);
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_post_delete(WP_REST_Request $request) {
    $id = (int)$request['id'];
    $params  = get_post($id);
    if (!$params) return xclipse_err('not_found', 'Không tìm thấy');
    wp_trash_post($id);
    return xclipse_ok(['ok' => true, 'id' => $id]);
}

function xclipse_audit_post_performance(WP_REST_Request $request) {
    global $wpdb;
    $post_type = sanitize_text_field($request['post_type'] ?? 'post');
    $per_page  = min((int)($request['per_page'] ?? 50), 200);
    $days      = min((int)($request['days'] ?? 30), 90);
    $pt_in     = $post_type === 'all' ? "'post','page','product'" : "'{$post_type}'";

    $posts = $wpdb->get_results(
        "SELECT ID, post_title, post_date FROM {$wpdb->posts}
         WHERE post_status='publish' AND post_type IN ({$pt_in})
         ORDER BY post_date DESC LIMIT {$per_page}"
    );

    // Burst pageviews (if available)
    $burst_views = [];
    $burst_table = $wpdb->prefix . 'burst_statistics';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$burst_table}'") === $burst_table) {
        $since = date('Y-m-d', strtotime("-{$days} days"));
        $rows = $wpdb->get_results(
            "SELECT page_id, SUM(page_views) views FROM {$burst_table}
             WHERE date >= '{$since}' AND page_id > 0
             GROUP BY page_id"
        );
        foreach ($rows as $row) $burst_views[(int)$row->page_id] = (int)$row->views;
    }

    $results = [];
    foreach ($posts as $params) {
        $pid        = (int)$params->ID;
        $score      = (int)get_post_meta($pid, 'rank_math_seo_score', true);
        $kw         = get_post_meta($pid, 'rank_math_focus_keyword', true);
        $title      = get_post_meta($pid, 'rank_math_title', true);
        $desc       = get_post_meta($pid, 'rank_math_description', true);
        $views      = $burst_views[$pid] ?? 0;
        $age_days   = (int)((time() - strtotime($params->post_date)) / 86400);

        $flags = [];
        if ($views > 100 && $score < 60)              $flags[] = 'high_traffic_low_score';
        if ($views === 0 && $age_days > 30)            $flags[] = 'no_traffic_30d';
        if (!$kw)                                      $flags[] = 'no_focus_kw';
        if (!$title)                                   $flags[] = 'no_seo_title';
        if (!$desc)                                    $flags[] = 'no_meta_desc';
        if ($score > 0 && $score < 50)                $flags[] = 'low_score';
        if ($age_days > 180 && $views < 10)           $flags[] = 'zombie_content';

        $results[] = [
            'id'       => $pid,
            'title'    => $params->post_title,
            'url'      => get_permalink($pid),
            'score'    => $score ?: null,
            'views'    => $views,
            'age_days' => $age_days,
            'has_kw'   => (bool)$kw,
            'has_title'=> (bool)$title,
            'has_desc' => (bool)$desc,
            'flags'    => $flags,
        ];
    }

    // Sort: most flags first, then by views DESC
    usort($results, fn($query_args,$body) => count($body['flags']) !== count($query_args['flags'])
        ? count($body['flags']) - count($query_args['flags'])
        : $body['views'] - $query_args['views']);

    $flag_summary = [];
    foreach ($results as $item) {
        foreach ($item['flags'] as $field) {
            $flag_summary[$field] = ($flag_summary[$field] ?? 0) + 1;
        }
    }

    return xclipse_ok([
        'period_days'  => $days,
        'total'        => count($results),
        'flag_summary' => $flag_summary,
        'items'        => $results,
    ]);
}