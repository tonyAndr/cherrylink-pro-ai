<?php

declare(strict_types=1);

namespace CherryLink\Tests\Unit;

use CherryLink\Indexing\IndexHelpers;
use PHPUnit\Framework\TestCase;

/**
 * Characterization test for the pure incremental-sync diff.
 * No WordPress needed — the method is pure.
 *
 * @covers \CherryLink\Indexing\IndexHelpers::compute_sync_plan
 */
final class SyncPlanTest extends TestCase
{
    public function testEmptyManifestUpsertsEverything(): void
    {
        $plan = IndexHelpers::compute_sync_plan(
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
            IndexHelpers::compute_sync_plan($manifest, $current)
        );
    }

    public function testChangedPostIsUpserted(): void
    {
        $manifest = [1 => ['modified' => '2026-01-01 00:00:00'], 2 => ['modified' => '2026-01-02 00:00:00']];
        $current  = [1 => '2027-06-06 06:06:06', 2 => '2026-01-02 00:00:00'];
        $this->assertSame(
            ['upsert' => [1], 'delete' => []],
            IndexHelpers::compute_sync_plan($manifest, $current)
        );
    }

    public function testNewPostIsUpserted(): void
    {
        $manifest = [1 => ['modified' => '2026-01-01 00:00:00'], 2 => ['modified' => '2026-01-02 00:00:00']];
        $current  = [1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00', 3 => '2026-03-03 00:00:00'];
        $this->assertSame(
            ['upsert' => [3], 'delete' => []],
            IndexHelpers::compute_sync_plan($manifest, $current)
        );
    }

    public function testRemovedPostIsDeleted(): void
    {
        $manifest = [1 => ['modified' => '2026-01-01 00:00:00'], 2 => ['modified' => '2026-01-02 00:00:00']];
        $current  = [1 => '2026-01-01 00:00:00'];
        $this->assertSame(
            ['upsert' => [], 'delete' => [2]],
            IndexHelpers::compute_sync_plan($manifest, $current)
        );
    }

    public function testMixedChangeNewAndOrphan(): void
    {
        $manifest = [1 => ['modified' => 'a'], 2 => ['modified' => 'b'], 9 => ['modified' => 'z']];
        $current  = [1 => 'a', 2 => 'CHANGED', 3 => 'new'];
        $this->assertSame(
            ['upsert' => [2, 3], 'delete' => [9]],
            IndexHelpers::compute_sync_plan($manifest, $current)
        );
    }

    public function testNullStoredModifiedIsTreatedAsChanged(): void
    {
        $manifest = [1 => ['modified' => null]];
        $this->assertSame(
            ['upsert' => [1], 'delete' => []],
            IndexHelpers::compute_sync_plan($manifest, [1 => '2026-01-01 00:00:00'])
        );
    }

    public function testForceFullReindexesPublishedPostsButStillPrunesOrphans(): void
    {
        $manifest = [1 => ['modified' => 'same'], 9 => ['modified' => 'orphan']];
        $current  = [1 => 'same', 2 => 'new'];

        $this->assertSame(
            ['upsert' => [1, 2], 'delete' => [9]],
            IndexHelpers::compute_sync_plan($manifest, $current, true)
        );
    }
}
