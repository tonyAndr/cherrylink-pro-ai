<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Api\AsyncClient;
use CherryLink\Support\Capabilities;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Capability negotiation must never perform HTTP from the save path; only the
 * explicit background refresh may probe the API.
 *
 * @covers \CherryLink\Support\Capabilities
 */
final class CapabilitiesTest extends TestCase
{
    /** @var array<string,mixed>|null */
    private $transient = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->transient = null;

        Functions\when('get_transient')->alias(function () {
            return $this->transient;
        });
        Functions\when('set_transient')->alias(function ($key, $value, $ttl = 0) {
            $this->transient = $value;
            return true;
        });
        Functions\when('delete_transient')->alias(function () {
            $this->transient = null;
            return true;
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function clientExpecting(int $calls): AsyncClient
    {
        $client = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('capabilities')->times($calls);

        return $client;
    }

    public function testColdCacheIsSyncWithoutAnyHttp(): void
    {
        $capabilities = new Capabilities($this->clientExpecting(0));

        $this->assertFalse($capabilities->asyncEnabled());
        $this->assertNull($capabilities->snapshot());
    }

    public function testRefreshCachesEnabledAsyncTenant(): void
    {
        $client = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('capabilities')->once()->andReturn([
            'ok'           => true,
            'status'       => 200,
            'retry_after'  => null,
            'error'        => '',
            'capabilities' => [
                'protocol_version' => 1,
                'async_indexing'   => 'enabled',
                'tenant_mode'      => 'async',
                'features'         => ['runs' => true, 'mutations' => true],
                'limits'           => ['batch_items' => 50, 'batch_bytes' => 1048576],
            ],
        ]);

        $capabilities = new Capabilities($client);
        $snapshot = $capabilities->refresh();

        $this->assertTrue($snapshot['async']);
        $this->assertTrue($capabilities->asyncEnabled());
        $this->assertSame(1, $snapshot['protocol_version']);
    }

    public function testAsyncRequiresBothAsyncModeAndFeatures(): void
    {
        $client = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('capabilities')->once()->andReturn([
            'ok' => true, 'status' => 200, 'retry_after' => null, 'error' => '',
            'capabilities' => [
                'protocol_version' => 1,
                'async_indexing'   => 'enabled',
                'tenant_mode'      => 'sync',            // still sync mode
                'features'         => ['runs' => true, 'mutations' => true],
                'limits'           => ['batch_items' => 50, 'batch_bytes' => 1048576],
            ],
        ]);

        $snapshot = (new Capabilities($client))->refresh();

        $this->assertFalse($snapshot['async']);
    }

    public function testCapabilityProbeWithoutProtocolIsNegative(): void
    {
        $client = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('capabilities')->once()->andReturn([
            'ok' => false, 'status' => 404, 'retry_after' => null, 'error' => 'HTTP 404', 'capabilities' => null,
        ]);

        $snapshot = (new Capabilities($client))->refresh();

        $this->assertFalse($snapshot['async']);
        $this->assertSame(0, $snapshot['protocol_version']);
    }

    public function testTransientOutageDoesNotDowngradeKnownAsyncTenant(): void
    {
        $this->transient = ['async' => true, 'protocol_version' => 1, 'tenant_mode' => 'async'];

        $client = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('capabilities')->once()->andReturn([
            'ok' => false, 'status' => 0, 'retry_after' => null, 'error' => 'timeout', 'capabilities' => null,
        ]);

        $snapshot = (new Capabilities($client))->refresh();

        $this->assertTrue($snapshot['async'], 'last good async state must survive an outage');
        $this->assertTrue((new Capabilities($this->clientExpecting(0)))->asyncEnabled());
    }

    public function testForgetDropsTheSnapshot(): void
    {
        $this->transient = ['async' => true];
        $capabilities = new Capabilities($this->clientExpecting(0));

        $this->assertTrue($capabilities->asyncEnabled());
        $capabilities->forget();
        $this->assertFalse($capabilities->asyncEnabled());
    }
}
