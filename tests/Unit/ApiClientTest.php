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

    public function testAuthKeyPrefersVectorApiKeyOverLicense(): void
    {
        Functions\when('get_option')->justReturn([
            'vector_api_key' => 'EXPLICIT_KEY',
            'hash_field'     => 'LICENSE123',
        ]);
        $this->mockHttp(['count' => 1]);

        (new Client())->get_count();

        $this->assertSame('EXPLICIT_KEY', $this->captured['args']['headers']['X-License-Key']);
    }

    public function testNoKeySkipsHttpAndReturnsNull(): void
    {
        Functions\when('get_option')->justReturn([]);
        Functions\expect('wp_remote_request')->never();

        $this->assertNull((new Client())->get_count());
        $this->addToAssertionCount(1); // the ->never() expectation is the assertion
    }

    public function testAltServerRewritesToRelayPortOnSameHost(): void
    {
        Functions\when('get_option')->justReturn([
            'hash_field'     => 'LICENSE123',
            'use_alt_server' => 'true',
        ]);
        $this->mockHttp(['count' => 7]);

        (new Client())->get_count();

        // Same hostname, relay port — so TLS/SNI/cert are unchanged; only the port differs.
        $this->assertSame(
            'https://seocherry.apov.dev:9443/v1/documents/count',
            $this->captured['url']
        );
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
     * The per-path timeout heuristic in request(): plain reads 15s, search-class 60s,
     * batch/all/parse 120s. Pinning it guards the substr/strpos classification.
     */
    public function testPlainReadUses15sTimeout(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['count' => 0]);

        (new Client())->get_count(); // /v1/documents/count

        $this->assertSame(15, $this->captured['args']['timeout']);
    }

    public function testSearchUses60sTimeoutAndJsonBody(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['results' => []]);

        (new Client())->search('hello world'); // /v1/search

        $this->assertSame(60, $this->captured['args']['timeout']);
        $this->assertSame('POST', $this->captured['args']['method']);
        $this->assertSame('{"query":"hello world","limit":20}', $this->captured['args']['body']);
    }

    public function testBatchClassPathUses120sTimeout(): void
    {
        Functions\when('get_option')->justReturn(['hash_field' => 'LICENSE123']);
        $this->mockHttp(['deleted' => 0]);

        (new Client())->delete_all_documents(); // /v1/documents/all

        $this->assertSame(120, $this->captured['args']['timeout']);
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
}
