# Indexing and asynchronous delivery

## Purpose

Keep each tenant's WordPress index and link graph correct while moving future
embedding work out of WordPress requests. The current release uses the
synchronous API with a durable Action Scheduler scan. The planned backend queue
is additive and stays disabled until capability negotiation and the API
protocol in [`async-indexing.md`](../../api/specs/async-indexing.md) are
available.

## Current synchronous contract

- `Api\\Client` is the only PHP transport boundary. Structured results retain
  HTTP status, response body, normalized headers, and `Retry-After`; legacy
  array/count wrappers remain compatible.
- `Indexing\\SyncService` builds a manifest diff and processes `upsert -> links ->
  prune` through Action Scheduler. It never advances a cursor after a failed or
  partial API operation.
- Manifest retrieval is fail-closed. A failed page leaves the previous plan
  untouched and schedules a bounded, fenced retry; `force_full` is persisted
  through that manifest phase and still prunes orphan IDs.
- Retry ownership is in `SyncService`, not `Api\\Client` or WooCommerce Action
  Scheduler: retry transport failures, 408, 429, and 5xx; honor valid
  `Retry-After`; otherwise use bounded exponential backoff with jitter. A
  permanent 4xx or exhausted attempt is visible in local state.
- The plugin sends plain embedding text and raw link HTML. It does not parse
  HTML in the browser or add a client-side parser.

Scan start queues the manifest phase and returns without API I/O. A browser
tick only requests non-blocking Action Scheduler dispatch; it never runs indexing
jobs inline. Read [request isolation](admin-responsiveness.md) for timeout contexts.

Persisted synchronous state is exposed through the existing admin AJAX actions
and options `cherrylink_sync_state` / `cherrylink_sync_plan`. A terminal
successful run clears the plan and the index-dirty marker; a failure retains
the cursor, plan, and failure metadata for operator recovery.

## Bulk async run (implemented)

`Indexing\AsyncBulkScan` owns the durable bulk workflow for async tenants:

1. **manifest** — fetch the manifest, diff against published posts, persist the
   plan, and allocate the run marker revision (older than every captured item).
2. **create** — `POST /v1/index-runs` with the stored `client_run_key`, mode and
   marker; persist `run_id`/`generation` before uploading. Snapshot/force-full
   runs also send the published count and a sha256 membership digest.
3. **upload** — batches of 20. Each item allocates its source revision **before**
   the authoritative read (`Outbox\Capture::build()`), so a concurrent live save
   is a newer outbox mutation and wins. The exact batch (ids, revisions, payloads)
   is persisted in `cherrylink_async_batch` **before** it is sent and reused on
   retry, so a lost `202` replays byte-identically and cannot trip
   `idempotency_key_reused`. Batch ids are `client_run_key:u:<offset>`.
4. **deletes** — batches of 50 via `/deletes` with `client_run_key:d:<offset>`.
   Every planned delete is re-read first: if the post was republished during the
   scan it is skipped (counted in `skipped_deletes`) instead of being tombstoned.
   Snapshot runs plan no explicit deletes; absence is pruned at seal.
5. **seal** — `/seal` with `client_run_key:seal`; only then does the backend
   prune absent snapshot memberships.
6. **poll** — `GET /v1/index-runs/{id}` with delayed actions (10–60 s). Terminal
   states end the scan.

Everything the chain needs (run id, phase, cursor, counts, poll/retry timers,
progress) is persisted in the non-autoloaded `cherrylink_async_run` option, so
closing the browser, a PHP timeout, or a WordPress restart resumes the run at the
same cursor. An independent WP-Cron sweep (`cherrylink_async_bulk_sweep`)
re-queues the persisted phase/cursor when no turn is queued, covering a crash
between saving the cursor and scheduling the next action. Every callback carries
the run key and is rejected for a different run, and a worker lock prevents two
turns from running the same scan concurrently.

Inventory reads fail closed: a failed post-list query raises
`InventoryUnavailableException` and is retried, never interpreted as "no posts"
(which would declare an empty snapshot or plan a full delete). Pause stops new work; resume restarts from the persisted phase and
cursor; cancel cancels an accepting remote run and otherwise just stops the
local chain. Stale Action Scheduler callbacks (wrong phase/cursor/attempt/mode)
are no-ops.

Uploading and indexing are distinct states, and the admin status exposes both:
`uploading`, `accepted`, `processing`, `vectors_applied`/`links_applied` and the
error/retry fields. Upload completion is never reported as indexing completion.
The existing AJAX actions are unchanged. `SyncService` delegates to the adapter
based on the tenant's **durable mode** (`handlesTenant()`), not current API
availability: an async tenant whose acceptance is temporarily disabled pauses
with `waiting=true` and probes every 60 s, rather than falling back to
synchronous writes the API rejects with `409 async_tenant_requires_queue`.

## Enrollment and mode changes (plugin side)

Enrollment is operator-initiated: the control plane binds the tenant's source
epoch and switches it to `async`; the plugin never invents or rotates an epoch.
The plugin's responsibilities are:

- **Route by durable mode.** `asyncEnabled()` (tenant mode) decides whether the
  outbox/bulk run is used. Availability only decides whether work can flow *now*.
- **Do not queue unusable work during enrollment.** While the API reports
  `mutations: false` (snapshot required / enrollment pending) or async acceptance
  is disabled, a licensed save records the index-dirty marker and queues no live
  mutation; the next snapshot run uploads the current state.
- **Seed with a snapshot.** `AsyncBulkScan` forces `mode=snapshot` whenever
  mutations are not accepted, so the first run enrolls instead of attempting an
  incremental diff. A populated existing index still needs the operator's verified
  inventory/bridge (`api/specs/source-lifecycle.md`); the plugin does not
  reconcile it on its own.
- **Defer, don't discard.** A `409` whose code describes a tenant-state problem
  (`index_run_state_invalid`, `async_tenant_requires_queue`,
  `async_indexing_disabled`, `source_revision_exhausted`) returns the outbox row to
  pending with its attempt budget reset and a 300 s delay, so an operator mode
  change does not destroy events. Payload errors still dead-letter.

## Planned capability-negotiated async flow

When the API advertises protocol version 1 and the tenant is enabled:

1. Allocate a source epoch/revision from the plugin outbox migration before
   reading each post. Save/delete hooks persist immutable payloads or tombstones
   and schedule lightweight Action Scheduler delivery.
2. Create a run with a stable client run key and, for snapshots, the published
   post count plus digest. Persist the returned `run_id` before uploading.
3. Upload batches with stable `batch_id` keys. A `202` means only that the API
   committed the payload and receipt; it does not mean the vector or links are
   ready. Release local payload bytes only after the receipt/item IDs are
   durable.
4. Seal only after every planned batch is accepted. Poll the backend run state
   with delayed actions and expose accepted, processing, retrying, applied,
   superseded, and failed counts in the existing admin status response.
5. A new save, unpublish, or delete during a run is a newer outbox event. It is
   not silently overwritten by the older scan; the backend source revision and
   tombstone rules decide ordering.

The plugin must not run the synchronous scan and async run for the same tenant
at once. If capabilities are missing, stale, or the API is disabled, it keeps
using the current synchronous path. Switching modes requires stopping
acceptance, draining or pausing remote work, reconciling manifest/link counts,
and then changing the tenant mode; rollback never deletes queue rows or wipes
vectors.

## Retry and scheduler invariants

- There is one retry owner on each side of the acceptance boundary. The
  plugin retries delivery until a durable API `202`; the backend retries
  execution after acceptance.
- Every delayed Action Scheduler callback carries phase, offset/identity,
  attempt, and the persisted run mode. Stale callbacks are no-ops.
- A retry never sleeps in a PHP request and never advances a cursor before
  durable acceptance. Replaying the same idempotency key/source revision is
  safe.
- The API's `Retry-After` is bounded before scheduling. Action Scheduler's
  normal one-off action completion is not treated as a failed-action retry.

## Local outbox schema and lifecycle

The plugin owns a prefix-aware outbox (`{$wpdb->prefix}cherrylink_outbox`) plus a
singleton `cherrylink_outbox_state` row for the source epoch/sequence. It is
created and upgraded with `dbDelta` on activation and after a plugin update,
version-gated by the `cherrylink_db_version` option. A defensive `plugins_loaded`
check re-runs the install when the version is stale, so an interrupted
activation/update recovers on the next request instead of proceeding on a missing
table. `dbDelta` is forward-only: columns are added but never dropped.

- Outbox rows are immutable: `event_key`, `source_epoch`, `source_revision`,
  `post_id`, `kind`, and `payload` are written only at insert time; delivery
  transitions update metadata columns only and are fenced by `lease_owner`.
- The canonical ordering pair is `(source_epoch, source_revision)`. Revisions
  come from one atomic monotonic MySQL counter, and a replayed event key returns
  the existing row without allocating a new revision.
- The repository exposes claim/ack/release/reclaim/dead-letter mechanics and
  enforces a maximum attempt budget. Retry policy (backoff, Action Scheduler
  scheduling) is owned by the delivery service, not the repository. Fenced
  transitions report failure when zero rows match, and the schema version is
  recorded only after both tables are verified to exist (dbDelta can fail
  without throwing).
- Delivery rows record the last HTTP status, `Retry-After`, a sanitized error,
  and the remote item/run id once a `202` is durably accepted.

Privacy / data handling: the outbox holds only the site's own post data (title,
plain embedding text, URL, content hash) required to deliver indexing work. Both
tables and the version option are removed on uninstall. No queue data is stored
in an autoloaded WordPress option.

## Implemented plugin async surface

The async adapter is implemented and gated per tenant; the synchronous path remains
the default and the fallback.

- `Api\AsyncClient` speaks the exact shipped routes: `GET /v1/capabilities`,
  `POST /v1/index-runs`, `/v1/index-runs/{id}/documents`, `/deletes`, `/seal`,
  `GET /v1/index-runs/{id}`, `/cancel`, `/pause`, `/resume`,
  `POST /v1/index-mutations`, and `GET /v1/index-items?item_id=…`. Every accepted
  body carries `protocol_version: 1`. A `202` is treated as durable acceptance,
  never execution, and a non-object body is a failure.
- `Support\Capabilities` is **cache-only on the request path**: `asyncEnabled()`
  reads the transient and never performs HTTP, so `wp_insert_post`/`delete_post`
  keep their no-external-I/O contract. `Support\CapabilitiesRefresh` refreshes the
  snapshot from WP-Cron/CLI on a registered **5-minute** schedule (shorter than
  the 15-minute snapshot TTL) and on activation/update. An established async
  tenant is also recorded in the non-autoloaded `cherrylink_async_tenant` option:
  snapshot expiry, eviction, or an API outage cannot move it to synchronous
  writes (which the backend rejects with `409 async_tenant_requires_queue`). Only
  an explicit negative capability answer (sync mode / async disabled) clears that
  marker. Async is used only when the API advertises the protocol **and** the
  tenant is in `async` mode with both `runs` and `mutations` enabled.
- `Indexing\Outbox\Capture` enforces the capture-order rule: allocate the source
  revision, then **re-read the post from the database** (the `WP_Post` passed to
  `wp_insert_post` may belong to an older request), and choose upsert vs tombstone
  from that fresh read. A stale unpublish therefore cannot tombstone content a
  newer save republished. The deciding row is read directly from `$wpdb`
  (bypassing the object cache), because `clean_post_cache()` is a no-op while
  cache invalidation is suspended; if the captured object does not match that
  row's status and `post_modified_gmt`, the capture is refused
  (`StaleCaptureException`) and the caller keeps the dirty marker instead of
  enqueuing stale content. Failed captures keep the index-dirty marker (fail
  closed); a failed sequence update raises instead of capturing without a
  durable revision.
- `Indexing\Outbox\Delivery` is the single retry owner on the WordPress side: one
  Action Scheduler turn reclaims expired leases, claims a bounded batch, and
  delivers each row under its stable `event_key` as the idempotency key. It
  retries transport/408/429/5xx with bounded backoff and valid `Retry-After`
  (the `retryable` flag is passed explicitly to the repository, which also
  derives it from the status when a caller omits it), dead-letters permanent
  4xx, and never sleeps inside a request. A 2xx without the documented receipt
  fields is a failure, not an acknowledgement: item ids must be non-empty
  strings, `accepted_count` must equal both the returned id count and the number
  of documents uploaded, and a seal must report `state: sealed`. An independent WP-Cron sweep
  (`cherrylink_outbox_sweep`, every 5 minutes) reclaims expired leases so a
  crashed turn cannot strand rows; leases that exhausted the attempt budget are
  dead-lettered instead of being retried forever.
- `Indexing\IndexHooks` writes an outbox event for a licensed save/unpublish/delete
  when the tenant is async-capable and performs no API call in that request;
  otherwise it keeps the existing synchronous adapter. Probe failures fall back
  to sync.

## Acceptance criteria

- [ ] Current synchronous scans preserve the API response compatibility and
      pass the plugin QA suite.
- [ ] Manifest, batch, link, and prune failures retain the exact cursor and
      expose retry/terminal state.
- [ ] Async capability fallback leaves old plugins and old APIs functional.
- [ ] Outbox rows are immutable, tenant-scoped, idempotent, leased, and
      recoverable after a worker or PHP crash.
- [ ] Save/delete events racing a scan are ordered by source epoch/revision;
      no older event can resurrect a newer delete.
- [ ] Closing the browser does not stop delivery or backend processing.
- [ ] Admin progress distinguishes accepted from applied and shows failed or
      dead-lettered work without claiming completion.

## Non-obvious constraints

- Do not put an outbox or queue in an autoloaded WordPress option.
- Do not add client-side HTML parsing; raw HTML is parsed by the backend.
- Do not push or deploy capability/async changes until the API ADR, Qdrant CAS
  evidence, migration tests, and mixed-load benchmarks are complete.
