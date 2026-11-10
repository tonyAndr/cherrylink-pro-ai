<?php

declare(strict_types=1);

namespace CherryLink\Indexing\Outbox;

/**
 * Durable, tenant-scoped outbox store for async indexing events.
 *
 * Rows are immutable once written: `enqueue()` is the only method that touches
 * `event_key`, `source_epoch`, `source_revision`, `post_id`, `kind`, and
 * `payload`. Delivery state transitions (claim/ack/release/dead-letter/reclaim)
 * only update metadata columns and are fenced by `lease_owner`, so a stale
 * worker can never acknowledge or release a row it no longer owns.
 *
 * Ordering uses the canonical `(source_epoch, source_revision)` pair from the
 * workspace plan. The epoch is a singleton UUID; revisions come from one atomic
 * monotonic MySQL counter (`LAST_INSERT_ID(last_revision + 1)`), which stays
 * safe under concurrent save/delete/bulk allocation. Replaying the same event
 * key returns the existing row and never allocates a new revision.
 *
 * The repository owns mechanics only. Retry *policy* (backoff/jitter, Action
 * Scheduler scheduling) belongs to the delivery service; `release()` accepts the
 * caller-computed next-attempt timestamp.
 */
class Repository
{
    public const STATE_PENDING   = 'pending';
    public const STATE_LEASED    = 'leased';
    public const STATE_DELIVERED = 'delivered';
    public const STATE_DEAD      = 'dead';

    public const KIND_UPSERT           = 'upsert';
    public const KIND_DELETE           = 'delete';
    public const KIND_RUN_MARKER       = 'run_marker';
    public const KIND_SNAPSHOT_MARKER  = 'snapshot_marker';

    public const MAX_ATTEMPTS          = 8;
    public const DEFAULT_LEASE_SECONDS = 60;
    public const MAX_RETRY_DELAY       = 300;
    public const BASE_RETRY_DELAY      = 2;

    /** @var string[] */
    private const ALLOWED_KINDS = [
        self::KIND_UPSERT,
        self::KIND_DELETE,
        self::KIND_RUN_MARKER,
        self::KIND_SNAPSHOT_MARKER,
    ];

    /** @var \wpdb */
    private $wpdb;

    /** @var callable():int */
    private $clock;

    /** @var callable():string */
    private $ownerFactory;

    /**
     * @param \wpdb|null          $wpdb         Defaults to the global $wpdb; injectable for tests.
     * @param callable|null       $clock        Returns the current unix timestamp (seconds).
     * @param callable|null       $ownerFactory Returns a unique lease-owner / UUID string.
     */
    public function __construct($wpdb = null, ?callable $clock = null, ?callable $ownerFactory = null)
    {
        $this->wpdb         = $wpdb ?? self::globalWpdb();
        $this->clock        = $clock ?? static function (): int {
            return \time();
        };
        $this->ownerFactory = $ownerFactory ?? static function (): string {
            return \wp_generate_uuid4();
        };
    }

    public function tableName(): string
    {
        return $this->wpdb->prefix . Schema::OUTBOX_TABLE;
    }

    public function stateTableName(): string
    {
        return $this->wpdb->prefix . Schema::STATE_TABLE;
    }

    // ── Source ordering ─────────────────────────────────────────────────────

    /**
     * Current source epoch UUID, creating the singleton state row on first use.
     */
    public function sourceEpoch(): string
    {
        $this->ensureStateRow();
        $row = $this->wpdb->get_row("SELECT source_epoch FROM {$this->stateTableName()} WHERE id = 1", ARRAY_A);

        return \is_array($row) ? (string) ($row['source_epoch'] ?? '') : '';
    }

    /**
     * Allocate the next revision from the atomic monotonic source sequence.
     * Retried events must NOT call this again — they reuse their stored revision.
     *
     * @throws OutboxException When the counter update fails (fail closed: a caller
     *                         must not capture content without a durable revision).
     */
    public function allocateRevision(): int
    {
        $this->ensureStateRow();
        $updated = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->stateTableName()} SET last_revision = LAST_INSERT_ID(last_revision + 1), updated_gmt = %s WHERE id = 1",
            $this->gmtNow()
        ));
        if ($updated === false || (int) $updated < 1) {
            throw new OutboxException('Outbox source sequence is unavailable');
        }

        $revision = (int) $this->wpdb->get_var('SELECT LAST_INSERT_ID()');
        if ($revision < 1) {
            throw new OutboxException('Outbox source sequence returned no revision');
        }

        return $revision;
    }

    /**
     * Start a fresh ordering epoch (rebase primitive). Returns the new epoch UUID.
     * The drain/pause orchestration around a rebase is P1.5 — this only rotates the
     * singleton epoch and resets the revision counter.
     */
    public function resetEpoch(): string
    {
        $this->ensureStateRow();
        $epoch = $this->uuid();
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->stateTableName()} SET source_epoch = %s, last_revision = 0, updated_gmt = %s WHERE id = 1",
            $epoch,
            $this->gmtNow()
        ));

        return $epoch;
    }

    // ── Enqueue ─────────────────────────────────────────────────────────────

    /**
     * Persist an immutable event payload and allocate its canonical source revision.
     * Idempotent per event key: a replayed key returns the existing row id.
     *
     * `$revision` lets a caller that already allocated a revision *before* reading
     * the post (the capture-order rule) persist that exact revision. Passing null
     * allocates one here, immediately before insert.
     *
     * @return int The outbox row id.
     *
     * @throws \InvalidArgumentException On an empty/over-long key or unknown kind.
     * @throws OutboxException           When the row cannot be inserted.
     */
    public function enqueue(
        string $eventKey,
        string $kind,
        int $postId,
        array $payload,
        ?string $runId = null,
        ?int $revision = null
    ): int {
        $this->assertEventKey($eventKey);
        $this->assertKind($kind);

        $existing = $this->findByEventKey($eventKey);
        if ($existing !== null) {
            return (int) $existing['id'];
        }

        $epoch    = $this->sourceEpoch();
        if ($epoch === '') {
            throw new OutboxException('Outbox source epoch is unavailable');
        }
        $revision = $revision ?? $this->allocateRevision();
        $now      = $this->gmtNow();
        $json     = \wp_json_encode($payload);
        if ($json === false) {
            throw new OutboxException('Outbox payload is not JSON-encodable for event key ' . \esc_html($eventKey));
        }

        $data = [
            'event_key'       => $eventKey,
            'source_epoch'    => $epoch,
            'source_revision' => $revision,
            'post_id'         => $postId,
            'kind'            => $kind,
            'payload'         => $json,
            'state'           => self::STATE_PENDING,
            'created_gmt'     => $now,
            'updated_gmt'     => $now,
        ];
        $formats = ['%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s'];
        if ($runId !== null) {
            $data['run_id'] = $runId;
            $formats[]      = '%s';
        }

        $inserted = $this->wpdb->insert($this->tableName(), $data, $formats);
        if ($inserted === false) {
            // A concurrent enqueue of the same key can lose the unique-index race.
            $existing = $this->findByEventKey($eventKey);
            if ($existing !== null) {
                return (int) $existing['id'];
            }
            throw new OutboxException('Outbox insert failed for event key ' . \esc_html($eventKey));
        }

        return (int) $this->wpdb->insert_id;
    }

    // ── Delivery mechanics ──────────────────────────────────────────────────

    /**
     * Lease up to $limit due pending rows to a fresh owner and count one attempt.
     *
     * @return array<int,array<string,mixed>>
     */
    public function claim(int $limit = 10, ?int $leaseSeconds = null): array
    {
        $limit = \max(1, $limit);
        $lease = \max(1, $leaseSeconds ?? self::DEFAULT_LEASE_SECONDS);
        $owner = $this->uuid();
        $table = $this->tableName();
        $now   = $this->gmtNow();
        $expiry = $this->gmt($this->now() + $lease);

        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$table} SET state = %s, lease_owner = %s, lease_expiry_gmt = %s, attempts = attempts + 1, updated_gmt = %s
             WHERE state = 'pending' AND (next_attempt_gmt IS NULL OR next_attempt_gmt <= %s)
             ORDER BY id
             LIMIT %d",
            self::STATE_LEASED,
            $owner,
            $expiry,
            $expiry,
            $now,
            $limit
        ));

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE lease_owner = %s AND state = %s ORDER BY id LIMIT %d",
            $owner,
            self::STATE_LEASED,
            $limit
        ), ARRAY_A);

        if (! \is_array($rows)) {
            return [];
        }

        return \array_map([self::class, 'normalize'], $rows);
    }

    /** @return array<string,mixed>|null */
    public function get(int $id): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->tableName()} WHERE id = %d", $id),
            ARRAY_A
        );

        return \is_array($row) ? self::normalize($row) : null;
    }

    /**
     * Mark a leased row durably delivered. Fenced on lease owner: a stale owner
     * (or an already-released row) is refused and the update never runs.
     *
     * @param array<string,mixed> $result {status:int, retry_after:int|null, remote_item_id:string|null}
     */
    public function ack(int $id, string $owner, array $result): bool
    {
        $row = $this->get($id);
        if ($row === null || ($row['lease_owner'] ?? null) !== $owner) {
            return false;
        }

        $data = [
            'state'            => self::STATE_DELIVERED,
            'lease_owner'      => null,
            'lease_expiry_gmt' => null,
            'remote_item_id'   => $result['remote_item_id'] ?? null,
            'last_http_status' => (int) ($result['status'] ?? 0),
            'last_retry_after' => $result['retry_after'] ?? null,
            'last_error'       => '',
            'updated_gmt'      => $this->gmtNow(),
        ];

        return $this->update($id, $owner, $data, ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
    }

    /**
     * Release a leased row after a failed delivery attempt.
     *
     * Dead-letters when the result is non-retryable, the attempt budget is
     * exhausted, or no next-attempt time is given; otherwise returns the row to
     * pending with the caller-computed `nextAttemptAt`.
     *
     * @param array<string,mixed> $result       {retryable:bool, status:int, retry_after:int|null, error:string}
     * @param int|null            $nextAttemptAt Unix timestamp for the next attempt.
     */
    public function release(int $id, string $owner, array $result, ?int $nextAttemptAt = null): bool
    {
        $row = $this->get($id);
        if ($row === null || ($row['lease_owner'] ?? null) !== $owner) {
            return false;
        }

        $retryable = \array_key_exists('retryable', $result)
            ? (bool) $result['retryable']
            // Defensive: a caller that omits the flag must not turn a transient
            // failure into a permanent dead letter.
            : self::isTransientStatus((int) ($result['status'] ?? 0));
        if (! $retryable || (int) $row['attempts'] >= self::MAX_ATTEMPTS || $nextAttemptAt === null) {
            return $this->deadLetterImpl($id, $owner, $result);
        }

        $data = [
            'state'            => self::STATE_PENDING,
            'lease_owner'      => null,
            'lease_expiry_gmt' => null,
            'next_attempt_gmt' => $this->gmt($nextAttemptAt),
            'last_http_status' => (int) ($result['status'] ?? 0),
            'last_retry_after' => $result['retry_after'] ?? null,
            'last_error'       => (string) ($result['error'] ?? ''),
            'updated_gmt'      => $this->gmtNow(),
        ];

        return $this->update($id, $owner, $data, ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
    }

    /**
     * Return a row to pending without consuming its attempt budget.
     *
     * Used when the failure is a *tenant state* problem that an operator action
     * will fix (unbound source epoch, async disabled, enrollment pending): the
     * event stays durable instead of being dead-lettered while the tenant is not
     * yet able to accept it.
     *
     * @param array<string,mixed> $result
     */
    public function defer(int $id, string $owner, array $result, int $nextAttemptAt): bool
    {
        $row = $this->get($id);
        if ($row === null || ($row['lease_owner'] ?? null) !== $owner) {
            return false;
        }

        $data = [
            'state'            => self::STATE_PENDING,
            'lease_owner'      => null,
            'lease_expiry_gmt' => null,
            'next_attempt_gmt' => $this->gmt($nextAttemptAt),
            'attempts'         => 0,
            'last_http_status' => (int) ($result['status'] ?? 0),
            'last_retry_after' => $result['retry_after'] ?? null,
            'last_error'       => (string) ($result['error'] ?? ''),
            'updated_gmt'      => $this->gmtNow(),
        ];

        return $this->update($id, $owner, $data, ['%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s']);
    }

    /**
     * Permanently fail a row. Fenced on lease owner. Non-retryable results and
     * exhausted budgets route here from `release()`.
     *
     * @param array<string,mixed> $result {status:int, retry_after:int|null, error:string}
     */
    public function deadLetter(int $id, string $owner, array $result): bool
    {
        $row = $this->get($id);
        if ($row === null || ($row['lease_owner'] ?? null) !== $owner) {
            return false;
        }

        return $this->deadLetterImpl($id, $owner, $result);
    }

    /**
     * Return expired leases to pending so another delivery attempt can claim them.
     * A row that already exhausted its attempt budget is dead-lettered instead of
     * being retried forever, so repeated crash/reclaim cycles cannot bypass the
     * attempt limit.
     *
     * @return int Number of rows reclaimed or newly dead-lettered.
     */
    public function reclaimExpired(): int
    {
        $now   = $this->gmtNow();
        $table = $this->tableName();
        $max   = self::MAX_ATTEMPTS;

        $exhausted = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$table} SET state = %s, lease_owner = NULL, lease_expiry_gmt = NULL, next_attempt_gmt = NULL, last_error = %s, updated_gmt = %s
             WHERE state = %s AND lease_expiry_gmt IS NOT NULL AND lease_expiry_gmt <= %s AND attempts >= %d",
            self::STATE_DEAD,
            'lease expired after the attempt budget was exhausted',
            $now,
            self::STATE_LEASED,
            $now,
            $max
        ));

        $reclaimed = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$table} SET state = %s, lease_owner = NULL, lease_expiry_gmt = NULL, updated_gmt = %s
             WHERE state = %s AND lease_expiry_gmt IS NOT NULL AND lease_expiry_gmt <= %s AND attempts < %d",
            self::STATE_PENDING,
            $now,
            self::STATE_LEASED,
            $now,
            $max
        ));

        $dead = \is_numeric($exhausted) ? (int) $exhausted : 0;
        $back = \is_numeric($reclaimed) ? (int) $reclaimed : 0;

        return $dead + $back;
    }

    public function pendingCount(): int
    {
        return $this->stateCount(self::STATE_PENDING);
    }

    public function leasedCount(): int
    {
        return $this->stateCount(self::STATE_LEASED);
    }

    public function deadCount(): int
    {
        return $this->stateCount(self::STATE_DEAD);
    }

    /**
     * Bounded retry delay for the outbox delivery chain: honor a valid Retry-After,
     * otherwise exponential backoff with jitter capped at MAX_RETRY_DELAY.
     */
    public static function retryDelay(int $attempt, ?int $retryAfter = null, ?int $jitter = null): int
    {
        if ($retryAfter !== null) {
            return \max(0, \min(self::MAX_RETRY_DELAY, $retryAfter));
        }

        $exponent = \max(0, $attempt - 1);
        $base     = \min(self::MAX_RETRY_DELAY, self::BASE_RETRY_DELAY * (2 ** $exponent));
        $jitter   = $jitter ?? \mt_rand(0, \min(5, self::MAX_RETRY_DELAY - $base));

        return \min(self::MAX_RETRY_DELAY, $base + \max(0, $jitter));
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * @param string[] $formats
     */
    private function update(int $id, string $owner, array $data, array $formats): bool
    {
        $where  = ['id' => $id, 'lease_owner' => $owner];
        $result = $this->wpdb->update($this->tableName(), $data, $where, $formats, ['%d', '%s']);

        // Every fenced transition changes `lease_owner` away from the non-null
        // owner, so zero affected rows means the fence did not match: report the
        // failure instead of silently pretending the transition committed.
        return \is_int($result) && $result > 0;
    }

    /** Status codes that are worth retrying (transport, 408, 429, 5xx). */
    private static function isTransientStatus(int $status): bool
    {
        if ($status === 0) {
            return true;
        }

        return $status === 408 || $status === 429 || $status >= 500;
    }

    /**
     * Persist a dead-letter transition without re-checking the fence (the caller
     * already verified ownership).
     *
     * @param array<string,mixed> $result
     */
    private function deadLetterImpl(int $id, string $owner, array $result): bool
    {
        $data = [
            'state'            => self::STATE_DEAD,
            'lease_owner'      => null,
            'lease_expiry_gmt' => null,
            'next_attempt_gmt' => null,
            'last_http_status' => (int) ($result['status'] ?? 0),
            'last_retry_after' => $result['retry_after'] ?? null,
            'last_error'       => (string) ($result['error'] ?? ''),
            'updated_gmt'      => $this->gmtNow(),
        ];

        return $this->update($id, $owner, $data, ['%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);
    }

    private function ensureStateRow(): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->stateTableName()} (id, source_epoch, last_revision, updated_gmt) VALUES (1, %s, 0, %s)",
            $this->uuid(),
            $this->gmtNow()
        ));
    }

    /** @return array<string,mixed>|null */
    private function findByEventKey(string $eventKey): ?array
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->tableName()} WHERE event_key = %s", $eventKey),
            ARRAY_A
        );

        return \is_array($row) ? self::normalize($row) : null;
    }

    private function stateCount(string $state): int
    {
        $count = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->tableName()} WHERE state = %s",
            $state
        ));

        return (int) $count;
    }

    /** @param array<string,mixed> $row */
    private static function normalize(array $row): array
    {
        $row['id']              = (int) ($row['id'] ?? 0);
        $row['post_id']         = (int) ($row['post_id'] ?? 0);
        $row['source_revision'] = (int) ($row['source_revision'] ?? 0);
        $row['attempts']         = (int) ($row['attempts'] ?? 0);
        $row['last_http_status'] = (int) ($row['last_http_status'] ?? 0);

        return $row;
    }

    private function now(): int
    {
        return (int) \call_user_func($this->clock);
    }

    private function gmtNow(): string
    {
        return $this->gmt($this->now());
    }

    private function gmt(int $timestamp): string
    {
        return \gmdate('Y-m-d H:i:s', $timestamp);
    }

    private function uuid(): string
    {
        return (string) \call_user_func($this->ownerFactory);
    }

    /** @return \wpdb */
    private static function globalWpdb()
    {
        global $wpdb;

        return $wpdb;
    }

    private function assertEventKey(string $eventKey): void
    {
        if ($eventKey === '' || \strlen($eventKey) > 64) {
            throw new \InvalidArgumentException('Outbox event key must be 1-64 characters');
        }
    }

    private function assertKind(string $kind): void
    {
        if (! \in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new \InvalidArgumentException('Unknown outbox event kind: ' . \esc_html($kind));
        }
    }
}
