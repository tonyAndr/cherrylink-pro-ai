<?php
/**
 * Real-MySQL assertions for the outbox repository.
 * Run through `wp eval-file` (see run.sh). Fails loudly via WP_CLI::error.
 */

use CherryLink\Indexing\Outbox\Repository;

$repo  = new Repository();
$epoch = $repo->sourceEpoch();
WP_CLI::log('epoch=' . $epoch);
if ($epoch === '') {
    WP_CLI::error('no source epoch allocated');
}

$r1 = $repo->allocateRevision();
$r2 = $repo->allocateRevision();
WP_CLI::log("revisions={$r1},{$r2}");
if ($r2 <= $r1) {
    WP_CLI::error('revisions are not monotonic');
}

$id1  = $repo->enqueue('smoke-key-1', Repository::KIND_UPSERT, 11, ['post_id' => 11, 'text' => 'hello', 'title' => 'T'], null, $r1);
$id1b = $repo->enqueue('smoke-key-1', Repository::KIND_UPSERT, 11, ['post_id' => 11, 'text' => 'different'], null, 999999);
if ($id1 !== $id1b) {
    WP_CLI::error('duplicate event key allocated a second row');
}
$row = $repo->get($id1);
if ((int) $row['source_revision'] !== $r1) {
    WP_CLI::error('immutable source revision was overwritten');
}

$id2 = $repo->enqueue('smoke-key-2', Repository::KIND_DELETE, 12, ['post_id' => 12, 'operation' => 'delete'], null, $r2);

$claimed = $repo->claim(10, 60);
$owners  = [];
foreach ($claimed as $claimedRow) {
    $owners[(int) $claimedRow['id']] = (string) $claimedRow['lease_owner'];
}
WP_CLI::log('claimed=' . count($claimed) . ' pending=' . $repo->pendingCount() . ' leased=' . $repo->leasedCount());
if (count($claimed) !== 2 || $repo->leasedCount() !== 2) {
    WP_CLI::error('claim did not lease both due rows');
}

if ($repo->ack($id1, 'not-the-owner', ['status' => 202])) {
    WP_CLI::error('stale-owner ack was accepted');
}
if (! $repo->ack($id1, $owners[$id1], ['status' => 202, 'remote_item_id' => 'item-1'])) {
    WP_CLI::error('ack failed');
}
if (! $repo->release($id2, $owners[$id2], ['retryable' => true, 'status' => 503, 'error' => 'temporary'], time() + 30)) {
    WP_CLI::error('release failed');
}

$released = $repo->get($id2);
WP_CLI::log('released state=' . $released['state'] . ' attempts=' . $released['attempts']);
if ($released['state'] !== Repository::STATE_PENDING || (int) $released['attempts'] !== 1) {
    WP_CLI::error('release did not return the row to pending with one attempt');
}

if (count($repo->claim(10, 60)) !== 0) {
    WP_CLI::error('a backing-off row was claimed before its next attempt');
}

$id3 = $repo->enqueue('smoke-key-3', Repository::KIND_RUN_MARKER, 0, ['client_run_key' => 'k', 'mode' => 'incremental'], null, $repo->allocateRevision());
foreach ($repo->claim(10, 60) as $claimedRow) {
    if ((int) $claimedRow['id'] === $id3) {
        $repo->deadLetter($id3, (string) $claimedRow['lease_owner'], ['status' => 422, 'error' => 'permanent']);
    }
}
$dead = $repo->get($id3);
WP_CLI::log('dead=' . $repo->deadCount() . ' state=' . $dead['state']);
if ($dead['state'] !== Repository::STATE_DEAD) {
    WP_CLI::error('non-retryable row was not dead-lettered');
}

WP_CLI::success('outbox repository smoke passed');
