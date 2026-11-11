const NAME = 'cl-hover';

function ensureStyle(doc) {
    if (!doc || !doc.head || doc.head.querySelector('style[data-cl-highlight]')) return;
    const s = doc.createElement('style');
    s.setAttribute('data-cl-highlight', '1');
    s.textContent = `::highlight(${NAME}){background-color:#ffe08a;color:inherit}`;
    doc.head.appendChild(s);
}

/** Paint a range without touching the editor selection. Returns false if unsupported. */
export function highlightRange(range, doc) {
    const win = doc && doc.defaultView;
    try {
        if (!win || !win.CSS || !win.CSS.highlights || !win.Highlight) return false;
        ensureStyle(doc);
        win.CSS.highlights.set(NAME, new win.Highlight(range));
        return true;
    } catch (e) {
        return false;
    }
}

export function clearHighlight(doc) {
    const win = doc && doc.defaultView;
    try {
        if (win && win.CSS && win.CSS.highlights) win.CSS.highlights.delete(NAME);
    } catch (e) { /* ignore */ }
}

/** First occurrence of phrase (case-insensitive) in text nodes under root that are not inside a link. */
export function findTextRange(root, phrase) {
    if (!root || !phrase) return null;
    const doc = root.ownerDocument || document;
    const needle = phrase.toLowerCase();
    const walker = doc.createTreeWalker(root, 4 /* SHOW_TEXT */);
    let node;
    while ((node = walker.nextNode())) {
        const parent = node.parentElement;
        if (parent && parent.closest('a')) continue;
        const i = node.data.toLowerCase().indexOf(needle);
        if (i >= 0) {
            const r = doc.createRange();
            r.setStart(node, i);
            r.setEnd(node, i + phrase.length);
            return r;
        }
    }
    return null;
}
