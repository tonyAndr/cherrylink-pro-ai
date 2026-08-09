# CherryLink Pro AI — Plugin Context

**Plugin specs** (editor UI, Gutenberg sidebar): `specs/` in this repo
**API**: the plugin talks to the companion Cherry Vector API service (semantic search, link graph, indexing).

---

## What This Is

WordPress plugin for AI-powered internal linking. Connects to Cherry Vector API (`https://seocherry.apov.dev`) for semantic search and link graph tracking. Works in both Gutenberg and the classic editor.

---

## Architecture (post-refactor)

The plugin was modernized from ~19 flat global-function root files into a
namespaced, autoloaded, DI-wired layout under `src/` (PSR-4 `CherryLink\`),
without changing runtime behavior. See the staged plan in
`.claude/plans/humming-hopping-parasol.md`.

- **Entry:** `cherrylink_main.php` — plugin header, requires `vendor/autoload.php`,
  then calls `\CherryLink\Plugin::boot(__FILE__)`. Still hosts the legacy
  `LinkatePosts` class + a few activation/update globals.
- **Bootstrap:** `src/Plugin.php` — constructs shared singletons (`Options`) and
  registers services implementing `Contracts\Registerable` (each hooks itself in
  `register()`): `Admin\StatsColumn`, `Editor\GutenbergAssets`,
  `Indexing\IndexHooks`, `Indexing\SyncService`, `Terms\TermsService`,
  `Statistics\StatisticsService`, `Csv\CsvService`, `Editor\ClassicPanel`.
- **Views:** HTML lives in `templates/` rendered via `Support\View` (escaped).
- **Legacy shims still required by `cherrylink_main.php`** (thin delegators /
  class aliases with real callers — do not delete without grep-proving no
  callers): `cherrylink_index.php` (`cl_index_status_data()`),
  `cherrylink_terms.php`, `cherrylink_editor_ui.php`,
  `cherrylink_vector_api.php` (`class_alias CherryVectorAPI` + the
  `http_api_curl` alt-server relay hook), `cherrylink_index_helpers.php`
  (`class_alias CL_Index_Helpers`), `cherrylink_tailwind_constants.php`.
- **Not yet migrated (still fat root files):** `cherrylink_admin_options.php`
  (settings page), `cherrylink_admin_subpages.php`, `cherrylink_acf.php` +
  `cherrylink_cf.php` (custom-fields index), `cherrylink_debug.php`,
  `cherrylink_output_tags.php`, `cherrylink_install.php` (`fill_options()`).

## Key Files / where things live now

| Concern | Location |
|---|---|
| Bootstrap / service registry | `src/Plugin.php`, `src/Contracts/Registerable.php` |
| Settings repository (defaults, keys) | `src/Support/Options.php` |
| API HTTP client (ex-`CherryVectorAPI`) | `src/Api/Client.php` (+ `Support/Logger.php`) |
| Post save/delete hooks + readiness payload | `src/Indexing/IndexHooks.php` |
| Background scan (Action Scheduler worker) | `src/Indexing/SyncService.php` |
| Incremental-sync diff (pure, tested) | `src/Indexing/IndexHelpers.php` (ex-`CL_Index_Helpers`) |
| Classic editor panel + suggestion AJAX | `src/Editor/ClassicPanel.php` |
| Suggestions pipeline (query→filter→render) | `src/Editor/SuggestionsResults.php`, `Editor/LinkFields.php` |
| Gutenberg sidebar enqueue | `src/Editor/GutenbergAssets.php` |
| Stats / CSV / Terms services | `src/Statistics/`, `src/Csv/`, `src/Terms/` |
| AJAX nonce+capability guard | `src/Ajax/Security.php` |
| Templates (escaped HTML) | `templates/**` |
| Classic editor JS | `js/cherry-front.js` |
| Gutenberg JS | `js/cherry-gutenberg.js` (**built** from `gutenberg-src/` — do not hand-edit) |
| Styles | `css/cherry-main.css` |
| Translations | `languages/` (`.pot` master, `cherrylink-en_US.{po,mo}`, JS `.json`) |

## Dev tooling & tests

Docker-only QA (no local PHP needed) via `dev/qa.sh {install|test|lint|fix|analyze|all}`:
PHPUnit + Brain Monkey (unit, no WP runtime), PHPCS (PSR-12 + WP security/i18n
sniffs, PHP 7.4 floor; errors block, warnings advisory), PHPStan level 6.
CI mirror in `.github/workflows/ci.yml` (PHP 7.4 + 8.2). Gutenberg unit tests:
`cd gutenberg-src && npm test -- --watchAll=false`.

## i18n

Text domain `cherrylink`, `Domain Path: /languages`. PHP strings wrapped in
`__()/esc_html__()/…`; hand-written JS uses `wp.i18n` with
`wp_set_script_translations()`; Gutenberg src uses `@wordpress/i18n`. Regenerate
the catalog after adding strings: `wp i18n make-pot . languages/cherrylink.pot
--domain=cherrylink --exclude=vendor,node_modules,gutenberg-src,tests,dev,build,.github`
(scans PHP + the built `js/` bundle), then `wp i18n make-json <po> --no-purge`.
Never cache locale-formatted text (e.g. status snapshots store raw counts and
format per-request — see `IndexHooks::statusTexts()`).

---

## JS Architecture

### Classic editor (`cherry-front.js`)
All code lives inside `jQuery(document).ready(function($) { ... })`.

**Scope trap:** helper functions defined inside `reLoad_when_data_received()` are NOT accessible from the outer ready scope. `fcl_findPhraseInEditor` and similar must be declared at the outer ready scope level (before `reLoad_when_data_received`).

Key functions:
- `fcl_findPhraseInEditor(phrase)` — walks TinyMCE text nodes or textarea, selects and scrolls to phrase
- `fcl_render_api_suggestions(triggerEl, item)` — builds suggestions panel HTML from API data
- `fcl_enableSuggestionSwitches()` — wires up suggestion item click handlers
- `fcl_load_incoming_tab()` — loads stats tab (outgoing from editor + incoming from API), one-shot cache via `cl_incoming_loaded`
- `fcl_isTinyMCE()` — returns true if active editor is TinyMCE (vs textarea)
- `fcl_getEditorContent()` — gets raw HTML from TinyMCE or textarea

Suggestion item UX:
- Click on `.cl-api-suggestion-item` → `fcl_findPhraseInEditor(phrase)` (scroll to phrase)
- Click on `.suggestion-insert` button → find phrase + insert link
- `.cl-api-suggestion-find` button has been removed

Stats tab:
- Outgoing: parsed from editor HTML via regex on `<a href>` tags, items have class `cl-stats-outgoing`
- Incoming: fetched from API via `fcl_fetch_incoming()`
- Both sections wrapped in `.cl-stats-section` with `.cl-stats-section-title`
- Clicking an outgoing anchor (`.cl-stats-outgoing .cl-incoming-anchor`) calls `fcl_findPhraseInEditor`

Footer:
- `.linkate-total-links` hidden entirely when stats tab is active, shown on articles/taxonomy tabs
- `.cl-footer-incoming` spans are shown/hidden independently

### Gutenberg sidebar (`cherry-gutenberg.js`)
Built from `gutenberg-src/` (lives inside this repo). Toolchain: `@wordpress/scripts` v32 (webpack 5).

Build + deploy:
```bash
cd gutenberg-src
npm run build        # CopyToJsPlugin auto-copies JS + CSS + asset.php to ../js/
npm run start        # dev watcher — same copy on each rebuild
npm test -- --watchAll=false  # 21 unit tests
```
Clean build before committing: `rm -rf build && npm run build`

`webpack.config.js` note: patches `css-loader` with `url: false` so absolute WP runtime paths
(`/wp-content/plugins/.../assets/gutenberg/*.png`) are not resolved at build time.
The check uses `/css-loader/` with slashes — `postcss-loader` contains `css-loader` as a substring
and would otherwise also get patched.

Scroll-to-block uses block data store, not DOM:
```js
import { findBlocksWithUrl, scrollToBlockInEditor } from '../HOC/scrollToEditor';
```

Source layout notes (`gutenberg-src/src/sidebar/`):
- `HOC/blockUtils.js` — shared `BlockAttributes` map + `flattenBlocks()`. Both `scrollToEditor.js` and `withLinkInsert.js` import from here; do not duplicate.
- `Data/` — render-less components (`GetData`, `GetAllTerms`, `GetIncomingLinks`, `GetArticleStats`). All fetch calls use `async/await`.
- Text filtering is server-side (the `custom_text` payload in `GetData.loadData()`). There is no client-side filter in the Gutenberg sidebar.

---

## PHP: API client

All API calls go through `\CherryLink\Api\Client` (`src/Api/Client.php`); the old
`CherryVectorAPI` name is a `class_alias` kept in `cherrylink_vector_api.php` for
back-compat callers.
- API URL: `CHERRY_VECTOR_API_URL` constant in wp-config → admin settings → `https://seocherry.apov.dev`
- Auth key priority: `CHERRY_VECTOR_API_KEY` constant → `vector_api_key` setting → the license key (`hash_field`). A valid license key is normally the only key needed. **Never ship the server ADMIN_KEY in plugin code.**
- Headers sent: `X-License-Key`, `X-Site-Domain`
- Errors: logged via `\CherryLink\Support\Logger` (`cherry_write_log()` remains as a shim)
- Alt-server relay: the `http_api_curl` hook in `cherrylink_vector_api.php` reroutes requests to `CL_ALT_API_*` when the "Для экспертов" toggle is on (blocked-provider workaround).

---

## Classic Editor UI (HTML)

`src/Editor/ClassicPanel.php` renders the panel from `templates/editor/classic-panel.php`. Tab structure:
```html
<div class="linkate-tabs">
  <div class="tab tab-articles linkate-tab-selected">  <!-- PNG icon from assets/gutenberg/tab-links.png -->
  <div class="tab tab-taxonomy">                       <!-- tab-taxonomies.png -->
  <div class="tab tab-stats">                          <!-- tab-stats.png -->
</div>
<div class="container-articles">...</div>
<div class="container-taxonomy">...</div>
<div class="container-stats" style="display:none">
  <div id="cl-incoming-list">...</div>
</div>
<div class="linkate-total-links">  <!-- hidden on stats tab -->
  <div class="total-links-counter">
    <div>Исходящих</div><div id="links-count-total">?</div>
    <div class="cl-footer-incoming">Входящих</div>
    <div id="links-count-targets" class="cl-footer-incoming">?</div>
  </div>
</div>
```

Tab icons use PNG files from `assets/gutenberg/` via `plugins_url()`.

---

## Data Attributes on Link Items

Each `.linkate-link` element carries:
- `data-url` — post permalink
- `data-title` — H1 title
- `data-titleseo` → maps to `title_seo` in `fcl_getDataAttrs()`
- `data-suggestions` — JSON string (HTML-escaped with `htmlspecialchars(ENT_COMPAT)` for classic editor, backslash-escaped for Gutenberg JSON-in-JSON)
- `data-category`, `data-date`, `data-author`

---

## Local Dev

```bash
cd wp-dev
docker compose up -d
# WordPress: http://localhost:8088 (admin/admin)
# Plugin mounted as live bind — PHP edits take effect immediately
# Connect to API: use https://seocherry.apov.dev or a local API at http://host.docker.internal:8000
```

---

## Non-obvious Things

- **`data-suggestions` escaping**: classic editor uses `htmlspecialchars(ENT_COMPAT)` (HTML attribute safe); Gutenberg JSON-in-JSON uses `str_replace('"', '\\"', ...)` — these are different code paths in `cherrylink_main.php`
- **Tab PNG icons**: `assets/gutenberg/tab-links.png`, `tab-taxonomies.png`, `tab-stats.png`
- **`linkate_scheme_exists` option**: repurposed flag — means "Cherry Vector API is configured and accessible", NOT that a MySQL table exists (that table was migrated to Postgres on the API)
- **`linkate_posts` MySQL table**: still used by `cherrylink_cf.php` for custom fields index — do not drop
- **Scan is incremental**: diffs current published posts against `GET /v1/documents/manifest` by `post_modified_gmt`, re-embeds only changed posts, builds their links via `POST /v1/links/parse-batch`, and prunes orphans via `POST /v1/documents/delete-batch`. The diff is a pure, unit-tested helper: `CL_Index_Helpers::compute_sync_plan()` (see `tests/test_sync_plan.php`).
- **Scan runs in the background via Action Scheduler** (`src/Indexing/SyncService.php`, bundled `vendor/woocommerce/action-scheduler`). Start it and leave the page — a self-rescheduling `cherrylink_sync_worker` action processes one batch (upsert→links→prune) and enqueues the next. AJAX: `linkate_sync_start` / `linkate_sync_cancel` / `linkate_sync_status`; JS (`cherry-admin-index.js`) starts it, polls status, and has a Stop button. Progress/worklists live in options `cherrylink_sync_state` / `cherrylink_sync_plan`. Cancel flags the state AND cancels pending actions by ID (`as_unschedule_all_actions([])` only matches arg-less actions). Needs WP-Cron enabled (or a server cron / `wp action-scheduler run`) to advance without traffic.
- **Anchor suggestions are lazy/on-demand**: `linkate_posts()` calls `/v1/search` with an empty `source_html` (no eager anchors, no top-5 cap). When the editor opens a hit's suggestion panel (loupe), it calls the `get_linkate_suggestions` AJAX action → `CherryVectorAPI::suggest_anchors()` → `POST /v1/suggest-anchors` for that one target. Wired in both editors: Gutenberg `SugestionsPanel.componentDidMount` fetches; classic `fcl_render_api_suggestions` fetches (both show a loading state, cache per target). `linkate_build_source_html()` wraps plain-text content in `<p>` for the extractor.
- **Updates**: no CI; clients auto-update via the bundled Plugin Update Checker (v4.9), wired in `cherrylink_main.php` to this GitHub repo. Ship a new version by publishing a GitHub Release whose tag matches the `Version:` header, with the packaged plugin zip attached as a release asset.
- **`cherry_write_log()`**: writes to PHP error log; in wp-dev: `docker compose logs wordpress`
