import { debounce, CHANGE_DEBOUNCE_MS, stripTags, linkTags, wrapWithLink, fail } from './util';
import { locatePhrase, linksInHtml } from './textSearch';

const OPEN_A = /<a\b/gi;
const CLOSE_A = /<\/a\s*>/gi;

function insideLink(value, pos) {
    const before = value.slice(0, pos);
    const opens = (before.match(OPEN_A) || []).length;
    const closes = (before.match(CLOSE_A) || []).length;
    return opens > closes;
}

/**
 * EditorAdapter over a plain <textarea> (Classic "Text" tab, term description).
 * @param {() => HTMLTextAreaElement|null} getEl
 */
export function createTextareaAdapter(getEl) {
    const el = () => getEl();

    function write(newValue) {
        const t = el();
        t.value = newValue;
        t.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function replace(start, end, inserted, original) {
        const t = el();
        const v = t.value;
        write(v.slice(0, start) + inserted + v.slice(end));
        return { ok: true, ref: { start, inserted, original } };
    }

    return {
        getHtml: () => (el() ? el().value : ''),
        getText: () => stripTags(el() ? el().value : ''),
        getSelection() {
            const t = el();
            if (!t) return { text: '', collapsed: true };
            const text = t.value.slice(t.selectionStart, t.selectionEnd);
            return { text, collapsed: t.selectionStart === t.selectionEnd };
        },
        applyLinkToSelection(link) {
            const t = el();
            if (!t) return fail('unsupported');
            const { selectionStart: s, selectionEnd: e, value } = t;
            const sel = value.slice(s, e);
            if (s === e || sel.trim() === '') return fail('no_selection');
            if (insideLink(value, s) || insideLink(value, e)) return fail('inside_link');
            const { open, close } = linkTags(link);
            return replace(s, e, wrapWithLink(open, sel, close), sel);
        },
        applyLinkToPhrase(phrase, blockIndex, link) {
            const t = el();
            if (!t) return fail('unsupported');
            const hit = locatePhrase(t.value, phrase, blockIndex);
            if (!hit) return fail('not_found');
            const { open, close } = linkTags(link);
            const original = t.value.slice(hit.start, hit.end);
            return replace(hit.start, hit.end, open + original + close, original);
        },
        /** Optional extension: insert text wrapped in the link at the caret (no selection). */
        insertAtCaret(link, text) {
            const t = el();
            if (!t) return fail('unsupported');
            const { open, close } = linkTags(link);
            const pos = t.selectionStart;
            return replace(pos, t.selectionEnd, open + text + close, '');
        },
        removeLink(ref) {
            const t = el();
            if (!t || !ref || !ref.inserted) return;
            let at = t.value.slice(ref.start, ref.start + ref.inserted.length) === ref.inserted ? ref.start : t.value.indexOf(ref.inserted);
            if (at < 0) return;
            const v = t.value;
            write(v.slice(0, at) + ref.original + v.slice(at + ref.inserted.length));
        },
        listLinks() {
            return linksInHtml(el() ? el().value : '').map((l) => ({ url: l.url, anchor: l.anchor, ref: { index: l.index, url: l.url, anchor: l.anchor } }));
        },
        reveal(arg) {
            // A text area cannot paint ranges without stealing the selection, so only explicit
            // link reveals (ref objects) move the selection; hover reveals are no-ops.
            const t = el();
            if (!t || !arg || typeof arg !== 'object') return;
            const l = linksInHtml(t.value).find((x) => x.url === arg.url && x.anchor === arg.anchor);
            if (!l) return;
            t.focus();
            t.setSelectionRange(l.index, l.index + 1);
        },
        clearReveal() {},
        onChange(cb) {
            const t = el();
            if (!t) return () => {};
            const d = debounce(cb, CHANGE_DEBOUNCE_MS);
            t.addEventListener('input', d);
            t.addEventListener('change', d);
            return () => {
                d.cancel();
                t.removeEventListener('input', d);
                t.removeEventListener('change', d);
            };
        },
    };
}
