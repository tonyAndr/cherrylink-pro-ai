<?php

declare(strict_types=1);

namespace CherryLink\Api;

use WP_Post;

/**
 * Async (durable queue) endpoints of the Cherry Vector API.
 *
 * This is a thin, typed surface over the exact routes shipped by the backend
 * (`api/api/main.py`). It deliberately contains no retry or sleeping logic: it
 * returns the structured result from `Client::request_result()`, and the caller
 * (the outbox delivery chain) owns retry policy. `Api\Client` remains the single
 * transport boundary; this class delegates every call to it.
 *
 * Contract reference: `api/specs/async-indexing.md`.
 */
class AsyncClient
{
    /** Protocol version this client speaks. */
    public const PROTOCOL_VERSION = 1;

    /** Backend maximum items per batch (capability `limits.batch_items`). */
    public const MAX_BATCH_ITEMS = 50;

    /** Timeout for acceptance calls — acceptance is fast; execution is async. */
    private const ACCEPT_TIMEOUT = 30;

    /** @var Client */
    private $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(null, Client::CONTEXT_BACKGROUND);
    }

    /** Expose the underlying transport (shared with the sync adapter for tests). */
    public function transport(): Client
    {
        return $this->client;
    }

    // ── Capability negotiation ──────────────────────────────────────────────

    /**
     * GET /v1/capabilities.
     *
     * @return array{ok:bool,status:int,retry_after:int|null,error:string,capabilities:array<string,mixed>|null}
     */
    public function capabilities(): array
    {
        return $this->normalize(
            $this->client->request_result('GET', '/v1/capabilities'),
            'capabilities'
        );
    }

    // ── Run lifecycle ───────────────────────────────────────────────────────

    /**
     * POST /v1/index-runs.
     *
     * @param array{client_run_key:string,mode:string,source_epoch:string,source_revision:int,
     *              expected_membership_count?:int,expected_membership_digest?:string} $body
     * @return array<string,mixed>
     */
    public function createRun(array $body): array
    {
        $body['protocol_version'] = self::PROTOCOL_VERSION;

        return $this->normalize(
            $this->client->request_result('POST', '/v1/index-runs', $body, self::ACCEPT_TIMEOUT),
            'run'
        );
    }

    /**
     * POST /v1/index-runs/{run_id}/documents.
     *
     * @param array<int,array<string,mixed>> $documents
     * @return array<string,mixed>
     */
    public function appendDocuments(string $runId, string $batchId, array $documents, ?int $expectedAccepted = null): array
    {
        $body = [
            'protocol_version' => self::PROTOCOL_VERSION,
            'batch_id'         => $batchId,
            'documents'        => \array_values($documents),
        ];

        return $this->normalize(
            $this->client->request_result(
                'POST',
                '/v1/index-runs/' . \rawurlencode($runId) . '/documents',
                $body,
                self::ACCEPT_TIMEOUT
            ),
            'batch',
            $expectedAccepted
        );
    }

    /**
     * POST /v1/index-runs/{run_id}/deletes.
     *
     * @param array<int,array{post_id:int,source_epoch:string,source_revision:int}> $deletes
     * @return array<string,mixed>
     */
    public function appendDeletes(string $runId, string $batchId, array $deletes, ?int $expectedAccepted = null): array
    {
        $body = [
            'protocol_version' => self::PROTOCOL_VERSION,
            'batch_id'         => $batchId,
            'deletes'          => \array_values($deletes),
        ];

        return $this->normalize(
            $this->client->request_result(
                'POST',
                '/v1/index-runs/' . \rawurlencode($runId) . '/deletes',
                $body,
                self::ACCEPT_TIMEOUT
            ),
            'batch',
            $expectedAccepted
        );
    }

    /**
     * POST /v1/index-runs/{run_id}/seal.
     *
     * @return array<string,mixed>
     */
    public function sealRun(string $runId, string $receiptId): array
    {
        $body = [
            'protocol_version' => self::PROTOCOL_VERSION,
            'receipt_id'       => $receiptId,
        ];

        return $this->normalize(
            $this->client->request_result(
                'POST',
                '/v1/index-runs/' . \rawurlencode($runId) . '/seal',
                $body,
                self::ACCEPT_TIMEOUT
            ),
            'seal'
        );
    }

    /**
     * GET /v1/index-runs/{run_id} — run state and ledger-derived progress.
     *
     * @return array<string,mixed>
     */
    public function runStatus(string $runId): array
    {
        return $this->normalize(
            $this->client->request_result('GET', '/v1/index-runs/' . \rawurlencode($runId)),
            'run'
        );
    }

    /** @return array<string,mixed> */
    public function cancelRun(string $runId): array
    {
        return $this->normalize(
            $this->client->request_result(
                'POST',
                '/v1/index-runs/' . \rawurlencode($runId) . '/cancel',
                [],
                self::ACCEPT_TIMEOUT
            ),
            'run'
        );
    }

    /** @return array<string,mixed> */
    public function pauseRun(string $runId): array
    {
        return $this->normalize(
            $this->client->request_result(
                'POST',
                '/v1/index-runs/' . \rawurlencode($runId) . '/pause',
                [],
                self::ACCEPT_TIMEOUT
            ),
            'run'
        );
    }

    /** @return array<string,mixed> */
    public function resumeRun(string $runId): array
    {
        return $this->normalize(
            $this->client->request_result(
                'POST',
                '/v1/index-runs/' . \rawurlencode($runId) . '/resume',
                [],
                self::ACCEPT_TIMEOUT
            ),
            'run'
        );
    }

    // ── Live mutations ──────────────────────────────────────────────────────

    /**
     * POST /v1/index-mutations — one durable live event group.
     *
     * @param array<int,array<string,mixed>> $documents
     * @return array<string,mixed>
     */
    public function mutations(
        string $mutationKey,
        array $documents,
        ?string $mutationGroup = null,
        ?int $expectedAccepted = null
    ): array {
        $body = [
            'protocol_version' => self::PROTOCOL_VERSION,
            'mutation_key'     => $mutationKey,
            'documents'        => \array_values($documents),
        ];
        if ($mutationGroup !== null && $mutationGroup !== '') {
            $body['mutation_group'] = $mutationGroup;
        }

        return $this->normalize(
            $this->client->request_result('POST', '/v1/index-mutations', $body, self::ACCEPT_TIMEOUT),
            'mutation',
            $expectedAccepted
        );
    }

    /**
     * GET /v1/index-items — progress for accepted item ids (never bodies).
     *
     * @param string[] $itemIds
     * @return array<string,mixed>
     */
    public function items(array $itemIds): array
    {
        $itemIds = \array_slice(\array_values(\array_unique($itemIds)), 0, self::MAX_BATCH_ITEMS);
        if ($itemIds === []) {
            return $this->normalize(
                ['ok' => true, 'status' => 200, 'headers' => [], 'body' => ['items' => []],
                 'error' => '', 'retry_after' => null],
                'items'
            );
        }
        $query = \implode('&', \array_map(static function (string $id): string {
            return 'item_id=' . \rawurlencode($id);
        }, $itemIds));

        return $this->normalize(
            $this->client->request_result('GET', '/v1/index-items?' . $query),
            'items'
        );
    }

    // ── Document payload builder ────────────────────────────────────────────

    /**
     * Build the async document body for a post (no source version — the caller
     * allocates that from the outbox sequence *before* reading the post).
     *
     * @return array<string,mixed>
     */
    public static function documentForPost(Client $client, WP_Post $post): array
    {
        return $client->capture_payload($post);
    }

    // ── Normalization ───────────────────────────────────────────────────────

    /**
     * Convert a raw transport result into an async result. `ok` requires both a
     * 2xx transport response and a JSON object body; a 202 is only durable
     * acceptance, never execution.
     *
     * @param array{ok:bool,status:int,headers:array<string,string>,body:array|string|null,error:string,retry_after:int|null} $response
     * @return array<string,mixed>
     */
    private function normalize(array $response, string $kind, ?int $expectedAccepted = null): array
    {
        $body  = \is_array($response['body']) ? $response['body'] : null;
        $valid = $body !== null;
        // FastAPI wraps raised errors as {"detail": {"code": "..."}}.
        $code = '';
        if (\is_array($body)) {
            $detail = \is_array($body['detail'] ?? null) ? $body['detail'] : [];
            $code   = (string) ($body['code'] ?? $detail['code'] ?? '');
        }

        $result = [
            'ok'          => $response['ok'] && $valid,
            'status'      => (int) $response['status'],
            'retry_after' => $response['retry_after'],
            'error'       => $response['error'],
            'code'        => $code,
            'kind'        => $kind,
            'body'        => $body,
        ];
        if (! $valid) {
            $result['error'] = $response['error'] ?: 'invalid async response body';
        }

        // A 2xx alone is not durable acceptance: the backend must return the
        // documented receipt. Otherwise a truncated/empty body would be
        // acknowledged as delivered and the work would be silently lost.
        // Only judge the receipt when the transport itself succeeded: a non-2xx body
        // carries an error payload, not a receipt, and its server code is the useful
        // diagnostic (e.g. `idempotency_key_reused`, `snapshot_membership_mismatch`).
        if ($valid && $response['ok'] && ! self::receiptIsValid($kind, $body, $expectedAccepted)) {
            $result['ok']    = false;
            $result['error'] = 'invalid async receipt';
        }

        // Expose the concrete payload for the caller under a stable key.
        switch ($kind) {
            case 'capabilities':
                $result['capabilities'] = $valid && isset($body['protocol_version']) ? $body : null;
                if ($result['capabilities'] === null) {
                    $result['ok'] = false;
                }
                break;
            case 'run':
            case 'seal':
                $result['run_id'] = $valid ? (string) ($body['run_id'] ?? '') : '';
                $result['state']  = $valid ? (string) ($body['state'] ?? '') : '';
                break;
            case 'batch':
            case 'mutation':
                $result['accepted_items'] = $valid && \is_array($body['accepted_items'] ?? null)
                    ? \array_values(\array_map('strval', $body['accepted_items']))
                    : [];
                $result['accepted_count'] = $valid ? (int) ($body['accepted_count'] ?? 0) : 0;
                $result['replayed']       = $valid && (bool) ($body['replayed'] ?? false);
                break;
            case 'items':
                $result['items'] = $valid && \is_array($body['items'] ?? null) ? $body['items'] : [];
                break;
        }

        return $result;
    }

    /**
     * Required receipt fields per response kind (spec section 3 table).
     *
     * @param array<string,mixed> $body
     */
    private static function receiptIsValid(string $kind, array $body, ?int $expectedAccepted = null): bool
    {
        switch ($kind) {
            case 'capabilities':
                return isset($body['protocol_version'], $body['tenant_mode'])
                    && isset($body['features']) && \is_array($body['features']);
            case 'run':
                return isset($body['run_id']) && (string) $body['run_id'] !== ''
                    && isset($body['state']) && (string) $body['state'] !== '';
            case 'seal':
                return isset($body['run_id']) && (string) $body['run_id'] !== ''
                    && (string) ($body['state'] ?? '') === 'sealed';
            case 'batch':
            case 'mutation':
                if (! isset($body['accepted_items']) || ! \is_array($body['accepted_items'])) {
                    return false;
                }
                $items = \array_values($body['accepted_items']);
                if ($items === []) {
                    return false;   // a batch always carries at least one item
                }
                foreach ($items as $itemId) {
                    // A blank id means the backend acknowledged nothing; do not
                    // release the payload on the strength of it.
                    if (! \is_string($itemId) || \trim($itemId) === '') {
                        return false;
                    }
                }
                if ((int) ($body['accepted_count'] ?? 0) !== \count($items)) {
                    return false;
                }
                if ($expectedAccepted !== null && $expectedAccepted !== \count($items)) {
                    return false;   // must accept exactly what we uploaded
                }

                return true;
            case 'items':
                return isset($body['items']) && \is_array($body['items']);
            default:
                return true;
        }
    }

    /**
     * Whether the result should be retried. Acceptance calls retry transport
     * failures, 408/429/5xx; a 409/422 is a permanent client-side error.
     *
     * @param array{status?:int,error?:string,code?:string} $result
     */
    public static function isRetryable(array $result): bool
    {
        $status = (int) ($result['status'] ?? 0);
        if ($status === 0) {
            return ($result['error'] ?? '') !== 'no license key configured';
        }
        if ($status === 429) {
            return true;
        }

        return $status === 408 || $status >= 500;
    }
}
