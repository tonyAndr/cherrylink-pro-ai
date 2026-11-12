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

    public function testNullAndZeroLimitKeepTheLegacyPlanShape(): void
    {
        $current = [1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00'];
        $legacy  = IndexHelpers::compute_sync_plan([], $current);
        $this->assertSame($legacy, IndexHelpers::compute_sync_plan([], $current, false, null));
        $this->assertSame($legacy, IndexHelpers::compute_sync_plan([], $current, false, 0));
        $this->assertSame(['upsert', 'delete'], array_keys($legacy));
    }

    public function testLimitedPlanUpsertsNewestNFirstAndDropsTheRest(): void
    {
        $manifest = [
            1 => ['modified' => '2026-01-01 00:00:00'],   // oldest, indexed -> dropped
            2 => ['modified' => '2026-01-02 00:00:00'],
            9 => ['modified' => '2026-01-09 00:00:00'],   // gone from the site -> orphan
        ];
        $current = [
            1 => '2026-01-01 00:00:00',
            2 => '2026-01-02 00:00:00',
            3 => '2026-01-03 00:00:00',
            4 => '2026-01-04 00:00:00',
        ];

        $plan = IndexHelpers::compute_sync_plan($manifest, $current, false, 2);

        $this->assertSame([4, 3], $plan['upsert']);        // newest first, 2 is out of the limit
        $this->assertSame([9], $plan['delete']);           // orphan: vector + links
        $this->assertSame([2, 1], $plan['drop']);          // outside the limit but indexed: vector only
        $this->assertSame([4, 3], $plan['links']);         // links follow upserts, not the whole tail
    }

    public function testLimitedPlanSkipsUnchangedPostsInsideTheLimit(): void
    {
        $manifest = [4 => ['modified' => '2026-01-04 00:00:00']];
        $current  = [3 => '2026-01-03 00:00:00', 4 => '2026-01-04 00:00:00'];

        $plan = IndexHelpers::compute_sync_plan($manifest, $current, false, 5);

        $this->assertSame([3], $plan['upsert']);
        $this->assertSame([], $plan['drop']);
        $this->assertSame([3], $plan['links']);
    }

    public function testLimitedPlanBreaksModifiedTiesByNewestId(): void
    {
        $same = '2026-01-01 00:00:00';
        $plan = IndexHelpers::compute_sync_plan([], [1 => $same, 2 => $same, 3 => $same], false, 2);
        $this->assertSame([3, 2], $plan['upsert']);
    }

    public function testServerPlanMapsPhasesAndDedupesDropVectorsAgainstDropLinks(): void
    {
        $plan = IndexHelpers::plan_from_server(
            ['index' => [3, 3, 4], 'links' => [4], 'drop_vectors' => [8, 9], 'drop_links' => [9]],
            [3 => '2026-01-03 00:00:00', 4 => '2026-01-04 00:00:00']
        );
        $this->assertSame(['upsert' => [3, 4], 'delete' => [9], 'drop' => [8], 'links' => [4]], $plan);
    }

    public function testServerPlanForceFullHonoursThePostLimit(): void
    {
        $plan = IndexHelpers::plan_from_server([], [1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00', 3 => '2026-01-03 00:00:00'], true, 2);
        $this->assertSame([3, 2], $plan['upsert']);
        $this->assertSame([3, 2, 1], $plan['links']);
    }
}
