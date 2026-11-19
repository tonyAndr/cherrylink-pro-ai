<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Editor\LinkFields;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CherryLink\Editor\LinkFields
 */
final class LinkFieldsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_parse_url')->alias('parse_url');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testPlainPermalinkKeepsQueryString(): void
    {
        $url = 'http://example.test:8099/?p=4';
        self::assertSame($url, LinkFields::unparseUrl($url, 'full'));
        self::assertSame('//example.test:8099/?p=4', LinkFields::unparseUrl($url, 'no_proto'));
        self::assertSame('/?p=4', LinkFields::unparseUrl($url, 'no_domain'));
    }

    public function testPrettyPermalinkUnchanged(): void
    {
        self::assertSame('/blog/post/', LinkFields::unparseUrl('https://example.test/blog/post/', 'no_domain'));
        self::assertSame('https://example.test/a/#top', LinkFields::unparseUrl('https://example.test/a/#top', 'full'));
    }
}
