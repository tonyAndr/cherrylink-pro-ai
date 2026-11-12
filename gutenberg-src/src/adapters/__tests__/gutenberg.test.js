import { createGutenbergAdapter } from '../gutenberg';
import { getActiveRichText } from '../gutenberg/cherryRichText';

const LINK = { url: 'http://s.test/t/', title: 'T' };

let blocks;
let updateBlockAttributes;
let selectedId;

// Tiny rich-text stand-in: plain text plus a list of link ranges.
const strip = (h) => String(h).replace(/<[^>]*>/g, '');
const fakeRichText = {
    registerFormatType: jest.fn(),
    create: ({ html }) => {
        const formats = [];
        let text = '';
        String(html).split(/(<a [^>]*>.*?<\/a>)/).forEach((part) => {
            const m = /<a href="([^"]*)"[^>]*>(.*?)<\/a>/.exec(part);
            const t = m ? m[2] : part;
            const f = m ? [{ type: 'core/link', attributes: { url: m[1] } }] : undefined;
            for (let i = 0; i < t.length; i++) formats[text.length + i] = f;
            text += t;
        });
        return { text, formats, start: 0, end: 0 };
    },
    applyFormat: (value, format, s, e) => {
        const formats = value.formats.slice();
        for (let i = s; i < e; i++) formats[i] = [format];
        return { ...value, formats };
    },
    toHTMLString: ({ value }) => {
        let out = '';
        let i = 0;
        while (i < value.text.length) {
            const f = value.formats[i] && value.formats[i][0];
            if (f) {
                let j = i;
                while (value.formats[j] && value.formats[j][0] === f) j++;
                out += `<a href="${f.attributes.url}">${value.text.slice(i, j)}</a>`;
                i = j;
            } else { out += value.text[i]; i++; }
        }
        return out;
    },
};

beforeEach(() => {
    blocks = [
        { clientId: 'b1', name: 'core/paragraph', attributes: { content: 'short' }, innerBlocks: [] },
        { clientId: 'b2', name: 'core/paragraph', attributes: { content: 'The quick brown fox jumps' }, innerBlocks: [] },
        { clientId: 'b3', name: 'core/paragraph', attributes: { content: 'Again <a href="/old">quick brown</a> and quick brown' }, innerBlocks: [] },
    ];
    updateBlockAttributes = jest.fn((id, attrs) => {
        blocks = blocks.map((b) => (b.clientId === id ? { ...b, attributes: { ...b.attributes, ...attrs } } : b));
    });
    selectedId = 'b2';
    global.wp.richText = { ...global.wp.richText, ...fakeRichText };
    global.wp.data = {
        select: jest.fn((store) => (store === 'core/editor'
            ? { getEditedPostContent: () => blocks.map((b) => `<p>${b.attributes.content}</p>`).join('') }
            : {
                getBlocks: () => blocks,
                getBlock: (id) => blocks.find((b) => b.clientId === id),
                getSelectedBlockClientId: () => selectedId,
            })),
        dispatch: jest.fn(() => ({ updateBlockAttributes, selectBlock: jest.fn() })),
        subscribe: jest.fn((fn) => { global.__notify = fn; return jest.fn(); }),
    };
});

describe('gutenberg adapter', () => {
    it('applies a phrase to the requested block, skipping text already linked', () => {
        const a = createGutenbergAdapter();
        const res = a.applyLinkToPhrase('quick brown', 0, LINK);
        expect(res.ok).toBe(true);
        expect(updateBlockAttributes).toHaveBeenCalledWith('b2', { content: 'The <a href="http://s.test/t/">quick brown</a> fox jumps' });
        // only an already-linked occurrence plus a free one remain in b3
        const r2 = a.applyLinkToPhrase('quick brown', 1, LINK);
        expect(r2.ok).toBe(true);
        expect(blocks[2].attributes.content).toBe('Again <a href="/old">quick brown</a> and <a href="http://s.test/t/">quick brown</a>');
    });

    it('undoes a phrase link and reports not_found', () => {
        const a = createGutenbergAdapter();
        const res = a.applyLinkToPhrase('quick brown', 0, LINK);
        a.removeLink(res.ref);
        expect(blocks[1].attributes.content).toBe('The quick brown fox jumps');
        expect(a.applyLinkToPhrase('zzz', 0, LINK)).toEqual({ ok: false, reason: 'not_found' });
    });

    it('lists links across blocks and returns html/text', () => {
        const a = createGutenbergAdapter();
        expect(a.listLinks()).toEqual([expect.objectContaining({ url: '/old', anchor: 'quick brown' })]);
        expect(a.getText()).toContain('The quick brown fox jumps');
    });

    it('selection: no_selection when nothing is selected', () => {
        const a = createGutenbergAdapter();
        expect(a.applyLinkToSelection(LINK).reason).toBe('no_selection');
    });

    it('selection: links the active rich-text range and undoes it', () => {
        const a = createGutenbergAdapter();
        const rt = getActiveRichText();
        const original = fakeRichText.create({ html: 'The quick brown fox jumps' });
        rt.value = { ...original, start: 4, end: 9 };
        rt.onChange = (next) => updateBlockAttributes('b2', { content: fakeRichText.toHTMLString({ value: next }) });
        global.wp.richText.applyFormat = fakeRichText.applyFormat;
        const res = a.applyLinkToSelection(LINK);
        expect(res.ok).toBe(true);
        expect(blocks[1].attributes.content).toBe('The <a href="http://s.test/t/">quick</a> brown fox jumps');
        a.removeLink(res.ref);
        expect(blocks[1].attributes.content).toBe('The quick brown fox jumps');
        // selection already inside a link
        rt.value = { ...fakeRichText.create({ html: 'x <a href="/o">linked text</a>' }), start: 3, end: 8 };
        expect(a.applyLinkToSelection(LINK).reason).toBe('inside_link');
        rt.value = null;
    });

    it('debounces block changes', () => {
        jest.useFakeTimers();
        const a = createGutenbergAdapter();
        const cb = jest.fn();
        a.onChange(cb);
        blocks = blocks.slice();
        global.__notify();
        jest.advanceTimersByTime(1000);
        expect(cb).toHaveBeenCalledTimes(1);
        jest.useRealTimers();
    });

    describe('classic block (core/freeform)', () => {
        const P1 = 'First paragraph with some words.';
        const P2 = 'Second has <strong>quick brown</strong> fox inside.';
        const P3 = 'Third has <a href="/old">quick brown</a> and quick brown too.';

        beforeEach(() => {
            blocks = [{ clientId: 'c1', name: 'core/freeform', attributes: { content: `<p>${P1}</p>\n<p>short</p>\n<h2>${P2}</h2><p>${P3}</p>` }, innerBlocks: [] }];
            selectedId = 'c1';
        });

        it('links the phrase in the indexed paragraph and updates the attribute', () => {
            const a = createGutenbergAdapter();
            // counted elements with enough text: P1 (0), h2 (1), P3 (2); "short" is skipped
            const res = a.applyLinkToPhrase('some words', 0, LINK);
            expect(res.ok).toBe(true);
            expect(updateBlockAttributes).toHaveBeenCalledTimes(1);
            expect(blocks[0].attributes.content).toContain('<p>First paragraph with <a href="http://s.test/t/" title="T">some words</a>.</p>');
            expect(res.ref).toEqual(expect.objectContaining({ clientId: 'c1', attr: 'content', url: LINK.url, anchor: 'some words' }));
        });

        it('uses the block index to pick the element, and skips linked text', () => {
            const a = createGutenbergAdapter();
            const res = a.applyLinkToPhrase('quick brown', 2, LINK);
            expect(res.ok).toBe(true);
            expect(blocks[0].attributes.content).toContain('and <a href="http://s.test/t/" title="T">quick brown</a> too');
            expect(blocks[0].attributes.content).toContain('<a href="/old">quick brown</a>');
        });

        it('links a phrase spanning inline tags', () => {
            const a = createGutenbergAdapter();
            const res = a.applyLinkToPhrase('brown fox', 1, LINK);
            expect(res.ok).toBe(true);
            const c = blocks[0].attributes.content;
            expect(c).toContain('<strong>quick </strong><a href="http://s.test/t/" title="T"><strong>brown</strong> fox</a>');
            expect(res.ref.anchor).toBe('brown fox');
        });

        it('does not link text that is already linked', () => {
            const a = createGutenbergAdapter();
            blocks[0].attributes.content = '<p>Only <a href="/old">linked words here</a> remain.</p>';
            expect(a.applyLinkToPhrase('linked words', 0, LINK)).toEqual({ ok: false, reason: 'not_found' });
        });

        it('treats raw blank-line separated text as paragraphs', () => {
            blocks[0].attributes.content = 'Plain first paragraph text.\n\nPlain second paragraph text.';
            const a = createGutenbergAdapter();
            expect(a.applyLinkToPhrase('second paragraph', 1, LINK).ok).toBe(true);
            expect(blocks[0].attributes.content).toBe('<p>Plain first paragraph text.</p><p>Plain <a href="http://s.test/t/" title="T">second paragraph</a> text.</p>');
        });

        it('lists freeform links and undoes an insert', () => {
            const a = createGutenbergAdapter();
            expect(a.listLinks()).toEqual([expect.objectContaining({ url: '/old', anchor: 'quick brown' })]);
            const before = blocks[0].attributes.content;
            const res = a.applyLinkToPhrase('some words', 0, LINK);
            expect(a.listLinks()).toHaveLength(2);
            a.removeLink(res.ref);
            expect(blocks[0].attributes.content).toBe(before);
        });

        it('undo unwraps the anchor when content changed meanwhile', () => {
            const a = createGutenbergAdapter();
            const res = a.applyLinkToPhrase('some words', 0, LINK);
            blocks[0].attributes.content += '<p>extra</p>';
            a.removeLink(res.ref);
            expect(blocks[0].attributes.content).toContain('<p>First paragraph with some words.</p>');
        });

        it('selection insert reports unsupported inside a Classic block', () => {
            expect(createGutenbergAdapter().applyLinkToSelection(LINK)).toEqual({ ok: false, reason: 'unsupported' });
        });
    });
});
