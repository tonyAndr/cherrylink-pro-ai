# Gutenberg Sidebar

## Purpose
A React sidebar panel registered as a Gutenberg plugin, providing the same linking functionality as the classic editor panel but adapted for the block editor.

## Build
Source: `plugin/gutenberg-src/` (`@wordpress/scripts` v32 / Webpack 5)
Outputs: `plugin/js/cherry-gutenberg.js`, `cherry-gutenberg.css`, `cherry-gutenberg.asset.php`

```bash
cd plugin/gutenberg-src
npm install
npm run build        # builds + auto-copies to ../js/ via CopyToJsPlugin
npm run start        # dev watcher — same copy on every rebuild
```

Always do a clean build before committing: `rm -rf build && npm run build`

## Architecture
Superseded by `specs/unified-panel.md`. Current layout:
```
src/index.js                    <- registers the Gutenberg plugin + PluginSidebar ("CherryLink")
src/classic.js                  <- Classic/term host (separate bundle)
src/panel/                      <- shared React app
src/adapters/                   <- EditorAdapter implementations
src/adapters/gutenberg/         <- blockUtils.js, cherryRichText.js, scrollToEditor.js, Icons.js
```
The former `src/sidebar/` tree (tabs, Wrapper, `Data/*`, `get_linkate_links`) was deleted.

### Tests
Jest tests live in `gutenberg-src/src/{panel,adapters}/**/__tests__/`.
Run with: `cd gutenberg-src && npm test -- --watchAll=false`

## Acceptance criteria
- [x] Sidebar panel loads and CSS renders correctly
- [x] Sidebar opens and loads link list on post open (`GetData` fires on `componentDidMount`; covered by unit tests)
- [ ] Clicking a suggestion scrolls to the block containing the linked URL (needs browser verification)
- [ ] "Insert" wraps selected text in `<a>` in the focused block (needs browser verification)
- [ ] Stats tab shows incoming and outgoing links (needs browser verification)
- [ ] Works inside the Gutenberg iframe editor (not just legacy non-iframe)

## Non-obvious constraints
- Scroll to block works in the iframe editor: `scrollToEditor.js` uses `wp.data` store + `editor.setIsInserterOpened` side effects to focus the correct block
- `withLinkInsert` must find the currently focused RichText block — if no block is focused, insert fails silently
- `data-suggestions` in the PHP-rendered link items uses `str_replace('"', '\\"', ...)` for JSON-in-JSON encoding — different from classic editor path which uses `htmlspecialchars`

## WP version compatibility (build toolchain)
These constraints apply when running on WP 6.5 with @wordpress/scripts v32:

- **`react-jsx-runtime` not in WP 6.5** (added WP 6.6): `webpack.config.js` overrides `DependencyExtractionWebpackPlugin` to bundle `react/jsx-runtime` inline (`requestToExternal` returns `false` for it). Do not add `react-jsx-runtime` to the PHP deps list.
- **`PluginSidebar` location**: WP ≤6.5 → `@wordpress/edit-post`; WP 6.6+ → `@wordpress/editor`. Resolved lazily at render time in `Wrapper.js` via `window.wp.editor?.PluginSidebar ?? window.wp.editPost.PluginSidebar`. Do not use a static import.
- **CSS**: extracted to `index.css` by MiniCssExtractPlugin, copied to `../js/cherry-gutenberg.css` by `CopyToJsPlugin`. Enqueued via `wp_enqueue_style` in `cherrylink_gutenberg.php`.
- **Silent script drop**: WordPress drops any script whose declared dependency handle isn't registered. Always verify handles exist in the target WP version before adding them to the asset list.
