<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use CherryLink\Support\WorkerLock;
use PHPUnit\Framework\TestCase;

final class WorkerLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
        \Brain\Monkey\Functions\when('wp_cache_delete')->justReturn(true);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    public function testStaleTakeoverDeletesOnlyObservedValueBeforeAddingNewOwner(): void
    {
        $deleted = [];
        $lock = new WorkerLock(
            static function (): int { return 100; },
            static function (): string { return 'new-owner'; },
            static function (string $option, string $value) use (&$deleted): bool {
                $deleted[] = [$option, $value];
                return true;
            }
        );
        \Brain\Monkey\Functions\when('get_option')->justReturn('90|old-owner');
        \Brain\Monkey\Functions\when('add_option')->justReturn(true);

        $this->assertSame('110|new-owner', $lock->acquire('worker_lock', 10));
        $this->assertSame([['worker_lock', '90|old-owner']], $deleted);
    }

    public function testStaleTakeoverFailsWhenConditionalDeleteLosesRace(): void
    {
        $lock = new WorkerLock(
            static function (): int { return 100; },
            static function (): string { return 'new-owner'; },
            static function (): bool { return false; }
        );
        \Brain\Monkey\Functions\when('get_option')->justReturn('90|old-owner');
        \Brain\Monkey\Functions\expect('add_option')->never();

        $this->assertNull($lock->acquire('worker_lock', 10));
    }

    public function testReleaseUsesConditionalDeleteWithoutPreRead(): void
    {
        $deleted = [];
        $lock = new WorkerLock(null, null, static function (string $option, string $value) use (&$deleted): bool {
            $deleted[] = [$option, $value];
            return true;
        });
        \Brain\Monkey\Functions\expect('get_option')->never();

        $lock->release('worker_lock', '110|owner');
        $this->assertSame([['worker_lock', '110|owner']], $deleted);
    }
}
