<?php

declare(strict_types=1);

namespace CherryLink\Indexing;

use CherryLink\Api\AsyncClient;
use CherryLink\Api\Client;
use CherryLink\Contracts\Registerable;
use CherryLink\Indexing\Outbox\Capture;
use CherryLink\Indexing\Outbox\Repository;
use CherryLink\Support\Capabilities;
use CherryLink\Support\CapabilitiesRefresh;
use CherryLink\Support\License;
use CherryLink\Support\Options;

/**
 * Durable bulk indexing run (plan stage P3).
 *
 * A scan is a chain of Action Scheduler turns that never holds state in the
 * browser or in PHP memory:
 *
 *   manifest -> create run -> upload batches -> upload deletes -> seal -> poll
 *
 * Guarantees this class is responsible for:
 *
 *  - **Persistence**: run id, phase, cursor, counts, timers and progress live in
 *    the non-autoloaded `cherrylink_async_run` option. A recovery sweep restarts
 *    the chain if a crash happens between persisting a cursor and scheduling the
 *    next action, and scheduling failures are recovered rather than swallowed.
 *  - **Run identity + fencing**: every callback carries the run key and the
 *    attempt, and a worker lock prevents overlapping turns for one scan.
 *  - **Idempotent retries**: the exact batch (ids, revisions, payloads) is
 *    persisted *before* it is sent and reused on retry, so a lost response
 *    replays byte-identically and cannot trip `idempotency_key_reused`.
 *  - **Ordering**: uploads allocate their revision before the authoritative read
 *    (`Outbox\Capture::build()`); a planned delete re-reads the post first, so a
 *    republish during the scan is never overwritten by a stale tombstone.
 *  - **Fail-closed inventory**: a failed post-list query never becomes an empty
 *    inventory (which would declare an empty snapshot or delete everything).
 *  - **Routing vs availability**: the tenant's durable mode decides whether the
 *    bulk run is used; if async acceptance is unavailable the run pauses and
 *    probes, instead of falling back to synchronous writes the API would reject.
 */
class AsyncBulkScan implements Registerable
{
    public const HOOK  = 'cherrylink_async_bulk';
    public const GROUP = 'cherrylink_async';

    /** Independent recovery sweep (WP-Cron). */
    public const SWEEP_HOOK = 'cherrylink_async_bulk_sweep';

    /** Persisted run state (autoload off). */
    public const STATE = 'cherrylink_async_run';

    /** Persisted worklists (autoload off). */
    public const PLAN = 'cherrylink_async_plan';

    /** The single in-flight batch, persisted before it is sent (autoload off). */
    public const BATCH = 'cherrylink_async_batch';

    /** Worker lock: prevents overlapping turns for the same scan. */
    public const LOCK = 'cherrylink_async_worker_lock';

    public const UPLOAD_BATCH = 20;
    public const DELETE_BATCH = 50;

    private const MAX_ATTEMPTS     = 8;
    private const POLL_MIN_SECONDS = 10;
    private const POLL_MAX_SECONDS = 60;
    private const PROBE_SECONDS    = 60;
    private const WORKER_LOCK_TTL  = 180;

    /** Run states that end a scan. */
    private const TERMINAL_RUN_STATES = [
        'completed',
        'completed_with_errors',
        'cancelled',
        'expired',
        'failed',
    ];

    /** @var Options */
    private $options;

    /** @var License */
    private $license;

    /** @var Capabilities */
    private $capabilities;

    /** @var callable */
    private $client_factory;

    /** @var callable */
    private $capture_factory;

    /** @var callable():array Returns the Client::fetch_manifest_result() shape. */
    private $manifest_factory;

    public function __construct(
        Options $options,
        License $license,
        ?Capabilities $capabilities = null,
        ?callable $client_factory = null,
        ?callable $capture_factory = null,
        ?callable $manifest_factory = null
    ) {
        $this->options         = $options;
        $this->license         = $license;
        $this->capabilities    = $capabilities ?? new Capabilities();
        $this->client_factory  = $client_factory ?? static function (): AsyncClient {
            return new AsyncClient(new Client(null, Client::CONTEXT_BACKGROUND));
        };
        $this->capture_factory = $capture_factory ?? static function (): Capture {
            return new Capture(new Repository(), new Client(null, Client::CONTEXT_BACKGROUND));
        };
        $this->manifest_factory = $manifest_factory ?? static function (): array {
            return (new Client(null, Client::CONTEXT_BACKGROUND))->fetch_manifest_result();
        };
    }

    public function register(): void
    {
        \add_action(self::HOOK, [$this, 'worker'], 10, 5);
        \add_action(self::SWEEP_HOOK, [$this, 'sweepTurn'], 10, 0);
        \add_action('wp_loaded', [$this, 'maybeScheduleSweep']);
    }

    // ── State ───────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function getState(): array
    {
        $defaults = [
            'status'           => 'idle',
            'phase'            => null,
            'cursor'           => 0,
            'run_id'           => '',
            'client_run_key'   => '',
            'mode'             => 'incremental',
            'source_epoch'     => '',
            'source_revision'  => 0,
            'generation'       => 0,
            'upsert_total'     => 0,
            'delete_total'     => 0,
            'uploaded'         => 0,
            'deleted'          => 0,
            'skipped_deletes'  => 0,
            'sealed'           => false,
            'poll_attempt'     => 0,
            'next_poll_gmt'    => '',
            'retry_attempt'    => 0,
            'next_retry_gmt'   => '',
            'last_http_status' => 0,
            'last_retry_after' => null,
            'last_error'       => '',
            'failed_phase'     => null,
            'failed_offset'    => null,
            'message'          => '',
            'progress'         => [],
            'started_gmt'      => '',
            'updated_gmt'      => '',
            'force_full'       => false,
            'waiting'          => false,
        ];

        return \array_merge($defaults, (array) \get_option(self::STATE, $defaults));
    }

    /** @param array<string,mixed> $patch @return array<string,mixed> */
    public function setState(array $patch): array
    {
        $state = \array_merge($this->getState(), $patch, ['updated_gmt' => \gmdate('Y-m-d H:i:s')]);
        \update_option(self::STATE, $state, false);

        return $state;
    }

    public function isRunning(): bool
    {
        $state = $this->getState();
        if (! \in_array($state['status'], ['running', 'paused'], true)) {
            return false;
        }
        if (\function_exists('as_has_scheduled_action')) {
            return (bool) \as_has_scheduled_action(self::HOOK, null, self::GROUP);
        }

        return true;
    }

    /** Whether this tenant routes bulk indexing through the durable run. */
    public function handlesTenant(): bool
    {
        return $this->capabilities->asyncEnabled();
    }

    /** Whether async acceptance is currently possible. */
    public function isAvailable(): bool
    {
        return $this->license->isLicensed() && $this->capabilities->asyncAvailable();
    }

    /**
     * Map the persisted run onto the existing admin status shape.
     *
     * `uploaded` (accepted by the API) stays separate from
     * `vectors_applied`/`links_applied` (actually indexed). Upload completion is
     * never reported as indexing completion.
     *
     * @return array<string,mixed>
     */
    public function statusForUi(): array
    {
        $state    = $this->getState();
        $progress = \is_array($state['progress'] ?? null) ? $state['progress'] : [];
        $phase    = (string) ($state['phase'] ?? '');

        return [
            'engine'           => 'async',
            'status'           => $state['status'],
            'phase'            => $phase,
            'mode'             => $state['mode'],
            'run_id'           => $state['run_id'],
            'uploading'        => \in_array($phase, ['manifest', 'create', 'upload', 'deletes', 'seal'], true)
                && $state['status'] === 'running',
            'accepted'         => (int) $state['uploaded'],
            'accepted_deletes' => (int) $state['deleted'],
            'skipped_deletes'  => (int) $state['skipped_deletes'],
            'processing'       => $phase === 'poll' && $state['status'] === 'running',
            'vectors_applied'  => (int) ($progress['vectors_applied'] ?? 0),
            'vectors_pending'  => (int) ($progress['vectors_pending'] ?? 0),
            'links_applied'    => (int) ($progress['links_applied'] ?? 0),
            'links_pending'    => (int) ($progress['links_pending'] ?? 0),
            'uploaded'         => (int) $state['uploaded'],
            'deleted'          => (int) $state['deleted'],
            'next_retry_gmt'   => $state['next_retry_gmt'],
            'next_poll_gmt'    => $state['next_poll_gmt'],
            'last_error'       => $state['last_error'],
            'message'          => $state['message'],
            'sealed'           => (bool) $state['sealed'],
            'waiting'          => (bool) $state['waiting'],
        ];
    }

    // ── Public controls ─────────────────────────────────────────────────────

    /**
     * Start (or resume) a durable run. Returns immediately; no API call happens
     * in this request. When acceptance is unavailable the run waits instead of
     * silently falling back to synchronous indexing.
     *
     * @return array<string,mixed>
     */
    public function start(bool $force_full = false): array
    {
        if (! $this->license->isLicensed()) {
            return $this->setState([
                'status'  => 'error',
                'message' => \__('Лицензия неактивна — синхронизация недоступна.', 'cherrylink'),
            ]);
        }
        if (! \function_exists('as_enqueue_async_action')) {
            return $this->setState(['status' => 'error', 'message' => 'Action Scheduler unavailable']);
        }
        if (\in_array($this->getState()['status'], ['running', 'paused'], true) && $this->isRunning()) {
            return $this->getState();
        }

        $state = $this->setState([
            'status'           => 'running',
            'phase'            => 'manifest',
            'cursor'           => 0,
            'run_id'           => '',
            'client_run_key'   => $this->newRunKey(),
            'source_epoch'     => '',
            'source_revision'  => 0,
            'generation'       => 0,
            'upsert_total'     => 0,
            'delete_total'     => 0,
            'uploaded'         => 0,
            'deleted'          => 0,
            'skipped_deletes'  => 0,
            'sealed'           => false,
            'poll_attempt'     => 0,
            'next_poll_gmt'    => '',
            'retry_attempt'    => 0,
            'next_retry_gmt'   => '',
            'last_http_status' => 0,
            'last_retry_after' => null,
            'last_error'       => '',
            'failed_phase'     => null,
            'failed_offset'    => null,
            'message'          => '',
            'progress'         => [],
            'started_gmt'      => \gmdate('Y-m-d H:i:s'),
            'force_full'       => $force_full,
            'waiting'          => false,
        ]);
        \delete_option(self::PLAN);
        \delete_option(self::BATCH);

        $meta = $this->options->meta();
        $meta['indexing_process'] = 'IN_PROGRESS';
        $this->options->saveMeta($meta);

        if (! $this->isAvailable()) {
            $this->setState([
                'status'  => 'paused',
                'waiting' => true,
                'message' => \__('Асинхронная индексация недоступна. Загрузка возобновится автоматически.', 'cherrylink'),
            ]);
            $this->scheduleLater(self::PROBE_SECONDS, 'probe', 0, 0);

            return $this->getState();
        }

        if (! $this->enqueue('manifest', 0, 0)) {
            // The recovery sweep will restart the chain; keep the run resumable.
            return $this->setState([
                'message' => \__('Планирование задерживается, попробуем снова автоматически.', 'cherrylink'),
            ]);
        }

        return $state;
    }

    /** @return array<string,mixed> */
    public function pause(): array
    {
        return $this->setState([
            'status'  => 'paused',
            'waiting' => false,
            'message' => \__('Приостановлено. Загрузка продолжится после «Продолжить».', 'cherrylink'),
        ]);
    }

    /** @return array<string,mixed> */
    public function resume(): array
    {
        $state = $this->getState();
        if ($state['status'] !== 'paused') {
            return $state;
        }

        $state = $this->setState(['status' => 'running', 'waiting' => false, 'message' => '']);
        $this->enqueue((string) $state['phase'], (int) $state['cursor'], 0);

        return $state;
    }

    /** @return array<string,mixed> */
    public function cancel(): array
    {
        $state  = $this->getState();
        $status = (string) ($state['status'] ?? 'idle');
        if (! \in_array($status, ['running', 'paused'], true)) {
            return $state;
        }

        if (! empty($state['run_id']) && empty($state['sealed'])) {
            $this->asyncClient()->cancelRun((string) $state['run_id']);
        }

        $this->unschedule();
        \delete_option(self::PLAN);
        \delete_option(self::BATCH);

        $meta = $this->options->meta();
        $meta['indexing_process'] = 'CANCELLED';
        $this->options->saveMeta($meta);

        return $this->setState([
            'status'  => 'cancelled',
            'waiting' => false,
            'message' => \__('Остановлено пользователем', 'cherrylink'),
        ]);
    }

    // ── Recovery ────────────────────────────────────────────────────────────

    /** Schedule the independent recovery sweep (WP-Cron only). */
    public function maybeScheduleSweep(): void
    {
        if (! \function_exists('wp_next_scheduled') || ! \function_exists('wp_schedule_event')) {
            return;
        }
        if (\wp_next_scheduled(self::SWEEP_HOOK)) {
            return;
        }
        \wp_schedule_event(\time() + 60, CapabilitiesRefresh::SCHEDULE, self::SWEEP_HOOK);
    }

    public function sweepTurn(): void
    {
        $this->sweep();
    }

    /**
     * Restart a chain that died between persisting a cursor and scheduling the
     * next action, and probe while waiting for async acceptance.
     *
     * @return bool True when a turn was (re)scheduled.
     */
    public function sweep(): bool
    {
        $state = $this->getState();
        if (! \in_array($state['status'], ['running', 'paused'], true)) {
            return false;
        }

        if (
            \function_exists('as_has_scheduled_action')
            && \as_has_scheduled_action(self::HOOK, null, self::GROUP)
        ) {
            return false;   // a turn is already queued
        }

        if ($state['waiting']) {
            return $this->enqueue('probe', 0, 0);
        }

        return $this->enqueue((string) $state['phase'], (int) $state['cursor'], (int) $state['retry_attempt']);
    }

    // ── Worker ──────────────────────────────────────────────────────────────

    /**
     * One Action Scheduler turn.
     *
     * @param string     $phase
     * @param int|string $cursor
     * @param int|string $attempt
     * @param bool|null  $force_full
     * @param string     $runKey
     */
    public function worker($phase = 'manifest', $cursor = 0, $attempt = 0, $force_full = null, $runKey = ''): void
    {
        $state = $this->getState();
        $phase = (string) $phase;

        // Fencing 1: callbacks must belong to the current run and be able to run.
        if ($phase !== 'probe') {
            if ($state['status'] !== 'running') {
                return;
            }
            if ((string) $runKey !== (string) $state['client_run_key']) {
                return;   // callback from a replaced run
            }
        } elseif (! \in_array($state['status'], ['running', 'paused'], true) || ! $state['waiting']) {
            return;
        }

        $cursor  = (int) $cursor;
        $attempt = (int) $attempt;
        if ($phase !== 'probe' && ($state['phase'] !== $phase || (int) $state['cursor'] !== $cursor)) {
            return;   // stale callback from an older cursor
        }
        if ($phase !== 'probe' && (int) $state['retry_attempt'] !== $attempt) {
            return;
        }
        if ($force_full !== null && (bool) $state['force_full'] !== (bool) $force_full) {
            return;
        }

        // Fencing 2: only one turn may run for this scan at a time.
        $token = $this->acquireWorkerLock();
        if ($token === null) {
            return;
        }

        try {
            switch ($phase) {
                case 'probe':
                    $this->phaseProbe();
                    return;
                case 'manifest':
                    $this->phaseManifest($state);
                    return;
                case 'create':
                    $this->phaseCreate($state);
                    return;
                case 'upload':
                    $this->phaseUpload($state);
                    return;
                case 'deletes':
                    $this->phaseDeletes($state);
                    return;
                case 'seal':
                    $this->phaseSeal($state);
                    return;
                case 'poll':
                    $this->phasePoll($state);
                    return;
                default:
                    $this->finish($state, 'done', \__('Сканирование завершено', 'cherrylink'));
                    return;
            }
        } catch (InventoryUnavailableException $error) {
            $this->retryOrFail($state, $phase, $cursor, [
                'ok'        => false,
                'status'    => 503,
                'retryable' => true,
                'error'     => $error->getMessage(),
            ]);
        } catch (\Throwable $error) {
            $this->fail($state, $phase, $cursor, $error->getMessage());
        } finally {
            $this->releaseWorkerLock($token);
        }
    }

    // ── Phases ──────────────────────────────────────────────────────────────

    private function phaseProbe(): void
    {
        $state = $this->getState();
        if (! $this->isAvailable()) {
            $this->scheduleLater(self::PROBE_SECONDS, 'probe', 0, 0);
            return;
        }

        $this->setState([
            'status'  => 'running',
            'phase'   => 'manifest',
            'cursor'  => 0,
            'waiting' => false,
            'message' => '',
        ]);
        $this->enqueue('manifest', 0, 0);
        unset($state);
    }

    /** @param array<string,mixed> $state */
    private function phaseManifest(array $state): void
    {
        $result = (array) \call_user_func($this->manifest_factory);

        if (! $result['ok']) {
            $this->retryOrFail($state, 'manifest', 0, $result);
            return;
        }

        // Fail closed: a broken inventory query must never look like "no posts".
        $publishes = $this->publishedPosts();
        $current   = [];
        foreach ($publishes as $row) {
            $current[(int) $row['ID']] = (string) $row['post_modified_gmt'];
        }

        // Enrollment / snapshot-required tenants can only be seeded by a snapshot:
        // live mutations are not accepted yet, so an incremental run would have
        // nothing to reconcile against.
        $snapshot = (bool) $state['force_full'] || ! $this->capabilities->mutationsAvailable();
        $plan     = IndexHelpers::compute_sync_plan($result['map'], $current, $snapshot);
        if ($snapshot) {
            // A snapshot prunes by absence at seal; explicit deletes are unnecessary
            // and would consume the same ordering budget.
            $plan['delete'] = [];
        }
        \update_option(self::PLAN, $plan, false);

        $capture  = $this->capture();
        $marker   = $snapshot
            ? $capture->allocateRunMarker(
                (string) $state['client_run_key'],
                'snapshot',
                \count($plan['upsert']),
                $this->digestFor($plan['upsert'])
            )
            : $capture->allocateRunMarker((string) $state['client_run_key'], 'incremental');

        $this->advance($state, 'create', 0, [
            'mode'            => $snapshot ? 'snapshot' : 'incremental',
            'force_full'      => $snapshot,
            'source_epoch'    => $capture->repository()->sourceEpoch(),
            'source_revision' => $this->markerRevision($marker),
            'upsert_total'    => \count($plan['upsert']),
            'delete_total'    => \count($plan['delete']),
            'cursor'          => 0,
        ]);
    }

    /** @param array<string,mixed> $state */
    private function phaseCreate(array $state): void
    {
        $payload = [
            'client_run_key'  => (string) $state['client_run_key'],
            'mode'            => (string) $state['mode'],
            'source_epoch'    => (string) $state['source_epoch'],
            'source_revision' => (int) $state['source_revision'],
        ];
        if ($state['mode'] === 'snapshot') {
            $plan = $this->plan();
            $payload['expected_membership_count']  = \count($plan['upsert']);
            $payload['expected_membership_digest'] = $this->digestFor($plan['upsert']);
        }

        $result = $this->asyncClient()->createRun($payload);
        if (! $result['ok']) {
            $this->retryOrFail($state, 'create', 0, $result);
            return;
        }

        $next = \count($this->plan()['upsert']) > 0 ? 'upload' : 'deletes';

        $this->advance($state, $next, 0, [
            'run_id'     => (string) $result['run_id'],
            'generation' => (int) ($result['body']['generation'] ?? 0),
            'cursor'     => 0,
        ]);
    }

    /** @param array<string,mixed> $state */
    private function phaseUpload(array $state): void
    {
        $plan    = $this->plan();
        $offset  = (int) $state['cursor'];
        $slice   = \array_slice($plan['upsert'], $offset, self::UPLOAD_BATCH);
        $batchId = $this->batchKey($state, 'u', $offset);

        if ($slice === []) {
            $this->advance($state, 'deletes', 0, ['cursor' => 0]);
            return;
        }

        // Reuse the exact batch already persisted for this cursor. Rebuilding it on
        // retry would change revisions/content and trip idempotency_key_reused.
        $batch = $this->loadBatch($batchId);
        if ($batch === null) {
            $capture   = $this->capture();
            $documents = [];
            $demoted   = [];
            foreach ($slice as $postId) {
                $built = $capture->build((int) $postId);
                if ($built['deleted']) {
                    $demoted[] = (int) $postId;
                    continue;
                }
                $documents[] = \array_merge($built['payload'], [
                    'source_epoch'    => (string) $state['source_epoch'],
                    'source_revision' => (int) $built['revision'],
                    'operation'       => 'upsert',
                ]);
            }

            if ($demoted !== [] && $state['mode'] === 'snapshot') {
                // The declared membership no longer matches what we can upload, so
                // the seal would fail. Restart the snapshot with a fresh run.
                $this->restartSnapshot($state, \count($demoted));
                return;
            }

            if ($demoted !== []) {
                $plan['delete'] = \array_values(\array_unique(\array_merge($plan['delete'], $demoted)));
                \update_option(self::PLAN, $plan, false);
            }

            if ($documents === []) {
                $this->advancePastSlice($state, $plan, $offset, \count($slice));
                return;
            }

            $batch = ['id' => $batchId, 'items' => $documents];
            $this->saveBatch($batch);
        }

        /** @var array<int,array<string,mixed>> $items */
        $items  = $batch['items'];
        $result = $this->asyncClient()->appendDocuments(
            (string) $state['run_id'],
            $batchId,
            $items,
            \count($items)
        );

        if (! $result['ok']) {
            $this->retryOrFail($state, 'upload', $offset, $result);
            return;
        }

        $this->deleteBatch();
        $this->advancePastSlice($state, $plan, $offset, \count($slice), (int) $result['accepted_count']);
    }

    /** @param array<string,mixed> $state */
    private function phaseDeletes(array $state): void
    {
        $plan   = $this->plan();
        $offset = (int) $state['cursor'];
        $slice  = \array_slice($plan['delete'], $offset, self::DELETE_BATCH);
        $batchId = $this->batchKey($state, 'd', $offset);

        if ($slice === []) {
            $this->advance($state, 'seal', 0, ['cursor' => 0]);
            return;
        }

        $batch = $this->loadBatch($batchId);
        if ($batch === null) {
            $capture = $this->capture();
            $deletes = [];
            $skipped = 0;
            foreach ($slice as $postId) {
                // Re-read before tombstoning: a republish during the scan must not be
                // overwritten by the bulk tombstone.
                $built = $capture->build((int) $postId);
                if (! $built['deleted']) {
                    $skipped++;
                    continue;
                }
                $deletes[] = [
                    'post_id'         => (int) $postId,
                    'source_epoch'    => (string) $state['source_epoch'],
                    'source_revision' => (int) $built['revision'],
                ];
            }

            if ($deletes === []) {
                $this->advancePastDeletes($state, $plan, $offset, \count($slice), 0, $skipped);
                return;
            }

            $batch = ['id' => $batchId, 'items' => $deletes];
            $this->saveBatch($batch);
        }

        /** @var array<int,array<string,mixed>> $items */
        $items  = $batch['items'];
        $result = $this->asyncClient()->appendDeletes(
            (string) $state['run_id'],
            $batchId,
            $items,
            \count($items)
        );

        if (! $result['ok']) {
            $this->retryOrFail($state, 'deletes', $offset, $result);
            return;
        }

        $this->deleteBatch();
        $this->advancePastDeletes($state, $plan, $offset, \count($slice), (int) $result['accepted_count'], 0);
    }

    /** @param array<string,mixed> $state */
    private function phaseSeal(array $state): void
    {
        $result = $this->asyncClient()->sealRun((string) $state['run_id'], (string) $state['client_run_key'] . ':seal');
        if (! $result['ok']) {
            if ((string) ($result['code'] ?? '') === 'snapshot_membership_mismatch' && $state['mode'] === 'snapshot') {
                $this->restartSnapshot($state, 0);
                return;
            }
            $this->retryOrFail($state, 'seal', 0, $result);
            return;
        }

        $this->advance($state, 'poll', 0, [
            'sealed'       => true,
            'poll_attempt' => 0,
            'next_poll_gmt' => '',
            'message'      => \__('Загружено. Идёт обработка на сервере.', 'cherrylink'),
        ]);
    }

    /** @param array<string,mixed> $state */
    private function phasePoll(array $state): void
    {
        $result = $this->asyncClient()->runStatus((string) $state['run_id']);
        if (! $result['ok']) {
            $this->retryOrFail($state, 'poll', 0, $result);
            return;
        }

        $progress = $this->progressFrom($result['body']);
        $runState = (string) ($result['body']['state'] ?? '');

        if (\in_array($runState, self::TERMINAL_RUN_STATES, true)) {
            $ok    = $runState === 'completed';
            $state = $this->setState(['progress' => $progress]);
            $this->finish($state, $ok ? 'done' : 'completed_with_errors', $ok
                ? \__('Индексация завершена', 'cherrylink')
                : \__('Индексация завершена с ошибками', 'cherrylink'));
            return;
        }

        $attempt = (int) $state['poll_attempt'] + 1;
        $delay   = \min(self::POLL_MAX_SECONDS, self::POLL_MIN_SECONDS * (1 + \intdiv($attempt, 3)));

        $this->setState([
            'phase'         => 'poll',
            'cursor'        => 0,
            'poll_attempt'  => $attempt,
            'next_poll_gmt' => \gmdate('Y-m-d H:i:s', \time() + $delay),
            'progress'      => $progress,
            'message'       => \sprintf(
                /* translators: 1: applied vectors, 2: total items, 3: pending items */
                \__('Обработка на сервере: %1$d из %2$d, в очереди %3$d.', 'cherrylink'),
                (int) ($progress['vectors_applied'] ?? 0),
                (int) ($progress['items'] ?? 0),
                (int) ($progress['vectors_pending'] ?? 0)
            ),
        ]);

        $this->scheduleLater($delay, 'poll', 0, 0);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $state
     * @param array{upsert:int[],delete:int[]} $plan
     */
    private function advancePastSlice(array $state, array $plan, int $offset, int $sent, int $accepted = 0): void
    {
        $nextOffset = $offset + $sent;
        $done       = $nextOffset >= \count($plan['upsert']);

        $this->advance(
            $state,
            $done ? 'deletes' : 'upload',
            $done ? 0 : $nextOffset,
            [
                'cursor'   => $done ? 0 : $nextOffset,
                'uploaded' => (int) $state['uploaded'] + $accepted,
            ]
        );
    }

    /**
     * @param array<string,mixed> $state
     * @param array{upsert:int[],delete:int[]} $plan
     */
    private function advancePastDeletes(array $state, array $plan, int $offset, int $sent, int $accepted, int $skipped): void
    {
        $nextOffset = $offset + $sent;
        $done       = $nextOffset >= \count($plan['delete']);

        $this->advance(
            $state,
            $done ? 'seal' : 'deletes',
            $done ? 0 : $nextOffset,
            [
                'cursor'          => $done ? 0 : $nextOffset,
                'deleted'         => (int) $state['deleted'] + $accepted,
                'skipped_deletes' => (int) $state['skipped_deletes'] + $skipped,
            ]
        );
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $extra */
    private function advance(array $state, string $phase, int $cursor, array $extra = []): void
    {
        $this->setState(\array_merge($extra, [
            'phase'            => $phase,
            'cursor'           => $cursor,
            'retry_attempt'    => 0,
            'next_retry_gmt'   => '',
            'last_http_status' => 0,
            'last_retry_after' => null,
            'last_error'       => '',
            'failed_phase'     => null,
            'failed_offset'    => null,
        ]));

        if ($phase === 'poll') {
            $this->scheduleLater(self::POLL_MIN_SECONDS, 'poll', 0, 0);
            return;
        }

        // A scheduling failure is not fatal: the recovery sweep re-queues the
        // persisted phase/cursor on the next cron tick.
        $this->enqueue($phase, $cursor, 0);
        unset($state);
    }

    /**
     * Restart a snapshot whose declared membership can no longer be uploaded.
     * The accepting run is cancelled so the backend restores the affected heads.
     *
     * @param array<string,mixed> $state
     */
    private function restartSnapshot(array $state, int $drifted): void
    {
        if (! empty($state['run_id'])) {
            $this->asyncClient()->cancelRun((string) $state['run_id']);
        }
        \delete_option(self::BATCH);

        $this->setState([
            'status'          => 'running',
            'phase'           => 'manifest',
            'cursor'          => 0,
            'run_id'          => '',
            'client_run_key'  => $this->newRunKey(),
            'sealed'          => false,
            'uploaded'        => 0,
            'deleted'         => 0,
            'skipped_deletes' => 0,
            'message'         => $drifted > 0
                ? \__('Содержимое изменилось во время сканирования — сканирование перезапущено.', 'cherrylink')
                : \__('Состав сайта изменился — сканирование перезапущено.', 'cherrylink'),
        ]);

        \delete_option(self::PLAN);
        $this->enqueue('manifest', 0, 0);
    }

    /** @param array<string,mixed> $state */
    private function finish(array $state, string $status, string $message): void
    {
        $meta = $this->options->meta();
        $meta['indexing_process'] = $status === 'done' ? 'DONE' : 'DONE_WITH_ERRORS';
        $meta['last_scan_gmt']    = \gmdate('Y-m-d H:i:s');
        $meta['last_upserted']    = (int) $state['uploaded'];
        $meta['last_deleted']     = (int) $state['deleted'];
        $this->options->saveMeta($meta);
        \delete_option(self::PLAN);
        \delete_option(self::BATCH);
        if ($status === 'done') {
            $this->options->clearIndexDirty();
        }

        $this->setState(['status' => $status, 'message' => $message, 'waiting' => false]);
    }

    /** @param array<string,mixed> $state */
    private function fail(array $state, string $phase, int $cursor, string $error): void
    {
        $meta = $this->options->meta();
        $meta['indexing_process'] = 'ERROR';
        $this->options->saveMeta($meta);

        $this->setState([
            'status'        => 'error',
            'phase'         => $phase,
            'cursor'        => $cursor,
            'message'       => $error,
            'last_error'    => $error,
            'failed_phase'  => $phase,
            'failed_offset' => $cursor,
            'waiting'       => false,
        ]);
    }

    /** @param array<string,mixed> $state @param array<string,mixed> $result */
    private function retryOrFail(array $state, string $phase, int $cursor, array $result): void
    {
        $attempt   = (int) $state['retry_attempt'] + 1;
        $retryable = \array_key_exists('retryable', $result)
            ? (bool) $result['retryable']
            : AsyncClient::isRetryable($result);
        $error     = (string) ($result['error'] ?? 'request failed');

        if (! $retryable || $attempt > self::MAX_ATTEMPTS) {
            $this->fail($state, $phase, $cursor, ! $retryable
                ? \sprintf('%s failed permanently: %s', $phase, $error)
                : \sprintf('%s retry budget exhausted: %s', $phase, $error));
            return;
        }

        $delay = Repository::retryDelay($attempt, isset($result['retry_after']) ? (int) $result['retry_after'] : null);
        $this->setState([
            'phase'            => $phase,
            'cursor'           => $cursor,
            'retry_attempt'    => $attempt,
            'next_retry_gmt'   => \gmdate('Y-m-d H:i:s', \time() + $delay),
            'last_http_status' => (int) ($result['status'] ?? 0),
            'last_retry_after' => $result['retry_after'] ?? null,
            'last_error'       => $error,
            'failed_phase'     => $phase,
            'failed_offset'    => $cursor,
            'message'          => \sprintf('Retrying %s (attempt %d/%d)', $phase, $attempt, self::MAX_ATTEMPTS),
        ]);

        $this->scheduleLater($delay, $phase, $cursor, $attempt);
    }

    /** @return array{upsert:int[],delete:int[]} */
    private function plan(): array
    {
        $plan = (array) \get_option(self::PLAN, ['upsert' => [], 'delete' => []]);

        return [
            'upsert' => \array_values(\array_map('intval', (array) ($plan['upsert'] ?? []))),
            'delete' => \array_values(\array_map('intval', (array) ($plan['delete'] ?? []))),
        ];
    }

    /**
     * Published posts (ID + modified time).
     *
     * @return array<int,array<string,mixed>>
     * @throws InventoryUnavailableException When the query fails.
     */
    private function publishedPosts(): array
    {
        global $wpdb;
        if (! \is_object($wpdb) || ! \is_callable([$wpdb, 'get_results'])) {
            throw new InventoryUnavailableException('WordPress database is unavailable');
        }
        /** @var \wpdb $db */
        $db      = $wpdb;
        $allowed = IndexHelpers::get_allowed_types_list();
        $ph      = \implode(',', \array_fill(0, \count($allowed), '%s'));

        try {
            $rows = $db->get_results($db->prepare(
                "SELECT ID, post_modified_gmt FROM {$db->posts} WHERE post_type IN ($ph) AND post_status = 'publish'",
                $allowed
            ), ARRAY_A);
        } catch (\Throwable $error) {
            throw new InventoryUnavailableException('Post inventory query failed: ' . \esc_html($error->getMessage()));
        }

        $lastError = (string) $db->last_error;
        if (! \is_array($rows)) {
            throw new InventoryUnavailableException(
                'Post inventory query failed' . ($lastError !== '' ? ': ' . \esc_html($lastError) : '')
            );
        }

        return \array_values($rows);
    }

    /**
     * Snapshot membership digest: sorted unique IDs, one decimal per LF-delimited
     * line, no trailing newline — byte-identical to the API's
     * `snapshot_membership_digest()` so seal can verify it.
     *
     * @param int[] $postIds
     */
    public function digestFor(array $postIds): string
    {
        $ids = \array_values(\array_unique(\array_map('intval', $postIds)));
        \sort($ids);

        return \hash('sha256', \implode("\n", $ids));
    }

    private function markerRevision(int $markerRowId): int
    {
        $row = $this->capture()->repository()->get($markerRowId);

        return (int) ($row['source_revision'] ?? 0);
    }

    /** @param array<string,mixed> $state */
    private function batchKey(array $state, string $kind, int $offset): string
    {
        return $state['client_run_key'] . ':' . $kind . ':' . $offset;
    }

    /** @return array{id:string,items:array<int,array<string,mixed>>}|null */
    private function loadBatch(string $batchId): ?array
    {
        $batch = \get_option(self::BATCH, null);
        if (! \is_array($batch) || ($batch['id'] ?? '') !== $batchId || ! \is_array($batch['items'] ?? null)) {
            return null;
        }

        return ['id' => (string) $batch['id'], 'items' => $batch['items']];
    }

    /** @param array{id:string,items:array<int,array<string,mixed>>} $batch */
    private function saveBatch(array $batch): void
    {
        \update_option(self::BATCH, $batch, false);
    }

    private function deleteBatch(): void
    {
        \delete_option(self::BATCH);
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function progressFrom($body): array
    {
        $body = \is_array($body) ? $body : [];

        return [
            'items'            => (int) ($body['items'] ?? 0),
            'vectors_applied'  => (int) ($body['vectors_applied'] ?? 0),
            'vectors_pending'  => (int) ($body['vectors_pending'] ?? 0),
            'vectors_terminal' => (int) ($body['vectors_terminal'] ?? 0),
            'links_applied'    => (int) ($body['links_applied'] ?? 0),
            'links_pending'    => (int) ($body['links_pending'] ?? 0),
            'links_terminal'   => (int) ($body['links_terminal'] ?? 0),
            'run_state'        => (string) ($body['state'] ?? ''),
        ];
    }

    private function newRunKey(): string
    {
        $suffix = \function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : \bin2hex(\random_bytes(8));

        return 'scan-' . \gmdate('YmdHis') . '-' . $suffix;
    }

    private function enqueue(string $phase, int $cursor, int $attempt): bool
    {
        if (! \function_exists('as_enqueue_async_action')) {
            return false;
        }
        try {
            $id = \as_enqueue_async_action(self::HOOK, [
                'phase'      => $phase,
                'cursor'     => $cursor,
                'attempt'    => $attempt,
                'force_full' => (bool) $this->getState()['force_full'],
                'run_key'    => (string) $this->getState()['client_run_key'],
            ], self::GROUP);
        } catch (\Throwable $error) {
            return false;
        }

        return (bool) $id;
    }

    private function scheduleLater(int $delay, string $phase, int $cursor, int $attempt): void
    {
        if (! \function_exists('as_schedule_single_action')) {
            return;
        }
        try {
            \as_schedule_single_action(\time() + \max(1, $delay), self::HOOK, [
                'phase'      => $phase,
                'cursor'     => $cursor,
                'attempt'    => $attempt,
                'force_full' => (bool) $this->getState()['force_full'],
                'run_key'    => (string) $this->getState()['client_run_key'],
            ], self::GROUP);
        } catch (\Throwable $error) {
            // The recovery sweep re-queues the persisted phase/cursor.
        }
    }

    private function unschedule(): void
    {
        if (! \function_exists('as_get_scheduled_actions') || ! \class_exists('ActionScheduler_Store')) {
            return;
        }
        $ids = \as_get_scheduled_actions([
            'hook'     => self::HOOK,
            'group'    => self::GROUP,
            'status'   => \ActionScheduler_Store::STATUS_PENDING,
            'per_page' => -1,
        ], 'ids');
        $store = \ActionScheduler::store();
        foreach ($ids as $id) {
            try {
                $store->cancel_action($id);
            } catch (\Throwable $error) {
                // best effort
            }
        }
    }

    // ── Worker lock ─────────────────────────────────────────────────────────

    private function acquireWorkerLock(): ?string
    {
        $current = \get_option(self::LOCK, '');
        if (\is_string($current) && $current !== '') {
            $expiry = (int) \strtok($current, '|');
            if ($expiry > \time()) {
                return null;   // another turn is running
            }
            \delete_option(self::LOCK);
        }

        $token = (\time() + self::WORKER_LOCK_TTL) . '|' .
            (\function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : \bin2hex(\random_bytes(8)));

        return \add_option(self::LOCK, $token, '', false) ? $token : null;
    }

    private function releaseWorkerLock(string $token): void
    {
        $current = \get_option(self::LOCK, '');
        if ($current === $token) {
            \delete_option(self::LOCK);
        }
    }

    private function asyncClient(): AsyncClient
    {
        return \call_user_func($this->client_factory);
    }

    private function capture(): Capture
    {
        return \call_user_func($this->capture_factory);
    }
}
