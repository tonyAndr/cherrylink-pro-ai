<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Admin\AdminApi;
use CherryLink\Admin\AdminMenu;
use CherryLink\Editor\LinkMarkup;
use CherryLink\Indexing\SyncService;
use CherryLink\Support\License;
use CherryLink\Support\Options;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CherryLink\Admin\AdminApi
 * @covers \CherryLink\Admin\AdminMenu
 * @covers \CherryLink\Editor\LinkMarkup
 */
final class AdminUiTest extends TestCase
{
    /** @var array<string,mixed> */
    private $store = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->store = [];
        Functions\when('get_option')->alias(function ($key, $default = false) {
            return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
        });
        Functions\when('update_option')->alias(function ($key, $value) {
            $this->store[$key] = $value;
            return true;
        });
        Functions\when('sanitize_html_class')->alias(static function ($c) {
            return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $c);
        });
        Functions\when('sanitize_key')->alias(static function ($k) {
            return strtolower(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $k));
        });
        Functions\when('sanitize_textarea_field')->returnArg(1);
        Functions\when('get_post_types')->justReturn([(object) ['name' => 'product']]);
        Functions\when('get_term_children')->alias(static function ($id) {
            return $id === 5 ? [6] : [];
        });
        Functions\when('add_query_arg')->alias(static function ($args, $url) {
            return $url . ($args ? '&' . http_build_query($args) : '');
        });
        Functions\when('admin_url')->alias(static function ($path = '') {
            return 'https://site.test/wp-admin/' . $path;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── settings whitelist ────────────────────────────────────────────────

    public function testSettingsWhitelistSanitizesAndIgnoresUnknownKeys(): void
    {
        $out = AdminApi::sanitizeSettings([
            'limit_ajax'         => 500,
            'max_incoming_links' => 0,
            'show_cat_filter'    => 'false',
            'match_cat'          => true,
            'excluded_authors'   => ['3', 'x', 0, 7],
            'excluded_cats'      => [5],
            'relative_links'     => 'bogus',
            'link_class'         => 'a  b"x <c>',
            'post_types'         => ['page', 'product', 'attachment'],
            'hash_field'         => 'stolen',
            'status'             => ['publish' => 'false'],
            'use_alt_server'     => 'true',
        ]);

        $this->assertSame(100, $out['limit_ajax']);
        $this->assertSame(1, $out['max_incoming_links']);
        $this->assertSame('false', $out['show_cat_filter']);
        $this->assertSame('true', $out['match_cat']);
        $this->assertSame('3,7', $out['excluded_authors']);
        $this->assertSame('5,6', $out['excluded_cats']); // subtree expanded
        $this->assertArrayNotHasKey('relative_links', $out);
        $this->assertSame('a bx c', $out['link_class']);
        $this->assertSame('true', $out['show_pages']);
        $this->assertSame('product', $out['show_customs']);
        $this->assertArrayNotHasKey('hash_field', $out);
        $this->assertArrayNotHasKey('status', $out);
        $this->assertArrayNotHasKey('use_alt_server', $out);
    }

    public function testImportMapsStoredFormAndMigratesTemplates(): void
    {
        $tpl = base64_encode(urlencode('<a class="x y" target="_blank" href="{url}">'));
        $in  = AdminApi::importToSettings(['link_before' => $tpl, 'show_pages' => 'true', 'show_customs' => 'product', 'limit_ajax' => '20']);
        $out = AdminApi::sanitizeSettings($in);

        $this->assertSame('false', $out['link_title_attr']);
        $this->assertSame('true', $out['link_new_tab']);
        $this->assertSame('x y', $out['link_class']);
        $this->assertSame('true', $out['show_pages']);
        $this->assertSame('product', $out['show_customs']);
        $this->assertSame(20, $out['limit_ajax']);
    }

    // ── link markup ───────────────────────────────────────────────────────

    public function testBuildDefaultOutputIsHrefAndTitle(): void
    {
        $this->assertSame(
            ['open' => '<a href="https://s.test/a/" title="T">', 'close' => '</a>'],
            LinkMarkup::build('https://s.test/a/', 'T', ['link_title_attr' => 'true'])
        );
        $this->assertSame('<a href="https://s.test/a/" title="T">', LinkMarkup::build('https://s.test/a/', 'T', [])['open']);
    }

    public function testBuildHonoursOptions(): void
    {
        $open = LinkMarkup::build('https://s.test/', 'T', ['link_title_attr' => 'false', 'link_new_tab' => 'true', 'link_class' => 'x y'])['open'];
        $this->assertSame('<a href="https://s.test/" target="_blank" rel="noopener" class="x y">', $open);
    }

    public function testMigrateDefaultTemplateKeepsTitleAndDropsLegacyKeys(): void
    {
        $enc = static function (string $s): string {
            return base64_encode(urlencode($s));
        };
        $out = LinkMarkup::migrate([
            'link_before' => $enc('<a href="{url}" title="{title}">'), 'link_after' => $enc('</a>'),
            'term_before' => $enc('<a href="{url}">'), 'term_after' => $enc('</a>'),
            'link_temp_alt' => 'x', 'limit_ajax' => 50,
        ]);
        $this->assertSame(['limit_ajax' => 50, 'link_title_attr' => 'true', 'link_new_tab' => 'false', 'link_class' => ''], $out);
    }

    public function testMigrateNeverOverridesExplicitNewSettings(): void
    {
        $out = LinkMarkup::migrate(['link_before' => base64_encode(urlencode('<a href="{url}">')), 'link_title_attr' => 'true']);
        $this->assertSame('true', $out['link_title_attr']);
    }

    // ── legacy URL redirect ───────────────────────────────────────────────

    public function testLegacySettingsUrlRedirectsKeepingConnectParams(): void
    {
        $this->assertSame(
            'https://site.test/wp-admin/admin.php?page=cherrylink&cl_connect=rate_limited&cl_retry=30',
            AdminMenu::legacyTarget('options-general.php', ['page' => 'cherrylink-pro', 'cl_connect' => 'rate_limited', 'cl_retry' => '30', 'junk' => 1])
        );
        $this->assertNull(AdminMenu::legacyTarget('options-general.php', ['page' => 'other']));
        $this->assertNull(AdminMenu::legacyTarget('admin.php', ['page' => 'cherrylink-pro']));
    }

    // ── learned cutoff ────────────────────────────────────────────────────

    public function testLearnedCutoffUsesOneQueryAndNullMeansUnlimited(): void
    {
        $this->assertNull(AdminApi::learnedCutoff(null));

        $GLOBALS['wpdb'] = new class {
            public $posts = 'wp_posts';
            public $queries = 0;
            public $args = [];

            public function prepare($sql, $args)
            {
                $this->args = $args;
                return $sql;
            }

            public function get_var($sql)
            {
                $this->queries++;
                return '2026-01-02 00:00:00';
            }
        };
        $this->assertSame('2026-01-02 00:00:00', AdminApi::learnedCutoff(100));
        $this->assertSame(1, $GLOBALS['wpdb']->queries);
        $this->assertSame(99, end($GLOBALS['wpdb']->args)); // OFFSET limit-1
        unset($GLOBALS['wpdb']);
    }

    // ── state payload ─────────────────────────────────────────────────────

    public function testStatePayloadShape(): void
    {
        $this->store['linkate-posts'] = ['hash_field' => 'clp_abcd1234', 'show_pages' => 'true', 'show_customs' => 'product',
            'excluded_authors' => '2,3', 'limit_ajax' => '30', 'link_new_tab' => 'true'];
        $this->store['linkate_posts_meta'] = ['key_valid' => true, 'expires_at' => '', 'plan' => 'free', 'post_limit' => 1000,
            'last_scan_gmt' => '2026-11-12 10:00:00', 'last_upserted' => 4, 'last_deleted' => 1];
        Functions\when('get_transient')->justReturn(false);
        Functions\when('get_post_type_object')->alias(static function ($n) {
            return (object) ['labels' => (object) ['name' => ucfirst($n)]];
        });
        Functions\when('wp_count_posts')->justReturn((object) ['publish' => 10]);
        Functions\when('get_users')->justReturn([(object) ['ID' => 2, 'display_name' => 'Ann']]);
        Functions\when('get_terms')->justReturn([]);
        Functions\when('get_date_from_gmt')->returnArg(1);
        Functions\when('number_format_i18n')->returnArg(1);
        Functions\when('home_url')->justReturn('https://site.test');
        Functions\when('wp_parse_url')->alias('parse_url');

        $options = new Options();
        $license = new License($options);
        $state   = (new AdminApi($options, $license, new SyncService($options, $license)))->state();

        $this->assertTrue($state['connected']);
        $this->assertSame('free', $state['license']['plan']);
        $this->assertSame(1000, $state['license']['post_limit']);
        $this->assertSame('clp_••••1234', $state['license']['key_masked']);
        $this->assertSame('active', $state['license']['status']);
        $this->assertSame('site.test', $state['license']['domain']);
        $this->assertSame(30, $state['settings']['limit_ajax']);
        $this->assertTrue($state['settings']['link_new_tab']);
        $this->assertTrue($state['settings']['link_title_attr']); // default on
        $this->assertSame([2, 3], $state['settings']['excluded_authors']);
        $this->assertSame(['page', 'product'], $state['settings']['post_types']);
        $this->assertSame(['gmt' => '2026-11-12 10:00:00', 'upserted' => 4, 'deleted' => 1], $state['last_scan']);
        $this->assertSame('direct', $state['route']);
        $this->assertFalse($state['sync']['running']);
        $this->assertSame([['id' => 2, 'name' => 'Ann']], $state['settings_choices']['authors']);
        $this->assertSame(30, $state['counts']['total']);
        $this->assertSame(['post', 'page', 'product'], array_column($state['counts']['types'], 'name'));
        $this->assertSame([true, true, true], array_column($state['counts']['types'], 'enabled'));
        foreach (['has_key', 'setup_done', 'counts', 'sync', 'settings'] as $key) {
            $this->assertArrayHasKey($key, $state);
        }
    }

    public function testIndexedTypesFollowTheChosenContentTypes(): void
    {
        Functions\when('get_post_types')->justReturn([(object) ['name' => 'product'], (object) ['name' => 'recipe']]);
        $this->store['linkate-posts'] = ['show_pages' => 'false', 'show_customs' => 'recipe'];
        $this->assertSame(['post', 'recipe'], \CherryLink\Indexing\IndexHelpers::get_allowed_types_list());
        $this->assertSame(['post', 'page', 'product', 'recipe'], \CherryLink\Indexing\IndexHelpers::get_public_types_list());

        $this->store['linkate-posts'] = ['show_pages' => 'true', 'show_customs' => ''];
        $this->assertSame(['post', 'page'], \CherryLink\Indexing\IndexHelpers::get_allowed_types_list());
    }
}
