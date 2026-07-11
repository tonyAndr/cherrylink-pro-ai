# CherryLink Pro AI — Plugin Context

**Plugin specs** (editor UI, Gutenberg sidebar): `specs/` in this repo
**API specs** (search, link graph, indexing, logging): `/Users/anton/projects/cherrylink/api/specs/`
**Full API + infra context**: `/Users/anton/projects/cherrylink/api/CLAUDE.md`

---

## What This Is

WordPress plugin for AI-powered internal linking. Connects to Cherry Vector API (`https://seocherry.apov.dev`) for semantic search and link graph tracking. Works in both Gutenberg and the classic editor.

---

## Key Files

| File | Purpose |
|---|---|
| `cherrylink_main.php` | Plugin header, requires all modules |
| `cherrylink_vector_api.php` | `CherryVectorAPI` class — all HTTP calls to API |
| `cherrylink_index.php` | Post save/delete hooks + **phased incremental** AJAX reindex (plan→upsert→links→prune→done) |
| `cherrylink_stats_scheme.php` | Full-site link graph scanning (`CL_Stats_Scheme`) |
| `cherrylink_editor_ui.php` | Classic editor panel HTML (tabs: articles / taxonomy / stats) |
| `cherrylink_gutenberg.php` | Gutenberg sidebar enqueue + script registration |
| `cherrylink_admin_options.php` | WP admin settings page + "Прочие настройки" actions |
| `cherrylink_install.php` | Activation, `fill_options()` defaults |
| `js/cherry-front.js` | Classic editor panel JS (suggestions, stats, link insertion) |
| `js/cherry-gutenberg.js` | **Built artifact** from `gutenberg-src/` — do not hand-edit |
| `css/cherry-main.css` | All plugin styles |

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

## PHP: CherryVectorAPI

All API calls go through `CherryVectorAPI` in `cherrylink_vector_api.php`.
- API URL: `CHERRY_VECTOR_API_URL` constant in wp-config → admin settings → `https://seocherry.apov.dev`
- License key: admin settings → `123123`
- Headers sent: `X-License-Key`, `X-Site-Domain`
- Errors: logged via `cherry_write_log()`

---

## Classic Editor UI (HTML)

`cherrylink_editor_ui.php` renders the panel. Tab structure:
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
cd /Users/anton/projects/cherrylink/plugin/wp-dev
docker compose up -d
# WordPress: http://localhost:8088 (admin/admin)
# Plugin mounted as live bind — PHP edits take effect immediately
# Connect to API: use https://seocherry.apov.dev or http://host.docker.internal:8000
```

---

## Non-obvious Things

- **`data-suggestions` escaping**: classic editor uses `htmlspecialchars(ENT_COMPAT)` (HTML attribute safe); Gutenberg JSON-in-JSON uses `str_replace('"', '\\"', ...)` — these are different code paths in `cherrylink_main.php`
- **Tab PNG icons**: `assets/gutenberg/tab-links.png`, `tab-taxonomies.png`, `tab-stats.png`
- **`linkate_scheme_exists` option**: repurposed flag — means "Cherry Vector API is configured and accessible", NOT that a MySQL table exists (that table was migrated to Postgres on the API)
- **`linkate_posts` MySQL table**: still used by `cherrylink_cf.php` for custom fields index — do not drop
- **Scan is incremental**: diffs current published posts against `GET /v1/documents/manifest` by `post_modified_gmt`, re-embeds only changed posts, builds their links via `POST /v1/links/parse-batch`, and prunes orphans via `POST /v1/documents/delete-batch`. The diff is a pure, unit-tested helper: `CL_Index_Helpers::compute_sync_plan()` (see `tests/test_sync_plan.php`).
- **Scan runs in the background via Action Scheduler** (`cherrylink_sync.php`, bundled `vendor/woocommerce/action-scheduler`). Start it and leave the page — a self-rescheduling `cherrylink_sync_worker` action processes one batch (upsert→links→prune) and enqueues the next. AJAX: `linkate_sync_start` / `linkate_sync_cancel` / `linkate_sync_status`; JS (`cherry-admin-index.js`) starts it, polls status, and has a Stop button. Progress/worklists live in options `cherrylink_sync_state` / `cherrylink_sync_plan`. Cancel flags the state AND cancels pending actions by ID (`as_unschedule_all_actions([])` only matches arg-less actions). Needs WP-Cron enabled (or a server cron / `wp action-scheduler run`) to advance without traffic.
- **Foreground `linkate_ajax_call_reindex`** (in `cherrylink_index.php`) is the older synchronous phase-loop; still functional as a fallback but the UI now uses the background sync.
- **`CL_Stats_Scheme::linkate_create_links_scheme` is deprecated dead code** — the scan's links phase supersedes it; don't revive it as a second link-scan path.
- **Anchor suggestions are lazy/on-demand**: `linkate_posts()` calls `/v1/search` with an empty `source_html` (no eager anchors, no top-5 cap). When the editor opens a hit's suggestion panel (loupe), it calls the `get_linkate_suggestions` AJAX action → `CherryVectorAPI::suggest_anchors()` → `POST /v1/suggest-anchors` for that one target. Wired in both editors: Gutenberg `SugestionsPanel.componentDidMount` fetches; classic `fcl_render_api_suggestions` fetches (both show a loading state, cache per target). `linkate_build_source_html()` wraps plain-text content in `<p>` for the extractor.
- **No CI for this plugin**: push to git = source of truth; client updates via plugin updater
- **`cherry_write_log()`**: writes to PHP error log; in wp-dev: `docker compose logs wordpress`
