<?php

declare(strict_types=1);

namespace CherryLink\Support;

/**
 * Short cross-process mutex for a background worker turn.
 *
 * WordPress can dispatch the same Action Scheduler hook from several processes at
 * once (WP-Cron loopback, a real server cron, the admin "tick", WP-CLI). Without a
 * fence two workers can read the same state, both perform the batch, and then
 * overwrite each other's counters — which looked like "the scan finished but
 * indexed 0".
 *
 * The lock is an option with an expiry embedded in its value: an owner that dies
 * mid-turn cannot block the queue forever, because an expired lock is taken over.
 * Acquisition uses `add_option`, which is atomic under the options table's unique
 * key.
 */
final class WorkerLock
{
    /** @var callable():int */
    private $clock;

    /** @var callable():string */
    private $tokenFactory;

    public function __construct(?callable $clock = null, ?callable $tokenFactory = null)
    {
        $this->clock = $clock ?? static function (): int {
            return \time();
        };
        $this->tokenFactory = $tokenFactory ?? static function (): string {
            return \function_exists('wp_generate_uuid4') ? \wp_generate_uuid4() : \bin2hex(\random_bytes(8));
        };
    }

    /**
     * Try to take the lock. Returns the owner token, or null when another turn
     * currently holds an unexpired lock.
     */
    public function acquire(string $option, int $ttl): ?string
    {
        $current = \get_option($option, '');
        if (\is_string($current) && $current !== '') {
            $expiry = (int) \strtok($current, '|');
            if ($expiry > $this->now()) {
                return null;
            }
            \delete_option($option);   // stale owner; take over
        }

        $token = ($this->now() + \max(1, $ttl)) . '|' . (string) \call_user_func($this->tokenFactory);

        return \add_option($option, $token, '', false) ? $token : null;
    }

    /** Release only if we still own it (another owner may have taken over). */
    public function release(string $option, string $token): void
    {
        if (\get_option($option, '') === $token) {
            \delete_option($option);
        }
    }

    private function now(): int
    {
        return (int) \call_user_func($this->clock);
    }
}
