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
 * before** reading the post, then persist an immutable payload.
 *
 * The read itself is authoritative: post status, title, content and modified time
 * come straight from `$wpdb`, and configured custom fields come straight from
 * `postmeta`. The object cache cannot be trusted here — `clean_post_cache()` is a
 * no-op while WordPress cache invalidation is suspended, and two saves inside the
 * same second share `post_modified_gmt` while carrying different text.
 *
 * A failed read is NOT treated as a deletion: it raises {@see CaptureReadException}
 * so the caller can keep its dirty marker instead of tombstoning a live article.
 * Only an absent row (or a non-published status) produces a tombstone.
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

    /** @var callable(int):(array<string,mixed>|null) Authoritative post row reader. */
    private $rowReader;

    /** @var callable(int,string[]):array<string,mixed> Authoritative meta reader. */
    private $metaReader;

    /** @var callable():string[] Configured custom-field names. */
    private $fieldReader;

    /**
     * @param callable|null $keyFactory  Builds a stable per-event idempotency key.
     * @param callable|null $rowReader   Returns the authoritative post row, or null
     *                                   when the row does not exist. Must throw
     *                                   {@see CaptureReadException} on a read error.
     * @param callable|null $metaReader  Returns configured meta values for a post.
     * @param callable|null $fieldReader Returns the configured custom-field names.
     */
    public function __construct(
        Repository $repository,
        Client $client,
        ?callable $keyFactory = null,
        ?callable $rowReader = null,
        ?callable $metaReader = null,
        ?callable $fieldReader = null
    ) {
        $this->repository = $repository;
        $this->client     = $client;
        $this->keyFactory = $keyFactory ?? static function (string $epoch, int $revision, int $postId, string $kind): string {
            return 'ev_' . \substr(\md5($epoch . ':' . $revision . ':' . $postId . ':' . $kind), 0, 32);
        };
        $this->rowReader  = $rowReader;
        $this->metaReader = $metaReader;
        $this->fieldReader = $fieldReader;
    }

    public function repository(): Repository
    {
        return $this->repository;
    }

    /**
     * Capture the current state of a post: allocate the revision, read the post
     * authoritatively, then persist either an upsert or a tombstone.
     *
     * @return int Outbox row id.
     *
     * @throws CaptureReadException When the post row cannot be read.
     */
    public function capture(int $postId, ?string $runId = null): int
    {
        $captured = $this->build($postId);

        if ($captured['deleted']) {
            return $this->repository->enqueue(
                $this->key($postId, Repository::KIND_DELETE, $captured['revision']),
                Repository::KIND_DELETE,
                $postId,
                ['post_id' => $postId, 'operation' => 'delete'],
                $runId,
                $captured['revision']
            );
        }

        return $this->repository->enqueue(
            $this->key($postId, Repository::KIND_UPSERT, $captured['revision']),
            Repository::KIND_UPSERT,
            $postId,
            $captured['payload'],
            $runId,
            $captured['revision']
        );
    }

    /**
     * Allocate a source revision and build the immutable payload (or tombstone)
     * without enqueuing anything. Used by the bulk uploader, which attaches items
     * to a run instead of delivering live mutations.
     *
     * @return array{revision:int,deleted:bool,payload:array<string,mixed>}
     *
     * @throws CaptureReadException When the post row cannot be read.
     */
    public function build(int $postId): array
    {
        // Revision first: a concurrent save that reads later must get a higher one.
        $revision = $this->repository->allocateRevision();
        $row      = $this->authoritativeRow($postId);

        if ($row === null || (string) ($row['post_status'] ?? '') !== 'publish') {
            return ['revision' => $revision, 'deleted' => true, 'payload' => []];
        }

        $row['post_id'] = $postId;
        $meta           = $this->authoritativeMeta($postId);
        $payload        = $this->client->capture_payload_from_row($row, $meta);

        return ['revision' => $revision, 'deleted' => false, 'payload' => $payload];
    }

    /**
     * Convenience wrapper for the save hook. The passed object is used only for its
     * id; content always comes from an authoritative read inside `build()`.
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
        return $this->tryCapture((int) $post->ID, $runId);
    }

    /**
     * Fail-closed capture for the save hook.
     *
     * @return int|null Null when the event could not be persisted (storage or read
     *                  failure); the caller must keep the index-dirty marker.
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

    /** @return array<string,mixed>|null */
    private function readRow(int $postId): ?array
    {
        $row = \call_user_func($this->rowReader, $postId);
        if ($row === null) {
            return null;
        }
        if (! \is_array($row)) {
            throw new CaptureReadException('Post row reader returned an invalid result');
        }

        return $row;
    }

    /** @return array<string,mixed>|null */
    private function authoritativeRow(int $postId): ?array
    {
        if ($this->rowReader !== null) {
            return $this->readRow($postId);
        }

        global $wpdb;
        if (! \is_object($wpdb) || ! \is_callable([$wpdb, 'get_row'])) {
            throw new CaptureReadException('WordPress database is unavailable');
        }
        /** @var \wpdb $db */
        $db = $wpdb;
        try {
            $row = $db->get_row(
                $db->prepare(
                    "SELECT post_status, post_modified_gmt, post_title, post_content FROM {$db->posts} WHERE ID = %d",
                    $postId
                ),
                ARRAY_A
            );
        } catch (\Throwable $readError) {
            throw new CaptureReadException(
                'Post row read failed: ' . \esc_html($readError->getMessage())
            );
        }

        $lastError = (string) $db->last_error;
        if (! \is_array($row)) {
            // Distinguish "no such post" (deletion) from "the read failed".
            if ($lastError !== '') {
                throw new CaptureReadException('Post row read failed: ' . \esc_html($lastError));
            }

            return null;
        }

        return $row;
    }

    /**
     * Configured meta values read straight from `postmeta`.
     *
     * @return array<string,mixed>
     */
    private function authoritativeMeta(int $postId): array
    {
        $fields = $this->customFields();
        if ($fields === []) {
            return [];
        }

        if ($this->metaReader !== null) {
            return $this->readMeta($postId, $fields);
        }

        global $wpdb;
        if (! \is_object($wpdb) || ! \is_callable([$wpdb, 'get_results'])) {
            return [];
        }
        /** @var \wpdb $db */
        $db      = $wpdb;
        $values  = [];
        $placeholders = \implode(', ', \array_fill(0, \count($fields), '%s'));
        try {
            $rows = $db->get_results($db->prepare(
                "SELECT meta_key, meta_value FROM {$db->postmeta} WHERE post_id = %d AND meta_key IN ($placeholders)",
                \array_merge([$postId], $fields)
            ), ARRAY_A);
        } catch (\Throwable $error) {
            return [];
        }

        $byKey = [];
        foreach ((array) $rows as $metaRow) {
            $key            = (string) ($metaRow['meta_key'] ?? '');
            $byKey[$key][] = \maybe_unserialize($metaRow['meta_value'] ?? '');
        }
        foreach ($fields as $field) {
            if (isset($byKey[$field])) {
                $values[$field] = \count($byKey[$field]) === 1 ? $byKey[$field][0] : $byKey[$field];
            }
        }

        return $values;
    }

    /**
     * @param string[] $fields
     * @return array<string,mixed>
     */
    private function readMeta(int $postId, array $fields): array
    {
        $values = \call_user_func($this->metaReader, $postId, $fields);
        if (! \is_array($values)) {
            return [];
        }

        return $values;
    }

    /** @return string[] */
    private function customFields(): array
    {
        if ($this->fieldReader !== null) {
            return $this->readFields();
        }

        $options = (array) \get_option('linkate-posts', []);

        return $this->client->configured_meta_fields($options);
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

    /** @return string[] */
    private function readFields(): array
    {
        $fields = \call_user_func($this->fieldReader);
        if (! \is_array($fields)) {
            return [];
        }

        return \array_values(\array_map('strval', $fields));
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
