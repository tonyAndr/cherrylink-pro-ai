/** Normalise a URL to path+query so absolute and relative links to the same page compare equal. */
export function normalizeUrl(u) {
    if (!u) return '';
    try {
        const x = new URL(u, 'http://cl.invalid');
        return x.pathname.replace(/\/+$/, '') + x.search;
    } catch (e) {
        return String(u).replace(/\/+$/, '');
    }
}

export const hiddenKey = (postId, phrase) => `${postId}|${phrase}`;

/**
 * One phrase -> one target. Targets are visited in rank order and each takes its first suggestion
 * whose phrase is not already used, not already a link anchor, and not hidden. A target already
 * linked from the text gets no card. Alternatives = other unlinked targets offering the same phrase.
 *
 * @param {Array} rows   server rows { post_id, url, view_url?, suggestions:[{phrase,context,block_index}] }
 * @param {{links?: Array<{url,anchor}>, hidden?: Set<string>}} state
 * @returns {Array<{ suggestion, targets: Row[] }>}
 */
export function buildOpportunities(rows, { links = [], hidden = new Set() } = {}) {
    const linkedUrls = new Set(links.map((l) => normalizeUrl(l.url)));
    const linkedTexts = new Set(links.map((l) => String(l.anchor || '').toLowerCase()));
    const isLinked = (r) => linkedUrls.has(normalizeUrl(r.url)) || (r.view_url && linkedUrls.has(normalizeUrl(r.view_url)));
    const used = new Set();
    const out = [];
    for (const t of rows) {
        if (isLinked(t)) continue;
        const free = (t.suggestions || []).filter((s) => {
            const p = s.phrase.toLowerCase();
            return !used.has(p) && !linkedTexts.has(p) && !hidden.has(hiddenKey(t.post_id, s.phrase));
        });
        if (!free.length) continue;
        const s = free[0];
        const p = s.phrase.toLowerCase();
        used.add(p);
        const alts = rows.filter((o) => o !== t && !isLinked(o) && (o.suggestions || []).some((x) => x.phrase.toLowerCase() === p));
        out.push({ suggestion: s, targets: [t, ...alts] });
    }
    return out;
}

export const isRowLinked = (row, links) => {
    const set = new Set(links.map((l) => normalizeUrl(l.url)));
    return set.has(normalizeUrl(row.url)) || (!!row.view_url && set.has(normalizeUrl(row.view_url)));
};

// ponytail: E5 cosine sits in ~0.82 (unrelated) .. ~0.95 (near-duplicate) on real sites, so raw % would
// read "83%" for noise. Linear rescale of that band; recalibrate if the embedding model changes.
const REL_LOW = 0.82;
const REL_HIGH = 0.95;
const calibrate = (s) => 100 * Math.min(1, Math.max(0, (s - REL_LOW) / (REL_HIGH - REL_LOW)));
/**
 * Vector score -> 0..100 relevance for display; null when the API sent no score.
 * `top` = best raw score in the same list: every value is lifted by half the best match's gap to 100,
 * so the best match reads high and the rest keep their distance from it.
 */
export function relevance(score, top = score) {
    if (typeof score !== 'number' || !(score > 0)) return null;
    const lift = (100 - calibrate(Math.max(score, top || 0))) / 2;
    return Math.round(Math.min(100, calibrate(score) + lift));
}

/** Best raw score among items (for relevance()'s `top`). */
export const topScore = (items) => Math.max(0, ...items.map((x) => (typeof x?.score === 'number' ? x.score : 0)));
