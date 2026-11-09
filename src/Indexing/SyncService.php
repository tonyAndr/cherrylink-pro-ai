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
    public const PRUNE_BATCH   = 1000;

    private const MAX_RETRY_ATTEMPTS = 8;
    private const MAX_RETRY_DELAY    = 300;
    private const BASE_RETRY_DELAY   = 2;

    /** @var Options */
    private $options;

    /** @var License */
    private $license;

    /** @var callable */
    private $client_factory;

    /**
     * @param callable|null $client_factory Factory returning a Client; test seam for workers.
     */
    public function __construct(Options $options, License $license, ?callable $client_factory = null)
    {
        $this->options        = $options;
        $this->license        = $license;
        $this->client_factory = $client_factory ?? static function (): Client {
            return new Client(null, Client::CONTEXT_BACKGROUND);
        };
    }

    public function register(): void
    {
        \add_action(self::HOOK, [$this, 'worker'], 10, 4);

        \add_action('wp_ajax_linkate_sync_start', [$this, 'ajaxStart']);
        \add_action('wp_ajax_linkate_sync_cancel', [$this, 'ajaxCancel']);
        \add_action('wp_ajax_linkate_sync_status', [$this, 'ajaxStatus']);
        \add_action('wp_ajax_linkate_sync_tick', [$this, 'ajaxTick']);
    }

    // ── State helpers ─────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function getState(): array
    {
        $defaults = [
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
            'retry_attempt' => 0,
            'next_retry_gmt' => '',
            'last_http_status' => 0,
            'last_retry_after' => null,
            'last_error' => '',
            'failed_phase' => null,
            'failed_offset' => null,
            'force_full' => false,
        ];

        return \array_merge($defaults, (array) \get_option(self::STATE, $defaults));
    }

    private function client(): Client
    {
        return \call_user_func($this->client_factory);
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
     * Persist the manifest phase and kick off the background chain. Returns immediately;
     * the worker fetches the manifest and builds the plan.
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

        // Mark the sync as running and queue manifest retrieval. The prior PLAN stays
        // untouched until the worker has fetched a complete manifest.
        $meta = \get_option('linkate_posts_meta', []);
        $previous_process = $meta['indexing_process'] ?? null;
        $meta['indexing_process'] = 'IN_PROGRESS';
        \update_option('linkate_posts_meta', $meta);

        $state = $this->setState([
            'status'       => 'running',
            'phase'        => 'manifest',
            'offset'       => 0,
            'upsert_total' => 0,
            'delete_total' => 0,
            'indexed'      => 0,
            'links'        => 0,
            'deleted'      => 0,
            'started_gmt'  => \gmdate('Y-m-d H:i:s'),
            'force_full'   => $force_full,
            'message'      => '',
            'retry_attempt' => 0,
            'next_retry_gmt' => '',
            'last_http_status' => 0,
            'last_retry_after' => null,
            'last_error' => '',
            'failed_phase' => null,
            'failed_offset' => null,
        ]);

        try {
            $action_id = \as_enqueue_async_action(
                self::HOOK,
                ['phase' => 'manifest', 'offset' => 0, 'attempt' => 0, 'force_full' => $force_full],
                self::GROUP
            );
        } catch (\Throwable $error) {
            $action_id = 0;
        }
        if (!$action_id) {
            $meta = \get_option('linkate_posts_meta', []);
            if ($previous_process === null) {
                unset($meta['indexing_process']);
            } else {
                $meta['indexing_process'] = $previous_process;
            }
            \update_option('linkate_posts_meta', $meta);
            return $this->setState([
                'status' => 'error',
                'message' => \__('Не удалось запланировать сканирование. Попробуйте снова.', 'cherrylink'),
            ]);
        }
        return $this->getState();
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
     * @param int|string $attempt
     * @param bool|null  $force_full
     */
    public function worker($phase = 'upsert', $offset = 0, $attempt = 0, $force_full = null): void
    {
        $state = $this->getState();
        if ($state['status'] !== 'running') {
            return;   // cancelled or finished between enqueue and execution — stop the chain
        }

        $offset  = (int) $offset;
        $attempt = (int) $attempt;
        if ($state['phase'] !== $phase || (int) $state['offset'] !== $offset) {
            return;   // stale callback from an older phase/cursor
        }
        if ((int) $state['retry_attempt'] !== $attempt) {
            return;   // stale retry callback
        }
        if ($force_full !== null && (bool) ($state['force_full'] ?? false) !== (bool) $force_full) {
            return;   // stale callback from a different scan mode
        }

        $plan   = \get_option(self::PLAN, ['upsert' => [], 'delete' => []]);
        $api    = $this->client();

        try {
            switch ($phase) {
                case 'manifest':
                    $res = $api->fetch_manifest_result();
                    if (!$res['ok']) {
                        $this->handleFailure($api, $phase, $offset, $state, $res);
                        return;
                    }
                    $this->startFromManifest($res['map'], (bool) ($state['force_full'] ?? false));
                    return;

                case 'upsert':
                    $slice = \array_slice($plan['upsert'], $offset, self::UPSERT_BATCH);
                    if ($slice) {
                        $res = $api->upsert_posts_by_ids_result($slice);
                        if (!$res['ok']) {
                            $this->handleFailure($api, $phase, $offset, $state, $res);
                            return;
                        }
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
                        $res = $api->parse_links_for_ids_result($slice);
                        if (!$res['ok']) {
                            $this->handleFailure($api, $phase, $offset, $state, $res);
                            return;
                        }
                        $state['links'] += (int) $res['count'];
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
                    $slice = \array_slice($plan['delete'], $offset, self::PRUNE_BATCH);
                    if ($slice) {
                        $res = $api->delete_documents_batch_result($slice);
                        if (!$res['ok']) {
                            $this->handleFailure($api, $phase, $offset, $state, $res);
                            return;
                        }
                        $state['deleted'] += (int) $res['deleted'];
                        foreach ($slice as $pid) {
                            $link_result = $api->delete_links_result((int) $pid, 0);
                            if (!$link_result['ok']) {
                                $this->handleFailure($api, $phase, $offset, $state, $link_result);
                                return;
                            }
                        }
                    }
                    $next = $offset + \count($slice);
                    if ($next >= \count($plan['delete'])) {
                        $next_phase  = 'done';
                        $next_offset = 0;
                    } else {
                        $next_phase  = 'prune';
                        $next_offset = $next;
                    }
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

        $state['retry_attempt']    = 0;
        $state['next_retry_gmt']   = '';
        $state['last_http_status'] = 0;
        $state['last_retry_after'] = null;
        $state['last_error']       = '';
        $state['failed_phase']     = null;
        $state['failed_offset']    = null;

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
        \as_enqueue_async_action(
            self::HOOK,
            [
                'phase'      => $next_phase,
                'offset'     => $next_offset,
                'attempt'    => 0,
                'force_full' => (bool) ($state['force_full'] ?? false),
            ],
            self::GROUP
        );
    }

    /**
     * Build a plan only after the complete manifest has been fetched, then enter the
     * first work phase. Existing plans remain untouched while manifest retrieval retries.
     *
     * @param array<int,array{hash:mixed,modified:mixed}> $manifest
     */
    private function startFromManifest(array $manifest, bool $force_full): void
    {
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

        $plan = IndexHelpers::compute_sync_plan($manifest, $current_modified, $force_full);
        // This is the first point at which a fresh plan is safe to publish.
        \update_option(self::PLAN, $plan, false);

        $first = $plan['upsert'] ? 'upsert' : ($plan['delete'] ? 'prune' : 'done');
        $state = $this->getState();

        // Nothing changed — finish immediately, no background round-trip.
        if ($first === 'done') {
            $meta = \get_option('linkate_posts_meta', []);
            $meta['indexing_process'] = 'DONE';
            $meta['last_scan_gmt']    = \gmdate('Y-m-d H:i:s');
            $meta['last_upserted']    = 0;
            $meta['last_deleted']     = 0;
            \update_option('linkate_posts_meta', $meta);
            \delete_option(self::PLAN);
            $this->options->clearIndexDirty();   // index reconciled — clear any out-of-sync flag
            $this->setState(\array_merge($state, [
                'status'       => 'done',
                'phase'        => 'done',
                'offset'       => 0,
                'upsert_total' => 0,
                'delete_total' => 0,
                'indexed'      => 0,
                'links'        => 0,
                'deleted'      => 0,
                'force_full'   => $force_full,
                'message'      => \__('Изменений не найдено', 'cherrylink'),
            ]));
            return;
        }

        $meta = \get_option('linkate_posts_meta', []);
        $meta['indexing_process'] = 'IN_PROGRESS';
        \update_option('linkate_posts_meta', $meta);

        $state = $this->setState(\array_merge($state, [
            'status'           => 'running',
            'phase'            => $first,
            'offset'           => 0,
            'upsert_total'     => \count($plan['upsert']),
            'delete_total'     => \count($plan['delete']),
            'indexed'          => 0,
            'links'            => 0,
            'deleted'          => 0,
            'force_full'       => $force_full,
            'retry_attempt'    => 0,
            'next_retry_gmt'   => '',
            'last_http_status' => 0,
            'last_retry_after' => null,
            'last_error'       => '',
            'failed_phase'     => null,
            'failed_offset'    => null,
        ]));

        \as_enqueue_async_action(
            self::HOOK,
            [
                'phase'      => $first,
                'offset'     => 0,
                'attempt'    => 0,
                'force_full' => (bool) ($state['force_full'] ?? false),
            ],
            self::GROUP
        );
    }

    /**
     * Persist a retry cursor and schedule exactly one delayed callback for it.
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $result
     */
    private function handleFailure(Client $api, string $phase, int $offset, array $state, array $result): void
    {
        $attempt  = (int)($state['retry_attempt'] ?? 0) + 1;
        $error    = (string)($result['error'] ?? 'sync operation failed');
        $retryable = (bool)($result['retryable'] ?? Client::is_retryable_result($result));

        if (!$retryable || $attempt > self::MAX_RETRY_ATTEMPTS) {
            $message = !$retryable
                ? \sprintf('Sync %s failed permanently at offset %d: %s', $phase, $offset, $error)
                : \sprintf('Sync %s retry budget exhausted at offset %d: %s', $phase, $offset, $error);
            $this->setState(\array_merge($state, [
                'status'           => 'error',
                'phase'            => $phase,
                'offset'           => $offset,
                'message'          => $message,
                'next_retry_gmt'   => '',
                'last_http_status' => (int)($result['status'] ?? 0),
                'last_retry_after' => $result['retry_after'] ?? null,
                'last_error'       => $error,
                'failed_phase'     => $phase,
                'failed_offset'    => $offset,
            ]));
            return;
        }

        if (!\function_exists('as_schedule_single_action')) {
            $this->setState(\array_merge($state, [
                'status'        => 'error',
                'phase'         => $phase,
                'offset'        => $offset,
                'message'       => 'Action Scheduler delayed retry unavailable',
                'last_error'    => 'Action Scheduler delayed retry unavailable',
                'failed_phase'  => $phase,
                'failed_offset' => $offset,
            ]));
            return;
        }

        $retry_after = isset($result['retry_after']) && $result['retry_after'] !== null
            ? (int)$result['retry_after']
            : null;
        $delay    = self::retry_delay($attempt, $retry_after);
        $run_at   = \time() + $delay;
        $next_gmt = \gmdate('Y-m-d H:i:s', $run_at);
        $action_id = \as_schedule_single_action(
            $run_at,
            self::HOOK,
            [
                'phase'      => $phase,
                'offset'     => $offset,
                'attempt'    => $attempt,
                'force_full' => (bool) ($state['force_full'] ?? false),
            ],
            self::GROUP
        );

        if (!$action_id) {
            $this->setState(\array_merge($state, [
                'status'        => 'error',
                'phase'         => $phase,
                'offset'        => $offset,
                'message'       => 'Action Scheduler could not persist delayed retry',
                'last_error'    => 'Action Scheduler could not persist delayed retry',
                'failed_phase'  => $phase,
                'failed_offset' => $offset,
            ]));
            return;
        }

        $this->setState(\array_merge($state, [
            'status'           => 'running',
            'phase'            => $phase,
            'offset'           => $offset,
            'retry_attempt'    => $attempt,
            'next_retry_gmt'   => $next_gmt,
            'last_http_status' => (int)($result['status'] ?? 0),
            'last_retry_after' => $retry_after,
            'last_error'       => $error,
            'failed_phase'     => $phase,
            'failed_offset'    => $offset,
            'message'          => \sprintf('Retrying %s at %s (attempt %d/%d)', $phase, $next_gmt, $attempt, self::MAX_RETRY_ATTEMPTS),
        ]));
    }

    public static function retry_delay(int $attempt, ?int $retry_after = null, ?int $jitter = null): int
    {
        if ($retry_after !== null) {
            return \max(0, \min(self::MAX_RETRY_DELAY, $retry_after));
        }

        $exponent = \max(0, $attempt - 1);
        $base     = \min(self::MAX_RETRY_DELAY, self::BASE_RETRY_DELAY * (2 ** $exponent));
        $jitter   = $jitter ?? \mt_rand(0, \min(5, self::MAX_RETRY_DELAY - $base));

        return \min(self::MAX_RETRY_DELAY, $base + \max(0, $jitter));
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
        $dispatched = false;
        if (
            $this->getState()['status'] === 'running'
            && \class_exists('ActionScheduler')
            && \function_exists('as_has_scheduled_action')
            && \as_has_scheduled_action(self::HOOK, null, self::GROUP)
        ) {
            try {
                $runner = ActionScheduler::runner();
                if (\is_object($runner) && \method_exists($runner, 'maybe_dispatch_async_request')) {
                    $runner->maybe_dispatch_async_request();
                    $dispatched = true;
                }
            } catch (\Throwable $e) {
                // ignore — WP-Cron will catch up
            }
        }
        \wp_send_json(['ran' => $ran, 'dispatch_requested' => $dispatched]);
    }
}
