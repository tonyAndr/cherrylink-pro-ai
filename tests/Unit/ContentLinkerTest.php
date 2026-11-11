<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use CherryLink\Editor\ContentLinker;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CherryLink\Editor\ContentLinker
 */
final class ContentLinkerTest extends TestCase
{
    private const OPEN  = '<a href="/x">';
    private const CLOSE = '</a>';

    private function link(string $html, string $phrase, int $block = -1): ?string
    {
        return ContentLinker::link($html, $phrase, $block, self::OPEN, self::CLOSE);
    }

    public function testSimpleParagraph(): void
    {
        $this->assertSame(
            '<p>Our big red apple tree grows.</p>',
            \str_replace(['<a href="/x">', '</a>'], '', (string) $this->link('<p>Our big red apple tree grows.</p>', 'red apple'))
        );
        $this->assertSame(
            '<p>Our big <a href="/x">red apple</a> tree grows.</p>',
            $this->link('<p>Our big red apple tree grows.</p>', 'red apple')
        );
    }

    public function testNotFoundReturnsNull(): void
    {
        $this->assertNull($this->link('<p>Nothing relevant in here at all.</p>', 'banana'));
        $this->assertNull($this->link('<p>text</p>', '   '));
        $this->assertNull($this->link('', 'x'));
    }

    public function testCaseInsensitivePreservesOriginalText(): void
    {
        $this->assertSame(
            '<p>The <a href="/x">Red Apple</a> is here today.</p>',
            $this->link('<p>The Red Apple is here today.</p>', 'red apple')
        );
    }

    public function testCyrillicCaseInsensitive(): void
    {
        $this->assertSame(
            '<p>Это <a href="/x">Внутренняя Перелинковка</a> сайта и многое другое.</p>',
            $this->link('<p>Это Внутренняя Перелинковка сайта и многое другое.</p>', 'внутренняя перелинковка')
        );
    }

    public function testWordBoundariesRespected(): void
    {
        $this->assertNull($this->link('<p>The catalogue is long enough to match.</p>', 'cat'));
        $this->assertNull($this->link('<p>Эта котлета была очень вкусная вчера.</p>', 'кот'));
    }

    public function testFirstMatchInTargetBlockThenAnywhere(): void
    {
        $html = '<p>Alpha beta gamma delta one.</p><p>Second beta gamma block here.</p>';
        $this->assertSame(
            '<p>Alpha beta gamma delta one.</p><p>Second <a href="/x">beta</a> gamma block here.</p>',
            $this->link($html, 'beta', 1)
        );
        // block 0 explicitly
        $this->assertSame(
            '<p>Alpha <a href="/x">beta</a> gamma delta one.</p><p>Second beta gamma block here.</p>',
            $this->link($html, 'beta', 0)
        );
        // phrase absent from block 1 -> falls back to first in document
        $html2 = '<p>Alpha zeta gamma delta one.</p><p>Second beta gamma block here.</p>';
        $this->assertSame(
            '<p>Alpha <a href="/x">zeta</a> gamma delta one.</p><p>Second beta gamma block here.</p>',
            $this->link($html2, 'zeta', 1)
        );
    }

    public function testBlockIndexSkipsShortBlocks(): void
    {
        // "Tiny" is <= 10 chars so it is not block 0 (mirrors the API extractor).
        $html = '<p>Tiny</p><p>Long enough paragraph with word.</p><p>Another long paragraph with word.</p>';
        $this->assertSame(
            '<p>Tiny</p><p>Long enough paragraph with word.</p><p>Another long paragraph with <a href="/x">word</a>.</p>',
            $this->link($html, 'word', 1)
        );
        $this->assertSame(['Long enough paragraph with word.', 'Another long paragraph with word.'], ContentLinker::blockTexts($html));
    }

    public function testBlockTextCountsEntitiesAndStripsTags(): void
    {
        $this->assertSame(['Tom & Jerry <3 forever ok'], ContentLinker::blockTexts('<p>Tom &amp; Jerry &lt;3 <b>forever</b> ok</p>'));
    }

    public function testGutenbergSerializedContent(): void
    {
        $html = "<!-- wp:paragraph -->\n<p>First paragraph with some words.</p>\n<!-- /wp:paragraph -->\n\n"
            . "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">The great apple heading</h2>\n<!-- /wp:heading -->\n\n"
            . "<!-- wp:paragraph -->\n<p>Another paragraph talks about apple pie.</p>\n<!-- /wp:paragraph -->";
        $out = $this->link($html, 'apple', 2);
        $this->assertNotNull($out);
        $this->assertStringContainsString('<p>Another paragraph talks about <a href="/x">apple</a> pie.</p>', (string) $out);
        $this->assertStringContainsString('<!-- wp:heading {"level":2} -->', (string) $out);
        $this->assertStringContainsString('The great apple heading', (string) $out);
    }

    public function testNeverInsideBlockCommentJson(): void
    {
        $html = '<!-- wp:paragraph {"note":"apple pie"} --><p>No match here friend.</p><!-- /wp:paragraph -->';
        $this->assertNull($this->link($html, 'apple pie'));
    }

    public function testNeverInsideExistingLink(): void
    {
        $html = '<p>Read <a href="/y">the apple guide</a> for more details.</p>';
        $this->assertNull($this->link($html, 'apple guide'));
        // but outside the link it is fine
        $html = '<p>An apple sits near <a href="/y">the apple guide</a> today.</p>';
        $this->assertSame(
            '<p>An <a href="/x">apple</a> sits near <a href="/y">the apple guide</a> today.</p>',
            $this->link($html, 'apple')
        );
    }

    public function testNestedInlineInsideAnchorSkipped(): void
    {
        $html = '<p>See <a href="/y"><strong>apple tree</strong></a> and later words.</p>';
        $this->assertNull($this->link($html, 'apple tree'));
    }

    public function testNeverInsideTagsOrAttributes(): void
    {
        $html = '<p class="apple" title="red apple">An image <img alt="red apple" src="a.png"> follows here.</p>';
        $this->assertNull($this->link($html, 'red apple'));
        $this->assertNull($this->link($html, 'apple'));
    }

    public function testPhraseSplitByInlineTagsIsNotFound(): void
    {
        $html = '<p>The red <strong>apple</strong> tree is tall.</p>';
        $this->assertNull($this->link($html, 'red apple'));
        // each side alone is linkable
        $this->assertSame(
            '<p>The red <strong><a href="/x">apple</a></strong> tree is tall.</p>',
            $this->link($html, 'apple')
        );
    }

    public function testNestedInlineTagsWrapOnlyTextNode(): void
    {
        $html = '<p>Some <em><strong>deep apple tree</strong></em> text lives here.</p>';
        $this->assertSame(
            '<p>Some <em><strong>deep <a href="/x">apple tree</a></strong></em> text lives here.</p>',
            $this->link($html, 'apple tree')
        );
    }

    public function testEntitiesInTextNode(): void
    {
        $html = '<p>Tom &amp; Jerry are old friends, truly.</p>';
        $this->assertSame(
            '<p><a href="/x">Tom &amp; Jerry</a> are old friends, truly.</p>',
            $this->link($html, 'Tom & Jerry')
        );
        // phrase after an entity keeps correct offsets
        $this->assertSame(
            '<p>Tom &amp; <a href="/x">Jerry</a> are old friends, truly.</p>',
            $this->link($html, 'Jerry')
        );
    }

    public function testNbspMatchesSpace(): void
    {
        $html = '<p>Big&nbsp;red apple in the garden today.</p>';
        $this->assertSame(
            '<p><a href="/x">Big&nbsp;red</a> apple in the garden today.</p>',
            $this->link($html, 'Big red')
        );
    }

    public function testListItemsAndTableCells(): void
    {
        $html = '<ul><li>Apples are tasty fruit</li><li>Pears are sweet fruit too</li></ul><table><tr><td>Plums grow on trees nearby</td></tr></table>';
        $this->assertSame(
            '<ul><li>Apples are tasty fruit</li><li><a href="/x">Pears</a> are sweet fruit too</li></ul><table><tr><td>Plums grow on trees nearby</td></tr></table>',
            $this->link($html, 'Pears', 1)
        );
        $out = $this->link($html, 'Plums', 2);
        $this->assertStringContainsString('<td><a href="/x">Plums</a> grow', (string) $out);
    }

    public function testBlockquoteAndHeading(): void
    {
        $html = '<h2>About the big topic</h2><blockquote>A quoted statement about topic goes here</blockquote>';
        $out  = $this->link($html, 'topic', 1);
        $this->assertSame('<h2>About the big topic</h2><blockquote>A quoted statement about <a href="/x">topic</a> goes here</blockquote>', $out);
    }

    public function testScriptAndStyleSkipped(): void
    {
        $html = '<script>var apple = "red apple";</script><style>.apple{}</style><p>Nothing to see in this paragraph.</p>';
        $this->assertNull($this->link($html, 'apple'));
    }

    public function testPlainTextContentBlocks(): void
    {
        $content = "First paragraph of plain text.\n\nSecond paragraph mentions apple trees.";
        $this->assertSame(
            "First paragraph of plain text.\n\nSecond paragraph mentions <a href=\"/x\">apple</a> trees.",
            $this->link($content, 'apple', 1)
        );
    }

    public function testAnchorAcrossMultipleMatchesPicksFirstEligible(): void
    {
        $html = '<p>Visit <a href="/y">apple</a> then apple again and apple.</p>';
        $this->assertSame(
            '<p>Visit <a href="/y">apple</a> then <a href="/x">apple</a> again and apple.</p>',
            $this->link($html, 'apple')
        );
    }

    public function testPhraseWithRegexMetacharacters(): void
    {
        $html = '<p>Use the (apple+pear) mix for cooking now.</p>';
        $this->assertSame(
            '<p>Use the <a href="/x">(apple+pear)</a> mix for cooking now.</p>',
            $this->link($html, '(apple+pear)')
        );
    }

    public function testWhitespaceInPhraseIsFlexible(): void
    {
        $html = "<p>Red\n  apple trees grow tall here.</p>";
        $this->assertSame("<p><a href=\"/x\">Red\n  apple</a> trees grow tall here.</p>", $this->link($html, 'Red  apple'));
    }

    // ── linksTo ─────────────────────────────────────────────────────────────────

    public function testLinksToAbsoluteRelativeAndProtocolRelative(): void
    {
        $target = 'https://site.test/blog/target-post/';
        $this->assertTrue(ContentLinker::linksTo('<p><a href="https://site.test/blog/target-post/">x</a></p>', $target));
        $this->assertTrue(ContentLinker::linksTo('<a class="k" href=\'/blog/target-post\'>x</a>', $target));
        $this->assertTrue(ContentLinker::linksTo('<a href="//site.test/blog/target-post/#frag">x</a>', $target));
        $this->assertTrue(ContentLinker::linksTo('<a href="HTTPS://SITE.TEST/blog/target-post/">x</a>', $target));
    }

    public function testLinksToNegative(): void
    {
        $target = 'https://site.test/blog/target-post/';
        $this->assertFalse(ContentLinker::linksTo('<a href="https://other.test/blog/target-post/">x</a>', $target));
        $this->assertFalse(ContentLinker::linksTo('<a href="/blog/other">x</a>', $target));
        $this->assertFalse(ContentLinker::linksTo('<a href="#">x</a><a href="">y</a>', $target));
        $this->assertFalse(ContentLinker::linksTo('<p>text blog/target-post</p>', $target));
        $this->assertFalse(ContentLinker::linksTo('<a href="/a">x</a>', ''));
    }

    public function testLinksToQueryPermalinks(): void
    {
        $this->assertTrue(ContentLinker::linksTo('<a href="/?p=42">x</a>', 'http://site.test/?p=42'));
        $this->assertFalse(ContentLinker::linksTo('<a href="/?p=43">x</a>', 'http://site.test/?p=42'));
    }
}
