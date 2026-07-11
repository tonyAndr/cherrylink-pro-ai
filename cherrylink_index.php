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
    $api = new CherryVectorAPI();
    $api->delete_post($post_id);
    $api->delete_links($post_id, 0);
}

// ── AJAX: incremental phased reindex ─────────────────────────────────────────
//
// The scan is a small state machine advanced by cherry-admin-index.js. Each request does
// one bounded unit of work and tells the JS what to call next (next_phase + next_offset):
//
//   plan   → diff current published posts against the API manifest; store worklists
//            (to_upsert = new or modified, to_delete = orphaned) in a transient
//   upsert → embed changed posts in batches            (POST /v1/documents/batch)
//   links  → build the link graph for changed posts    (POST /v1/links/parse-batch)
//   prune  → delete orphaned docs + their links         (POST /v1/documents/delete-batch)
//   done   → mark DONE, stash last-scan summary, clear the transient
//
// Only CHANGED posts are re-embedded and re-parsed; unchanged posts are left untouched.

add_action('wp_ajax_linkate_ajax_call_reindex', 'linkate_ajax_call_reindex');
function linkate_ajax_call_reindex(): void
{
    if (!current_user_can('edit_posts')) {
        wp_die();
    }

    $phase      = isset($_POST['phase'])        ? sanitize_key($_POST['phase'])    : 'plan';
    $offset     = isset($_POST['index_offset']) ? (int)$_POST['index_offset']      : 0;
    $batch_size = isset($_POST['batch_size'])   ? max(1, (int)$_POST['batch_size']) : 50;
    $link_batch = 25;   // HTML payloads are large; keep link batches smaller than embeds

    $uid      = get_current_user_id();
    $plan_key = 'cherry_reindex_plan_' . $uid;

    $api = new CherryVectorAPI();
    $out = [
        'status'  => 'OK', 'phase' => $phase,
        'indexed' => 0, 'errors' => 0, 'links' => 0, 'deleted' => 0,
    ];

    try {
        switch ($phase) {
            case 'plan':
                $meta = get_option('linkate_posts_meta', []);
                $meta['indexing_process'] = 'IN_PROGRESS';
                update_option('linkate_posts_meta', $meta);

                $manifest = !empty($_POST['force_full']) ? [] : $api->fetch_manifest_map();

                global $wpdb;
                $allowed = CL_Index_Helpers::get_allowed_types_list();
                $ph      = implode(',', array_fill(0, count($allowed), '%s'));
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT ID, post_modified_gmt FROM {$wpdb->posts}
                     WHERE post_type IN ($ph) AND post_status = 'publish'",
                    $allowed
                ));

                $current_modified = [];
                foreach ($rows as $r) {
                    $current_modified[(int)$r->ID] = $r->post_modified_gmt;
                }
                $plan      = CL_Index_Helpers::compute_sync_plan($manifest, $current_modified);
                $to_upsert = $plan['upsert'];
                $to_delete = $plan['delete'];

                set_transient($plan_key, ['upsert' => $to_upsert, 'delete' => $to_delete], HOUR_IN_SECONDS);

                $out['upsert_total']  = count($to_upsert);
                $out['delete_total']  = count($to_delete);
                $out['total_current'] = count($current_modified);

                if ($to_upsert) {
                    $out['next_phase'] = 'upsert'; $out['next_offset'] = 0;
                } elseif ($to_delete) {
                    $out['next_phase'] = 'prune';  $out['next_offset'] = 0;
                } else {
                    $out['next_phase'] = 'done';   $out['next_offset'] = 0;
                }
                break;

            case 'upsert':
                $plan  = get_transient($plan_key) ?: ['upsert' => [], 'delete' => []];
                $slice = array_slice($plan['upsert'], $offset, $batch_size);
                if ($slice) {
                    $res            = $api->upsert_posts_by_ids($slice);
                    $out['indexed'] = $res['indexed'];
                    $out['errors']  = $res['errors'];
                }
                $next = $offset + count($slice);
                $out['processed']   = $next;
                $out['phase_total'] = count($plan['upsert']);
                if ($next >= count($plan['upsert'])) {
                    $out['next_phase'] = 'links'; $out['next_offset'] = 0;
                } else {
                    $out['next_phase'] = 'upsert'; $out['next_offset'] = $next;
                }
                break;

            case 'links':
                $plan  = get_transient($plan_key) ?: ['upsert' => [], 'delete' => []];
                $slice = array_slice($plan['upsert'], $offset, $link_batch);
                if ($slice) {
                    $out['links'] = $api->parse_links_for_ids($slice);
                }
                $next = $offset + count($slice);
                $out['processed']   = $next;
                $out['phase_total'] = count($plan['upsert']);
                if ($next >= count($plan['upsert'])) {
                    $out['next_phase'] = !empty($plan['delete']) ? 'prune' : 'done';
                    $out['next_offset'] = 0;
                } else {
                    $out['next_phase'] = 'links'; $out['next_offset'] = $next;
                }
                break;

            case 'prune':
                $plan = get_transient($plan_key) ?: ['upsert' => [], 'delete' => []];
                if (!empty($plan['delete'])) {
                    $out['deleted'] = $api->delete_documents_batch($plan['delete']);
                    foreach ($plan['delete'] as $pid) {
                        $api->delete_links((int)$pid, 0);
                    }
                }
                $out['next_phase'] = 'done'; $out['next_offset'] = 0;
                break;

            case 'done':
            default:
                $plan = get_transient($plan_key) ?: ['upsert' => [], 'delete' => []];
                $meta = get_option('linkate_posts_meta', []);
                $meta['indexing_process'] = 'DONE';
                $meta['last_scan_gmt']     = gmdate('Y-m-d H:i:s');
                $meta['last_upserted']     = count($plan['upsert']);
                $meta['last_deleted']      = count($plan['delete']);
                update_option('linkate_posts_meta', $meta);
                delete_transient($plan_key);
                $out['status']     = 'DONE';
                $out['next_phase'] = null;
                break;
        }
    } catch (\Throwable $e) {
        $out['status']  = 'ERROR';
        $out['message'] = $e->getMessage();
        $api->log_error('reindex phase ' . $phase . ' failed: ' . $e->getMessage(), 'php_index');
    }

    $out['time'] = number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3);

    // echo + wp_die (not wp_send_json) so cherry-admin-index.js can JSON.parse() the raw body.
    echo wp_json_encode($out);
    wp_die();
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
