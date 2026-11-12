import { select, dispatch, subscribe } from '@wordpress/data';
import { applyLinkAtSelection, getActiveRichText } from './gutenberg/cherryRichText';
import { scrollToBlockInEditor } from './gutenberg/scrollToEditor';
import { flattenBlocks } from './gutenberg/blockUtils';
import { debounce, CHANGE_DEBOUNCE_MS, MIN_BLOCK_LEN, BLOCK_SELECTOR, stripTags, fail } from './util';
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

/**
 * Classic block (core/freeform) content as a detached DOM plus its counted elements
 * (p/h1-6/li/blockquote/td in document order, like the API extractor). Raw text without block
 * tags follows wpautop: blank-line separated chunks become <p> (re-saved wrapped on edit).
 */
function parseFreeform(html) {
    const root = document.createElement('div');
    root.innerHTML = String(html || '');
    if (!root.querySelector(BLOCK_SELECTOR) && root.textContent.trim()) {
        root.innerHTML = String(html).split(/\n\s*\n/).filter((c) => c.trim()).map((c) => `<p>${c.trim()}</p>`).join('');
    }
    return { root, els: [...root.querySelectorAll(BLOCK_SELECTOR)] };
}

/** Wrap the first free (outside <a>) occurrence of phrase in el, even across inline tags. Returns matched text or null. */
function wrapPhraseInElement(el, phrase, link) {
    const doc = el.ownerDocument;
    const nodes = [];
    const walker = doc.createTreeWalker(el, 4 /* SHOW_TEXT */);
    let text = '';
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
        nodes.push({ node: n, start: text.length, linked: !!(n.parentElement && n.parentElement.closest('a')) });
        text += n.data;
    }
    const hay = text.toLowerCase();
    const needle = phrase.toLowerCase();
    let from = 0;
    for (;;) {
        const i = hay.indexOf(needle, from);
        if (i < 0) return null;
        const j = i + needle.length;
        const bad = nodes.some((x) => x.linked && x.start < j && x.start + x.node.data.length > i);
        if (!bad) {
            const at = (pos, end) => nodes.find((x) => (end ? pos > x.start && pos <= x.start + x.node.data.length : pos >= x.start && pos < x.start + x.node.data.length));
            const a = at(i, false);
            const b = at(j, true);
            const range = doc.createRange();
            range.setStart(a.node, i - a.start);
            range.setEnd(b.node, j - b.start);
            const anchor = doc.createElement('a');
            anchor.setAttribute('href', link.url);
            if (link.title) anchor.setAttribute('title', link.title);
            const matched = range.toString();
            anchor.appendChild(range.extractContents());
            range.insertNode(anchor);
            return matched;
        }
        from = i + 1;
    }
}

const getBlocksTree = () => select('core/block-editor').getBlocks();

/**
 * Content blocks counted like the API extractor counts p/h/li/blockquote/td:
 * [{ clientId, attr|null, html, text }] (attr null = counted but not editable here).
 */
function contentBlocks() {
    const out = [];
    const push = (clientId, attr, html, extra) => {
        const text = stripTags(asHtml(html)).trim();
        if (text.length > MIN_BLOCK_LEN) out.push({ clientId, attr, html: asHtml(html), text, ...extra });
    };
    const innerText = (b) => flattenBlocks(b.innerBlocks || []).map((x) => (TEXT_ATTR[x.name] ? stripTags(asHtml(x.attributes[TEXT_ATTR[x.name]])) : '')).join(' ');
    for (const b of flattenBlocks(getBlocksTree())) {
        if (TEXT_ATTR[b.name]) {
            push(b.clientId, TEXT_ATTR[b.name], b.attributes[TEXT_ATTR[b.name]]);
        } else if (b.name === 'core/freeform') {
            parseFreeform(b.attributes.content).els.forEach((el, index) => push(b.clientId, 'content', el.innerHTML, { freeform: { index } }));
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
    let revealed = null;
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
            const sel = select('core/block-editor');
            const selId = sel.getSelectedBlockClientId && sel.getSelectedBlockClientId();
            const selBlock = selId && sel.getBlock(selId);
            // Classic block text lives in TinyMCE, not RichText: say so instead of acting on a stale range.
            if (selBlock && selBlock.name === 'core/freeform') return fail('unsupported');
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
                if (b.freeform) {
                    const raw = select('core/block-editor').getBlock(b.clientId);
                    const before = raw ? raw.attributes.content : '';
                    const { root, els } = parseFreeform(before);
                    const target = els[b.freeform.index];
                    const anchor = target && wrapPhraseInElement(target, phrase, link);
                    if (!anchor) continue;
                    const newHtml = root.innerHTML;
                    update(b.clientId, { content: newHtml });
                    return { ok: true, ref: { clientId: b.clientId, attr: 'content', beforeRaw: before, afterSer: newHtml, url: link.url, anchor } };
                }
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
                const attr = TEXT_ATTR[b.name] || (b.name === 'core/freeform' ? 'content' : null) || (b.name === 'core/quote' && !(b.innerBlocks || []).length ? 'value' : null);
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
                if (!el) continue;
                let hdoc = doc;
                let range = findTextRange(el, arg);
                if (!range && b.freeform) {
                    // Classic block: text sits in a TinyMCE iframe inside the block.
                    for (const f of el.querySelectorAll('iframe')) {
                        const d = f.contentDocument;
                        range = d && d.body && findTextRange(d.body, arg);
                        if (range) { hdoc = d; break; }
                    }
                }
                if (range) {
                    revealed = hdoc;
                    highlightRange(range, hdoc);
                    if (el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    return;
                }
            }
        },
        clearReveal() {
            clearHighlight(editorDoc());
            if (revealed) clearHighlight(revealed);
            revealed = null;
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
