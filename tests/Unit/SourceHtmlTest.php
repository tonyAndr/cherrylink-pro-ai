<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Editor\ClassicPanel;
use PHPUnit\Framework\TestCase;

/** Classic (wpautop-style) content must reach the API extractor with paragraph tags. */
final class SourceHtmlTest extends TestCase
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

    public function testInlineLinkDoesNotDisableParagraphs(): void
    {
        // A saved Classic block after a link was inserted: no <p>, one inline <a>.
        $saved = "<a href=\"https://x.test/a/\">First</a> sentence\n\nSecond paragraph";
        $this->assertSame(
            '<p><a href="https://x.test/a/">First</a> sentence</p><p>Second paragraph</p>',
            ClassicPanel::buildSourceHtml($saved)
        );
    }

    public function testUsesWpautopWhenAvailable(): void
    {
        Functions\expect('wpautop')->once()->with("One\n\nTwo")->andReturn("<p>One</p>\n<p>Two</p>\n");
        $this->assertSame("<p>One</p>\n<p>Two</p>\n", ClassicPanel::buildSourceHtml("One\n\nTwo"));
    }

    public function testStructuredHtmlIsUntouched(): void
    {
        $html = '<!-- wp:paragraph --><p>Block <strong>text</strong></p><!-- /wp:paragraph --><h2>Title</h2>';
        $this->assertSame($html, ClassicPanel::buildSourceHtml($html));
        $this->assertSame('', ClassicPanel::buildSourceHtml(''));
    }
}
