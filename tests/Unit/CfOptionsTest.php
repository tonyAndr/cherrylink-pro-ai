<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\CustomFields\Cf;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Regression: Options::fill() stores `limit_ajax` (and friends) as ints. With
 * `declare(strict_types=1)` a plain stripslashes() on an int is a TypeError on
 * PHP 8, which fataled the editor's link-list AJAX request.
 *
 * @covers \CherryLink\CustomFields\Cf
 */
final class CfOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return array<string,mixed> */
    private function defaults(): array
    {
        // Shape produced by Options::fill(): ints for the numeric settings.
        return [
            'limit_ajax'         => 50,
            'divider'            => '',
            'none_text'          => 'Ничего не найдено...',
            'excluded_cats'      => '',
            'excluded_authors'   => '',
            'show_customs'       => '',
            'match_cat'          => 'false',
            'status'             => ['publish' => 'true'],
            'max_incoming_links' => 20,
        ];
    }

    public function testIntegerOptionsDoNotThrow(): void
    {
        Functions\when('get_option')->justReturn($this->defaults());

        $merged = Cf::setOptions('linkate-posts', []);

        $this->assertSame(50, $merged['limit_ajax']);
        $this->assertSame('', $merged['divider']);
    }

    public function testStringsAreStillUnslashed(): void
    {
        $options = $this->defaults();
        $options['none_text'] = 'It\\\'s slashed';
        Functions\when('get_option')->justReturn($options);

        $merged = Cf::setOptions('linkate-posts', []);

        $this->assertSame("It's slashed", $merged['none_text']);
    }

    public function testCurrentPostIdToleratesAnAjaxRequestWithoutAPost(): void
    {
        // AJAX has a wp_the_query but no post loop; this used to raise warnings.
        $GLOBALS['wp_the_query'] = (object) ['post' => null, 'posts' => []];
        unset($GLOBALS['post']);

        $this->assertFalse(Cf::currentPostId(0));
        $this->assertSame(7, Cf::currentPostId(7));

        unset($GLOBALS['wp_the_query']);
        $this->assertFalse(Cf::currentPostId());
    }

    public function testRequestArgumentsWin(): void
    {
        Functions\when('get_option')->justReturn($this->defaults());

        $merged = Cf::setOptions('linkate-posts', ['limit_ajax' => '25']);

        $this->assertSame('25', $merged['limit_ajax']);
    }

    public function testMissingOptionsAreTolerated(): void
    {
        Functions\when('get_option')->justReturn(false);

        $merged = Cf::setOptions('linkate-posts', []);

        $this->assertArrayHasKey('limit_ajax', $merged);
        $this->assertNull($merged['limit_ajax']);
    }

}
