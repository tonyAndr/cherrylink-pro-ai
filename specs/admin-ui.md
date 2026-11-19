# Admin UI (2.x) — contract

Status: being implemented on `feature/free-mode` (plan step 3b, `FREE-PRO-PLAN-2026-11-12.md`).
Design reference (static HTML boards, read for layout/copy):
`/tmp/claude-1000/-home-anton-projects-cherrylink/a4149d3a-8c94-4d50-a393-b2539b92df24/scratchpad/design/project/`
(`Main` = wizard Connect, `WizardAnalyze`, `WizardDone`, `Dashboard`, `Links`, `Index`, `Settings`).

## Shape

- One React app (`gutenberg-src/src/admin/`, entry `src/admin/index.js`, bundle `js/cherry-app.{js,css,asset.php}`)
  built with the same webpack config as the editor bundles; `@wordpress/components`, `@wordpress/element`,
  `@wordpress/i18n` (externals). No Tailwind, no jQuery.
- PHP prints one mount `<div id="cherrylink-admin" data-page="…"></div>` per page and
  `window.cherrylinkAdmin` (bootstrap, below). All data goes through admin-ajax (works when REST is blocked).
- Top-level menu **CherryLink** (`admin.php?page=cherrylink`), capability `cherrylink_settings`, cherry SVG
  icon (data URI). Submenus: Dashboard (`cherrylink`), Link health (`cherrylink-links`),
  Index (`cherrylink-index`), Settings (`cherrylink-settings`).
- Until connected the Dashboard page renders the setup wizard; the other pages show a single
  "Connect CherryLink first" card linking to it. After Connect, the wizard continues (Analyze → Done)
  until `cherrylink_setup_done` option is set (Skip / Continue / Go to dashboard).
- Plugin-row "Settings" link, notices,
  `Connect::settingsUrl()` and panel config URLs point to the new pages.
- English source strings. Every plan term carries a translator comment:
  "learned" = post analyzed for suggestions, counts toward the plan limit; "checked"/"mapped" = post whose
  links were read for link health (whole site, no limit).

## Bootstrap `window.cherrylinkAdmin`

```
{ page: 'dashboard'|'links'|'index'|'settings',
  ajaxUrl, nonce,                       // Security::nonce()
  connect: { postUrl, action, nonce, error },   // admin-post Connect::ACTION form; error = translated cherrylink_result message or ''
  urls: { dashboard, links, index, settings, exportSettings, editPost /* admin_url('post.php?action=edit&post=') */,
          upgrade, pricing, terms, privacy, docs, limits },   // user-facing ones from Support\Urls
  state: <cherrylink_admin_state payload> }
```

## AJAX (all `Security::verify('cherrylink_settings')`, `wp_send_json_success` / `_error({message})`)

| action | in | out |
|---|---|---|
| `cherrylink_admin_state` | – | state (below) |
| `cherrylink_admin_link_health` | `refresh` 0/1 | link-health payload; cached in option `cherrylink_link_health` (non-autoload); refresh calls the API with the background client (problems can exceed the 5 s interactive budget; explicit user action) |
| `cherrylink_admin_save_settings` | `settings` JSON object | `{state}`; whitelisted keys only, unknown keys ignored, each sanitized |
| `cherrylink_admin_license` | `op`=`set_key`(+`key`)/`recheck`/`disconnect` | `{state}`; set_key/recheck schedule the background validation (existing License flow); disconnect = old `remove_license` |
| `cherrylink_admin_remove_data` | – | `{state}`; old `truncate_all` (delete docs + links in API), clears link-health cache |
| `cherrylink_admin_import_settings` | multipart `file` (.txt from export) | `{state}`; through the same whitelist + link-markup migration |
| `cherrylink_admin_reset_settings` | – | `{state}` (`Options::fill(null)`, keeps license) |
| `cherrylink_admin_setup_done` | – | `{}` sets `cherrylink_setup_done` |

Reused unchanged: `cherrylink_sync_start|status|cancel|tick` (scan), `cherrylink_orphan_donors` (Find donors),
`cherrylink_server_status` (Check connection), `admin-post.php?action=cherrylink_export_options` (export).

### state

```
{ connected, has_key, setup_done,
  license: { plan: 'free'|'pro'|'legacy'|null, post_limit: int|null, key_masked: 'clp_••••4f2a'|'',
             domain, expires_at: string|null, status: 'active'|'pending'|'error'|'none', error: string },
  counts:  { total, types: [{name,label,count,enabled}], learned: int|null, checked: int|null, links: int|null },
  sync:    SyncService::getState() + { running: bool },
  last_scan: { gmt, upserted, deleted } | null,   // last completed scan (cherrylink_state)
  route:   'direct'|'relay',
  settings: { <every whitelisted key, typed> },
  settings_choices: { authors: [{id, name}], categories: [{id, name, level}] } }
```
`settings` is typed JSON (real bools, ints, `excluded_authors`/`excluded_cats` as int[], `post_types` as string[]);
`save_settings` accepts the same typed shape (partial objects allowed). `authors` = users who can publish
(max 500); `categories` = `Editor\PanelConfig::categories()`. `js/cherry-app.css` is enqueued with the script.
`learned` = capabilities plan `indexed` → readiness `index_rows`; `checked` = link-health `posts_checked`;
`links` = readiness `scheme_rows`. Never cache formatted text.

### link health

```
{ checked_at: ISO8601|null,
  summary: { orphans, broken, no_outgoing, repeats, self_links, links_total|null, posts_checked|null },
  rows: [ { post_id, title, type, type_label, url, edit_url, learned: bool,
            orphan: bool, no_outgoing: bool, repeats: [{target_url,count}], broken: [{target_url,anchor}],
            self: [anchor] } ] }
```
Built from `GET /v1/links/problems` (rows keyed by `source_id`, fallback `url_to_postid`). `learned` =
plan unlimited, or the post is among the newest `post_limit` eligible posts by `post_modified_gmt`
(same rule as server admission; one cutoff query). `summary.links_total`/`posts_checked` come from the
problems response when present (newer API), else readiness counts / null.

### settings whitelist

`limit_ajax` (int 1–100), `show_cat_filter` (bool), `match_cat` (bool), `excluded_authors` (int[]),
`excluded_cats` (int[]), `consider_max_incoming_links` (bool), `max_incoming_links` (int ≥1),
`relative_links` ('full'|'no_proto'|'no_domain'), `link_title_attr` (bool), `link_new_tab` (bool),
`link_class` (space-separated `sanitize_html_class` tokens), content types (`show_pages` + `show_customs`,
exposed as `post_types: string[]`), `vector_custom_fields` (textarea). Stored in the existing string forms
('true'/'false', comma lists) so the rest of the plugin reads them unchanged.

## Link markup (insert-time only)

`Editor\LinkMarkup::build(string $url, string $title, array $options): {open, close}` is the single builder for
posts, terms and Gutenberg: `href` (URL format via `LinkFields::url` / `relative_links`), optional `title`,
optional `target="_blank" rel="noopener"`, optional `class`. No free-form templates, no nofollow.
Migration (Lifecycle upgrade + settings import): decode `link_before`/`term_before`; default template →
title on; otherwise title on iff it contains `title=`, new tab iff `target="_blank"`, class from `class="…"`;
then delete `link_before|link_after|term_before|term_after`. Changing options never rewrites existing links
(UI says so).

## Connection route

No alt-server toggle. `Api\Client` sends directly; on a transport failure (WP_Error, no HTTP status) of the
direct route it sets transient `cherrylink_relay_until` (24 h) and retries that request once via the relay
port within the remaining budget. While the transient is set, requests use the relay. Route shown in
Settings › Troubleshooting.

## Reports / hosts

The plugin sends no usage or lifecycle reports. Licensing default `https://api.cherrylink.app/cherrylink-pro-licensing/` (override constant stays).
User-facing links live only in `Support\Urls` (cherrylink.app; may become market-aware, see plan step 6).

## Content types and the plan limit

The content types chosen on Index (post always; `show_pages`, `show_customs`) decide what is
indexed, counted (`counts.total`), learned and suggested: `IndexHelpers::get_allowed_types_list()`.
`get_public_types_list()` lists every public type for the pickers. Pages are on by default.

A post outside the learned set keeps link health and its own editor suggestions; it is only never
suggested as a link target in other posts. Updating it makes it newest, so it gets learned.
This is explained (with Upgrade) in the editor strip (`config.postLearned === false`), on Link health
rows, on Index ("Why only N?") and in Settings › License.

`learned` on Link health rows uses the newest-N cutoff with N = max(stored plan limit, the API's
learned count from the capabilities snapshot), so a raised plan that the licence check has not yet
stored never marks learned posts as locked.

Orphan pages tab, in three parts: **Selected page** (the `#orphans-<id>` deep link from the dashboard's
"Easiest wins"; its donors load on open), **Ready to fix** (learned orphans, paged; the Related posts
cell holds a "Find posts that could link here" button until donors are loaded on click, one search plus
up to five anchor calls each) and **Not learned on your plan** (orphans outside the limit: a blurred,
`aria-hidden` preview of up to five rows under an overlay with the count, the limit and Upgrade). The
headings appear only when there is a selected page or locked rows. Export CSV still exports every row.

No outgoing tab: the Related posts cell holds "Find posts to link to" (on click, never preloaded).
`cherrylink_post_targets` runs the editor panel pipeline (`SuggestionsResults::rows`, one search call on
the saved content) and returns up to five targets in the donors shape, each with a link phrase from
this post that no earlier target already uses; Edit opens this post. Results are kept per tab, since a
post can be both an orphan and without outgoing links.

## Purged index (`index_expired`)

The API deletes a free site's index (vectors, link graph, inventory) after 180 days without contact and reports
`plan.index_expired: true` in `GET /v1/capabilities`. `Capabilities::summarize()` carries it into the cached
snapshot (`plan.index_expired`, default false; absent on older APIs). `AdminApi::state()` exposes
`index_expired`; when set, the Dashboard shows a "Your site data was removed" notice with one **Analyze site**
button (the normal `cherrylink_sync_start`), hides the stale link-health cards, and Link health shows the same
notice instead of cached results. `SyncService::start()` clears the cached flag locally and deletes the
`cherrylink_link_health` option, so the notice disappears at once instead of after the 5-minute refresh (the
API flips it back on `POST /v1/sync/plan`). The editor strip shows a `.cl-plan-note` (`config.indexExpired`,
`PanelConfig`) linking to the dashboard (`config.connectUrl`). The Wizard Connect disclosure states the 180-day
deletion.

## Key pages (v1)

Contract: `api/specs/key-pages.md`. `Support\KeyPages` keeps the last result in the non-autoloaded option
`cherrylink_key_pages` (`{checked_at, key_pages, posts_considered}`, each page/member enriched with
`edit_url` and the current WP title, pages also with `view_url`) and the site-wide choices in
`cherrylink_key_pins` (`{pin:int[], demote:int[]}`). `Api\Client::key_pages(pin, demote)` is
`POST /v1/key-pages` (background context). Both options are removed on uninstall; the result (not the
pins) is also cleared where `cherrylink_link_health` is: "Remove data" and the purged-index rescan.

- `cherrylink_admin_link_health` now also returns `keys` (`KeyPages::payload()`: the stored result plus
  `demoted: [{post_id,title,edit_url}]`). With `refresh=1` it recomputes key pages after the health call;
  a key-pages failure keeps the previous result and never fails the health refresh.
- `cherrylink_admin_key_pin` `{post_id, mode: pin|demote|clear}` (same nonce + `cherrylink_settings` guard):
  stores the choice (one choice per post), refreshes, returns `{keys}`.
- Link health gets a **Key pages** tab (first in the bar, deep link `#keys`; the default tab is still
  Orphan pages): title (edit link), Pinned/Underlinked badges, group size (members + 1), inbound count,
  expandable members that do not link yet (Edit links), Pin/Unpin and Demote buttons, and a "Demoted pages"
  list with Restore. Help text: found automatically from what posts are about; free plan counts only learned posts.
