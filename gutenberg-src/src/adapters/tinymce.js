import { debounce, CHANGE_DEBOUNCE_MS, MIN_BLOCK_LEN, BLOCK_SELECTOR, linkTags, wrapWithLink, nextMarker, unwrapElement, escapeHtml, fail } from './util';
import { highlightRange, clearHighlight, findTextRange } from './highlight';

const closestLink = (node) => {
    const el = node && node.nodeType === 3 ? node.parentElement : node;
    return el && el.closest ? el.closest('a') : null;
};

/**
 * EditorAdapter over a TinyMCE editor (Classic visual tab).
 * @param {() => object|null} getEditor  returns the tinymce Editor (window.tinymce.activeEditor by default)
 */
export function createTinymceAdapter(getEditor) {
    const ed = () => getEditor();
    const body = () => (ed() && ed().getBody()) || null;
    const doc = () => (ed() && ed().getDoc && ed().getDoc()) || (body() && body().ownerDocument) || document;

    const blocks = () =>
        body()
            ? [...body().querySelectorAll(BLOCK_SELECTOR)].filter((b) => (b.textContent || '').trim().length > MIN_BLOCK_LEN)
            : [];

    /** Insert `html` over the current selection as one undo level and return the created <a>. */
    function insertWrapped(buildHtml, marker) {
        const editor = ed();
        editor.undoManager.transact(() => buildHtml(editor));
        const a = body().querySelector(`a[data-cl-ref="${marker}"]`);
        if (a) a.removeAttribute('data-cl-ref');
        return a;
    }

    const refOf = (a, link) => ({ el: a, url: link.url, anchor: a ? a.textContent : '' });

    return {
        getHtml: () => (ed() ? ed().getContent() : ''),
        getText: () => (body() ? body().textContent || '' : ''),
        getSelection() {
            const e = ed();
            if (!e) return { text: '', collapsed: true };
            const rng = e.selection.getRng();
            return { text: rng.toString(), collapsed: !!rng.collapsed };
        },
        applyLinkToSelection(link) {
            const e = ed();
            if (!e) return fail('unsupported');
            const rng = e.selection.getRng();
            const html = e.selection.getContent();
            const hasImg = /<img\b/i.test(html);
            if (rng.collapsed || (rng.toString().trim() === '' && !hasImg)) return fail('no_selection');
            if (closestLink(rng.startContainer) || closestLink(rng.endContainer) || closestLink(rng.commonAncestorContainer)) {
                return fail('inside_link');
            }
            const marker = nextMarker();
            const { open, close } = linkTags(link, marker);
            const a = insertWrapped((editor) => editor.selection.setContent(wrapWithLink(open, html, close)), marker);
            return { ok: true, ref: refOf(a, link) };
        },
        applyLinkToPhrase(phrase, blockIndex, link) {
            const e = ed();
            if (!e || !body()) return fail('unsupported');
            const list = blocks();
            const order = [list[blockIndex], ...list].filter(Boolean);
            let range = null;
            for (const b of order) {
                range = findTextRange(b, phrase);
                if (range) break;
            }
            if (!range) return fail('not_found');
            const marker = nextMarker();
            const { open, close } = linkTags(link, marker);
            const a = insertWrapped((editor) => {
                editor.selection.setRng(range);
                editor.selection.setContent(open + escapeHtml(range.toString()) + close);
            }, marker);
            return { ok: true, ref: refOf(a, link) };
        },
        /** Optional extension: insert text wrapped in the link at the caret (no selection). */
        insertAtCaret(link, text) {
            const e = ed();
            if (!e) return fail('unsupported');
            const marker = nextMarker();
            const { open, close } = linkTags(link, marker);
            const a = insertWrapped((editor) => editor.insertContent(open + text + close), marker);
            return { ok: true, ref: refOf(a, link) };
        },
        removeLink(ref) {
            const e = ed();
            if (!e || !ref) return;
            let a = ref.el && ref.el.isConnected ? ref.el : null;
            if (!a && body()) {
                a = [...body().querySelectorAll('a[href]')].find((x) => x.getAttribute('href') === ref.url && x.textContent === ref.anchor) || null;
            }
            if (!a) return;
            e.undoManager.transact(() => unwrapElement(a));
            if (e.nodeChanged) e.nodeChanged();
        },
        listLinks() {
            if (!body()) return [];
            return [...body().querySelectorAll('a[href]')].map((a) => ({
                url: a.getAttribute('data-mce-href') || a.getAttribute('href'),
                anchor: a.textContent,
                ref: { el: a, url: a.getAttribute('href'), anchor: a.textContent },
            }));
        },
        reveal(arg, blockIndex) {
            if (!body()) return;
            let target = null;
            let range = null;
            if (arg && typeof arg === 'object') {
                const a = arg.el && arg.el.isConnected ? arg.el : null;
                if (!a) return;
                target = a;
                range = doc().createRange();
                range.selectNodeContents(a);
            } else {
                const list = blocks();
                for (const b of [list[blockIndex], ...list].filter(Boolean)) {
                    range = findTextRange(b, arg);
                    if (range) { target = b; break; }
                }
            }
            if (!range) return;
            if (!highlightRange(range, doc()) && arg && typeof arg === 'object') ed().selection.select(target);
            if (target.scrollIntoView) target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        },
        clearReveal() {
            if (ed()) clearHighlight(doc());
        },
        onChange(cb) {
            const e = ed();
            if (!e || !e.on) return () => {};
            const d = debounce(cb, CHANGE_DEBOUNCE_MS);
            const events = 'input change keyup undo redo SetContent';
            e.on(events, d);
            return () => {
                d.cancel();
                e.off && e.off(events, d);
            };
        },
    };
}
