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

    /** @var AsyncClient|null Built on first refresh (cache reads never need it). */
    private $client;

    public function __construct(?AsyncClient $client = null)
    {
        $this->client = $client;
    }

    /** Lazily build the transport so cache-only reads never construct a Client. */
    private function client(): AsyncClient
    {
        if ($this->client === null) {
            $this->client = new AsyncClient();
        }

        return $this->client;
    }

    /**
     * Whether this tenant should write live indexing through the durable outbox.
     *
     * This is a **routing** decision about the tenant's mode, deliberately separate
     * from whether the API currently accepts async writes (`asyncAvailable()`).
     * Cache-only: never performs HTTP.
     *
     * A tenant that was ever observed in `async` mode stays there for routing, even
     * while the API is unreachable or an operator has temporarily disabled async
     * acceptance — falling back to synchronous writes would be rejected with
     * `409 async_tenant_requires_queue`. Only an explicit `sync` tenant mode clears
     * the marker.
     */
    public function asyncEnabled(): bool
    {
        $snapshot = $this->snapshot();

        if ($snapshot !== null && (bool) ($snapshot['async'] ?? false)) {
            return true;
        }

        if ($snapshot !== null && ($snapshot['known'] ?? true) === true) {
            // A real negative observation (tenant is in sync mode).
            $this->forgetSticky();

            return false;
        }

        // Unknown (expired/evicted snapshot or an outage we could not classify):
        // keep the established tenant mode.
        return $this->stickyAsync();
    }

    /**
     * Whether the API is currently accepting async writes for this tenant.
     *
     * Delivery uses this to pause instead of burning retries (and dead-lettering)
     * while acceptance is disabled or the API is unreachable. Rows are retained.
     */
    public function asyncAvailable(): bool
    {
        $snapshot = $this->snapshot();
        if ($snapshot === null) {
            return false;
        }

        return (bool) ($snapshot['available'] ?? false);
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
        $result       = $this->client()->capabilities();
        $capabilities = $result['ok'] ? ($result['capabilities'] ?? null) : null;

        if (! \is_array($capabilities)) {
            // A failed probe says nothing about the tenant's mode. Never let it
            // write a negative snapshot that would clear the sticky marker and
            // send an async tenant back to synchronous writes.
            if ($this->stickyAsync() || $this->snapshot() !== null && ($this->snapshot()['async'] ?? false)) {
                $stale = $this->unavailableSnapshot();
                \set_transient(self::CACHE_KEY, $stale, self::NEGATIVE_TTL);

                return $stale;
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
     * Drop the cached snapshot but KEEP the durable tenant mode.
     *
     * Used on activation and plugin update, where a fresh observation is required
     * but the tenant is still the same async tenant. Clearing the mode here would
     * push it onto synchronous writes until the next successful probe.
     */
    public function forget(): void
    {
        \delete_transient(self::CACHE_KEY);
    }

    /**
     * Drop the snapshot AND the durable tenant mode. Used only when the tenant
     * identity changes (different licence/site) so a previous tenant's mode cannot
     * leak into the new one.
     */
    public function forgetTenant(): void
    {
        \delete_transient(self::CACHE_KEY);
        $this->forgetSticky();
    }

    /** A classic negative observation: the tenant really is in sync mode. */
    public function negativeSnapshot(): array
    {
        return [
            'known'            => true,
            'available'        => false,
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
     * Snapshot used while the API cannot be reached: the tenant keeps its mode,
     * but acceptance is treated as unavailable so delivery pauses instead of
     * retrying into dead letters.
     *
     * @return array<string,mixed>
     */
    public function unavailableSnapshot(): array
    {
        return [
            'known'            => false,
            'available'        => false,
            'async'            => true,
            'protocol_version' => 1,
            'tenant_mode'      => 'async',
            'runs'             => true,
            'mutations'        => true,
            'batch_items'      => AsyncClient::MAX_BATCH_ITEMS,
            'batch_bytes'      => 1048576,
            'checked_at'       => \time(),
            'stale'            => true,
        ];
    }

    /**
     * Reduce a capability body to the decisions this plugin needs.
     *
     * `async` is the tenant's durable mode (routing); `available` is whether the
     * API currently accepts async writes (delivery). An operator emergency disable
     * sets available=false while tenant_mode stays `async`.
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
        $enabled     = (string) ($capabilities['async_indexing'] ?? 'disabled') === 'enabled';

        return [
            'known'            => true,
            'async'            => $tenant_mode === 'async',
            'available'        => $enabled && $tenant_mode === 'async' && $features_on,
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
