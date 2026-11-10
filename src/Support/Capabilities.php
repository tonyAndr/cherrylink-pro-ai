<?php

declare(strict_types=1);

namespace CherryLink\Support;

use CherryLink\Api\AsyncClient;

/**
 * Cached async-capability negotiation.
 *
 * WordPress asks "is durable async indexing available for this tenant?" on every
 * post save, so the answer is cached in a non-autoloaded transient with a bounded
 * lifetime. The backend already caches for 60 seconds and sends `Cache-Control:
 * no-store` when async is active or the tenant is not in `sync` mode, so a stale
 * *negative* answer is safe (the site keeps using the synchronous path) while a
 * stale *positive* answer only costs one rejected request that falls back.
 *
 * Default is always synchronous: any missing, malformed, or failed capability
 * response returns false, preserving the current behavior for old APIs.
 */
final class Capabilities
{
    /** Transient key for the last successful capability snapshot. */
    public const CACHE_KEY = 'cherrylink_capabilities';

    /** Short cache for a positive async answer. */
    public const POSITIVE_TTL = 60;

    /** Longer cache for a negative answer — re-probing on every save is wasteful. */
    public const NEGATIVE_TTL = 300;

    /** @var AsyncClient */
    private $client;

    public function __construct(?AsyncClient $client = null)
    {
        $this->client = $client ?? new AsyncClient();
    }

    /**
     * Whether this tenant should write live indexing through the durable outbox.
     *
     * Requires the API to advertise the protocol *and* the tenant to be in async
     * mode with both runs and mutations enabled. Anything else keeps the
     * synchronous adapter.
     */
    public function asyncEnabled(bool $forceRefresh = false): bool
    {
        $snapshot = $this->snapshot($forceRefresh);

        return $snapshot !== null && (bool) ($snapshot['async'] ?? false);
    }

    /**
     * Raw capability snapshot (cached). Null when the API is unreachable or does
     * not advertise the protocol.
     *
     * @return array<string,mixed>|null
     */
    public function snapshot(bool $forceRefresh = false): ?array
    {
        if (! $forceRefresh) {
            $cached = \get_transient(self::CACHE_KEY);
            if (\is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->client->capabilities();
        $capabilities = $result['ok'] ? ($result['capabilities'] ?? null) : null;

        if (! \is_array($capabilities)) {
            $negative = ['async' => false, 'protocol_version' => 0, 'tenant_mode' => 'sync', 'checked_at' => \time()];
            \set_transient(self::CACHE_KEY, $negative, self::NEGATIVE_TTL);
            return $negative;
        }

        $snapshot = $this->summarize($capabilities);
        \set_transient(
            self::CACHE_KEY,
            $snapshot,
            $snapshot['async'] ? self::POSITIVE_TTL : self::NEGATIVE_TTL
        );

        return $snapshot;
    }

    /** Drop the cached capability snapshot (license/mode change, operator disable). */
    public function forget(): void
    {
        \delete_transient(self::CACHE_KEY);
    }

    /**
     * Reduce a capability body to the decision this plugin needs.
     *
     * @param array<string,mixed> $capabilities
     * @return array<string,mixed>
     */
    public function summarize(array $capabilities): array
    {
        $features = \is_array($capabilities['features'] ?? null) ? $capabilities['features'] : [];
        $limits   = \is_array($capabilities['limits'] ?? null) ? $capabilities['limits'] : [];
        $features_on = ! empty($features['runs']) && ! empty($features['mutations']);
        $tenant_mode = (string) ($capabilities['tenant_mode'] ?? 'sync');

        return [
            'async'            => $features_on
                && $tenant_mode === 'async'
                && (string) ($capabilities['async_indexing'] ?? 'disabled') === 'enabled',
            'protocol_version' => (int) ($capabilities['protocol_version'] ?? 0),
            'tenant_mode'      => $tenant_mode,
            'runs'             => ! empty($features['runs']),
            'mutations'        => ! empty($features['mutations']),
            'batch_items'      => (int) ($limits['batch_items'] ?? AsyncClient::MAX_BATCH_ITEMS),
            'batch_bytes'      => (int) ($limits['batch_bytes'] ?? 1048576),
            'checked_at'       => \time(),
        ];
    }
}
