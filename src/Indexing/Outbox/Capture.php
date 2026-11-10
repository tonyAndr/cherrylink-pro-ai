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

    /** @var callable(int):array{status:string,modified:string}|null Authoritative DB reader. */
    private $rowReader;

    /**
     * @param callable|null $keyFactory Builds a stable per-event idempotency key.
     * @param callable|null $rowReader  Reads the authoritative post row; injectable
     *                                  for tests and defaults to a direct $wpdb read.
     */
    public function __construct(
        Repository $repository,
        Client $client,
        ?callable $keyFactory = null,
        ?callable $rowReader = null
    ) {
        $this->repository = $repository;
        $this->client     = $client;
        $this->keyFactory = $keyFactory ?? static function (string $epoch, int $revision, int $postId, string $kind): string {
            return 'ev_' . \substr(\md5($epoch . ':' . $revision . ':' . $postId . ':' . $kind), 0, 32);
        };
        $this->rowReader  = $rowReader;
    }

    public function repository(): Repository
    {
        return $this->repository;
    }

    /**
     * Capture the current state of a post: allocate the revision, **re-read the
     * post from the database**, then persist either an upsert or a tombstone.
     *
     * The `WP_Post` handed to `wp_insert_post` is already in memory and can belong
     * to an older request that resumed after a newer save. Allocating the revision
     * and then embedding that stale object would let the older request win with the
     * higher revision. Re-reading after the allocation makes the revision reflect
     * the moment the content was actually loaded.
     *
     * The operation is chosen from the **fresh** post, not from the caller's
     * object, so a stale "unpublish" cannot tombstone content a newer save has
     * already republished.
     *
     * @return int Outbox row id.
     */
    public function capture(int $postId, ?string $runId = null): int
    {
        $revision = $this->repository->allocateRevision();
        $row      = $this->authoritativeRow($postId);
        $fresh    = $this->reload($postId);

        // The authoritative row decides upsert vs tombstone. If it is gone or no
        // longer published, the event is a tombstone regardless of the cached object.
        if ($row === null || $row['status'] !== 'publish') {
            return $this->enqueueDelete($postId, $revision, $runId);
        }

        // The payload must describe the same revision of the post we captured. When
        // both status and modified time match, the object is current. When they do
        // not (for example when cache invalidation is suspended, so clean_post_cache()
        // and get_post() are served from a stale cache), refuse to enqueue stale
        // content: the caller marks the index dirty and a later scan reconciles.
        if (
            $fresh === null
            || $fresh->post_status !== $row['status']
            || (string) $fresh->post_modified_gmt !== $row['modified']
        ) {
            throw new StaleCaptureException(
                'Post ' . (int) $postId . ' changed during capture; refusing to enqueue stale content'
            );
        }

        $payload = $this->client->capture_payload($fresh);

        return $this->repository->enqueue(
            $this->key((int) $fresh->ID, Repository::KIND_UPSERT, $revision),
            Repository::KIND_UPSERT,
            (int) $fresh->ID,
            $payload,
            $runId,
            $revision
        );
    }

    /**
     * Convenience wrapper for the save hook. The passed object is used only for its
     * id; content always comes from a fresh read inside `capture()`.
     *
     * @return int Outbox row id.
     */
    public function upsert(WP_Post $post, ?string $runId = null): int
    {
        return $this->capture((int) $post->ID, $runId);
    }

    /**
     * Capture an unpublish/delete as a durable tombstone. The payload is minimal
     * and immutable, so it survives the WordPress post disappearing.
     *
     * @return int Outbox row id.
     */
    public function delete(int $postId, ?string $runId = null): int
    {
        return $this->enqueueDelete($postId, $this->repository->allocateRevision(), $runId);
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
     * Fail-closed capture for the save hook.
     *
     * @return int|null Null when the event could not be persisted.
     */
    public function tryCapture(int $postId, ?string $runId = null): ?int
    {
        try {
            return $this->capture($postId, $runId);
        } catch (\Throwable $error) {
            (new Logger())->log('CherryLink outbox capture failed for post ' . $postId . ': ' . $error->getMessage());
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

    /** @return int Outbox row id. */
    private function enqueueDelete(int $postId, int $revision, ?string $runId): int
    {
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
     * Authoritative post row, read straight from the database.
     *
     * The object cache can be unreliable here: `clean_post_cache()` is a no-op
     * while WordPress cache invalidation is suspended, so `get_post()` is not
     * guaranteed to see the newest row. This read does not depend on the cache and
     * is what decides upsert vs tombstone and whether the captured object is current.
     *
     * @return array{status:string,modified:string}|null
     */
    private function authoritativeRow(int $postId): ?array
    {
        if ($this->rowReader !== null) {
            return $this->readRow($postId);
        }

        global $wpdb;
        if (! \is_object($wpdb) || ! \is_callable([$wpdb, 'get_row'])) {
            return null;
        }
        /** @var \wpdb $db */
        $db  = $wpdb;
        $row = $db->get_row(
            $db->prepare("SELECT post_status, post_modified_gmt FROM {$db->posts} WHERE ID = %d", $postId),
            ARRAY_A
        );

        if (! \is_array($row)) {
            return null;
        }

        return [
            'status'   => (string) ($row['post_status'] ?? ''),
            'modified' => (string) ($row['post_modified_gmt'] ?? ''),
        ];
    }

    /** @return array{status:string,modified:string}|null */
    private function readRow(int $postId): ?array
    {
        $row = \call_user_func($this->rowReader, $postId);
        if (! \is_array($row)) {
            return null;
        }

        return [
            'status'   => (string) ($row['status'] ?? ''),
            'modified' => (string) ($row['modified'] ?? ''),
        ];
    }

    /**
     * Re-read the post after the revision has been allocated.
     *
     * `clean_post_cache()` helps WordPress drop the just-saved object; the
     * authoritative row above is what guarantees ordering when it cannot.
     */
    private function reload(int $postId): ?WP_Post
    {
        if (\function_exists('clean_post_cache')) {
            \clean_post_cache($postId);
        }
        if (! \function_exists('get_post')) {
            return null;
        }

        $fresh = \get_post($postId);

        return $fresh instanceof WP_Post ? $fresh : null;
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
