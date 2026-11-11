# Editor panel UI

The editor panel is one React app shared by the Gutenberg sidebar, the Classic editor
(TinyMCE visual + text tab) and the term edit screen. Full spec: `specs/unified-panel.md`
(UX, visual system, adapter contract, PHP endpoints). The old jQuery panel
(`js/cherry-front.js`, `templates/editor/classic-panel.php`, `templates/link-card/*`), its
`get_linkate_links` / `get_linkate_suggestions` AJAX actions and the old `src/sidebar/` React
tree were removed in phase 3.

## Summary
- Strip under the header: `Исходящих N · Входящих M` (open the Ссылки view) + one status chip (link-count advice, see unified-panel.md).
- Tabs: **Подсказки** (cards, insert at phrase, alternatives, hide/restore, undo), **Все статьи**
  (switch Статьи | Рубрики; topic search, optional category filter, `Вставить` = link the selection),
  **Входящие** (donor articles; insert is written into the donor server side, with undo).
- Gutenberg host: `PluginSidebar` titled "CherryLink" (no extra logo row). Classic host: floating,
  draggable box toggled by the "CherryLink Pro" media button (`#cherrylink-panel-toggle`).
- Editor differences live behind `EditorAdapter` (`gutenberg-src/src/adapters/`, contract in
  `EditorAdapter.md`).

## Where things live
- Front end: `gutenberg-src/src/{panel,adapters,index.js,classic.js}` -> `js/cherry-gutenberg.*`,
  `js/cherry-classic.*` (built, do not hand-edit).
- PHP: `src/Editor/{PanelApi,PanelConfig,ContentLinker,DonorFinder,LinkMarkup,ClassicPanel,
  GutenbergAssets,SuggestionsResults}.php`. Actions: `cherrylink_panel_{links,terms,incoming,
  donors,donor_insert,donor_undo}`.
- `css/cherry-main.css` only holds the media-button icon.

## Acceptance
Parity checklist: `specs/unified-panel.md` section 5.
