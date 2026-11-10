<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Api\Client;
use CherryLink\Indexing\Outbox\Capture;
use CherryLink\Indexing\Outbox\Repository;
use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * Capture-order rule: the revision is allocated BEFORE the post is read, and the
 * immutable payload is persisted with that exact revision.
 *
 * @covers \CherryLink\Indexing\Outbox\Capture
 */
final class OutboxCaptureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('clean_post_cache')->justReturn(null);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return \Mockery\MockInterface */
    private function repositoryMock()
    {
        $repo = Mockery::mock(Repository::class);
        $repo->shouldReceive('sourceEpoch')->andReturn('epoch-1')->byDefault();

        return $repo;
    }

    /** @return \Mockery\MockInterface */
    private function clientMock()
    {
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('capture_payload')->andReturn([
            'post_id' => 11, 'title' => 'T', 'text' => 'Body', 'url' => 'http://x/11',
            'lang' => 'ru', 'content_hash' => 'h', 'modified_gmt' => '2026-01-01 00:00:00',
            'link_html' => '<p>x</p>',
        ]);

        return $client;
    }

    public function testUpsertAllocatesRevisionBeforeRereadingThePostAndPassesItToEnqueue(): void
    {
        $stale = new WP_Post();
        $stale->ID = 11;
        $stale->post_status = 'publish';

        $fresh = new WP_Post();
        $fresh->ID = 11;
        $fresh->post_status = 'publish';

        $order = [];
        $repo = $this->repositoryMock();
        $repo->shouldReceive('allocateRevision')->once()->andReturnUsing(function () use (&$order) {
            $order[] = 'allocate';
            return 42;
        });
        // The hook hands over an already-loaded WP_Post; Capture must re-read it.
        Functions\when('get_post')->alias(function ($id) use (&$order, $fresh) {
            $order[] = 'reload';
            return $fresh;
        });
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('capture_payload')->once()->with(Mockery::on(static function ($p) use ($fresh) {
            return $p === $fresh;
        }))->andReturnUsing(function () use (&$order) {
            $order[] = 'capture';
            return ['post_id' => 11, 'title' => 'T', 'text' => 'Body', 'url' => 'u', 'lang' => 'ru',
                    'content_hash' => 'h', 'modified_gmt' => 'm', 'link_html' => '<p>x</p>'];
        });
        $repo->shouldReceive('enqueue')->once()->with(
            Mockery::type('string'),
            Repository::KIND_UPSERT,
            11,
            Mockery::type('array'),
            null,
            42
        )->andReturn(9);

        $id = (new Capture($repo, $client))->upsert($stale);

        $this->assertSame(9, $id);
        $this->assertSame(
            ['allocate', 'reload', 'capture'],
            $order,
            'the revision must be allocated before the post is re-read'
        );
    }

    public function testUpsertFallsBackToATombstoneWhenThePostIsNoLongerPublished(): void
    {
        $stale = new WP_Post();
        $stale->ID = 11;
        $stale->post_status = 'publish';

        $draft = new WP_Post();
        $draft->ID = 11;
        $draft->post_status = 'draft';

        $repo = $this->repositoryMock();
        $repo->shouldReceive('allocateRevision')->once()->andReturn(44);
        Functions\when('get_post')->justReturn($draft);
        $repo->shouldReceive('enqueue')->once()->with(
            Mockery::type('string'),
            Repository::KIND_DELETE,
            11,
            ['post_id' => 11, 'operation' => 'delete'],
            null,
            44
        )->andReturn(12);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('capture_payload')->never();

        $this->assertSame(12, (new Capture($repo, $client))->upsert($stale));
    }

    public function testDeletePersistsMinimalTombstonePayload(): void
    {
        $repo = $this->repositoryMock();
        $repo->shouldReceive('allocateRevision')->once()->andReturn(43);
        $repo->shouldReceive('enqueue')->once()->with(
            Mockery::type('string'),
            Repository::KIND_DELETE,
            11,
            ['post_id' => 11, 'operation' => 'delete'],
            null,
            43
        )->andReturn(10);

        $id = (new Capture($repo, $this->clientMock()))->delete(11);

        $this->assertSame(10, $id);
    }

    public function testRunMarkerAllocatesItsOwnOlderRevision(): void
    {
        $repo = $this->repositoryMock();
        $repo->shouldReceive('allocateRevision')->once()->andReturn(50);
        $repo->shouldReceive('enqueue')->once()->with(
            Mockery::type('string'),
            Repository::KIND_SNAPSHOT_MARKER,
            0,
            Mockery::on(static function (array $payload): bool {
                return $payload['client_run_key'] === 'run-key'
                    && $payload['mode'] === 'snapshot'
                    && $payload['expected_membership_count'] === 3
                    && \strlen($payload['expected_membership_digest']) === 64;
            }),
            null,
            50
        )->andReturn(11);

        $id = (new Capture($repo, $this->clientMock()))->allocateRunMarker('run-key', 'snapshot', 3, \str_repeat('a', 64));

        $this->assertSame(11, $id);
    }

    public function testEventKeysAreStableForTheSameRevisionAndPost(): void
    {
        $keys = [];
        $repo = $this->repositoryMock();
        $repo->shouldReceive('allocateRevision')->andReturn(60, 61);
        $repo->shouldReceive('enqueue')->andReturnUsing(function ($key) use (&$keys) {
            $keys[] = $key;
            return 1;
        });

        $capture = new Capture($repo, $this->clientMock());
        $capture->delete(11);
        $capture->delete(11);

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1], 'different revisions must yield different keys');
        $this->assertMatchesRegularExpression('/^ev_[0-9a-f]{32}$/', $keys[0]);
    }

    public function testTryUpsertFailsClosedOnStorageError(): void
    {
        $post = new WP_Post();
        $post->ID = 11;
        $post->post_status = 'publish';

        $fresh = new WP_Post();
        $fresh->ID = 11;
        $fresh->post_status = 'publish';
        Functions\when('get_post')->justReturn($fresh);

        $repo = $this->repositoryMock();
        $repo->shouldReceive('allocateRevision')->once()->andReturn(70);
        $repo->shouldReceive('enqueue')->once()->andThrow(new \RuntimeException('db down'));

        $this->assertNull((new Capture($repo, $this->clientMock()))->tryUpsert($post));
    }
}
