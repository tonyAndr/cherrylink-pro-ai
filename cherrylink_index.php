<?php
/*
 * CherryLink Plugin – Indexing via Cherry Vector API
 */

defined('ABSPATH') || exit;
define('LINKATE_INDEX_LIBRARY', true);

// ── Post save / delete hooks ─────────────────────────────────────────────────

add_action('wp_insert_post', 'linkate_sp_save_index_entry', 100000, 3);
function linkate_sp_save_index_entry(int $post_id, WP_Post $post, bool $update): void
{
    // Skip revisions, autosaves, and non-public statuses
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
        return;
    }

    $allowed = CL_Index_Helpers::get_allowed_types_list();
    if (!in_array($post->post_type, $allowed, true)) {
        return;
    }

    $api = new CherryVectorAPI();

    if ($post->post_status === 'publish') {
        $api->upsert_post($post);
    } else {
        // Unpublished / trashed — remove from index
        $api->delete_post($post_id);
    }
}

add_action('delete_post', 'linkate_sp_delete_index_entry');
function linkate_sp_delete_index_entry(int $post_id): void
{
    $api = new CherryVectorAPI();
    $api->delete_post($post_id);
}

// ── AJAX: batch reindex all posts ────────────────────────────────────────────

add_action('wp_ajax_linkate_ajax_call_reindex', 'linkate_ajax_call_reindex');
function linkate_ajax_call_reindex(): void
{
    $options_meta                    = get_option('linkate_posts_meta', []);
    $options_meta['indexing_process'] = 'IN_PROGRESS';
    update_option('linkate_posts_meta', $options_meta);

    $batch_size = isset($_POST['batch_size'])    ? (int)$_POST['batch_size']    : 50;
    $offset     = isset($_POST['index_offset'])  ? (int)$_POST['index_offset']  : 0;
    $total      = isset($_POST['index_posts_count']) ? (int)$_POST['index_posts_count'] : 0;

    $api    = new CherryVectorAPI();
    $result = $api->reindex_all($batch_size, $offset);

    $done = ($offset + $result['total']) >= $total;
    if ($done && $result['errors'] === 0) {
        $options_meta['indexing_process'] = 'DONE';
        update_option('linkate_posts_meta', $options_meta);
    }

    wp_send_json([
        'status'  => $result['errors'] > 0 ? 'ERROR' : ($done ? 'DONE' : 'OK'),
        'indexed' => $result['indexed'],
        'errors'  => $result['errors'],
    ]);
}

// ── AJAX: get post count for progress bar ────────────────────────────────────

add_action('wp_ajax_linkate_get_posts_count_reindex', 'linkate_get_posts_count_reindex');
function linkate_get_posts_count_reindex(): void
{
    global $wpdb;

    $allowed      = CL_Index_Helpers::get_allowed_types_list();
    $placeholders = implode(',', array_fill(0, count($allowed), '%s'));

    $count = (int)$wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type IN ($placeholders) AND post_status = 'publish'",
            $allowed
        )
    );

    echo $count;
    wp_die();
}
