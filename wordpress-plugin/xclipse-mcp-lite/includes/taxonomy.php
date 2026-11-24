<?php
/** Categories & tags */
defined('ABSPATH') || exit;

// TAXONOMY
function xclipse_cats() {
    return xclipse_ok(array_map(fn($entry) => ['id' => $entry->term_id, 'name' => $entry->name, 'slug' => $entry->slug, 'count' => $entry->count], get_categories(['hide_empty' => false, 'orderby' => 'count', 'order' => 'DESC'])));
}

function xclipse_tags() {
    return xclipse_ok(array_map(fn($term) => ['id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'count' => $term->count], get_tags(['hide_empty' => false, 'orderby' => 'count', 'order' => 'DESC', 'number' => 100])));
}