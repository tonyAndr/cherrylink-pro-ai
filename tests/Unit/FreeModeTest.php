<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Api\Client;
use CherryLink\Indexing\SyncService;
use CherryLink\Support\Capabilities;
use CherryLink\Support\CapabilitiesRefresh;
use CherryLink\Support\Connect;
use CherryLink\Support\License;
use CherryLink\Support\Options;
use CherryLink\Support\PluginReports;
use PHPUnit\Framework\TestCase;

/**
 * Free plan + Connect: license acceptance of free keys, the connect round trip, the
 * public proof route, and the guarantee that nothing reaches the network before a key exists.
 *
 * @covers \CherryLink\Support\License
 * @covers \CherryLink\Support\Connect
 */
final class FreeModeTest extends TestCase
{
    /** @var array<string,mixed> */
    private $store = [];

    /** @var array<string,mixed> */
    private $transients = [];

    /** @var array<int,array{0:string,1:array<string,mixed>}> */
    private $posts = [];

    /** @var array<int,string> */
    private $scheduled = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        if (!defined('CHERRY_VECTOR_API_URL')) {
            define('CHERRY_VECTOR_API_URL', 'https://seocherry.apov.dev');
        }
        if (!defined('CL_ALT_API_PORT')) {
            define('CL_ALT_API_PORT', 9443);
        }
        if (!defined('CL_ALT_API_IP')) {
            define('CL_ALT_API_IP', '31.129.48.202');
        }
        $this->store = ['linkate-posts' => [], 'linkate_posts_meta' => []];
        $this->transients = [];
        $this->posts = [];
        $this->scheduled = [];

        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->store[$key] = $value;
            return true;
        });
        Functions\when('get_transient')->alias(function ($key) {
            return $this->transients[$key] ?? false;
        });
        Functions\when('set_transient')->alias(function ($key, $value) {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('delete_transient')->alias(function ($key) {
            unset($this->transients[$key]);
            return true;
        });
        Functions\when('wp_cache_delete')->justReturn(true);
        Functions\when('home_url')->justReturn('https://mysite.example');
        Functions\when('wp_parse_url')->alias(static function ($url, $component = -1) {
            return parse_url($url, $component);
        });
        Functions\when('wp_json_encode')->alias(static function ($data) {
            return json_encode($data);
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('rest_url')->alias(static function ($path = '') {
            return 'https://mysite.example/wp-json/' . $path;
        });
        Functions\when('admin_url')->alias(static function ($path = '') {
            return 'https://mysite.example/wp-admin/' . $path;
        });
        Functions\when('wp_generate_password')->justReturn('nonceNONCE1234567890nonceNONCE1234567890');
        Functions\when('wp_clear_scheduled_hook')->justReturn(true);
        Functions\when('wp_schedule_single_event')->alias(function ($time, $hook) {
            $this->scheduled[] = $hook;
            return true;
        });
        Functions\when('is_wp_error')->alias(static function ($v) {
            return $v instanceof \WP_Error;
        });
        Functions\when('wp_remote_retrieve_response_code')->alias(static function ($r) {
            return $r['response']['code'] ?? 0;
        });
        Functions\when('wp_remote_retrieve_body')->alias(static function ($r) {
            return $r['body'] ?? '';
        });
        Functions\when('wp_remote_retrieve_header')->alias(static function ($r, $name) {
            return $r['headers'][$name] ?? '';
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function license(): License
    {
        return new License(new Options());
    }

    private function mockHome(string $body, int $code = 200): void
    {
        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => $code], 'body' => $body]);
    }

    // ── callHome ─────────────────────────────────────────────────────────────

    public function testCallHomeAcceptsFreeKeyWithNullExpiry(): void
    {
        $this->mockHome('{"key_valid":1,"expires_at":null,"key_error_reason":"","plan":"free","post_limit":1000,"features":{}}');

        $response = $this->license()->callHome('key', 'mysite.example');

        $this->assertIsObject($response);
        $this->assertSame('free', $response->plan);
    }

    /** @dataProvider legacyMalformedProvider */
    public function testCallHomeStillRejectsMalformedLegacyResponses(string $body): void
    {
        $this->mockHome($body);
        $this->assertFalse($this->license()->callHome('key', 'mysite.example'));
    }

    /** @return array<string,array{string}> */
    public static function legacyMalformedProvider(): array
    {
        return [
            'null expiry without plan' => ['{"key_valid":1,"expires_at":null}'],
            'null expiry on paid plan' => ['{"key_valid":1,"expires_at":null,"plan":"pro"}'],
            'missing expiry' => ['{"key_valid":1}'],
            'bad expiry' => ['{"key_valid":1,"expires_at":"nope"}'],
        ];
    }

    public function testCallHomeUsesTheOverridableLicensingBase(): void
    {
        $this->assertSame('https://cherrylink.apov.dev/cherrylink-pro-licensing/', License::licensingUrl());
    }

    public function testCallHomeMakesNoRequestWithoutAKey(): void
    {
        Functions\expect('wp_remote_post')->never();
        $this->assertFalse($this->license()->callHome('  ', 'mysite.example'));
    }

    // ── validity ─────────────────────────────────────────────────────────────

    public function testFreeKeyIsValidWithoutExpiryAndPaidExpiredFallbackStaysValid(): void
    {
        $this->store['linkate-posts'] = ['hash_field' => 'k'];
        $this->store['linkate_posts_meta'] = ['key_valid' => true, 'expires_at' => '', 'plan' => 'free'];
        $this->assertTrue($this->license()->isValid());
        $this->assertTrue($this->license()->isConnected());

        // Lapsed paid subscription answers plan free with a past expires_at.
        $this->store['linkate_posts_meta'] = ['key_valid' => true, 'expires_at' => '2020-01-01 00:00:00', 'plan' => 'free', 'paid_expired' => true];
        $this->assertTrue($this->license()->isValid());
    }

    public function testNonFreeKeysStillNeedAFutureExpiry(): void
    {
        $this->store['linkate-posts'] = ['hash_field' => 'k'];
        $this->store['linkate_posts_meta'] = ['key_valid' => true, 'expires_at' => '2020-01-01 00:00:00', 'plan' => 'pro'];
        $this->assertFalse($this->license()->isValid());
        $this->store['linkate_posts_meta'] = ['key_valid' => true, 'expires_at' => '2020-01-01 00:00:00']; // legacy
        $this->assertFalse($this->license()->isValid());
        $this->store['linkate_posts_meta'] = ['key_valid' => true, 'expires_at' => '2099-01-01 00:00:00'];
        $this->assertTrue($this->license()->isValid());
    }

    public function testPlanFieldsArePersistedFromTheDecisionAndClearedForLegacy(): void
    {
        $meta = License::applyDecision([], (object) [
            'plan' => 'free', 'post_limit' => 1000, 'features' => (object) [], 'paid_expired' => true,
        ]);
        $this->assertSame('free', $meta['plan']);
        $this->assertSame(1000, $meta['post_limit']);
        $this->assertTrue($meta['paid_expired']);

        $legacy = License::applyDecision($meta, (object) ['key_valid' => 1]);
        $this->assertArrayNotHasKey('plan', $legacy);
        $this->assertArrayNotHasKey('post_limit', $legacy);
    }

    public function testPostLimitOnlyForValidKeysWithALimit(): void
    {
        $this->store['linkate-posts'] = ['hash_field' => 'k'];
        $this->store['linkate_posts_meta'] = ['key_valid' => true, 'plan' => 'free', 'post_limit' => 1000];
        $this->assertSame(1000, $this->license()->postLimit());
        $this->store['linkate_posts_meta'] = ['key_valid' => true, 'plan' => 'pro', 'post_limit' => null, 'expires_at' => '2099-01-01'];
        $this->assertNull($this->license()->postLimit());
        $this->store['linkate_posts_meta'] = ['key_valid' => true, 'expires_at' => '2099-01-01'];
        $this->assertNull($this->license()->postLimit());
    }

    // ── connect ──────────────────────────────────────────────────────────────

    public function testConnectStoresKeyAndPlanAndExposesTheNonceOnlyWhilePending(): void
    {
        $connect = new Connect($this->license());
        $this->assertInstanceOf(\WP_Error::class, $connect->proof(), 'no connect pending -> 404');

        $seen = null;
        Functions\when('wp_remote_post')->alias(function ($url, $args) use ($connect, &$seen) {
            $this->posts[] = [$url, $args];
            $seen = $connect->proof();
            return ['response' => ['code' => 200], 'body' => '{"license_key":"free-key-1","plan":"free","post_limit":1000,"features":{}}'];
        });

        $result = $connect->connect();

        $this->assertTrue($result['ok']);
        $this->assertSame('https://cherrylink.apov.dev/cherrylink-pro-licensing/connect', $this->posts[0][0]);
        $body = json_decode($this->posts[0][1]['body'], true);
        $this->assertSame('mysite.example', $body['domain']);
        $this->assertSame(['nonce' => $body['nonce']], $seen, 'proof route served the nonce during the call');
        $this->assertInstanceOf(\WP_Error::class, $connect->proof(), 'nonce is gone afterwards');

        $this->assertSame('free-key-1', $this->store['linkate-posts']['hash_field']);
        $meta = $this->store['linkate_posts_meta'];
        $this->assertTrue($meta['key_valid']);
        $this->assertSame('free', $meta['plan']);
        $this->assertSame(1000, $meta['post_limit']);
        $this->assertTrue($this->license()->isLicensed());
        $this->assertContains(Capabilities::REFRESH_HOOK, $this->scheduled);
    }

    public function testConnectSendsHttpsProofUrls(): void
    {
        Functions\when('wp_remote_post')->alias(function ($url, $args) {
            $this->posts[] = [$url, $args];
            return ['response' => ['code' => 422], 'body' => '{}'];
        });
        (new Connect($this->license()))->connect();
        $body = json_decode($this->posts[0][1]['body'], true);
        $this->assertSame([
            'https://mysite.example/wp-json/cherrylink/v1/connect-proof',
            'https://mysite.example/wp-admin/admin-ajax.php?action=cherrylink_connect_proof',
        ], $body['proof_urls']);
    }

    public function testConnectDropsNonHttpsProofUrlsAndOmitsTheFieldWhenNoneRemain(): void
    {
        Functions\when('rest_url')->justReturn('http://mysite.example/wp-json/cherrylink/v1/connect-proof');
        Functions\when('wp_remote_post')->alias(function ($url, $args) {
            $this->posts[] = [$url, $args];
            return ['response' => ['code' => 422], 'body' => '{}'];
        });
        (new Connect($this->license()))->connect();
        $body = json_decode($this->posts[0][1]['body'], true);
        $this->assertSame(['https://mysite.example/wp-admin/admin-ajax.php?action=cherrylink_connect_proof'], $body['proof_urls']);

        Functions\when('admin_url')->justReturn('http://mysite.example/wp-admin/admin-ajax.php');
        (new Connect($this->license()))->connect();
        $body = json_decode($this->posts[1][1]['body'], true);
        $this->assertArrayNotHasKey('proof_urls', $body);
    }

    public function testAjaxProofServesTheNonceOnlyWhilePending(): void
    {
        $sent = [];
        Functions\when('wp_send_json')->alias(static function ($data, $status = null) use (&$sent) {
            $sent[] = [$data, $status];
        });
        $connect = new Connect($this->license());

        $connect->handleAjaxProof();
        $this->assertSame(404, $sent[0][1]);
        $this->assertArrayNotHasKey('nonce', $sent[0][0]);

        $this->transients[Connect::NONCE_TRANSIENT] = 'abc';
        $connect->handleAjaxProof();
        $this->assertSame([['nonce' => 'abc'], null], $sent[1]);
    }

    public function testProofRouteProofShape(): void
    {
        $this->transients[Connect::NONCE_TRANSIENT] = 'abc';
        $this->assertSame(['nonce' => 'abc'], (new Connect($this->license()))->proof());
    }

    /** @dataProvider errorProvider */
    public function testConnectMapsErrors(int $status, string $body, array $headers, string $code, ?int $retry): void
    {
        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => $status], 'body' => $body, 'headers' => $headers]);

        $result = (new Connect($this->license()))->connect();

        $this->assertFalse($result['ok']);
        $this->assertSame($code, $result['code']);
        $this->assertSame($retry, $result['retry_after']);
        $this->assertSame('', (string) ($this->store['linkate-posts']['hash_field'] ?? ''), 'no key stored on failure');
        $this->assertArrayNotHasKey(Connect::NONCE_TRANSIENT, $this->transients);
    }

    /** @return array<string,array{int,string,array<string,string>,string,int|null}> */
    public static function errorProvider(): array
    {
        return [
            'proof failed' => [409, '{"detail":"domain_proof_failed"}', [], 'domain_proof_failed', null],
            'rate limited' => [429, '{"detail":"rate_limited"}', ['retry-after' => '120'], 'rate_limited', 120],
            'invalid domain' => [422, '{"detail":"invalid_domain"}', [], 'invalid_domain', null],
            'unavailable' => [503, '{"detail":"licensing_unavailable"}', [], 'unavailable', null],
            'bad success body' => [200, '{"plan":"free"}', [], 'unavailable', null],
        ];
    }

    public function testConnectTransportErrorIsUnavailable(): void
    {
        Functions\when('wp_remote_post')->justReturn(new \WP_Error('http_request_failed', 'x'));
        $this->assertSame('unavailable', (new Connect($this->license()))->connect()['code']);
    }

    // ── no outbound calls before Connect ─────────────────────────────────────

    public function testNothingReachesTheNetworkWithoutAKey(): void
    {
        Functions\expect('wp_remote_request')->never();
        Functions\expect('wp_remote_post')->never();
        Functions\expect('wp_remote_get')->never();
        Functions\expect('wp_safe_remote_request')->never();

        $license = $this->license();
        $license->runDailyCheck();
        $license->runScheduledValidation();
        $this->assertFalse($license->validate());

        (new CapabilitiesRefresh($license, new Capabilities()))->run();

        $reports = new PluginReports($license);
        $reports->recordActivation();
        $reports->sweep();
        $reports->send();

        $client = new Client();
        $this->assertNull($client->get_count());
        $this->assertNull($client->get_links_count());
        $diagnostics = $client->diagnostics();
        $this->assertFalse($diagnostics['probes']['health']['ok']);
        $this->assertSame('', $this->store['linkate-posts']['hash_field'] ?? '');
    }

    public function testSyncStartRefusesWhenNotConnected(): void
    {
        Functions\expect('wp_remote_request')->never();
        $service = new SyncService(new Options(), $this->license());
        $this->assertSame('error', $service->start()['status']);
    }

    public function testConnectedFreeKeyEnablesReports(): void
    {
        $this->assertFalse(PluginReports::enabled());
        $this->store['linkate-posts'] = ['hash_field' => 'k'];
        $this->store['linkate_posts_meta'] = ['key_valid' => true, 'expires_at' => '', 'plan' => 'free'];
        $this->assertTrue(PluginReports::enabled()); // connected: no opt-in flag any more
    }
}
