<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Api\Client;
use CherryLink\Indexing\SyncService;
use CherryLink\Admin\AdminApi;
use CherryLink\Support\Capabilities;
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

    /** @var string[] hooks cleared before a single-event reschedule */
    private $cleared = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('wp_rand')->alias('mt_rand');
        Functions\when('get_transient')->justReturn(false); // capabilities cache is cold
        $this->cleared = [];
        Functions\when('wp_clear_scheduled_hook')->alias(function (string $hook): int {
            $this->cleared[] = $hook;
            return 0;
        });
        Functions\when('wp_schedule_single_event')->justReturn(true);

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
        $GLOBALS['cherrylink_sync_test_enqueue'] = function ($hook, $args, $group) {
            $this->enqueued[] = ['hook' => $hook, 'args' => $args, 'group' => $group];
            return 101;
        };
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
        // Cross-process worker fence.
        Functions\when('add_option')->justReturn(true);
        Functions\when('wp_generate_uuid4')->justReturn('lock-token');
        $GLOBALS['wpdb'] = new class {
            public $options = 'wp_options';

            public function prepare($query, ...$args)
            {
                foreach ($args as $arg) {
                    $query = \preg_replace('/%s/', "'" . \str_replace("'", "''", (string) $arg) . "'", $query, 1);
                }
                return $query;
            }

            public function query($query)
            {
                return 1;
            }
        };
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
        Functions\when('as_schedule_single_action')->alias(function ($timestamp, $hook, $args, $group) {
            $this->scheduled[] = ['timestamp' => $timestamp, 'hook' => $hook, 'args' => $args, 'group' => $group];
            return 202;
        });
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

    public function testWorkerIsFencedAndRequeuesWhenAnotherTurnHoldsTheLock(): void
    {
        // Another process (cron / tick / WP-CLI) is running this turn already.
        $this->options[SyncService::LOCK] = (\time() + 120) . '|other-turn';
        $service = $this->service(Mockery::mock(Client::class));

        $service->worker('upsert', 0, 0);

        // The batch must not run, and the turn must be re-queued rather than lost.
        $this->assertCount(1, $this->scheduled);
        $this->assertSame('upsert', $this->scheduled[0]['args']['phase']);
        $this->assertSame(0, $this->state['indexed']);
    }

    public function testExpiredLockIsTakenOverSoTheQueueCannotStall(): void
    {
        $this->options[SyncService::LOCK] = (\time() - 5) . '|dead-turn';
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('upsert_posts_by_ids_result')->once()->andReturn([
            'ok' => true, 'retryable' => false, 'status' => 200,
            'retry_after' => null, 'indexed' => 2, 'errors' => 0, 'error' => '',
        ]);
        $client->shouldReceive('log_error')->andReturnNull()->byDefault();

        $this->service($client)->worker('upsert', 0, 0);

        $this->assertSame(2, $this->state['indexed']);
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

    public function testStartQueuesManifestWithoutCreatingClientOrReplacingPriorPlan(): void
    {
        if (!\function_exists('as_enqueue_async_action')) {
            eval('function as_enqueue_async_action($hook, $args, $group) { return call_user_func($GLOBALS["cherrylink_sync_test_enqueue"], $hook, $args, $group); }');
        }
        $this->options['cherrylink_state'] = ['key_valid' => true, 'expires_at' => '2099-01-01'];
        $this->options[SyncService::PLAN] = ['upsert' => [77], 'delete' => [88]];
        $this->state['status'] = 'idle';
        $factory_calls = 0;
        $service = new SyncService(new Options(), new License(new Options()), static function () use (&$factory_calls) {
            ++$factory_calls;
            throw new \RuntimeException('start must not create an API client');
        });

        $state = $service->start(true);

        $this->assertSame(0, $factory_calls);
        $this->assertSame('running', $state['status']);
        $this->assertSame('manifest', $state['phase']);
        $this->assertTrue($state['force_full']);
        $this->assertSame(['upsert' => [77], 'delete' => [88]], $this->options[SyncService::PLAN]);
        $this->assertCount(1, $this->enqueued);
        $this->assertSame('manifest', $this->enqueued[0]['args']['phase']);
        $this->assertSame(0, $this->enqueued[0]['args']['attempt']);
        $this->assertTrue($this->enqueued[0]['args']['force_full']);
    }

    public function testStartClearsExpiredIndexFlagAndStaleLinkHealth(): void
    {
        if (!\function_exists('as_enqueue_async_action')) {
            eval('function as_enqueue_async_action($hook, $args, $group) { return call_user_func($GLOBALS["cherrylink_sync_test_enqueue"], $hook, $args, $group); }');
        }
        $this->options['cherrylink_state'] = ['key_valid' => true, 'expires_at' => '2099-01-01'];
        $this->state['status'] = 'idle';
        $snapshot = ['async' => false, 'plan' => ['name' => 'free', 'index_expired' => true]];
        $stored   = null;
        $deleted  = [];
        Functions\when('get_transient')->alias(static function () use (&$snapshot) {
            return $snapshot;
        });
        Functions\when('set_transient')->alias(static function ($key, $value) use (&$snapshot, &$stored) {
            $snapshot = $stored = $value;
            return true;
        });
        Functions\when('delete_option')->alias(static function ($key) use (&$deleted) {
            $deleted[] = $key;
            return true;
        });

        $this->service(Mockery::mock(Client::class))->start();

        $this->assertFalse($stored['plan']['index_expired']);
        $this->assertContains('cherrylink_link_health', $deleted);
    }

    public function testManifestSchedulingFailureIsVisibleAndKeepsPriorPlan(): void
    {
        $this->options['cherrylink_state'] = ['key_valid' => true, 'expires_at' => '2099-01-01'];
        $this->state['status'] = 'idle';
        Functions\when('as_enqueue_async_action')->justReturn(0);
        $api = Mockery::mock(Client::class);
        $api->shouldNotReceive('fetch_manifest_result');
        $state = $this->service($api)->start();
        $this->assertSame('error', $state['status']);
        $this->assertArrayNotHasKey('indexing_process', $this->options['cherrylink_state']);
        $this->assertSame(['upsert' => [1, 2], 'delete' => []], $this->plan);
    }

    public function testAjaxTickDispatchesAsyncRequestWithoutRunningQueueInline(): void
    {
        if (!\class_exists('ActionScheduler', false)) {
            eval('class ActionScheduler { public static function runner() { return $GLOBALS["cherrylink_sync_test_runner"]; } }');
        }
        if (!\function_exists('as_has_scheduled_action')) {
            eval('function as_has_scheduled_action($hook, $args = null, $group = "") { return true; }');
        }
        $this->state['status'] = 'running';
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('as_has_scheduled_action')->justReturn(true);
        $runner = new class {
            public $dispatches = 0;
            public function maybe_dispatch_async_request(): void { ++$this->dispatches; }
            public function run(): void { throw new \RuntimeException('queue must not run inline'); }
        };
        $GLOBALS['cherrylink_sync_test_runner'] = $runner;
        $response = null;
        Functions\when('wp_send_json')->alias(static function ($data) use (&$response) {
            $response = $data;
        });

        $this->service(Mockery::mock(Client::class))->ajaxTick();

        $this->assertSame(['ran' => 0, 'dispatch_requested' => true], $response);
        $this->assertSame(1, $runner->dispatches);
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
        $api->shouldReceive('log_error')->once();

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
        $this->eligible([]);
        $api = Mockery::mock(Client::class);
        $api->shouldReceive('fetch_sync_plan_result')->once()->andReturn($this->planResult([], 404));
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

        $this->eligible([1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00']);

        $api = Mockery::mock(Client::class);
        $api->shouldReceive('content_hash')->andReturn('h');
        $api->shouldReceive('fetch_sync_plan_result')->once()->andReturn($this->planResult([], 404));
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

    /** @param array<int,string> $rows post_modified_gmt by ID */
    private function eligible(array $rows): void
    {
        Functions\when('get_post_types')->justReturn([]);
        Functions\when('get_permalink')->alias(static function ($id) {
            return 'https://site.test/p' . $id . '/';
        });
        Functions\when('get_post')->alias(static function ($id) {
            $p = new \WP_Post();
            $p->ID = (int) $id;
            return $p;
        });
        $GLOBALS['wpdb'] = new class ($rows) {
            public $posts = 'wp_posts';
            private $rows;

            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function get_results($query)
            {
                $out = [];
                foreach ($this->rows as $id => $modified) {
                    $out[] = (object) ['ID' => $id, 'post_modified_gmt' => $modified];
                }
                return $out;
            }
        };
    }

    /** @return array<string,mixed> */
    private function planResult(array $plan, int $status = 200): array
    {
        return ['ok' => $status === 200, 'plan' => $plan, 'status' => $status, 'retry_after' => null,
                'retryable' => false, 'error' => $status === 200 ? '' : 'nope'];
    }

    private function manifestOk(array $map): array
    {
        return ['ok' => true, 'map' => $map, 'status' => 200, 'retry_after' => null, 'retryable' => false, 'error' => ''];
    }

    public function testServerPlanRequestUsesEligiblePostsAndTheUpsertHash(): void
    {
        $this->state['phase'] = 'manifest';
        $this->eligible([1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00']);
        $api = Mockery::mock(Client::class);
        $api->shouldReceive('content_hash')->twice()->andReturnUsing(static function ($post) {
            return 'hash-' . $post->ID;
        });
        $api->shouldReceive('fetch_sync_plan_result')->once()->with([
            ['post_id' => 1, 'modified_gmt' => '2026-01-01 00:00:00', 'content_hash' => 'hash-1', 'url' => 'https://site.test/p1/'],
            ['post_id' => 2, 'modified_gmt' => '2026-01-02 00:00:00', 'content_hash' => 'hash-2', 'url' => 'https://site.test/p2/'],
        ])->andReturn($this->planResult([
            'index' => [2], 'links' => [1, 2], 'drop_vectors' => [7, 9], 'drop_links' => [9],
        ]));
        $api->shouldNotReceive('fetch_manifest_result');

        $this->service($api)->worker('manifest', 0, 0, false);

        $this->assertSame(
            ['upsert' => [2], 'delete' => [9], 'drop' => [7], 'links' => [1, 2]],
            $this->options[SyncService::PLAN]
        );
        $this->assertSame('upsert', $this->state['phase']);
        $this->assertSame(2, $this->state['delete_total'], 'full removal + vector-only drop both count as prune work');
    }

    public function testServerPlanForceFullReuploadsEveryEligiblePost(): void
    {
        $this->state['phase'] = 'manifest';
        $this->state['force_full'] = true;
        $this->eligible([1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00']);
        $api = Mockery::mock(Client::class);
        $api->shouldReceive('content_hash')->andReturn('h');
        $api->shouldReceive('fetch_sync_plan_result')->once()->andReturn($this->planResult([
            'index' => [], 'links' => [], 'drop_vectors' => [], 'drop_links' => [],
        ]));

        $this->service($api)->worker('manifest', 0, 0, true);

        $plan = $this->options[SyncService::PLAN];
        $this->assertSame([2, 1], $plan['upsert']);
        $this->assertSame([2, 1], $plan['links']);
    }

    public function testServerPlanFailureRetriesThePlanPhase(): void
    {
        $this->state['phase'] = 'manifest';
        $this->eligible([1 => '2026-01-01 00:00:00']);
        $api = Mockery::mock(Client::class);
        $api->shouldReceive('content_hash')->andReturn('h');
        $api->shouldReceive('fetch_sync_plan_result')->once()->andReturn(
            $this->result(['status' => 429, 'retry_after' => 4]) + ['plan' => []]
        );

        $this->service($api)->worker('manifest', 0, 0, false);

        $this->assertSame('manifest', $this->state['phase']);
        $this->assertSame(1, $this->state['retry_attempt']);
        $this->assertSame('manifest', $this->scheduled[0]['args']['phase']);
    }

    public function testOlderApiWithoutPlanRouteFallsBackToTheManifest(): void
    {
        $this->state['phase'] = 'manifest';
        $this->options['cherrylink_options'] = ['hash_field' => 'free-key'];
        $this->options['cherrylink_state'] = ['key_valid' => true, 'expires_at' => '', 'plan' => 'free', 'post_limit' => 1];
        $this->eligible([1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00']);
        $api = Mockery::mock(Client::class);
        $api->shouldReceive('content_hash')->andReturn('h');
        $api->shouldReceive('fetch_sync_plan_result')->once()->andReturn($this->planResult([], 404));
        $api->shouldReceive('fetch_manifest_result')->once()->andReturn(
            $this->manifestOk([1 => ['hash' => 'h', 'modified' => '2026-01-01 00:00:00']])
        );

        $this->service($api)->worker('manifest', 0, 0, false);

        $plan = $this->options[SyncService::PLAN];
        $this->assertSame([2], $plan['upsert']);
        $this->assertSame([1], $plan['drop']);
        $this->assertSame([2], $plan['links'], 'limited plans on an old API: links follow upserts');
        $this->assertSame('upsert', $this->state['phase']);
        $this->assertSame(1, $this->state['delete_total'], 'the dropped post counts as prune work');
    }

    public function testInventoryAboveTheServerLimitUsesTheManifest(): void
    {
        $this->state['phase'] = 'manifest';
        $rows = [];
        for ($i = 1; $i <= SyncService::PLAN_MAX_ENTRIES + 1; ++$i) {
            $rows[$i] = '2026-01-01 00:00:00';
        }
        $this->eligible($rows);
        $api = Mockery::mock(Client::class);
        $api->shouldNotReceive('fetch_sync_plan_result');
        $api->shouldNotReceive('content_hash');
        $api->shouldReceive('log_error')->once()->with(Mockery::type('string'), 'php_index', 0, 'warning');
        $api->shouldReceive('fetch_manifest_result')->once()->andReturn($this->manifestOk([]));

        $this->service($api)->worker('manifest', 0, 0, false);

        $this->assertCount(SyncService::PLAN_MAX_ENTRIES + 1, $this->options[SyncService::PLAN]['upsert']);
    }

    public function testPlanLimitDropRemovesTheVectorButKeepsTheLinkGraph(): void
    {
        $this->state['phase'] = 'prune';
        $this->state['upsert_total'] = 0;
        $this->state['delete_total'] = 3;
        $this->plan = ['upsert' => [], 'delete' => [5], 'drop' => [6, 7], 'links' => []];

        $ok = ['ok' => true, 'retryable' => false, 'status' => 200, 'retry_after' => null, 'error' => ''];
        $api = Mockery::mock(Client::class);
        $api->shouldReceive('delete_documents_batch_result')->once()->with([5, 6, 7])->andReturn($ok + ['deleted' => 3]);
        $api->shouldReceive('delete_links_result')->once()->with(5, 0)->andReturn($ok);

        $this->service($api)->worker('prune', 0, 0);

        $this->assertSame('done', $this->state['phase']);
        // A finished scan refreshes the learned count and link health / key pages from cron.
        $this->assertSame([Capabilities::REFRESH_HOOK, AdminApi::HEALTH_HOOK], $this->cleared);
    }
}
