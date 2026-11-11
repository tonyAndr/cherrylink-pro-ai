<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Support\License;
use CherryLink\Support\Options;
use CherryLink\Support\PluginMeta;
use CherryLink\Support\PluginReports;
use CherryLink\Tests\Support\FakeReportClient;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Durability tests for the queue/send/backoff path. The API client is injected,
 * so no real HTTP or WordPress request path is involved.
 *
 * @covers \CherryLink\Support\PluginReports
 */
final class PluginReportsTest extends TestCase
{
    /** @var array<string,mixed> */
    private $options = [];

    /** @var array<int,array{0:int,1:string,2:array}> */
    private $scheduled = [];

    /** @var FakeReportClient */
    private $client;

    /** @var object|null Previous global wpdb, restored after each test. */
    private $previousWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->options = [
            'linkate-posts' => ['hash_field' => 'LICENSE123'],
            'linkate_posts_meta' => ['key_valid' => true, 'expires_at' => '2099-01-01 00:00:00'],
            PluginReports::WEB_ENV_OPTION => [
                'wp_version' => '6.5.5',
                'php_version' => '8.2.21',
                'db_version' => '8.0.46',
                'web_server' => 'Apache/2.4.59',
                'os' => 'Linux 6.8',
                'theme_name' => 'Twenty Twenty-Four',
                'theme_version' => '1.1',
                'locale' => 'ru_RU',
                'multisite' => false,
            ],
        ];
        $this->scheduled = [];
        $this->client = new FakeReportClient();

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value, $autoload = null) {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('add_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('wp_generate_uuid4')->justReturn('00000000-0000-4000-8000-000000000001');
        Functions\when('wp_schedule_single_event')->alias(function ($time, $hook, $args = []) {
            $this->scheduled[] = [$time, $hook, $args];
            return true;
        });
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('wp_doing_cron')->justReturn(true);

        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            /** @var string */
            public $options = 'wp_options';

            /** @var string */
            public $prefix = 'wp_';

            /** @param mixed ...$args */
            public function prepare(string $query, ...$args): string
            {
                return $query;
            }

            public function query(string $query): int
            {
                return 1;
            }
        };

        $version = new ReflectionProperty(PluginMeta::class, 'version');
        $version->setAccessible(true);
        $version->setValue(null, '1.2.0');
    }

    protected function tearDown(): void
    {
        $version = new ReflectionProperty(PluginMeta::class, 'version');
        $version->setAccessible(true);
        $version->setValue(null, null);
        if ($this->previousWpdb !== null) {
            $GLOBALS['wpdb'] = $this->previousWpdb;
        } else {
            unset($GLOBALS['wpdb']);
        }
        Monkey\tearDown();
        parent::tearDown();
    }

    private function reports(): PluginReports
    {
        return new PluginReports(new License(new Options()), function () {
            return $this->client;
        });
    }

    public function testActivationQueuesOneEventAndDeduplicates(): void
    {
        $reports = $this->reports();
        $reports->recordActivation();
        $reports->recordActivation();

        $pending = $this->options[PluginReports::OPTION] ?? [];
        $this->assertCount(1, $pending);
        $this->assertSame('plugin.activated', $pending[0]['action']);
        $this->assertSame('1.2.0', $pending[0]['plugin_version']);
        $this->assertNull($pending[0]['from_version']);
        $this->assertSame('Apache/2.4.59', $pending[0]['environment']['web_server']);
        $this->assertSame('1.2.0', $this->options[PluginReports::VERSION_OPTION]);
        $this->assertNotEmpty($this->scheduled);
        $this->assertSame(PluginReports::SEND_HOOK, $this->scheduled[0][1]);
    }

    public function testVersionChangeSweepQueuesUpgradeWithoutSending(): void
    {
        $this->options[PluginReports::VERSION_OPTION] = '1.1.1';
        $this->reports()->sweep();

        $pending = $this->options[PluginReports::OPTION] ?? [];
        $this->assertCount(1, $pending);
        $this->assertSame('plugin.upgraded', $pending[0]['action']);
        $this->assertSame('1.1.1', $pending[0]['from_version']);
        $this->assertSame('1.2.0', $pending[0]['plugin_version']);
        $this->assertSame('1.2.0', $this->options[PluginReports::VERSION_OPTION]);
        $this->assertSame([], $this->client->calls);
    }

    public function testSendPostsOneBatchAndClearsQueue(): void
    {
        $reports = $this->reports();
        $reports->recordActivation();
        $reports->send();

        $this->assertCount(1, $this->client->calls);
        $this->assertCount(1, $this->client->calls[0]);
        $this->assertSame([], $this->options[PluginReports::OPTION]);
    }

    public function testFailedSendRetainsEventWithBackoff(): void
    {
        $reports = $this->reports();
        $reports->recordActivation();
        $this->client->result = null;
        $this->scheduled = [];
        $before = time();

        $reports->send();

        $pending = $this->options[PluginReports::OPTION] ?? [];
        $this->assertCount(1, $pending);
        $this->assertSame(1, $pending[0]['attempts']);
        $this->assertGreaterThanOrEqual($before + 290, $pending[0]['next_attempt']);
        $this->assertNotEmpty($this->scheduled);
        $delay = $this->scheduled[0][0] - time();
        $this->assertGreaterThanOrEqual(295, $delay);
        $this->assertLessThanOrEqual(305, $delay);
    }

    public function testMaxAttemptsDropsEventInsteadOfLoopingForever(): void
    {
        $this->options[PluginReports::OPTION] = [[
            'event_key' => 'evt-00000001',
            'action' => 'plugin.activated',
            'plugin_version' => '1.2.0',
            'from_version' => null,
            'occurred_at' => gmdate('c'),
            'environment' => $this->options[PluginReports::WEB_ENV_OPTION],
            'attempts' => PluginReports::MAX_ATTEMPTS - 1,
            'next_attempt' => 0,
        ]];
        $this->client->result = null;
        $this->scheduled = [];

        $this->reports()->send();

        $this->assertSame([], $this->options[PluginReports::OPTION]);
        $this->assertSame([], $this->scheduled);
    }

    public function testUnlicensedSiteNeverCallsClientOrSchedules(): void
    {
        $this->options['linkate_posts_meta'] = ['key_valid' => false];
        $reports = $this->reports();
        $reports->recordActivation();
        $this->scheduled = [];

        $reports->send();

        $this->assertSame([], $this->client->calls);
        $this->assertCount(1, $this->options[PluginReports::OPTION] ?? []);
        $this->assertSame([], $this->scheduled);
    }
}
