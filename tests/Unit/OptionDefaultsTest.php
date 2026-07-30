<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once CHERRYLINK_PLUGIN_DIR . '/cherrylink_install.php';

/**
 * Characterization test pinning the default options written on a fresh install
 * (fill_options()). This is the contract the Stage 2 Options repository must
 * reproduce exactly — get_option defaults must not silently drift.
 *
 * @covers ::fill_options
 */
final class OptionDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        // A fresh install calls fill_options((array) get_option('linkate-posts', [])),
        // i.e. fill_options([]). Since [] == null in PHP, that enters the "reset to
        // defaults" branch, which re-reads the stored options to preserve the license
        // key before rebuilding every default. Mock that read + the final persist.
        Functions\when('get_option')->justReturn(['hash_field' => '']);
        Functions\when('update_option')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testFreshInstallScalarDefaults(): void
    {
        $opts = \fill_options([]);

        $expected = [
            'limit_ajax'                  => 50,
            'divider'                     => '',
            'show_private'                => 'false',
            'show_pages'                  => 'false',
            'none_text'                   => 'Ничего не найдено...',
            'excluded_cats'               => '',
            'excluded_authors'            => '',
            'show_customs'                => '',
            'match_cat'                   => 'false',
            'no_selection_action'         => 'placeholder',
            'hash_field'                  => '',
            'multilink'                   => 'checked',
            'anons_len'                   => 200,
            'linkate_scheme_exists'       => false,
            'relative_links'              => 'full',
            'debug_enabled'               => 'false',
            'consider_max_incoming_links' => 'false',
            'max_incoming_links'          => 20,
            'template_image_size'         => '',
            'show_cat_filter'             => 'false',
            'vector_api_url'              => '',
            'vector_api_key'              => '',
            'vector_custom_fields'        => '',
            'use_alt_server'              => 'false',
        ];

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $opts, "missing default: $key");
            $this->assertSame($value, $opts[$key], "default drifted for: $key");
        }
    }

    public function testFreshInstallStatusMatrix(): void
    {
        $opts = \fill_options([]);

        $this->assertSame(
            ['publish' => 'true', 'private' => 'false', 'draft' => 'false', 'future' => 'false'],
            $opts['status']
        );
    }

    /**
     * Template defaults are stored base64(urlencode(html)); assert they decode
     * back to the exact markup rather than transcribing brittle encoded blobs.
     */
    public function testFreshInstallTemplateDefaultsDecode(): void
    {
        $opts = \fill_options([]);

        $decode = static function (string $stored): string {
            return urldecode(base64_decode($stored));
        };

        $this->assertSame('<a href="{url}" title="{title}">', $decode($opts['link_before']));
        $this->assertSame('</a>', $decode($opts['link_after']));
        $this->assertSame('<a href="{url}" title="{title}">', $decode($opts['term_before']));
        $this->assertSame('</a>', $decode($opts['term_after']));
        $this->assertStringContainsString('{imagesrc}', $decode($opts['link_temp_alt']));
        $this->assertStringContainsString('{title}', $decode($opts['term_temp_alt']));
    }

    /**
     * The full key set must stay stable — a refactor that drops or renames a
     * default option would break saved-settings compatibility.
     */
    public function testFreshInstallKeySet(): void
    {
        $opts = \fill_options([]);
        $keys = array_keys($opts);
        sort($keys);

        $expectedKeys = [
            'anons_len', 'consider_max_incoming_links', 'debug_enabled', 'divider',
            'excluded_authors', 'excluded_cats', 'hash_field', 'limit_ajax',
            'link_after', 'link_before', 'link_temp_alt', 'linkate_scheme_exists',
            'match_cat', 'max_incoming_links', 'multilink', 'no_selection_action',
            'none_text', 'relative_links', 'show_cat_filter', 'show_customs',
            'show_pages', 'show_private', 'status', 'template_image_size',
            'term_after', 'term_before', 'term_temp_alt', 'use_alt_server',
            'vector_api_key', 'vector_api_url', 'vector_custom_fields',
        ];
        sort($expectedKeys);

        $this->assertSame($expectedKeys, $keys);
    }
}
