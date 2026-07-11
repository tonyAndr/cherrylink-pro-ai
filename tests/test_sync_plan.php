<?php
/**
 * Unit tests for CL_Index_Helpers::compute_sync_plan (pure incremental-sync diff).
 * No WordPress needed — run with: php tests/test_sync_plan.php
 */

define('ABSPATH', __DIR__ . '/');            // satisfy the guard in the source file
require __DIR__ . '/../cherrylink_index_helpers.php';

$failures = 0;
function check($label, $got, $want) {
    global $failures;
    $ok = ($got === $want);
    if (!$ok) { $failures++; }
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
    if (!$ok) {
        echo "   got:  " . json_encode($got) . "\n";
        echo "   want: " . json_encode($want) . "\n";
    }
}

// 1. Empty manifest → everything is new (upsert all, delete none)
$plan = CL_Index_Helpers::compute_sync_plan(
    [],
    [1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00']
);
check('empty manifest → upsert all', $plan, ['upsert' => [1, 2], 'delete' => []]);

// 2. All in sync (same modified) → nothing to do
$manifest = [1 => ['modified' => '2026-01-01 00:00:00'], 2 => ['modified' => '2026-01-02 00:00:00']];
$current  = [1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00'];
check('in sync → no-op', CL_Index_Helpers::compute_sync_plan($manifest, $current), ['upsert' => [], 'delete' => []]);

// 3. One post changed → upsert only that one
$current2 = [1 => '2027-06-06 06:06:06', 2 => '2026-01-02 00:00:00'];
check('one changed → upsert 1', CL_Index_Helpers::compute_sync_plan($manifest, $current2), ['upsert' => [1], 'delete' => []]);

// 4. New post added → upsert it
$current3 = [1 => '2026-01-01 00:00:00', 2 => '2026-01-02 00:00:00', 3 => '2026-03-03 00:00:00'];
check('new post → upsert new', CL_Index_Helpers::compute_sync_plan($manifest, $current3), ['upsert' => [3], 'delete' => []]);

// 5. Post removed → delete orphan
$current4 = [1 => '2026-01-01 00:00:00'];
check('removed post → delete orphan', CL_Index_Helpers::compute_sync_plan($manifest, $current4), ['upsert' => [], 'delete' => [2]]);

// 6. Mixed: one changed, one new, one orphaned
$manifest6 = [1 => ['modified' => 'a'], 2 => ['modified' => 'b'], 9 => ['modified' => 'z']];
$current6  = [1 => 'a', 2 => 'CHANGED', 3 => 'new'];  // 1 same, 2 changed, 3 new, 9 orphaned
check('mixed', CL_Index_Helpers::compute_sync_plan($manifest6, $current6), ['upsert' => [2, 3], 'delete' => [9]]);

// 7. Null manifest modified (old plugin build never sent it) → treated as changed → upsert
$manifest7 = [1 => ['modified' => null]];
check('null stored modified → upsert', CL_Index_Helpers::compute_sync_plan($manifest7, [1 => '2026-01-01 00:00:00']), ['upsert' => [1], 'delete' => []]);

echo $failures === 0 ? "\nAll tests passed.\n" : "\n$failures test(s) FAILED.\n";
exit($failures === 0 ? 0 : 1);
