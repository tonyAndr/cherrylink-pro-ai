<?php

declare(strict_types=1);

namespace CherryLink\Indexing;

use ActionScheduler;
use ActionScheduler_Store;
use CherryLink\Ajax\Security;
use CherryLink\Api\Client;
use CherryLink\Contracts\Registerable;
use CherryLink\Support\License;
use CherryLink\Support\Options;

/**
 * Background incremental sync via Action Scheduler.
 *
 * Start it and walk away: the scan runs as a chain of self-rescheduling background
 * actions, surviving page navigation, refresh, and browser close. A Stop control
 * cancels it cleanly.
 *
 * Ported verbatim from the legacy cherrylink_sync.php (now a thin shim that keeps
 * the CL_SYNC_* constant names for back-compat). The option keys the state/plan are
 * persisted under are unchanged, so an in-flight scan survives this refactor.
 *
 * Reuses the same building blocks as the foreground scan:
 *   IndexHelpers::compute_sync_plan()  — pure diff (new/changed vs orphaned)
 *   Client::upsert_posts_by_ids / parse_links_for_ids / delete_documents_batch
 */
final class SyncService implements Registerable
{
    /** Action Scheduler action hook. */
    public const HOOK = 'cherrylink_sync_worker';

    /** Action Scheduler group. */
    public const GROUP = 'cherrylink_sync';

    /** Option key: progress state (autoload no). */
    public const STATE = 'cherrylink_sync_state';

    /** Option key: worklists (autoload no). */
    public const PLAN = 'cherrylink_sync_plan';

    public const UPSERT_BATCH = 20;
    public const LINK_BATCH    = 25;

    /** @var Options */
    private $options;

    /** @var License */
    private $license;

    public function __construct(Options $options, License $license)
    {
        $this->options = $options;
        $this->license = $license;
    }

    public function register(): void
    {
        \add_action(self::HOOK, [$this, 'worker'], 10, 2);

        \add_action('wp_ajax_linkate_sync_start', [$this, 'ajaxStart']);
        \add_action('wp_ajax_linkate_sync_cancel', [$this, 'ajaxCancel']);
        \add_action('wp_ajax_linkate_sync_status', [$this, 'ajaxStatus']);
        \add_action('wp_ajax_linkate_sync_tick', [$this, 'ajaxTick']);
    }

    // ── State helpers ─────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function getState(): array
    {
        return \get_option(self::STATE, [
            'status'       => 'idle',
            'phase'        => null,
            'offset'       => 0,
            'upsert_total' => 0,
            'delete_total' => 0,
            'indexed'      => 0,
            'links'        => 0,
            'deleted'      => 0,
            'started_gmt'  => '',
            'updated_gmt'  => '',
            'message'      => '',
        ]);
    }

    /**
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public function setState(array $patch): array
    {
        $state = \array_merge($this->getState(), $patch, ['updated_gmt' => \gmdate('Y-m-d H:i:s')]);
        \update_option(self::STATE, $state, false);
        return $state;
    }

    public function isRunning(): bool
    {
        if ($this->getState()['status'] !== 'running') {
            return false;
        }
        // Guard against a stale 'running' state with no queued action (e.g. the worker never
        // fired because cron/loopback was unavailable) — otherwise the user could never restart.
        if (\function_exists('as_has_scheduled_action')) {
            return (bool) \as_has_scheduled_action(self::HOOK, null, self::GROUP);
        }
        return true;
    }

    // ── Public actions ──────────────────────────────────────────────────────

    /**
     * Build the diff plan and kick off the background chain. Returns the initial state.
     *
     * @return array<string,mixed>
     */
    public function start(bool $force_full = false): array
    {
        if (! $this->license->isLicensed()) {
            return $this->setState([
                'status'  => 'error',
                'message' => \__('Лицензия неактивна — синхронизация недоступна. Введите ключ лицензии.', 'cherrylink'),
            ]);
        }
        if (! \function_exists('as_enqueue_async_action')) {
            return $this->setState(['status' => 'error', 'message' => 'Action Scheduler unavailable']);
        }
        if ($this->isRunning()) {
            return $this->getState();   // already running — don't double-start
        }

        $api      = new Client();
        $manifest = $force_full ? [] : $api->fetch_manifest_map();

        global $wpdb;
        $allowed = IndexHelpers::get_allowed_types_list();
        $ph      = \implode(',', \array_fill(0, \count($allowed), '%s'));
        $rows    = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_modified_gmt FROM {$wpdb->posts}
             WHERE post_type IN ($ph) AND post_status = 'publish'",
            $allowed
        ));
        $current_modified = [];
        foreach ($rows as $r) {
            $current_modified[(int) $r->ID] = $r->post_modified_gmt;
        }

        $plan = IndexHelpers::compute_sync_plan($manifest, $current_modified);
        \update_option(self::PLAN, $plan, false);

        $first = $plan['upsert'] ? 'upsert' : ($plan['delete'] ? 'prune' : 'done');

        $meta = \get_option('linkate_posts_meta', []);

        // Nothing changed — finish immediately, no background round-trip.
        if ($first === 'done') {
            $meta['indexing_process'] = 'DONE';
            $meta['last_scan_gmt']    = \gmdate('Y-m-d H:i:s');
            $meta['last_upserted']    = 0;
            $meta['last_deleted']     = 0;
            \update_option('linkate_posts_meta', $meta);
            \delete_option(self::PLAN);
            $this->options->clearIndexDirty();   // index reconciled — clear any out-of-sync flag
            return $this->setState([
                'status'       => 'done',
                'phase'        => 'done',
                'offset'       => 0,
                'upsert_total' => 0,
                'delete_total' => 0,
                'indexed'      => 0,
                'links'        => 0,
                'deleted'      => 0,
                'started_gmt'  => \gmdate('Y-m-d H:i:s'),
                'message'      => \__('Изменений не найдено', 'cherrylink'),
            ]);
        }

        $meta['indexing_process'] = 'IN_PROGRESS';
        \update_option('linkate_posts_meta', $meta);

        $state = $this->setState([
            'status'       => 'running',
            'phase'        => $first,
            'offset'       => 0,
            'upsert_total' => \count($plan['upsert']),
            'delete_total' => \count($plan['delete']),
            'indexed'      => 0,
            'links'        => 0,
            'deleted'      => 0,
            'started_gmt'  => \gmdate('Y-m-d H:i:s'),
            'message'      => '',
        ]);

        \as_enqueue_async_action(self::HOOK, ['phase' => $first, 'offset' => 0], self::GROUP);
        return $state;
    }

    /**
     * Cancel a running sync: flag it stopped and drop all pending queued actions.
     *
     * @return array<string,mixed>
     */
    public function cancel(): array
    {
        // Flag first so any worker already mid-flight aborts and stops rescheduling.
        $state = $this->setState(['status' => 'cancelled', 'message' => \__('Остановлено пользователем', 'cherrylink')]);

        // Then drop pending queued actions. as_unschedule_all_actions() with empty args only
        // matches argument-less actions, so cancel each pending action by ID instead.
        if (\function_exists('as_get_scheduled_actions') && \class_exists('ActionScheduler_Store')) {
            $ids = \as_get_scheduled_actions([
                'hook'     => self::HOOK,
                'group'    => self::GROUP,
                'status'   => ActionScheduler_Store::STATUS_PENDING,
                'per_page' => -1,
            ], 'ids');
            $store = ActionScheduler::store();
            foreach ($ids as $id) {
                try {
                    $store->cancel_action($id);
                } catch (\Throwable $e) {
                    // best-effort cancel
                }
            }
        }
        return $state;
    }

    /**
     * Worker: process ONE batch for the current phase, then enqueue the next.
     * Registered as the Action Scheduler callback.
     *
     * @param string     $phase
     * @param int|string $offset
     */
    public function worker($phase = 'upsert', $offset = 0): void
    {
        $state = $this->getState();
        if ($state['status'] !== 'running') {
            return;   // cancelled or finished between enqueue and execution — stop the chain
        }

        $plan   = \get_option(self::PLAN, ['upsert' => [], 'delete' => []]);
        $api    = new Client();
        $offset = (int) $offset;

        try {
            switch ($phase) {
                case 'upsert':
                    $slice = \array_slice($plan['upsert'], $offset, self::UPSERT_BATCH);
                    if ($slice) {
                        $res = $api->upsert_posts_by_ids($slice);
                        $state['indexed'] += (int) $res['indexed'];
                    }
                    $next = $offset + \count($slice);
                    if ($next >= \count($plan['upsert'])) {
                        $next_phase  = 'links';
                        $next_offset = 0;
                    } else {
                        $next_phase  = 'upsert';
                        $next_offset = $next;
                    }
                    break;

                case 'links':
                    $slice = \array_slice($plan['upsert'], $offset, self::LINK_BATCH);
                    if ($slice) {
                        $state['links'] += $api->parse_links_for_ids($slice);
                    }
                    $next = $offset + \count($slice);
                    if ($next >= \count($plan['upsert'])) {
                        $next_phase  = $plan['delete'] ? 'prune' : 'done';
                        $next_offset = 0;
                    } else {
                        $next_phase  = 'links';
                        $next_offset = $next;
                    }
                    break;

                case 'prune':
                    if (! empty($plan['delete'])) {
                        $state['deleted'] += $api->delete_documents_batch($plan['delete']);
                        foreach ($plan['delete'] as $pid) {
                            $api->delete_links((int) $pid, 0);
                        }
                    }
                    $next_phase  = 'done';
                    $next_offset = 0;
                    break;

                case 'done':
                default:
                    $next_phase  = 'done';
                    $next_offset = 0;
                    break;
            }
        } catch (\Throwable $e) {
            $this->setState(['status' => 'error', 'message' => $e->getMessage()]);
            $api->log_error('background sync phase ' . $phase . ' failed: ' . $e->getMessage(), 'php_index');
            return;   // stop the chain on error
        }

        // Re-check cancellation before scheduling more work (user may have stopped mid-batch).
        if ($this->getState()['status'] !== 'running') {
            return;
        }

        if ($next_phase === 'done') {
            $meta = \get_option('linkate_posts_meta', []);
            $meta['indexing_process'] = 'DONE';
            $meta['last_scan_gmt']    = \gmdate('Y-m-d H:i:s');
            $meta['last_upserted']    = (int) $state['upsert_total'];
            $meta['last_deleted']     = (int) $state['delete_total'];
            \update_option('linkate_posts_meta', $meta);
            \delete_option(self::PLAN);
            $this->options->clearIndexDirty();   // index reconciled — clear any out-of-sync flag
            $this->setState(\array_merge($state, ['status' => 'done', 'phase' => 'done', 'offset' => 0]));
            return;
        }

        $this->setState(\array_merge($state, ['phase' => $next_phase, 'offset' => $next_offset]));
        \as_enqueue_async_action(self::HOOK, ['phase' => $next_phase, 'offset' => $next_offset], self::GROUP);
    }

    // ── AJAX endpoints (admin) ─────────────────────────────────────────────────

    public function ajaxStart(): void
    {
        Security::verify('edit_posts');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- gated by Security::verify()
        \wp_send_json($this->start(! empty($_POST['force_full'])));
    }

    public function ajaxCancel(): void
    {
        Security::verify('edit_posts');
        \wp_send_json($this->cancel());
    }

    public function ajaxStatus(): void
    {
        Security::verify('edit_posts');
        $state              = $this->getState();
        $state['scheduled'] = \function_exists('as_has_scheduled_action')
            ? \as_has_scheduled_action(self::HOOK, null, self::GROUP)
            : null;
        \wp_send_json($state);
    }

    /**
     * Nudge the Action Scheduler queue from the open scan page, so the background sync
     * advances on a fresh/no-traffic site without waiting for a WP-Cron tick. Close the
     * page and normal cron takes over. No-op unless our sync is running and queued.
     */
    public function ajaxTick(): void
    {
        Security::verify('edit_posts');
        $ran = 0;
        if (
            $this->getState()['status'] === 'running'
            && \class_exists('ActionScheduler')
            && \function_exists('as_has_scheduled_action')
            && \as_has_scheduled_action(self::HOOK, null, self::GROUP)
        ) {
            try {
                $ran = (int) ActionScheduler::runner()->run('CherryLink admin');
            } catch (\Throwable $e) {
                // ignore — WP-Cron will catch up
            }
        }
        \wp_send_json(['ran' => $ran]);
    }
}
