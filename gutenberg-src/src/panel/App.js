import { useState, useEffect, useRef, useMemo, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { createApi } from './api';
import { buildOpportunities, normalizeUrl } from './opportunities';
import Strip, { countWords } from './Strip';
import Tabs from './Tabs';
import OpportunityList from './OpportunityList';
import PostList, { Skeleton } from './PostList';
import TermsList from './TermsList';
import DonorList from './DonorList';
import LinksView from './LinksView';
import Toast from './Toast';
import { Logo, IconClose } from './icons';
import './panel.css';

const SEARCH_DEBOUNCE_MS = 400;
const EMPTY = { rows: [], hasMore: false, status: 'loading', error: '' };

const REASONS = {
    no_selection: () => __('Select text in the post to insert a link', 'cherrylink-ai'),
    inside_link: () => __('Select text outside an existing link', 'cherrylink-ai'),
    not_found: () => __('The phrase was not found in the article text', 'cherrylink-ai'),
    unsupported: () => __('This block does not support inserting links', 'cherrylink-ai'),
};

const flattenCategories = (terms) =>
    (terms || []).reduce((acc, t) => {
        if ((t.is_divider === true || t.is_divider === 'yes') || (t.taxonomy && t.taxonomy !== 'category')) return acc;
        const walk = (x) => { acc.push(x.name || x.title); (x.children || []).forEach(walk); };
        walk(t);
        return acc;
    }, []);

export default function App({ adapter, config, onClose, headProps }) {
    const api = useMemo(() => createApi(config), [config]);
    const opts = config.options || {};
    const isTerm = !!config.isTerm;
    // Suggestions, incoming links and donors come from the CherryLink service; categories and
    // the link-insertion tools are local and work without a connection.
    const connected = config.connected !== false;

    const [tab, setTab] = useState('opp');
    const [view, setView] = useState(null); // null | { section }
    const [links, setLinks] = useState(() => adapter.listLinks());
    const [base, setBase] = useState(EMPTY);
    const [filt, setFilt] = useState(EMPTY);
    const [query, setQuery] = useState('');
    const [dq, setDq] = useState('');
    const [category, setCategory] = useState('');
    const [terms, setTerms] = useState({ items: [], status: 'idle', error: '' });
    const [mode, setMode] = useState(connected ? 'posts' : 'terms'); // posts | terms
    const [incoming, setIncoming] = useState([]);
    const [donors, setDonors] = useState({ items: [], status: 'loading', error: '' });
    const [donorDone, setDonorDone] = useState({});
    const [donorBusy, setDonorBusy] = useState({});
    const [hidden, setHidden] = useState([]); // [{key, phrase, title}]
    const [showHidden, setShowHidden] = useState(false);
    const [altIndex, setAltIndex] = useState({});
    const [lastInsert, setLastInsert] = useState(null);
    const [toast, setToast] = useState(null);
    const [keyPage, setKeyPage] = useState(config.keyPage || null);
    const [keyBusy, setKeyBusy] = useState(false);

    const seq = useRef({ base: 0, filt: 0 });
    const [words, setWords] = useState(() => countWords(adapter.getText()));
    const refreshLinks = useCallback(() => { setLinks(adapter.listLinks()); setWords(countWords(adapter.getText())); }, [adapter]);
    const say = (message, undo = false) => setToast({ message, undo, id: Date.now() });

    useEffect(() => adapter.onChange(refreshLinks), [adapter, refreshLinks]);

    // ---- data ----
    const fetchRows = (which, { append, q, cat, offset }) => {
        const setter = which === 'base' ? setBase : setFilt;
        const id = ++seq.current[which];
        setter((s) => ({ ...s, status: 'loading', error: '', rows: append ? s.rows : [] }));
        api('cherrylink_panel_links', {
            post_id: config.postId,
            is_term: isTerm,
            source_html: adapter.getHtml(),
            query: q,
            category: cat,
            offset,
        }).then(
            (res) => {
                if (id !== seq.current[which]) return;
                setter((s) => ({ rows: append ? s.rows.concat(res.items || []) : res.items || [], hasMore: !!res.has_more, status: 'ready', error: '' }));
            },
            (err) => {
                if (id !== seq.current[which]) return;
                setter((s) => ({ ...s, status: 'error', error: err.message }));
            }
        );
    };
    const loadBase = () => fetchRows('base', { append: false, q: '', cat: '', offset: 0 });

    const loadIncoming = () =>
        api('cherrylink_panel_incoming', { object_id: config.postId, type: isTerm ? 'term' : 'post' }).then(
            (res) => setIncoming(res.items || []),
            () => {}
        );
    const loadDonors = () => {
        if (isTerm) return;
        setDonors((s) => ({ ...s, status: 'loading', error: '' }));
        api('cherrylink_panel_donors', { post_id: config.postId }).then(
            (res) => setDonors({ items: res.items || [], status: 'ready', error: '' }),
            (err) => setDonors((s) => ({ ...s, status: 'error', error: err.message }))
        );
    };
    const loadTerms = () => {
        setTerms((s) => ({ ...s, status: 'loading', error: '' }));
        api('cherrylink_panel_terms', {}).then(
            (res) => setTerms({ items: Array.isArray(res) ? res : res.items || [], status: 'ready', error: '' }),
            (err) => setTerms((s) => ({ ...s, status: 'error', error: err.message }))
        );
    };

    useEffect(() => {
        if (!connected) return;
        loadBase();
        loadIncoming();
        loadDonors();
        if (opts.showCatFilter) loadTerms();
    }, []);

    useEffect(() => {
        const t = setTimeout(() => setDq(query.trim()), SEARCH_DEBOUNCE_MS);
        return () => clearTimeout(t);
    }, [query]);

    const firstFilter = useRef(true);
    useEffect(() => {
        if (firstFilter.current) { firstFilter.current = false; return; }
        if (!dq && !category) { seq.current.filt++; setFilt(EMPTY); return; }
        fetchRows('filt', { append: false, q: dq, cat: category, offset: 0 });
    }, [dq, category]);

    useEffect(() => {
        if (mode === 'terms' && terms.status === 'idle') loadTerms();
    }, [mode]);

    // ---- derived ----
    const filtering = !!(dq || category);
    const listData = filtering ? filt : base;
    const hiddenSet = useMemo(() => new Set(hidden.map((h) => h.key)), [hidden]);
    const items = useMemo(() => buildOpportunities(base.rows, { links, hidden: hiddenSet }), [base.rows, links, hiddenSet]);
    const doneCount = Object.keys(donorDone).length;
    const inc = incoming.length + doneCount;
    const donorCount = donors.items.filter((d) => d.suggestion && !donorDone[d.donor_id]).length;
    const titleByUrl = useMemo(() => {
        const m = {};
        base.rows.concat(filt.rows).forEach((r) => { m[normalizeUrl(r.url)] = r.title; });
        return m;
    }, [base.rows, filt.rows]);

    // ---- actions ----
    const toLink = (r) => ({ url: r.url, title: r.title, open: r.open, close: r.close });

    const afterApply = (res, text, title) => {
        if (res.ok) {
            setLastInsert({ ref: res.ref, text, title });
            refreshLinks();
            say(__('Link inserted', 'cherrylink-ai'), true);
        } else {
            say((REASONS[res.reason] || REASONS.unsupported)());
        }
    };

    const insertPhrase = (row, s) => {
        adapter.clearReveal();
        afterApply(adapter.applyLinkToPhrase(s.phrase, s.block_index, toLink(row)), s.phrase, row.title);
    };

    const insertSelection = (row) => {
        const text = adapter.getSelection().text;
        const res = adapter.applyLinkToSelection(toLink(row));
        const action = opts.noSelectionAction;
        if (!res.ok && res.reason === 'no_selection' && config.host === 'classic' && adapter.insertAtCaret && ['title', 'h1', 'placeholder', 'empty'].includes(action)) {
            const t = action === 'placeholder' ? __('LINK_TEXT', 'cherrylink-ai') : action === 'empty' ? '&nbsp;' : row.title;
            afterApply(adapter.insertAtCaret(toLink(row), t), t, row.title);
            return;
        }
        afterApply(res, text.trim(), row.title);
    };

    const undoInsert = () => {
        if (lastInsert) adapter.removeLink(lastInsert.ref);
        setLastInsert(null);
        setToast(null);
        refreshLinks();
    };

    const hide = (key, row, phrase) => {
        adapter.clearReveal();
        setHidden((h) => (h.some((x) => x.key === key) ? h : h.concat({ key, phrase, title: row.title })));
    };

    const donorInsert = async (d) => {
        setDonorBusy((b) => ({ ...b, [d.donor_id]: true }));
        try {
            const res = await api('cherrylink_panel_donor_insert', {
                donor_id: d.donor_id, target_id: config.postId, phrase: d.suggestion.phrase, block_index: d.suggestion.block_index,
            });
            setDonorDone((m) => ({ ...m, [d.donor_id]: { token: res.undo_token } }));
            say(sprintf(__('Link added to “%s”', 'cherrylink-ai'), d.title));
        } catch (e) {
            say(e.message);
        }
        setDonorBusy((b) => ({ ...b, [d.donor_id]: false }));
    };

    const donorUndo = async (d) => {
        setDonorBusy((b) => ({ ...b, [d.donor_id]: true }));
        try {
            await api('cherrylink_panel_donor_undo', { donor_id: d.donor_id, undo_token: donorDone[d.donor_id].token });
            setDonorDone((m) => { const n = { ...m }; delete n[d.donor_id]; return n; });
            say(__('Link removed', 'cherrylink-ai'));
        } catch (e) {
            say(e.code === 'changed' ? __('The post has changed since, so this cannot be undone automatically', 'cherrylink-ai') : e.message);
        }
        setDonorBusy((b) => ({ ...b, [d.donor_id]: false }));
    };

    const keyPin = async (mode) => {
        setKeyBusy(true);
        try {
            const res = await api('cherrylink_panel_key_pin', { post_id: config.postId, mode });
            setKeyPage(res.keyPage || null);
            say(mode === 'pin' ? __('Marked as a key page', 'cherrylink-ai') : __('Key page choice saved', 'cherrylink-ai'));
            loadBase();
        } catch (e) {
            say(e.message);
        }
        setKeyBusy(false);
    };

    const refreshAll = () => {
        if (!connected) return;
        loadBase();
        if (filtering) fetchRows('filt', { append: false, q: dq, cat: category, offset: 0 });
        refreshLinks();
        say(__('Suggestions refreshed for the current text', 'cherrylink-ai'));
    };

    const more = () => {
        const which = filtering ? 'filt' : 'base';
        fetchRows(which, { append: true, q: filtering ? dq : '', cat: filtering ? category : '', offset: listData.rows.length });
    };

    // ---- render ----
    const head = config.host === 'gutenberg' ? null : (
        <div className="cl-head" {...(headProps || {})}>
            <Logo />
            <span className="cl-brand">CherryLink</span>
            {onClose && (
                <button type="button" className="cl-btn cl-ghost cl-close" onClick={onClose} aria-label={__('Close', 'cherrylink-ai')}><IconClose /></button>
            )}
        </div>
    );

    const connectPrompt = (
        <div className="cl-empty">
            <p>{__('CherryLink is not connected yet. Connect the plugin to get link suggestions.', 'cherrylink-ai')}</p>
            <a className="cl-btn cl-primary" href={config.connectUrl}>{__('Connect CherryLink', 'cherrylink-ai')}</a>
        </div>
    );

    const tabs = [
        { id: 'opp', label: __('Suggestions', 'cherrylink-ai'), badge: items.length || '' },
        { id: 'all', label: __('All posts', 'cherrylink-ai') },
        ...(isTerm ? [] : [{ id: 'in', label: __('Incoming links', 'cherrylink-ai') }]),
    ];
    const categories = flattenCategories(terms.items);

    let body;
    if (view) {
        body = (
            <LinksView links={links} incoming={incoming} titleByUrl={titleByUrl} section={view.section}
                onBack={() => setView(null)} onReveal={(ref) => adapter.reveal(ref)} />
        );
    } else if (!connected && tab !== 'all') {
        body = connectPrompt;
    } else if (tab === 'opp') {
        body = base.status === 'error' ? (
            <div className="cl-empty"><p>{base.error}</p><button type="button" className="cl-btn" onClick={loadBase}>{__('Retry', 'cherrylink-ai')}</button></div>
        ) : base.status === 'loading' && !base.rows.length ? <Skeleton /> : (
            <OpportunityList items={items} loading={base.status === 'loading'} hiddenList={hidden} showHidden={showHidden} altIndex={altIndex} lastInsert={lastInsert}
                onInsert={insertPhrase} onAlt={(p) => setAltIndex((a) => ({ ...a, [p.toLowerCase()]: (a[p.toLowerCase()] || 0) + 1 }))}
                onHide={hide} onRestore={(k) => setHidden((h) => h.filter((x) => x.key !== k))} onToggleHidden={() => setShowHidden((v) => !v)}
                onUndo={undoInsert} onReveal={(p, b) => adapter.reveal(p, b)} onClearReveal={() => adapter.clearReveal()} />
        );
    } else if (tab === 'all') {
        body = (
            <>
                <div className="cl-seg" role="group" aria-label={__('What to show', 'cherrylink-ai')}>
                    <button type="button" aria-pressed={mode === 'posts'} onClick={() => setMode('posts')}>{__('Posts', 'cherrylink-ai')}</button>
                    <button type="button" aria-pressed={mode === 'terms'} onClick={() => setMode('terms')}>{__('Categories', 'cherrylink-ai')}</button>
                </div>
                {mode === 'posts' && !connected ? connectPrompt : mode === 'posts' ? (
                    <PostList rows={listData.rows} links={links} status={listData.status} error={listData.error} hasMore={listData.hasMore}
                        query={query} category={category} categories={categories} showCatFilter={!!opts.showCatFilter}
                        onQuery={setQuery} onCategory={setCategory} onHere={insertSelection}
                        onPill={insertPhrase} onMore={more}
                        onRetry={() => (filtering ? fetchRows('filt', { append: false, q: dq, cat: category, offset: 0 }) : loadBase())} />
                ) : (
                    <TermsList terms={terms.items} status={terms.status} error={terms.error} links={links} onHere={insertSelection} onRetry={loadTerms} />
                )}
            </>
        );
    } else {
        body = (
            <DonorList donors={donors.items} status={donors.status} error={donors.error} done={donorDone} busy={donorBusy} targetId={config.postId}
                onInsert={donorInsert} onUndo={donorUndo} onRetry={loadDonors} />
        );
    }

    return (
        <div className="cl-panel">
            {head}
            <Strip out={links.length} inc={inc} words={words} donorCount={donorCount} onGoSuggestions={() => { setView(null); setTab('opp'); }}
                links={links} keyPage={keyPage} canKeyPin={!!config.canKeyPin && connected} keyBusy={keyBusy} onKeyPin={keyPin}
                plan={config.plan} postLearned={config.postLearned} upgradeUrl={config.upgradeUrl}
                indexExpired={!!config.indexExpired} dashboardUrl={config.connectUrl}
                onLinks={(section) => setView({ section })} onGoIncoming={() => { setView(null); setTab('in'); }} />
            <Tabs tabs={tabs} current={view ? '' : tab} onChange={(t) => { setView(null); setTab(t); }} />
            <div className="cl-body">{body}</div>
            {connected && (
                <div className="cl-foot">
                    <span>{__('Suggestions for the current text', 'cherrylink-ai')}</span>
                    <button type="button" className="cl-btn cl-ghost" onClick={refreshAll}>{__('Refresh', 'cherrylink-ai')}</button>
                </div>
            )}
            <Toast toast={toast} onUndo={undoInsert} onClose={() => setToast(null)} />
        </div>
    );
}
