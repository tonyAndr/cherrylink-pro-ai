<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Api\AsyncClient;
use CherryLink\Indexing\Outbox\Delivery;
use CherryLink\Indexing\Outbox\Repository;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Outbox delivery chain: one call per immutable row, stable idempotency keys,
 * bounded retry/dead-letter, and no sleep inside the request.
 *
 * @covers \CherryLink\Indexing\Outbox\Delivery
 */
final class OutboxDeliveryTest extends TestCase
{
    /** @var array<int,array<string,mixed>> */
    private $rows = [];

    /** @var array<string,array<int,mixed>> */
    private $acknowledged = [];

    /** @var array<int,array<string,mixed>> */
    private $released = [];

    /** @var array<int,array<string,mixed>> */
    private $dead = [];

    /** @var array<int,array{ts:int,args:array}> */
    private $scheduled = [];

    /** @var array<string,mixed>|null */
    private $mutationResult;

    /** @var array<string,mixed>|null */
    private $runResult;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->rows = [];
        $this->acknowledged = [];
        $this->released = [];
        $this->dead = [];
        $this->scheduled = [];
        $this->mutationResult = null;
        $this->runResult = null;

        Functions\when('as_schedule_single_action')->alias(function ($ts, $hook, $args, $group) {
            $this->scheduled[] = ['ts' => $ts, 'args' => $args];
            return 1;
        });
        Functions\when('as_enqueue_async_action')->alias(function ($hook, $args, $group) {
            $this->scheduled[] = ['ts' => 0, 'args' => $args];
            return 1;
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return \Mockery\MockInterface */
    private function repositoryMock(int $pending = 0)
    {
        $test = $this;
        $repo = Mockery::mock(Repository::class);
        $repo->shouldReceive('reclaimExpired')->andReturn(0)->byDefault();
        $repo->shouldReceive('claim')->andReturnUsing(static function () use ($test) {
            return $test->claimedRows();
        })->byDefault();
        $repo->shouldReceive('pendingCount')->andReturn($pending)->byDefault();
        $repo->shouldReceive('ack')->andReturnUsing(static function ($id, $owner, $result) use ($test) {
            $test->recordAck($id, $owner, $result);
            return true;
        })->byDefault();
        $repo->shouldReceive('release')->andReturnUsing(static function ($id, $owner, $result, $next) use ($test) {
            $test->recordRelease($id, $owner, $result, $next);
            return true;
        })->byDefault();
        $repo->shouldReceive('deadLetter')->andReturnUsing(static function ($id, $owner, $result) use ($test) {
            $test->recordDead($id, $owner, $result);
            return true;
        })->byDefault();

        return $repo;
    }

    /** @return array<int,array<string,mixed>> */
    public function claimedRows(): array
    {
        return $this->rows;
    }

    /** @param array<string,mixed> $result */
    public function recordAck(int $id, string $owner, array $result): void
    {
        $this->acknowledged[$owner . ':' . $id] = $result;
    }

    /** @param array<string,mixed> $result */
    public function recordRelease(int $id, string $owner, array $result, ?int $next): void
    {
        $this->released[] = ['id' => $id, 'owner' => $owner, 'result' => $result, 'next' => $next];
    }

    /** @param array<string,mixed> $result */
    public function recordDead(int $id, string $owner, array $result): void
    {
        $this->dead[] = ['id' => $id, 'owner' => $owner, 'result' => $result];
    }

    /** @return \Mockery\MockInterface */
    private function clientMock()
    {
        $test = $this;
        $client = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('mutations')->andReturnUsing(static function ($key, $docs, $group = null) use ($test) {
            return $test->mutationResponse($key, $docs);
        })->byDefault();
        $client->shouldReceive('createRun')->andReturnUsing(static function ($body) use ($test) {
            return $test->runResponse($body);
        })->byDefault();

        return $client;
    }

    /** @return array<string,mixed> */
    public function mutationResponse(string $key, array $docs): array
    {
        if ($this->mutationResult !== null) {
            return $this->mutationResult;
        }
        return ['ok' => true, 'status' => 202, 'retry_after' => null, 'error' => '', 'accepted_items' => ['item-' . $key], 'accepted_count' => 1, 'replayed' => false];
    }

    /** @return array<string,mixed> */
    public function runResponse(array $body): array
    {
        if ($this->runResult !== null) {
            return $this->runResult;
        }
        return ['ok' => true, 'status' => 202, 'retry_after' => null, 'error' => '', 'run_id' => 'run-1', 'state' => 'accepting'];
    }

    private function delivery($repo, int $now = 5000): Delivery
    {
        return new Delivery($repo, $this->clientMock(), static function () use ($now): int {
            return $now;
        });
    }

    /** @return array<string,mixed> */
    private function row(array $overrides = []): array
    {
        return \array_merge([
            'id'              => 1,
            'event_key'       => 'ev_p1',
            'source_epoch'    => 'epoch-1',
            'source_revision' => 7,
            'post_id'         => 11,
            'kind'            => Repository::KIND_UPSERT,
            'run_id'          => null,
            'payload'         => \json_encode(['post_id' => 11, 'title' => 'T', 'text' => 'Body', 'url' => 'http://x/1', 'lang' => 'ru', 'content_hash' => 'h', 'modified_gmt' => '2026-01-01 00:00:00', 'link_html' => '<a>']),
            'state'           => 'leased',
            'attempts'        => 1,
            'lease_owner'     => 'owner-1',
        ], $overrides);
    }

    public function testUpsertRowIsDeliveredAsSingleStableMutationAndAcknowledged(): void
    {
        $this->rows = [$this->row()];

        $result = $this->delivery($this->repositoryMock())->run();

        $this->assertSame(1, $result['claimed']);
        $this->assertSame(1, $result['delivered']);
        $ack = $this->acknowledged['owner-1:1'];
        $this->assertSame(202, $ack['status']);
        $this->assertSame('item-ev_p1', $ack['remote_item_id']);
    }

    public function testDeleteRowDeliversTombstoneOperation(): void
    {
        $seen = null;
        $client = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('mutations')->once()->andReturnUsing(function ($key, $docs) use (&$seen) {
            $seen = $docs;
            return ['ok' => true, 'status' => 202, 'retry_after' => null, 'error' => '', 'accepted_items' => ['i'], 'accepted_count' => 1];
        });
        $this->rows = [$this->row([
            'kind'    => Repository::KIND_DELETE,
            'payload' => \json_encode(['post_id' => 11, 'operation' => 'delete']),
        ])];

        $repo = $this->repositoryMock();
        (new Delivery($repo, $client, static function (): int {
            return 5000;
        }))->run();

        $this->assertSame('delete', $seen[0]['operation']);
        $this->assertSame('epoch-1', $seen[0]['source_epoch']);
        $this->assertSame(7, $seen[0]['source_revision']);
    }

    public function testRunMarkerStoresReturnedRunId(): void
    {
        $this->rows = [$this->row([
            'kind'    => Repository::KIND_RUN_MARKER,
            'payload' => \json_encode(['client_run_key' => 'k-1', 'mode' => 'incremental']),
        ])];

        $this->delivery($this->repositoryMock())->run();

        $this->assertSame('run-1', $this->acknowledged['owner-1:1']['remote_item_id']);
    }

    public function testRetryableFailureSchedulesBackoffAndReleases(): void
    {
        $this->mutationResult = ['ok' => false, 'status' => 503, 'retry_after' => null, 'error' => 'HTTP 503'];
        $this->rows = [$this->row()];

        $result = $this->delivery($this->repositoryMock())->run();

        $this->assertSame(1, $result['retried']);
        $this->assertSame(503, $this->released[0]['result']['status']);
        $this->assertGreaterThan(5000, $this->released[0]['next']);
        $this->assertNotEmpty($this->scheduled);
    }

    public function testRetryAfterIsHonoredForScheduling(): void
    {
        $this->mutationResult = ['ok' => false, 'status' => 429, 'retry_after' => 42, 'error' => 'HTTP 429'];
        $this->rows = [$this->row()];

        $this->delivery($this->repositoryMock(), 5000)->run();

        $this->assertSame(5042, $this->released[0]['next']);
    }

    public function testPermanentFailureDeadLetters(): void
    {
        $this->mutationResult = ['ok' => false, 'status' => 409, 'retry_after' => null, 'error' => 'HTTP 409', 'code' => 'idempotency_key_reused'];
        $this->rows = [$this->row()];

        $result = $this->delivery($this->repositoryMock())->run();

        $this->assertSame(1, $result['dead']);
        $this->assertCount(1, $this->dead);
        $this->assertSame(409, $this->dead[0]['result']['status']);
        $this->assertSame([], $this->released);
    }

    public function testCorruptPayloadDeadLettersWithoutCallingApi(): void
    {
        $client = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('mutations')->never();
        $client->shouldReceive('createRun')->never();
        $this->rows = [$this->row(['payload' => 'not-json'])];

        (new Delivery($this->repositoryMock(), $client, static function (): int {
            return 5000;
        }))->run();

        $this->assertCount(1, $this->dead);
    }

    public function testNoFollowUpScheduledWhenQueueIsDrained(): void
    {
        $this->rows = [];
        $this->delivery($this->repositoryMock(0))->run();

        $this->assertSame([], $this->scheduled);
    }

    public function testFollowUpScheduledWhileRowsRemainPending(): void
    {
        $this->rows = [];
        $this->delivery($this->repositoryMock(3))->run();

        $this->assertNotEmpty($this->scheduled);
    }

    public function testScheduleUsesAsyncAction(): void
    {
        $ok = $this->delivery($this->repositoryMock())->schedule();

        $this->assertTrue($ok);
        $this->assertNotEmpty($this->scheduled);
    }
}
