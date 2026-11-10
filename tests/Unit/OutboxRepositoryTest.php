<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Indexing\Outbox\OutboxException;
use CherryLink\Indexing\Outbox\Repository;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Mechanics of the durable outbox: immutable enqueue, idempotent event keys,
 * atomic source ordering, and lease-fenced claim/ack/release/dead-letter/reclaim.
 *
 * The $wpdb global is replaced with a Mockery mock, so no real MySQL is required.
 *
 * @covers \CherryLink\Indexing\Outbox\Repository
 */
final class OutboxRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_json_encode')->alias('json_encode');
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
        $wpdb = Mockery::mock();
        $wpdb->prefix    = 'wp_';
        $wpdb->insert_id = 42;
        $wpdb->shouldReceive('prepare')->andReturnUsing(static function ($sql, ...$args) {
            return $sql;
        })->byDefault();

        return $wpdb;
    }

    /** @return Repository */
    private function repository($wpdb, int $now = 1000, string $owner = 'owner-1'): Repository
    {
        return new Repository(
            $wpdb,
            static function () use ($now): int {
                return $now;
            },
            static function () use ($owner): string {
                return $owner;
            }
        );
    }

    public function testEnqueueAllocatesEpochAndRevisionAndInsertsImmutablePendingRow(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('get_row')->andReturnUsing(static function ($sql, $mode) {
            if (\strpos($sql, 'source_epoch FROM') !== false) {
                return ['source_epoch' => 'epoch-1'];
            }
            return null; // no existing event key
        });
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('get_var')->andReturn(7);

        $inserted = null;
        $wpdb->shouldReceive('insert')->once()->with(
            'wp_cherrylink_outbox',
            Mockery::on(static function ($data) use (&$inserted) {
                $inserted = $data;
                return true;
            }),
            Mockery::any()
        )->andReturn(1);

        $id = $this->repository($wpdb)->enqueue('key-1', Repository::KIND_UPSERT, 5, ['text' => 'hello']);

        $this->assertSame(42, $id);
        $this->assertSame('key-1', $inserted['event_key']);
        $this->assertSame('epoch-1', $inserted['source_epoch']);
        $this->assertSame(7, $inserted['source_revision']);
        $this->assertSame(5, $inserted['post_id']);
        $this->assertSame('upsert', $inserted['kind']);
        $this->assertSame('{"text":"hello"}', $inserted['payload']);
        $this->assertSame('pending', $inserted['state']);
        // Immutability: the enqueue INSERT never sets delivery/lease columns.
        $this->assertArrayNotHasKey('lease_owner', $inserted);
        $this->assertArrayNotHasKey('next_attempt_gmt', $inserted);
        $this->assertArrayNotHasKey('last_http_status', $inserted);
    }

    public function testEnqueueReturnsExistingRowForDuplicateEventKeyWithoutNewRevision(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['id' => 42, 'post_id' => 5]);
        $wpdb->shouldReceive('query')->never();
        $wpdb->shouldReceive('get_var')->never();
        $wpdb->shouldReceive('insert')->never();

        $id = $this->repository($wpdb)->enqueue('key-1', Repository::KIND_UPSERT, 5, ['text' => 'hello']);

        $this->assertSame(42, $id);
    }

    public function testEnqueueRejectsUnknownKind(): void
    {
        $wpdb = $this->mockWpdb();

        $this->expectException(\InvalidArgumentException::class);
        $this->repository($wpdb)->enqueue('key-1', 'nonsense', 5, []);
    }

    public function testEnqueueRejectsEmptyOrOversizedEventKey(): void
    {
        $wpdb = $this->mockWpdb();

        $repo = $this->repository($wpdb);
        $this->expectException(\InvalidArgumentException::class);
        $repo->enqueue('', Repository::KIND_DELETE, 5, []);
    }

    public function testEnqueueThrowsOutboxExceptionWhenInsertFailsWithoutReplay(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('get_row')->andReturn(null); // no existing key, both lookups
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('get_var')->andReturn(7);
        $wpdb->shouldReceive('insert')->once()->andReturn(false);

        $this->expectException(OutboxException::class);
        $this->repository($wpdb)->enqueue('key-1', Repository::KIND_UPSERT, 5, ['text' => 'hello']);
    }

    public function testSourceEpochCreatesSingletonOnFirstUse(): void
    {
        $wpdb = $this->mockWpdb();
        $queries = [];
        $wpdb->shouldReceive('query')->andReturnUsing(static function ($sql) use (&$queries) {
            $queries[] = $sql;
            return 1;
        });
        $wpdb->shouldReceive('get_row')->once()->andReturn(['source_epoch' => 'epoch-9']);

        $epoch = $this->repository($wpdb)->sourceEpoch();

        $this->assertSame('epoch-9', $epoch);
        $this->assertStringContainsString('INSERT IGNORE', $queries[0]);
        $this->assertStringContainsString('cherrylink_outbox_state', $queries[0]);
    }

    public function testAllocateRevisionUsesAtomicLastInsertIdCounter(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('get_var')->once()->andReturn(11);

        $revision = $this->repository($wpdb)->allocateRevision();

        $this->assertSame(11, $revision);
    }

    public function testResetEpochRotatesEpochAndResetsRevision(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('query')->andReturn(1);
        $wpdb->shouldReceive('get_var')->andReturn(0);

        $newEpoch = $this->repository($wpdb, 1000, 'new-epoch-uuid')->resetEpoch();

        $this->assertSame('new-epoch-uuid', $newEpoch);
    }

    public function testClaimLeasesDueRowsToUniqueOwnerAndCountsAttempt(): void
    {
        $wpdb = $this->mockWpdb();
        $queries = [];
        $wpdb->shouldReceive('query')->andReturnUsing(static function ($sql) use (&$queries) {
            $queries[] = $sql;
            return 2;
        });
        $wpdb->shouldReceive('get_results')->once()->andReturn([
            ['id' => '3', 'lease_owner' => 'owner-1', 'state' => 'leased', 'attempts' => '2'],
            ['id' => '4', 'lease_owner' => 'owner-1', 'state' => 'leased', 'attempts' => '1'],
        ]);

        $rows = $this->repository($wpdb, 1000, 'owner-1')->claim(5, 60);

        $this->assertCount(2, $rows);
        $this->assertSame(3, $rows[0]['id']);
        $this->assertSame(2, $rows[0]['attempts']);
        $this->assertStringContainsString('attempts = attempts + 1', $queries[0]);
        $this->assertStringContainsString("WHERE state = 'pending'", $queries[0]);
        $this->assertStringContainsString('lease_expiry_gmt', $queries[0]);
    }

    public function testAckPersistsDeliveredStateAndRemoteItemId(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['id' => 5, 'lease_owner' => 'owner-1', 'attempts' => 1]);

        $updated = null;
        $wpdb->shouldReceive('update')->once()->with(
            'wp_cherrylink_outbox',
            Mockery::on(static function ($data) use (&$updated) {
                $updated = $data;
                return true;
            }),
            ['id' => 5, 'lease_owner' => 'owner-1'],
            Mockery::any(),
            ['%d', '%s']
        )->andReturn(1);

        $ok = $this->repository($wpdb)->ack(5, 'owner-1', ['status' => 202, 'remote_item_id' => 'it-9']);

        $this->assertTrue($ok);
        $this->assertSame('delivered', $updated['state']);
        $this->assertSame('it-9', $updated['remote_item_id']);
        $this->assertNull($updated['lease_owner']);
        $this->assertArrayNotHasKey('payload', $updated);
    }

    public function testAckRefusesStaleOwner(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['id' => 5, 'lease_owner' => 'someone-else', 'attempts' => 1]);
        $wpdb->shouldReceive('update')->never();

        $this->assertFalse($this->repository($wpdb)->ack(5, 'owner-1', ['status' => 202]));
    }

    public function testReleaseReturnsToPendingWithComputedNextAttempt(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['id' => 5, 'lease_owner' => 'owner-1', 'attempts' => 2]);

        $updated = null;
        $wpdb->shouldReceive('update')->once()->with(
            'wp_cherrylink_outbox',
            Mockery::on(static function ($data) use (&$updated) {
                $updated = $data;
                return true;
            }),
            ['id' => 5, 'lease_owner' => 'owner-1'],
            Mockery::any(),
            ['%d', '%s']
        )->andReturn(1);

        $ok = $this->repository($wpdb)->release(
            5,
            'owner-1',
            ['retryable' => true, 'status' => 503, 'retry_after' => null, 'error' => 'temporary'],
            2000
        );

        $this->assertTrue($ok);
        $this->assertSame('pending', $updated['state']);
        $this->assertSame(\gmdate('Y-m-d H:i:s', 2000), $updated['next_attempt_gmt']);
        $this->assertNull($updated['lease_owner']);
        $this->assertSame(503, $updated['last_http_status']);
    }

    public function testReleaseDeadLettersNonRetryable(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['id' => 5, 'lease_owner' => 'owner-1', 'attempts' => 1]);

        $updated = null;
        $wpdb->shouldReceive('update')->once()->with(
            'wp_cherrylink_outbox',
            Mockery::on(static function ($data) use (&$updated) {
                $updated = $data;
                return true;
            }),
            ['id' => 5, 'lease_owner' => 'owner-1'],
            Mockery::any(),
            ['%d', '%s']
        )->andReturn(1);

        $ok = $this->repository($wpdb)->release(
            5,
            'owner-1',
            ['retryable' => false, 'status' => 400, 'retry_after' => null, 'error' => 'bad payload'],
            2000
        );

        $this->assertTrue($ok);
        $this->assertSame('dead', $updated['state']);
        $this->assertNull($updated['next_attempt_gmt']);
    }

    public function testReleaseDeadLettersWhenAttemptsExhausted(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn([
            'id' => 5,
            'lease_owner' => 'owner-1',
            'attempts' => Repository::MAX_ATTEMPTS,
        ]);

        $updated = null;
        $wpdb->shouldReceive('update')->once()->with(
            'wp_cherrylink_outbox',
            Mockery::on(static function ($data) use (&$updated) {
                $updated = $data;
                return true;
            }),
            ['id' => 5, 'lease_owner' => 'owner-1'],
            Mockery::any(),
            ['%d', '%s']
        )->andReturn(1);

        $ok = $this->repository($wpdb)->release(
            5,
            'owner-1',
            ['retryable' => true, 'status' => 503, 'retry_after' => null, 'error' => 'temporary'],
            2000
        );

        $this->assertTrue($ok);
        $this->assertSame('dead', $updated['state']);
    }

    public function testDeadLetterRefusesStaleOwner(): void
    {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive('get_row')->once()->andReturn(['id' => 5, 'lease_owner' => 'someone-else']);
        $wpdb->shouldReceive('update')->never();

        $this->assertFalse($this->repository($wpdb)->deadLetter(5, 'owner-1', ['status' => 400]));
    }

    public function testReclaimExpiredReturnsLeasedRowsToPending(): void
    {
        $wpdb = $this->mockWpdb();
        $queries = [];
        $wpdb->shouldReceive('query')->andReturnUsing(static function ($sql) use (&$queries) {
            $queries[] = $sql;
            return 3;
        });

        $reclaimed = $this->repository($wpdb)->reclaimExpired();

        $this->assertSame(3, $reclaimed);
        $this->assertStringContainsString("WHERE state = 'leased'", $queries[0]);
        $this->assertStringContainsString('lease_expiry_gmt IS NOT NULL AND lease_expiry_gmt <=', $queries[0]);
    }

    public function testRetryDelayHonorsRetryAfter(): void
    {
        $this->assertSame(30, Repository::retryDelay(1, 30));
        $this->assertSame(Repository::MAX_RETRY_DELAY, Repository::retryDelay(1, 99999));
        $this->assertSame(0, Repository::retryDelay(1, -5));
    }

    public function testRetryDelayUsesBoundedExponentialBackoff(): void
    {
        $this->assertSame(2, Repository::retryDelay(1, null, 0));
        $this->assertSame(4, Repository::retryDelay(2, null, 0));
        $this->assertSame(8, Repository::retryDelay(3, null, 0));
        $this->assertSame(Repository::MAX_RETRY_DELAY, Repository::retryDelay(20, null, 0));
    }
}
