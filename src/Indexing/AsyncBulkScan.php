<?php

declare(strict_types=1);

namespace CherryLink\Indexing;

use CherryLink\Api\AsyncClient;
use CherryLink\Api\Client;
use CherryLink\Contracts\Registerable;
use CherryLink\Indexing\Outbox\Capture;
use CherryLink\Indexing\Outbox\Repository;
use CherryLink\Support\Capabilities;
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
 * Every identifier and cursor is persisted in a non-autoloaded option, so closing
 * the browser, a PHP timeout, or a WordPress restart resumes exactly where the run
 * stopped. Uploads use stable `batch_id`s, so replaying a batch after a crash is
 * idempotent (the API returns the original receipt).
 *
 * The upload phase allocates each item's source revision **before** reading the
 * post (via `Outbox\Capture::build()`), which preserves the canonical
 * `(source_epoch, source_revision)` ordering against concurrent live saves — a
 * live save during the scan is a newer outbox mutation and wins.
 *
 * Uploading and indexing are separate states: a `202` only means the API accepted
 * the payload. `done` is reached only when the run reports a terminal state.
 */
final class AsyncBulkScan implements Registerable
{
    public const HOOK  = 'cherrylink_async_bulk';
    public const GROUP = 'cherrylink_async';

    /** Persisted run state (autoload off). */
    public const STATE = 'cherrylink_async_run';

    /** Persisted worklists (autoload off). */
    public const PLAN = 'cherrylink_async_plan';

    public const UPLOAD_BATCH = 20;
    public const DELETE_BATCH = 50;

    private const MAX_ATTEMPTS     = 8;
    private const POLL_MIN_SECONDS = 10;
    private const POLL_MAX_SECONDS = 60;

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

    public function __construct(
        Options $options,
        License $license,
        ?Capabilities $capabilities = null,
        ?callable $client_factory = null,
        ?callable $capture_factory = null
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
    }

    public function register(): void
    {
        \add_action(self::HOOK, [$this, 'worker'], 10, 4);
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
        if ($this->getState()['status'] !== 'running') {
            return false;
        }
        if (\function_exists('as_has_scheduled_action')) {
            return (bool) \as_has_scheduled_action(self::HOOK, null, self::GROUP);
        }

        return true;
    }

    /**
     * Whether this tenant should use the durable uploader: the API must accept
     * async writes and a licence must be active.
     */
    public function isAvailable(): bool
    {
        return $this->license->isLicensed() && $this->capabilities->asyncAvailable();
    }

    /**
     * Map the persisted run onto the existing admin status shape.
     *
     * `uploaded` (accepted by the API) is deliberately separate from
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
            'engine'            => 'async',
            'status'            => $state['status'],
            'phase'             => $phase,
            'mode'              => $state['mode'],
            'run_id'            => $state['run_id'],
            'uploading'         => \in_array($phase, ['manifest', 'create', 'upload', 'deletes', 'seal'], true)
                && $state['status'] === 'running',
            'accepted'          => (int) $state['uploaded'],
            'accepted_deletes'  => (int) $state['deleted'],
            'processing'        => $phase === 'poll' && $state['status'] === 'running',
            'vectors_applied'   => (int) ($progress['vectors_applied'] ?? 0),
            'vectors_pending'   => (int) ($progress['vectors_pending'] ?? 0),
            'links_applied'     => (int) ($progress['links_applied'] ?? 0),
            'links_pending'     => (int) ($progress['links_pending'] ?? 0),
            'uploaded'          => (int) $state['uploaded'],
            'deleted'           => (int) $state['deleted'],
            'next_retry_gmt'    => $state['next_retry_gmt'],
            'next_poll_gmt'     => $state['next_poll_gmt'],
            'last_error'        => $state['last_error'],
            'message'           => $state['message'],
            'sealed'            => (bool) $state['sealed'],
        ];
    }

    private function asyncClient(): AsyncClient
    {
        return \call_user_func($this->client_factory);
    }

    private function capture(): Capture
    {
        return \call_user_func($this->capture_factory);
    }

    // ── Public controls ─────────────────────────────────────────────────────

    /**
     * Start (or resume) a durable run. Returns immediately; the first turn fetches
     * the manifest. No API call happens in this request.
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
        if (! $this->capabilities->asyncAvailable()) {
            return $this->setState([
                'status'  => 'error',
                'message' => \__('Асинхронная индексация сейчас недоступна.', 'cherrylink'),
            ]);
        }
        if (! \function_exists('as_enqueue_async_action')) {
            return $this->setState(['status' => 'error', 'message' => 'Action Scheduler unavailable']);
        }
        if ($this->isRunning()) {
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
        ]);

        $meta = $this->options->meta();
        $meta['indexing_process'] = 'IN_PROGRESS';
        $this->options->saveMeta($meta);

        if (! $this->enqueue('manifest', 0, 0)) {
            return $this->setState([
                'status'  => 'error',
                'message' => \__('Не удалось запланировать сканирование.', 'cherrylink'),
            ]);
        }

        return $state;
    }

    /** @return array<string,mixed> */
    public function pause(): array
    {
        return $this->setState([
            'status'  => 'paused',
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

        $state = $this->setState(['status' => 'running', 'message' => '']);
        $this->enqueue((string) $state['phase'], (int) $state['cursor'], 0);

        return $state;
    }

    /**
     * Cancel: an accepting run is cancelled on the backend (which restores heads),
     * otherwise the local chain simply stops and the remote run drains on its own.
     *
     * @return array<string,mixed>
     */
    public function cancel(): array
    {
        $state = $this->getState();
        $status = (string) ($state['status'] ?? 'idle');
        if (! \in_array($status, ['running', 'paused'], true)) {
            return $state;
        }

        if (! empty($state['run_id']) && empty($state['sealed'])) {
            $this->asyncClient()->cancelRun((string) $state['run_id']);
        }

        $this->unschedule();
        \delete_option(self::PLAN);

        $meta = $this->options->meta();
        $meta['indexing_process'] = 'CANCELLED';
        $this->options->saveMeta($meta);

        return $this->setState([
            'status'  => 'cancelled',
            'message' => \__('Остановлено пользователем', 'cherrylink'),
        ]);
    }

    // ── Worker ──────────────────────────────────────────────────────────────

    /**
     * One Action Scheduler turn.
     *
     * @param string     $phase
     * @param int|string $cursor
     * @param int|string $attempt
     * @param bool|null  $force_full
     */
    public function worker($phase = 'manifest', $cursor = 0, $attempt = 0, $force_full = null): void
    {
        $state = $this->getState();
        if ($state['status'] !== 'running') {
            return;   // paused/cancelled/finished between enqueue and execution
        }

        $cursor  = (int) $cursor;
        $attempt = (int) $attempt;
        if ($state['phase'] !== $phase || (int) $state['cursor'] !== $cursor) {
            return;   // stale callback from an older cursor
        }
        if ((int) $state['retry_attempt'] !== $attempt) {
            return;
        }
        if ($force_full !== null && (bool) $state['force_full'] !== (bool) $force_full) {
            return;
        }

        try {
            switch ($phase) {
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
        } catch (\Throwable $error) {
            $this->fail($state, $phase, $cursor, $error->getMessage());
        }
    }

    // ── Phases ──────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $state */
    private function phaseManifest(array $state): void
    {
        $client = new Client(null, Client::CONTEXT_BACKGROUND);
        $result = $client->fetch_manifest_result();

        if (! $result['ok']) {
            $this->retryOrFail($state, 'manifest', 0, $result);
            return;
        }

        $publishes = $this->publishedPosts();
        $current   = [];
        foreach ($publishes as $row) {
            $current[(int) $row['ID']] = (string) $row['post_modified_gmt'];
        }

        $forceFull = (bool) $state['force_full'];
        $plan      = IndexHelpers::compute_sync_plan($result['map'], $current, $forceFull);
        \update_option(self::PLAN, $plan, false);

        $capture  = $this->capture();
        $snapshot = $forceFull;
        $revision = null;
        if ($snapshot) {
            $ids = \array_keys($current);
            \sort($ids);
            $marker = $capture->allocateRunMarker(
                (string) $state['client_run_key'],
                'snapshot',
                \count($current),
                \hash('sha256', $ids === [] ? '' : \implode("\n", $ids) . "\n")
            );
            $revision = $this->markerRevision($marker);
        } else {
            $marker   = $capture->allocateRunMarker((string) $state['client_run_key'], 'incremental');
            $revision = $this->markerRevision($marker);
        }

        $this->advance($state, 'create', 0, [
            'mode'            => $snapshot ? 'snapshot' : 'incremental',
            'source_epoch'    => $capture->repository()->sourceEpoch(),
            'source_revision' => $revision,
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
            $payload['expected_membership_count']  = (int) $state['upsert_total'];
            $payload['expected_membership_digest'] = $this->membershipDigest();
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
        $capture = $this->capture();

        if ($slice === []) {
            $this->advance($state, 'deletes', 0, ['cursor' => 0]);
            return;
        }

        $documents = [];
        $demoted   = [];
        foreach ($slice as $postId) {
            $built = $capture->build((int) $postId);
            if ($built['deleted']) {
                $demoted[] = (int) $postId;   // vanished between plan and capture
                continue;
            }
            $documents[] = \array_merge($built['payload'], [
                'source_epoch'    => (string) $state['source_epoch'],
                'source_revision' => $built['revision'],
                'operation'       => 'upsert',
            ]);
        }

        if ($demoted !== []) {
            $plan['delete'] = \array_values(\array_unique(\array_merge($plan['delete'], $demoted)));
            \update_option(self::PLAN, $plan, false);
        }

        if ($documents === []) {
            $this->advance(
                $state,
                $offset + \count($slice) >= \count($plan['upsert']) ? 'deletes' : 'upload',
                $offset + \count($slice) >= \count($plan['upsert']) ? 0 : $offset + \count($slice),
                ['cursor' => $offset + \count($slice) >= \count($plan['upsert']) ? 0 : $offset + \count($slice)]
            );
            return;
        }

        $result = $this->asyncClient()->appendDocuments(
            (string) $state['run_id'],
            $this->batchKey($state, 'u', $offset),
            $documents,
            \count($documents)
        );

        if (! $result['ok']) {
            $this->retryOrFail($state, 'upload', $offset, $result);
            return;
        }

        $nextOffset  = $offset + \count($slice);
        $nextDeleted = (int) $state['delete_total'];

        $this->advance(
            $state,
            $nextOffset >= \count($plan['upsert']) ? 'deletes' : 'upload',
            $nextOffset >= \count($plan['upsert']) ? 0 : $nextOffset,
            [
                'cursor'       => $nextOffset >= \count($plan['upsert']) ? 0 : $nextOffset,
                'uploaded'     => (int) $state['uploaded'] + (int) $result['accepted_count'],
                'delete_total' => $nextDeleted,
            ]
        );
    }

    /** @param array<string,mixed> $state */
    private function phaseDeletes(array $state): void
    {
        $plan   = $this->plan();
        $offset = (int) $state['cursor'];
        $slice  = \array_slice($plan['delete'], $offset, self::DELETE_BATCH);

        if ($slice === []) {
            $this->advance($state, 'seal', 0, ['cursor' => 0]);
            return;
        }

        $capture = $this->capture();
        $deletes = [];
        foreach ($slice as $postId) {
            $revision = $capture->repository()->allocateRevision();
            $deletes[] = [
                'post_id'         => (int) $postId,
                'source_epoch'    => (string) $state['source_epoch'],
                'source_revision' => $revision,
            ];
        }

        $result = $this->asyncClient()->appendDeletes(
            (string) $state['run_id'],
            $this->batchKey($state, 'd', $offset),
            $deletes,
            \count($deletes)
        );

        if (! $result['ok']) {
            $this->retryOrFail($state, 'deletes', $offset, $result);
            return;
        }

        $nextOffset = $offset + \count($slice);
        $done       = $nextOffset >= \count($plan['delete']);

        $this->advance($state, $done ? 'seal' : 'deletes', $done ? 0 : $nextOffset, [
            'cursor'  => $done ? 0 : $nextOffset,
            'deleted' => (int) $state['deleted'] + (int) $result['accepted_count'],
        ]);
    }

    /** @param array<string,mixed> $state */
    private function phaseSeal(array $state): void
    {
        $result = $this->asyncClient()->sealRun((string) $state['run_id'], (string) $state['client_run_key'] . ':seal');
        if (! $result['ok']) {
            $this->retryOrFail($state, 'seal', 0, $result);
            return;
        }

        $this->advance($state, 'poll', 0, [
            'sealed'        => true,
            'poll_attempt'  => 0,
            'next_poll_gmt' => '',
            'message'       => \__('Загружено. Идёт обработка на сервере.', 'cherrylink'),
        ]);
    }

    /** @param array<string,mixed> $state */
    private function phasePoll(array $state): void
    {
        $result = $this->asyncClient()->runStatus((string) $state['run_id']);
        if (! $result['ok']) {
            $this->retryOrFail($state, 'poll', 0, $result, true);
            return;
        }

        $progress = $this->progressFrom($result['body']);
        $runState = (string) ($result['body']['state'] ?? '');

        if (\in_array($runState, self::TERMINAL_RUN_STATES, true)) {
            $ok = $runState === 'completed';
            $state = $this->setState(['progress' => $progress]);
            $this->finish($state, $ok ? 'done' : 'completed_with_errors', $ok
                ? \__('Индексация завершена', 'cherrylink')
                : \__('Индексация завершена с ошибками', 'cherrylink'));
            return;
        }

        $attempt = (int) $state['poll_attempt'] + 1;
        $delay   = \min(self::POLL_MAX_SECONDS, self::POLL_MIN_SECONDS * (1 + \intdiv($attempt, 3)));

        $state = $this->setState([
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

    /** @param array<string,mixed> $state @param array<string,mixed> $extra */
    private function advance(array $state, string $phase, int $cursor, array $extra = []): void
    {
        $patch = \array_merge($extra, [
            'phase'            => $phase,
            'cursor'           => $cursor,
            'retry_attempt'    => 0,
            'next_retry_gmt'   => '',
            'last_http_status' => 0,
            'last_retry_after' => null,
            'last_error'       => '',
            'failed_phase'     => null,
            'failed_offset'    => null,
        ]);
        $this->setState($patch);

        if ($phase === 'poll') {
            $this->scheduleLater(self::POLL_MIN_SECONDS, 'poll', 0, 0);
            return;
        }

        $this->enqueue($phase, $cursor, 0);
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
        if ($status === 'done') {
            $this->options->clearIndexDirty();
        }

        $this->setState([
            'status'  => $status,
            'message' => $message,
        ]);
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
        ]);
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $result
     */
    private function retryOrFail(array $state, string $phase, int $cursor, array $result, bool $polling = false): void
    {
        $attempt   = (int) $state['retry_attempt'] + 1;
        $retryable = AsyncClient::isRetryable($result);
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
        unset($polling);
    }

    /** @return array{upsert:int[],delete:int[]} */
    private function plan(): array
    {
        $plan = (array) \get_option(self::PLAN, ['upsert' => [], 'delete' => []]);

        return [
            'upsert' => \array_map('intval', (array) ($plan['upsert'] ?? [])),
            'delete' => \array_map('intval', (array) ($plan['delete'] ?? [])),
        ];
    }

    /** @return array<int,array{ID:int|string,post_modified_gmt:string}> */
    private function publishedPosts(): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $allowed = IndexHelpers::get_allowed_types_list();
        $ph      = \implode(',', \array_fill(0, \count($allowed), '%s'));
        $rows    = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE post_type IN ($ph) AND post_status = 'publish'",
            $allowed
        ), ARRAY_A);

        return \is_array($rows) ? $rows : [];
    }

    private function membershipDigest(): string
    {
        $ids = [];
        foreach ($this->publishedPosts() as $row) {
            $ids[] = (int) $row['ID'];
        }
        $ids = \array_values(\array_unique($ids));
        \sort($ids);

        return \hash('sha256', $ids === [] ? '' : \implode("\n", $ids) . "\n");
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
            ], self::GROUP);
        } catch (\Throwable $error) {
            // WP-Cron / the admin tick will recover the chain.
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
}
