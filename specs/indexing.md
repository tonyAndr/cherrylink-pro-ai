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
  scheduling) is owned by the delivery service, not the repository.
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
- `Support\Capabilities` caches the decision (60 s positive / 300 s negative).
  Async is used only when the API advertises the protocol **and** the tenant is
  in `async` mode with both `runs` and `mutations` enabled.
- `Indexing\Outbox\Capture` enforces the capture-order rule: allocate the source
  revision immediately before reading the post, then persist an immutable
  payload. Failed captures keep the index-dirty marker (fail closed).
- `Indexing\Outbox\Delivery` is the single retry owner on the WordPress side: one
  Action Scheduler turn reclaims expired leases, claims a bounded batch, and
  delivers each row under its stable `event_key` as the idempotency key. It
  retries transport/408/429/5xx with bounded backoff and valid `Retry-After`,
  dead-letters permanent 4xx, and never sleeps inside a request.
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
