<?php

declare(strict_types=1);

namespace CherryLink\Indexing\Outbox;

use CherryLink\Api\Client;
use CherryLink\Support\Logger;
use WP_Post;

/**
 * Capture-order enforcement for live events (plan stage P1.5).
 *
 * The canonical ordering key is `(source_epoch, source_revision)`. The rule that
 * makes concurrent saves deterministic is: allocate the revision **immediately
 * before** reading the post, then persist an immutable payload. A save that
 * races the read therefore gets a later revision and wins; at worst both payloads
 * contain the same newest content, which is harmless.
 *
 * Everything here runs inside the WordPress request that triggered it and makes
 * no network call — the save/delete hook must never wait on the API.
 */
class Capture
{
    /** @var Repository */
    private $repository;

    /** @var Client */
    private $client;

    /** @var callable():string */
    private $keyFactory;

    /**
     * @param callable|null $keyFactory Builds a stable per-event idempotency key.
     */
    public function __construct(Repository $repository, Client $client, ?callable $keyFactory = null)
    {
        $this->repository = $repository;
        $this->client     = $client;
        $this->keyFactory = $keyFactory ?? static function (string $epoch, int $revision, int $postId, string $kind): string {
            return 'ev_' . \substr(\md5($epoch . ':' . $revision . ':' . $postId . ':' . $kind), 0, 32);
        };
    }

    public function repository(): Repository
    {
        return $this->repository;
    }

    /**
     * Capture a published post: allocate revision, read the post, persist payload.
     *
     * @return int Outbox row id.
     */
    public function upsert(WP_Post $post, ?string $runId = null): int
    {
        $revision = $this->repository->allocateRevision();
        $payload  = $this->client->capture_payload($post);

        return $this->repository->enqueue(
            $this->key((int) $post->ID, Repository::KIND_UPSERT, $revision),
            Repository::KIND_UPSERT,
            (int) $post->ID,
            $payload,
            $runId,
            $revision
        );
    }

    /**
     * Capture an unpublish/delete as a durable tombstone. The payload is minimal
     * and immutable, so it survives the WordPress post disappearing.
     *
     * @return int Outbox row id.
     */
    public function delete(int $postId, ?string $runId = null): int
    {
        $revision = $this->repository->allocateRevision();

        return $this->repository->enqueue(
            $this->key($postId, Repository::KIND_DELETE, $revision),
            Repository::KIND_DELETE,
            $postId,
            ['post_id' => $postId, 'operation' => 'delete'],
            $runId,
            $revision
        );
    }

    /**
     * Allocate the run capture marker. A run marker must be older than every item
     * captured for that run, so callers allocate it once before iterating posts.
     */
    public function allocateRunMarker(string $clientRunKey, string $mode, ?int $expectedCount = null, ?string $expectedDigest = null): int
    {
        $revision = $this->repository->allocateRevision();
        $payload  = [
            'client_run_key' => $clientRunKey,
            'mode'           => $mode,
        ];
        if ($expectedCount !== null) {
            $payload['expected_membership_count'] = $expectedCount;
        }
        if ($expectedDigest !== null) {
            $payload['expected_membership_digest'] = $expectedDigest;
        }
        $kind = $mode === 'snapshot' ? Repository::KIND_SNAPSHOT_MARKER : Repository::KIND_RUN_MARKER;

        return $this->repository->enqueue(
            $this->key(0, $kind, $revision),
            $kind,
            0,
            $payload,
            null,
            $revision
        );
    }

    /**
     * Capture a post, mapping any storage failure to a fail-closed outcome so the
     * caller can keep its dirty marker instead of silently dropping the change.
     *
     * @return int|null Null when the event could not be persisted.
     */
    public function tryUpsert(WP_Post $post, ?string $runId = null): ?int
    {
        try {
            return $this->upsert($post, $runId);
        } catch (\Throwable $error) {
            (new Logger())->log('CherryLink outbox capture failed for post ' . $post->ID . ': ' . $error->getMessage());
            return null;
        }
    }

    /**
     * @return int|null Null when the tombstone could not be persisted.
     */
    public function tryDelete(int $postId, ?string $runId = null): ?int
    {
        try {
            return $this->delete($postId, $runId);
        } catch (\Throwable $error) {
            (new Logger())->log('CherryLink outbox tombstone failed for post ' . $postId . ': ' . $error->getMessage());
            return null;
        }
    }

    private function key(int $postId, string $kind, int $revision): string
    {
        return (string) \call_user_func(
            $this->keyFactory,
            $this->repository->sourceEpoch(),
            $revision,
            $postId,
            $kind
        );
    }
}
