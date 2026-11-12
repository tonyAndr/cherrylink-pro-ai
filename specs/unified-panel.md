# Unified editor panel (spec, 2026-11-11)

Status: in implementation on branch `feature/unified-panel` (experimental, not for release yet).
Visual reference (clickable prototype with real data): `../prototypes/panel-opportunities.html`
(workspace root). Background and decisions: `../PANEL-BRAINSTORM-2026-11-11.md` §11-§13.

One React app serves the Gutenberg sidebar, the Classic editor (TinyMCE visual + text tab) and the
term edit screen. Only document operations differ, behind an `EditorAdapter`. The jQuery panel
(`js/cherry-front.js`, `templates/editor/classic-panel.php`, `templates/link-card/*`) was removed in
phase 3 (done 2026-11-11).

## 1. UX

Header: cherry logo + "CherryLink" (Classic host only; the Gutenberg `PluginSidebar` title "CherryLink" is the header). Under it a **strip**: `Исходящих N · Входящих M` (counts are
buttons that open the **Ссылки** view) plus at most one chip from `linkAdvice(words, out, in)`
(Strip.js; soft heuristics, not a Google rule). From 100 live words the outgoing count shows a recommended
range `N/min–max`, min = max(1, ⌊words/300⌋), max = max(1, ⌈words/200⌉). Chip priority:
incoming = 0 → warn "Нет входящих · K статьи могут сослаться" (opens Входящие; static "Нет входящих
ссылок" without donors); outgoing < min → warn "Мало исходящих · нужно min–max" (opens Подсказки);
outgoing > max(3, ⌈words/100⌉) → static warn "Много ссылок"; incoming < 3 → soft "Мало входящих"
(opens Входящие); else outgoing > 0 → ok "Ссылок достаточно". Word count updates with the editor.

Tabs:
1. **Подсказки** (default, badge = count). Cards: context snippet with the phrase marked, `→ target
   title`, buttons `Вставить ссылку` (primary), `Другая статья (i/n)` when the phrase fits several
   targets, `Скрыть` (ghost). Hover/focus on a card reveals the phrase in the editor. After insert:
   green line "Добавлено: «phrase» → title · Отменить" and a toast with "Отменить". Hidden cards go
   to a collapsed "Скрытые подсказки (N)" list with "Вернуть" per item (session-only state).
   Footer: "Подсказки по текущему тексту" + `Обновить` (refetch with live content).
   Assignment: iterate targets in rank order, give each the first suggestion phrase not used yet,
   not already linked in the text and not hidden; alternatives = other unlinked targets whose
   suggestions contain the same phrase. A target already linked from this post gets no card.
2. **Все статьи**: segmented switch `Статьи | Рубрики`.
   - Статьи: search input "Найти статьи по теме…" (server-side semantic query, debounced 400 ms,
     replaces the live-text query), optional category filter when `show_cat_filter` is on, rows:
     title, `↑out ↓in`, badge `✓ ссылка есть` if linked, else button `Вставить` (tooltip explains; link the current
     selection; whitespace-only/empty selection → toast "Выделите текст в статье для вставки ссылки",
     or the `no_selection_action` behaviour on Classic: insert title/placeholder at caret), up to 3
     anchor pills (click = insert at that phrase), small icon links `↗` view / edit (new tab).
     "Показать ещё" pagination (offset).
   - Рубрики: hierarchical category/tag list (existing terms data), each row `Вставить` (selection) like posts.
3. **Входящие**: hint text; donor cards: `← donor title`, context snippet, `Вставить ссылку` (primary;
   writes into the donor server side), small ghost `Открыть ↗` (donor edit screen, new tab, with
   `&cherrylink_target=<this id>`), `↓ in` meta. Inserted → "✓ Ссылка добавлена · Отменить".
   Donor locked by another user or not editable → no insert button, reason text instead.
   Donor without a phrase → "Подходящей фразы не нашлось" + only `Открыть ↗`.

**Ссылки** view (opened from the strip; back arrow returns to the tab): outgoing links of the live
text (anchor, target title if internal, click = reveal in editor) and incoming links (source title,
anchor, edit/view links). This replaces the old Stats tab.

Empty / error / loading states: skeleton rows while loading; API errors show the message plus
`Повторить`; unlicensed → single Connect button (see Free mode additions).

## 2. Visual system

CSS custom properties on `.cl-panel` root (copy values from the prototype): `--cl-accent #a8123e`,
`--cl-accent-ink`, `--cl-accent-soft`, `--cl-accent-line`, `--cl-surface`, `--cl-surface-alt`,
`--cl-border`, `--cl-text`, `--cl-muted`, `--cl-ok`, `--cl-ok-soft`, `--cl-warn`, `--cl-warn-soft`,
`--cl-mark`, `--cl-radius`. Fonts: inherit the WP admin system font (no web fonts). All icons inline
SVG (no PNG). Everything prefixed `cl-` to avoid wp-admin collisions. Visible focus states.
Classic host: floating box 340px wide, draggable by the header, toggled by the existing media button
("CherryLink"), position remembered in localStorage (try/catch). Gutenberg host: `PluginSidebar`.

## 3. Front-end architecture (`gutenberg-src/`)

```
src/panel/                      shared app (no editor-specific imports)
  App.js, Strip.js, Tabs.js, OpportunityList.js, PostList.js, TermsList.js, DonorList.js,
  LinksView.js, Toast.js, icons.js, api.js (fetch wrappers), opportunities.js (pure assignment),
  panel.css (tokens + components)
src/adapters/
  EditorAdapter.md (contract), gutenberg.js, tinymce.js, textarea.js, classicSwitch.js
src/index.js                    Gutenberg entry: registerPlugin + PluginSidebar → <App adapter=…/>
src/classic.js                  Classic/term entry: floating host → <App adapter=classicSwitch/>
```
`EditorAdapter`:
```
getHtml(): string                                  live content (for anchors + query)
getText(): string                                  plain text of live content
getSelection(): { text, collapsed }
applyLinkToSelection(link): { ok, ref?, reason? }  reason 'no_selection' | 'inside_link' | 'unsupported'
applyLinkToPhrase(phrase, blockIndex, link): { ok, ref?, reason? }   first unlinked occurrence in
                                                   block blockIndex, then anywhere; reason 'not_found'
removeLink(ref): void                              undo of the two above
listLinks(): [{ url, anchor, ref }]
reveal(phrase, blockIndex) / reveal(ref) / clearReveal()
onChange(cb): unsubscribe                          debounced by the adapter (≈1 s)
```
`link` = `{ url, title, open, close }`; Gutenberg applies `core/link` (url, title attr) and ignores
open/close; TinyMCE/textarea wrap with `open`+text+`close` (server-rendered from the link templates
`link_before/link_after` and the relative-link setting). Block index = index among blocks of type
p/h1-h6/li/blockquote/td whose text is longer than 10 chars (same rule as the API extractor).
Gutenberg adapter reuses `HOC/blockUtils.js`, the RichText format logic in `HOC/cherryRichText.js`
and `HOC/scrollToEditor.js` (move them under `src/adapters/gutenberg/` if convenient); unsupported
blocks → reason 'unsupported'. Classic blocks (`core/freeform`) are supported for phrase insert/list/undo/reveal: `attributes.content` is parsed in a detached DOM, counted elements follow the same p/h/li/blockquote/td rule, and the phrase is wrapped by DOM range (may span inline tags). Raw text without block tags is split on blank lines and saved wrapped in `<p>` (wpautop-like). Manual selection insert inside a Classic block (TinyMCE) returns 'unsupported'; the TinyMCE view may not refresh until the block is re-focused. classicSwitch delegates to tinymce or textarea
depending on which Classic tab is active (and the term description editor).

Build: webpack entries `cherry-gutenberg` (src/index.js) and `cherry-classic` (src/classic.js);
outputs copied to `../js/cherry-gutenberg.{js,css,asset.php}` and `../js/cherry-classic.{js,css,asset.php}`.
Classic bundle may depend only on `wp-element`, `wp-i18n` (core since 5.0). Gutenberg bundle as today.
Tests (Jest): opportunities.js assignment, textarea adapter, tinymce adapter with a fake editor,
gutenberg adapter with mocked data stores (existing tests patterns), App smoke render.

## 4. Backend (PHP)

Bootstrap config (both hosts, `wp_localize_script` as `cherrylinkPanel`):
`{ ajaxUrl, nonce, postId, isTerm, termTaxonomy?, host: 'gutenberg'|'classic', options:
{ showCatFilter, noSelectionAction, limit }, licensed: bool, editUrlBase }`.

New service `Editor\PanelApi` (Registerable). All actions: `Security::verify('edit_posts')`, JSON body
(`php://input`), JSON response `{ ok: true, ... }` or `{ ok: false, error: code, message }`.
| action | input | output |
|---|---|---|
| `cherrylink_panel_links` | post_id, is_term, source_html (live, raw), query (optional topic text), category (opt), offset | `{ items: [Row], has_more }` |
| `cherrylink_panel_terms` | — | existing hierarchical terms data, each with url/title/open/close |
| `cherrylink_panel_incoming` | object_id, type post/term | `{ items: [{ source_id, source_title, source_url, edit_url, anchor }] }` |
| `cherrylink_panel_donors` | post_id | `{ items: [Donor] }` |
| `cherrylink_panel_donor_insert` | donor_id, target_id, phrase, block_index | `{ undo_token }` |
| `cherrylink_panel_donor_undo` | donor_id, undo_token | `{}` |

`Row = { post_id, score, title, url, view_url, edit_url, out, in, saturated, linked?: false, open, close,
suggestions: [{ phrase, context, block_index }] }`. `score` is the raw API cosine;
the panel shows it as a relevance meter (`relevance()` in `opportunities.js` rescales the E5 band
0.82–0.95 to 0–100%, then scales the list so the best match closes half its gap to 100 (best ≥50%);
scaling keeps unrelated articles at 0% and the ratios between articles; tiers ≥60 green, ≥30 amber) on «Все статьи» rows and on suggestion cards (the
target article's score — anchors carry no score of their own). Recalibrate if the embedding model changes. Donors are searched with the same title + text query,
so «Входящие» scores are on the same scale (and the same pair scores alike in both directions). Links pipeline = today's
`SuggestionsResults` filtering (status, type, category match, exclusions, private, max incoming,
offset/limit) refactored to return structured rows; query = `query` if given, else title +
plain text of `source_html`, else saved content. Call `/v1/search` with `source_html` (live),
`lang` (Language::forPost), `suggestion_hits` = min(10, limit). Keep the old `get_linkate_links`
renderers working until phase 3.

`Donor = { donor_id, score, title, edit_url, view_url, in, can_edit, locked_by?, suggestion: {phrase,
context, block_index} | null }`: search by this post's title + text (+lang), up to 5 published donors
excluding this post and donors whose content already links to this post's permalink; one
`suggest_anchors(donor_html, title, 1, lang, target_post_id = this post)` per donor.

Donor insert (`Editor\ContentLinker`, pure + unit-tested): find the phrase in block `block_index`
of the donor's `post_content` (same block rule as above; then anywhere), only in text nodes, never
inside an existing `<a>`, an HTML comment (block delimiters), a tag or attribute; wrap the first match
with the link markup (Gutenberg-serialized content: plain `<a href>` without templates; classic
content: link templates). Guards: `current_user_can('edit_post', donor)`, `wp_check_post_lock` (refuse
if another user holds it), donor published. Save with `wp_update_post` (creates a revision; existing
save hooks reindex links). Undo token: store `{ previous_content, new_hash }` in a 1-hour transient
keyed by donor + token; undo restores only if the current content hash still equals `new_hash`,
else error `changed`.

Enqueue: Gutenberg as today (new config). Classic post/page edit screens (non-block) and `term.php`:
enqueue `js/cherry-classic.js` + css + config instead of cherry-front.js/cherry-main.css and the panel
template. Keep the media button as the toggle.

## 5. Phases

1. Backend endpoints + ContentLinker (+tests) ‖ React app + adapters + both entries (+tests).
2. Integration in wp-dev (Gutenberg post 105, Classic post 42, a term page): parity checklist below.
3. Remove `js/cherry-front.js`, `templates/editor/classic-panel.php`, `templates/link-card/*`,
   `get_linkate_links`/`get_linkate_suggestions` handlers, old `src/sidebar/*`, PNG tab icons and the
   editor parts of `css/cherry-main.css` (check the settings page does not use them); update
   `specs/editor-ui.md`, i18n catalog.

Parity checklist: insert by selection (both editors, text tab, term page), insert at phrase, undo,
reveal, link templates + relative links on Classic, category filter, topic search, pagination,
terms linking, incoming list, donor insert/undo, locked donor, unlicensed state, no console errors.

## 6. Backend notes (implementation, 2026-11-11)

- Phase 3 done: old panel, `get_linkate_*` actions, `SuggestionsResults::execute()`, `src/sidebar/`, PNG tab icons and
  editor CSS removed; `css/cherry-main.css` keeps only the media-button icon; Gutenberg helpers moved to
  `src/adapters/gutenberg/`. Debug probe `output_admin_debug_links` now uses `rows()`.
- Files: `src/Editor/{PanelApi,ContentLinker,DonorFinder,LinkMarkup,PanelConfig}.php`;
  `SuggestionsResults::rows()` is the structured pipeline (the old `execute()` was removed in phase 3).
- Config is emitted with `wp_add_inline_script(..., 'before')` (`window.cherrylinkPanel = {...}`),
  not `wp_localize_script`, so booleans/ints keep their types. Extra key `categories: [{id,name,level}]`
  (only when `showCatFilter`). `licensed` is false for unconnected sites (the host still loads; the panel shows the Connect button).
- Classic toggle: the media button has `id="cherrylink-panel-toggle"` (printed once per page) and class
  `cherrylink-panel-toggle`. Old jQuery panel is only used when `js/cherry-classic.js` does not exist.
- Error replies: `{ok:false,error,message}` with HTTP status. Codes: forbidden 403, bad_request 400,
  not_published/locked/already_linked/changed 409, phrase_not_found 422, expired 410, save_failed 500.
  Security::verify failures use WP's own `{success:false}` 403.
- Row: `linked` is omitted (front derives it); `saturated` is always false because saturated posts are
  still dropped by the max-incoming filter. `out`/`in` come from `cherry_outgoing`/`cherry_income` post meta.
  `category` accepts a term id or a category name. `has_more` = SQL found more than `limit` rows; the API
  returns at most 50 hits, so offset paging is bounded by that.
- Terms reply: `{items:[{is_divider:true,title}|{is_divider:false,title,url,taxonomy,post_count,open,close,children}]}`.
- Donors: `suggest_anchors` is only called for donors the user can edit and that are not locked
  (otherwise `suggestion:null` with `can_edit`/`locked_by` explaining why). `locked_by` is the display name.
- Donor insert returns `{ok:true, undo_token}`. Content with `<!-- wp:` gets a plain
  `<a href title>` (relative-link setting applied); otherwise the link_before/link_after templates.
- Plain-text donor content (no tags) uses blank-line paragraphs as blocks, like `buildSourceHtml`.
- `StatisticsService::ajaxOrphanDonors` is untouched (still works; does not share DonorFinder).

## Free mode additions

Config also carries `connectUrl`, `upgradeUrl` and `plan`. Unlicensed: a single "Подключить CherryLink" button
(no fetch). Plan-limited sites: the strip shows "Проиндексировано N из M · Расширить". Details in
`connect-and-plans.md`.

## Key pages (v1)

Contract: `api/specs/key-pages.md`; storage and admin side in `specs/admin-ui.md` ("Key pages").

- `PanelConfig` passes `keyPage = KeyPages::forPost($id)` = `{is_key, key:{post_id,title,url,view_url}|null,
  members_not_linking, pinned, demoted}` (null for terms and while the index is purged) and
  `canKeyPin` (`edit_others_posts`).
- Strip: a post in a key page's group whose **live** editor links (same `isRowLinked`/`normalizeUrl` as the
  suggestions) lack the key page shows "Doesn't link to key page “Y” yet" (opens Suggestions). A key page shows
  "Key page · N related posts don't link here" (opens Incoming; plain "Key page" when N = 0). The small
  "Mark as key page" / "Unmark key page" link (only with `canKeyPin`) calls `cherrylink_panel_key_pin`
  `{post_id, mode}` (`edit_posts` nonce + `edit_post` on the post + `edit_others_posts`; pins are site-wide),
  which stores the pin (`pin`; unmark = `clear` if pinned, else `demote`), refreshes and returns `{keyPage}`.
- Suggestion rows carry `is_key`; PostList and OpportunityList show a "Key page" badge. Ranking lives in one
  place, `SuggestionsResults::rows()`: API order is re-ranked by `score + KeyPages::BOOST` (0.004 cosine ≈ 3 display points, a tie-breaker) for key pages
  (`KeyPages::rank`), the displayed relevance still uses the raw score.
