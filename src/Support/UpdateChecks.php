<?php

declare(strict_types=1);

namespace CherryLink\Support;

/** Keep periodic updater I/O off ordinary dashboard requests. */
final class UpdateChecks
{
    public static function allowScheduledCheck(bool $due): bool
    {
        return $due && (\wp_doing_cron() || (\defined('WP_CLI') && WP_CLI));
    }
}
