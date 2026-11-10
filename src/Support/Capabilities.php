<?php

declare(strict_types=1);

namespace CherryLink\Support;

use CherryLink\Api\AsyncClient;

/**
 * Cached async-capability negotiation.
 *
 * WordPress asks "is durable async indexing available for this tenant?" on every
 * post save. That check must never make an HTTP request: `wp_insert_post` and
 * `delete_post` have a hard no-external-I/O contract (see
 * `specs/admin-responsiveness.md`). So this class separates the two operations:
 *
 *   - `asyncEnabled()` / `snapshot()` read only the transient cache.
 *   - `refresh()` performs the network call and is run from cron/CLI, license
 *     activation, and plugin activation — never from a page render or save.
 *
 * With an empty cache the answer is `false`, i.e. the existing synchronous
 * adapter, which is also correct for old APIs and for tenants in `sync` mode.
 */
final class Capabilities
{
    /** Transient key for the last successful capability snapshot. */
    public const CACHE_KEY = 'cherrylink_capabilities';

    /** Cron/CLI hook that refreshes the snapshot in the background. */
    public const REFRESH_HOOK = 'cherrylink_refresh_capabilities';

    /**
     * Durable marker: this tenant was last observed in async mode. It survives
     * snapshot expiry/eviction so a cache miss cannot move an async tenant back to
     * synchronous writes (which the backend rejects with 409).
     */
    public const STICKY_OPTION = 'cherrylink_async_tenant';

    /**
     * Cache lifetime for both directions. Capability changes are operator-driven
     * (a documented drain/reconcile mode switch), so a few minutes of staleness is
     * acceptable; the background refresh keeps it converging.
     */
    public const POSITIVE_TTL = 900;

    public const NEGATIVE_TTL = 900;

    /** @var AsyncClient */
    private $client;

    public function __construct(?AsyncClient $client = null)
    {
        $this->client = $client ?? new AsyncClient();
    }

    /**
     * Whether this tenant should write live indexing through the durable outbox.
     *
     * Cache-only: never performs HTTP. Requires the API to advertise the protocol
     * *and* the tenant to be in async mode with both runs and mutations enabled.
     */
    public function asyncEnabled(): bool
    {
        $snapshot = $this->snapshot();

        if ($snapshot !== null) {
            if ((bool) ($snapshot['async'] ?? false)) {
                return true;
            }
            // An explicit negative answer (sync mode / disabled) clears the sticky
            // marker so we do not honour a stale async decision forever.
            $this->forgetSticky();

            return false;
        }

        // No fresh snapshot: keep the established tenant mode instead of falling
        // back to synchronous writes the backend would reject.
        return $this->stickyAsync();
    }

    /** Whether a previously observed async tenant is still recorded as async. */
    public function stickyAsync(): bool
    {
        return (bool) \get_option(self::STICKY_OPTION, false);
    }

    private function rememberSticky(): void
    {
        \update_option(self::STICKY_OPTION, \time(), false);
    }

    private function forgetSticky(): void
    {
        if (\get_option(self::STICKY_OPTION, false) !== false) {
            \delete_option(self::STICKY_OPTION);
        }
    }

    /**
     * Cached capability snapshot, or null when nothing has been cached yet / the
     * API did not advertise the protocol. Never performs HTTP.
     *
     * @return array<string,mixed>|null
     */
    public function snapshot(): ?array
    {
        $cached = \get_transient(self::CACHE_KEY);

        return \is_array($cached) ? $cached : null;
    }

    /**
     * Perform the network probe and cache the result. Background contexts only.
     *
     * @return array<string,mixed> The stored snapshot.
     */
    public function refresh(): array
    {
        $result       = $this->client->capabilities();
        $capabilities = $result['ok'] ? ($result['capabilities'] ?? null) : null;

        if (! \is_array($capabilities)) {
            // A transient API outage must not downgrade a known-good async tenant:
            // keep the last positive snapshot so ordering keeps flowing through the
            // outbox while delivery retries. Only cache the negative when we have
            // no established positive state.
            $existing = $this->snapshot();
            if ($existing !== null && ($existing['async'] ?? false)) {
                return $existing;
            }
            $negative = $this->negativeSnapshot();
            \set_transient(self::CACHE_KEY, $negative, self::NEGATIVE_TTL);
            return $negative;
        }

        $snapshot = $this->summarize($capabilities);
        \set_transient(
            self::CACHE_KEY,
            $snapshot,
            $snapshot['async'] ? self::POSITIVE_TTL : self::NEGATIVE_TTL
        );

        if ($snapshot['async']) {
            $this->rememberSticky();
        } else {
            $this->forgetSticky();
        }

        return $snapshot;
    }

    /**
     * Drop the cached snapshot and the sticky tenant mode. Used on activation,
     * plugin update, and license change, where a fresh observation is required.
     */
    public function forget(): void
    {
        \delete_transient(self::CACHE_KEY);
        $this->forgetSticky();
    }

    /** @return array<string,mixed> */
    public function negativeSnapshot(): array
    {
        return [
            'async'            => false,
            'protocol_version' => 0,
            'tenant_mode'      => 'sync',
            'runs'             => false,
            'mutations'        => false,
            'batch_items'      => AsyncClient::MAX_BATCH_ITEMS,
            'batch_bytes'      => 1048576,
            'checked_at'       => \time(),
        ];
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
