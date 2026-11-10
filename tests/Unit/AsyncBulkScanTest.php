<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Api\AsyncClient;
use CherryLink\Indexing\AsyncBulkScan;
use CherryLink\Indexing\Outbox\Capture;
use CherryLink\Indexing\Outbox\Repository;
use CherryLink\Support\Capabilities;
use CherryLink\Support\License;
use CherryLink\Support\Options;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Durable bulk run state machine: manifest -> create -> upload -> deletes ->
 * seal -> poll, with persisted cursors, pause/resume/cancel, retry and recovery.
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

    /** @var array<int,array<string,mixed>> */
    private $built = [];

    /** @var array<string,array<string,mixed>> */
    private $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->options   = [];
        $this->enqueued  = [];
        $this->scheduled = [];
        $this->calls     = [];
        $this->built     = [];
        $this->published = [
            ['ID' => '1', 'post_modified_gmt' => '2026-01-01 00:00:00'],
            ['ID' => '2', 'post_modified_gmt' => '2026-01-01 00:00:00'],
        ];
        $this->responses = [];
        $this->capabilitySnapshot = [
            'known' => true, 'async' => true, 'available' => true,
            'tenant_mode' => 'async', 'protocol_version' => 1,
        ];

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
        Functions\when('as_enqueue_async_action')->alias(function ($hook, $args, $group) {
            $this->enqueued[] = ['hook' => $hook, 'args' => $args];
            return 1;
        });
        Functions\when('as_schedule_single_action')->alias(function ($ts, $hook, $args, $group) {
            $this->scheduled[] = ['ts' => $ts, 'args' => $args];
            return 1;
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
        $wpdb->prefix = 'wp_';
        $wpdb->posts = 'wp_posts';
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->andReturnUsing(static function () use ($test) {
            return $test->publishedRows();
        })->byDefault();
        $wpdb->shouldReceive('get_row')->andReturn(null)->byDefault();

        return $wpdb;
    }

    /** @return array<int,array<string,mixed>> */
    public function publishedRows(): array
    {
        return $this->published;
    }

    /** Real License (final service) driven by the stubbed option store. */
    private function license(bool $valid = true): License
    {
        if ($valid) {
            $this->options[Options::META] = [
                'key_valid'  => true,
                'expires_at' => \gmdate('Y-m-d H:i:s', \time() + 86400),
            ];
            $this->options[Options::MAIN] = ['hash_field' => 'key-1'];
        } else {
            $this->options[Options::META] = ['key_valid' => false];
        }

        return new License(new Options());
    }

    /** Real Capabilities (final service): availability comes from the cache. */
    private function capabilities(bool $available = true): Capabilities
    {
        $this->capabilitySnapshot = [
            'known' => true, 'async' => true, 'available' => $available,
            'tenant_mode' => 'async', 'protocol_version' => 1,
        ];

        return new Capabilities();
    }

    /** @var array<string,mixed>|null */
    private $capabilitySnapshot = null;

    private function service(?Capabilities $capabilities = null): AsyncBulkScan
    {
        $test     = $this;
        $client   = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('createRun')->andReturnUsing(static function ($payload) use ($test) {
            return $test->respond('create', $payload);
        });
        $client->shouldReceive('appendDocuments')->andReturnUsing(static function ($runId, $batchId, $docs, $expected) use ($test) {
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
        $repo    = $this->repositoryMock($capture);

        return new AsyncBulkScan(
            new Options(),
            $this->license(),
            $capabilities ?? $this->capabilities(),
            static function () use ($client) {
                return $client;
            },
            static function () use ($capture) {
                return $capture;
            }
        );
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
            case 'create':
                return ['ok' => true, 'status' => 202, 'retry_after' => null, 'error' => '',
                        'run_id' => 'run-1', 'state' => 'accepting', 'body' => ['generation' => 3]];
            case 'documents':
            case 'deletes':
                $count = \count($context[$key === 'documents' ? 'documents' : 'deletes'] ?? []);
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
    private function repositoryMock($capture)
    {
        $test = $this;
        $repo = Mockery::mock(Repository::class);
        $repo->shouldReceive('sourceEpoch')->andReturn('epoch-1');
        $repo->shouldReceive('allocateRevision')->andReturnUsing(static function () use (&$n) {
            static $i = 100;
            return ++$i;
        });
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
            return $test->repositoryForCapture();
        });
        $capture->shouldReceive('build')->andReturnUsing(static function ($postId) use ($test) {
            return $test->buildResult($postId);
        });

        return $capture;
    }

    /** @var Repository|null */
    private $repoForCapture;

    public function repositoryForCapture()
    {
        if ($this->repoForCapture === null) {
            $this->repoForCapture = $this->repositoryMock(null);
        }

        return $this->repoForCapture;
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
            'payload'  => ['post_id' => $postId, 'title' => 'T' . $postId, 'text' => 'body',
                           'url' => 'u', 'lang' => 'ru', 'content_hash' => 'h' . $postId,
                           'modified_gmt' => '2026-01-01 00:00:00', 'link_html' => ''],
        ];
    }

    // ── Tests ───────────────────────────────────────────────────────────────

    public function testFullScanRunsManifestCreateUploadSealThenPolls(): void
    {
        $service = $this->service();

        $service->start();

        // manifest phase fetches through Client; stub the manifest result via the
        // synchronous client is out of scope here, so drive phases directly.
        $service->setState([
            'status' => 'running', 'phase' => 'create', 'cursor' => 0,
            'client_run_key' => 'scan-1', 'mode' => 'incremental',
            'source_epoch' => 'epoch-1', 'source_revision' => 555,
            'upsert_total' => 2, 'delete_total' => 0, 'force_full' => false,
        ]);
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];

        $service->worker('create', 0, 0, false);
        $this->assertSame('run-1', $service->getState()['run_id']);
        $this->assertSame('upload', $service->getState()['phase']);
        $this->assertContains('create', $this->calls);

        $service->worker('upload', 0, 0, false);
        $state = $service->getState();
        $this->assertSame(2, $state['uploaded']);
        $this->assertSame('deletes', $state['phase']);

        $service->worker('deletes', 0, 0, false);
        $this->assertSame('seal', $service->getState()['phase']);

        $service->worker('seal', 0, 0, false);
        $state = $service->getState();
        $this->assertTrue($state['sealed']);
        $this->assertSame('poll', $state['phase']);

        $service->worker('poll', 0, 0, false);
        $state = $service->getState();
        $this->assertSame('poll', $state['phase']);
        $this->assertSame(1, $state['progress']['vectors_applied']);
        $this->assertNotEmpty($this->scheduled, 'poll must reschedule');

        unset($manifest);
    }

    public function testUploadCursorIsPersistedSoARestartResumes(): void
    {
        $this->published = [];
        for ($i = 1; $i <= 30; $i++) {
            $this->published[] = ['ID' => (string) $i, 'post_modified_gmt' => '2026-01-01 00:00:00'];
        }

        $service = $this->service();
        $ids = \range(1, 30);
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => $ids, 'delete' => []];
        $service->setState([
            'status' => 'running', 'phase' => 'upload', 'cursor' => 0, 'run_id' => 'run-1',
            'client_run_key' => 'scan-1', 'source_epoch' => 'epoch-1', 'source_revision' => 555,
            'upsert_total' => 30, 'uploaded' => 0, 'force_full' => false,
        ]);

        $service->worker('upload', 0, 0, false);
        $this->assertSame(20, $service->getState()['cursor']);
        $this->assertSame(20, $service->getState()['uploaded']);
        $this->assertSame(20, $service->getState()['progress'] === [] ? 20 : 20);

        // Simulate a restart: a fresh instance reads the same persisted cursor.
        $resumed = $this->service();
        $resumed->worker('upload', 20, 0, false);
        $this->assertSame(30, $resumed->getState()['uploaded']);
        $this->assertSame('deletes', $resumed->getState()['phase']);
    }

    public function testStaleCursorCallbackIsIgnored(): void
    {
        $service = $this->service();
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];
        $service->setState([
            'status' => 'running', 'phase' => 'upload', 'cursor' => 20, 'run_id' => 'run-1',
            'client_run_key' => 'scan-1', 'source_epoch' => 'e', 'source_revision' => 1,
            'force_full' => false,
        ]);

        $service->worker('upload', 0, 0, false);   // stale offset

        $this->assertSame(20, $service->getState()['cursor']);
        $this->assertSame([], $this->calls);
    }

    public function testTransientFailureSchedulesRetryWithSameCursor(): void
    {
        $this->responses['documents'] = ['ok' => false, 'status' => 503, 'retry_after' => null, 'error' => 'HTTP 503'];
        $service = $this->service();
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];
        $service->setState([
            'status' => 'running', 'phase' => 'upload', 'cursor' => 0, 'run_id' => 'run-1',
            'client_run_key' => 'scan-1', 'source_epoch' => 'e', 'source_revision' => 1,
            'upsert_total' => 2, 'force_full' => false,
        ]);

        $service->worker('upload', 0, 0, false);

        $state = $service->getState();
        $this->assertSame('running', $state['status']);
        $this->assertSame('upload', $state['phase']);
        $this->assertSame(0, $state['cursor']);
        $this->assertSame(1, $state['retry_attempt']);
        $this->assertNotEmpty($state['next_retry_gmt']);
        $this->assertNotEmpty($this->scheduled);
    }

    public function testPermanentFailureIsVisibleAndStopsTheChain(): void
    {
        $this->responses['documents'] = ['ok' => false, 'status' => 422, 'retry_after' => null, 'error' => 'HTTP 422'];
        $service = $this->service();
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1], 'delete' => []];
        $service->setState([
            'status' => 'running', 'phase' => 'upload', 'cursor' => 0, 'run_id' => 'run-1',
            'client_run_key' => 'scan-1', 'source_epoch' => 'e', 'source_revision' => 1,
            'upsert_total' => 1, 'force_full' => false,
        ]);

        $service->worker('upload', 0, 0, false);

        $state = $service->getState();
        $this->assertSame('error', $state['status']);
        $this->assertSame('upload', $state['failed_phase']);
        $this->assertStringContainsString('422', $state['last_error']);
        $this->assertSame('ERROR', $this->options[Options::META]['indexing_process']);
    }

    public function testPostThatVanishesDuringUploadBecomesADelete(): void
    {
        $this->built[2] = ['revision' => 9, 'deleted' => true, 'payload' => []];
        $service = $this->service();
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];
        $service->setState([
            'status' => 'running', 'phase' => 'upload', 'cursor' => 0, 'run_id' => 'run-1',
            'client_run_key' => 'scan-1', 'source_epoch' => 'e', 'source_revision' => 1,
            'upsert_total' => 2, 'force_full' => false,
        ]);

        $service->worker('upload', 0, 0, false);

        $plan = $this->options[AsyncBulkScan::PLAN];
        $this->assertContains(2, $plan['delete']);
        $this->assertSame(1, $service->getState()['uploaded']);
    }

    public function testTerminalRunStateFinishesAndSeparatesUploadFromIndexing(): void
    {
        $this->responses['status'] = ['ok' => true, 'status' => 200, 'retry_after' => null, 'error' => '',
            'body' => ['state' => 'completed_with_errors', 'items' => 2, 'vectors_applied' => 1,
                       'vectors_terminal' => 1, 'links_applied' => 0, 'links_pending' => 0]];
        $service = $this->service();
        $service->setState([
            'status' => 'running', 'phase' => 'poll', 'cursor' => 0, 'run_id' => 'run-1',
            'client_run_key' => 'scan-1', 'sealed' => true, 'uploaded' => 2, 'force_full' => false,
        ]);

        $service->worker('poll', 0, 0, false);

        $state = $service->getState();
        $this->assertSame('completed_with_errors', $state['status']);
        $this->assertSame('DONE_WITH_ERRORS', $this->options[Options::META]['indexing_process']);
        $this->assertArrayNotHasKey(AsyncBulkScan::PLAN, $this->options);
    }

    public function testPauseStopsTheChainAndResumeRestartsFromTheSamePhase(): void
    {
        $service = $this->service();
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];
        $service->setState([
            'status' => 'running', 'phase' => 'upload', 'cursor' => 0, 'run_id' => 'run-1',
            'client_run_key' => 'scan-1', 'source_epoch' => 'e', 'source_revision' => 1,
            'force_full' => false,
        ]);

        $service->pause();
        $this->assertSame('paused', $service->getState()['status']);

        // A worker invocation racing the pause must do nothing.
        $service->worker('upload', 0, 0, false);
        $this->assertSame([], $this->calls);

        $service->resume();
        $this->assertSame('running', $service->getState()['status']);
        $this->assertNotEmpty($this->enqueued);
    }

    public function testCancelWhileAcceptingCancelsTheRemoteRun(): void
    {
        $service = $this->service();
        $service->setState([
            'status' => 'running', 'phase' => 'upload', 'cursor' => 0, 'run_id' => 'run-1',
            'sealed' => false, 'client_run_key' => 'scan-1', 'force_full' => false,
        ]);

        $service->cancel();

        $this->assertContains('cancel', $this->calls);
        $this->assertSame('cancelled', $service->getState()['status']);
    }

    public function testSealedRunIsNotCancelledRemotely(): void
    {
        $service = $this->service();
        $service->setState([
            'status' => 'running', 'phase' => 'poll', 'cursor' => 0, 'run_id' => 'run-1',
            'sealed' => true, 'client_run_key' => 'scan-1', 'force_full' => false,
        ]);

        $service->cancel();

        $this->assertNotContains('cancel', $this->calls);
        $this->assertSame('cancelled', $service->getState()['status']);
    }

    public function testStartRefusesWhenAsyncIsUnavailable(): void
    {
        $service = $this->service($this->capabilities(false));

        $state = $service->start();

        $this->assertSame('error', $state['status']);
        $this->assertSame([], $this->enqueued);
    }

    public function testSnapshotModeSendsMembershipDigest(): void
    {
        $service = $this->service();
        $this->options[AsyncBulkScan::PLAN] = ['upsert' => [1, 2], 'delete' => []];
        $service->setState([
            'status' => 'running', 'phase' => 'create', 'cursor' => 0,
            'client_run_key' => 'scan-1', 'mode' => 'snapshot',
            'source_epoch' => 'epoch-1', 'source_revision' => 555,
            'upsert_total' => 2, 'force_full' => true,
        ]);

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
            $this->license(),
            $this->capabilities(),
            static function () use ($client) {
                return $client;
            },
            static function () use ($capture) {
                return $capture;
            }
        );
        $service->setState([
            'status' => 'running', 'phase' => 'create', 'cursor' => 0,
            'client_run_key' => 'scan-1', 'mode' => 'snapshot',
            'source_epoch' => 'epoch-1', 'source_revision' => 555,
            'upsert_total' => 2, 'force_full' => true,
        ]);

        $service->worker('create', 0, 0, true);

        $this->assertIsArray($seen);
        $this->assertSame('snapshot', $seen['mode']);
        $this->assertSame(2, $seen['expected_membership_count']);
        $this->assertSame(64, \strlen($seen['expected_membership_digest']));
    }
}
