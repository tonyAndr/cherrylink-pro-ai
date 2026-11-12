<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Admin\SettingsPage;
use CherryLink\Support\License;
use CherryLink\Support\Options;
use Mockery;
use PHPUnit\Framework\TestCase;

/** @covers \CherryLink\Support\License */
final class LicenseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('wp_remote_retrieve_response_code')->alias(static function ($response) {
            return $response['response']['code'] ?? 0;
        });
        Functions\when('wp_remote_retrieve_body')->alias(static function ($response) {
            return $response['body'] ?? '';
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testScheduleValidationPersistsPendingStateAndSchedulesNoKeyArgument(): void
    {
        $meta = ['key_valid' => false, 'key_error_reason' => 'wrong_key'];
        Functions\when('get_option')->alias(static function ($key, $default = false) use (&$meta) {
            if ($key === 'linkate-posts') {
                return ['hash_field' => 'secret-key'];
            }
            if ($key === 'linkate_posts_meta') {
                return $meta;
            }
            return $default;
        });
        Functions\when('update_option')->alias(static function ($key, $value) use (&$meta) {
            if ($key === 'linkate_posts_meta') {
                $meta = $value;
            }
            return true;
        });
        Functions\expect('wp_schedule_single_event')->once()->with(
            Mockery::type('int'),
            License::VALIDATION_HOOK
        )->andReturn(true);

        (new License(new Options()))->scheduleValidation();

        $this->assertSame('pending', $meta['validation_status']);
        $this->assertSame(0, $meta['validation_attempt']);
        $this->assertSame(hash('sha256', 'secret-key'), $meta['validation_key_hash']);
    }

    public function testChangedKeyImmediatelyLosesPreviousActivation(): void
    {
        $meta = ['key_valid' => true, 'expires_at' => '2099-01-01 00:00:00'];
        Functions\when('get_option')->alias(static function ($key, $default = false) use (&$meta) {
            return $key === 'linkate-posts' ? ['hash_field' => 'new'] : ($key === 'linkate_posts_meta' ? $meta : $default);
        });
        Functions\when('update_option')->alias(static function ($key, $value) use (&$meta) {
            if ($key === 'linkate_posts_meta') {
                $meta = $value;
            }
            return true;
        });
        Functions\expect('wp_schedule_single_event')->once()->with(
            Mockery::type('int'),
            License::VALIDATION_HOOK
        )->andReturn(true);

        Functions\expect('wp_remote_post')->never();
        (new License(new Options()))->onOptionsUpdated(['hash_field' => 'old'], ['hash_field' => 'new']);

        $this->assertFalse($meta['key_valid']);
        $this->assertSame('', $meta['expires_at']);
        $this->assertSame('pending', $meta['validation_status']);
    }

    public function testIsValidRejectsCachedActivationForDifferentConfiguredKey(): void
    {
        Functions\when('get_option')->alias(static function ($key, $default = false) {
            if ($key === 'linkate-posts') {
                return ['hash_field' => 'new-key'];
            }
            if ($key === 'linkate_posts_meta') {
                return [
                    'key_valid' => true,
                    'expires_at' => '2099-01-01 00:00:00',
                    'validation_key_hash' => hash('sha256', 'old-key'),
                ];
            }
            return $default;
        });

        $this->assertFalse((new License(new Options()))->isValid());
    }

    /**
     * @dataProvider malformedResponseProvider
     * @param string $body
     */
    public function testCallHomeRejectsMalformedSuccessfulResponse(string $body): void
    {
        Functions\expect('wp_remote_post')->once()->with(
            'https://api.cherrylink.app/cherrylink-pro-licensing/',
            Mockery::on(static function (array $args): bool {
                return $args['timeout'] === 5 && $args['redirection'] === 0
                    && $args['body']['domain'] === 'example.test';
            })
        )->andReturn(['response' => ['code' => 200], 'body' => $body]);

        $license = new License(new Options());
        $this->assertFalse($license->callHome('secret', 'example.test'));
    }

    /** @return array<string,array{string}> */
    public static function malformedResponseProvider(): array
    {
        return [
            'unexpected shape' => ['{"unexpected":"shape"}'],
            'object flag' => ['{"key_valid":{},"expires_at":"2099-01-01"}'],
            'array flag' => ['{"key_valid":[],"expires_at":"2099-01-01"}'],
            'unparseable valid expiry' => ['{"key_valid":1,"expires_at":"not-a-date"}'],
            'non-string invalid reason' => ['{"key_valid":0,"expires_at":"","key_error_reason":[]}'],
        ];
    }

    public function testOutagePreservesSameKeyUnexpiredLicenseAndUsesStableHomeDomain(): void
    {
        $this->mockLock();
        $meta = [
            'key_valid' => true,
            'expires_at' => '2099-01-01 00:00:00',
            'validation_status' => 'valid',
        ];
        Functions\when('get_option')->alias(static function ($key, $default = false) use (&$meta) {
            return $key === 'linkate-posts' ? ['hash_field' => 'current'] : ($key === 'linkate_posts_meta' ? $meta : $default);
        });
        Functions\when('update_option')->alias(static function ($key, $value) use (&$meta) {
            if ($key === 'linkate_posts_meta') {
                $meta = $value;
            }
            return true;
        });
        Functions\expect('wp_remote_post')->once()->with(
            'https://api.cherrylink.app/cherrylink-pro-licensing/',
            Mockery::on(static function (array $args): bool {
                return $args['body']['domain'] === 'site.example' && $args['timeout'] === 5;
            })
        )->andReturn(false);
        Functions\when('wp_parse_url')->justReturn('site.example');
        Functions\when('home_url')->justReturn('https://site.example/path');
        Functions\when('sanitize_text_field')->returnArg();
        Functions\expect('wp_schedule_single_event')->once()->with(Mockery::type('int'), License::VALIDATION_HOOK)->andReturn(true);

        (new License(new Options()))->runDailyCheck();

        $this->assertTrue($meta['key_valid']);
        $this->assertSame('valid', $meta['validation_status']);
        $this->assertSame(1, $meta['validation_attempt']);
    }

    public function testRetryScheduleFailureIsVisibleAndPreservesActiveLicense(): void
    {
        $this->mockLock();
        $settings = ['hash_field' => 'current'];
        $meta = [
            'key_valid' => true,
            'expires_at' => '2099-01-01 00:00:00',
            'validation_key_hash' => hash('sha256', 'current'),
            'validation_attempt' => 0,
        ];
        Functions\when('get_option')->alias(static function ($key, $default = false) use (&$settings, &$meta) {
            if ($key === 'linkate-posts') {
                return $settings;
            }
            return $key === 'linkate_posts_meta' ? $meta : $default;
        });
        Functions\when('update_option')->alias(static function ($key, $value) use (&$meta) {
            if ($key === 'linkate_posts_meta') {
                $meta = $value;
            }
            return true;
        });
        Functions\when('wp_remote_post')->justReturn(false);
        Functions\when('wp_parse_url')->justReturn('site.example');
        Functions\when('home_url')->justReturn('https://site.example');
        Functions\when('sanitize_text_field')->returnArg();
        Functions\expect('wp_schedule_single_event')->once()->with(
            Mockery::type('int'),
            License::VALIDATION_HOOK
        )->andReturn(false);

        (new License(new Options()))->runScheduledValidation();

        $this->assertTrue($meta['key_valid']);
        $this->assertSame('error', $meta['validation_status']);
        $this->assertSame('validation_schedule_failed', $meta['key_error_reason']);
        $this->assertSame('', $meta['validation_next_gmt']);
    }

    public function testScheduledResponseForRotatedKeyIsIgnoredAndCurrentKeyRescheduled(): void
    {
        $this->mockLock();
        $settings_reads = 0;
        $meta = ['key_valid' => false, 'validation_status' => 'pending'];
        Functions\when('get_option')->alias(static function ($key, $default = false) use (&$settings_reads, &$meta) {
            if ($key === 'linkate-posts') {
                $settings_reads++;
                return ['hash_field' => $settings_reads === 1 ? 'old' : 'new'];
            }
            if ($key === 'linkate_posts_meta') {
                return $meta;
            }
            return $default;
        });
        // This read occurs after the network response and sees the replacement key.
        Functions\when('update_option')->alias(static function ($key, $value) use (&$meta) {
            if ($key === 'linkate_posts_meta') {
                $meta = $value;
            }
            return true;
        });
        Functions\when('wp_remote_post')->justReturn(['response' => ['code' => 200], 'body' => '{"key_valid":1,"expires_at":"2099-01-01","key_error_reason":""}']);
        Functions\when('wp_parse_url')->justReturn('site.example');
        Functions\when('home_url')->justReturn('https://site.example');
        Functions\when('sanitize_text_field')->returnArg();
        Functions\expect('wp_schedule_single_event')->once()->with(Mockery::type('int'), License::VALIDATION_HOOK)->andReturn(true);

        (new License(new Options()))->runScheduledValidation();

        $this->assertFalse($meta['key_valid'] ?? false);
        $this->assertSame('pending', $meta['validation_status']);
    }

    private function mockLock(): void
    {
        $wpdb = Mockery::mock();
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('prepare')->once()->andReturn('DELETE FROM wp_options WHERE option_name = ...');
        $wpdb->shouldReceive('query')->once()->andReturn(1);
        $GLOBALS['wpdb'] = $wpdb;
        Functions\when('get_option')->alias(static function ($key, $default = false) {
            return $key === 'clpro_license_validation_lock' ? '' : $default;
        });
        Functions\expect('add_option')->once()->with(
            'clpro_license_validation_lock',
            Mockery::pattern('/^\d+\|test-lock-token$/'),
            '',
            false
        )->andReturn(true);
        Functions\when('wp_generate_uuid4')->justReturn('test-lock-token');
        Functions\when('wp_cache_delete')->justReturn(true);
    }
}
