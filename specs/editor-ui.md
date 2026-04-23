# Classic Editor Panel UI

## Purpose
A meta box panel in the WordPress classic editor (TinyMCE / textarea) with three tabs: article suggestions, taxonomy suggestions, and link stats.

## Panel structure
```
[tab: Links icon] [tab: Taxonomy icon] [tab: Stats icon]
─────────────────────────────────────────────────────
[tab content area]
─────────────────────────────────────────────────────
[footer: Outgoing N | Incoming N]   ← hidden on Stats tab
```

Tab icons: PNG files from `assets/gutenberg/tab-links.png`, `tab-taxonomies.png`, `tab-stats.png`.

## Articles tab
- Filter input (with X clear button — hidden unless text entered or filter applied)
- List of `.linkate-link` items, each with `data-url`, `data-title`, `data-suggestions` (JSON)
- Click item → expand suggestion list for that post
- Each suggestion item (`.cl-api-suggestion-item`): click → scroll to phrase in editor
- "Вставить" button → scroll to phrase + insert `<a href="url">phrase</a>`

## Stats tab
On activation (first time per page load):
1. Parse `<a href>` tags from current editor content → Outgoing section
2. Fetch `GET /v1/links/incoming?target_url=<permalink>` → Incoming section

Each outgoing item: click `.cl-incoming-anchor` → `fcl_findPhraseInEditor(phrase)`

Footer `.linkate-total-links` is hidden entirely when stats tab is active; shown on other tabs.

## Key JS functions (all at outer `$(document).ready` scope)
- `fcl_findPhraseInEditor(phrase)` — walks TinyMCE text nodes or textarea, selects + scrolls
- `fcl_isTinyMCE()` — returns true if active editor is TinyMCE
- `fcl_getEditorContent()` — gets raw HTML from TinyMCE or textarea
- `fcl_render_api_suggestions(triggerEl, item)` — builds suggestions panel HTML
- `fcl_enableSuggestionSwitches()` — wires up suggestion click handlers
- `fcl_load_incoming_tab()` — loads stats tab, one-shot (cached via `cl_incoming_loaded`)

## Acceptance criteria
- [ ] Clicking a suggestion item scrolls to and highlights the phrase in TinyMCE
- [ ] "Вставить" inserts a correct `<a>` tag at the phrase location
- [ ] Stats tab shows outgoing links parsed from current editor content
- [ ] Stats tab shows incoming links from API
- [ ] Footer hidden when stats tab active, visible on other tabs
- [ ] Filter X button hidden until text is typed
- [ ] Tab icons show at 55% opacity, 100% when selected

## Non-obvious constraints
- **Scope trap**: `fcl_findPhraseInEditor` and any function called from multiple places must be declared BEFORE `reLoad_when_data_received()` at the outer `$(document).ready` level — functions defined inside it are not accessible from outer event handlers
- **`data-suggestions` escaping**: HTML attribute uses `htmlspecialchars(ENT_COMPAT)`, not JSON encode — parse with `JSON.parse($(el).attr('data-suggestions').replace(/&quot;/g, '"'))`
- `cl_incoming_loaded` flag prevents double-loading the stats tab
- TinyMCE node walking: must handle both text nodes and element children
