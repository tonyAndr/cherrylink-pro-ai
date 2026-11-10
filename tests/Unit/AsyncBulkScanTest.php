<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Api\AsyncClient;
use CherryLink\Indexing\AsyncBulkScan;
use CherryLink\Indexing\IndexHelpers;
use CherryLink\Indexing\InventoryUnavailableException;
use CherryLink\Indexing\Outbox\Capture;
use CherryLink\Indexing\Outbox\Repository;
use CherryLink\Indexing\SyncService;
use CherryLink\Support\Capabilities;
use CherryLink\Support\License;
use CherryLink\Support\Options;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Durable bulk run: state machine plus the guarantees the review asked for —
 * a digest byte-identical to the API, idempotent retries, delete re-reads,
 * fail-closed inventory, tenant-mode routing, crash recovery and callback
 * fencing.
 *
 * @covers \CherryLink\Indexing\AsyncBulkScan
 */
final class AsyncBulkScanTest extends TestCase
{
    /** @var array<string,mixed> */
    private $options = [];

    /** @var array<int,array{hook:string,args:array}> */
    private $enqueued = [];

    /** @var array<int,array{ts:int,args:array}> */
    private $scheduled = [];

    /** @var array<int,string> */
    private $calls = [];

    /** @var array<int,array<string,mixed>> */
    private $published = [];

    /** @var array<int,array{revision:int,deleted:bool,payload:array}> */
    private $built = [];

    /** @var array<string,array<string,mixed>> */
    private $responses = [];

    /** @var bool */
    private $inventoryFails = false;

    /** @var bool */
    private $enqueueFails = false;

    /** @var bool */
    private $scheduledAction = false;

    /** @var array<int,array{docs:array,key:string}> */
    private $sentDocuments = [];

    /** @var array<int,array{items:array,key:string}> */
    private $sentDeletes = [];

    /** @var array<string,mixed>|null */
    private $capabilitySnapshot = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->options   = [];
        $this->enqueued  = [];
        $this->scheduled = [];
        $this->calls     = [];
        $this->built     = [];
        $this->responses = [];
        $this->sentDocuments = [];
        $this->sentDeletes = [];
        $this->inventoryFails = false;
        $this->enqueueFails = false;
        $this->scheduledAction = false;
        $this->published = [
            ['ID' => '1', 'post_modified_gmt' => '2026-01-01 00:00:00'],
            ['ID' => '2', 'post_modified_gmt' => '2026-01-01 00:00:00'],
        ];
        $this->capabilitySnapshot = [
            'known' => true, 'async' => true, 'available' => true,
            'tenant_mode' => 'async', 'protocol_version' => 1,
        ];
        $this->options[Options::META] = [
            'key_valid'  => true,
            'expires_at' => \gmdate('Y-m-d H:i:s', \time() + 86400),
        ];
        $this->options[Options::MAIN] = ['hash_field' => 'key-1'];

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return \array_key_exists($key, $this->options) ? $this->options[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) {
            unset($this->options[$key]);
            return true;
        });
        Functions\when('add_option')->alias(function ($key, $value) {
            if (\array_key_exists($key, $this->options)) {
                return false;
            }
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('as_enqueue_async_action')->alias(function ($hook, $args, $group) {
            if ($this->enqueueFails) {
                return false;
            }
            $this->enqueued[] = ['hook' => $hook, 'args' => $args];
            return 1;
        });
        Functions\when('as_schedule_single_action')->alias(function ($ts, $hook, $args, $group) {
            $this->scheduled[] = ['ts' => $ts, 'args' => $args];
            return 1;
        });
        Functions\when('as_has_scheduled_action')->alias(function () {
            return $this->scheduledAction;
        });
        Functions\when('wp_generate_uuid4')->justReturn('uuid-1');
        Functions\when('get_post_types')->justReturn([]);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('get_transient')->alias(function () {
            return $this->capabilitySnapshot;
        });

        $GLOBALS['wpdb'] = $this->mockWpdb();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return \Mockery\MockInterface */
    private function mockWpdb()
    {
        $test = $this;
        $wpdb = Mockery::mock();
        $wpdb->prefix   = 'wp_';
        $wpdb->posts    = 'wp_posts';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->last_error = '';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->andReturnUsing(static function () use ($test) {
            return $test->inventory();
        })->byDefault();
        $wpdb->shouldReceive('get_row')->andReturn(null)->byDefault();

        return $wpdb;
    }

    /** @return array<int,array<string,mixed>>|null */
    public function inventory(): ?array
    {
        if ($this->inventoryFails) {
            return null;
        }

        return $this->published;
    }

    private function service(bool $available = true): AsyncBulkScan
    {
        if (! $available) {
            $this->capabilitySnapshot['available'] = false;
        }

        $test     = $this;
        $client   = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('createRun')->andReturnUsing(static function ($payload) use ($test) {
            return $test->respond('create', $payload);
        });
        $client->shouldReceive('appendDocuments')->andReturnUsing(static function ($runId, $batchId, $docs) use ($test) {
            return $test->respond('documents', ['run_id' => $runId, 'batch_id' => $batchId, 'documents' => $docs]);
        });
        $client->shouldReceive('appendDeletes')->andReturnUsing(static function ($runId, $batchId, $items) use ($test) {
            return $test->respond('deletes', ['run_id' => $runId, 'batch_id' => $batchId, 'deletes' => $items]);
        });
        $client->shouldReceive('sealRun')->andReturnUsing(static function ($runId, $receipt) use ($test) {
            return $test->respond('seal', ['run_id' => $runId, 'receipt' => $receipt]);
        });
        $client->shouldReceive('runStatus')->andReturnUsing(static function ($runId) use ($test) {
            return $test->respond('status', ['run_id' => $runId]);
        });
        $client->shouldReceive('cancelRun')->andReturnUsing(static function ($runId) use ($test) {
            return $test->respond('cancel', ['run_id' => $runId]);
        });

        $capture = $this->captureMock();

        return new AsyncBulkScan(
            new Options(),
            new License(new Options()),
            new Capabilities(),
            static function () use ($client) {
                return $client;
            },
            static function () use ($capture) {
                return $capture;
            },
            static function () use ($test) {
                return $test->manifest();
            }
        );
    }

    /** @return array<string,mixed> */
    public function manifest(): array
    {
        if (isset($this->responses['manifest'])) {
            return $this->responses['manifest'];
        }

        return ['ok' => true, 'status' => 200, 'retry_after' => null, 'error' => '', 'map' => []];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function respond(string $key, array $context): array
    {
        $this->calls[] = $key;

        if (isset($this->responses[$key])) {
            return $this->responses[$key];
        }

        switch ($key) {
            case 'documents':
                $this->sentDocuments[] = ['docs' => $context['documents'], 'key' => $context['batch_id']];
                $count = \count($context['documents']);
                break;
            case 'deletes':
                $this->sentDeletes[] = ['items' => $context['deletes'], 'key' => $context['batch_id']];
                $count = \count($context['deletes']);
                break;
            default:
                $count = 0;
        }

        switch ($key) {
            case 'create':
                return ['ok' => true, 'status' => 202, 'retry_after' => null, 'error' => '',
                        'run_id' => 'run-1', 'state' => 'accepting', 'body' => ['generation' => 3]];
            case 'documents':
            case 'deletes':
                return ['ok' => true, 'status' => 202, 'retry_after' => null, 'error' => '',
                        'accepted_items' => \array_map(static function ($i) {
                            return 'item-' . $i;
                        }, \range(1, \max(1, $count))),
                        'accepted_count' => $count, 'replayed' => false];
            case 'seal':
                return ['ok' => true, 'status' => 202, 'retry_after' => null, 'error' => '',
                        'run_id' => 'run-1', 'state' => 'sealed'];
            case 'status':
                return ['ok' => true, 'status' => 200, 'retry_after' => null, 'error' => '',
                        'body' => ['state' => 'processing_vectors', 'items' => 2, 'vectors_applied' => 1,
                                   'vectors_pending' => 1, 'links_applied' => 0, 'links_pending' => 0]];
            default:
                return ['ok' => true, 'status' => 202, 'retry_after' => null, 'error' => ''];
        }
    }

    /** @return \Mockery\MockInterface */
    private function repositoryMock()
    {
        $repo = Mockery::mock(Repository::class);
        $repo->shouldReceive('sourceEpoch')->andReturn('epoch-1');
        $repo->shouldReceive('allocateRevision')->andReturn(900);
        $repo->shouldReceive('enqueue')->andReturn(7);
        $repo->shouldReceive('get')->andReturnUsing(static function ($id) {
            return ['id' => $id, 'source_revision' => 555];
        });

        return $repo;
    }

    /** @return \Mockery\MockInterface */
    private function captureMock()
    {
        $test = $this;
        $capture = Mockery::mock(Capture::class);
        $capture->shouldReceive('allocateRunMarker')->andReturn(7);
        $capture->shouldReceive('repository')->andReturnUsing(static function () use ($test) {
            return $test->repo();
        });
        $capture->shouldReceive('build')->andReturnUsing(static function ($postId) use ($test) {
            return $test->buildResult($postId);
        });

        return $capture;
    }

    /** @var Repository|null */
    private $repoInstance;

    public function repo()
    {
        if ($this->repoInstance === null) {
            $this->repoInstance = $this->repositoryMock();
        }

        return $this->repoInstance;
    }

    /** @return array{revision:int,deleted:bool,payload:array<string,mixed>} */
    public function buildResult(int $postId): array
    {
        if (isset($this->built[$postId])) {
            return $this->built[$postId];
        }

        return [
            'revision' => 1000 + $postId,
            'deleted'  => false,
            'payload'  => ['post_id' => $postId, 'title' => 'T' . $postId, 'text' => 'body' . $postId,
                           'url' => 'u', 'lang' => 'ru', 'content_hash' => 'h' . $postId,
                           'modified_gmt' => '2026-01-01 00:00:00', 'link_html' => ''],
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function seedRun(array $overrides = []): void
    {
        $this->options[AsyncBulkScan::STATE] = \array_merge([
            'status'         => 'running',
            'phase'          => 'upload',
            'cursor'         => 0,
            'run_id'         => 'run-1',
            'client_run_key' => 'scan-1',
            'mode'           => 'incremental',
            'source_epoch'   => 'epoch-1',
            'source_revision' => 555,
            'force_full'     => false,
            'uploaded'       => 0,
            'deleted'        => 0,
            'retry_attempt'  => 0,
            'waiting'        => false,
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        return (array) $this->options[AsyncBulkScan::STATE];
    }

    // ── Happy path ──────────────────────────────────────────────────────────

    public function testFullScanRunsManifestCreateUploadSealThenPolls(): void
    {
        $service = $this->service();
        $this->seedRun(['phase' => 'create']);
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];

        $key = $this->state()['client_run_key'];

        $service->worker('create', 0, 0, false, $key);
        $this->assertSame('run-1', $this->state()['run_id']);

        $service->worker('upload', 0, 0, false, $key);
        $this->assertSame(2, $this->state()['uploaded']);

        $service->worker('deletes', 0, 0, false, $key);
        $this->assertSame('seal', $this->state()['phase']);

        $service->worker('seal', 0, 0, false, $key);
        $this->assertTrue($this->state()['sealed']);

        $service->worker('poll', 0, 0, false, $key);
        $this->assertSame('poll', $this->state()['phase']);
        $this->assertSame(1, $this->state()['progress']['vectors_applied']);
    }

    public function testStaleRunKeyCallbackIsIgnored(): void
    {
        $service = $this->service();
        $this->seedRun();
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];

        $service->worker('upload', 0, 0, false, 'scan-from-an-older-run');

        $this->assertSame([], $this->calls);
        $this->assertSame(0, $this->state()['uploaded']);
    }

    public function testOverlappingWorkerCannotRunWhileTheLockIsHeld(): void
    {
        $service = $this->service();
        $this->seedRun();
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];
        // Another turn already holds the worker lock.
        $this->options[AsyncBulkScan::LOCK] = (\time() + 120) . '|other-worker';

        $service->worker('upload', 0, 0, false, 'scan-1');

        $this->assertSame([], $this->calls);
    }

    public function testExpiredWorkerLockIsTakenOver(): void
    {
        $service = $this->service();
        $this->seedRun();
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1], 'delete' => []];
        $this->options[AsyncBulkScan::LOCK] = (\time() - 5) . '|crashed-worker';

        $service->worker('upload', 0, 0, false, 'scan-1');

        $this->assertSame(1, $this->state()['uploaded']);
    }

    // ── 1. Digest parity ────────────────────────────────────────────────────

    public function testSnapshotDigestMatchesTheApiCanonicalForm(): void
    {
        $service = $this->service();

        // API: "\n".join(sorted(set(ids))) — no trailing newline.
        $expected = \hash('sha256', "1\n2\n7");
        $this->assertSame($expected, $service->digestFor([7, 1, 2, 2, 1]));
        $this->assertSame(\hash('sha256', ''), $service->digestFor([]));
    }

    public function testSnapshotDeclaresThePersistedPlanNotTheCurrentSite(): void
    {
        $service = $this->service();
        $this->seedRun(['phase' => 'create', 'mode' => 'snapshot', 'force_full' => true]);
        // The plan was captured earlier; the site has changed since.
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];
        $this->published = [['ID' => '9', 'post_modified_gmt' => '2026-03-03 00:00:00']];

        $seen = null;
        $client = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('createRun')->once()->andReturnUsing(static function ($payload) use (&$seen) {
            $seen = $payload;
            return ['ok' => true, 'status' => 202, 'retry_after' => null, 'error' => '',
                    'run_id' => 'run-1', 'state' => 'accepting'];
        });
        $capture = $this->captureMock();

        $service = new AsyncBulkScan(
            new Options(),
            new License(new Options()),
            new Capabilities(),
            static function () use ($client) {
                return $client;
            },
            static function () use ($capture) {
                return $capture;
            }
        );
        $this->seedRun(['phase' => 'create', 'mode' => 'snapshot', 'force_full' => true]);
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];

        $service->worker('create', 0, 0, true, 'scan-1');

        $this->assertIsArray($seen);
        $this->assertSame(2, $seen['expected_membership_count']);
        $this->assertSame($service->digestFor([1, 2]), $seen['expected_membership_digest']);
    }

    // ── 2. Idempotent retries ───────────────────────────────────────────────

    public function testRetryReusesThePersistedBatchInsteadOfRebuilding(): void
    {
        $this->responses['documents'] = ['ok' => false, 'status' => 0, 'retry_after' => null, 'error' => 'timeout'];
        $service = $this->service();
        $this->seedRun();
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];

        $service->worker('upload', 0, 0, false, 'scan-1');
        $first = $this->options[AsyncBulkScan::BATCH];
        $this->assertIsArray($first);

        // Retry: same cursor, success this time.
        unset($this->responses['documents']);
        $this->sentDocuments = [];
        $service->worker('upload', 0, 1, false, 'scan-1');

        $this->assertCount(1, $this->sentDocuments);
        $this->assertSame('scan-1:u:0', $this->sentDocuments[0]['key']);
        $this->assertSame(
            \array_column($first['items'], 'source_revision'),
            \array_column($this->sentDocuments[0]['docs'], 'source_revision'),
            'the retry must resend the exact same revisions'
        );
        $this->assertSame(
            \array_column($first['items'], 'content_hash'),
            \array_column($this->sentDocuments[0]['docs'], 'content_hash')
        );
        $this->assertArrayNotHasKey(AsyncBulkScan::BATCH, $this->options);
    }

    public function testRetryDoesNotReReadThePost(): void
    {
        $this->responses['documents'] = ['ok' => false, 'status' => 503, 'retry_after' => null, 'error' => 'HTTP 503'];
        $builds = 0;

        $service = $this->service();
        $capture = $this->captureMock();
        $capture->shouldReceive('build')->andReturnUsing(function ($postId) use (&$builds) {
            $builds++;
            return $this->buildResult($postId);
        });

        $service = new AsyncBulkScan(
            new Options(),
            new License(new Options()),
            new Capabilities(),
            static function () {
                return Mockery::mock(AsyncClient::class);
            },
            static function () use ($capture) {
                return $capture;
            }
        );

        $this->seedRun();
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];
        // Pre-seed the persisted batch as if a previous attempt had built it.
        $this->options[AsyncBulkScan::BATCH] = ['id' => 'scan-1:u:0', 'items' => [
            ['post_id' => 1, 'source_revision' => 111],
            ['post_id' => 2, 'source_revision' => 112],
        ]];

        // The client factory returns a mock with no expectations; the worker will
        // fail, but the build path must not run.
        $service->worker('upload', 0, 0, false, 'scan-1');

        $this->assertSame(0, $builds);
    }

    // ── 3. Delete re-read ───────────────────────────────────────────────────

    public function testPlannedDeleteIsSkippedWhenThePostWasRepublished(): void
    {
        $service = $this->service();
        $this->seedRun(['phase' => 'deletes']);
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [], 'delete' => [4]];
        // build() reports the post is live again.
        $this->built[4] = ['revision' => 9, 'deleted' => false, 'payload' => ['post_id' => 4]];

        $service->worker('deletes', 0, 0, false, 'scan-1');

        $this->assertSame([], $this->sentDeletes, 'a republished post must not be tombstoned');
        $this->assertSame(1, $this->state()['skipped_deletes']);
        $this->assertSame(0, $this->state()['deleted']);
        $this->assertSame('seal', $this->state()['phase']);
    }

    public function testPlannedDeleteStillSendsWhenThePostIsGone(): void
    {
        $service = $this->service();
        $this->seedRun(['phase' => 'deletes']);
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [], 'delete' => [4]];
        $this->built[4] = ['revision' => 44, 'deleted' => true, 'payload' => []];

        $service->worker('deletes', 0, 0, false, 'scan-1');

        $this->assertCount(1, $this->sentDeletes);
        $this->assertSame(44, $this->sentDeletes[0]['items'][0]['source_revision']);
        $this->assertSame(1, $this->state()['deleted']);
    }

    // ── 4. Fail-closed inventory ────────────────────────────────────────────

    public function testInventoryFailureIsRetriedAndNeverBecomesAnEmptyPlan(): void
    {
        $this->inventoryFails = true;
        $service = $this->service();
        $this->seedRun(['phase' => 'manifest']);

        $service->worker('manifest', 0, 0, false, 'scan-1');

        $state = $this->state();
        $this->assertSame('running', $state['status']);
        $this->assertSame('manifest', $state['phase']);
        $this->assertArrayNotHasKey(AsyncBulkScan::PLAN, $this->options, 'no plan may be written');
        $this->assertNotEmpty($this->scheduled);
    }

    public function testInventoryUnavailableExceptionIsThrownByTheReader(): void
    {
        $this->inventoryFails = true;
        $service = $this->service();

        $method = new \ReflectionMethod(AsyncBulkScan::class, 'publishedPosts');
        $method->setAccessible(true);

        $this->expectException(InventoryUnavailableException::class);
        $method->invoke($service);
    }

    // ── 5. Routing uses tenant mode, not availability ───────────────────────

    public function testEstablishedAsyncTenantPausesInsteadOfFallingBackToSync(): void
    {
        $service = $this->service(false);

        $state = $service->start();

        $this->assertSame('paused', $state['status']);
        $this->assertTrue($state['waiting']);
        // No synchronous chain was queued; only a probe was scheduled.
        $this->assertSame([], $this->enqueued);
        $this->assertNotEmpty($this->scheduled);
        $this->assertSame('probe', $this->scheduled[0]['args']['phase']);
    }

    public function testProbeResumesTheRunWhenAvailabilityReturns(): void
    {
        $service = $this->service(false);
        $service->start();
        $this->capabilitySnapshot['available'] = true;

        $service->worker('probe', 0, 0, null, 'ignored');

        $this->assertSame('running', $this->state()['status']);
        $this->assertSame('manifest', $this->state()['phase']);
        $this->assertFalse($this->state()['waiting']);
    }

    public function testSyncServiceRoutesByTenantModeNotAvailability(): void
    {
        $options = new Options();
        $async = Mockery::mock(AsyncBulkScan::class);
        $async->shouldReceive('handlesTenant')->andReturn(true);
        $async->shouldReceive('start')->once()->andReturn(['status' => 'paused']);

        $sync = new SyncService($options, new License($options), static function () {
            return Mockery::mock(\CherryLink\Api\Client::class);
        }, $async);

        $state = $sync->start();

        $this->assertSame('paused', $state['status']);
        $this->assertArrayNotHasKey(SyncService::STATE, $this->options, 'the sync chain must not start');
    }

    // ── 6. Crash recovery ───────────────────────────────────────────────────

    public function testSweepRestartsAChainWhoseSchedulingFailed(): void
    {
        $service = $this->service();
        $this->seedRun(['phase' => 'upload', 'cursor' => 20]);
        $this->enqueueFails = true;

        // A turn completes but cannot schedule the next action.
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1], 'delete' => []];
        $service->worker('upload', 20, 0, false, 'scan-1');
        $this->assertSame([], $this->enqueued);

        // The recovery sweep notices there is no queued turn and re-queues the
        // persisted phase/cursor.
        $this->enqueueFails = false;
        $this->scheduledAction = false;
        $persisted = $this->state();
        $this->assertTrue($service->sweep());
        $this->assertNotEmpty($this->enqueued);
        $this->assertSame($persisted['phase'], $this->enqueued[0]['args']['phase']);
        $this->assertSame((int) $persisted['cursor'], (int) $this->enqueued[0]['args']['cursor']);
    }

    public function testSweepIsQuietWhenATurnIsAlreadyQueued(): void
    {
        $service = $this->service();
        $this->seedRun();
        $this->scheduledAction = true;

        $this->assertFalse($service->sweep());
        $this->assertSame([], $this->enqueued);
    }

    // ── Snapshot drift ──────────────────────────────────────────────────────

    public function testSnapshotRestartsWhenMembershipDriftsDuringUpload(): void
    {
        $service = $this->service();
        $this->seedRun(['phase' => 'upload', 'mode' => 'snapshot', 'force_full' => true]);
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];
        $this->built[2] = ['revision' => 9, 'deleted' => true, 'payload' => []];

        $service->worker('upload', 0, 0, true, 'scan-1');

        $state = $this->state();
        $this->assertSame('manifest', $state['phase']);
        $this->assertSame('', $state['run_id']);
        $this->assertContains('cancel', $this->calls);
    }

    // ── Failure visibility ──────────────────────────────────────────────────

    public function testPermanentFailureIsVisibleAndStopsTheChain(): void
    {
        $this->responses['documents'] = ['ok' => false, 'status' => 422, 'retry_after' => null, 'error' => 'HTTP 422'];
        $service = $this->service();
        $this->seedRun();
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1], 'delete' => []];

        $service->worker('upload', 0, 0, false, 'scan-1');

        $state = $this->state();
        $this->assertSame('error', $state['status']);
        $this->assertSame('upload', $state['failed_phase']);
        $this->assertSame('ERROR', $this->options[Options::META]['indexing_process']);
    }

    public function testTerminalRunStateFinishesWithErrors(): void
    {
        $this->responses['status'] = ['ok' => true, 'status' => 200, 'retry_after' => null, 'error' => '',
            'body' => ['state' => 'completed_with_errors', 'items' => 2, 'vectors_applied' => 1]];
        $service = $this->service();
        $this->seedRun(['phase' => 'poll', 'sealed' => true]);

        $service->worker('poll', 0, 0, false, 'scan-1');

        $this->assertSame('completed_with_errors', $this->state()['status']);
        $this->assertSame('DONE_WITH_ERRORS', $this->options[Options::META]['indexing_process']);
    }

    public function testPauseThenResumeRestartsFromThePersistedCursor(): void
    {
        $service = $this->service();
        $this->seedRun(['cursor' => 20]);
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1], 'delete' => []];

        $service->pause();
        $this->assertSame('paused', $this->state()['status']);
        $service->worker('upload', 20, 0, false, 'scan-1');
        $this->assertSame([], $this->calls);

        $service->resume();
        $this->assertSame('running', $this->state()['status']);
        $this->assertSame(20, $this->enqueued[0]['args']['cursor']);
    }

    public function testCancelWhileAcceptingCancelsTheRemoteRun(): void
    {
        $service = $this->service();
        $this->seedRun(['sealed' => false]);

        $service->cancel();

        $this->assertContains('cancel', $this->calls);
        $this->assertSame('cancelled', $this->state()['status']);
    }

    public function testSealedRunIsNotCancelledRemotely(): void
    {
        $service = $this->service();
        $this->seedRun(['phase' => 'poll', 'sealed' => true]);

        $service->cancel();

        $this->assertNotContains('cancel', $this->calls);
        $this->assertSame('cancelled', $this->state()['status']);
    }

    public function testStatusPayloadSeparatesUploadFromIndexing(): void
    {
        $service = $this->service();
        $this->seedRun(['phase' => 'poll', 'uploaded' => 10, 'progress' => [
            'items' => 10, 'vectors_applied' => 3, 'vectors_pending' => 7,
            'links_applied' => 0, 'links_pending' => 0,
        ]]);

        $ui = $service->statusForUi();

        $this->assertSame('async', $ui['engine']);
        $this->assertSame(10, $ui['accepted']);
        $this->assertSame(3, $ui['vectors_applied']);
        $this->assertTrue($ui['processing']);
        $this->assertFalse($ui['uploading']);
    }
}
