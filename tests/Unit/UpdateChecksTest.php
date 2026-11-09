<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Support\UpdateChecks;
use PHPUnit\Framework\TestCase;

final class UpdateChecksTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testPeriodicCheckCannotRunDuringOrdinaryAdminRequest(): void
    {
        Functions\when('wp_doing_cron')->justReturn(false);
        $this->assertFalse(UpdateChecks::allowScheduledCheck(true));
    }

    public function testCronRetainsDueDecision(): void
    {
        Functions\when('wp_doing_cron')->justReturn(true);
        $this->assertTrue(UpdateChecks::allowScheduledCheck(true));
        $this->assertFalse(UpdateChecks::allowScheduledCheck(false));
    }
}
