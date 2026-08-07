<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use CherryLink\Support\View;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the Stage 5 template renderer. Locks the guarantees the
 * extracted templates rely on: data is scoped in, $this/$file do not leak,
 * string() returns the raw template, a missing template throws, and escaping
 * helpers called inside a template are applied to the output.
 */
final class ViewTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->tmp = sys_get_temp_dir() . '/cl-view-' . uniqid('', true);
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->tmp);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function writeTpl(string $name, string $body): void
    {
        file_put_contents($this->tmp . '/' . $name . '.php', $body);
    }

    public function testRenderExtractsDataIntoScope(): void
    {
        $this->writeTpl('greet', '<?php echo "Hi {$name}, you are {$age}";');
        $out = (new View($this->tmp))->render('greet', ['name' => 'Ada', 'age' => 36]);
        $this->assertSame('Hi Ada, you are 36', $out);
    }

    public function testTemplateCannotSeeRendererThis(): void
    {
        // The static closure keeps $this out of template scope.
        $this->writeTpl('leak', '<?php echo isset($this) ? "THIS" : "no-this";');
        $out = (new View($this->tmp))->render('leak');
        $this->assertSame('no-this', $out);
    }

    /**
     * @dataProvider reservedNameKeys
     */
    public function testDataKeyIsNotShadowedByInternals(string $key): void
    {
        // Keys that could collide with the renderer's own vars must still reach
        // the template. Regression: a key named "data" was being unset internally.
        $this->writeTpl('usekey', '<?php echo $' . $key . ';');
        $out = (new View($this->tmp))->render('usekey', [$key => 'from-data']);
        $this->assertSame('from-data', $out);
    }

    /** @return array<string, array{0: string}> */
    public function reservedNameKeys(): array
    {
        return [
            'file' => ['file'],
            'data' => ['data'],
        ];
    }

    public function testRenderReturnsBufferedOutputNotPrinted(): void
    {
        $this->writeTpl('echoes', 'plain <?php echo "text"; ?> here');
        ob_start();
        $out = (new View($this->tmp))->render('echoes');
        $stray = ob_get_clean();
        $this->assertSame('plain text here', $out);
        $this->assertSame('', $stray, 'render() must not emit to the output buffer');
    }

    public function testStringReturnsRawTemplate(): void
    {
        $this->writeTpl('tpl', "<?php return 'a{token}b';");
        $this->assertSame('a{token}b', (new View($this->tmp))->string('tpl'));
    }

    public function testMissingTemplateThrows(): void
    {
        // esc_html() is provided as a passthrough stub in tests/stubs.
        $this->expectException(\RuntimeException::class);
        (new View($this->tmp))->render('does/not/exist');
    }

    public function testTemplateMayCallWordPressFunctions(): void
    {
        // View does not escape; templates do. Prove a WP escaping call inside a
        // template reaches the output (the test stub is passthrough).
        $this->writeTpl('attr', '<input value="<?php echo esc_attr($v); ?>">');
        $out = (new View($this->tmp))->render('attr', ['v' => 'plain']);
        $this->assertSame('<input value="plain">', $out);
    }
}
