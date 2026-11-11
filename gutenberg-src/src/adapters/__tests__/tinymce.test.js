import { createTinymceAdapter } from '../tinymce';

const LINK = { url: 'http://s.test/t/', title: 'T', open: '<a href="http://s.test/t/">', close: '</a>' };

/** Minimal fake TinyMCE editor over a jsdom body. */
function fakeEditor(html) {
    const body = document.createElement('div');
    body.innerHTML = html;
    document.body.appendChild(body);
    let rng = document.createRange();
    rng.setStart(body, 0);
    rng.collapse(true);
    const handlers = {};
    const insert = (h) => {
        rng.deleteContents();
        const t = document.createElement('template');
        t.innerHTML = h;
        rng.insertNode(t.content);
    };
    const ed = {
        body,
        transacts: 0,
        getBody: () => body,
        getDoc: () => document,
        getContent: () => body.innerHTML,
        isHidden: () => false,
        selection: {
            getRng: () => rng,
            setRng: (r) => { rng = r; },
            getContent: () => { const d = document.createElement('div'); d.appendChild(rng.cloneContents()); return d.innerHTML; },
            setContent: insert,
            select: jest.fn(),
        },
        insertContent: insert,
        undoManager: { transact: (fn) => { ed.transacts++; fn(); } },
        on: (ev, cb) => { handlers[ev] = cb; },
        off: jest.fn(),
        handlers,
    };
    return ed;
}

function select(ed, node, s, e) {
    const r = document.createRange();
    r.setStart(node, s);
    r.setEnd(node, e);
    ed.selection.setRng(r);
}

afterEach(() => { document.body.innerHTML = ''; });

describe('tinymce adapter', () => {
    it('links the selection inside one undo transaction and removes the marker attribute', () => {
        const ed = fakeEditor('<p>say hello world now</p>');
        const a = createTinymceAdapter(() => ed);
        select(ed, ed.body.firstChild.firstChild, 4, 15);
        expect(a.getSelection()).toEqual({ text: 'hello world', collapsed: false });
        const res = a.applyLinkToSelection(LINK);
        expect(res.ok).toBe(true);
        expect(ed.transacts).toBe(1);
        expect(ed.body.innerHTML).toBe('<p>say <a href="http://s.test/t/">hello world</a> now</p>');
        a.removeLink(res.ref);
        expect(ed.body.textContent).toBe('say hello world now');
        expect(ed.body.querySelector('a')).toBeNull();
    });

    it('rejects collapsed selection and selection inside a link', () => {
        const ed = fakeEditor('<p>text <a href="/x">linked words here</a> tail</p>');
        const a = createTinymceAdapter(() => ed);
        expect(a.applyLinkToSelection(LINK).reason).toBe('no_selection');
        select(ed, ed.body.querySelector('a').firstChild, 0, 6);
        expect(a.applyLinkToSelection(LINK).reason).toBe('inside_link');
    });

    it('applies a phrase in block N then anywhere, skipping existing links', () => {
        const ed = fakeEditor('<p>tiny</p><p>First block with fox text</p><p>Second block with <a href="/f">fox</a> and fox again</p>');
        const a = createTinymceAdapter(() => ed);
        const r = a.applyLinkToPhrase('fox', 1, LINK); // block index 1 == second qualifying block
        expect(r.ok).toBe(true);
        const ps = ed.body.querySelectorAll('p');
        expect(ps[2].querySelectorAll('a')).toHaveLength(2);
        expect(ps[2].innerHTML).toContain('<a href="http://s.test/t/">fox</a> again');
        expect(a.applyLinkToPhrase('missing', 0, LINK)).toEqual({ ok: false, reason: 'not_found' });
        a.removeLink(r.ref);
        expect(ps[2].querySelectorAll('a')).toHaveLength(1);
    });

    it('lists links and debounces change events', () => {
        jest.useFakeTimers();
        const ed = fakeEditor('<p>a <a href="/x">xx</a></p>');
        const a = createTinymceAdapter(() => ed);
        expect(a.listLinks()[0]).toEqual(expect.objectContaining({ url: '/x', anchor: 'xx' }));
        const cb = jest.fn();
        const off = a.onChange(cb);
        const h = Object.values(ed.handlers)[0];
        h(); h();
        jest.advanceTimersByTime(1000);
        expect(cb).toHaveBeenCalledTimes(1);
        off();
        expect(ed.off).toHaveBeenCalled();
        jest.useRealTimers();
    });

    it('returns unsupported without an editor', () => {
        const a = createTinymceAdapter(() => null);
        expect(a.applyLinkToPhrase('x', 0, LINK).ok).toBe(false);
        expect(a.listLinks()).toEqual([]);
    });
});
