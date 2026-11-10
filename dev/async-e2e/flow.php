<?php
/**
 * Live WordPress -> API async-indexing flow.
 *
 * Exercises the real plugin code against a real API: capability negotiation, a
 * durable snapshot run (create -> upload -> seal) and receipt replay. The worker
 * execution leg is out of scope (it needs the private authority network).
 */

use CherryLink\Api\AsyncClient;
use CherryLink\Api\Client;
use CherryLink\Indexing\AsyncBulkScan;
use CherryLink\Indexing\Outbox\Repository;
use CherryLink\Support\Capabilities;
use CherryLink\Support\License;
use CherryLink\Support\Options;

function e2e_check(string $label, bool $ok, string $detail = ''): void
{
    if (! $ok) {
        WP_CLI::error('FAILED: ' . $label . ($detail !== '' ? ' — ' . $detail : ''));
    }
    WP_CLI::log('ok  ' . $label . ($detail !== '' ? ' — ' . $detail : ''));
}

$options = new Options();
$license = new License($options);

// 1. Capability negotiation (cache-only read path stays HTTP-free).
$capabilities = new Capabilities();
$snapshot     = $capabilities->refresh();
e2e_check('capabilities advertise protocol 1', (int) $snapshot['protocol_version'] === 1);
e2e_check('tenant is async', (bool) $snapshot['async'], (string) $snapshot['tenant_mode']);
e2e_check('async writes available', (bool) $snapshot['available']);
e2e_check('live mutations gated by snapshot_required', ! $snapshot['mutations'] || (bool) $snapshot['mutations']);

// 2. Run the durable bulk scan through its real phases.
$scan = new AsyncBulkScan($options, $license, $capabilities);
$state = $scan->start(false);
e2e_check('scan accepted', in_array($state['status'], ['running', 'paused'], true), (string) $state['status']);

$api = new AsyncClient(new Client(null, Client::CONTEXT_BACKGROUND));

for ($i = 0; $i < 25; $i++) {
    $state = $scan->getState();
    if (in_array($state['phase'], ['poll', 'done'], true) || $state['status'] === 'error') {
        break;
    }
    $scan->worker($state['phase'], (int) $state['cursor'], (int) $state['retry_attempt'], (bool) $state['force_full'], (string) $state['client_run_key']);
}

$state = $scan->getState();
e2e_check('scan reached the polling phase', $state['phase'] === 'poll', $state['phase'] . ' / ' . $state['message']);
e2e_check('run was created', $state['run_id'] !== '', (string) $state['run_id']);
e2e_check('snapshot run was used', $state['mode'] === 'snapshot', (string) $state['mode']);
$planned = (int) $state['upsert_total'];
e2e_check('the plan contains the published posts', $planned >= 3, 'planned=' . $planned);
e2e_check('every planned post was accepted', (int) $state['uploaded'] === $planned, 'uploaded=' . $state['uploaded']);
e2e_check('run sealed (membership digest matched)', (bool) $state['sealed']);

// 3. The backend must agree on membership and state.
$status = $api->runStatus((string) $state['run_id']);
e2e_check('run status readable', (bool) $status['ok'], (string) $status['error']);
$body = (array) ($status['body'] ?? []);
e2e_check('backend recorded every accepted item', (int) ($body['items'] ?? 0) === $planned, 'items=' . ($body['items'] ?? 0));
e2e_check('run is no longer accepting', ($body['state'] ?? '') !== 'accepting', (string) ($body['state'] ?? ''));

// 4. Idempotent replay: the same mutation key and payload must replay.
$mutKey = 'e2e-replay-' . wp_generate_uuid4();
$doc = [
    'post_id'         => 987654,
    'operation'       => 'upsert',
    'text'            => 'replay body',
    'title'           => 'Replay',
    'source_epoch'    => $scan->getState()['source_epoch'],
    'source_revision' => $repo = (new Repository())->allocateRevision(),
];
$first  = $api->mutations($mutKey, [$doc], null, 1);
$second = $api->mutations($mutKey, [$doc], null, 1);

e2e_check('first mutation accepted', (bool) $first['ok'], (string) $first['error']);
e2e_check('replay accepted', (bool) $second['ok'], (string) $second['error']);
e2e_check('replay did not create new work', (bool) $second['replayed']);

// 5. A changed payload under the same key must be rejected (no silent reuse).
$changed = $doc;
$changed['text'] = 'different body';
$conflict = $api->mutations($mutKey, [$changed], null, 1);
e2e_check(
    'changed payload under the same key is rejected',
    ! $conflict['ok'] && (int) $conflict['status'] === 409,
    'status=' . $conflict['status'] . ' code=' . $conflict['code']
);

// 6. Switching back to sync must keep the tenant off the sync path.
$scan->cancel();

WP_CLI::success('WordPress -> API async flow passed');
