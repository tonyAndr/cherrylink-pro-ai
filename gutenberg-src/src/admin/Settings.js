import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useAdmin } from './ctx';
import { planName } from './Dashboard';
import { Badge, Btn, Card, Confirm, Link, Notice, Title } from './ui';

export const previewTag = (s, origin = 'https://example.com') => {
    const path = '/bread-basics/';
    const href = s.relative_links === 'no_domain' ? path
        : s.relative_links === 'no_proto' ? origin.replace(/^https?:/, '') + path : origin + path;
    const cls = (s.link_class || '').trim();
    return `<a href="${href}"${s.link_title_attr ? ' title="Bread baking basics"' : ''}${s.link_new_tab ? ' target="_blank" rel="noopener"' : ''}${cls ? ` class="${cls}"` : ''}>sourdough bread</a>`;
};

const Check = ({ id, checked, onChange, children }) => (
    <label htmlFor={id}><input id={id} type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} />{children}</label>
);

function Picker({ legend, items, value, onChange }) {
    const set = new Set((value || []).map(Number));
    const flip = (id) => { set.has(id) ? set.delete(id) : set.add(id); onChange([...set]); };
    return (
        <details className="cl-details">
            <summary>{sprintf(
                /* translators: 1: list name (authors or categories), 2: how many are hidden */
                __('%1$s (%2$s hidden)', 'cherrylink-ai'), legend, set.size)}</summary>
            <div className="cl-checklist">
                {items.map((i) => (
                    <label key={i.id} style={{ paddingLeft: (i.level || 0) * 14 }}><input type="checkbox" checked={set.has(Number(i.id))} onChange={() => flip(Number(i.id))} />{i.name}</label>
                ))}
            </div>
        </details>
    );
}

async function waitConnection(api, setState) {
    for (let i = 0; i < 15; i += 1) {
        await new Promise((r) => setTimeout(r, 2000));
        const s = await api('cherrylink_admin_state');
        setState(s);
        if (s.connection.status !== 'pending') return;
    }
}

function Form({ onReload }) {
    const { api, state, setState, urls } = useAdmin();
    const [s, setS] = useState(state.settings);
    const [msg, setMsg] = useState({ tone: '', text: '' });
    const [modal, setModal] = useState('');
    const [busy, setBusy] = useState(false);
    const [newKey, setNewKey] = useState(null);
    const [conn, setConn] = useState(null);
    const file = useRef(null);
    const { connection, route } = state;
    const choices = state.settings_choices || { authors: [], categories: [] };
    const up = (k) => (v) => setS({ ...s, [k]: v });

    const run = async (fn, ok) => {
        setBusy(true);
        setMsg({ tone: '', text: '' });
        try {
            await fn();
            if (ok) setMsg({ tone: 'success', text: ok });
        } catch (e) {
            setMsg({ tone: 'danger', text: e.message });
        }
        setBusy(false);
        setModal('');
    };
    const post = async (action, data) => {
        const r = await api(action, data);
        if (r.state) setState(r.state);
        return r;
    };
    const save = () => run(() => post('cherrylink_admin_save_settings', { settings: s }), __('Settings saved.', 'cherrylink-ai'));
    const changeKey = () => run(async () => {
        await post('cherrylink_admin_connection', { op: 'set_key', key: newKey });
        setNewKey(null);
        await waitConnection(api, setState);
    });
    const reset = () => run(async () => { await post('cherrylink_admin_reset_settings'); onReload(); }, __('Settings reset to defaults.', 'cherrylink-ai'));
    const disconnect = () => run(() => post('cherrylink_admin_connection', { op: 'disconnect' }));
    const doImport = () => run(async () => {
        const f = file.current && file.current.files[0];
        if (!f) throw new Error(__('Choose a file first.', 'cherrylink-ai'));
        await post('cherrylink_admin_import_settings', { file: f });
        onReload();
    }, __('Settings imported.', 'cherrylink-ai'));
    const check = () => run(async () => { setConn(await api('cherrylink_server_status')); });

    const probes = conn && conn.probes;
    const connText = !conn ? '' : conn.pending ? __('A check is already running. Try again in a moment.', 'cherrylink-ai')
        : probes && probes.health && probes.health.ok ? __('Connection is working.', 'cherrylink-ai') : __('Could not reach the CherryLink server.', 'cherrylink-ai');
    const statusText = connection.status === 'pending' ? __('checking…', 'cherrylink-ai') : connection.status === 'active' ? __('connected', 'cherrylink-ai') : connection.status === 'error' ? __('problem', 'cherrylink-ai') : __('not connected', 'cherrylink-ai');

    return (
        <>
            <nav className="cl-subnav" aria-label={__('Settings sections', 'cherrylink-ai')}>
                {[['lic', __('Connection', 'cherrylink-ai')], ['ed', __('Editor panel', 'cherrylink-ai')], ['mk', __('Link markup', 'cherrylink-ai')], ['pv', __('Privacy', 'cherrylink-ai')], ['io', __('Import / export', 'cherrylink-ai')], ['db', __('Troubleshooting', 'cherrylink-ai')]]
                    .map(([id, l]) => <a key={id} href={`#cl-${id}`}>{l}</a>)}
            </nav>
            {msg.text && <Notice tone={msg.tone}>{msg.text}</Notice>}

            <Card id="cl-lic">
                <h2>{__('Connection', 'cherrylink-ai')}</h2>
                <div className="cl-row"><Badge tone={connection.status === 'error' ? 'danger' : 'ok'}>{planName(connection.plan)} · {statusText}</Badge>
                    <span className="cl-muted">{[
                        connection.key_masked && sprintf(
                            /* translators: %s: masked API key */
                            __('Key %s', 'cherrylink-ai'), connection.key_masked),
                        connection.domain && sprintf(
                            /* translators: %s: site domain */
                            __('bound to %s', 'cherrylink-ai'), connection.domain),
                        connection.expires_at ? sprintf(
                            /* translators: %s: expiry date */
                            __('expires %s', 'cherrylink-ai'), connection.expires_at) : __('never expires', 'cherrylink-ai'),
                    ].filter(Boolean).join(' · ')}</span></div>
                {connection.error && <Notice tone="danger">{connection.error}</Notice>}
                {connection.post_limit && state.counts.total > connection.post_limit && (
                    <p className="cl-muted">{sprintf(
                        /* translators: 1: learned posts, 2: all posts. "Learned" = post analyzed for suggestions; counts toward the plan limit. */
                        __('%1$s of %2$s posts learned. Posts beyond the limit keep link health and their own suggestions, but other posts won\'t suggest links to them.', 'cherrylink-ai'),
                        (state.counts.learned ?? connection.post_limit).toLocaleString(), state.counts.total.toLocaleString())}</p>
                )}
                <div className="cl-row">
                    {connection.plan === 'free' && <Link href={urls.upgrade} target="_blank" rel="noreferrer">{__('Upgrade to Pro', 'cherrylink-ai')}</Link>}
                    <button type="button" className="cl-textbtn" onClick={() => setNewKey(newKey === null ? '' : null)}>{__('Use a different key', 'cherrylink-ai')}</button>
                    <button type="button" className="cl-textbtn" onClick={() => setModal('disconnect')}>{__('Disconnect site', 'cherrylink-ai')}</button>
                </div>
                {newKey !== null && (
                    <div className="cl-row">
                        <label htmlFor="cl-newkey" className="screen-reader-text">{__('API key', 'cherrylink-ai')}</label>
                        <input id="cl-newkey" className="cl-input cl-grow" placeholder="clp_…" value={newKey} onChange={(e) => setNewKey(e.target.value)} />
                        <Btn kind="outline" disabled={busy || !newKey.trim()} onClick={changeKey}>{__('Use this key', 'cherrylink-ai')}</Btn>
                    </div>
                )}
            </Card>

            <Card id="cl-ed">
                <h2>{__('Editor panel', 'cherrylink-ai')}</h2>
                <div className="cl-form">
                    <label htmlFor="cl-limit">{__('Posts per page', 'cherrylink-ai')}</label>
                    <input id="cl-limit" type="number" min={1} max={100} className="cl-input" value={s.limit_ajax ?? ''} onChange={(e) => up('limit_ajax')(e.target.value === '' ? '' : Number(e.target.value))} />
                    <span>{__('Category filter', 'cherrylink-ai')}</span>
                    <div className="cl-stack">
                        <Check id="cl-scf" checked={s.show_cat_filter} onChange={up('show_cat_filter')}>{__('Show a category filter in the panel', 'cherrylink-ai')}</Check>
                        <Check id="cl-mc" checked={s.match_cat} onChange={up('match_cat')}>{__('Suggest only posts from the same category', 'cherrylink-ai')}</Check>
                    </div>
                    <span>{__('Hide from suggestions', 'cherrylink-ai')}</span>
                    <div className="cl-stack">
                        <Picker legend={__('Authors', 'cherrylink-ai')} items={choices.authors} value={s.excluded_authors} onChange={up('excluded_authors')} />
                        <Picker legend={__('Categories', 'cherrylink-ai')} items={choices.categories} value={s.excluded_cats} onChange={up('excluded_cats')} />
                    </div>
                </div>
            </Card>

            <Card id="cl-mk">
                <h2>{__('Link markup', 'cherrylink-ai')}</h2>
                <span className="cl-muted">{__('Applies to links you insert from now on. Links already in your posts are not changed.', 'cherrylink-ai')}</span>
                <div className="cl-form">
                    <label htmlFor="cl-uf">{__('URL format', 'cherrylink-ai')}</label>
                    <select id="cl-uf" className="cl-input" value={s.relative_links || 'full'} onChange={(e) => up('relative_links')(e.target.value)}>
                        <option value="full">{__('Full (https://example.com/page/)', 'cherrylink-ai')}</option>
                        <option value="no_proto">{__('Without protocol (//example.com/page/)', 'cherrylink-ai')}</option>
                        <option value="no_domain">{__('Relative (/page/)', 'cherrylink-ai')}</option>
                    </select>
                    <span>{__('Title attribute', 'cherrylink-ai')}</span>
                    <Check id="cl-ta" checked={s.link_title_attr} onChange={up('link_title_attr')}>{__('Add the target post title as a tooltip (title="…")', 'cherrylink-ai')}</Check>
                    <span>{__('New tab', 'cherrylink-ai')}</span>
                    <Check id="cl-nt" checked={s.link_new_tab} onChange={up('link_new_tab')}>{__('Open inserted links in a new tab (adds rel="noopener")', 'cherrylink-ai')}</Check>
                    <label htmlFor="cl-cls">{__('CSS class', 'cherrylink-ai')}</label>
                    <input id="cl-cls" className="cl-input" placeholder={__('e.g. related-link (optional)', 'cherrylink-ai')} value={s.link_class || ''} onChange={(e) => up('link_class')(e.target.value)} />
                    <span>{__('Preview', 'cherrylink-ai')}</span>
                    <code data-testid="preview">{previewTag(s, window.location.origin)}</code>
                </div>
            </Card>

            <Card id="cl-pv">
                <h2>{__('Privacy', 'cherrylink-ai')}</h2>
                <span>{__('While connected, CherryLink receives your posts\' titles, text and URLs.', 'cherrylink-ai')} <a className="cl-link" href={urls.privacy} target="_blank" rel="noreferrer">{__('Privacy Policy', 'cherrylink-ai')}</a> · {__('To stop all data transfer, remove the site data on the Index page and disconnect.', 'cherrylink-ai')}</span>
            </Card>

            <Card id="cl-io">
                <h2>{__('Import / export settings', 'cherrylink-ai')}</h2>
                <div className="cl-row">
                    <a className="cl-btn cl-btn-outline" href={urls.exportSettings}>{__('Download settings', 'cherrylink-ai')}</a>
                    <label htmlFor="cl-file" className="screen-reader-text">{__('Settings file', 'cherrylink-ai')}</label>
                    <input id="cl-file" type="file" accept=".txt" ref={file} />
                    <Btn kind="outline" disabled={busy} onClick={doImport}>{__('Import file', 'cherrylink-ai')}</Btn>
                </div>
            </Card>

            <Card id="cl-db">
                <h2>{__('Troubleshooting', 'cherrylink-ai')}</h2>
                <span className="cl-muted">{route === 'relay'
                    ? __('Connection: backup route (the direct route was blocked)', 'cherrylink-ai')
                    : __('Connection: direct · switches to a backup route automatically if blocked', 'cherrylink-ai')}</span>
                <div className="cl-row">
                    <Btn kind="outline" disabled={busy} onClick={check}>{__('Check connection', 'cherrylink-ai')}</Btn>
                    {connText && <span role="status">{connText}</span>}
                    <Btn kind="ghost" onClick={() => setModal('reset')}>{__('Reset settings', 'cherrylink-ai')}</Btn>
                </div>
            </Card>

            <div><Btn className="cl-btn-lg" disabled={busy} onClick={save}>{__('Save changes', 'cherrylink-ai')}</Btn></div>

            {modal === 'disconnect' && <Confirm title={__('Disconnect this site?', 'cherrylink-ai')} text={__('Suggestions and link health stop until you connect again. Data already on CherryLink servers stays until you remove it on the Index page.', 'cherrylink-ai')}
                action={__('Disconnect', 'cherrylink-ai')} busy={busy} onCancel={() => setModal('')} onConfirm={disconnect} />}
            {modal === 'reset' && <Confirm title={__('Reset settings?', 'cherrylink-ai')} text={__('All CherryLink settings return to their defaults. Your site stays connected.', 'cherrylink-ai')}
                action={__('Reset settings', 'cherrylink-ai')} busy={busy} onCancel={() => setModal('')} onConfirm={reset} />}
        </>
    );
}

export default function Settings() {
    const [n, setN] = useState(0);
    // Remount the form (fresh local copy of settings) after import/reset.
    return <><Title>{__('Settings', 'cherrylink-ai')}</Title><Form key={n} onReload={() => setN(n + 1)} /></>;
}
