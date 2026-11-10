<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Indexing\IndexHooks;
use CherryLink\Indexing\Outbox\Capture;
use CherryLink\Indexing\Outbox\Delivery;
use CherryLink\Support\License;
use CherryLink\Support\Options;
use Mockery;
use PHPUnit\Framework\TestCase;
use WP_Post;

/**
 * The save/delete hook must never perform an API call on the async path, must
 * fall back to the synchronous adapter when async is unavailable, and must keep
 * the dirty marker when a capture fails (fail closed).
 *
 * Options/License are real (they are final services reading WP options); WP
 * itself is stubbed per test.
 *
 * @covers \CherryLink\Indexing\IndexHooks
 */
final class IndexHooksAsyncTest extends TestCase
{
    /** @var array<string,mixed> */
    private $options = [];

    /** @var array<string,mixed> */
    private $updates = [];

    /** @var int Outbound HTTP calls observed by the sync-transport stub. */
    private $httpCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->options = [];
        $this->updates = [];
        $this->httpCalls = 0;

        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('wp_is_post_autosave')->justReturn(false);
        Functions\when('get_post_types')->justReturn([]);
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return \array_key_exists($key, $this->options) ? $this->options[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value, $autoload = null) {
            $this->updates[$key] = $value;
            $this->options[$key] = $value;
            return true;
        });
        Functions\when('delete_option')->alias(function ($key) {
            unset($this->options[$key]);
            return true;
        });
        Functions\when('get_transient')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function post(string $status = 'publish'): WP_Post
    {
        $post = new WP_Post();
        $post->ID = 11;
        $post->post_type = 'post';
        $post->post_status = $status;
        $post->post_title = 'Title';
        $post->post_content = '<p>body</p>';
        $post->post_modified_gmt = '2026-01-01 00:00:00';

        return $post;
    }

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

    private function hooks(
        ?callable $asyncProbe,
        $capture = null,
        $delivery = null,
        ?callable $mutationsProbe = null
    ): IndexHooks {
        return new IndexHooks(
            new Options(),
            $this->license(),
            $capture !== null ? static function () use ($capture) {
                return $capture;
            } : null,
            $delivery !== null ? static function () use ($delivery) {
                return $delivery;
            } : null,
            $asyncProbe,
            $mutationsProbe ?? static function (): bool {
                return true;
            }
        );
    }

    private function defineApiConstants(): void
    {
        if (! \defined('CHERRY_VECTOR_API_URL')) {
            \define('CHERRY_VECTOR_API_URL', 'https://api.example.test');
        }
        if (! \defined('CL_ALT_API_PORT')) {
            \define('CL_ALT_API_PORT', 8443);
        }
    }

    private function asyncTrue(): callable
    {
        return static function (): bool {
            return true;
        };
    }

    public function testAsyncSaveCapturesEventAndNeverCallsApi(): void
    {
        Functions\expect('wp_remote_request')->never();
        Functions\expect('get_permalink')->never();
        Functions\expect('get_post_meta')->never();

        $capture = Mockery::mock(Capture::class);
        $capture->shouldReceive('tryCapture')->once()->with(11)->andReturn(5);
        $capture->shouldReceive('tryDelete')->never();
        $delivery = Mockery::mock(Delivery::class);
        $delivery->shouldReceive('schedule')->once()->andReturn(true);

        $this->hooks($this->asyncTrue(), $capture, $delivery)->onSave(11, $this->post('publish'), true);

        $this->assertArrayNotHasKey(Options::INDEX_DIRTY, $this->updates);
    }

    public function testAsyncUnpublishCapturesTombstone(): void
    {
        Functions\expect('wp_remote_request')->never();

        $capture = Mockery::mock(Capture::class);
        $capture->shouldReceive('tryCapture')->once()->with(11)->andReturn(6);
        $capture->shouldReceive('tryDelete')->never();
        $delivery = Mockery::mock(Delivery::class);
        $delivery->shouldReceive('schedule')->once()->andReturn(true);

        $this->hooks($this->asyncTrue(), $capture, $delivery)->onSave(11, $this->post('draft'), true);

        $this->assertArrayNotHasKey(Options::INDEX_DIRTY, $this->updates);
    }

    public function testAsyncDeleteCapturesTombstoneAndSchedules(): void
    {
        Functions\expect('wp_remote_request')->never();

        $capture = Mockery::mock(Capture::class);
        $capture->shouldReceive('tryDelete')->once()->with(11)->andReturn(7);
        $delivery = Mockery::mock(Delivery::class);
        $delivery->shouldReceive('schedule')->once()->andReturn(true);

        $this->hooks($this->asyncTrue(), $capture, $delivery)->onDelete(11);

        $this->assertArrayNotHasKey(Options::INDEX_DIRTY, $this->updates);
    }

    public function testFailedCaptureKeepsDirtyMarker(): void
    {
        $capture = Mockery::mock(Capture::class);
        $capture->shouldReceive('tryCapture')->once()->andReturn(null);
        $delivery = Mockery::mock(Delivery::class);
        $delivery->shouldReceive('schedule')->never();

        $this->hooks($this->asyncTrue(), $capture, $delivery)->onSave(11, $this->post('publish'), true);

        $this->assertArrayHasKey(Options::INDEX_DIRTY, $this->updates);
    }

    public function testDisabledAsyncFallsBackToSynchronousAdapter(): void
    {
        $this->stubSyncTransport();

        $capture = Mockery::mock(Capture::class);
        $capture->shouldReceive('tryCapture')->never();
        $delivery = Mockery::mock(Delivery::class);
        $delivery->shouldReceive('schedule')->never();

        $probe = static function (): bool {
            return false;
        };
        $this->hooks($probe, $capture, $delivery)->onSave(11, $this->post('publish'), true);

        $this->assertGreaterThan(0, $this->httpCalls, 'sync fallback should call the API');
    }

    public function testUnlicensedSaveOnlyMarksDirty(): void
    {
        Functions\expect('wp_remote_request')->never();

        $hooks = new IndexHooks(
            new Options(),
            $this->license(false),
            static function () {
                return Mockery::mock(Capture::class);
            },
            static function () {
                return Mockery::mock(Delivery::class);
            },
            $this->asyncTrue(),
            static function (): bool {
                return true;
            }
        );

        $hooks->onSave(11, $this->post('publish'), true);

        $this->assertArrayHasKey(Options::INDEX_DIRTY, $this->updates);
    }

    public function testProbeFailureFallsBackToSync(): void
    {
        $this->stubSyncTransport();

        $probe = static function (): bool {
            throw new \RuntimeException('capability probe failed');
        };
        $capture = Mockery::mock(Capture::class);
        $capture->shouldReceive('tryCapture')->never();

        $this->hooks($probe, $capture, Mockery::mock(Delivery::class))->onSave(11, $this->post('publish'), true);

        $this->assertGreaterThan(0, $this->httpCalls, 'probe failure must fail closed to sync');
    }

    public function testDefaultProbeNeverFetchesCapabilitiesDuringSave(): void
    {
        $this->defineApiConstants();
        $urls = [];
        Functions\when('get_permalink')->justReturn('https://site/11');
        Functions\when('wp_strip_all_tags')->returnArg();
        Functions\when('home_url')->justReturn('https://site');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_headers')->justReturn([]);
        Functions\when('wp_remote_retrieve_body')->justReturn('{}');
        Functions\when('wp_parse_url')->justReturn('site');
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_request')->alias(function ($url) use (&$urls) {
            $urls[] = $url;
            return [];
        });

        // No injected probe: IndexHooks uses the real, cache-only Capabilities.
        $hooks = new IndexHooks(new Options(), $this->license());
        $hooks->onSave(11, $this->post('publish'), true);

        foreach ($urls as $url) {
            $this->assertStringNotContainsString('/v1/capabilities', $url);
        }
        $this->assertNotEmpty($urls, 'cold cache must fall back to the synchronous adapter');
    }

    public function testEnrollmentKeepsDirtyMarkerAndQueuesNoMutation(): void
    {
        // Tenant is async but the API reports mutations=false (enrollment /
        // snapshot required). A live mutation would be rejected, so we must not
        // queue one; the marker stays until the snapshot runs.
        Functions\expect('wp_remote_request')->never();

        $capture = Mockery::mock(Capture::class);
        $capture->shouldReceive('tryCapture')->never();
        $delivery = Mockery::mock(Delivery::class);
        $delivery->shouldReceive('schedule')->never();

        $hooks = $this->hooks(
            $this->asyncTrue(),
            $capture,
            $delivery,
            static function (): bool {
                return false;   // mutations not available yet
            }
        );

        $hooks->onSave(11, $this->post('publish'), true);

        $this->assertArrayHasKey(Options::INDEX_DIRTY, $this->updates);
    }

    public function testEmergencyDisableAlsoDefersLiveMutations(): void
    {
        $capture = Mockery::mock(Capture::class);
        $capture->shouldReceive('tryCapture')->never();
        $delivery = Mockery::mock(Delivery::class);
        $delivery->shouldReceive('schedule')->never();

        $this->hooks($this->asyncTrue(), $capture, $delivery, static function (): bool {
            return false;
        })->onDelete(11);

        $this->assertArrayHasKey(Options::INDEX_DIRTY, $this->updates);
    }

    /** Stub the WP functions the synchronous path needs and count outbound calls. */
    private function stubSyncTransport(): void
    {
        $this->defineApiConstants();
        Functions\when('get_permalink')->justReturn('https://site/11');
        Functions\when('wp_strip_all_tags')->returnArg();
        Functions\when('home_url')->justReturn('https://site');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_headers')->justReturn([]);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"count":0}');
        Functions\when('wp_parse_url')->justReturn('site');
        Functions\when('is_wp_error')->justReturn(false);

        Functions\when('wp_remote_request')->alias(function () {
            $this->httpCalls++;
            return [];
        });
    }
}
