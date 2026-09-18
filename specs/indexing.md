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
