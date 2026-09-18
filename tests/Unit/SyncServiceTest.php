<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Api\Client;
use CherryLink\Indexing\SyncService;
use CherryLink\Support\License;
use CherryLink\Support\Options;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Failure/cursor characterization for the legacy synchronous Action Scheduler chain.
 *
 * These tests deliberately exercise only the plugin-owned retry boundary. The API
 * client is injected so no HTTP or WordPress post lookup is needed.
 *
 * @covers \CherryLink\Indexing\SyncService
 */
final class SyncServiceTest extends TestCase
{
    /** @var array<string,mixed> */
    private $state;

    /** @var array<string,mixed> */
    private $plan;

    /** @var array<string,mixed> */
    private $options = [];

    /** @var array<int,array<string,mixed>> */
    private $scheduled = [];

    /** @var array<int,array<string,mixed>> */
    private $enqueued = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->state = [
            'status'        => 'running',
            'phase'         => 'upsert',
            'offset'        => 0,
            'upsert_total'  => 2,
            'delete_total'  => 0,
            'indexed'       => 0,
            'links'         => 0,
            'deleted'       => 0,
            'retry_attempt' => 0,
        ];
        $this->plan = ['upsert' => [1, 2], 'delete' => []];

        Functions\when('get_option')->alias(function ($key, $default = false) {
            if ($key === SyncService::STATE) {
                return $this->state;
            }
            if ($key === SyncService::PLAN) {
                return $this->options[$key] ?? $this->plan;
            }
            if (\array_key_exists($key, $this->options)) {
                return $this->options[$key];
            }
            return $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) {
            if ($key === SyncService::STATE) {
                $this->state = $value;
            } else {
                $this->options[$key] = $value;
            }
            return true;
        });
        Functions\when('as_enqueue_async_action')->alias(function ($hook, $args, $group) {
            $this->enqueued[] = ['hook' => $hook, 'args' => $args, 'group' => $group];
            return 101;
        });
        Functions\when('as_schedule_single_action')->alias(function ($timestamp, $hook, $args, $group) {
            $this->scheduled[] = [
                'timestamp' => $timestamp,
                'hook'      => $hook,
                'args'      => $args,
                'group'     => $group,
            ];
            return 202;
        });
        Functions\when('delete_option')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function result(array $overrides = []): array
    {
        return \array_merge([
            'ok'          => false,
            'retryable'   => true,
            'status'      => 503,
            'retry_after' => null,
            'error'       => 'temporary failure',
        ], $overrides);
    }

    private function service(Client $client): SyncService
    {
        $options = new Options();
        $license = new License($options);

        return new SyncService($options, $license, static function () use ($client): Client {
            return $client;
        });
    }

    public function testRetryKeepsUpsertCursorAndHonorsRetryAfter(): void
    {
        $api = Mockery::mock(Client::class);
        $api->shouldReceive('upsert_posts_by_ids_result')
            ->once()
            ->with([1, 2])
            ->andReturn($this->result(['status' => 429, 'retry_after' => 7]));

        $before = \time();
        $this->service($api)->worker('upsert', 0, 0);
        $after = \time();

        $this->assertSame('running', $this->state['status']);
        $this->assertSame('upsert', $this->state['phase']);
        $this->assertSame(0, $this->state['offset']);
        $this->assertSame(1, $this->state['retry_attempt']);
        $this->assertSame(429, $this->state['last_http_status']);
        $this->assertSame(7, $this->state['last_retry_after']);
        $this->assertCount(1, $this->scheduled);
        $this->assertSame(1, $this->scheduled[0]['args']['attempt']);
        $this->assertGreaterThanOrEqual($before + 7, $this->scheduled[0]['timestamp']);
        $this->assertLessThanOrEqual($after + 7, $this->scheduled[0]['timestamp']);
        $this->assertCount(0, $this->enqueued);
    }

    public function testPermanentUpsertFailureIsVisibleWithoutRetry(): void
    {
        $api = Mockery::mock(Client::class);
        $api->shouldReceive('upsert_posts_by_ids_result')
            ->once()
            ->andReturn($this->result([
                'retryable' => false,
                'status'    => 400,
                'error'     => 'invalid payload',
            ]));

        $this->service($api)->worker('upsert', 0, 0);

        $this->assertSame('error', $this->state['status']);
        $this->assertSame(0, $this->state['offset']);
        $this->assertSame('upsert', $this->state['failed_phase']);
        $this->assertSame('invalid payload', $this->state['last_error']);
        $this->assertCount(0, $this->scheduled);
        $this->assertCount(0, $this->enqueued);
    }

    public function testPartialLinkFailureDoesNotAdvanceCursor(): void
    {
        $this->state['phase'] = 'links';
        $this->plan['upsert'] = [1, 2];
        $api = Mockery::mock(Client::class);
        $api->shouldReceive('parse_links_for_ids_result')
            ->once()
            ->with([1, 2])
            ->andReturn($this->result([
                'status' => 200,
                'error'  => 'link batch contained errors',
                'count'  => 1,
                'errors' => 1,
            ]));

        $this->service($api)->worker('links', 0, 0);

        $this->assertSame('running', $this->state['status']);
        $this->assertSame(0, $this->state['offset']);
        $this->assertSame(0, $this->state['links']);
        $this->assertCount(1, $this->scheduled);
        $this->assertSame('links', $this->scheduled[0]['args']['phase']);
    }

    public function testManifestRetryKeepsExistingPlanAndHonorsRetryAfter(): void
    {
        $this->state['phase'] = 'manifest';
        $this->plan = ['upsert' => [99], 'delete' => [100]];
        $api = Mockery::mock(Client::class);
        $api->shouldReceive('fetch_manifest_result')
            ->once()
            ->andReturn($this->result([
                'status'      => 429,
                'retry_after' => 11,
                'error'       => 'manifest busy',
            ]));

        $this->service($api)->worker('manifest', 0, 0, false);

        $this->assertSame('running', $this->state['status']);
        $this->assertSame('manifest', $this->state['phase']);
        $this->assertSame(0, $this->state['offset']);
        $this->assertSame(1, $this->state['retry_attempt']);
        $this->assertSame($this->plan, $this->options[SyncService::PLAN] ?? $this->plan);
        $this->assertCount(1, $this->scheduled);
        $this->assertSame('manifest', $this->scheduled[0]['args']['phase']);
        $this->assertSame(11, $this->state['last_retry_after']);
        $this->assertSame(11, $this->scheduled[0]['timestamp'] - \time());
    }

    public function testSuccessfulManifestRetryBuildsPlanAndPreservesForceFull(): void
    {
        $this->state['phase'] = 'manifest';
        $this->state['retry_attempt'] = 1;
        $this->state['force_full'] = true;
        $this->plan = ['upsert' => [77], 'delete' => []];

        Functions\when('get_post_types')->justReturn([]);
        $GLOBALS['wpdb'] = new class {
            public $posts = 'wp_posts';

            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function get_results($query)
            {
                return [
                    (object) ['ID' => 1, 'post_modified_gmt' => '2026-01-01 00:00:00'],
                    (object) ['ID' => 2, 'post_modified_gmt' => '2026-01-02 00:00:00'],
                ];
            }
        };

        $api = Mockery::mock(Client::class);
        $api->shouldReceive('fetch_manifest_result')
            ->once()
            ->andReturn([
                'ok'          => true,
                'map'         => [3 => ['hash' => 'h3', 'modified' => '2026-01-03 00:00:00']],
                'status'      => 200,
                'retry_after' => null,
                'retryable'   => false,
                'error'       => '',
            ]);

        $this->service($api)->worker('manifest', 0, 1, true);

        $this->assertSame('running', $this->state['status']);
        $this->assertSame('upsert', $this->state['phase']);
        $this->assertSame(2, $this->state['upsert_total']);
        $this->assertSame(1, $this->state['delete_total']);
        $this->assertSame(['upsert' => [1, 2], 'delete' => [3]], $this->options[SyncService::PLAN]);
        $this->assertSame(0, $this->state['retry_attempt']);
        $this->assertCount(1, $this->enqueued);
        $this->assertSame('upsert', $this->enqueued[0]['args']['phase']);
        $this->assertTrue($this->enqueued[0]['args']['force_full']);
    }

    public function testPruneProcessesAtMostOneThousandDocumentsPerAction(): void
    {
        $this->state['phase'] = 'prune';
        $this->state['upsert_total'] = 0;
        $this->state['delete_total'] = 1001;
        $this->plan['upsert'] = [];
        $this->plan['delete'] = \range(1, 1001);

        $api = Mockery::mock(Client::class);
        $api->shouldReceive('delete_documents_batch_result')
            ->once()
            ->with(\range(1, 1000))
            ->andReturn([
                'ok' => true, 'retryable' => false, 'status' => 200,
                'retry_after' => null, 'deleted' => 1000, 'error' => '',
            ]);
        $api->shouldReceive('delete_links_result')
            ->times(1000)
            ->andReturn([
                'ok' => true, 'retryable' => false, 'status' => 200,
                'retry_after' => null, 'error' => '',
            ]);

        $this->service($api)->worker('prune', 0, 0);

        $this->assertSame('running', $this->state['status']);
        $this->assertSame('prune', $this->state['phase']);
        $this->assertSame(1000, $this->state['offset']);
        $this->assertSame(1000, $this->state['deleted']);
        $this->assertCount(1, $this->enqueued);
        $this->assertSame(1000, $this->enqueued[0]['args']['offset']);
    }

    public function testRetryDelayHonorsCapAndExponentialFallback(): void
    {
        $this->assertSame(7, SyncService::retry_delay(1, 7, 99));
        $this->assertSame(300, SyncService::retry_delay(99, 999, 0));
        $this->assertSame(10, SyncService::retry_delay(3, null, 2));
    }
}
