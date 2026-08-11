<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Golden-master for the link-card renderer. Proves the current strtr()-based
 * linkate_link_fields() path produces byte-identical output to the historical
 * {tag}-dispatch machinery, for the REAL templates pulled out of
 * cherrylink_main.php, in both editor modes.
 *
 * Ported from tests/test_render_output.php. This is the regression lock for the
 * Stage 5 view extraction: whatever replaces these templates must still match.
 */
final class RenderOutputTest extends TestCase
{
    private const OPTS = [
        'relative_links'      => 'full',
        'anons_len'           => 0,
        'template_image_size' => '',
        'divider'             => '',
        'none_text'           => 'Ничего не найдено...',
    ];

    private const OPTION_KEY = 'linkate-posts';

    /** @var string */
    private $gutTpl;
    /** @var string */
    private $classic;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $opts = self::OPTS;
        Functions\when('get_option')->alias(static function ($key, $default = false) use ($opts) {
            return $key === 'date_format' ? 'Y-m-d' : $opts;
        });
        Functions\when('get_permalink')->alias(static function ($id) {
            return "http://site/?p=$id";
        });
        Functions\when('get_the_author_meta')->alias(static function ($field, $author) {
            return "Author$author";
        });
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_the_category')->justReturn([]);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('mysql2date')->returnArg(2);
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        Functions\when('attachment_url_to_postid')->justReturn(0);
        Functions\when('wp_get_attachment_image_url')->justReturn(false);
        Functions\when('strip_shortcodes')->returnArg(1);

        // Legacy linkate_otf_* getters — used ONLY by the historical {tag}-dispatch
        // oracle below (refPrepare/refExpand). The production "new" path calls
        // \CherryLink\Editor\LinkFields directly.
        require_once CHERRYLINK_PLUGIN_DIR . '/tests/fixtures/legacy-output-tags.php';

        // Pull the REAL templates out of the plugin so the test tracks production.
        // Stage 5 moved them from inline strings in cherrylink_main.php to
        // return-style partials under templates/link-card/, loaded via View::string().
        $view          = new \CherryLink\Support\View(CHERRYLINK_PLUGIN_DIR . '/templates');
        $this->gutTpl  = $view->string('link-card/gutenberg');
        $this->classic = $view->string('link-card/classic');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testGutenbergRenderMatchesOracle(): void
    {
        $ids = [11, 22];
        $this->assertSame(
            $this->oldGutenberg($ids, $this->gutTpl),
            $this->newGutenberg($ids, $this->gutTpl)
        );
    }

    public function testClassicRenderMatchesOracle(): void
    {
        $ids       = [11, 22];
        $classicH1 = $this->classic;
        $this->assertSame(
            $this->oldClassic($ids, $classicH1, ''),
            $this->newClassic($ids, $classicH1, '')
        );
    }

    public function testNoneTextMatchesOracle(): void
    {
        $noneText = self::OPTS['none_text'];
        $old = trim('<p>' . $this->refExpand([], $noneText, $this->refPrepare($noneText)) . '</p>');
        $new = trim('<p>' . $noneText . "\n</p>");
        $this->assertSame($old, $new);
    }

    public function testNoUnresolvedPlaceholders(): void
    {
        $classicH1 = $this->classic;
        $leftover  = strtr($classicH1, \CherryLink\Editor\LinkFields::forResult(self::OPTION_KEY, $this->mk(1)));
        $this->assertSame(0, preg_match('/\{[a-z_]+\}/', $leftover), "unresolved placeholder: $leftover");
    }

    // ── historical {tag}-dispatch oracle, reproduced verbatim ─────────────────────

    private function refTagAction(string $tag): string
    {
        return 'linkate_otf_' . $tag;
    }

    /** @return array<string, array<int, mixed>> */
    private function refPrepare(string $template): array
    {
        preg_match_all('/{((?:[^{}]|{[^{}]*})*)}/', $template, $matches);
        $translations = [];
        foreach ($matches[1] as $match) {
            if (strpos($match, ':') !== false) {
                [$tag, $ext] = explode(':', $match, 2);
            } else {
                $tag = $match;
                $ext = false;
            }
            $action = $this->refTagAction($tag);
            if (function_exists($action)) {
                $translations['acts'][]     = $action;
                $translations['fulltags'][] = '{' . $match . '}';
                $translations['exts'][]     = $ext;
            }
        }
        return $translations;
    }

    /** @param array<string, array<int, mixed>> $translations */
    private function refExpand($result, string $template, array $translations): string
    {
        $replacements = [];
        if (array_key_exists('fulltags', $translations)) {
            $n = count($translations['fulltags']);
            for ($i = 0; $i < $n; $i++) {
                $replacements[$translations['fulltags'][$i]] =
                    $translations['acts'][$i](self::OPTION_KEY, $result, $translations['exts'][$i]);
            }
        }
        return strtr($template, $replacements) . "\n";
    }

    private function mk(int $id): \stdClass
    {
        $r = new \stdClass();
        $r->ID          = $id;
        $r->post_title  = "Title $id";
        $r->post_content = '';
        $r->post_author = $id;
        $r->post_date   = '2026-01-01 10:00:00';
        $r->post_excerpt = '';
        $r->suggestions = '';
        return $r;
    }

    /** @param int[] $ids */
    private function oldGutenberg(array $ids, string $tpl): string
    {
        $tr    = $this->refPrepare($tpl);
        $items = [];
        foreach ($ids as $id) {
            $r = $this->mk($id);
            $r->suggestions = str_replace('"', '\\"', $r->suggestions);
            $items[] = '{' . $this->refExpand($r, $tpl, $tr) . '}';
        }
        return '[' . implode(',', str_replace("\n", '', $items)) . ']';
    }

    /** @param int[] $ids */
    private function newGutenberg(array $ids, string $tpl): string
    {
        $items = [];
        foreach ($ids as $id) {
            $r = $this->mk($id);
            $r->suggestions = str_replace('"', '\\"', $r->suggestions);
            $items[] = '{' . strtr($tpl, \CherryLink\Editor\LinkFields::forResult(self::OPTION_KEY, $r)) . '}';
        }
        return '[' . implode(',', $items) . ']';
    }

    /** @param int[] $ids */
    private function oldClassic(array $ids, string $outFinal, string $div): string
    {
        $tr    = $this->refPrepare($outFinal);
        $items = [];
        foreach ($ids as $id) {
            $r = $this->mk($id);
            $r->suggestions = htmlspecialchars($r->suggestions, ENT_COMPAT, 'UTF-8');
            $items[] = $this->refExpand($r, $outFinal, $tr);
        }
        return trim(implode($div ? $div : "\n", $items));
    }

    /** @param int[] $ids */
    private function newClassic(array $ids, string $outFinal, string $div): string
    {
        $items = [];
        foreach ($ids as $id) {
            $r = $this->mk($id);
            $r->suggestions = htmlspecialchars($r->suggestions, ENT_COMPAT, 'UTF-8');
            $items[] = strtr($outFinal, \CherryLink\Editor\LinkFields::forResult(self::OPTION_KEY, $r)) . "\n";
        }
        return trim(implode($div ? $div : "\n", $items));
    }
}
