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
    no_selection: () => __('Выделите текст в статье для вставки ссылки', 'cherrylink'),
    inside_link: () => __('Выделите текст вне существующей ссылки', 'cherrylink'),
    not_found: () => __('Фраза не найдена в тексте статьи', 'cherrylink'),
    unsupported: () => __('Этот блок не поддерживает вставку ссылок', 'cherrylink'),
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

    const [tab, setTab] = useState('opp');
    const [view, setView] = useState(null); // null | { section }
    const [links, setLinks] = useState(() => adapter.listLinks());
    const [base, setBase] = useState(EMPTY);
    const [filt, setFilt] = useState(EMPTY);
    const [query, setQuery] = useState('');
    const [dq, setDq] = useState('');
    const [category, setCategory] = useState('');
    const [terms, setTerms] = useState({ items: [], status: 'idle', error: '' });
    const [mode, setMode] = useState('posts'); // posts | terms
    const [incoming, setIncoming] = useState([]);
    const [donors, setDonors] = useState({ items: [], status: 'loading', error: '' });
    const [donorDone, setDonorDone] = useState({});
    const [donorBusy, setDonorBusy] = useState({});
    const [hidden, setHidden] = useState([]); // [{key, phrase, title}]
    const [showHidden, setShowHidden] = useState(false);
    const [altIndex, setAltIndex] = useState({});
    const [lastInsert, setLastInsert] = useState(null);
    const [toast, setToast] = useState(null);

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
        if (config.licensed === false) return;
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
            say(__('Ссылка вставлена', 'cherrylink'), true);
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
            const t = action === 'placeholder' ? __('ТЕКСТ_ССЫЛКИ', 'cherrylink') : action === 'empty' ? '&nbsp;' : row.title;
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
            say(sprintf(__('Ссылка добавлена в «%s»', 'cherrylink'), d.title));
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
            say(__('Ссылка убрана', 'cherrylink'));
        } catch (e) {
            say(e.code === 'changed' ? __('Статья уже изменилась, отменить автоматически нельзя', 'cherrylink') : e.message);
        }
        setDonorBusy((b) => ({ ...b, [d.donor_id]: false }));
    };

    const refreshAll = () => {
        loadBase();
        if (filtering) fetchRows('filt', { append: false, q: dq, cat: category, offset: 0 });
        refreshLinks();
        say(__('Подсказки пересчитаны по текущему тексту', 'cherrylink'));
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
                <button type="button" className="cl-btn cl-ghost cl-close" onClick={onClose} aria-label={__('Закрыть', 'cherrylink')}><IconClose /></button>
            )}
        </div>
    );

    if (config.licensed === false) {
        return (
            <div className="cl-panel">
                {head}
                <div className="cl-body">
                    <div className="cl-empty">
                        <p>{__('CherryLink ещё не подключён. Подключите плагин, чтобы получать подсказки ссылок.', 'cherrylink')}</p>
                        <a className="cl-btn cl-primary" href={config.connectUrl}>{__('Подключить CherryLink', 'cherrylink')}</a>
                    </div>
                </div>
            </div>
        );
    }

    const tabs = [
        { id: 'opp', label: __('Подсказки', 'cherrylink'), badge: items.length || '' },
        { id: 'all', label: __('Все статьи', 'cherrylink') },
        ...(isTerm ? [] : [{ id: 'in', label: __('Входящие', 'cherrylink') }]),
    ];
    const categories = flattenCategories(terms.items);

    let body;
    if (view) {
        body = (
            <LinksView links={links} incoming={incoming} titleByUrl={titleByUrl} section={view.section}
                onBack={() => setView(null)} onReveal={(ref) => adapter.reveal(ref)} />
        );
    } else if (tab === 'opp') {
        body = base.status === 'error' ? (
            <div className="cl-empty"><p>{base.error}</p><button type="button" className="cl-btn" onClick={loadBase}>{__('Повторить', 'cherrylink')}</button></div>
        ) : base.status === 'loading' && !base.rows.length ? <Skeleton /> : (
            <OpportunityList items={items} loading={base.status === 'loading'} hiddenList={hidden} showHidden={showHidden} altIndex={altIndex} lastInsert={lastInsert}
                onInsert={insertPhrase} onAlt={(p) => setAltIndex((a) => ({ ...a, [p.toLowerCase()]: (a[p.toLowerCase()] || 0) + 1 }))}
                onHide={hide} onRestore={(k) => setHidden((h) => h.filter((x) => x.key !== k))} onToggleHidden={() => setShowHidden((v) => !v)}
                onUndo={undoInsert} onReveal={(p, b) => adapter.reveal(p, b)} onClearReveal={() => adapter.clearReveal()} />
        );
    } else if (tab === 'all') {
        body = (
            <>
                <div className="cl-seg" role="group" aria-label={__('Что показывать', 'cherrylink')}>
                    <button type="button" aria-pressed={mode === 'posts'} onClick={() => setMode('posts')}>{__('Статьи', 'cherrylink')}</button>
                    <button type="button" aria-pressed={mode === 'terms'} onClick={() => setMode('terms')}>{__('Рубрики', 'cherrylink')}</button>
                </div>
                {mode === 'posts' ? (
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
                plan={config.plan} postLearned={config.postLearned} upgradeUrl={config.upgradeUrl}
                onLinks={(section) => setView({ section })} onGoIncoming={() => { setView(null); setTab('in'); }} />
            <Tabs tabs={tabs} current={view ? '' : tab} onChange={(t) => { setView(null); setTab(t); }} />
            <div className="cl-body">{body}</div>
            <div className="cl-foot">
                <span>{__('Подсказки по текущему тексту', 'cherrylink')}</span>
                <button type="button" className="cl-btn cl-ghost" onClick={refreshAll}>{__('Обновить', 'cherrylink')}</button>
            </div>
            <Toast toast={toast} onUndo={undoInsert} onClose={() => setToast(null)} />
        </div>
    );
}
