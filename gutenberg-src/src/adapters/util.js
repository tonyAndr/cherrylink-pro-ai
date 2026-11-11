export function debounce(fn, ms) {
    let t;
    const d = (...args) => {
        clearTimeout(t);
        t = setTimeout(() => fn(...args), ms);
    };
    d.cancel = () => clearTimeout(t);
    return d;
}

export const CHANGE_DEBOUNCE_MS = 1000;
export const MIN_BLOCK_LEN = 10;
export const BLOCK_SELECTOR = 'p,h1,h2,h3,h4,h5,h6,li,blockquote,td';

const ENTITIES = { '&nbsp;': ' ', '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#039;': "'", '&#39;': "'" };

export function stripTags(html) {
    return String(html || '')
        .replace(/<[^>]*>/g, '')
        .replace(/&(nbsp|amp|lt|gt|quot|#0?39);/g, (m) => ENTITIES[m] || m);
}

export function escapeHtml(s) {
    return String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
}

/** Split leading/trailing whitespace off a selection so it stays outside the link. */
export function splitSpaces(s) {
    const m = /^(\s*)([\s\S]*?)(\s*)$/.exec(s);
    return { first: m[1], core: m[2], last: m[3] };
}

/** Build open+inner+close, keeping edge whitespace outside the link. */
export function wrapWithLink(open, inner, close) {
    const { first, core, last } = splitSpaces(inner);
    return first + open + core + close + last;
}

/** `<a href=...>` fallback when the server sent no templates. */
export function linkTags(link, marker) {
    let open = link.open;
    let close = link.close;
    if (!open || !close) {
        open = `<a href="${escapeHtml(link.url)}"${link.title ? ` title="${escapeHtml(link.title)}"` : ''}>`;
        close = '</a>';
    }
    if (marker) open = open.replace(/<a\b/i, (m) => `${m} data-cl-ref="${marker}"`);
    return { open, close };
}

let markerSeq = 0;
export const nextMarker = () => `m${Date.now().toString(36)}${markerSeq++}`;

export function unwrapElement(a) {
    const p = a.parentNode;
    if (!p) return;
    while (a.firstChild) p.insertBefore(a.firstChild, a);
    p.removeChild(a);
    p.normalize();
}

/** Reasons to show for a failed adapter call are mapped in the panel; adapters only return codes. */
export const fail = (reason) => ({ ok: false, reason });
