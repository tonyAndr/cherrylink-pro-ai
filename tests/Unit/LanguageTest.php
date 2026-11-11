<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Support\Language;
use PHPUnit\Framework\TestCase;

/** @covers \CherryLink\Support\Language */
final class LanguageTest extends TestCase
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

    public function test_wpml_language_is_used(): void
    {
        Functions\when('apply_filters')->alias(static function ($tag, $value, ...$args) {
            return $tag === 'wpml_post_language_details' ? ['language_code' => 'DE'] : $value;
        });
        Functions\when('get_locale')->justReturn('ru_RU');
        $this->assertSame('de', Language::forPost(5));
    }

    public function test_locale_fallback(): void
    {
        Functions\when('apply_filters')->alias(static fn ($tag, $value) => $value);
        Functions\when('get_locale')->justReturn('en_US');
        $this->assertSame('en', Language::forPost(5));
        $this->assertSame('en', Language::forSite());
    }

    public function test_empty_locale_falls_back_to_ru(): void
    {
        Functions\when('apply_filters')->alias(static fn ($tag, $value) => $value);
        Functions\when('get_locale')->justReturn('');
        $this->assertSame('ru', Language::forPost(5));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_polylang_takes_precedence(): void
    {
        require_once __DIR__ . '/../../vendor/autoload.php';
        Functions\when('pll_get_post_language')->alias(static fn ($id, $field) => 'fr');
        Functions\when('get_locale')->justReturn('ru_RU');
        $this->assertSame('fr', Language::forPost(5));
    }
}
