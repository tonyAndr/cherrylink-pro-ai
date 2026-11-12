# Connect, free plan and the no-calls-before-connect guarantee

Status: implemented on `feature/free-mode` (Steps 3 and 3b of `FREE-PRO-PLAN-2026-11-12.md`); not released.
Backend contracts: `control/specs/licensing.md` (check + connect), `api/specs/plans.md` (plan limit).

## Guarantee (WordPress.org guideline 7)
With no key stored the plugin makes **no external request** on any path. The only exception is the
Connect button, which is an explicit administrator action.

Audited paths and how each is closed:
| Path | Closed by |
|---|---|
| `License::callHome` (check, daily cron, scheduled validation) | returns false for an empty key; validation is only scheduled with a key |
| `Api\Client::request_result` (all API calls) | existing "no license key configured" short-circuit (also `AsyncClient`, readiness, logs) |
| `Api\Client::probe` / `diagnostics` (health, no auth) | now also refuses without a key |
| `Support\CapabilitiesRefresh` / `Capabilities::refresh` | cron callback requires `isLicensed()` |
| `Support\PluginReports` | `enabled()` = connected; not connected = nothing queued, nothing sent, queue cleared |
| `Indexing\IndexHooks`, `SyncService`, `AsyncBulkScan` | `isLicensed()` gates (index is only marked dirty) |
| `Api\Relay` | only adjusts connect address of our own requests |
| Admin app (`js/cherry-app.{js,css}`), editor bundle | all local files; no CDN/remote asset |
| `env-info/`, `updater/` | env-info only reads local data. `updater/` (GitHub PUC) exists only in the legacy zip; the `.org` zip (`dev/build-org-zip.sh`) removes it and the main file guards it with `file_exists` |
Tests: `FreeModeTest::testNothingReachesTheNetworkWithoutAKey`.

## Licensing URL
`License::licensingUrl()` is the single base for check and connect (default
`https://api.cherrylink.app/cherrylink-pro-licensing/`); define `CHERRYLINK_LICENSING_URL` in wp-config for local dev.

## Free keys in the license
- `callHome` accepts `key_valid: 1` with `expires_at: null` **only** when `plan === 'free'`; responses without
  `plan` keep the strict legacy checks.
- `plan`, `post_limit`, `features`, `paid_expired` are stored in `linkate_posts_meta` (cleared for legacy responses
  and on key change).
- `isValid()` skips the expiry test for stored plan `free` (also the `paid_expired` fallback, whose `expires_at` is
  past), including the outage fallback and schedule-failure paths. Other plans still need a future expiry.
- `isConnected()` = key stored + valid. `isLicensed()` is unchanged (same validity gate) and remains the gate for
  indexing/panel code; for a free key it means "connected".

## Connect flow (`Support\Connect`)
1. The admin wizard (React, `admin.php?page=cherrylink`) posts to `admin-post.php?action=cherrylink_connect` (nonce, `cherrylink_settings`).
2. Plugin stores a random nonce in transient `cherrylink_connect_nonce` (10 min), POSTs JSON
   `{domain, nonce, proof_urls}` (domain = `home_url()` host) to `<licensing base>connect` (20 s), then deletes the transient.
3. `GET /wp-json/cherrylink/v1/connect-proof` (public) returns `{"nonce": ...}` only while the transient exists,
   else 404. The authority fetches it over HTTPS as domain proof. `proof_urls` lists `rest_url('cherrylink/v1/connect-proof')`
   and `admin_url('admin-ajax.php?action=cherrylink_connect_proof')` (non-https entries dropped; field omitted if none
   remain), so plain permalinks, subdirectory installs and REST-blocking plugins still prove. The ajax twin
   (`wp_ajax_nopriv_` + `wp_ajax_cherrylink_connect_proof`, `Connect::handleAjaxProof`) answers `{"nonce": ...}` while
   the transient exists, else 404.
4. 200: `license_key` is stored in the key option, plan fields and `key_valid` in meta (no second round trip), a
   capabilities refresh is scheduled on cron, and the user is redirected back to `Connect::settingsUrl()` (`admin.php?page=cherrylink`) with `cl_connect=ok`.
5. Errors map to `cl_connect=<code>` notices: `domain_proof_failed` (409), `rate_limited` (429, `Retry-After`),
   `invalid_domain` (422), `subscription_not_eligible` (409), everything else `unavailable`.
"I already have a key" in the wizard saves it via `cherrylink_admin_license` (`set_key`).

## Setup wizard
Part of the React admin app (`specs/admin-ui.md`): Connect -> content types -> Analyze/Skip, finished by
`cherrylink_admin_setup_done`. No activation redirect. Plugins screen only: dismissible notice "connect CherryLink"
until connected (user meta `cherrylink_connect_notice_dismissed`). The old scan-prompt notice is removed.
Terms/Privacy/Upgrade/Docs links live in `Support\Urls` (`cherrylink.app`, placeholders until the site is live).

## Plan limit in indexing
`License::postLimit()` (null = unlimited/legacy). The limit is applied by the server in `POST /v1/sync/plan` (see
`indexing.md` "Server-side sync plan"): `index` covers only the newest `post_limit` posts, `drop_vectors` removes
vectors outside that set (**link graph kept**), `links` is driven by the stored links hash, not by the limit.
Fallback for an API without the route (manifest + local diff, `IndexHelpers::compute_sync_plan`):
- `upsert` = only the newest `post_limit` eligible posts by `post_modified_gmt` (ties: higher ID), newest first;
- `drop` = indexed published posts outside that set: vectors deleted in the prune phase, **link graph kept**;
- `links` = the upserts (links follow upserts; the former "links for every over-limit post" re-sent the tail each scan);
- `delete` = orphans (vector and links), as before.
Server rejections are handled outcomes, not errors: single upsert `409 {detail: plan_limit}` returns success;
batch responses expose `plan_limited: [ids]` on `upsert_batch_result` and never count as `errors` (no retry loop).
The async bulk run (`AsyncBulkScan`) is not plan-limited (the API does not limit async routes either).

## Panel
`PanelConfig` adds `connectUrl`, `upgradeUrl` and `plan {name, post_limit, indexed, total}` (null for legacy keys;
`indexed` from the cached capabilities `plan.indexed`, else the cached readiness count; `total` = published posts of
indexable types, 10-minute transient). The Gutenberg and Classic hosts now load for unconnected sites too:
`licensed === false` shows one "Connect CherryLink" link to `connectUrl` and makes no AJAX call. When
`post_limit` is set and `total > post_limit` the strip shows the learned count with an Upgrade link, and
`postLearned` (false = this post is outside the newest `post_limit` by `post_modified_gmt`) shows a note that other
posts won't suggest links to it. Content types (post always, page by default, opt-in customs) decide what is
indexed and counted (`IndexHelpers::get_allowed_types_list()`).
The panel AJAX handlers (`PanelApi`) remain registered only for validated keys.

## Coexistence
The main file returns early (admin notice "Deactivate CherryLink Pro AI first") when `CHERRYLINK_DIR` is already
defined. `release.yml` triggers only on `v1.*` tags. `dev/build-org-zip.sh` builds `dist/cherrylink.zip`
(`cherrylink/` folder, no `updater/`).

## Free-site retention

Free sites with no contact for 180 days have their index purged server-side. `plan.index_expired` (capabilities
`plan` object, bool, absent = false) is cached in the snapshot; any scan rebuilds the index and clears the flag
(locally at `SyncService::start()`, server-side on the next `POST /v1/sync/plan`). See `admin-ui.md`.
