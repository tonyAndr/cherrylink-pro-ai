<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Indexing\IndexHooks;
use Mockery;
use PHPUnit\Framework\TestCase;

/** @covers \CherryLink\Indexing\IndexHooks */
final class AdminResponsivenessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        if (!\defined('HOUR_IN_SECONDS')) {
            \define('HOUR_IN_SECONDS', 3600);
        }
        if (!\defined('CHERRY_VECTOR_API_URL')) {
            \define('CHERRY_VECTOR_API_URL', 'https://seocherry.apov.dev');
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testCachedStatusRenderDoesNotAcquireLockOrCallApi(): void
    {
        $cached = $this->snapshot(true, 12, 7, 301);
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn($cached);
        Functions\expect('add_option')->never();
        Functions\expect('wp_remote_request')->never();

        $data = IndexHooks::statusData();

        $this->assertSame(12, $data['index_rows']);
        $this->assertSame(7, $data['scheme_rows']);
    }

    public function testBusyRefreshReturnsCachedSnapshotWithoutHttp(): void
    {
        $cached = $this->snapshot(true, 21, 9, 301);
        Functions\when('get_option')->alias(static function ($key, $default = false) {
            return $key === 'cl_status_refresh_lock' ? (string) (\time() + 10) . '|other-request' : $default;
        });
        Functions\when('get_transient')->justReturn($cached);
        Functions\expect('add_option')->never();
        Functions\expect('wp_remote_request')->never();

        $data = IndexHooks::statusData(true);

        $this->assertSame(21, $data['index_rows']);
        $this->assertSame(9, $data['scheme_rows']);
        $this->assertTrue($data['refresh_pending']);
    }

    public function testRecentOutageCooldownReturnsCachedSnapshotWithoutHttp(): void
    {
        $cached = $this->snapshot(false, 31, 14, 0);
        Functions\when('get_option')->justReturn([]);
        Functions\when('get_transient')->justReturn($cached);
        Functions\expect('add_option')->never();
        Functions\expect('wp_remote_request')->never();

        $data = IndexHooks::statusData(true);

        $this->assertFalse($data['reachable']);
        $this->assertSame(31, $data['index_rows']);
        $this->assertSame(14, $data['scheme_rows']);
    }

    public function testFailedBoundedRefreshPreservesCountsAndReleasesItsLock(): void
    {
        $cached = $this->snapshot(true, 44, 18, 301);
        Functions\when('get_option')->alias(static function ($key, $default = false) {
            if ($key === 'linkate-posts') return ['hash_field' => 'test-key'];
            if ($key === 'cl_status_refresh_lock') return '';
            return $default;
        });
        Functions\when('get_transient')->alias(static function ($key) use ($cached) {
            return $key === IndexHooks::STATUS_CACHE_KEY ? $cached : false;
        });
        Functions\when('wp_generate_uuid4')->justReturn('refresh-token');
        Functions\expect('add_option')->once()->with(
            'cl_status_refresh_lock',
            Mockery::pattern('/^\d+\|refresh-token$/'),
            '',
            false
        )->andReturn(true);
        Functions\when('home_url')->justReturn('https://site.example.test');
        Functions\when('wp_parse_url')->justReturn('site.example.test');
        Functions\expect('wp_remote_request')->twice()->with(
            Mockery::type('string'),
            Mockery::on(static function (array $args): bool {
                return $args['timeout'] <= 2 && $args['redirection'] === 0;
            })
        )->andReturn(new class {
            public function get_error_message(): string { return 'offline'; }
        });
        Functions\when('is_wp_error')->justReturn(true);

        $wpdb = Mockery::mock();
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('prepare')->once()->with(
            'DELETE FROM wp_options WHERE option_name = %s AND option_value = %s',
            'cl_status_refresh_lock',
            Mockery::pattern('/^\d+\|refresh-token$/')
        )->andReturn('DELETE FROM wp_options WHERE option_name = ... AND option_value = ...');
        $wpdb->shouldReceive('query')->once()->andReturn(1);
        $GLOBALS['wpdb'] = $wpdb;
        Functions\expect('wp_cache_delete')->once()->with('cl_status_refresh_lock', 'options')->andReturn(true);
        Functions\expect('set_transient')->once()->with(
            IndexHooks::STATUS_CACHE_KEY,
            Mockery::on(static function (array $data): bool {
                return $data['index_rows'] === 44 && $data['scheme_rows'] === 18 && $data['reachable'] === false;
            }),
            HOUR_IN_SECONDS
        )->andReturn(true);

        $data = IndexHooks::statusData(true);

        $this->assertFalse($data['reachable']);
        $this->assertSame(44, $data['index_rows']);
        $this->assertSame(18, $data['scheme_rows']);
    }

    public function testClearStatusCachesDropsReadinessAndDiagnosticsSnapshots(): void
    {
        Functions\expect('delete_transient')->once()->with(IndexHooks::STATUS_CACHE_KEY)->andReturn(true);
        Functions\expect('delete_transient')->once()->with(IndexHooks::SERVER_STATUS_CACHE_KEY)->andReturn(true);

        IndexHooks::clearStatusCaches();
        $this->addToAssertionCount(1);
    }

    /** @return array<string,mixed> */
    private function snapshot(bool $reachable, int $indexRows, int $schemeRows, int $age): array
    {
        return [
            'index_rows' => $indexRows,
            'scheme_rows' => $schemeRows,
            'reachable' => $reachable,
            'checked_gmt' => \gmdate('Y-m-d H:i:s', \time() - $age),
        ];
    }
}
