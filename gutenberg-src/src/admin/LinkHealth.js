import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useAdmin, useLinkHealth, useSync } from './ctx';
import { buildCsv, buildKeysCsv, detailsFor, downloadCsv } from './csv';
import KeyPagesTab from './KeyPagesTab';
import { Btn, Card, ExpiredNotice, Link, Notice, Title, num } from './ui';

const PER_PAGE = 25;
export const TABS = [
    { id: 'orphans', label: __('Orphan pages', 'cherrylink-ai'), count: 'orphans', test: (r) => r.orphan,
        help: __('No other post links to these pages. Find related posts that could link to them, then add the link from the editor panel.', 'cherrylink-ai') },
    { id: 'broken', label: __('Broken links', 'cherrylink-ai'), count: 'broken', test: (r) => r.broken.length > 0,
        help: __('Internal links in these posts point to pages that no longer exist.', 'cherrylink-ai') },
    { id: 'no_outgoing', label: __('No outgoing', 'cherrylink-ai'), count: 'no_outgoing', test: (r) => r.no_outgoing,
        help: __('These posts do not link to any other post.', 'cherrylink-ai') },
    { id: 'repeats', label: __('Repeated links', 'cherrylink-ai'), count: 'repeats', test: (r) => r.repeats.length > 0,
        help: __('These posts link to the same page more than once.', 'cherrylink-ai') },
    { id: 'self', label: __('Links to itself', 'cherrylink-ai'), count: 'self_links', test: (r) => r.self.length > 0,
        help: __('These posts contain a link to their own address.', 'cherrylink-ai') },
];

export const rowsFor = (tab, rows, type, q) => {
    const t = TABS.find((x) => x.id === tab);
    const needle = q.trim().toLowerCase();
    return rows.filter((r) => t.test(r) && (!type || r.type === type) && (!needle || r.title.toLowerCase().includes(needle)));
};

function Donors({ list }) {
    if (list === 'loading') return <span className="cl-muted">{__('Looking for related posts…', 'cherrylink-ai')}</span>;
    if (list === 'error') return <Notice tone="danger">{__('Could not load related posts. Try again.', 'cherrylink-ai')}</Notice>;
    if (!list.length) return <span className="cl-muted">{__('No related posts found.', 'cherrylink-ai')}</span>;
    return (
        <ul className="cl-donors">
            {list.map((d) => (
                <li key={d.source_id}>
                    <strong>{d.source_title}</strong>
                    {d.anchor && <span className="cl-muted"> {sprintf(
                        /* translators: %s: suggested link text inside the related post */
                        __('suggested link text: “%s”', 'cherrylink-ai'), d.anchor)}</span>}{' '}
                    <a className="cl-link" href={d.edit_url}>{__('Edit', 'cherrylink-ai')}</a>
                </li>
            ))}
        </ul>
    );
}

export default function LinkHealth() {
    const { api, state, urls } = useAdmin();
    const expired = !!state.index_expired;
    const { data, busy, error, refresh } = useLinkHealth(expired);
    const { start: startScan, error: scanError } = useSync();
    const hash = (window.location.hash || '').replace('#', '');
    const [hTab, hPost] = hash.split('-');
    const [tab, setTab] = useState(TABS.some((t) => t.id === hTab) || hTab === 'keys' ? hTab : 'orphans');
    const [keys, setKeys] = useState(null);
    const [pinBusy, setPinBusy] = useState(0);
    const [type, setType] = useState('');
    const [q, setQ] = useState('');
    const [page, setPage] = useState(1);
    const [donors, setDonors] = useState({});

    useEffect(() => { if (data && data.keys) setKeys(data.keys); }, [data]);
    const pin = async (id, mode) => {
        setPinBusy(id);
        try {
            const r = await api('cherrylink_admin_key_pin', { post_id: id, mode });
            setKeys(r.keys);
        } catch (e) { /* keep the list; the next check shows the truth */ }
        setPinBusy(0);
    };

    const limit = state.connection.post_limit;
    const rows = data && tab !== 'keys' ? rowsFor(tab, data.rows, type, q) : [];
    // Orphans split in three: the page opened from the dashboard, learned pages (donors on demand),
    // and pages outside the plan limit (locked behind an upgrade overlay).
    const orphans = tab === 'orphans';
    const locked = orphans && limit ? rows.filter((r) => !r.learned) : [];
    const focus = orphans && hTab === 'orphans' ? rows.find((r) => String(r.post_id) === hPost && !locked.includes(r)) : null;
    const list = orphans ? rows.filter((r) => r !== focus && !locked.includes(r)) : rows;
    const pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
    const shown = list.slice((page - 1) * PER_PAGE, page * PER_PAGE);
    const types = data ? [...new Map(data.rows.map((r) => [r.type, r.type_label || r.type])).entries()] : [];

    // Orphans: posts that could link here. No outgoing: posts this one could link to. Both on click.
    const find = async (id, action = 'cherrylink_orphan_donors') => {
        const k = `${action}:${id}`; // a post can be on both tabs
        setDonors((d) => ({ ...d, [k]: 'loading' }));
        try {
            const r = await api(action, { post_id: id });
            setDonors((d) => ({ ...d, [k]: r.donors || [] }));
        } catch (e) {
            setDonors((d) => ({ ...d, [k]: 'error' }));
        }
    };

    // Deep link `#orphans-<id>` (dashboard "Easiest wins"): that page is shown first with its donors open.
    useEffect(() => {
        if (data && hPost && hTab === 'orphans') find(Number(hPost));
    }, [data]); // eslint-disable-line react-hooks/exhaustive-deps

    const cur = TABS.find((t) => t.id === tab) || TABS[0];
    const title = (r) => r.title || __('(no title)', 'cherrylink-ai');
    const orphanRow = (r) => {
        const out = tab === 'no_outgoing';
        const action = out ? 'cherrylink_post_targets' : 'cherrylink_orphan_donors';
        const d = donors[`${action}:${r.post_id}`];
        return (
            <tr key={r.post_id}>
                <td><a className="cl-link" href={r.edit_url}>{title(r)}</a></td>
                <td>{r.type_label || r.type}</td>
                <td>
                    {d && <Donors list={d} />}
                    {(!d || d === 'error') && <button type="button" className="cl-textbtn" onClick={() => find(r.post_id, action)}>{out ? __('Find posts to link to', 'cherrylink-ai') : __('Find posts that could link here', 'cherrylink-ai')}</button>}
                </td>
            </tr>
        );
    };
    const head = <thead><tr><th>{__('Post', 'cherrylink-ai')}</th><th>{__('Type', 'cherrylink-ai')}</th><th>{orphans || tab === 'no_outgoing' ? __('Related posts', 'cherrylink-ai') : __('Details', 'cherrylink-ai')}</th></tr></thead>;

    if (expired) {
        return (
            <>
                <Title>{__('Link health', 'cherrylink-ai')}</Title>
                {scanError && <Notice tone="danger">{scanError}</Notice>}
                <ExpiredNotice onStart={startScan} />
            </>
        );
    }

    return (
        <>
            <Title extra={
                <>
                    <span className="cl-muted cl-grow">{data && data.checked_at ? sprintf(
                        /* translators: 1: date and time, 2: posts checked (links read for link health; whole site, no limit) */
                        __('Checked %1$s · %2$s posts', 'cherrylink-ai'), new Date(data.checked_at).toLocaleString(), num(data.summary.posts_checked ?? state.counts.checked)) : ''}</span>
                    <Btn kind="outline" onClick={refresh} disabled={busy}>{busy ? __('Checking…', 'cherrylink-ai') : __('Check again', 'cherrylink-ai')}</Btn>
                    <Btn kind="outline" disabled={tab === 'keys' ? !(keys && keys.key_pages.length) : !rows.length}
                        onClick={() => downloadCsv(`cherrylink-${tab}.csv`, tab === 'keys' ? buildKeysCsv(keys.key_pages) : buildCsv(tab, rows))}>{__('Export CSV', 'cherrylink-ai')}</Btn>
                </>
            }>{__('Link health', 'cherrylink-ai')}</Title>
            {error && <Notice tone="danger">{error}</Notice>}
            {data && (
                <>
                    <div role="tablist" aria-label={__('Issue type', 'cherrylink-ai')} className="cl-tabs">
                        <button type="button" role="tab" aria-selected={tab === 'keys'} className={tab === 'keys' ? 'is-on' : ''}
                            onClick={() => { setTab('keys'); setPage(1); }}>{__('Key pages', 'cherrylink-ai')} <span className="cl-count">{num(keys ? keys.key_pages.length : 0)}</span></button>
                        {TABS.map((t) => (
                            <button key={t.id} type="button" role="tab" aria-selected={tab === t.id} className={tab === t.id ? 'is-on' : ''}
                                onClick={() => { setTab(t.id); setPage(1); }}>{t.label} <span className="cl-count">{num(data.summary[t.count])}</span></button>
                        ))}
                    </div>
                    {tab === 'keys' ? <KeyPagesTab keys={keys} busyId={pinBusy} onPin={pin} /> : <>
                    <p className="cl-muted">{cur.help}</p>
                    <div className="cl-filters">
                        <label htmlFor="cl-type" className="screen-reader-text">{__('Type', 'cherrylink-ai')}</label>
                        <select id="cl-type" className="cl-input" value={type} onChange={(e) => { setType(e.target.value); setPage(1); }}>
                            <option value="">{__('All types', 'cherrylink-ai')}</option>
                            {types.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                        </select>
                        <label htmlFor="cl-q" className="screen-reader-text">{__('Search posts', 'cherrylink-ai')}</label>
                        <input id="cl-q" type="search" className="cl-input" value={q} placeholder={__('Search posts', 'cherrylink-ai')} onChange={(e) => { setQ(e.target.value); setPage(1); }} />
                    </div>
                    {focus && <>
                        <h3 className="cl-eyebrow">{__('Selected page', 'cherrylink-ai')}</h3>
                        <div className="cl-tablewrap"><table className="cl-table">{head}<tbody>{orphanRow(focus)}</tbody></table></div>
                    </>}
                    {orphans && (focus || locked.length > 0) && <h3 className="cl-eyebrow">{sprintf(
                        /* translators: %s: number of orphan pages that can get links now */
                        __('Ready to fix (%s)', 'cherrylink-ai'), num(list.length))}</h3>}
                    <div className="cl-tablewrap">
                        <table className="cl-table">
                            {head}
                            <tbody>
                                {!shown.length && <tr><td colSpan={3}>{__('Nothing found here.', 'cherrylink-ai')}</td></tr>}
                                {shown.map((r) => orphans || tab === 'no_outgoing' ? orphanRow(r) : (
                                    <tr key={r.post_id}>
                                        <td><a className="cl-link" href={r.edit_url}>{title(r)}</a></td>
                                        <td>{r.type_label || r.type}</td>
                                        <td>
                                            {detailsFor(tab, r)}
                                            {!r.learned && limit && <span className="cl-muted">{__('Not suggested in other posts: outside the free limit.', 'cherrylink-ai')} <a className="cl-link" href={urls.upgrade} target="_blank" rel="noreferrer">{__('Upgrade', 'cherrylink-ai')}</a></span>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="cl-between">
                        <span className="cl-muted">{list.length ? sprintf(
                            /* translators: 1: first row number, 2: last row number, 3: total rows */
                            __('Showing %1$s–%2$s of %3$s', 'cherrylink-ai'), num((page - 1) * PER_PAGE + 1), num(Math.min(page * PER_PAGE, list.length)), num(list.length)) : ''}</span>
                        <span className="cl-row">
                            <Btn kind="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>{__('‹ Previous', 'cherrylink-ai')}</Btn>
                            <Btn kind="ghost" disabled={page >= pages} onClick={() => setPage(page + 1)}>{__('Next ›', 'cherrylink-ai')}</Btn>
                        </span>
                    </div>
                    {locked.length > 0 && <>
                        <h3 className="cl-eyebrow">{sprintf(
                            /* translators: %s: number of orphan pages outside the plan limit */
                            __('Not learned on your plan (%s)', 'cherrylink-ai'), num(locked.length))}</h3>
                        <div className="cl-locked">
                            {/* ponytail: a blurred preview of the first rows; the full list is in Export CSV */}
                            <div className="cl-tablewrap" aria-hidden="true"><table className="cl-table"><tbody>
                                {locked.slice(0, 5).map((r) => <tr key={r.post_id}><td>{title(r)}</td><td>{r.type_label || r.type}</td></tr>)}
                            </tbody></table></div>
                            <div className="cl-locked-over">
                                <strong>{sprintf(
                                    /* translators: %s: number of orphan pages outside the plan limit */
                                    _n('%s orphan page is outside your plan', '%s orphan pages are outside your plan', locked.length, 'cherrylink-ai'), num(locked.length))}</strong>
                                <span className="cl-muted">{sprintf(
                                    /* translators: %s: plan limit of learned posts */
                                    __('The free plan learns your %s most recently updated posts. Upgrade to find links for these pages too.', 'cherrylink-ai'), num(limit))}</span>
                                <Link kind="btn" href={urls.upgrade} target="_blank" rel="noreferrer">{__('Upgrade', 'cherrylink-ai')}</Link>
                            </div>
                        </div>
                    </>}
                    </>}
                </>
            )}
            {!data && busy && <Card><p className="cl-muted">{__('Reading your links…', 'cherrylink-ai')}</p></Card>}
        </>
    );
}
