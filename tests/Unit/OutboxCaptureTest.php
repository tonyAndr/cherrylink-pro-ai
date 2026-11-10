<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Api\Client;
use CherryLink\Indexing\Outbox\Capture;
use CherryLink\Indexing\Outbox\Repository;
use CherryLink\Indexing\Outbox\CaptureReadException;
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
    /** @var array<string,mixed>|null */
    private $authoritative = [
        'post_status'       => 'publish',
        'post_modified_gmt' => '2026-01-01 00:00:00',
        'post_title'        => 'T',
        'post_content'      => 'Body',
    ];

    /** @var array<string,mixed> */
    private $meta = [];

    /** @var string[] */
    private $fields = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('clean_post_cache')->justReturn(null);
    }

    private function capture(\Mockery\MockInterface $repo, Client $client): Capture
    {
        return new Capture(
            $repo,
            $client,
            null,
            function (int $id): ?array {
                return $this->authoritative;
            },
            function (int $id, array $fields): array {
                return $this->meta;
            },
            function (): array {
                return $this->fields;
            }
        );
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
        $client->shouldReceive('capture_payload_from_row')->andReturn([
            'post_id' => 11, 'title' => 'T', 'text' => 'Body', 'url' => 'http://x/11',
            'lang' => 'ru', 'content_hash' => 'h', 'modified_gmt' => '2026-01-01 00:00:00',
            'link_html' => '<p>x</p>',
        ]);
        $client->shouldReceive('configured_meta_fields')->andReturn([])->byDefault();

        return $client;
    }

    public function testUpsertAllocatesRevisionBeforeTheAuthoritativeReadAndPassesItToEnqueue(): void
    {
        $stale = new WP_Post();
        $stale->ID = 11;
        $stale->post_status = 'publish';

        $order = [];
        $repo = $this->repositoryMock();
        $repo->shouldReceive('allocateRevision')->once()->andReturnUsing(function () use (&$order) {
            $order[] = 'allocate';
            return 42;
        });

        $reader = function (int $id) use (&$order) {
            $order[] = 'read';
            return [
                'post_status'       => 'publish',
                'post_modified_gmt' => '2026-01-01 00:00:00',
                'post_title'        => 'Fresh title',
                'post_content'      => 'Fresh body',
            ];
        };

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('configured_meta_fields')->andReturn([]);
        $client->shouldReceive('capture_payload_from_row')->once()->with(
            Mockery::on(static function (array $row): bool {
                return $row['post_id'] === 11
                    && $row['post_content'] === 'Fresh body'
                    && $row['post_title'] === 'Fresh title';
            }),
            []
        )->andReturn(['post_id' => 11, 'title' => 'Fresh title', 'text' => 'Fresh body',
                       'url' => 'u', 'lang' => 'ru', 'content_hash' => 'h',
                       'modified_gmt' => '2026-01-01 00:00:00', 'link_html' => 'Fresh body']);

        $repo->shouldReceive('enqueue')->once()->with(
            Mockery::type('string'),
            Repository::KIND_UPSERT,
            11,
            Mockery::type('array'),
            null,
            42
        )->andReturn(9);

        $capture = new Capture($repo, $client, null, $reader, static function (): array {
            return [];
        }, static function (): array {
            return [];
        });

        $id = $capture->upsert($stale);

        $this->assertSame(9, $id);
        $this->assertSame(['allocate', 'read'], $order, 'the revision must be allocated before the read');
        // The stale in-memory object is never used for content.
        $this->assertSame('publish', $stale->post_status);
    }

    public function testTwoSavesInTheSameSecondProduceDifferentPayloads(): void
    {
        // Regression: status + post_modified_gmt can be identical while the text differs.
        $payloads = [];
        $repo = $this->repositoryMock();
        $repo->shouldReceive('allocateRevision')->andReturn(1, 2);
        $repo->shouldReceive('enqueue')->andReturnUsing(static function ($key, $kind, $postId, $payload) use (&$payloads) {
            $payloads[] = $payload;
            return 1;
        });

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('configured_meta_fields')->andReturn([]);
        $client->shouldReceive('capture_payload_from_row')->andReturnUsing(static function (array $row): array {
            return ['text' => $row['post_content'], 'modified_gmt' => $row['post_modified_gmt']];
        });

        $post = new WP_Post();
        $post->ID = 11;
        $post->post_status = 'publish';

        $bodies = ['first body', 'second body'];
        $index  = 0;
        $capture = new Capture(
            $repo,
            $client,
            null,
            function (int $id) use (&$index, $bodies): array {
                $content = $bodies[$index++];
                return [
                    'post_status'       => 'publish',
                    'post_modified_gmt' => '2026-01-01 00:00:00',   // identical
                    'post_title'        => 'T',
                    'post_content'      => $content,
                ];
            },
            static function (): array {
                return [];
            },
            static function (): array {
                return [];
            }
        );

        $capture->upsert($post);
        $capture->upsert($post);

        $this->assertNotSame($payloads[0]['text'], $payloads[1]['text']);
    }

    public function testReadingThePostAgainIsNotRequiredBecausePayloadIsAuthoritative(): void
    {
        // get_post() must not be consulted at all for content.
        Functions\expect('get_post')->never();

        $post = new WP_Post();
        $post->ID = 11;
        $post->post_status = 'publish';

        $repo = $this->repositoryMock();
        $repo->shouldReceive('allocateRevision')->once()->andReturn(3);
        $repo->shouldReceive('enqueue')->once()->andReturn(4);

        (new Capture(
            $repo,
            $this->clientMock(),
            null,
            function (): array {
                return ['post_status' => 'publish', 'post_modified_gmt' => 'm',
                        'post_title' => 'T', 'post_content' => 'Body'];
            },
            static function (): array {
                return [];
            },
            static function (): array {
                return [];
            }
        ))->upsert($post);

        $this->assertTrue(true);
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

        $id = $this->capture($repo, $this->clientMock())->delete(11);

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

        $id = $this->capture($repo, $this->clientMock())->allocateRunMarker('run-key', 'snapshot', 3, \str_repeat('a', 64));

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

        $capture = $this->capture($repo, $this->clientMock());
        $capture->delete(11);
        $capture->delete(11);

        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1], 'different revisions must yield different keys');
        $this->assertMatchesRegularExpression('/^ev_[0-9a-f]{32}$/', $keys[0]);
    }

    public function testReadFailureIsNotTreatedAsDeletion(): void
    {
        // A failed read must never tombstone a live article.
        $post = new WP_Post();
        $post->ID = 11;
        $post->post_status = 'publish';

        $repo = $this->repositoryMock();
        $repo->shouldReceive('allocateRevision')->once()->andReturn(80);
        $repo->shouldReceive('enqueue')->never();

        $reader = function (int $id): ?array {
            throw new CaptureReadException('database is offline');
        };

        $capture = new Capture($repo, $this->clientMock(), null, $reader, static function (): array {
            return [];
        }, static function (): array {
            return [];
        });

        $this->expectException(CaptureReadException::class);
        $capture->upsert($post);
    }

    public function testTryCaptureReturnsNullOnReadFailureSoTheCallerKeepsItsMarker(): void
    {
        $post = new WP_Post();
        $post->ID = 11;

        $repo = $this->repositoryMock();
        $repo->shouldReceive('allocateRevision')->once()->andReturn(81);
        $repo->shouldReceive('enqueue')->never();

        $reader = function (int $id): ?array {
            throw new CaptureReadException('database is offline');
        };

        $capture = new Capture($repo, $this->clientMock(), null, $reader, static function (): array {
            return [];
        }, static function (): array {
            return [];
        });

        $this->assertNull($capture->tryUpsert($post));
    }

    public function testTryUpsertFailsClosedOnStorageError(): void
    {
        $post = new WP_Post();
        $post->ID = 11;
        $post->post_status = 'publish';

        $fresh = new WP_Post();
        $fresh->ID = 11;
        $fresh->post_status = 'publish';
        $fresh->post_modified_gmt = '2026-01-01 00:00:00';
        Functions\when('get_post')->justReturn($fresh);

        $repo = $this->repositoryMock();
        $repo->shouldReceive('allocateRevision')->once()->andReturn(70);
        $repo->shouldReceive('enqueue')->once()->andThrow(new \RuntimeException('db down'));

        $this->assertNull($this->capture($repo, $this->clientMock())->tryUpsert($post));
    }
}
