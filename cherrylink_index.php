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

    // No valid license → do no API work; record that the index is now out of sync.
    if (!cl_is_licensed()) {
        cl_mark_index_dirty();
        return;
    }

    $api = new CherryVectorAPI();

    if ($post->post_status === 'publish') {
        $api->upsert_post($post);

        // Update link graph for this post
        $source_url = get_permalink($post_id);
        if (!empty($source_url) && !empty($post->post_content)) {
            $count = $api->parse_links($post_id, 0, $source_url, $post->post_content);
            update_post_meta($post_id, 'cherry_outgoing', $count);

            // Refresh this post's own incoming count (1 API call, not N on page load)
            $incoming = $api->get_incoming_links($source_url);
            update_post_meta($post_id, 'cherry_income', (int)($incoming['count'] ?? 0));
        }
    } else {
        // Unpublished / trashed — remove from both vector index and link graph
        $api->delete_post($post_id);
        $api->delete_links($post_id, 0);
    }
}

add_action('delete_post', 'linkate_sp_delete_index_entry');
function linkate_sp_delete_index_entry(int $post_id): void
{
    if (!cl_is_licensed()) {
        cl_mark_index_dirty();
        return;
    }
    $api = new CherryVectorAPI();
    $api->delete_post($post_id);
    $api->delete_links($post_id, 0);
}

// ── Readiness status payload (shared by the settings render + AJAX refresh) ───
//
// Single source of truth for the "Готовность к работе" card: doc count, link
// count, the process badge, and the last-scan summary. The scan page fetches this
// after a scan finishes so the card updates in place instead of asking for a reload.

function cl_index_status_data(): array
{
    $meta       = get_option('linkate_posts_meta', []);
    $api        = new CherryVectorAPI();
    $index_rows = $api->get_count();
    $scheme_rows = $api->get_links_count();

    $index_status_text = $index_rows > 0
        ? " проиндексировано {$index_rows} статей в Vector API."
        : " статьи не проиндексированы, запустите сканирование.";
    $scheme_status_text = $scheme_rows
        ? " найдено {$scheme_rows} ссылок в записях и страницах."
        : " ссылки не найдены.";

    $last_scan_text = '';
    if (!empty($meta['last_scan_gmt'])) {
        $up   = (int)($meta['last_upserted'] ?? 0);
        $del  = (int)($meta['last_deleted'] ?? 0);
        // Machine-readable UTC instant + site-tz fallback text. cherry-admin-index.js
        // rewrites the <time> to the viewer's browser-local time on load and after AJAX.
        $iso  = esc_attr(str_replace(' ', 'T', $meta['last_scan_gmt']) . 'Z');
        $when = esc_html(get_date_from_gmt($meta['last_scan_gmt']));
        $last_scan_text = " Последнее сканирование: <time class=\"cl-localtime\" datetime=\"{$iso}\">{$when}</time> (обновлено: {$up}, удалено: {$del}).";
    }

    $status = $meta['indexing_process'] ?? 'VALUE_NOT_EXIST';
    switch ($status) {
        case 'IN_PROGRESS':    $badge = '<code class="bad-index">[Сканирование не завершено]</code>'; break;
        case 'DONE':           $badge = '<code class="good-index">[Все хорошо]</code>'; break;
        case 'VALUE_NOT_EXIST':$badge = '<code class="bad-index">[Нужно просканировать сайт]</code>'; break;
        default:               $badge = ''; break;
    }

    return [
        'index_rows'         => (int)$index_rows,
        'scheme_rows'        => (int)$scheme_rows,
        'index_status_text'  => $index_status_text,
        'scheme_status_text' => $scheme_status_text,
        'last_scan_text'     => $last_scan_text,
        'badge_html'         => $badge,
    ];
}

add_action('wp_ajax_linkate_index_status_data', function () {
    if (!current_user_can('edit_posts')) {
        wp_die();
    }
    wp_send_json(cl_index_status_data());
});
