<?php
/*
 * CherryLink — Background incremental sync via Action Scheduler.
 *
 * Start it and walk away: the scan runs as a chain of self-rescheduling background actions,
 * surviving page navigation, refresh, and browser close. A Stop control cancels it cleanly.
 *
 * Reuses the same building blocks as the foreground scan:
 *   CL_Index_Helpers::compute_sync_plan()  — pure diff (new/changed vs orphaned)
 *   CherryVectorAPI::upsert_posts_by_ids / parse_links_for_ids / delete_documents_batch
 */

defined('ABSPATH') || exit;
define('LINKATE_SYNC_LIBRARY', true);

const CL_SYNC_HOOK        = 'cherrylink_sync_worker';   // Action Scheduler action hook
const CL_SYNC_GROUP       = 'cherrylink_sync';
const CL_SYNC_STATE       = 'cherrylink_sync_state';    // progress (autoload no)
const CL_SYNC_PLAN        = 'cherrylink_sync_plan';     // worklists (autoload no)
const CL_SYNC_UPSERT_BATCH = 20;
const CL_SYNC_LINK_BATCH   = 25;

// ── State helpers ────────────────────────────────────────────────────────────

function cl_sync_get_state(): array
{
    return get_option(CL_SYNC_STATE, [
        'status' => 'idle', 'phase' => null, 'offset' => 0,
        'upsert_total' => 0, 'delete_total' => 0,
        'indexed' => 0, 'links' => 0, 'deleted' => 0,
        'started_gmt' => '', 'updated_gmt' => '', 'message' => '',
    ]);
}

function cl_sync_set_state(array $patch): array
{
    $state = array_merge(cl_sync_get_state(), $patch, ['updated_gmt' => gmdate('Y-m-d H:i:s')]);
    update_option(CL_SYNC_STATE, $state, false);
    return $state;
}

function cl_sync_is_running(): bool
{
    if (cl_sync_get_state()['status'] !== 'running') {
        return false;
    }
    // Guard against a stale 'running' state with no queued action (e.g. the worker never
    // fired because cron/loopback was unavailable) — otherwise the user could never restart.
    if (function_exists('as_has_scheduled_action')) {
        return (bool) as_has_scheduled_action(CL_SYNC_HOOK, null, CL_SYNC_GROUP);
    }
    return true;
}

// ── Public actions ───────────────────────────────────────────────────────────

/**
 * Build the diff plan and kick off the background chain. Returns the initial state.
 */
function cl_sync_start(bool $force_full = false): array
{
    if (!function_exists('as_enqueue_async_action')) {
        return cl_sync_set_state(['status' => 'error', 'message' => 'Action Scheduler unavailable']);
    }
    if (cl_sync_is_running()) {
        return cl_sync_get_state();   // already running — don't double-start
    }

    $api      = new CherryVectorAPI();
    $manifest = $force_full ? [] : $api->fetch_manifest_map();

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

    $plan = CL_Index_Helpers::compute_sync_plan($manifest, $current_modified);
    update_option(CL_SYNC_PLAN, $plan, false);

    $first = $plan['upsert'] ? 'upsert' : ($plan['delete'] ? 'prune' : 'done');

    $meta = get_option('linkate_posts_meta', []);

    // Nothing changed — finish immediately, no background round-trip (avoids a stuck 'running/done' poll).
    if ($first === 'done') {
        $meta['indexing_process'] = 'DONE';
        $meta['last_scan_gmt']     = gmdate('Y-m-d H:i:s');
        $meta['last_upserted']     = 0;
        $meta['last_deleted']      = 0;
        update_option('linkate_posts_meta', $meta);
        delete_option(CL_SYNC_PLAN);
        return cl_sync_set_state([
            'status' => 'done', 'phase' => 'done', 'offset' => 0,
            'upsert_total' => 0, 'delete_total' => 0,
            'indexed' => 0, 'links' => 0, 'deleted' => 0,
            'started_gmt' => gmdate('Y-m-d H:i:s'), 'message' => 'Изменений не найдено',
        ]);
    }

    $meta['indexing_process'] = 'IN_PROGRESS';
    update_option('linkate_posts_meta', $meta);

    $state = cl_sync_set_state([
        'status' => 'running', 'phase' => $first, 'offset' => 0,
        'upsert_total' => count($plan['upsert']), 'delete_total' => count($plan['delete']),
        'indexed' => 0, 'links' => 0, 'deleted' => 0,
        'started_gmt' => gmdate('Y-m-d H:i:s'), 'message' => '',
    ]);

    as_enqueue_async_action(CL_SYNC_HOOK, ['phase' => $first, 'offset' => 0], CL_SYNC_GROUP);
    return $state;
}

/**
 * Cancel a running sync: flag it stopped and drop all pending queued actions.
 */
function cl_sync_cancel(): array
{
    // Flag first so any worker already mid-flight aborts and stops rescheduling.
    $state = cl_sync_set_state(['status' => 'cancelled', 'message' => 'Остановлено пользователем']);

    // Then drop pending queued actions. as_unschedule_all_actions() with empty args only
    // matches argument-less actions, so cancel each pending action by ID instead.
    if (function_exists('as_get_scheduled_actions') && class_exists('ActionScheduler_Store')) {
        $ids = as_get_scheduled_actions([
            'hook'     => CL_SYNC_HOOK,
            'group'    => CL_SYNC_GROUP,
            'status'   => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => -1,
        ], 'ids');
        $store = ActionScheduler::store();
        foreach ($ids as $id) {
            try { $store->cancel_action($id); } catch (\Throwable $e) {}
        }
    }
    return $state;
}

/**
 * Worker: process ONE batch for the current phase, then enqueue the next.
 * Registered as the Action Scheduler callback.
 */
function cl_sync_worker($phase = 'upsert', $offset = 0): void
{
    $state = cl_sync_get_state();
    if ($state['status'] !== 'running') {
        return;   // cancelled or finished between enqueue and execution — stop the chain
    }

    $plan = get_option(CL_SYNC_PLAN, ['upsert' => [], 'delete' => []]);
    $api  = new CherryVectorAPI();
    $offset = (int)$offset;

    try {
        switch ($phase) {
            case 'upsert':
                $slice = array_slice($plan['upsert'], $offset, CL_SYNC_UPSERT_BATCH);
                if ($slice) {
                    $res = $api->upsert_posts_by_ids($slice);
                    $state['indexed'] += (int)$res['indexed'];
                }
                $next = $offset + count($slice);
                if ($next >= count($plan['upsert'])) {
                    $next_phase = 'links'; $next_offset = 0;
                } else {
                    $next_phase = 'upsert'; $next_offset = $next;
                }
                break;

            case 'links':
                $slice = array_slice($plan['upsert'], $offset, CL_SYNC_LINK_BATCH);
                if ($slice) {
                    $state['links'] += $api->parse_links_for_ids($slice);
                }
                $next = $offset + count($slice);
                if ($next >= count($plan['upsert'])) {
                    $next_phase = $plan['delete'] ? 'prune' : 'done'; $next_offset = 0;
                } else {
                    $next_phase = 'links'; $next_offset = $next;
                }
                break;

            case 'prune':
                if (!empty($plan['delete'])) {
                    $state['deleted'] += $api->delete_documents_batch($plan['delete']);
                    foreach ($plan['delete'] as $pid) {
                        $api->delete_links((int)$pid, 0);
                    }
                }
                $next_phase = 'done'; $next_offset = 0;
                break;

            case 'done':
            default:
                $next_phase = 'done'; $next_offset = 0;
                break;
        }
    } catch (\Throwable $e) {
        cl_sync_set_state(['status' => 'error', 'message' => $e->getMessage()]);
        $api->log_error('background sync phase ' . $phase . ' failed: ' . $e->getMessage(), 'php_index');
        return;   // stop the chain on error
    }

    // Re-check cancellation before scheduling more work (user may have stopped mid-batch).
    if (cl_sync_get_state()['status'] !== 'running') {
        return;
    }

    if ($next_phase === 'done') {
        $meta = get_option('linkate_posts_meta', []);
        $meta['indexing_process'] = 'DONE';
        $meta['last_scan_gmt']     = gmdate('Y-m-d H:i:s');
        $meta['last_upserted']     = (int)$state['upsert_total'];
        $meta['last_deleted']      = (int)$state['delete_total'];
        update_option('linkate_posts_meta', $meta);
        delete_option(CL_SYNC_PLAN);
        cl_sync_set_state(array_merge($state, ['status' => 'done', 'phase' => 'done', 'offset' => 0]));
        return;
    }

    cl_sync_set_state(array_merge($state, ['phase' => $next_phase, 'offset' => $next_offset]));
    as_enqueue_async_action(CL_SYNC_HOOK, ['phase' => $next_phase, 'offset' => $next_offset], CL_SYNC_GROUP);
}
add_action(CL_SYNC_HOOK, 'cl_sync_worker', 10, 2);

// ── AJAX endpoints (admin) ────────────────────────────────────────────────────

add_action('wp_ajax_linkate_sync_start', function () {
    if (!current_user_can('edit_posts')) wp_die();
    wp_send_json(cl_sync_start(!empty($_POST['force_full'])));
});

add_action('wp_ajax_linkate_sync_cancel', function () {
    if (!current_user_can('edit_posts')) wp_die();
    wp_send_json(cl_sync_cancel());
});

add_action('wp_ajax_linkate_sync_status', function () {
    if (!current_user_can('edit_posts')) wp_die();
    $state = cl_sync_get_state();
    $state['scheduled'] = function_exists('as_has_scheduled_action')
        ? as_has_scheduled_action(CL_SYNC_HOOK, null, CL_SYNC_GROUP) : null;
    wp_send_json($state);
});
