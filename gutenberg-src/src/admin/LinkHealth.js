import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useAdmin, useLinkHealth } from './ctx';
import { buildCsv, detailsFor, downloadCsv } from './csv';
import { Btn, Card, Notice, Title, num } from './ui';

const PER_PAGE = 25;
export const TABS = [
    { id: 'orphans', label: __('Orphan pages', 'cherrylink'), count: 'orphans', test: (r) => r.orphan,
        help: __('No other post links to these pages. Find related posts that could link to them, then add the link from the editor panel.', 'cherrylink') },
    { id: 'broken', label: __('Broken links', 'cherrylink'), count: 'broken', test: (r) => r.broken.length > 0,
        help: __('Internal links in these posts point to pages that no longer exist.', 'cherrylink') },
    { id: 'no_outgoing', label: __('No outgoing', 'cherrylink'), count: 'no_outgoing', test: (r) => r.no_outgoing,
        help: __('These posts do not link to any other post.', 'cherrylink') },
    { id: 'repeats', label: __('Repeated links', 'cherrylink'), count: 'repeats', test: (r) => r.repeats.length > 0,
        help: __('These posts link to the same page more than once.', 'cherrylink') },
    { id: 'self', label: __('Links to itself', 'cherrylink'), count: 'self_links', test: (r) => r.self.length > 0,
        help: __('These posts contain a link to their own address.', 'cherrylink') },
];

export const rowsFor = (tab, rows, type, q) => {
    const t = TABS.find((x) => x.id === tab);
    const needle = q.trim().toLowerCase();
    return rows.filter((r) => t.test(r) && (!type || r.type === type) && (!needle || r.title.toLowerCase().includes(needle)));
};

function Donors({ list }) {
    if (list === 'loading') return <span className="cl-muted">{__('Looking for related posts…', 'cherrylink')}</span>;
    if (list === 'error') return <Notice tone="danger">{__('Could not load related posts. Try again.', 'cherrylink')}</Notice>;
    if (!list.length) return <span className="cl-muted">{__('No related posts found.', 'cherrylink')}</span>;
    return (
        <ul className="cl-donors">
            {list.map((d) => (
                <li key={d.source_id}>
                    <strong>{d.source_title}</strong>
                    {d.anchor && <span className="cl-muted"> {sprintf(
                        /* translators: %s: suggested link text inside the related post */
                        __('suggested link text: “%s”', 'cherrylink'), d.anchor)}</span>}{' '}
                    <a className="cl-link" href={d.edit_url}>{__('Edit', 'cherrylink')}</a>
                </li>
            ))}
        </ul>
    );
}

export default function LinkHealth() {
    const { api, state, urls } = useAdmin();
    const { data, busy, error, refresh } = useLinkHealth();
    const hash = (window.location.hash || '').replace('#', '');
    const [hTab, hPost] = hash.split('-');
    const [tab, setTab] = useState(TABS.some((t) => t.id === hTab) ? hTab : 'orphans');
    const [type, setType] = useState('');
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [donors, setDonors] = useState({});

    const rows = data ? rowsFor(tab, data.rows, type, q) : [];
    const pages = Math.max(1, Math.ceil(rows.length / PER_PAGE));
    const shown = rows.slice((page - 1) * PER_PAGE, page * PER_PAGE);
    const types = data ? [...new Map(data.rows.map((r) => [r.type, r.type_label || r.type])).entries()] : [];

    const find = async (id) => {
        setDonors((d) => ({ ...d, [id]: 'loading' }));
        try {
            const r = await api('linkate_orphan_donors', { post_id: id });
            setDonors((d) => ({ ...d, [id]: r.donors || [] }));
        } catch (e) {
            setDonors((d) => ({ ...d, [id]: 'error' }));
        }
    };

    // Deep link `#orphans-<id>`: jump to the page holding that row and open its donors once.
    useEffect(() => {
        if (!data || !hPost || hTab !== 'orphans') return;
        const i = rowsFor('orphans', data.rows, '', '').findIndex((r) => String(r.post_id) === hPost);
        if (i >= 0) { setPage(Math.floor(i / PER_PAGE) + 1); find(Number(hPost)); }
    }, [data]); // eslint-disable-line react-hooks/exhaustive-deps

    const cur = TABS.find((t) => t.id === tab);
    const limit = state.license.post_limit;

    return (
        <>
            <Title extra={
                <>
                    <span className="cl-muted cl-grow">{data && data.checked_at ? sprintf(
                        /* translators: 1: date and time, 2: posts checked (links read for link health; whole site, no limit) */
                        __('Checked %1$s · %2$s posts', 'cherrylink'), new Date(data.checked_at).toLocaleString(), num(data.summary.posts_checked ?? state.counts.checked)) : ''}</span>
                    <Btn kind="outline" onClick={refresh} disabled={busy}>{busy ? __('Checking…', 'cherrylink') : __('Check again', 'cherrylink')}</Btn>
                    <Btn kind="outline" disabled={!rows.length} onClick={() => downloadCsv(`cherrylink-${tab}.csv`, buildCsv(tab, rows))}>{__('Export CSV', 'cherrylink')}</Btn>
                </>
            }>{__('Link health', 'cherrylink')}</Title>
            {error && <Notice tone="danger">{error}</Notice>}
            {data && (
                <>
                    <div role="tablist" aria-label={__('Issue type', 'cherrylink')} className="cl-tabs">
                        {TABS.map((t) => (
                            <button key={t.id} type="button" role="tab" aria-selected={tab === t.id} className={tab === t.id ? 'is-on' : ''}
                                onClick={() => { setTab(t.id); setPage(1); }}>{t.label} <span className="cl-count">{num(data.summary[t.count])}</span></button>
                        ))}
                    </div>
                    <p className="cl-muted">{cur.help}</p>
                    <div className="cl-filters">
                        <label htmlFor="cl-type">{__('Type', 'cherrylink')}</label>
                        <select id="cl-type" className="cl-input" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }}>
                            <option value="">{__('All types', 'cherrylink')}</option>
                            {types.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                        <label htmlFor="cl-q">{__('Search posts', 'cherrylink')}</label>
                        <input id="cl-q" type="search" className="cl-input" value={q} placeholder={__('Search posts', 'cherrylink')} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                    </div>
                    <div className="cl-tablewrap">
                        <table className="cl-table">
                            <thead><tr><th>{__('Post', 'cherrylink')}</th><th>{__('Type', 'cherrylink')}</th><th>{tab === 'orphans' ? __('Related posts', 'cherrylink') : __('Details', 'cherrylink')}</th><th><span className="screen-reader-text">{__('Actions', 'cherrylink')}</span></th></tr></thead>
                            <tbody>
                                {!shown.length && <tr><td colSpan={4}>{__('Nothing found here.', 'cherrylink')}</td></tr>}
                                {shown.map((r) => (
                                    <tr key={r.post_id}>
                                        <td><a className="cl-link" href={r.edit_url}>{r.title || __('(no title)', 'cherrylink')}</a></td>
                                        <td>{r.type_label || r.type}</td>
                                        <td>
                                            {tab !== 'orphans' && detailsFor(tab, r)}
                                            {!r.learned && limit && <span className="cl-muted">{__('Not suggested in other posts: outside the free limit.', 'cherrylink')} <a className="cl-link" href={urls.upgrade} target="_blank" rel="noreferrer">{__('Upgrade', 'cherrylink')}</a></span>}
                                            {tab === 'orphans' && donors[r.post_id] && <Donors list={donors[r.post_id]} />}
                                        </td>
                                        <td>{tab === 'orphans' && <button type="button" className="cl-textbtn" onClick={() => find(r.post_id)}>{__('Find donors', 'cherrylink')}</button>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="cl-between">
                        <span className="cl-muted">{rows.length ? sprintf(
                            /* translators: 1: first row number, 2: last row number, 3: total rows */
                            __('Showing %1$s–%2$s of %3$s', 'cherrylink'), num((page - 1) * PER_PAGE + 1), num(Math.min(page * PER_PAGE, rows.length)), num(rows.length)) : ''}</span>
                        <span className="cl-row">
                            <Btn kind="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>{__('‹ Previous', 'cherrylink')}</Btn>
                            <Btn kind="ghost" disabled={page >= pages} onClick={() => setPage(page + 1)}>{__('Next ›', 'cherrylink')}</Btn>
                        </span>
                    </div>
                </>
            )}
            {!data && busy && <Card><p className="cl-muted">{__('Reading your links…', 'cherrylink')}</p></Card>}
        </>
    );
}
