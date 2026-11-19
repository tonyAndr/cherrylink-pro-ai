# CherryLink Pro AI — Plugin Context

**Plugin specs** (editor UI, Gutenberg sidebar): `specs/` in this repo
**API**: the plugin talks to the companion Cherry Vector API service (semantic search, link graph, indexing).

---

## What This Is

WordPress plugin for AI-powered internal linking. Connects to Cherry Vector API (`https://api.cherrylink.app`) for semantic search and link graph tracking. Works in both Gutenberg and the classic editor.

---

## Architecture (post-refactor)

The plugin was modernized from ~19 flat global-function root files into a
namespaced, autoloaded, DI-wired layout under `src/` (PSR-4 `CherryLink\`),
without changing runtime behavior. See the staged plan in
`.claude/plans/humming-hopping-parasol.md`.

- **Entry:** `cherrylink_main.php` — plugin header, requires `vendor/autoload.php`,
  then calls `\CherryLink\Plugin::boot(__FILE__)`. It also loads Action Scheduler
  + the GitHub update checker. No plugin logic. (Rendered output lives in
  `Editor\SuggestionsResults`.)
- **Bootstrap:** `src/Plugin.php` — defines the API runtime constants
  (`Api\Relay::defineConstants()`), constructs the shared `Options` + `License`,
  and registers services implementing `Contracts\Registerable` (each hooks itself
  in `register()`): `Api\Relay`, `Admin\StatsColumn`, `Admin\AdminApi`, `Admin\AdminMenu`,
  `Admin\Notices`, `Editor\GutenbergAssets`, `Indexing\IndexHooks`,
  `Indexing\SyncService`, `Terms\TermsService`, `Statistics\StatisticsService`,
  `Editor\ClassicPanel`, `Support\License`,
  `Support\Lifecycle`.
- **Views:** HTML lives in `templates/` rendered via `Support\View` (escaped).
  Templates reference namespaced classes by FQN (e.g. `\CherryLink\Support\Tailwind::$CARD`).
- **No shims left.** The migration is complete — the plugin root contains exactly
  two PHP files: `cherrylink_main.php` and `uninstall.php`. Every other concern
  lives in a namespaced, autoloaded, DI-wired service under `src/`. All former
  global-function / class-alias shim files were deleted once grep proved no
  callers (their behavior was ported to the services above and callers re-pointed;
  behavior was ported to the services above).


## Key Files / where things live now

| Concern | Location |
|---|---|
| Bootstrap / service registry | `src/Plugin.php`, `src/Contracts/Registerable.php` |
| Settings repository (defaults, keys) | `src/Support/Options.php` |
| API HTTP client (ex-`CherryVectorAPI`) | `src/Api/Client.php` (+ `Support/Logger.php`) |
| API constants + alt-server relay hook | `src/Api/Relay.php` |
| Plugin metadata (version, assetVer, screen check) | `src/Support/PluginMeta.php` |
| Activation / update / textdomain lifecycle | `src/Support/Lifecycle.php` |
| Admin menu/pages + bundle enqueue + scan notices | `src/Admin/AdminMenu.php`, `src/Admin/Notices.php` (contract: `specs/admin-ui.md`) |
| Post save/delete hooks + readiness payload | `src/Indexing/IndexHooks.php` |
| Background scan (Action Scheduler worker) | `src/Indexing/SyncService.php` |
| Incremental-sync diff (pure, tested) | `src/Indexing/IndexHelpers.php` |
| Classic editor integration (media button + bundle enqueue) | `src/Editor/ClassicPanel.php` |
| Editor panel API (AJAX `cherrylink_panel_*`) + config | `src/Editor/PanelApi.php`, `PanelConfig.php`, `ContentLinker.php`, `DonorFinder.php`, `LinkMarkup.php` |
| Suggestions pipeline (query→filter→structured rows) | `src/Editor/SuggestionsResults.php::rows()`, `Editor/LinkFields.php` |
| Gutenberg sidebar enqueue | `src/Editor/GutenbergAssets.php` |
| Stats / CSV / Terms services | `src/Statistics/`, `src/Csv/`, `src/Terms/` |
| Admin app AJAX (state, settings, connection, link health) | `src/Admin/AdminApi.php` (React app `gutenberg-src/src/admin/`) |
| License validation / cron / capability | `src/Support/License.php` |
| User-facing links | `src/Support/Urls.php` |
| AJAX nonce+capability guard | `src/Ajax/Security.php` |
| Templates (escaped HTML) | `templates/**` |
| Classic editor / term panel JS | `js/cherry-classic.js` (**built** from `gutenberg-src/src/classic.js`) |
| Gutenberg JS | `js/cherry-gutenberg.js` (**built** from `gutenberg-src/` — do not hand-edit) |
| Panel spec | `specs/unified-panel.md` |
| Styles | panel CSS is bundled (`js/cherry-*.css`); `css/cherry-main.css` = media-button icon only |
| Translations | `languages/` (`.pot` master, `cherrylink-ai-ru_RU.{po,mo}`, JS `.json`) |

## Request isolation checkpoint

Read `specs/admin-responsiveness.md` before changing HTTP calls or admin flows.
Default `Api\Client` instances are interactive (5-second per-call / 8-second
shared budget); readiness has 2/4-second limits. Background scans must explicitly
use `Client::CONTEXT_BACKGROUND`. Scan start queues manifest work and the UI tick
only nudges the asynchronous scheduler, never runs jobs inline. License save
schedules a bounded background check.

## Async outbox checkpoint

`Indexing\Outbox\*` is the durable live-event path: `Schema` (prefix-aware
`dbDelta` tables + versioned lifecycle), `Repository` (immutable enqueue, atomic
`(source_epoch, source_revision)` sequence, lease-fenced claim/ack/release/
dead-letter), `Capture` (revision allocated before the post read), and `Delivery`
(one Action Scheduler turn, stable `event_key` idempotency, bounded retry).
`Indexing\IndexHooks` uses it only when `Support\Capabilities` reports an
async-enabled tenant; otherwise it keeps the synchronous adapter.

`Indexing\AsyncBulkScan` is the durable bulk workflow for async tenants
(manifest -> create -> upload -> deletes -> seal -> poll) with persisted
`run_id`/cursor in `cherrylink_async_run`. `SyncService` delegates
start/cancel/status/tick to it when the tenant is async-capable and otherwise
keeps the synchronous chain untouched. Remaining work: initial enrollment /
existing-index reconciliation, the progress UI split, and one live
WordPress -> API -> worker check.

## Usage reports

The plugin sends no usage, lifecycle or environment reports (removed for WordPress.org). Only what
the service needs to function leaves the site: Connect, key checks, capabilities, indexing and
search, error events from failed scan steps. Keep `readme.txt` "External services" in step with
any new outbound call.

## Naming (WordPress.org prefix rule)

Everything the plugin registers globally uses a unique `cherrylink` prefix: options
`cherrylink_options` (settings, `Options::MAIN`) and `cherrylink_state` (key/connection state,
`Options::META`), transients/locks `cherrylink_*`, cron hooks `cherrylink_*`, AJAX actions
`cherrylink_*`, nonce `cherrylink_nonce`, post meta `cherrylink_outgoing` / `cherrylink_incoming`,
constants `CHERRYLINK_*` (wp-config overrides: `CHERRYLINK_API_URL`, `CHERRYLINK_API_KEY`,
`CHERRYLINK_LICENSING_URL`), script/style handles and page slugs `cherrylink-*`. The plugin is a new
2.x line: no migration from the 1.x option names. Do not reintroduce `linkate`, `cl_`, `clpro`, `cherry_`.

## Input handling

Every request field is read through `Ajax\Input` (typed `int/uint/bool/key/text/textarea/url/html`,
sanitized at read time; JSON bodies go through a field => type whitelist) after
`Ajax\Security::verify($cap)` (nonce + capability). Admin-app handlers use `cherrylink_settings`;
editor-panel handlers use `edit_posts` plus a per-object `edit_post` check. The only public
unauthenticated handlers are the read-only Connect domain proof (REST + `nopriv` AJAX).

## Features without a connection

`Editor\PanelApi` registers every panel action for any admin request. Service actions
(links, incoming, donors, key_pin) answer `not_connected` when the site is not connected; local
ones (terms, donor_insert, donor_undo) always work. The React panel shows a Connect prompt for
service tabs and keeps the categories list; the admin Settings page is available unconnected.

## Dev tooling & tests

Live plugin ↔ API flow (no TEI/worker): `./dev/async-e2e/run.sh` boots
PostgreSQL + Qdrant + the API (async enabled) + MySQL + WordPress and drives
capability negotiation, a snapshot run through seal, and mutation replay.

Docker-only QA (no local PHP needed) via `dev/qa.sh {install|test|lint|fix|analyze|all}`:
PHPUnit + Brain Monkey (unit, no WP runtime), PHPCS (PSR-12 + WP security/i18n
sniffs, PHP 7.4 floor; errors block, warnings advisory), PHPStan level 6.
CI mirror in `.github/workflows/ci.yml` (PHP 7.4 + 8.2). Gutenberg unit tests:
`cd gutenberg-src && npm test -- --watchAll=false`.

Release pre-flight: follow `RELEASE-CHECKLIST.md` (quality gates, WordPress
smokes, manual wp-dev pass, packaging and metadata checks).

Real-MySQL smoke (not part of `qa.sh`, needs Docker only):
`./dev/outbox-smoke/run.sh` boots throwaway MySQL 8 + WordPress, activates the
plugin with a custom table prefix, and asserts the outbox schema plus
repository transactions. Use it after changing `src/Indexing/Outbox/` because
the unit suite mocks `$wpdb`/`dbDelta`.

`./dev/wp-smoke/run.sh` exercises the editor AJAX path against real stored
options (the strict_types/option-type and search-limit regressions are
invisible to the mocked suite).

## i18n

Text domain `cherrylink-ai`, `Domain Path: /languages`. **English is the source
language**: every msgid is English; never put non-English text in `__()` or JS
`__()`. Russian lives in the `languages/cherrylink-ai-ru_RU.po` catalogue
(3 plural forms; `.mo` and `cherrylink-ai-ru_RU-<md5>.json` are compiled from it and
committed). PHP strings use `__()/esc_html__()/…`; hand-written JS uses `wp.i18n`
with `wp_set_script_translations()`; Gutenberg src uses `@wordpress/i18n`.
After adding strings, rebuild the bundles (`cd gutenberg-src && npm run build`),
then from `plugin/` (wp-cli in Docker works without a WP install):

```bash
wp i18n make-pot . languages/cherrylink-ai.pot --domain=cherrylink-ai \
  --exclude=vendor,node_modules,gutenberg-src,tests,dev,build,.github,wp-dev,env-info
wp i18n update-po languages/cherrylink-ai.pot languages/cherrylink-ai-ru_RU.po   # then translate new entries
wp i18n make-mo languages/cherrylink-ai-ru_RU.po languages/
wp i18n make-json languages/cherrylink-ai-ru_RU.po --no-purge
```

(make-pot scans PHP plus the built `js/` bundles.) Don't name a bundle `*min.js` (e.g. `cherry-admin.js`):
wp-cli's unescaped `.min` regex mangles the source path and the JSON gets the wrong hash.
Keep the ru_RU po free of untranslated/fuzzy entries.
Never cache locale-formatted text (e.g. status snapshots store raw counts and
format per-request — see `IndexHooks::statusTexts()`).

---

## JS Architecture

### Unified panel (Gutenberg + Classic + term screen)
One React app (`gutenberg-src/src/panel/`) serves every host; editor operations sit behind
`EditorAdapter` (`gutenberg-src/src/adapters/`, contract in `EditorAdapter.md`). Entries:
`src/index.js` (Gutenberg `PluginSidebar`) -> `js/cherry-gutenberg.js`, `src/classic.js` (floating
host, toggled by the `#cherrylink-panel-toggle` media button) -> `js/cherry-classic.js`. Config is
printed as `window.cherrylinkPanel` by `Editor\PanelConfig`; data comes from `Editor\PanelApi`
(`cherrylink_panel_*` AJAX). The old jQuery panel (`cherry-front.js`) is gone. If
`js/cherry-classic.js` is missing the Classic panel renders nothing. Read `specs/unified-panel.md`.

### Build (`gutenberg-src/`)
Builds both bundles (`cherry-gutenberg`, `cherry-classic`). Toolchain: `@wordpress/scripts` v32 (webpack 5).

Build + deploy:
```bash
cd gutenberg-src
npm run build        # postbuild copies the JS + asset.php (CSS is in the bundle files) to ../js/
npm run start        # dev watcher — same copy on each rebuild
npm test -- --watchAll=false
```
Clean build before committing: `rm -rf build && npm run build`

Gutenberg-specific helpers (block flattening, RichText link application, scroll-to-block)
live in `gutenberg-src/src/adapters/gutenberg/`; scroll-to-block uses the block data store, not the DOM.
The bundle reads `wp-rich-text`/`wp-editor`/`wp-edit-post`/`wp-block-editor` as globals, so they are
explicit script deps in `Editor\GutenbergAssets`.

---

## PHP: API client

All API calls go through `\CherryLink\Api\Client` (`src/Api/Client.php`, ex-`CherryVectorAPI`).
The API runtime constants + the alt-server hook live in `\CherryLink\Api\Relay`.
- API URL: `CHERRYLINK_API_URL` constant in wp-config → admin settings → `https://api.cherrylink.app` (defined by `Api\Relay::defineConstants()`)
- Auth key priority: saved API key (`hash_field`) → `CHERRYLINK_API_KEY` constant → `vector_api_key` setting. The explicit key is a fallback for unconnected/local installs; a leftover admin key must not shadow the saved key. **Never ship the server ADMIN_KEY in plugin code.**
- Headers sent: `X-License-Key`, `X-Site-Domain`
- Errors: logged via `\CherryLink\Support\Logger`
- Alt-server relay: `Api\Relay::resolveAltRoute()` (the `http_api_curl` hook) reroutes requests to `CHERRYLINK_ALT_API_*` when the "For experts" toggle is on (blocked-provider workaround).

---

## Classic Editor UI

`src/Editor/ClassicPanel.php` adds the "CherryLink" media button (`#cherrylink-panel-toggle`)
and enqueues `js/cherry-classic.{js,css}` on classic post/page and `term.php` screens. The panel
DOM is rendered by React (`gutenberg-src/src/panel/`); there are no PHP panel templates. See
`specs/unified-panel.md`.

---

## Local Dev

```bash
cd wp-dev
docker compose up -d
# WordPress: http://localhost:8088 (admin/admin)
# Plugin mounted as live bind — PHP edits take effect immediately
# Connect to API: use https://api.cherrylink.app or a local API at http://host.docker.internal:8000
```

---

## Non-obvious Things

- **Scan is incremental**: diffs current published posts against `GET /v1/documents/manifest` by `post_modified_gmt`, re-embeds only changed posts, builds their links via `POST /v1/links/parse-batch`, and prunes orphans via `POST /v1/documents/delete-batch`. The diff is a pure, unit-tested helper: `Indexing\IndexHelpers::compute_sync_plan()` (see `tests/Unit/SyncPlanTest.php`).
- **Scan runs in the background via Action Scheduler** (`src/Indexing/SyncService.php`, bundled `vendor/woocommerce/action-scheduler`). Start it and leave the page — a self-rescheduling `cherrylink_sync_worker` action processes one batch (upsert→links→prune) and enqueues the next. AJAX: `cherrylink_sync_start` / `cherrylink_sync_cancel` / `cherrylink_sync_status`; JS (`cherry-admin-index.js`) starts it, polls status, and has a Stop button. Progress/worklists live in options `cherrylink_sync_state` / `cherrylink_sync_plan`. Cancel flags the state AND cancels pending actions by ID (`as_unschedule_all_actions([])` only matches arg-less actions). Needs WP-Cron enabled (or a server cron / `wp action-scheduler run`) to advance without traffic.
- **Async queue status**: the durable backend queue routes exist but async writes
  are disabled in production, so tenants stay in `sync` mode and the plugin uses
  the synchronous adapter. The plugin-side async surface is implemented:
  `Api\AsyncClient` (exact shipped routes), `Support\Capabilities` (cached
  negotiation), `Indexing\Outbox\{Schema,Repository,Capture,Delivery}` and the
  gated async branch in `Indexing\IndexHooks`. Read `specs/indexing.md` and the
  workspace `ASYNC-INDEXING-PLAN.md` before changing outbox ordering, delivery
  retry ownership, or capability gating. The current synchronous scan already has
  structured retry metadata, fail-closed/retryable manifest planning, cursor
  fencing, and 1,000-ID prune chunks; do not duplicate retry ownership in the
  client or Action Scheduler.
- **Anchor suggestions**: `Editor\SuggestionsResults::rows()` calls `/v1/search` with the live `source_html` and `suggestion_hits` (`min(10, limit)`), so suggestions come with each row; donors use one `Api\Client::suggest_anchors()` (`POST /v1/suggest-anchors`) per donor in `Editor\DonorFinder`. `Editor\ClassicPanel::buildSourceHtml()` wraps plain-text content in `<p>` for the extractor.
- **Updates**: no CI; clients auto-update via the bundled Plugin Update Checker (v4.9), wired in `cherrylink_main.php` to this GitHub repo. Ship a new version by publishing a GitHub Release whose tag matches the `Version:` header, with the packaged plugin zip attached as a release asset.
- **Logging** (`\CherryLink\Support\Logger`): writes to PHP error log; in wp-dev: `docker compose logs wordpress`


Local review corrections (2026-11-10): async captures no longer depend
on API availability; WorkerLock uses ownership-conditional SQL deletion; both
workers validate state inside the mutex. Bulk cancel/restart is a persisted worker
phase with retained run identity and retries, and resume retains its retry attempt.
See specs/indexing.md. No API/control runtime or deployment changed.
