<?php

declare(strict_types=1);

namespace CherryLink\Support;

/** Short cross-request mutex. Expired/released tokens use compare-and-delete. */
final class RequestLock
{
    public static function acquire(string $name, int $ttl): ?string
    {
        $current = \get_option($name, '');
        if (\is_string($current) && $current !== '') {
            $expires = (int) \strtok($current, '|');
            if ($expires > \time()) {
                return null;
            }
            self::release($name, $current);
        }
        $token = (\time() + $ttl) . '|' . \wp_generate_uuid4();
        return \add_option($name, $token, '', false) ? $token : null;
    }

    public static function release(string $name, string $token): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            $name,
            $token
        ));
        if ($deleted) {
            \wp_cache_delete($name, 'options');
        }
    }
}
