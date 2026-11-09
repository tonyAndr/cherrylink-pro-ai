<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Support\RequestLock;
use Mockery;
use PHPUnit\Framework\TestCase;

/** @covers \CherryLink\Support\RequestLock */
final class RequestLockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testAcquireReturnsNullForExistingUnexpiredLockWithoutAddingOrHttp(): void
    {
        Functions\when('get_option')->justReturn((string) (time() + 60) . '|held');
        Functions\expect('add_option')->never();
        Functions\expect('wp_remote_request')->never();

        $this->assertNull(RequestLock::acquire('cl_test_lock', 10));
    }

    public function testAcquireReturnsNullWhenAtomicInsertLosesRace(): void
    {
        Functions\when('get_option')->justReturn('');
        Functions\when('wp_generate_uuid4')->justReturn('race-token');
        Functions\expect('add_option')->once()->with(
            'cl_test_lock',
            Mockery::pattern('/^\d+\|race-token$/'),
            '',
            false
        )->andReturn(false);

        $this->assertNull(RequestLock::acquire('cl_test_lock', 10));
    }

    public function testReleaseDeletesOnlyMatchingNameAndExactToken(): void
    {
        $wpdb = Mockery::mock();
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('prepare')->once()->with(
            'DELETE FROM wp_options WHERE option_name = %s AND option_value = %s',
            'cl_test_lock',
            '123|owner-token'
        )->andReturn('DELETE FROM wp_options WHERE option_name = ... AND option_value = ...');
        $queried = false;
        $wpdb->shouldReceive('query')->once()->andReturnUsing(static function () use (&$queried): int {
            $queried = true;
            return 0;
        });
        $GLOBALS['wpdb'] = $wpdb;
        Functions\expect('wp_cache_delete')->never();

        RequestLock::release('cl_test_lock', '123|owner-token');
        $this->assertTrue($queried);
    }
}
