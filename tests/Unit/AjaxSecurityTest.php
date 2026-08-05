<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Ajax\Security;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the AJAX security envelope: verify() must check the shared nonce and
 * the caller's capability, and reject when the capability is missing.
 *
 * @covers \CherryLink\Ajax\Security
 */
final class AjaxSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testVerifyChecksSharedNonceThenCapability(): void
    {
        Functions\expect('check_ajax_referer')->once()->with('cherry_nonce');
        Functions\expect('current_user_can')->once()->with('edit_posts')->andReturn(true);
        Functions\expect('wp_send_json_error')->never();

        Security::verify();

        $this->addToAssertionCount(1); // the ->once()/->never() expectations are the assertions
    }

    public function testVerifyHonorsCustomCapability(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\expect('current_user_can')->once()->with('manage_options')->andReturn(true);

        Security::verify('manage_options');

        $this->addToAssertionCount(1);
    }

    public function testVerifyRejectsInsufficientCapabilityWith403(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(false);
        // wp_send_json_error exits in WP; simulate that with an exception so we can assert.
        Functions\when('wp_send_json_error')->alias(static function ($data, $status = null) {
            throw new \RuntimeException('halt:' . (int) $status);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('halt:403');

        Security::verify();
    }

    public function testNonceUsesSharedAction(): void
    {
        Functions\expect('wp_create_nonce')->once()->with('cherry_nonce')->andReturn('abc123');

        $this->assertSame('abc123', Security::nonce());
    }

    public function testNonceConstantIsStable(): void
    {
        // The token name is load-bearing: it must match the Gutenberg localization.
        $this->assertSame('cherry_nonce', Security::NONCE);
    }
}
