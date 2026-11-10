<?php
/**
 * Joint delivery + repository test against real MySQL.
 *
 * The unit tests mock Repository and AsyncClient separately, which hid an
 * interface mismatch (a missing `retryable` flag dead-lettered every transient
 * failure). Here Delivery runs against the real Repository and the real
 * AsyncClient normalization; only the HTTP transport is faked, so the contract
 * between the three components is actually exercised.
 */

use CherryLink\Api\AsyncClient;
use CherryLink\Api\Client;
use CherryLink\Indexing\Outbox\Delivery;
use CherryLink\Indexing\Outbox\Repository;

/** Replaces only the transport boundary; everything above it is real. */
class OutboxSmokeTransport extends Client
{
    /** @var array<int,array<string,mixed>> */
    public $responses = [];

    public function __construct()
    {
    }

    public function request_result(
        string $method,
        string $path,
        array $body = [],
        int $timeout = 0,
        array $extra_headers = []
    ): array {
        $next = array_shift($this->responses);

        return $next ?? [
            'ok' => false, 'status' => 0, 'headers' => [], 'body' => null,
            'error' => 'no more fake responses', 'retry_after' => null,
        ];
    }
}

/**
 * @param array<string,mixed> $row
 */
function outbox_smoke_state(Repository $repo, int $id): array
{
    $row = $repo->get($id);
    if ($row === null) {
        WP_CLI::error('row vanished: ' . $id);
    }

    return $row;
}

/**
 * @param array<string,mixed> $response
 * @return array{0:Delivery,1:Repository,2:OutboxSmokeTransport}
 */
function outbox_smoke_delivery(array $responses): array
{
    $transport = new OutboxSmokeTransport();
    $transport->responses = $responses;
    $repo = new Repository();

    return [new Delivery($repo, new AsyncClient($transport)), $repo, $transport];
}

$repo = new Repository();
$now  = time();

// --- Case 1: transient 503 must be retried, not dead-lettered -----------------
$id = $repo->enqueue(
    'smoke-delivery-503-' . $now,
    Repository::KIND_UPSERT,
    21,
    ['post_id' => 21, 'title' => 'T', 'text' => 'body', 'url' => 'http://x/21', 'lang' => 'ru',
     'content_hash' => 'h', 'modified_gmt' => '2026-01-01 00:00:00', 'link_html' => '<p>x</p>'],
    null,
    $repo->allocateRevision()
);

[$delivery, $repo, $transport] = outbox_smoke_delivery([
    ['ok' => false, 'status' => 503, 'headers' => [], 'body' => ['code' => 'async_queue_unavailable'],
     'error' => 'HTTP 503', 'retry_after' => null],
]);
$summary = $delivery->run();
$state   = outbox_smoke_state($repo, $id);
WP_CLI::log(sprintf(
    '503: retried=%d dead=%d state=%s attempts=%d next=%s',
    $summary['retried'],
    $summary['dead'],
    $state['state'],
    $state['attempts'],
    (string) $state['next_attempt_gmt']
));
if ($state['state'] !== Repository::STATE_PENDING) {
    WP_CLI::error('a transient 503 was not returned to pending (dead-lettered instead?)');
}
if ($state['next_attempt_gmt'] === null || $state['next_attempt_gmt'] === '') {
    WP_CLI::error('retry did not schedule a next attempt');
}

// --- Case 2: Retry-After is persisted and honoured ---------------------------
$id = $repo->enqueue(
    'smoke-delivery-429-' . $now,
    Repository::KIND_DELETE,
    22,
    ['post_id' => 22, 'operation' => 'delete'],
    null,
    $repo->allocateRevision()
);

[$delivery, $repo] = outbox_smoke_delivery([
    ['ok' => false, 'status' => 429, 'headers' => ['retry-after' => '42'], 'body' => ['code' => 'async_capacity_exhausted'],
     'error' => 'HTTP 429', 'retry_after' => 42],
]);
$before = time();
$delivery->run();
$state = outbox_smoke_state($repo, $id);
WP_CLI::log('429: state=' . $state['state'] . ' retry_after=' . (string) $state['last_retry_after']);
if ($state['state'] !== Repository::STATE_PENDING) {
    WP_CLI::error('429 was not retried');
}
if ((int) $state['last_retry_after'] !== 42) {
    WP_CLI::error('Retry-After was not persisted');
}
if (strtotime((string) $state['next_attempt_gmt'] . ' UTC') < $before + 40) {
    WP_CLI::error('Retry-After was not honoured in next_attempt_gmt');
}

// --- Case 3: permanent 422 dead-letters -------------------------------------
$id = $repo->enqueue(
    'smoke-delivery-422-' . $now,
    Repository::KIND_UPSERT,
    23,
    ['post_id' => 23, 'text' => 'x'],
    null,
    $repo->allocateRevision()
);
[$delivery, $repo] = outbox_smoke_delivery([
    ['ok' => false, 'status' => 422, 'headers' => [], 'body' => ['code' => 'run_item_limit_exceeded'],
     'error' => 'HTTP 422', 'retry_after' => null],
]);
$delivery->run();
$state = outbox_smoke_state($repo, $id);
if ($state['state'] !== Repository::STATE_DEAD) {
    WP_CLI::error('permanent 422 was not dead-lettered (state=' . $state['state'] . ')');
}

// --- Case 4: 202 without a receipt must NOT be acknowledged ------------------
$id = $repo->enqueue(
    'smoke-delivery-empty202-' . $now,
    Repository::KIND_UPSERT,
    24,
    ['post_id' => 24, 'text' => 'x'],
    null,
    $repo->allocateRevision()
);
[$delivery, $repo] = outbox_smoke_delivery([
    ['ok' => true, 'status' => 202, 'headers' => [], 'body' => [], 'error' => '', 'retry_after' => null],
]);
$delivery->run();
$state = outbox_smoke_state($repo, $id);
WP_CLI::log('empty-202: state=' . $state['state'] . ' error=' . $state['last_error']);
if ($state['state'] === Repository::STATE_DELIVERED) {
    WP_CLI::error('an empty 202 body was acknowledged as durable delivery');
}

// --- Case 4b: 202 with a blank item id must NOT be acknowledged -------------
$id = $repo->enqueue(
    'smoke-delivery-blank-id-' . $now,
    Repository::KIND_UPSERT,
    27,
    ['post_id' => 27, 'text' => 'x'],
    null,
    $repo->allocateRevision()
);
[$delivery, $repo] = outbox_smoke_delivery([
    ['ok' => true, 'status' => 202, 'headers' => [], 'body' => [
        'protocol_version' => 1, 'status' => 'accepted',
        'accepted_items' => [''], 'accepted_count' => 1, 'replayed' => false,
    ], 'error' => '', 'retry_after' => null],
]);
$delivery->run();
$state = outbox_smoke_state($repo, $id);
WP_CLI::log('blank-item-id: state=' . $state['state'] . ' error=' . $state['last_error']);
if ($state['state'] === Repository::STATE_DELIVERED) {
    WP_CLI::error('a blank accepted item id was treated as durable delivery');
}

// --- Case 5: a real receipt is acknowledged with its item id -----------------
$id = $repo->enqueue(
    'smoke-delivery-ok-' . $now,
    Repository::KIND_UPSERT,
    25,
    ['post_id' => 25, 'text' => 'x'],
    null,
    $repo->allocateRevision()
);
[$delivery, $repo] = outbox_smoke_delivery([
    ['ok' => true, 'status' => 202, 'headers' => [], 'body' => [
        'protocol_version' => 1, 'status' => 'accepted', 'run_id' => null, 'batch_id' => null,
        'mutation_group' => null, 'accepted_items' => ['item-25'], 'accepted_count' => 1, 'replayed' => false,
    ], 'error' => '', 'retry_after' => null],
]);
$delivery->run();
$state = outbox_smoke_state($repo, $id);
WP_CLI::log('accepted: state=' . $state['state'] . ' remote=' . (string) $state['remote_item_id']);
if ($state['state'] !== Repository::STATE_DELIVERED) {
    WP_CLI::error('a valid receipt was not acknowledged');
}
if ($state['remote_item_id'] !== 'item-25') {
    WP_CLI::error('the accepted item id was not persisted');
}
// Durable acceptance releases the local payload bytes.
if (\strlen((string) $state['payload']) === 0) {
    WP_CLI::error('payload was cleared before durable acceptance');
}

// --- Case 6: crash/reclaim cannot bypass the attempt limit -------------------
$id = $repo->enqueue(
    'smoke-delivery-exhaust-' . $now,
    Repository::KIND_UPSERT,
    26,
    ['post_id' => 26, 'text' => 'x'],
    null,
    $repo->allocateRevision()
);

// Simulate repeated crash-after-claim cycles: raise attempts to the cap and
// expire the lease, then let the sweep reclaim it.
global $wpdb;
$wpdb->query($wpdb->prepare(
    "UPDATE {$repo->tableName()} SET state = 'leased', attempts = %d, lease_owner = 'crashed', lease_expiry_gmt = %s WHERE id = %d",
    Repository::MAX_ATTEMPTS,
    gmdate('Y-m-d H:i:s', time() - 5),
    $id
));
$reclaimed = $repo->reclaimExpired();
$state     = outbox_smoke_state($repo, $id);
WP_CLI::log('exhausted: reclaimed=' . $reclaimed . ' state=' . $state['state']);
if ($state['state'] !== Repository::STATE_DEAD) {
    WP_CLI::error('a lease that exhausted its attempt budget was reclaimed instead of dead-lettered');
}

// --- Clean up the rows this smoke created -----------------------------------
$wpdb->query($wpdb->prepare(
    "DELETE FROM {$repo->tableName()} WHERE event_key LIKE %s",
    'smoke-delivery-%'
));

WP_CLI::success('outbox delivery + repository joint smoke passed');
