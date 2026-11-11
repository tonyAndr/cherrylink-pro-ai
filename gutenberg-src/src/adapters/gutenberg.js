import { select, dispatch, subscribe } from '@wordpress/data';
import { applyLinkAtSelection, getActiveRichText } from './gutenberg/cherryRichText';
import { scrollToBlockInEditor } from './gutenberg/scrollToEditor';
import { flattenBlocks } from './gutenberg/blockUtils';
import { debounce, CHANGE_DEBOUNCE_MS, MIN_BLOCK_LEN, stripTags, fail } from './util';
import { highlightRange, clearHighlight, findTextRange } from './highlight';

const asHtml = (v) => (typeof v === 'string' ? v : v && v.toHTMLString ? v.toHTMLString() : String(v == null ? '' : v));
const ser = (v) => (typeof v === 'string' || v == null ? String(v == null ? '' : v) : v.toHTMLString ? v.toHTMLString() : JSON.stringify(v));

// Text attribute that a link can be applied to, per block type.
const TEXT_ATTR = {
    'core/paragraph': 'content',
    'core/heading': 'content',
    'core/list-item': 'content',
    'core/preformatted': 'content',
    'core/verse': 'content',
};

function editorDoc() {
    const f = document.querySelector('iframe[name="editor-canvas"], .editor-canvas__iframe');
    return (f && f.contentDocument) || document;
}

const getBlocksTree = () => select('core/block-editor').getBlocks();

/**
 * Content blocks counted like the API extractor counts p/h/li/blockquote/td:
 * [{ clientId, attr|null, html, text }] (attr null = counted but not editable here).
 */
function contentBlocks() {
    const out = [];
    const push = (clientId, attr, html) => {
        const text = stripTags(asHtml(html)).trim();
        if (text.length > MIN_BLOCK_LEN) out.push({ clientId, attr, html: asHtml(html), text });
    };
    const innerText = (b) => flattenBlocks(b.innerBlocks || []).map((x) => (TEXT_ATTR[x.name] ? stripTags(asHtml(x.attributes[TEXT_ATTR[x.name]])) : '')).join(' ');
    for (const b of flattenBlocks(getBlocksTree())) {
        if (TEXT_ATTR[b.name]) {
            push(b.clientId, TEXT_ATTR[b.name], b.attributes[TEXT_ATTR[b.name]]);
        } else if (b.name === 'core/quote') {
            if (b.innerBlocks && b.innerBlocks.length) push(b.clientId, null, innerText(b));
            else push(b.clientId, 'value', b.attributes.value);
        } else if (b.name === 'core/table') {
            ['head', 'body', 'foot'].forEach((sec) =>
                (b.attributes[sec] || []).forEach((row) => (row.cells || []).forEach((c) => push(b.clientId, null, c.content)))
            );
        }
    }
    return out;
}

function linksIn(html) {
    const d = document.createElement('div');
    d.innerHTML = html;
    return [...d.querySelectorAll('a[href]')].map((a) => ({ url: a.getAttribute('href'), anchor: a.textContent }));
}

function hasLinkFormat(value, from, to) {
    for (let i = from; i < to; i++) {
        if ((value.formats[i] || []).some((f) => f.type === 'core/link')) return true;
    }
    return false;
}

function findFreeOccurrence(value, phrase) {
    const text = (value.text || '').toLowerCase();
    const needle = phrase.toLowerCase();
    let from = 0;
    for (;;) {
        const i = text.indexOf(needle, from);
        if (i < 0) return -1;
        if (!hasLinkFormat(value, i, i + needle.length)) return i;
        from = i + 1;
    }
}

function removeLinkFromHtml(html, ref) {
    const d = document.createElement('div');
    d.innerHTML = html;
    const a = [...d.querySelectorAll('a[href]')].find((x) => x.getAttribute('href') === ref.url && (!ref.anchor || x.textContent === ref.anchor));
    if (!a) return null;
    while (a.firstChild) a.parentNode.insertBefore(a.firstChild, a);
    a.remove();
    return d.innerHTML;
}

/** EditorAdapter for the block editor. */
export function createGutenbergAdapter() {
    const update = (clientId, attrs) => dispatch('core/block-editor').updateBlockAttributes(clientId, attrs);

    return {
        getHtml() {
            const ed = select('core/editor');
            const html = ed && ed.getEditedPostContent ? ed.getEditedPostContent() : '';
            return typeof html === 'string' ? html : '';
        },
        getText() {
            return stripTags(this.getHtml());
        },
        getSelection() {
            const { value } = getActiveRichText();
            if (!value) return { text: '', collapsed: true };
            return { text: String(value.text || '').slice(value.start, value.end), collapsed: value.start === value.end };
        },
        applyLinkToSelection(link) {
            const rt = getActiveRichText();
            const v = rt.value;
            if (!v || !rt.onChange || v.start === v.end) return fail('no_selection');
            if (String(v.text || '').slice(v.start, v.end).trim() === '') return fail('no_selection');
            if (v.formats && hasLinkFormat(v, v.start, v.end)) return fail('inside_link');
            const be = select('core/block-editor');
            const clientId = be.getSelectedBlockClientId && be.getSelectedBlockClientId();
            const before = clientId && be.getBlock(clientId) ? { ...be.getBlock(clientId).attributes } : null;
            if (!applyLinkAtSelection(link.url, link.title)) return fail('no_selection');
            const after = clientId && be.getBlock(clientId) ? be.getBlock(clientId).attributes : null;
            let ref = { clientId: null, url: link.url, anchor: String(v.text).slice(v.start, v.end) };
            if (before && after) {
                const key = Object.keys(after).find((k) => ser(after[k]) !== ser(before[k]));
                if (key) ref = { ...ref, clientId, attr: key, beforeRaw: before[key], afterSer: ser(after[key]) };
            }
            return { ok: true, ref };
        },
        applyLinkToPhrase(phrase, blockIndex, link) {
            const list = contentBlocks();
            const order = [list[blockIndex], ...list].filter((b) => b && b.attr);
            for (const b of order) {
                const html = b.html;
                const value = wp.richText.create({ html });
                const i = findFreeOccurrence(value, phrase);
                if (i < 0) continue;
                const next = wp.richText.applyFormat(
                    value,
                    { type: 'core/link', attributes: { url: link.url, ...(link.title ? { title: link.title } : {}) } },
                    i,
                    i + phrase.length
                );
                const newHtml = wp.richText.toHTMLString({ value: next });
                const raw = select('core/block-editor').getBlock(b.clientId);
                update(b.clientId, { [b.attr]: newHtml });
                return {
                    ok: true,
                    ref: {
                        clientId: b.clientId,
                        attr: b.attr,
                        beforeRaw: raw ? raw.attributes[b.attr] : html,
                        afterSer: newHtml,
                        url: link.url,
                        anchor: String(value.text).slice(i, i + phrase.length),
                    },
                };
            }
            return fail(list.length ? 'not_found' : 'unsupported');
        },
        removeLink(ref) {
            if (!ref || !ref.clientId || !ref.attr) return;
            const block = select('core/block-editor').getBlock(ref.clientId);
            if (!block) return;
            const current = block.attributes[ref.attr];
            if (ser(current) === ref.afterSer) {
                update(ref.clientId, { [ref.attr]: ref.beforeRaw });
                return;
            }
            if (typeof current === 'string' || (current && current.toHTMLString)) {
                const cleaned = removeLinkFromHtml(asHtml(current), ref);
                if (cleaned !== null) update(ref.clientId, { [ref.attr]: cleaned });
            }
        },
        listLinks() {
            const out = [];
            for (const b of flattenBlocks(getBlocksTree())) {
                const attr = TEXT_ATTR[b.name] || (b.name === 'core/quote' && !(b.innerBlocks || []).length ? 'value' : null);
                if (!attr) continue;
                linksIn(asHtml(b.attributes[attr])).forEach((l) => out.push({ ...l, ref: { clientId: b.clientId, attr, url: l.url, anchor: l.anchor } }));
            }
            return out;
        },
        reveal(arg, blockIndex) {
            if (arg && typeof arg === 'object') {
                if (arg.clientId) scrollToBlockInEditor(arg.clientId, arg.anchor);
                return;
            }
            const list = contentBlocks();
            const doc = editorDoc();
            for (const b of [list[blockIndex], ...list].filter(Boolean)) {
                const el = doc.querySelector(`[data-block="${b.clientId}"]`);
                const range = el && findTextRange(el, arg);
                if (range) {
                    highlightRange(range, doc);
                    if (el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    return;
                }
            }
        },
        clearReveal() {
            clearHighlight(editorDoc());
        },
        onChange(cb) {
            const d = debounce(cb, CHANGE_DEBOUNCE_MS);
            let last = getBlocksTree();
            const off = subscribe(() => {
                const cur = getBlocksTree();
                if (cur !== last) {
                    last = cur;
                    d();
                }
            });
            return () => {
                d.cancel();
                if (typeof off === 'function') off();
            };
        },
    };
}
