# EditorAdapter contract

All methods are synchronous. `link = { url, title, open, close }`.

| method | returns |
|---|---|
| `getHtml()` / `getText()` | live content (html / plain text) |
| `getSelection()` | `{ text, collapsed }` |
| `applyLinkToSelection(link)` | `{ ok, ref?, reason? }`, reason `no_selection` / `inside_link` / `unsupported` |
| `applyLinkToPhrase(phrase, blockIndex, link)` | same; reason `not_found` / `unsupported` |
| `insertAtCaret(link, text)` | optional extension (Classic adapters): insert `text` wrapped in the link at the caret |
| `removeLink(ref)` | undo of the apply calls |
| `listLinks()` | `[{ url, anchor, ref }]` |
| `reveal(phrase, blockIndex)` / `reveal(ref)` / `clearReveal()` | non-destructive highlight + scroll |
| `onChange(cb)` | debounced (about 1 s); returns unsubscribe |

Block index = index among p/h1-h6/li/blockquote/td blocks whose text is longer than 10 chars.
Gutenberg ignores `open`/`close`; TinyMCE/textarea wrap with them (fallback: plain `<a href>`).
