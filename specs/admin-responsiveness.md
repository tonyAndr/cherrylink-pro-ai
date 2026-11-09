# WordPress request isolation

This contract separates fast UI requests from indexing work. It is an additive
step on the unpublished modernization branch; it does not enable the backend
async protocol or publish a plugin release.

## Request contexts and limits

| Context | Network behavior | Fallback |
|---|---|---|
| Ordinary settings/card rendering | Cached/local state only | Last known counts, unknown/pending state |
| License form submission / retry | Save local key/state and schedule WP-Cron; no licensing HTTP | Pending notice and retry control; scheduling failure is an error |
| License background callback | One 5-second call; redirects disabled | Preserve a matching unexpired license on outage; bounded delayed retries |
| Periodic GitHub update check | Cron/CLI only through the updater filter; dashboard hooks cannot fetch GitHub | Cached update metadata; explicit update actions remain available |
| Scan start | Persist manifest phase and enqueue Action Scheduler | Preserve prior plan; report scheduling failure |
| Scan status / tick | Local status; tick requests async runner dispatch only | WP-Cron remains fallback; never consume queue inline |
| Readiness AJAX | 2 seconds per API call, 4 seconds shared per client | Preserve cached counts; one cross-request refresh lock; 30-second outage cooldown |
| Explicit server diagnostics | At most three 2-second probes, 6-second shared deadline | Skip authenticated probes if health fails; 30-second cache and cross-request lock |
| Editor/statistics AJAX | Interactive Client defaults: 5 seconds per call, 8 seconds shared per client | Existing empty/error UI; no transport-level retry loop |
| Background synchronous scan | Explicit background Client retains 15/60/120-second route budgets | SyncService owns persisted retries/cursors |

Transport budgets use monotonic time and shrink subsequent request timeouts.
No new network request starts once a shared deadline is exhausted. Redirects
are disabled so they cannot multiply requests or forward license headers to a
redirect target. Browser timeouts bound the UI wait separately; aborting browser
AJAX does not itself stop PHP execution.

`Support\RequestLock` uses a non-autoloaded option and atomic `add_option`.
Expired/released tokens are removed with SQL compare-and-delete, so an old
request cannot release another request's newer lease. An unavailable lock returns
cached/pending data immediately. It is a short mutex, not a durable job queue.

## License checks

Daily and requested validation run in WP-Cron. Single-event retries are owned by
License (at most three attempts, delays 30 and 120 seconds); the HTTP client never
sleeps/retries. Scheduler arguments contain no license key. Domain identity comes
from the stored home URL, including when cron has no HTTP_HOST. Response shape,
current key identity and expiry are checked; an outage cannot extend expiry or
transfer a prior key's activation to a new key. Reset clears queued validation. Changes through imports or other option writers
also invalidate the previous activation and schedule the new key.
WP-Cron must be driven by site traffic or a configured system cron. A pending
activation is not successful activation; the UI asks the operator to refresh.

## Remaining P1A gates

This checkpoint completes settings/license/scan-start isolation and the PHP
interactive timeout separation. It does **not** claim full P1A acceptance:

- Live post save/unpublish/delete still call the synchronous API. Those hooks
  explicitly retain the background transport budget for compatibility until a
  durable immutable outbox and source sequence exist. Shortening their timeout
  alone could lose updates. Implement P1.3/P1.4/P2.3 before release.
- The explicit legacy “truncate index” POST still performs bounded API work.
- The expert TCP debugger retains its separate legacy implementation.
- Stale-response cancellation in the classic/Gutenberg editors remains a focused
  follow-up; no generated Gutenberg assets change in this checkpoint.
- Scheduling a scan is now fast, but cross-run scan generation fencing and
  concurrency with future live outbox delivery remain part of that next design.

Do not release this as “all WordPress requests are non-blocking.” The release
requires zero external HTTP during ordinary rendering and live save/delete,
plus durable recovery when scheduling or PHP execution fails.

## Local validation checkpoint (2026-11-09)

PHP 7.4 PHPUnit: 89 tests / 267 assertions passed. The final affected licensing
subset passes 13 tests / 31 assertions after review fixes. PHPStan level 6 is
clean. Changed PHP service files have zero PHPCS errors (existing line-length
warnings remain); the entry file header spacing was normalized. Handwritten
admin JS passes `node --check`; English messages compile with `msgfmt
--check-format`. No Gutenberg source/bundle changed, so its build/test cycle was
not rerun. No API containers, load tests, remote calls or production deployments
were used for validation.

Focused coverage includes cache-only rendering, failed-read cache preservation,
lock contention/token release, transport deadline exhaustion, background budget
compatibility, explicit diagnostics, queued manifest start/scheduling failure,
async-only scheduler ticks, periodic updater isolation, key replacement/import,
malformed responses, cron identity, outage preservation and failed validation
scheduling. A full WordPress browser/outage drill remains a release gate after
the live outbox is implemented; these are unit/static checks, not that drill.
