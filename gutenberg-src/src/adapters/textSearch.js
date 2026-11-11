import { MIN_BLOCK_LEN, stripTags } from './util';

const PROTECTED = /<a\b[^>]*>[\s\S]*?<\/a\s*>|<!--[\s\S]*?-->|<[^>]*>/gi;

/** First case-insensitive occurrence of phrase in [from,to) outside tags, comments and links. */
export function findPhrase(str, phrase, from = 0, to = str.length) {
    if (!phrase) return null;
    const needle = phrase.toLowerCase();
    const hay = str.toLowerCase();
    let cursor = from;
    const gaps = [];
    PROTECTED.lastIndex = 0;
    let m;
    while ((m = PROTECTED.exec(str))) {
        if (m.index > cursor) gaps.push([cursor, m.index]);
        cursor = m.index + m[0].length;
    }
    if (cursor < str.length) gaps.push([cursor, str.length]);
    for (const [s, e] of gaps) {
        const a = Math.max(s, from);
        const b = Math.min(e, to);
        if (b <= a) continue;
        const i = hay.slice(a, b).indexOf(needle);
        if (i >= 0) return { start: a + i, end: a + i + phrase.length };
    }
    return null;
}

/** Block segments (inner ranges) of p/h1-6/li/blockquote/td with text longer than MIN_BLOCK_LEN, in document order. */
export function blockSegments(html) {
    const re = /<(\/?)(p|h[1-6]|li|blockquote|td)\b[^>]*>/gi;
    const stacks = {};
    const segs = [];
    let m;
    while ((m = re.exec(html))) {
        const tag = m[2].toLowerCase();
        if (!m[1]) {
            (stacks[tag] = stacks[tag] || []).push({ order: m.index, start: m.index + m[0].length });
        } else if (stacks[tag] && stacks[tag].length) {
            const o = stacks[tag].pop();
            segs.push({ order: o.order, start: o.start, end: m.index });
        }
    }
    let out = segs.sort((a, b) => a.order - b.order);
    if (!out.length) {
        // Classic text without block tags: paragraphs separated by blank lines.
        out = [];
        const sep = /\n\s*\n/g;
        let last = 0;
        while ((m = sep.exec(html))) {
            out.push({ start: last, end: m.index });
            last = m.index + m[0].length;
        }
        out.push({ start: last, end: html.length });
    }
    return out.filter((s) => stripTags(html.slice(s.start, s.end)).trim().length > MIN_BLOCK_LEN);
}

/** Locate phrase: block `blockIndex` first, then anywhere. */
export function locatePhrase(html, phrase, blockIndex) {
    const segs = blockSegments(html);
    const seg = segs[blockIndex];
    if (seg) {
        const hit = findPhrase(html, phrase, seg.start, seg.end);
        if (hit) return hit;
    }
    return findPhrase(html, phrase);
}

/** All links `<a href>` in html: [{url, anchor}] */
export function linksInHtml(html) {
    const out = [];
    const re = /<a\b([^>]*)>([\s\S]*?)<\/a\s*>/gi;
    let m;
    while ((m = re.exec(html))) {
        const h = /href\s*=\s*("([^"]*)"|'([^']*)')/i.exec(m[1]);
        if (h) out.push({ url: h[2] !== undefined ? h[2] : h[3], anchor: stripTags(m[2]), index: m.index });
    }
    return out;
}
