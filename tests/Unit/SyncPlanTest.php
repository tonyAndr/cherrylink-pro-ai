<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once CHERRYLINK_PLUGIN_DIR . '/cherrylink_index_helpers.php';

/**
 * Characterization test for the pure incremental-sync diff.
 * Ported from tests/test_sync_plan.php. No WordPress needed — the method is pure.
 *
 * @covers \CL_Index_Helpers::compute_sync_plan
 */
final class SyncPlanTest extends TestCase
{
    public function testEmptyManifestUpsertsEverything(): void
    {
        $plan = \CL_Index_Helpers::compute_sync_plan(
            [],
            [1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00']
        );
        $this->assertSame(['upsert' => [1, 2], 'delete' => []], $plan);
    }

    public function testInSyncIsNoOp(): void
    {
        $manifest = [1 => ['modified' => '2026-01-01 00:00:00'], 2 => ['modified' => '2026-01-02 00:00:00']];
        $current  = [1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00'];
        $this->assertSame(
            ['upsert' => [], 'delete' => []],
            \CL_Index_Helpers::compute_sync_plan($manifest, $current)
        );
    }

    public function testChangedPostIsUpserted(): void
    {
        $manifest = [1 => ['modified' => '2026-01-01 00:00:00'], 2 => ['modified' => '2026-01-02 00:00:00']];
        $current  = [1 => '2027-06-06 06:06:06', 2 => '2026-01-02 00:00:00'];
        $this->assertSame(
            ['upsert' => [1], 'delete' => []],
            \CL_Index_Helpers::compute_sync_plan($manifest, $current)
        );
    }

    public function testNewPostIsUpserted(): void
    {
        $manifest = [1 => ['modified' => '2026-01-01 00:00:00'], 2 => ['modified' => '2026-01-02 00:00:00']];
        $current  = [1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00', 3 => '2026-03-03 00:00:00'];
        $this->assertSame(
            ['upsert' => [3], 'delete' => []],
            \CL_Index_Helpers::compute_sync_plan($manifest, $current)
        );
    }

    public function testRemovedPostIsDeleted(): void
    {
        $manifest = [1 => ['modified' => '2026-01-01 00:00:00'], 2 => ['modified' => '2026-01-02 00:00:00']];
        $current  = [1 => '2026-01-01 00:00:00'];
        $this->assertSame(
            ['upsert' => [], 'delete' => [2]],
            \CL_Index_Helpers::compute_sync_plan($manifest, $current)
        );
    }

    public function testMixedChangeNewAndOrphan(): void
    {
        $manifest = [1 => ['modified' => 'a'], 2 => ['modified' => 'b'], 9 => ['modified' => 'z']];
        $current  = [1 => 'a', 2 => 'CHANGED', 3 => 'new'];
        $this->assertSame(
            ['upsert' => [2, 3], 'delete' => [9]],
            \CL_Index_Helpers::compute_sync_plan($manifest, $current)
        );
    }

    public function testNullStoredModifiedIsTreatedAsChanged(): void
    {
        $manifest = [1 => ['modified' => null]];
        $this->assertSame(
            ['upsert' => [1], 'delete' => []],
            \CL_Index_Helpers::compute_sync_plan($manifest, [1 => '2026-01-01 00:00:00'])
        );
    }
}
