<?php

declare(strict_types=1);

namespace CherryLink\Support;

use CherryLink\Contracts\Registerable;

/**
 * Background refresh of the cached async-capability snapshot.
 *
 * The save/delete hooks read the cache only (no HTTP). This service keeps that
 * cache fresh from cron/CLI, which is the only context allowed to make the
 * capability request. It is deliberately quiet: a missing license or an
 * unreachable API just leaves the synchronous adapter in place.
 */
final class CapabilitiesRefresh implements Registerable
{
    /** Refresh cadence; shorter than the cache TTL so entries rarely expire cold. */
    public const INTERVAL = 600;

    /** @var Capabilities */
    private $capabilities;

    /** @var License */
    private $license;

    public function __construct(License $license, ?Capabilities $capabilities = null)
    {
        $this->license      = $license;
        $this->capabilities = $capabilities ?? new Capabilities();
    }

    public function register(): void
    {
        \add_action(Capabilities::REFRESH_HOOK, [$this, 'run']);
        \add_action('wp_loaded', [$this, 'maybeSchedule']);
    }

    /**
     * Schedule the recurring refresh once. Uses WP-Cron (or a real server cron /
     * `wp cron event run`), never a page request.
     */
    public function maybeSchedule(): void
    {
        if (! \function_exists('wp_next_scheduled') || ! \function_exists('wp_schedule_event')) {
            return;
        }
        if (\wp_next_scheduled(Capabilities::REFRESH_HOOK)) {
            return;
        }
        \wp_schedule_event(\time() + self::INTERVAL, 'hourly', Capabilities::REFRESH_HOOK);
    }

    /** Cron callback: probe capabilities when licensed; drop the cache otherwise. */
    public function run(): void
    {
        if (! $this->license->isLicensed()) {
            $this->capabilities->forget();
            return;
        }

        $this->capabilities->refresh();
    }
}
