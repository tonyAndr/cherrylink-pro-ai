import { createTextareaAdapter } from '../textarea';

const LINK = { url: 'http://s.test/t/', title: 'T', open: '<a href="http://s.test/t/">', close: '</a>' };

function make(value, s = 0, e = 0) {
    const el = document.createElement('textarea');
    el.value = value;
    el.setSelectionRange(s, e);
    return { el, a: createTextareaAdapter(() => el) };
}

describe('textarea adapter', () => {
    it('rejects empty and whitespace-only selections', () => {
        expect(make('hello world', 3, 3).a.applyLinkToSelection(LINK)).toEqual({ ok: false, reason: 'no_selection' });
        expect(make('a   b', 1, 4).a.applyLinkToSelection(LINK).reason).toBe('no_selection');
    });

    it('wraps the selection with the templates, keeping edge spaces outside, and undoes', () => {
        const { el, a } = make('say hello world now', 3, 15);
        const res = a.applyLinkToSelection(LINK);
        expect(res.ok).toBe(true);
        expect(el.value).toBe('say <a href="http://s.test/t/">hello world</a> now');
        a.removeLink(res.ref);
        expect(el.value).toBe('say hello world now');
    });

    it('refuses a selection inside an existing link', () => {
        const { a } = make('<p>go <a href="/x">some words</a> end</p>', 14, 18);
        expect(a.applyLinkToSelection(LINK).reason).toBe('inside_link');
    });

    it('falls back to a plain anchor when no templates are sent', () => {
        const { el, a } = make('one two three', 4, 7);
        a.applyLinkToSelection({ url: '/t', title: 'A"B' });
        expect(el.value).toBe('one <a href="/t" title="A&quot;B">two</a> three');
    });

    it('applies a phrase in the requested block, then anywhere, never inside links or tags', () => {
        const html = '<p>short</p>\n<p>The quick brown fox jumps</p>\n<p>Another fox <a href="/f">quick brown</a> here</p>';
        const { el, a } = make(html);
        const res = a.applyLinkToPhrase('quick brown', 0, LINK);
        expect(res.ok).toBe(true);
        expect(el.value).toContain('<p>The <a href="http://s.test/t/">quick brown</a> fox jumps</p>');
        // second call: only occurrence left is inside a link
        expect(a.applyLinkToPhrase('quick brown', 0, LINK)).toEqual({ ok: false, reason: 'not_found' });
        a.removeLink(res.ref);
        expect(el.value).toBe(html);
    });

    it('does not match inside tag attributes', () => {
        const { a } = make('<p class="quick">nothing to see here, move on</p>');
        expect(a.applyLinkToPhrase('quick', 0, LINK).ok).toBe(false);
    });

    it('lists links and fires a debounced onChange', () => {
        jest.useFakeTimers();
        const { el, a } = make('<p>a <a href="/x">xx</a></p>');
        expect(a.listLinks()).toEqual([expect.objectContaining({ url: '/x', anchor: 'xx' })]);
        const cb = jest.fn();
        const off = a.onChange(cb);
        el.dispatchEvent(new Event('input'));
        el.dispatchEvent(new Event('input'));
        expect(cb).not.toHaveBeenCalled();
        jest.advanceTimersByTime(1000);
        expect(cb).toHaveBeenCalledTimes(1);
        off();
        jest.useRealTimers();
    });

    it('inserts a placeholder link at the caret', () => {
        const { el, a } = make('ab', 1, 1);
        const r = a.insertAtCaret(LINK, 'TEXT');
        expect(el.value).toBe('a<a href="http://s.test/t/">TEXT</a>b');
        a.removeLink(r.ref);
        expect(el.value).toBe('ab');
    });
});
