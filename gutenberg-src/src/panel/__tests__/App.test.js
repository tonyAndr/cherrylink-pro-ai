import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import App from '../App';

const row = (id, phrase) => ({
    post_id: id, title: `Target ${id}`, url: `http://s.test/p${id}/`, view_url: `http://s.test/p${id}/`, edit_url: `http://s.test/wp-admin/post.php?post=${id}`,
    out: 1, in: 2, saturated: false, open: '<a>', close: '</a>',
    suggestions: [{ phrase, context: `some [${phrase}] here`, block_index: 0 }],
});

const config = { ajaxUrl: '/ajax', nonce: 'n1', postId: 7, isTerm: false, host: 'classic', licensed: true, options: { showCatFilter: false, noSelectionAction: 'placeholder', limit: 10 } };

function makeAdapter() {
    let links = [];
    return {
        getHtml: () => '<p>live</p>',
        getText: () => 'live',
        getSelection: () => ({ text: '', collapsed: true }),
        applyLinkToPhrase: jest.fn((phrase, block, link) => { links = [{ url: link.url, anchor: phrase, ref: 'r' }]; return { ok: true, ref: 'r' }; }),
        applyLinkToSelection: jest.fn(() => ({ ok: false, reason: 'no_selection' })),
        removeLink: jest.fn(() => { links = []; }),
        listLinks: () => links,
        reveal: jest.fn(),
        clearReveal: jest.fn(),
        onChange: () => () => {},
    };
}

let calls;
beforeEach(() => {
    calls = [];
    global.fetch = jest.fn(async (url, init) => {
        const action = /action=([^&]+)/.exec(url)[1];
        calls.push({ action, body: JSON.parse(init.body), url });
        const data = {
            cherrylink_panel_links: { ok: true, items: [row(1, 'alpha'), row(2, 'beta')], has_more: false },
            cherrylink_panel_incoming: { ok: true, items: [] },
            cherrylink_panel_donors: { ok: true, items: [{ donor_id: 9, title: 'Donor', edit_url: 'http://s.test/e', in: 0, can_edit: true, suggestion: { phrase: 'p', context: 'a [p] b', block_index: 0 } }] },
            cherrylink_panel_terms: { ok: true, items: [] },
        }[action];
        return { ok: true, status: 200, json: async () => data };
    });
});

describe('App', () => {
    it('loads suggestions, inserts a phrase and updates counts without refetching links', async () => {
        const adapter = makeAdapter();
        render(<App adapter={adapter} config={config} />);
        await screen.findByText('Target 1');
        expect(calls.find((c) => c.action === 'cherrylink_panel_links').body).toMatchObject({ post_id: 7, is_term: false, source_html: '<p>live</p>', offset: 0 });
        expect(calls[0].url).toContain('_ajax_nonce=n1');
        // donors chip appears: no incoming but a donor can link
        expect(screen.getByText(/could link here/)).toBeInTheDocument();

        const before = calls.length;
        fireEvent.click(screen.getAllByText('Insert link')[0]);
        expect(adapter.applyLinkToPhrase).toHaveBeenCalledWith('alpha', 0, expect.objectContaining({ url: 'http://s.test/p1/' }));
        await screen.findByText(/Added/);
        expect(screen.queryByText('Target 1')).toBeNull(); // linked target leaves the list
        expect(calls.length).toBe(before);

        fireEvent.click(screen.getAllByText('Undo')[0]);
        expect(adapter.removeLink).toHaveBeenCalledWith('r');
        await screen.findByText('Target 1');
    });

    it('hides a suggestion and restores it', async () => {
        render(<App adapter={makeAdapter()} config={config} />);
        await screen.findByText('Target 1');
        fireEvent.click(screen.getAllByText('Hide')[0]);
        expect(screen.queryByText('Target 1')).toBeNull();
        fireEvent.click(screen.getByText(/Hidden suggestions/));
        fireEvent.click(screen.getByText('Restore'));
        expect(screen.getByText('Target 1')).toBeInTheDocument();
    });

    it('shows an error with a retry button', async () => {
        global.fetch = jest.fn(async () => ({ ok: true, status: 200, json: async () => ({ ok: false, error: 'api_down', message: 'API unavailable' }) }));
        render(<App adapter={makeAdapter()} config={config} />);
        await screen.findByText('API unavailable');
        fireEvent.click(screen.getAllByText('Retry')[0]);
        await waitFor(() => expect(global.fetch.mock.calls.length).toBeGreaterThan(3));
    });

    it('treats network failure as an error', async () => {
        global.fetch = jest.fn(async () => { throw new Error('offline'); });
        render(<App adapter={makeAdapter()} config={config} />);
        await screen.findByText('No connection to the server');
    });

    it('debounces topic search by 400 ms and sends the query', async () => {
        render(<App adapter={makeAdapter()} config={config} />);
        await screen.findByText('Target 1');
        fireEvent.click(screen.getByText('All posts'));
        jest.useFakeTimers();
        fireEvent.change(screen.getByLabelText('Find related posts'), { target: { value: 'seo' } });
        expect(calls.filter((c) => c.body.query === 'seo')).toHaveLength(0);
        act(() => { jest.advanceTimersByTime(400); });
        jest.useRealTimers();
        await waitFor(() => expect(calls.filter((c) => c.body.query === 'seo')).toHaveLength(1));
    });

    it('selection insert without selection falls back to a toast on gutenberg', async () => {
        render(<App adapter={makeAdapter()} config={{ ...config, host: 'gutenberg' }} />);
        await screen.findByText('Target 1');
        fireEvent.click(screen.getByText('All posts'));
        fireEvent.click(screen.getAllByText('Insert')[0]);
        expect(await screen.findByText('Select text in the post to insert a link')).toBeInTheDocument();
    });

    it('donor insert sends the contract body and shows undo', async () => {
        global.fetch.mockImplementationOnce(global.fetch.getMockImplementation());
        render(<App adapter={makeAdapter()} config={config} />);
        await screen.findByText('Target 1');
        fireEvent.click(screen.getByText('Incoming links', { selector: 'button[role="tab"]' }));
        const prev = global.fetch.getMockImplementation();
        global.fetch.mockImplementation(async (url, init) => {
            if (/donor_insert/.test(url)) { calls.push({ action: 'donor_insert', body: JSON.parse(init.body) }); return { ok: true, status: 200, json: async () => ({ ok: true, undo_token: 'tok' }) }; }
            return prev(url, init);
        });
        fireEvent.click(screen.getByText('Insert link'));
        await screen.findByText(/Link added$/);
        expect(calls.find((c) => c.action === 'donor_insert').body).toEqual({ donor_id: 9, target_id: 7, phrase: 'p', block_index: 0 });
    });

    it('shows a single Connect button without fetching when not connected', () => {
        render(<App adapter={makeAdapter()} config={{ ...config, licensed: false, connectUrl: '/wp-admin/options-general.php?page=cherrylink-pro' }} />);
        expect(screen.getByText(/CherryLink is not connected yet/)).toBeInTheDocument();
        const link = screen.getByText('Connect CherryLink');
        expect(link.closest('a')).toHaveAttribute('href', '/wp-admin/options-general.php?page=cherrylink-pro');
        expect(screen.getAllByRole('link')).toHaveLength(1);
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('shows the plan line in the strip only when the limit cuts the index', async () => {
        const plan = { name: 'free', post_limit: 1000, indexed: 1000, total: 2340 };
        const { unmount } = render(<App adapter={makeAdapter()} config={{ ...config, plan, upgradeUrl: 'https://example.test/pricing' }} />);
        await screen.findByText('Target 1');
        expect(screen.getByText(/Indexed 1\s000 of 2\s340/)).toBeInTheDocument();
        expect(screen.getByText('Upgrade')).toHaveAttribute('href', 'https://example.test/pricing');
        unmount();

        render(<App adapter={makeAdapter()} config={{ ...config, plan: { ...plan, total: 800 } }} />);
        await screen.findByText('Target 1');
        expect(screen.queryByText(/Indexed/)).toBeNull();
    });

    it('always shows the Posts/Categories switch and loads terms lazily', async () => {
        render(<App adapter={makeAdapter()} config={config} />);
        await screen.findByText('Target 1');
        fireEvent.click(screen.getByText('All posts'));
        expect(screen.getByText('Categories')).toBeInTheDocument();
        expect(screen.queryByRole('combobox')).toBeNull(); // category filter stays gated
        expect(calls.some((c) => c.action === 'cherrylink_panel_terms')).toBe(false);
        fireEvent.click(screen.getByText('Categories'));
        await screen.findByText('No categories found');
        expect(calls.filter((c) => c.action === 'cherrylink_panel_terms')).toHaveLength(1);
    });

    it('renders its own logo row on classic but not on the gutenberg host', async () => {
        const { container, unmount } = render(<App adapter={makeAdapter()} config={config} />);
        await screen.findByText('Target 1');
        expect(container.querySelector('.cl-brand')).not.toBeNull();
        unmount();
        const g = render(<App adapter={makeAdapter()} config={{ ...config, host: 'gutenberg' }} />);
        await screen.findByText('Target 1');
        expect(g.container.querySelector('.cl-brand')).toBeNull();
        expect(g.container.querySelector('.cl-strip')).not.toBeNull();
    });
});
