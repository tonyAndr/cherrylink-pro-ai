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
                __('%1$s (%2$s hidden)', 'cherrylink'), legend, set.size)}</summary>
            <div className="cl-checklist">
                {items.map((i) => (
                    <label key={i.id} style={{ paddingLeft: (i.level || 0) * 14 }}><input type="checkbox" checked={set.has(Number(i.id))} onChange={() => flip(Number(i.id))} />{i.name}</label>
                ))}
            </div>
        </details>
    );
}

async function waitLicense(api, setState) {
    for (let i = 0; i < 15; i += 1) {
        await new Promise((r) => setTimeout(r, 2000));
        const s = await api('cherrylink_admin_state');
        setState(s);
        if (s.license.status !== 'pending') return;
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
    const { license, route } = state;
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
    const save = () => run(() => post('cherrylink_admin_save_settings', { settings: s }), __('Settings saved.', 'cherrylink'));
    const changeKey = () => run(async () => {
        await post('cherrylink_admin_license', { op: 'set_key', key: newKey });
        setNewKey(null);
        await waitLicense(api, setState);
    });
    const reset = () => run(async () => { await post('cherrylink_admin_reset_settings'); onReload(); }, __('Settings reset to defaults.', 'cherrylink'));
    const disconnect = () => run(() => post('cherrylink_admin_license', { op: 'disconnect' }));
    const doImport = () => run(async () => {
        const f = file.current && file.current.files[0];
        if (!f) throw new Error(__('Choose a file first.', 'cherrylink'));
        await post('cherrylink_admin_import_settings', { file: f });
        onReload();
    }, __('Settings imported.', 'cherrylink'));
    const check = () => run(async () => { setConn(await api('cherrylink_server_status')); });

    const probes = conn && conn.probes;
    const connText = !conn ? '' : conn.pending ? __('A check is already running. Try again in a moment.', 'cherrylink')
        : probes && probes.health && probes.health.ok ? __('Connection is working.', 'cherrylink') : __('Could not reach the CherryLink server.', 'cherrylink');
    const licText = license.status === 'pending' ? __('checking…', 'cherrylink') : license.status === 'active' ? __('connected', 'cherrylink') : license.status === 'error' ? __('problem', 'cherrylink') : __('not connected', 'cherrylink');

    return (
        <>
            <nav className="cl-subnav" aria-label={__('Settings sections', 'cherrylink')}>
                {[['lic', __('License', 'cherrylink')], ['ed', __('Editor panel', 'cherrylink')], ['mk', __('Link markup', 'cherrylink')], ['pv', __('Privacy', 'cherrylink')], ['io', __('Import / export', 'cherrylink')], ['db', __('Troubleshooting', 'cherrylink')]]
                    .map(([id, l]) => <a key={id} href={`#cl-${id}`}>{l}</a>)}
            </nav>
            {msg.text && <Notice tone={msg.tone}>{msg.text}</Notice>}

            <Card id="cl-lic">
                <h2>{__('License', 'cherrylink')}</h2>
                <div className="cl-row"><Badge tone={license.status === 'error' ? 'danger' : 'ok'}>{planName(license.plan)} · {licText}</Badge>
                    <span className="cl-muted">{[
                        license.key_masked && sprintf(
                            /* translators: %s: masked license key */
                            __('Key %s', 'cherrylink'), license.key_masked),
                        license.domain && sprintf(
                            /* translators: %s: site domain */
                            __('bound to %s', 'cherrylink'), license.domain),
                        license.expires_at ? sprintf(
                            /* translators: %s: expiry date */
                            __('expires %s', 'cherrylink'), license.expires_at) : __('never expires', 'cherrylink'),
                    ].filter(Boolean).join(' · ')}</span></div>
                {license.error && <Notice tone="danger">{license.error}</Notice>}
                {license.post_limit && state.counts.total > license.post_limit && (
                    <p className="cl-muted">{sprintf(
                        /* translators: 1: learned posts, 2: all posts. "Learned" = post analyzed for suggestions; counts toward the plan limit. */
                        __('%1$s of %2$s posts learned. Posts beyond the limit keep link health and their own suggestions, but other posts won\'t suggest links to them.', 'cherrylink'),
                        (state.counts.learned ?? license.post_limit).toLocaleString(), state.counts.total.toLocaleString())}</p>
                )}
                <div className="cl-row">
                    {license.plan === 'free' && <Link href={urls.upgrade} target="_blank" rel="noreferrer">{__('Upgrade to Pro', 'cherrylink')}</Link>}
                    <button type="button" className="cl-textbtn" onClick={() => setNewKey(newKey === null ? '' : null)}>{__('Use a different key', 'cherrylink')}</button>
                    <button type="button" className="cl-textbtn" onClick={() => setModal('disconnect')}>{__('Disconnect site', 'cherrylink')}</button>
                </div>
                {newKey !== null && (
                    <div className="cl-row">
                        <label htmlFor="cl-newkey" className="screen-reader-text">{__('License key', 'cherrylink')}</label>
                        <input id="cl-newkey" className="cl-input cl-grow" placeholder="clp_…" value={newKey} onChange={(e) => setNewKey(e.target.value)} />
                        <Btn kind="outline" disabled={busy || !newKey.trim()} onClick={changeKey}>{__('Use this key', 'cherrylink')}</Btn>
                    </div>
                )}
            </Card>

            <Card id="cl-ed">
                <h2>{__('Editor panel', 'cherrylink')}</h2>
                <div className="cl-form">
                    <label htmlFor="cl-limit">{__('Posts per page', 'cherrylink')}</label>
                    <input id="cl-limit" type="number" min={1} max={100} className="cl-input" value={s.limit_ajax ?? ''} onChange={(e) => up('limit_ajax')(e.target.value === '' ? '' : Number(e.target.value))} />
                    <span>{__('Category filter', 'cherrylink')}</span>
                    <div className="cl-stack">
                        <Check id="cl-scf" checked={s.show_cat_filter} onChange={up('show_cat_filter')}>{__('Show a category filter in the panel', 'cherrylink')}</Check>
                        <Check id="cl-mc" checked={s.match_cat} onChange={up('match_cat')}>{__('Suggest only posts from the same category', 'cherrylink')}</Check>
                    </div>
                    <span>{__('Hide from suggestions', 'cherrylink')}</span>
                    <div className="cl-stack">
                        <Picker legend={__('Authors', 'cherrylink')} items={choices.authors} value={s.excluded_authors} onChange={up('excluded_authors')} />
                        <Picker legend={__('Categories', 'cherrylink')} items={choices.categories} value={s.excluded_cats} onChange={up('excluded_cats')} />
                    </div>
                </div>
            </Card>

            <Card id="cl-mk">
                <h2>{__('Link markup', 'cherrylink')}</h2>
                <span className="cl-muted">{__('Applies to links you insert from now on. Links already in your posts are not changed.', 'cherrylink')}</span>
                <div className="cl-form">
                    <label htmlFor="cl-uf">{__('URL format', 'cherrylink')}</label>
                    <select id="cl-uf" className="cl-input" value={s.relative_links || 'full'} onChange={(e) => up('relative_links')(e.target.value)}>
                        <option value="full">{__('Full (https://example.com/page/)', 'cherrylink')}</option>
                        <option value="no_proto">{__('Without protocol (//example.com/page/)', 'cherrylink')}</option>
                        <option value="no_domain">{__('Relative (/page/)', 'cherrylink')}</option>
                    </select>
                    <span>{__('Title attribute', 'cherrylink')}</span>
                    <Check id="cl-ta" checked={s.link_title_attr} onChange={up('link_title_attr')}>{__('Add the target post title as a tooltip (title="…")', 'cherrylink')}</Check>
                    <span>{__('New tab', 'cherrylink')}</span>
                    <Check id="cl-nt" checked={s.link_new_tab} onChange={up('link_new_tab')}>{__('Open inserted links in a new tab (adds rel="noopener")', 'cherrylink')}</Check>
                    <label htmlFor="cl-cls">{__('CSS class', 'cherrylink')}</label>
                    <input id="cl-cls" className="cl-input" placeholder={__('e.g. related-link (optional)', 'cherrylink')} value={s.link_class || ''} onChange={(e) => up('link_class')(e.target.value)} />
                    <span>{__('Preview', 'cherrylink')}</span>
                    <code data-testid="preview">{previewTag(s, window.location.origin)}</code>
                </div>
            </Card>

            <Card id="cl-pv">
                <h2>{__('Privacy', 'cherrylink')}</h2>
                <span>{__('While connected, CherryLink receives your posts\' titles, text and URLs, plus plugin, PHP and WordPress versions for compatibility.', 'cherrylink')} <a className="cl-link" href={urls.privacy} target="_blank" rel="noreferrer">{__('Privacy Policy', 'cherrylink')}</a> · {__('To stop all data transfer, remove the site data on the Index page and disconnect.', 'cherrylink')}</span>
            </Card>

            <Card id="cl-io">
                <h2>{__('Import / export settings', 'cherrylink')}</h2>
                <div className="cl-row">
                    <a className="cl-btn cl-btn-outline" href={urls.exportSettings}>{__('Download settings', 'cherrylink')}</a>
                    <label htmlFor="cl-file" className="screen-reader-text">{__('Settings file', 'cherrylink')}</label>
                    <input id="cl-file" type="file" accept=".txt" ref={file} />
                    <Btn kind="outline" disabled={busy} onClick={doImport}>{__('Import file', 'cherrylink')}</Btn>
                </div>
            </Card>

            <Card id="cl-db">
                <h2>{__('Troubleshooting', 'cherrylink')}</h2>
                <span className="cl-muted">{route === 'relay'
                    ? __('Connection: backup route (the direct route was blocked)', 'cherrylink')
                    : __('Connection: direct · switches to a backup route automatically if blocked', 'cherrylink')}</span>
                <div className="cl-row">
                    <Btn kind="outline" disabled={busy} onClick={check}>{__('Check connection', 'cherrylink')}</Btn>
                    {connText && <span role="status">{connText}</span>}
                    <Btn kind="ghost" onClick={() => setModal('reset')}>{__('Reset settings', 'cherrylink')}</Btn>
                </div>
            </Card>

            <div><Btn className="cl-btn-lg" disabled={busy} onClick={save}>{__('Save changes', 'cherrylink')}</Btn></div>

            {modal === 'disconnect' && <Confirm title={__('Disconnect this site?', 'cherrylink')} text={__('Suggestions and link health stop until you connect again. Data already on CherryLink servers stays until you remove it on the Index page.', 'cherrylink')}
                action={__('Disconnect', 'cherrylink')} busy={busy} onCancel={() => setModal('')} onConfirm={disconnect} />}
            {modal === 'reset' && <Confirm title={__('Reset settings?', 'cherrylink')} text={__('All CherryLink settings return to their defaults. Your license stays connected.', 'cherrylink')}
                action={__('Reset settings', 'cherrylink')} busy={busy} onCancel={() => setModal('')} onConfirm={reset} />}
        </>
    );
}

export default function Settings() {
    const [n, setN] = useState(0);
    // Remount the form (fresh local copy of settings) after import/reset.
    return <><Title>{__('Settings', 'cherrylink')}</Title><Form key={n} onReload={() => setN(n + 1)} /></>;
}
