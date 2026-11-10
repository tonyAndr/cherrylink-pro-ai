<?php

declare(strict_types=1);

namespace CherryLink\Indexing\Outbox;

use CherryLink\Api\AsyncClient;
use CherryLink\Contracts\Registerable;
use CherryLink\Support\CapabilitiesRefresh;
use CherryLink\Support\Logger;

/**
 * Outbox delivery chain (plan stage P2.2).
 *
 * One Action Scheduler action per turn: reclaim expired leases, claim a bounded
 * batch, deliver each row with a *stable* idempotency key, and acknowledge or
 * release it. There is no sleeping in PHP and at most one retry owner: a failed
 * delivery is rescheduled with backoff (or `Retry-After`) and the backend owns
 * execution retries after a `202`.
 *
 * Each row is delivered in its own request keyed by its stable `event_key`. That
 * keeps a replay byte-identical — if a reclaimed row is retried, the same key and
 * same payload produce the same receipt instead of an `idempotency_key_reused`
 * conflict that batching arbitrary rows together could cause.
 */
class Delivery implements Registerable
{
    /** Action Scheduler hook. */
    public const HOOK = 'cherrylink_outbox_delivery';

    /** Action Scheduler group. */
    public const GROUP = 'cherrylink_outbox';

    /** WP-Cron hook for the independent lease-recovery sweep. */
    public const SWEEP_HOOK = 'cherrylink_outbox_sweep';

    /** Rows delivered per background turn. */
    public const BATCH = 10;

    /** Lease length; reclaimed if a turn dies mid-batch. */
    public const LEASE_SECONDS = 120;

    /** @var Repository */
    private $repository;

    /** @var AsyncClient */
    private $client;

    /** @var callable():int */
    private $clock;

    public function __construct(?Repository $repository = null, ?AsyncClient $client = null, ?callable $clock = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->client     = $client ?? new AsyncClient();
        $this->clock      = $clock ?? static function (): int {
            return \time();
        };
    }

    public function register(): void
    {
        \add_action(self::HOOK, [$this, 'runTurn'], 10, 0);
        \add_action(self::SWEEP_HOOK, [$this, 'sweepTurn'], 10, 0);
        \add_action('wp_loaded', [$this, 'maybeScheduleSweep']);
    }

    /**
     * Schedule the independent recovery sweep. Without it a worker crash (or a
     * silently ignored scheduling failure) would strand rows until the next post
     * change happened to start a turn.
     */
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

    /**
     * Recovery sweep: reclaim expired leases (dead-lettering exhausted rows) and
     * restart delivery when work is waiting. Runs from WP-Cron independently of
     * any post save.
     *
     * @return array{reclaimed:int,pending:int}
     */
    public function sweep(): array
    {
        $reclaimed = $this->repository->reclaimExpired();
        $pending   = $this->repository->pendingCount();
        if ($reclaimed > 0 || $pending > 0 || $this->repository->leasedCount() > 0) {
            $this->schedule();
        }

        return ['reclaimed' => $reclaimed, 'pending' => $pending];
    }

    /** Action Scheduler callback: run one turn and discard the summary. */
    public function runTurn(): void
    {
        $this->run();
    }

    /** WP-Cron callback: run one recovery sweep and discard the summary. */
    public function sweepTurn(): void
    {
        $this->sweep();
    }

    /**
     * Ensure a delivery turn is queued. Idempotent within a request and cheap:
     * Action Scheduler de-duplicates nothing, so guard with `as_has_scheduled_action`
     * when available to avoid runaway chains.
     *
     * @return bool True when a turn is queued or already pending.
     */
    public function schedule(): bool
    {
        if (! \function_exists('as_enqueue_async_action')) {
            return false;
        }
        if (\function_exists('as_has_scheduled_action') && \as_has_scheduled_action(self::HOOK, null, self::GROUP)) {
            return true;
        }

        try {
            $id = \as_enqueue_async_action(self::HOOK, [], self::GROUP);
        } catch (\Throwable $error) {
            return false;
        }

        return (bool) $id;
    }

    /**
     * One delivery turn. Safe to run concurrently: claims are lease-fenced.
     *
     * @return array{claimed:int,delivered:int,retried:int,dead:int}
     */
    public function run(): array
    {
        $this->repository->reclaimExpired();

        $rows      = $this->repository->claim(self::BATCH, self::LEASE_SECONDS);
        $delivered = 0;
        $retried   = 0;
        $dead      = 0;

        foreach ($rows as $row) {
            $owner = (string) ($row['lease_owner'] ?? '');
            $id    = (int) ($row['id'] ?? 0);
            if ($owner === '' || $id <= 0) {
                continue;
            }

            $result = $this->deliver($row);

            if ($result['ok']) {
                $this->repository->ack($id, $owner, [
                    'status'         => $result['status'],
                    'retry_after'    => $result['retry_after'],
                    'remote_item_id' => $result['remote_item_id'] ?? null,
                ]);
                $delivered++;
                continue;
            }

            $retryable = AsyncClient::isRetryable($result);
            // The repository requires the explicit flag; omitting it made every
            // transient failure look permanent.
            $result['retryable'] = $retryable;
            if (! $retryable) {
                $this->repository->deadLetter($id, $owner, $result);
                $dead++;
                continue;
            }

            $attempt = (int) ($row['attempts'] ?? 0);
            $delay   = Repository::retryDelay($attempt, $result['retry_after'] ?? null);
            $this->repository->release($id, $owner, $result, $this->now() + $delay);
            $retried++;
        }

        // Keep the chain alive while retryable work or fresh pending rows remain.
        // Scheduling is bounded: one follow-up turn, never a tight loop.
        if ($retried > 0 || $this->repository->pendingCount() > 0) {
            $this->scheduleLater(Repository::retryDelay(1, null, 0));
        }

        return ['claimed' => \count($rows), 'delivered' => $delivered, 'retried' => $retried, 'dead' => $dead];
    }

    /**
     * Deliver a single outbox row. Returns a normalized result with `ok`.
     *
     * @param array<string,mixed> $row
     * @return array{ok:bool,status:int,retry_after:int|null,error:string,remote_item_id?:string|null}
     */
    public function deliver(array $row): array
    {
        $kind = (string) ($row['kind'] ?? '');
        $payload = \json_decode((string) ($row['payload'] ?? '{}'), true);
        if (! \is_array($payload)) {
            return ['ok' => false, 'status' => 422, 'retry_after' => null, 'error' => 'corrupt outbox payload'];
        }

        switch ($kind) {
            case Repository::KIND_UPSERT:
            case Repository::KIND_DELETE:
                return $this->deliverMutation($row, $payload);
            case Repository::KIND_RUN_MARKER:
            case Repository::KIND_SNAPSHOT_MARKER:
                return $this->deliverRunMarker($row, $payload, $kind === Repository::KIND_SNAPSHOT_MARKER);
            default:
                return ['ok' => false, 'status' => 422, 'retry_after' => null, 'error' => 'unknown outbox kind'];
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $payload
     * @return array{ok:bool,status:int,retry_after:int|null,error:string,remote_item_id?:string|null}
     */
    private function deliverMutation(array $row, array $payload): array
    {
        $document = [
            'post_id'         => (int) ($row['post_id'] ?? 0),
            'source_epoch'    => (string) ($row['source_epoch'] ?? ''),
            'source_revision' => (int) ($row['source_revision'] ?? 0),
            'operation'       => ($row['kind'] ?? '') === Repository::KIND_DELETE ? 'delete' : 'upsert',
        ];

        if ($document['operation'] === 'upsert') {
            foreach (['title', 'text', 'url', 'lang', 'content_hash', 'modified_gmt', 'link_html'] as $field) {
                if (isset($payload[$field])) {
                    $document[$field] = $payload[$field];
                }
            }
        }

        $result = $this->client->mutations(
            (string) ($row['event_key'] ?? ''),
            [$document],
            $row['run_id'] !== null ? (string) $row['run_id'] : null
        );

        $accepted = $result['accepted_items'] ?? [];

        return [
            'ok'             => (bool) $result['ok'],
            'status'         => (int) $result['status'],
            'retry_after'    => $result['retry_after'],
            'error'          => $this->errorMessage($result),
            'remote_item_id' => \is_array($accepted) && $accepted !== [] ? (string) $accepted[0] : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $payload
     * @return array{ok:bool,status:int,retry_after:int|null,error:string,remote_item_id?:string|null}
     */
    private function deliverRunMarker(array $row, array $payload, bool $snapshot): array
    {
        $body = [
            'client_run_key'  => (string) ($payload['client_run_key'] ?? ''),
            'mode'            => $snapshot ? 'snapshot' : 'incremental',
            'source_epoch'    => (string) ($row['source_epoch'] ?? ''),
            'source_revision' => (int) ($row['source_revision'] ?? 0),
        ];
        if ($snapshot) {
            $body['expected_membership_count']  = (int) ($payload['expected_membership_count'] ?? 0);
            $body['expected_membership_digest'] = (string) ($payload['expected_membership_digest'] ?? '');
        }

        $result = $this->client->createRun($body);

        return [
            'ok'             => (bool) $result['ok'],
            'status'         => (int) $result['status'],
            'retry_after'    => $result['retry_after'],
            'error'          => $this->errorMessage($result),
            'remote_item_id' => $result['run_id'] ?? null,
        ];
    }

    /** @param array<string,mixed> $result */
    private function errorMessage(array $result): string
    {
        if (! empty($result['error'])) {
            return (string) $result['error'];
        }
        $code = (string) ($result['code'] ?? '');

        return $code !== '' ? $code : 'async delivery failed';
    }

    private function scheduleLater(int $delay): void
    {
        if (! \function_exists('as_schedule_single_action')) {
            return;
        }
        try {
            \as_schedule_single_action($this->now() + \max(1, $delay), self::HOOK, [], self::GROUP);
        } catch (\Throwable $error) {
            (new Logger())->log('CherryLink outbox follow-up schedule failed: ' . $error->getMessage());
        }
    }

    private function now(): int
    {
        return (int) \call_user_func($this->clock);
    }
}
