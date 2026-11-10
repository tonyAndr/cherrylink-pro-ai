<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CherryLink\Indexing\Outbox\Schema;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Prefix-aware outbox schema DDL and its lifecycle upgrade strategy.
 *
 * @covers \CherryLink\Indexing\Outbox\Schema
 */
final class OutboxSchemaTest extends TestCase
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

    /** @return \Mockery\MockInterface */
    private function mockWpdb()
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('get_charset_collate')->andReturn(' DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        return $wpdb;
    }

    public function testInstallRunsDbDeltaForBothTablesAndRecordsVersion(): void
    {
        $GLOBALS['wpdb'] = $this->mockWpdb();
        $ddl = [];
        Functions\expect('dbDelta')->twice()->andReturnUsing(static function ($sql) use (&$ddl) {
            $ddl[] = $sql;
            return [];
        });
        Functions\expect('update_option')->once()->with(Schema::VERSION_OPTION, Schema::DB_VERSION, false)->andReturn(true);

        $this->assertTrue(Schema::install());

        $this->assertCount(2, $ddl);
        $this->assertStringContainsString('CREATE TABLE wp_cherrylink_outbox', $ddl[0]);
        $this->assertStringContainsString('UNIQUE KEY event_key', $ddl[0]);
        $this->assertStringContainsString('CREATE TABLE wp_cherrylink_outbox_state', $ddl[1]);
        $this->assertStringContainsString('DEFAULT CHARACTER SET utf8mb4', $ddl[0]);
    }

    public function testInstallReturnsFalseWhenDbDeltaIsUnavailable(): void
    {
        $GLOBALS['wpdb'] = $this->mockWpdb();
        // dbDelta is not defined here and the upgrade include does not exist under
        // the test ABSPATH, so install() must fail closed without recording a version.
        Functions\expect('update_option')->never();

        $this->assertFalse(Schema::install());
    }

    public function testIsInstalledReadsVersionOption(): void
    {
        Functions\when('get_option')->justReturn(Schema::DB_VERSION);

        $this->assertTrue(Schema::isInstalled());
    }

    public function testMaybeInstallShortCircuitsWhenAlreadyCurrent(): void
    {
        Functions\when('get_option')->justReturn(Schema::DB_VERSION);
        Functions\expect('dbDelta')->never();

        $this->assertTrue(Schema::maybeInstall());
    }

    public function testMaybeInstallRunsInstallWhenVersionStale(): void
    {
        $GLOBALS['wpdb'] = $this->mockWpdb();
        Functions\when('get_option')->justReturn(0);
        Functions\expect('dbDelta')->twice()->andReturn([]);
        Functions\expect('update_option')->once()->andReturn(true);

        $this->assertTrue(Schema::maybeInstall());
    }

    public function testDropRemovesBothTablesAndVersionOption(): void
    {
        $wpdb = $this->mockWpdb();
        $queries = [];
        $wpdb->shouldReceive('query')->twice()->andReturnUsing(static function ($sql) use (&$queries) {
            $queries[] = $sql;
            return 1;
        });
        $GLOBALS['wpdb'] = $wpdb;
        Functions\expect('delete_option')->once()->with(Schema::VERSION_OPTION);

        Schema::drop();

        $this->assertStringContainsString('DROP TABLE IF EXISTS wp_cherrylink_outbox', $queries[0]);
        $this->assertStringContainsString('DROP TABLE IF EXISTS wp_cherrylink_outbox_state', $queries[1]);
    }
}
