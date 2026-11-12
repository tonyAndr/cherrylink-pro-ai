# Connect, free plan and the no-calls-before-connect guarantee

Status: implemented on `feature/free-mode` (Step 3 of `FREE-PRO-PLAN-2026-11-12.md`); not released.
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
| `Support\PluginReports` | opt-in setting `lifecycle_reports` (default `false`); off = nothing queued, nothing sent, queue cleared |
| `Indexing\IndexHooks`, `SyncService`, `AsyncBulkScan` | `isLicensed()` gates (index is only marked dirty) |
| `Api\Relay` | only adjusts connect address of our own requests |
| Settings assets (`js/tailwind.js`, admin CSS/JS), editor bundle | all local files; no CDN/remote asset |
| `env-info/`, `updater/` | env-info only reads local data. `updater/` (GitHub PUC) exists only in the legacy zip; the `.org` zip (`dev/build-org-zip.sh`) removes it and the main file guards it with `file_exists` |
Tests: `FreeModeTest::testNothingReachesTheNetworkWithoutAKey`.

## Licensing URL
`License::licensingUrl()` is the single base for check and connect (default
`https://seocherry.ru/cherrylink-pro-licensing/`); define `CHERRYLINK_LICENSING_URL` in wp-config for local dev.

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
1. Settings page wizard step 1 posts to `admin-post.php?action=cherrylink_connect` (nonce, `cherrylink_settings`).
2. Plugin stores a random nonce in transient `cherrylink_connect_nonce` (10 min), POSTs JSON
   `{domain, nonce}` (domain = `home_url()` host) to `<licensing base>connect` (20 s), then deletes the transient.
3. `GET /wp-json/cherrylink/v1/connect-proof` (public) returns `{"nonce": ...}` only while the transient exists,
   else 404. The authority fetches it over HTTPS as domain proof.
4. 200: `license_key` is stored in the key option, plan fields and `key_valid` in meta (no second round trip), a
   capabilities refresh is scheduled on cron, and the user is redirected to wizard step 2.
5. Errors map to `cl_connect=<code>` notices: `domain_proof_failed` (409), `rate_limited` (429, `Retry-After`),
   `invalid_domain` (422), `subscription_not_eligible` (409), everything else `unavailable`.
"I already have a key" opens the normal tabs (`cl_key=1`) with the existing key field.

## Setup wizard
Rendered by `SettingsPage::renderPage()` (template `admin/wizard.php`) instead of the tabs: step 1 while there is no
key, steps 2 (post types + existing scan progress UI) and 3 (summary) for a connected site (`cl_step`). No
activation redirect. Plugins screen only: dismissible notice "connect CherryLink" until connected (user meta
`cherrylink_connect_notice_dismissed`). `Notices::scanPrompt` shows only on the CherryLink screen, only when
connected, with `admin_url()` links. Terms/Privacy/Upgrade URLs are placeholder constants on `Connect`.

## Plan limit in indexing
`License::postLimit()` (null = unlimited/legacy). In `SyncService::startFromManifest` a limit changes only the plan:
- `upsert` = only the newest `post_limit` eligible posts by `post_modified_gmt` (ties: higher ID), newest first;
- `drop` = indexed published posts outside that set: vectors deleted in the prune phase, **link graph kept**;
- `links` = upserts plus every post outside the limit (the link audit covers the whole site; costs a re-parse of
  the overflow each scan);
- `delete` = orphans (vector and links), as before.
With no limit the plan is exactly `{upsert, delete}` as before.
Server rejections are handled outcomes, not errors: single upsert `409 {detail: plan_limit}` returns success;
batch responses expose `plan_limited: [ids]` on `upsert_batch_result` and never count as `errors` (no retry loop).
The async bulk run (`AsyncBulkScan`) is not plan-limited (the API does not limit async routes either).

## Panel
`PanelConfig` adds `connectUrl`, `upgradeUrl` and `plan {name, post_limit, indexed, total}` (null for legacy keys;
`indexed` from the cached capabilities `plan.indexed`, else the cached readiness count; `total` = published posts of
indexable types, 10-minute transient). The Gutenberg and Classic hosts now load for unconnected sites too:
`licensed === false` shows one "Подключить CherryLink" link to `connectUrl` and makes no AJAX call. When
`post_limit` is set and `total > post_limit` the strip shows "Проиндексировано 1 000 из 2 340 · Расширить".
The panel AJAX handlers (`PanelApi`) remain registered only for validated keys.

## Coexistence
The main file returns early (admin notice "Deactivate CherryLink Pro AI first") when `CHERRYLINK_DIR` is already
defined. `release.yml` triggers only on `v1.*` tags. `dev/build-org-zip.sh` builds `dist/cherrylink.zip`
(`cherrylink/` folder, no `updater/`).
