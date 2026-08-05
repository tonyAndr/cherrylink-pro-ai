<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Support\Options;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Options repository (the typed read/write API + index-dirty
 * flag). The default/fill logic is pinned separately by OptionDefaultsTest, which
 * now exercises the same code through the fill_options() back-compat shim.
 *
 * @covers \CherryLink\Support\Options
 */
final class OptionsTest extends TestCase
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

    public function testKeyConstantsMatchLegacyOptionNames(): void
    {
        // These strings are load-bearing: they must match the persisted option
        // names the plugin has always used, or saved settings would be orphaned.
        $this->assertSame('linkate-posts', Options::MAIN);
        $this->assertSame('linkate_posts_meta', Options::META);
        $this->assertSame('cherrylink_index_dirty', Options::INDEX_DIRTY);
        $this->assertSame('cherry_debug_info', Options::DEBUG_INFO);
    }

    public function testMainReadsMainOptionAsArray(): void
    {
        Functions\expect('get_option')
            ->once()
            ->with('linkate-posts', [])
            ->andReturn(['divider' => '|']);

        $this->assertSame(['divider' => '|'], (new Options())->main());
    }

    public function testGetReturnsStoredValue(): void
    {
        Functions\when('get_option')->justReturn(['divider' => '|', 'anons_len' => 200]);

        $options = new Options();
        $this->assertSame('|', $options->get('divider'));
        $this->assertSame(200, $options->get('anons_len'));
    }

    public function testGetReturnsFallbackWhenKeyMissing(): void
    {
        Functions\when('get_option')->justReturn([]);

        $this->assertSame('default', (new Options())->get('missing', 'default'));
        $this->assertNull((new Options())->get('missing'));
    }

    public function testSetMergesIntoMainAndPersists(): void
    {
        Functions\when('get_option')->justReturn(['divider' => '|']);
        Functions\expect('update_option')
            ->once()
            ->with('linkate-posts', ['divider' => '|', 'anons_len' => 300])
            ->andReturn(true);

        $this->assertTrue((new Options())->set('anons_len', 300));
    }

    public function testSaveMetaWritesMetaOption(): void
    {
        Functions\expect('update_option')
            ->once()
            ->with('linkate_posts_meta', ['key_valid' => true])
            ->andReturn(true);

        $this->assertTrue((new Options())->saveMeta(['key_valid' => true]));
    }

    public function testMarkIndexDirtyWritesTimestampOnlyWhenClean(): void
    {
        // Clean → writes a GMT timestamp, non-autoloaded. gmdate is a PHP builtin
        // (not mockable here), so match its Y-m-d H:i:s shape rather than a fixed value.
        Functions\expect('get_option')->once()->with('cherrylink_index_dirty')->andReturn(false);
        Functions\expect('update_option')
            ->once()
            ->with(
                'cherrylink_index_dirty',
                \Mockery::pattern('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'),
                false
            )
            ->andReturn(true);

        (new Options())->markIndexDirty();

        // The Mockery "once" expectation (verified on tearDown) is the assertion here.
        $this->addToAssertionCount(1);
    }

    public function testMarkIndexDirtyIsNoOpWhenAlreadyDirty(): void
    {
        // Already dirty → first write wins, no overwrite.
        Functions\expect('get_option')->once()->with('cherrylink_index_dirty')->andReturn('2026-01-01 00:00:00');
        Functions\expect('update_option')->never();

        (new Options())->markIndexDirty();

        // The Mockery "never" expectation (verified on tearDown) is the assertion here.
        $this->addToAssertionCount(1);
    }

    public function testClearAndIsDirty(): void
    {
        Functions\expect('delete_option')->once()->with('cherrylink_index_dirty');
        (new Options())->clearIndexDirty();

        Functions\when('get_option')->justReturn('2026-01-01 00:00:00');
        $this->assertTrue((new Options())->indexIsDirty());
        $this->assertSame('2026-01-01 00:00:00', (new Options())->indexDirtySince());
    }
}
