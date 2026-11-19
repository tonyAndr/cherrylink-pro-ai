<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Ajax\Input;
use PHPUnit\Framework\TestCase;

/**
 * @covers \CherryLink\Ajax\Input
 */
final class InputTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('sanitize_text_field')->alias(static function ($v) {
            return trim(strip_tags((string) $v));
        });
        Functions\when('sanitize_key')->alias(static function ($v) {
            return strtolower(preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $v));
        });
        Functions\when('absint')->alias(static function ($v) {
            return abs((int) $v);
        });
        Functions\when('rest_sanitize_boolean')->alias(static function ($v) {
            return is_string($v) ? !in_array(strtolower($v), ['false', '0', ''], true) : (bool) $v;
        });
        $_POST = [];
        $_GET  = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET  = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testPostReadsAndSanitizesByType(): void
    {
        $_POST = ['id' => '7x', 'flag' => 'false', 'name' => '<b>Hi</b>', 'ids' => ['3', '<i>4</i>', ['5']]];
        $this->assertSame(7, Input::post('id', 'uint'));
        $this->assertFalse(Input::post('flag', 'bool'));
        $this->assertSame('Hi', Input::post('name'));
        $this->assertSame(['3', '4', ['5']], Input::post('ids', 'text'));
        $this->assertSame('fallback', Input::post('missing', 'text', 'fallback'));
    }

    public function testGetReadsAndSanitizes(): void
    {
        $_GET = ['page' => 'CherryLink<x>'];
        $this->assertSame('cherrylinkx', Input::get('page', 'key'));
        $this->assertSame(0, Input::get('none', 'uint', 0));
    }

    public function testFieldsKeepsOnlyTheSchemaAndCollapsesNonScalars(): void
    {
        $out = Input::fields(['a' => ['x' => '1'], 'b' => new \stdClass(), 'c' => 'keep', 'd' => 'dropped'], ['a' => 'uint', 'b' => 'text', 'c' => 'text', 'e' => 'text']);
        $this->assertSame(['a' => ['x' => 1], 'b' => '', 'c' => 'keep'], $out);
    }
}
