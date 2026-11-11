import { buildOpportunities, hiddenKey, normalizeUrl, relevance } from '../opportunities';

const sug = (phrase, block_index = 0) => ({ phrase, context: `x [${phrase}] y`, block_index });
const row = (id, suggestions, url = `http://s.test/p${id}/`) => ({ post_id: id, title: `T${id}`, url, suggestions });

describe('buildOpportunities', () => {
    it('gives each phrase to the best-ranked target only', () => {
        const rows = [row(1, [sug('alpha'), sug('beta')]), row(2, [sug('alpha'), sug('gamma')])];
        const out = buildOpportunities(rows);
        expect(out.map((o) => [o.suggestion.phrase, o.targets[0].post_id])).toEqual([['alpha', 1], ['gamma', 2]]);
    });

    it('lists other unlinked targets with the same phrase as alternatives', () => {
        const rows = [row(1, [sug('alpha')]), row(2, [sug('Alpha')]), row(3, [sug('other')])];
        const out = buildOpportunities(rows);
        expect(out[0].targets.map((t) => t.post_id)).toEqual([1, 2]);
    });

    it('skips targets already linked (absolute or relative url) and linked anchors', () => {
        const rows = [row(1, [sug('alpha')]), row(2, [sug('beta')]), row(3, [sug('gamma')])];
        const links = [{ url: '/p1', anchor: 'x' }, { url: 'http://elsewhere.test/', anchor: 'Gamma' }];
        const out = buildOpportunities(rows, { links });
        expect(out.map((o) => o.targets[0].post_id)).toEqual([2]);
    });

    it('excludes hidden suggestions and falls through to the next phrase', () => {
        const rows = [row(1, [sug('alpha'), sug('beta')])];
        const out = buildOpportunities(rows, { hidden: new Set([hiddenKey(1, 'alpha')]) });
        expect(out[0].suggestion.phrase).toBe('beta');
        expect(buildOpportunities(rows, { hidden: new Set([hiddenKey(1, 'alpha'), hiddenKey(1, 'beta')]) })).toEqual([]);
    });

    it('does not offer a linked target as an alternative', () => {
        const rows = [row(1, [sug('alpha')]), row(2, [sug('alpha')])];
        const out = buildOpportunities(rows, { links: [{ url: 'http://s.test/p2/', anchor: 'q' }] });
        expect(out[0].targets).toHaveLength(1);
    });
});

describe('normalizeUrl', () => {
    it('treats absolute and relative same-page urls as equal', () => {
        expect(normalizeUrl('http://a.test/foo/')).toBe(normalizeUrl('/foo'));
    });
});

describe('relevance', () => {
    it('rescales the E5 band and clamps', () => {
        expect(relevance(0.82)).toBe(0);
        expect(relevance(0.885)).toBe(50);
        expect(relevance(0.99)).toBe(100);
        expect(relevance(0.5)).toBe(0);
        expect(relevance(undefined)).toBeNull();
        expect(relevance(0)).toBeNull();
    });
});
