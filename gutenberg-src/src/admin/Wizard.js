import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useAdmin, useSync, useLinkHealth } from './ctx';
import { Badge, Bar, Btn, Card, Mark, Notice, num } from './ui';

const Steps = ({ step }) => {
    const names = [__('Connect', 'cherrylink'), __('Analyze site', 'cherrylink'), __('Done', 'cherrylink')];
    return (
        <ol className="cl-steps" aria-label={__('Setup steps', 'cherrylink')}>
            {names.map((n, i) => (
                <li key={n} className={i + 1 < step ? 'is-done' : i + 1 === step ? 'is-now' : ''} aria-current={i + 1 === step ? 'step' : undefined}>
                    <span>{i + 1 < step ? '✓' : i + 1}</span>{n}
                </li>
            ))}
        </ol>
    );
};

function Connect() {
    const { boot, api, state, setState, urls } = useAdmin();
    const [showKey, setShowKey] = useState(false);
    const [key, setKey] = useState('');
    const [checking, setChecking] = useState(state.has_key && state.license.status === 'pending');
    const [err, setErr] = useState('');

    useEffect(() => {
        if (!checking) return undefined;
        let dead = false;
        const t = setInterval(async () => {
            try {
                const s = await api('cherrylink_admin_state');
                if (dead) return;
                setState(s);
                if (s.license.status !== 'pending') {
                    setChecking(false);
                    if (!s.connected) setErr(s.license.error || __('This key was not accepted.', 'cherrylink'));
                }
            } catch (e) { /* retry */ }
        }, 2000);
        return () => { dead = true; clearInterval(t); };
    }, [checking]); // eslint-disable-line react-hooks/exhaustive-deps

    const useKey = async () => {
        setErr('');
        try {
            setState(await api('cherrylink_admin_license', { op: 'set_key', key }));
            setChecking(true);
        } catch (e) {
            setErr(e.message);
        }
    };
    const error = err || boot.connect.error;

    return (
        <Card className="cl-card cl-wide">
            <div className="cl-stack">
                <h1>{__('Connect CherryLink', 'cherrylink')}</h1>
                <p className="cl-muted">{__('CherryLink compares your posts on its servers to suggest internal links and check link health. Connecting creates a free key bound to this site. Nothing is sent until you click Connect.', 'cherrylink')}</p>
            </div>
            {error && <Notice tone="danger">{error}</Notice>}
            <div className="cl-grid2">
                <div className="cl-soft"><strong>{__('What is sent', 'cherrylink')}</strong><span>{__('Post titles, text and URLs of the post types you choose, plus your plugin, PHP and WordPress versions for compatibility. Stored on servers in the EU.', 'cherrylink')}</span></div>
                <div className="cl-soft"><strong>{__('What is not', 'cherrylink')}</strong><span>{__('No users, emails, comments or drafts. Data is deleted when you remove the index or after 180 days of inactivity.', 'cherrylink')}</span></div>
            </div>
            <div className="cl-accent-box"><strong>{__('Free plan', 'cherrylink')}</strong><span>{__('Suggestions from your 1,000 most recently updated posts. Link health check for the whole site. No credits, no expiry.', 'cherrylink')}</span></div>
            {checking ? (
                <Notice tone="info">{__('Checking your key…', 'cherrylink')}</Notice>
            ) : (
                <div className="cl-row">
                    <form method="post" action={boot.connect.postUrl}>
                        <input type="hidden" name="action" value={boot.connect.action} />
                        <input type="hidden" name="_wpnonce" value={boot.connect.nonce} />
                        <button type="submit" className="cl-btn cl-btn-primary cl-btn-lg">{__('Connect and get a free key', 'cherrylink')}</button>
                    </form>
                    <button type="button" className="cl-textbtn" aria-expanded={showKey} onClick={() => setShowKey(!showKey)}>{__('I already have a key', 'cherrylink')}</button>
                </div>
            )}
            {showKey && !checking && (
                <div className="cl-stack cl-top">
                    <label htmlFor="cl-key"><strong>{__('License key', 'cherrylink')}</strong></label>
                    <div className="cl-row">
                        <input id="cl-key" type="text" className="cl-input cl-grow" placeholder="clp_…" value={key} onChange={(e) => setKey(e.target.value)} />
                        <Btn kind="outline" disabled={!key.trim()} onClick={useKey}>{__('Use this key', 'cherrylink')}</Btn>
                    </div>
                </div>
            )}
            <p className="cl-small cl-muted">
                {__('By connecting you agree to the', 'cherrylink')} <a href={urls.terms} target="_blank" rel="noreferrer">{__('Terms of Use', 'cherrylink')}</a> {__('and', 'cherrylink')} <a href={urls.privacy} target="_blank" rel="noreferrer">{__('Privacy Policy', 'cherrylink')}</a>.
            </p>
        </Card>
    );
}

const phaseLabel = (p) => ({
    manifest: __('Comparing your posts', 'cherrylink'),
    create: __('Preparing', 'cherrylink'),
    upload: __('Sending posts', 'cherrylink'),
    upsert: __('Learning your posts', 'cherrylink'),
    links: __('Mapping links', 'cherrylink'),
    prune: __('Cleaning up', 'cherrylink'),
}[p] || __('Working', 'cherrylink'));

export function Progress({ sync }) {
    const async = sync.engine === 'async';
    const done = async ? sync.accepted : sync.indexed;
    const total = async ? 0 : sync.upsert_total;
    return (
        <div className="cl-stack">
            <div className="cl-between"><strong>{phaseLabel(sync.phase)}</strong>
                {total > 0 && <span>{sprintf(
                    /* translators: 1: posts learned so far, 2: posts to learn in this scan */
                    __('%1$s / %2$s posts learned', 'cherrylink'), num(done), num(total))}</span>}
            </div>
            {total > 0 && <Bar value={done} total={total} />}
            {!async && sync.links > 0 && <span className="cl-muted">{sprintf(
                /* translators: %s: number of posts whose links were read */
                _n('%s post mapped so far', '%s posts mapped so far', sync.links, 'cherrylink'), num(sync.links))}</span>}
            {sync.next_retry_gmt && <span className="cl-muted">{__('The server is busy; retrying shortly.', 'cherrylink')}</span>}
        </div>
    );
}

const secs = (s) => {
    const a = Date.parse(`${(s.started_gmt || '').replace(' ', 'T')}Z`);
    const b = Date.parse(`${(s.updated_gmt || '').replace(' ', 'T')}Z`);
    return a && b && b >= a ? Math.round((b - a) / 1000) : null;
};

function Analyze({ onDone }) {
    const { api, state, setState } = useAdmin();
    const { sync, error, start, cancel } = useSync();
    const types = state.counts.types || [];
    const [sel, setSel] = useState(types.filter((t) => t.enabled).map((t) => t.name));
    const [saveErr, setSaveErr] = useState('');
    const limit = state.license.post_limit;
    const found = types.filter((t) => sel.includes(t.name)).reduce((a, t) => a + t.count, 0);
    const running = sync.status === 'running';
    const finished = sync.status === 'done';

    const begin = async () => {
        setSaveErr('');
        try {
            const r = await api('cherrylink_admin_save_settings', { settings: { post_types: sel } });
            if (r.state) setState(r.state);
        } catch (e) {
            setSaveErr(e.message);
            return;
        }
        start();
    };
    const skip = async () => {
        try { await api('cherrylink_admin_setup_done'); } catch (e) { /* proceed anyway */ }
        setState({ ...state, setup_done: true });
    };
    const toggle = (n) => setSel(sel.includes(n) ? sel.filter((x) => x !== n) : [...sel, n]);
    const secsTaken = finished ? secs(sync) : null;

    return (
        <Card className="cl-card cl-wide">
            <div className="cl-row"><h1>{__('Analyze your site', 'cherrylink')}</h1><Badge>{state.license.plan === 'free' ? __('Connected · Free', 'cherrylink') : __('Connected', 'cherrylink')}</Badge></div>
            {(error || saveErr) && <Notice tone="danger">{error || saveErr}</Notice>}
            {sync.status === 'error' && <Notice tone="danger">{sync.last_error || sync.message || __('The analysis stopped with an error.', 'cherrylink')}</Notice>}
            {!running && !finished && (
                <>
                    <p className="cl-muted">{__('CherryLink reads your posts once to learn what each one is about, and maps every internal link to find orphan pages and broken links. After that it keeps up automatically whenever you save.', 'cherrylink')}</p>
                    <fieldset className="cl-fieldset">
                        <legend>{__('Content to analyze', 'cherrylink')}</legend>
                        {types.map((t) => (
                            <label key={t.name}><input type="checkbox" checked={sel.includes(t.name)} onChange={() => toggle(t.name)} />{t.label} <span className="cl-muted">({num(t.count)})</span></label>
                        ))}
                    </fieldset>
                    <Notice tone="warn">
                        {limit ? (
                            <>
                                <strong>{sprintf(
                                    /* translators: %s: number of posts found */
                                    _n('%s post found.', '%s posts found.', found, 'cherrylink'), num(found))}</strong>{' '}
                                {sprintf(
                                    /* translators: 1: free plan learned-post limit, 2: total posts found. "Learned" = analyzed for suggestions; "checked" = links read for link health. */
                                    __('The free plan gives suggestions from your %1$s most recently updated posts (learned). Link health covers all %2$s: it only reads the links already in your posts, so no learning is needed.', 'cherrylink'), num(limit), num(found))}
                            </>
                        ) : sprintf(
                            /* translators: %s: number of posts found */
                            _n('%s post found. All of them will be learned for suggestions and checked for link health.', '%s posts found. All of them will be learned for suggestions and checked for link health.', found, 'cherrylink'), num(found))}
                    </Notice>
                    <div className="cl-row">
                        <Btn className="cl-btn-lg" onClick={begin} disabled={!sel.length}>{__('Analyze site', 'cherrylink')}</Btn>
                        <button type="button" className="cl-textbtn" onClick={skip}>{__('Skip, I\'ll do it later', 'cherrylink')}</button>
                    </div>
                    <span className="cl-muted">{__('Skipping goes straight to the dashboard. You can start the analysis there or from CherryLink › Index.', 'cherrylink')}</span>
                </>
            )}
            {running && (
                <>
                    <p className="cl-muted">{__('Analysis started. It runs in the background on your server\'s scheduler, so you don\'t have to wait here.', 'cherrylink')}</p>
                    <Progress sync={sync} />
                    <div className="cl-row">
                        <Btn kind="ghost" disabled>{__('See results', 'cherrylink')}</Btn>
                        <button type="button" className="cl-textbtn" onClick={skip}>{__('Continue to dashboard', 'cherrylink')}</button>
                        <button type="button" className="cl-textbtn" onClick={cancel}>{__('Stop', 'cherrylink')}</button>
                    </div>
                    <span className="cl-muted">{__('"See results" unlocks when the analysis finishes. If you leave, the dashboard shows the progress.', 'cherrylink')}</span>
                </>
            )}
            {finished && (
                <>
                    <div className="cl-row"><span className="cl-ok">{__('✓ Analysis complete', 'cherrylink')}</span>
                        <span className="cl-muted">{sprintf(
                            /* translators: 1: posts learned (analyzed for suggestions), 2: posts checked (links read for link health) */
                            __('%1$s posts learned · %2$s posts checked', 'cherrylink'), num(state.counts.learned), num(state.counts.checked))}{secsTaken !== null && ` · ${sprintf(__('%s s', 'cherrylink'), secsTaken)}`}</span></div>
                    <div className="cl-row"><Btn onClick={onDone}>{__('See results', 'cherrylink')}</Btn></div>
                </>
            )}
        </Card>
    );
}

function Done() {
    const { api, state, setState, urls } = useAdmin();
    const { data, busy, error } = useLinkHealth();
    const [err, setErr] = useState('');
    const finish = async (to) => {
        try {
            await api('cherrylink_admin_setup_done');
            if (to) window.location.assign(to);
            else setState({ ...state, setup_done: true });
        } catch (e) {
            setErr(e.message);
        }
    };
    const s = data && data.summary;
    const cards = s && [
        [s.orphans, __('Orphan pages', 'cherrylink'), __('No other post links to them, so readers and search engines rarely find them.', 'cherrylink'), 'danger'],
        [s.broken, __('Broken links', 'cherrylink'), __('Internal links that point to pages that no longer exist.', 'cherrylink'), 'danger'],
        [s.no_outgoing, __('No outgoing links', 'cherrylink'), __('Posts that link nowhere. Each one is an easy place to add links.', 'cherrylink'), 'warn'],
    ];
    return (
        <Card className="cl-card cl-wide">
            <div className="cl-stack">
                <h1>{__('Your site is ready', 'cherrylink')}</h1>
                <p className="cl-muted">{sprintf(
                    /* translators: 1: posts learned (analyzed for suggestions; counts toward the plan limit), 2: posts checked (links read for link health; whole site, no limit) */
                    __('%1$s posts learned, %2$s posts checked. Here is what CherryLink found.', 'cherrylink'), num(state.counts.learned), num(s ? s.posts_checked ?? state.counts.checked : state.counts.checked))}</p>
            </div>
            {(err || error) && <Notice tone="danger">{err || error}</Notice>}
            {busy && <p className="cl-muted">{__('Reading your links…', 'cherrylink')}</p>}
            {cards && (
                <div className="cl-grid3">
                    {cards.map(([n, h, t, tone]) => (
                        <div className="cl-tile" key={h}><span className={`cl-big cl-${tone}`}>{num(n)}</span><strong>{h}</strong><span className="cl-muted">{t}</span></div>
                    ))}
                </div>
            )}
            <div className="cl-row">
                <Btn onClick={() => finish(null)}>{__('Go to dashboard', 'cherrylink')}</Btn>
                <button type="button" className="cl-textbtn" onClick={() => finish(urls.links)}>{__('Review link issues', 'cherrylink')}</button>
            </div>
        </Card>
    );
}

export default function Wizard() {
    const { state } = useAdmin();
    const [done, setDone] = useState(false);
    const step = !state.connected ? 1 : done ? 3 : 2;
    return (
        <div className="cl-wizard">
            <div className="cl-row cl-center"><Mark size={24} /><strong>CherryLink</strong></div>
            <Steps step={step} />
            {step === 1 && <Connect />}
            {step === 2 && <Analyze onDone={() => setDone(true)} />}
            {step === 3 && <Done />}
        </div>
    );
}
