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

    /** @var array<string,mixed> */
    private $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->transient = null;
        $this->options = [];

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

    public function testForgetDropsOnlyTheSnapshotAndKeepsTheTenantMode(): void
    {
        $this->transient = ['async' => true];
        $this->options[Capabilities::STICKY_OPTION] = 123;
        $capabilities = new Capabilities($this->clientExpecting(0));

        $this->assertTrue($capabilities->asyncEnabled());
        $capabilities->forget();

        // Plugin update / activation must not push an async tenant onto sync writes.
        $this->assertTrue($capabilities->asyncEnabled());
        $this->assertTrue($capabilities->stickyAsync());
    }

    public function testForgetTenantDropsBothSnapshotAndMode(): void
    {
        $this->transient = ['async' => true];
        $this->options[Capabilities::STICKY_OPTION] = 123;
        $capabilities = new Capabilities($this->clientExpecting(0));

        $capabilities->forgetTenant();

        $this->assertFalse($capabilities->asyncEnabled());
        $this->assertFalse($capabilities->stickyAsync());
    }

    public function testExpiredSnapshotKeepsAnEstablishedAsyncTenant(): void
    {
        // Snapshot expired (transient gone) but the tenant was observed async.
        $this->transient = null;
        $this->options[Capabilities::STICKY_OPTION] = 123;

        $this->assertTrue(
            (new Capabilities($this->clientExpecting(0)))->asyncEnabled(),
            'cache expiry must not move an async tenant back to synchronous writes'
        );
    }

    public function testExplicitNegativeAnswerClearsStickyMode(): void
    {
        $this->options[Capabilities::STICKY_OPTION] = 123;
        $this->transient = ['async' => false, 'tenant_mode' => 'sync'];

        $capabilities = new Capabilities($this->clientExpecting(0));

        $this->assertFalse($capabilities->asyncEnabled());
        $this->assertFalse($capabilities->stickyAsync());
    }

    public function testFailedRefreshWithExpiredCacheKeepsTheAsyncTenant(): void
    {
        // The reviewer's repro: sticky async, cache expired, refresh fails.
        $this->transient = null;
        $this->options[Capabilities::STICKY_OPTION] = 123;

        $client = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('capabilities')->once()->andReturn([
            'ok' => false, 'status' => 0, 'retry_after' => null, 'error' => 'timeout', 'capabilities' => null,
        ]);

        $capabilities = new Capabilities($client);
        $capabilities->refresh();

        $this->assertTrue(
            $capabilities->asyncEnabled(),
            'a failed refresh must not send an async tenant back to synchronous writes'
        );
        $this->assertTrue($capabilities->stickyAsync());
        $this->assertFalse($capabilities->asyncAvailable(), 'delivery must pause during an outage');
    }

    public function testEmergencyDisableKeepsTenantModeButStopsDelivery(): void
    {
        // Operator disables async acceptance; the tenant is still an async tenant.
        $this->transient = [
            'known' => true, 'async' => true, 'available' => false,
            'tenant_mode' => 'async', 'protocol_version' => 1,
        ];

        $capabilities = new Capabilities($this->clientExpecting(0));

        $this->assertTrue($capabilities->asyncEnabled(), 'routing stays async');
        $this->assertFalse($capabilities->asyncAvailable(), 'delivery must pause');
    }

    public function testMutationsAreUnavailableWhileEnrollmentIsIncomplete(): void
    {
        $this->transient = [
            'known' => true, 'async' => true, 'available' => false, 'mutations' => false,
            'tenant_mode' => 'async', 'protocol_version' => 1,
        ];

        $capabilities = new Capabilities($this->clientExpecting(0));

        $this->assertTrue($capabilities->asyncEnabled(), 'still routes through the outbox');
        $this->assertFalse($capabilities->mutationsAvailable(), 'but must not queue live mutations');
    }

    public function testMutationsAvailableOnceTheTenantIsReady(): void
    {
        $this->transient = [
            'known' => true, 'async' => true, 'available' => true,
            'mutations' => true, 'tenant_mode' => 'async',
        ];

        $this->assertTrue((new Capabilities($this->clientExpecting(0)))->mutationsAvailable());
    }

    public function testTenantModeIsAsyncEvenWhileEnrollmentIsIncomplete(): void
    {
        $client = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('capabilities')->once()->andReturn([
            'ok' => true, 'status' => 200, 'retry_after' => null, 'error' => '',
            'capabilities' => [
                'protocol_version' => 1,
                'async_indexing'   => 'enabled',
                'tenant_mode'      => 'async',
                'features'         => ['runs' => false, 'mutations' => false],
                'limits'           => ['batch_items' => 50, 'batch_bytes' => 1048576],
            ],
        ]);

        $snapshot = (new Capabilities($client))->refresh();

        $this->assertTrue($snapshot['async'], 'tenant mode is the routing signal');
        $this->assertFalse($snapshot['available'], 'not yet accepting mutations');
    }

    public function testRefreshRemembersStickyModeForAsyncTenant(): void
    {
        $client = Mockery::mock(AsyncClient::class);
        $client->shouldReceive('capabilities')->once()->andReturn([
            'ok' => true, 'status' => 200, 'retry_after' => null, 'error' => '',
            'capabilities' => [
                'protocol_version' => 1,
                'async_indexing'   => 'enabled',
                'tenant_mode'      => 'async',
                'features'         => ['runs' => true, 'mutations' => true],
                'limits'           => ['batch_items' => 50, 'batch_bytes' => 1048576],
            ],
        ]);

        (new Capabilities($client))->refresh();

        $this->assertArrayHasKey(Capabilities::STICKY_OPTION, $this->options);
    }
}
