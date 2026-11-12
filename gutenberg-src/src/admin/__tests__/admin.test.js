import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';
import App from '../App';
import { buildCsv } from '../csv';
import { rowsFor } from '../LinkHealth';
import { previewTag } from '../Settings';

const row = (id, o = {}) => ({ post_id: id, title: `Post ${id}`, type: 'post', type_label: 'Post', url: `http://s.test/p${id}/`, edit_url: `http://s.test/e${id}`,
    learned: true, orphan: false, no_outgoing: false, repeats: [], broken: [], self: [], ...o });
const health = {
    checked_at: '2026-11-12T09:14:00Z',
    summary: { orphans: 2, broken: 1, no_outgoing: 0, repeats: 0, self_links: 0, links_total: 50, posts_checked: 10 },
    rows: [row(1, { orphan: true }), row(2, { orphan: true, learned: false }), row(3, { broken: [{ target_url: 'http://s.test/gone/', anchor: 'a, "b"' }] })],
};
const mkState = (o = {}) => ({
    connected: true, has_key: true, setup_done: true,
    license: { plan: 'free', post_limit: 1000, key_masked: 'clp_••••4f2a', domain: 's.test', expires_at: null, status: 'active', error: '' },
    counts: { total: 10, types: [{ name: 'post', label: 'Posts', count: 8, enabled: true }, { name: 'page', label: 'Pages', count: 2, enabled: false }], learned: 8, checked: 10, links: 50 },
    sync: { status: 'idle', phase: null, upsert_total: 0, indexed: 0, links: 0 },
    route: 'direct',
    settings: { limit_ajax: 10, show_cat_filter: true, match_cat: false, excluded_authors: [], excluded_cats: [], relative_links: 'full', link_title_attr: true, link_new_tab: false, link_class: '', post_types: ['post'], vector_custom_fields: '' },
    settings_choices: { authors: [{ id: 1, name: 'Ann' }], categories: [{ id: 5, name: 'News', level: 0 }] },
    ...o,
});
const boot = (page, state = mkState()) => ({ page, ajaxUrl: '/ajax', nonce: 'N', connect: { postUrl: '/post', action: 'cl_connect', nonce: 'W', error: '' },
    urls: { dashboard: '/d', links: '/l', index: '/i', settings: '/s', exportSettings: '/x', editPost: '/e=', upgrade: '/up', pricing: '/pr', terms: '/t', privacy: '/p', docs: '/dc', limits: '/lim' }, state });

let calls, responses;
beforeEach(() => {
    calls = [];
    responses = {
        cherrylink_admin_link_health: health,
        linkate_sync_start: { status: 'running', phase: 'upsert', upsert_total: 10, indexed: 0, links: 0 },
        linkate_sync_tick: { ran: 0 },
        cherrylink_admin_save_settings: { success: true, data: { state: mkState({ setup_done: false }) } },
        cherrylink_admin_state: { success: true, data: mkState() },
        linkate_orphan_donors: { donors: [{ source_id: 9, source_title: 'Donor nine', anchor: 'hello', edit_url: '/edit9' }] },
    };
    global.fetch = jest.fn(async (url, init) => {
        const action = init.body.get('action');
        calls.push({ action, body: init.body });
        return { ok: true, json: async () => responses[action] || { success: true, data: {} } };
    });
});

describe('admin pages (connected free state)', () => {
    it('dashboard shows orphan count, learned orphans and plan strip', async () => {
        render(<App boot={boot('dashboard')} />);
        expect(await screen.findByText('Post 1')).toBeInTheDocument();
        expect(screen.queryByText('Post 2')).toBeNull(); // not learned
        expect(screen.getByText('pages nobody links to')).toBeInTheDocument();
        expect(screen.getByText('Upgrade')).toHaveAttribute('href', '/up');
        expect(screen.getByText('How the limit works')).toHaveAttribute('href', '/lim');
        expect(calls[0].body.get('_ajax_nonce')).toBe('N');
    });

    it('link health switches tabs, finds donors, and marks unlearned rows', async () => {
        render(<App boot={boot('links')} />);
        await screen.findByText('Post 1');
        expect(screen.getByText(/Not suggested in other posts: outside the free limit/)).toBeInTheDocument();
        expect(screen.getAllByText('Find donors').length).toBe(2); // unlearned orphans get donors too
        fireEvent.click(screen.getAllByText('Find donors')[0]);
        expect(await screen.findByText('Donor nine')).toBeInTheDocument();
        expect(calls.find((c) => c.action === 'linkate_orphan_donors').body.get('post_id')).toBe('1');
        fireEvent.click(screen.getByRole('tab', { name: /Broken links/ }));
        expect(screen.getByText('Post 3')).toBeInTheDocument();
        expect(screen.queryByText('Post 1')).toBeNull();
    });

    it('index renders tiles and confirms data removal in a modal', async () => {
        render(<App boot={boot('index')} />);
        expect(screen.getByText('learned for suggestions')).toBeInTheDocument();
        expect(screen.getByText('Why only 1,000?')).toBeInTheDocument();
        fireEvent.click(screen.getByText('Remove data', { selector: 'button' }));
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(calls.find((c) => c.action === 'cherrylink_admin_remove_data')).toBeUndefined();
    });

    it('settings renders sections, live preview and saves JSON settings', async () => {
        render(<App boot={boot('settings')} />);
        expect(screen.getByText('Key clp_••••4f2a · bound to s.test · never expires')).toBeInTheDocument();
        fireEvent.click(screen.getByLabelText(/Open inserted links in a new tab/));
        expect(screen.getByTestId('preview').textContent).toContain('target="_blank"');
        fireEvent.click(screen.getByText('Save changes'));
        await screen.findByText('Settings saved.');
        const saved = JSON.parse(calls.find((c) => c.action === 'cherrylink_admin_save_settings').body.get('settings'));
        expect(saved.link_new_tab).toBe(true);
    });

    it('non-dashboard pages ask to connect first when not connected', () => {
        render(<App boot={boot('links', mkState({ connected: false }))} />);
        expect(screen.getByText('Connect CherryLink first')).toBeInTheDocument();
    });
});

describe('wizard', () => {
    it('shows Connect with a real form post and the key field', () => {
        const { container } = render(<App boot={boot('dashboard', mkState({ connected: false, has_key: false, setup_done: false }))} />);
        const form = container.querySelector('form');
        expect(form.getAttribute('action')).toBe('/post');
        expect(form.querySelector('input[name=_wpnonce]').value).toBe('W');
        fireEvent.click(screen.getByText('I already have a key'));
        expect(screen.getByLabelText('License key')).toBeInTheDocument();
    });

    it('analyze: saves types, starts the scan, polls and stops when done', async () => {
        jest.useFakeTimers();
        try {
            responses.linkate_sync_status = [
                { status: 'running', phase: 'upsert', upsert_total: 10, indexed: 5, links: 0 },
                { status: 'done', phase: 'done', upsert_total: 10, indexed: 10, links: 10 },
            ];
            responses.cherrylink_admin_state = { success: true, data: mkState({ setup_done: false }) };
            let n = 0;
            const orig = global.fetch;
            global.fetch = jest.fn(async (u, i) => {
                if (i.body.get('action') === 'linkate_sync_status') {
                    calls.push({ action: 'linkate_sync_status' });
                    return { ok: true, json: async () => responses.linkate_sync_status[Math.min(n++, 1)] };
                }
                return orig(u, i);
            });
            render(<App boot={boot('dashboard', mkState({ setup_done: false }))} />);
            fireEvent.click(screen.getByText('Analyze site', { selector: 'button' }));
            await screen.findByText('Learning your posts');
            expect(JSON.parse(calls.find((c) => c.action === 'cherrylink_admin_save_settings').body.get('settings'))).toEqual({ post_types: ['post'] });
            await act(async () => { jest.advanceTimersByTime(3100); });
            await screen.findByText(/5 \/ 10/);
            await act(async () => { jest.advanceTimersByTime(3100); });
            await screen.findByText('✓ Analysis complete');
            const polls = calls.filter((c) => c.action === 'linkate_sync_status').length;
            await act(async () => { jest.advanceTimersByTime(20000); });
            expect(calls.filter((c) => c.action === 'linkate_sync_status').length).toBe(polls);
        } finally {
            jest.useRealTimers();
        }
    });
});

describe('helpers', () => {
    it('buildCsv escapes quotes and commas and includes tab details', () => {
        const csv = buildCsv('broken', [health.rows[2]]);
        expect(csv).toContain('"http://s.test/gone/ (a, ""b"")"');
        expect(csv.split('\r\n')[0]).toBe('﻿Title,Type,URL,Details');
    });
    it('rowsFor filters by tab, type and search', () => {
        expect(rowsFor('orphans', health.rows, '', '').length).toBe(2);
        expect(rowsFor('orphans', health.rows, '', 'post 2').length).toBe(1);
        expect(rowsFor('orphans', health.rows, 'page', '').length).toBe(0);
    });
    it('previewTag follows markup options', () => {
        expect(previewTag({ relative_links: 'no_domain', link_class: 'x' })).toBe('<a href="/bread-basics/" class="x">sourdough bread</a>');
    });
});
