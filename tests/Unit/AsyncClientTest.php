<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Api\AsyncClient;
use CherryLink\Api\Client;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the async client matches the shipped backend contract exactly:
 * paths, bodies, protocol version, and the meaning of a 2xx/`202`.
 *
 * @covers \CherryLink\Api\AsyncClient
 */
final class AsyncClientTest extends TestCase
{
    /** @var array<int,array{method:string,path:string,body:array}> */
    private $calls = [];

    /** @var array<string,mixed> */
    private $next = ['ok' => true, 'status' => 202, 'headers' => [], 'body' => ['status' => 'accepted'], 'error' => '', 'retry_after' => null];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->calls = [];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Build a Client whose transport is stubbed (no HTTP). */
    private function client(): Client
    {
        $test = $this;
        $transport = Mockery::mock(Client::class);
        $transport->shouldReceive('request_result')->andReturnUsing(
            static function (string $method, string $path, array $body = [], int $timeout = 0) use ($test) {
                $test->recordCall($method, $path, $body);
                return $test->nextResult();
            }
        );

        return $transport;
    }

    public function recordCall(string $method, string $path, array $body): void
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];
    }

    /** @return array<string,mixed> */
    public function nextResult(): array
    {
        return $this->next;
    }

    public function testCapabilitiesCallsTheAdvertisedRoute(): void
    {
        $this->next = [
            'ok' => true, 'status' => 200, 'headers' => [], 'error' => '', 'retry_after' => null,
            'body' => [
                'protocol_version' => 1,
                'async_indexing'   => 'disabled',
                'tenant_mode'      => 'sync',
                'features'         => ['runs' => false, 'mutations' => false],
                'limits'           => ['batch_items' => 50, 'batch_bytes' => 1048576],
            ],
        ];

        $result = (new AsyncClient($this->client()))->capabilities();

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['capabilities']['protocol_version']);
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertSame('/v1/capabilities', $this->calls[0]['path']);
    }

    public function testCreateRunSendsProtocolVersionAndSourceMarker(): void
    {
        $this->next = [
            'ok' => true, 'status' => 202, 'headers' => [], 'error' => '', 'retry_after' => null,
            'body' => ['status' => 'accepted', 'run_id' => 'run-1', 'generation' => 3, 'mode' => 'snapshot', 'state' => 'accepting', 'replayed' => false],
        ];

        $result = (new AsyncClient($this->client()))->createRun([
            'client_run_key'  => 'key-1',
            'mode'            => 'snapshot',
            'source_epoch'    => 'epoch-1',
            'source_revision' => 10,
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame('run-1', $result['run_id']);
        $this->assertSame('/v1/index-runs', $this->calls[0]['path']);
        $this->assertSame(AsyncClient::PROTOCOL_VERSION, $this->calls[0]['body']['protocol_version']);
        $this->assertSame('key-1', $this->calls[0]['body']['client_run_key']);
    }

    public function testAppendDocumentsUsesRunScopedBatchRoute(): void
    {
        $this->next = [
            'ok' => true, 'status' => 202, 'headers' => [], 'error' => '', 'retry_after' => null,
            'body' => ['status' => 'accepted', 'run_id' => 'run-1', 'batch_id' => 'b-1', 'accepted_items' => ['i-1', 'i-2'], 'accepted_count' => 2, 'replayed' => false],
        ];

        $result = (new AsyncClient($this->client()))->appendDocuments('run-1', 'b-1', [
            ['post_id' => 1, 'source_epoch' => 'e', 'source_revision' => 1, 'text' => 'a'],
            ['post_id' => 2, 'source_epoch' => 'e', 'source_revision' => 2, 'text' => 'b'],
        ]);

        $this->assertSame('/v1/index-runs/run-1/documents', $this->calls[0]['path']);
        $this->assertSame('b-1', $this->calls[0]['body']['batch_id']);
        $this->assertSame(['i-1', 'i-2'], $result['accepted_items']);
        $this->assertSame(2, $result['accepted_count']);
        $this->assertFalse($result['replayed']);
    }

    public function testAppendDeletesUsesDeletesRoute(): void
    {
        $this->next = [
            'ok' => true, 'status' => 202, 'headers' => [], 'error' => '', 'retry_after' => null,
            'body' => ['status' => 'accepted', 'accepted_items' => ['i-9'], 'accepted_count' => 1],
        ];

        (new AsyncClient($this->client()))->appendDeletes('run-1', 'd-1', [
            ['post_id' => 3, 'source_epoch' => 'e', 'source_revision' => 5],
        ]);

        $this->assertSame('/v1/index-runs/run-1/deletes', $this->calls[0]['path']);
        $this->assertSame('d-1', $this->calls[0]['body']['batch_id']);
    }

    public function testMutationsCarriesStableMutationKey(): void
    {
        $this->next = [
            'ok' => true, 'status' => 202, 'headers' => [], 'error' => '', 'retry_after' => null,
            'body' => ['status' => 'accepted', 'mutation_group' => 'g-1', 'accepted_items' => ['i-7'], 'accepted_count' => 1],
        ];

        (new AsyncClient($this->client()))->mutations('ev_abc', [
            ['post_id' => 4, 'operation' => 'delete', 'source_epoch' => 'e', 'source_revision' => 6],
        ]);

        $this->assertSame('/v1/index-mutations', $this->calls[0]['path']);
        $this->assertSame('ev_abc', $this->calls[0]['body']['mutation_key']);
        $this->assertSame('delete', $this->calls[0]['body']['documents'][0]['operation']);
    }

    public function testRunStatusAndLifecycleRoutes(): void
    {
        $this->next = [
            'ok' => true, 'status' => 200, 'headers' => [], 'error' => '', 'retry_after' => null,
            'body' => ['protocol_version' => 1, 'run_id' => 'run-1', 'state' => 'completed', 'items' => 3, 'vectors_applied' => 3],
        ];
        (new AsyncClient($this->client()))->runStatus('run-1');

        $this->next = ['ok' => true, 'status' => 202, 'headers' => [], 'error' => '', 'retry_after' => null,
            'body' => ['status' => 'accepted', 'run_id' => 'run-1', 'state' => 'paused']];
        (new AsyncClient($this->client()))->pauseRun('run-1');

        $this->assertSame('/v1/index-runs/run-1', $this->calls[0]['path']);
        $this->assertSame('GET', $this->calls[0]['method']);
        $this->assertSame('/v1/index-runs/run-1/pause', $this->calls[1]['path']);
    }

    public function testItemsBuildsRepeatedQueryParameters(): void
    {
        $this->next = ['ok' => true, 'status' => 200, 'headers' => [], 'error' => '', 'retry_after' => null,
            'body' => ['protocol_version' => 1, 'items' => [], 'missing_item_ids' => []]];

        (new AsyncClient($this->client()))->items(['a', 'b']);

        $this->assertStringStartsWith('/v1/index-items?', $this->calls[0]['path']);
        $this->assertStringContainsString('item_id=a', $this->calls[0]['path']);
        $this->assertStringContainsString('item_id=b', $this->calls[0]['path']);
    }

    public function testItemsWithNoIdsMakesNoRequest(): void
    {
        $result = (new AsyncClient($this->client()))->items([]);

        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['items']);
        $this->assertCount(0, $this->calls);
    }

    public function testA202IsAcceptanceNotCompletionAndNotAJsonBodyIsAnError(): void
    {
        $this->next = ['ok' => true, 'status' => 202, 'headers' => [], 'error' => '', 'retry_after' => null, 'body' => 'not json'];

        $result = (new AsyncClient($this->client()))->createRun([
            'client_run_key' => 'k', 'mode' => 'incremental', 'source_epoch' => 'e', 'source_revision' => 1,
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(202, $result['status']);
    }

    public function testBlankAcceptedItemIdIsNotAnAcknowledgement(): void
    {
        $this->next = [
            'ok' => true, 'status' => 202, 'headers' => [], 'error' => '', 'retry_after' => null,
            'body' => ['status' => 'accepted', 'accepted_items' => [''], 'accepted_count' => 1, 'replayed' => false],
        ];

        $result = (new AsyncClient($this->client()))->mutations('ev_1', [
            ['post_id' => 1, 'source_epoch' => 'e', 'source_revision' => 1],
        ], null, 1);

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid async receipt', $result['error']);
    }

    public function testAcceptedCountMustMatchTheUpload(): void
    {
        $this->next = [
            'ok' => true, 'status' => 202, 'headers' => [], 'error' => '', 'retry_after' => null,
            'body' => ['status' => 'accepted', 'accepted_items' => ['item-1', 'item-2'], 'accepted_count' => 2],
        ];

        // We uploaded one document, so accepting two is not a valid receipt for it.
        $result = (new AsyncClient($this->client()))->mutations('ev_1', [
            ['post_id' => 1, 'source_epoch' => 'e', 'source_revision' => 1],
        ], null, 1);

        $this->assertFalse($result['ok']);
    }

    public function testSealRequiresSealedState(): void
    {
        $this->next = [
            'ok' => true, 'status' => 202, 'headers' => [], 'error' => '', 'retry_after' => null,
            'body' => ['status' => 'accepted', 'run_id' => 'run-1', 'state' => 'accepting'],
        ];

        $result = (new AsyncClient($this->client()))->sealRun('run-1', 'r-1');

        $this->assertFalse($result['ok']);
    }

    public function testConflictIsNotRetryableButRateLimitAndServerErrorsAre(): void
    {
        $this->assertFalse(AsyncClient::isRetryable(['status' => 409, 'error' => 'HTTP 409']));
        $this->assertFalse(AsyncClient::isRetryable(['status' => 422, 'error' => 'HTTP 422']));
        $this->assertTrue(AsyncClient::isRetryable(['status' => 429, 'error' => 'HTTP 429']));
        $this->assertTrue(AsyncClient::isRetryable(['status' => 503, 'error' => 'HTTP 503']));
        $this->assertTrue(AsyncClient::isRetryable(['status' => 0, 'error' => 'timeout']));
        $this->assertFalse(AsyncClient::isRetryable(['status' => 0, 'error' => 'no license key configured']));
    }
}
