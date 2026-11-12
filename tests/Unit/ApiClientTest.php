<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Api\Client;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for the API client (ex-CherryVectorAPI, now CherryLink\Api\Client).
 * These pin the request-building behavior — headers, auth-key priority, alt-server URL
 * rewrite, per-path timeout defaults, and response mapping — with the HTTP layer mocked,
 * so the Stage 3 port stays byte-for-byte equivalent to the legacy class.
 *
 * @covers \CherryLink\Api\Client
 */
final class ApiClientTest extends TestCase
{
    /** @var array<string,mixed> */
    private $transients = [];

    /** @var array<string,mixed> Captured args of the last wp_remote_request() call. */
    private $captured = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Runtime constants normally set by cherrylink_vector_api.php at include time.
        if (!defined('CHERRY_VECTOR_API_URL')) {
            define('CHERRY_VECTOR_API_URL', 'https://seocherry.apov.dev');
        }
        if (!defined('CL_ALT_API_PORT')) {
            define('CL_ALT_API_PORT', 9443);
        }
        if (!defined('CL_ALT_API_IP')) {
            define('CL_ALT_API_IP', '31.129.48.202');
        }

        // Stable identity + real URL parsing for the constructor.
        Functions\when('home_url')->justReturn('https://mysite.ru');
        Functions\when('wp_parse_url')->alias(static function ($url, $component = -1) {
            return parse_url($url, $component);
        });
        Functions\when('wp_json_encode')->alias(static function ($data) {
            return json_encode($data);
        });
        $this->transients = [];
        Functions\when('get_transient')->alias(function ($key) {
            return $this->transients[$key] ?? false;
        });
        Functions\when('set_transient')->alias(function ($key, $value) {
            $this->transients[$key] = $value;
            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Wire wp_remote_request to capture the outgoing args and return a canned HTTP result.
     *
     * @param mixed $body    Response body (encoded to JSON for the client to decode).
     * @param int   $code    HTTP status code.
     * @param bool                 $isError Whether is_wp_error() should report a transport error.
     * @param array<string,string> $headers Response headers.
     */
    private function mockHttp($body, int $code = 200, bool $isError = false, array $headers = []): void
    {
        $result = $isError
            ? new class {
                public function get_error_message(): string
                {
                    return 'transport failure';
                }
            }
            : ['sentinel' => true];
        Functions\when('wp_remote_request')->alias(function ($url, $args) use ($result) {
            $this->captured = ['url' => $url, 'args' => $args];
            return $result;
        });
        Functions\when('is_wp_error')->justReturn($isError);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($code);
        Functions\when('wp_remote_retrieve_headers')->justReturn($headers);
        Functions\when('wp_remote_retrieve_body')->justReturn(is_string($body) ? $body : json_encode($body));
    }

    public function testSendsAuthHeadersAndParsesResponse(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['count' => 42]);

        $count = (new Client())->get_count();

        $this->assertSame(42, $count);
        $this->assertSame('https://seocherry.apov.dev/v1/documents/count', $this->captured['url']);
        $this->assertSame('GET', $this->captured['args']['method']);
        $headers = $this->captured['args']['headers'];
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertSame('LICENSE123', $headers['X-License-Key']);
        $this->assertSame('mysite.ru', $headers['X-Site-Domain']);
    }

    public function testAuthKeyPrefersSavedLicenseOverExplicitApiKey(): void
    {
        Functions\when('get_option')->justReturn([
            'vector_api_key' => 'EXPLICIT_KEY',
            'hash_field'     => 'LICENSE123',
        ]);
        $this->mockHttp(['count' => 1]);

        (new Client())->get_count();

        // Production disables admin-license bypass; a leftover explicit key must not
        // shadow the customer license.
        $this->assertSame('LICENSE123', $this->captured['args']['headers']['X-License-Key']);
    }

    public function testAuthKeyFallsBackToExplicitApiKeyWithoutLicense(): void
    {
        Functions\when('get_option')->justReturn(['vector_api_key' => 'EXPLICIT_KEY']);
        $this->mockHttp(['count' => 1]);

        (new Client())->get_count();

        $this->assertSame('EXPLICIT_KEY', $this->captured['args']['headers']['X-License-Key']);
    }

    public function testHttpErrorIncludesSafeApiDetail(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['detail' => 'customer_license_required'], 403);

        $result = (new Client())->request_result('GET', '/v1/documents/count');

        $this->assertFalse($result['ok']);
        $this->assertSame('HTTP 403: customer_license_required', $result['error']);
    }

    public function testReportPluginEventsPostsBoundedBatchInBackgroundContext(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['accepted' => 1, 'duplicates' => 0]);

        $result = (new Client(null, Client::CONTEXT_BACKGROUND))->report_plugin_events([[
            'event_key'      => 'evt-00000001',
            'action'         => 'plugin.upgraded',
            'plugin_version' => '1.2.0',
            'from_version'   => '1.1.1',
            'occurred_at'    => '2026-11-11T09:00:00+00:00',
            'environment'    => ['wp_version' => '6.5.5', 'multisite' => false],
        ]]);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['accepted']);
        $this->assertSame('https://seocherry.apov.dev/v1/client-events', $this->captured['url']);
        $this->assertSame('POST', $this->captured['args']['method']);
        $this->assertSame(10, $this->captured['args']['timeout']);
        $body = json_decode($this->captured['args']['body'], true);
        $this->assertSame('evt-00000001', $body['events'][0]['event_key']);
        $this->assertSame('1.1.1', $body['events'][0]['from_version']);
    }

    public function testKeyPagesPostsPinsAndDemotes(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['key_pages' => [], 'posts_considered' => 2]);

        $result = (new Client(null, Client::CONTEXT_BACKGROUND))->key_pages([12, 40], [7]);

        $this->assertSame(2, $result['posts_considered']);
        $this->assertSame('https://seocherry.apov.dev/v1/key-pages', $this->captured['url']);
        $this->assertSame('POST', $this->captured['args']['method']);
        $this->assertSame(['pin' => [12, 40], 'demote' => [7]], json_decode($this->captured['args']['body'], true));
    }

    public function testNoKeySkipsHttpAndReturnsNull(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\expect('wp_remote_request')->never();

        $this->assertNull((new Client())->get_count());
        $this->addToAssertionCount(1); // the ->never() expectation is the assertion
    }

    public function testActiveRelayTransientRewritesToRelayPortOnSameHost(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->transients['cherrylink_relay_until'] = time() + 3600;
        $this->mockHttp(['count' => 7]);

        (new Client())->get_count();

        // Same hostname, relay port: TLS/SNI/cert are unchanged; only the port differs.
        $this->assertSame('https://seocherry.apov.dev:9443/v1/documents/count', $this->captured['url']);
        $this->assertSame('relay', Client::route());
    }

    public function testTransportErrorOnDirectRouteSetsTransientAndRetriesOnceViaRelay(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $urls = [];
        Functions\when('is_wp_error')->alias(static function ($v) {
            return $v instanceof \WP_Error;
        });
        Functions\when('wp_remote_request')->alias(static function ($url) use (&$urls) {
            $urls[] = $url;
            return count($urls) === 1 ? new \WP_Error() : ['sentinel' => true];
        });
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"count":3}');
        Functions\when('wp_remote_retrieve_headers')->justReturn([]);

        $this->assertSame(3, (new Client())->get_count());

        $this->assertSame([
            'https://seocherry.apov.dev/v1/documents/count',
            'https://seocherry.apov.dev:9443/v1/documents/count',
        ], $urls);
        $this->assertArrayHasKey('cherrylink_relay_until', $this->transients);
    }

    public function testReadTimeoutAfterBytesDoesNotFallBack(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $calls = 0;
        Functions\when('is_wp_error')->alias(static function ($v) {
            return $v instanceof \WP_Error;
        });
        Functions\when('wp_remote_request')->alias(static function () use (&$calls) {
            $calls++;
            return new class extends \WP_Error {
                public function get_error_message(): string
                {
                    return 'cURL error 28: Operation timed out after 5001 milliseconds with 120 out of 900 bytes received';
                }
            };
        });

        $this->assertNull((new Client())->get_count());
        $this->assertSame(1, $calls);
        $this->assertSame([], $this->transients);
    }

    public function testHttpErrorNeverFallsBackToRelay(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp('{"error":"boom"}', 500);

        $this->assertNull((new Client())->get_count());
        $this->assertSame([], $this->transients);
    }

    public function testRelayFailureDoesNotRetryAgain(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->transients['cherrylink_relay_until'] = time() + 3600;
        $calls = 0;
        Functions\when('is_wp_error')->alias(static function ($v) {
            return $v instanceof \WP_Error;
        });
        Functions\when('wp_remote_request')->alias(static function () use (&$calls) {
            $calls++;
            return new class extends \WP_Error {
                public function get_error_message(): string
                {
                    return 'down';
                }
            };
        });

        $this->assertNull((new Client())->get_count());
        $this->assertSame(1, $calls);
    }

    public function testNonSuccessStatusMapsToNull(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp('{"error":"boom"}', 500);

        $this->assertNull((new Client())->get_count());
    }

    public function testTransportErrorMapsToNull(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp('', 0, true);

        $this->assertNull((new Client())->get_count());
    }

    /**
     * Interactive callers cannot inherit background timeout heuristics.
     */
    public function testInteractiveReadUsesFiveSecondTimeout(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['count' => 0]);

        (new Client())->get_count(); // /v1/documents/count

        $this->assertSame(5, $this->captured['args']['timeout']);
        $this->assertSame(0, $this->captured['args']['redirection']);
    }

    public function testInteractiveSearchUsesFiveSecondTimeoutAndJsonBody(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['results' => []]);

        (new Client())->search('hello world'); // /v1/search

        $this->assertSame(5, $this->captured['args']['timeout']);
        $this->assertSame('POST', $this->captured['args']['method']);
        $this->assertSame('{"query":"hello world","limit":20}', $this->captured['args']['body']);
    }

    public function testSearchClampsTheLimitToTheApiMaximum(): void
    {
        // The API validates 1..50 (le=50) and answers 422 otherwise. The editor asks
        // for headroom (limit*3 = 150 with the default settings), which silently made
        // the link list empty.
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['results' => []]);

        (new Client())->search('hello', '', 150);

        $this->assertSame('{"query":"hello","limit":50}', $this->captured['args']['body']);
    }

    public function testSearchNeverSendsAZeroOrNegativeLimit(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['results' => []]);

        (new Client())->search('hello', '', 0);
        $this->assertSame('{"query":"hello","limit":1}', $this->captured['args']['body']);

        (new Client())->search('hello', '', -5);
        $this->assertSame('{"query":"hello","limit":1}', $this->captured['args']['body']);
    }

    public function testSearchSendsTheSavedPostForStoredVectorReuse(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['results' => []]);
        $post     = new \WP_Post();
        $post->ID = 42;
        $client   = new class extends Client {
            public function content_hash(\WP_Post $post): string
            {
                return 'h' . $post->ID;
            }
        };

        $client->search('hello', '', 5, '', -1, $post);

        $this->assertSame('{"query":"hello","limit":5,"post_id":42,"content_hash":"h42"}', $this->captured['args']['body']);
    }

    public function testEditedContentIsNotTreatedAsTheSavedPost(): void
    {
        Functions\when('wp_strip_all_tags')->alias(static function ($v) {
            return \strip_tags((string) $v);
        });

        $this->assertTrue(\CherryLink\Editor\SuggestionsResults::sameText("<p>Hello\n  world</p>", 'Hello world'));
        $this->assertFalse(\CherryLink\Editor\SuggestionsResults::sameText('<p>Hello there</p>', 'Hello world'));
        // A typed "All posts" search must never be answered with the post's stored vector.
        $this->assertTrue(\CherryLink\Editor\SuggestionsResults::reuseStoredVector(false, false, '<p>Hello world</p>', 'Hello world'));
        $this->assertFalse(\CherryLink\Editor\SuggestionsResults::reuseStoredVector(false, true, '<p>Hello world</p>', 'Hello world'));
        $this->assertFalse(\CherryLink\Editor\SuggestionsResults::reuseStoredVector(true, false, '<p>Hello world</p>', 'Hello world'));
    }

    public function testInteractiveAllRouteCannotUseBackgroundTimeout(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['deleted' => 0]);

        (new Client())->delete_all_documents(); // /v1/documents/all

        $this->assertSame(5, $this->captured['args']['timeout']);
    }

    public function testStructuredResultPreservesStatusHeadersRetryAfterAndExtraHeaders(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(
            ['error' => 'busy'],
            429,
            false,
            ['Retry-After' => '7', 'X-Request-Id' => 'req-123']
        );

        $result = (new Client())->request_result(
            'POST',
            '/v1/documents/batch',
            ['documents' => []],
            120,
            ['Idempotency-Key' => 'batch-123']
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(429, $result['status']);
        $this->assertSame(['error' => 'busy'], $result['body']);
        $this->assertSame('7', $result['headers']['retry-after']);
        $this->assertSame('req-123', $result['headers']['x-request-id']);
        $this->assertSame(7, $result['retry_after']);
        $this->assertSame('batch-123', $this->captured['args']['headers']['Idempotency-Key']);
    }

    public function testRetryAfterAcceptsHttpDateAndRejectsInvalidValues(): void
    {
        $this->assertSame(
            60,
            Client::parse_retry_after(
                gmdate('D, d M Y H:i:s \\G\\M\\T', 1700000060),
                1700000000
            )
        );
        $this->assertNull(Client::parse_retry_after('not-a-delay'));
        $this->assertNull(Client::parse_retry_after('-1'));
    }

    public function testStructuredResultRetainsTransportFailure(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp('', 0, true);

        $result = (new Client())->request_result('GET', '/v1/documents/count');

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $result['status']);
        $this->assertSame('transport failure', $result['error']);
        $this->assertNull($result['retry_after']);
    }

    public function testManifestFailureIsFailClosedAndKeepsTransportMetadata(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['error' => 'unavailable'], 503, false, ['Retry-After' => '4']);

        $result = (new Client())->fetch_manifest_result();

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['map']);
        $this->assertSame(503, $result['status']);
        $this->assertSame(4, $result['retry_after']);
        $this->assertTrue($result['retryable']);
    }

    public function testRetryClassificationKeepsPermanentClientErrorsOut(): void
    {
        $this->assertTrue(Client::is_retryable_result(['status' => 0, 'error' => 'transport failure']));
        $this->assertTrue(Client::is_retryable_result(['status' => 408, 'error' => 'HTTP 408']));
        $this->assertTrue(Client::is_retryable_result(['status' => 429, 'error' => 'HTTP 429']));
        $this->assertTrue(Client::is_retryable_result(['status' => 503, 'error' => 'HTTP 503']));
        $this->assertFalse(Client::is_retryable_result(['status' => 400, 'error' => 'HTTP 400']));
        $this->assertFalse(Client::is_retryable_result(['status' => 0, 'error' => 'no license key configured']));
    }
    public function testBackgroundContextRetainsLongBatchBudget(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['deleted' => 0]);
        (new Client(null, Client::CONTEXT_BACKGROUND))->delete_all_documents();
        $this->assertSame(120, $this->captured['args']['timeout']);
    }

    public function testInteractiveDeadlineCapsLaterCallsAndStopsWithoutMoreHttp(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $now = 100.0;
        $calls = 0;
        $this->mockHttp(['count' => 3]);
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$calls) {
            ++$calls;
            $this->captured = ['url' => $url, 'args' => $args];
            return [];
        });
        $api = new Client(null, Client::CONTEXT_INTERACTIVE, static function () use (&$now): float {
            return $now;
        });
        $api->get_count();
        $now += 6.0;
        $api->get_count();
        $this->assertEquals(2.0, $this->captured['args']['timeout']);
        $now += 2.0;
        $result = $api->request_result('GET', '/v1/documents/count');
        $this->assertFalse($result['ok']);
        $this->assertSame('request budget exhausted', $result['error']);
        $this->assertSame(2, $calls);
    }

    public function testStatusReadsHaveTwoSecondPerCallLimit(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['count' => 3]);
        (new Client(null, Client::CONTEXT_STATUS))->get_count();
        $this->assertSame(2, $this->captured['args']['timeout']);
    }

    public function testUnavailableHealthSkipsAuthenticatedDiagnosticProbes(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $calls = 0;
        $this->transients['cherrylink_relay_until'] = time() + 3600; // already relayed: no second attempt
        $this->mockHttp('', 0, true);
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$calls) {
            ++$calls;
            $this->assertSame(2, $args['timeout']);
            $this->assertSame(0, $args['redirection']);
            return new class {
                public function get_error_message(): string
                {
                    return 'timeout';
                }
            };
        });
        $result = (new Client())->diagnostics();
        $this->assertSame(1, $calls);
        $this->assertFalse($result['probes']['health']['ok']);
        $this->assertSame(0, $result['probes']['documents_count']['http_code']);
    }

    public function testDiagnosticsStopWhenSharedBudgetIsExhausted(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $now = 100.0;
        $calls = 0;
        $this->mockHttp(['count' => 1]);
        Functions\when('wp_remote_request')->alias(function ($url, $args) use (&$calls, &$now) {
            ++$calls;
            $now += 6.0;
            return [];
        });
        $result = (new Client(null, Client::CONTEXT_INTERACTIVE, static function () use (&$now): float {
            return $now;
        }))->diagnostics();
        $this->assertSame(1, $calls);
        $this->assertSame('request budget exhausted', $result['probes']['links_count']['error']);
    }

    public function testSingleUpsertTreatsPlanLimit409AsHandled(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        Functions\when('get_permalink')->justReturn('https://mysite.ru/p/');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_strip_all_tags')->returnArg();
        $this->mockHttp(['detail' => 'plan_limit', 'post_limit' => 1000], 409);
        $post = new \WP_Post();
        $post->ID = 5;

        $this->assertTrue((new Client())->upsert_post($post));
    }

    public function testSingleUpsertStillFailsOnOtherErrors(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        Functions\when('get_permalink')->justReturn('https://mysite.ru/p/');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_strip_all_tags')->returnArg();
        $this->mockHttp(['detail' => 'sync_mode_required'], 409);
        $post = new \WP_Post();
        $post->ID = 5;

        $this->assertFalse((new Client())->upsert_post($post));
    }

    public function testBatchPlanLimitedIdsAreNotErrorsOrRetried(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        Functions\when('get_permalink')->justReturn('https://mysite.ru/p/');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_strip_all_tags')->returnArg();
        $this->mockHttp(['status' => 'ok', 'indexed' => 1, 'errors' => 0, 'plan_limited' => [7, '8']]);
        $a = new \WP_Post();
        $a->ID = 6;
        $b = new \WP_Post();
        $b->ID = 7;

        $result = (new Client())->upsert_batch_result([$a, $b]);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['indexed']);
        $this->assertSame([7, 8], $result['plan_limited']);
    }

    public function testBatchWithoutPlanLimitedKeepsAnEmptyList(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        Functions\when('get_permalink')->justReturn('https://mysite.ru/p/');
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_strip_all_tags')->returnArg();
        $this->mockHttp(['status' => 'ok', 'indexed' => 1, 'errors' => 0]);
        $a = new \WP_Post();
        $a->ID = 6;

        $this->assertSame([], (new Client())->upsert_batch_result([$a])['plan_limited']);
    }

    public function testLinkBatchCarriesTheUpsertContentHashPerItem(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $post = new \WP_Post();
        $post->ID = 5;
        $post->post_title = 'T';
        $post->post_content = 'body';
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://mysite.ru/t/');
        Functions\when('wp_strip_all_tags')->returnArg();
        Functions\when('get_post_meta')->justReturn('');
        $this->mockHttp(['results' => [['count' => 1]], 'errors' => 0]);

        $client = new Client();
        $client->parse_links_for_ids_result([5]);

        $item = json_decode($this->captured['args']['body'], true)['items'][0];
        $this->assertSame($client->content_hash($post), $item['content_hash']);
    }

    public function testSingleLinkParseSendsContentHashOnlyWhenGiven(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['count' => 0]);
        $client = new Client();

        $client->parse_links(5, 0, 'https://mysite.ru/t/', '<p>x</p>', 'abc');
        $this->assertSame('abc', json_decode($this->captured['args']['body'], true)['content_hash']);

        $client->parse_links(5, 0, 'https://mysite.ru/t/', '<p>x</p>');
        $this->assertArrayNotHasKey('content_hash', json_decode($this->captured['args']['body'], true));
    }

    public function testSyncPlanRequestIsCompleteAndA404IsReportedForFallback(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $entries = [['post_id' => 1, 'modified_gmt' => '2026-01-01 00:00:00', 'content_hash' => 'h']];

        $this->mockHttp(['index' => [1], 'links' => [1], 'drop_vectors' => [], 'drop_links' => []]);
        $res = (new Client())->fetch_sync_plan_result($entries);
        $this->assertTrue($res['ok']);
        $this->assertSame('https://seocherry.apov.dev/v1/sync/plan', $this->captured['url']);
        $this->assertSame(['entries' => $entries, 'complete' => true], json_decode($this->captured['args']['body'], true));

        $this->mockHttp(['detail' => 'Not Found'], 404);
        $res = (new Client())->fetch_sync_plan_result($entries);
        $this->assertFalse($res['ok']);
        $this->assertSame(404, $res['status']);
    }
}
