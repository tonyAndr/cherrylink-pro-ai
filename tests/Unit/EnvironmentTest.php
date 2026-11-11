<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Support\Environment;
use PHPUnit\Framework\TestCase;

/** @covers \CherryLink\Support\Environment */
final class EnvironmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.59';
        $GLOBALS['wpdb'] = new class {
            public function db_version(): string
            {
                return '8.0.46';
            }
        };

        Functions\when('get_bloginfo')->justReturn('6.5.5');
        Functions\when('wp_get_theme')->justReturn(new class {
            public function get(string $key): string
            {
                return $key === 'Name' ? 'Twenty Twenty-Four' : '1.1';
            }
        });
        Functions\when('get_locale')->justReturn('ru_RU');
        Functions\when('is_multisite')->justReturn(false);
        Functions\when('sanitize_text_field')->alias(static function ($value) {
            return trim((string) $value);
        });
        Functions\when('wp_unslash')->alias(static function ($value) {
            return $value;
        });
    }

    protected function tearDown(): void
    {
        unset($_SERVER['SERVER_SOFTWARE'], $GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testCollectsOnlyAllowlistedBoundedEnvironment(): void
    {
        $environment = Environment::collect();

        $this->assertSame('6.5.5', $environment['wp_version']);
        $this->assertSame(PHP_VERSION, $environment['php_version']);
        $this->assertSame('8.0.46', $environment['db_version']);
        $this->assertSame('Apache/2.4.59', $environment['web_server']);
        $this->assertStringStartsWith(PHP_OS_FAMILY, $environment['os']);
        $this->assertSame('Twenty Twenty-Four', $environment['theme_name']);
        $this->assertSame('1.1', $environment['theme_version']);
        $this->assertSame('ru_RU', $environment['locale']);
        $this->assertFalse($environment['multisite']);
        $this->assertArrayNotHasKey('private_path', $environment);
    }

    public function testStripsControlCharactersAndBoundsValues(): void
    {
        Functions\when('get_bloginfo')->justReturn(str_repeat('a', 60));
        Functions\when('wp_get_theme')->justReturn(new class {
            public function get(string $key): string
            {
                return $key === 'Name' ? "Twenty\nTwenty" : "1.1\t";
            }
        });
        $_SERVER['SERVER_SOFTWARE'] = "Apache\n/2.4";

        $environment = Environment::collect();

        $this->assertSame(40, strlen($environment['wp_version']));
        $this->assertSame('TwentyTwenty', $environment['theme_name']);
        $this->assertSame('1.1', $environment['theme_version']);
        $this->assertStringNotContainsString("\n", $environment['web_server']);
    }
}
