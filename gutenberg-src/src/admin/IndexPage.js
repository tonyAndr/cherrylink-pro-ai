import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useAdmin, useSync } from './ctx';
import { Progress } from './Wizard';
import { Btn, Card, Confirm, Link, Notice, Title, num } from './ui';

export default function IndexPage() {
    const { api, state, setState, urls } = useAdmin();
    const { sync, error, start, cancel } = useSync();
    const { counts, license } = state;
    const limit = license.post_limit;
    const types = counts.types || [];
    const [sel, setSel] = useState(types.filter((t) => t.enabled).map((t) => t.name));
    const [fields, setFields] = useState(state.settings.vector_custom_fields || '');
    const [msg, setMsg] = useState({ tone: '', text: '' });
    const [confirm, setConfirm] = useState(false);
    const [busy, setBusy] = useState(false);

    const call = async (action, data, ok) => {
        setBusy(true);
        try {
            const r = await api(action, data);
            if (r.state) setState(r.state);
            setMsg({ tone: 'ok', text: ok });
        } catch (e) {
            setMsg({ tone: 'danger', text: e.message });
        }
        setBusy(false);
        setConfirm(false);
    };
    const toggle = (n) => setSel(sel.includes(n) ? sel.filter((x) => x !== n) : [...sel, n]);
    const running = sync.status === 'running';
    const status = running ? __('Scanning…', 'cherrylink')
        : sync.status === 'error' ? __('Error', 'cherrylink')
        : sync.status === 'cancelled' ? __('Stopped', 'cherrylink')
        : sync.status === 'done' ? __('Up to date', 'cherrylink') : __('Ready', 'cherrylink');

    return (
        <>
            <Title>{__('Index', 'cherrylink')}</Title>
            {msg.text && <Notice tone={msg.tone === 'ok' ? 'success' : 'danger'}>{msg.text}</Notice>}

            <Card aria-label={__('What is indexed', 'cherrylink')}>
                <h2>{__('What CherryLink knows about your site', 'cherrylink')}</h2>
                <div className="cl-grid3">
                    <div className="cl-tile"><span className="cl-big">{num(counts.total)}</span><strong>{__('posts on the site', 'cherrylink')}</strong><span className="cl-muted">{__('Published posts of the content types you chose', 'cherrylink')}</span></div>
                    <div className="cl-tile"><span className="cl-big">{num(counts.learned)}</span><strong>{__('learned for suggestions', 'cherrylink')}</strong><span className="cl-muted">{limit ? sprintf(
                        /* translators: %s: plan limit of learned posts */
                        __('Free limit: newest %s by last update', 'cherrylink'), num(limit)) : __('No limit on your plan', 'cherrylink')}</span></div>
                    <div className="cl-tile"><span className="cl-big">{num(counts.checked)}</span><strong>{__('checked for link health', 'cherrylink')}</strong><span className="cl-muted">{__('Always the whole site', 'cherrylink')}</span></div>
                </div>
                <p className="cl-muted">{__('Learned = analysed for suggestions; counts toward the plan limit. Checked = links read for link health; whole site, no limit.', 'cherrylink')}</p>
                {limit && (
                    <div className="cl-accent-box cl-between">
                        <div className="cl-stack">
                            <strong>{sprintf(
                                /* translators: %s: plan limit of learned posts */
                                __('Why only %s?', 'cherrylink'), num(limit))}</strong>
                            <span>{__('Learning a post means computing what it is about on our servers, which costs real computing time. The free plan covers the posts you updated most recently, which is usually where links matter most. Posts beyond the limit keep link health and their own suggestions, but other posts won\'t suggest links to them. When you update an older post, it takes the place of the oldest learned one.', 'cherrylink')}</span>
                        </div>
                        <Link kind="btn" href={urls.upgrade} target="_blank" rel="noreferrer">{__('Upgrade to Pro', 'cherrylink')}</Link>
                    </div>
                )}
            </Card>

            <Card aria-label={__('Sync', 'cherrylink')}>
                <div className="cl-between"><h2>{__('Sync', 'cherrylink')}</h2><span className="cl-muted">● {status}</span></div>
                <p className="cl-muted">{__('Posts are updated automatically when you save, publish or delete them. Run a scan after importing content or changing what is indexed. Only changed posts are sent.', 'cherrylink')}</p>
                {(error || sync.status === 'error') && <Notice tone="danger">{error || sync.last_error || sync.message}</Notice>}
                {running && <Progress sync={sync} />}
                <div className="cl-row">
                    {running
                        ? <Btn kind="outline" onClick={cancel}>{__('Stop', 'cherrylink')}</Btn>
                        : <Btn onClick={start}>{__('Scan site now', 'cherrylink')}</Btn>}
                    {sync.status === 'done' && sync.updated_gmt && <span className="cl-muted">{sprintf(
                        /* translators: 1: date and time (UTC), 2: posts updated, 3: posts removed */
                        __('Last scan: %1$s UTC · %2$s posts updated · %3$s removed', 'cherrylink'), sync.updated_gmt, num(sync.upsert_total), num(sync.delete_total))}</span>}
                </div>
            </Card>

            <Card aria-label={__('What to index', 'cherrylink')}>
                <fieldset className="cl-fieldset">
                    <legend>{__('Content types', 'cherrylink')}</legend>
                    {types.map((t) => <label key={t.name}><input type="checkbox" checked={sel.includes(t.name)} onChange={() => toggle(t.name)} />{t.label}</label>)}
                </fieldset>
                <div className="cl-stack">
                    <label htmlFor="cl-cf"><strong>{__('Extra fields to include', 'cherrylink')}</strong></label>
                    <span className="cl-muted">{__('Custom field keys (ACF, meta) added to the post text, one per line.', 'cherrylink')}</span>
                    <textarea id="cl-cf" className="cl-input" rows={3} value={fields} onChange={(e) => setFields(e.target.value)} />
                    <div><Btn disabled={busy} onClick={() => call('cherrylink_admin_save_settings', { settings: { post_types: sel, vector_custom_fields: fields } }, __('Saved. Run a scan to apply the change.', 'cherrylink'))}>{__('Save', 'cherrylink')}</Btn></div>
                </div>
            </Card>

            <Card aria-label={__('Remove data', 'cherrylink')} className="cl-card cl-between cl-danger-zone">
                <div className="cl-stack">
                    <strong>{__('Remove site data from CherryLink servers', 'cherrylink')}</strong>
                    <span className="cl-muted">{__('Deletes the learned posts and the link map for this site. Links already in your posts are not touched. You can scan again at any time.', 'cherrylink')}</span>
                </div>
                <Btn kind="danger" onClick={() => setConfirm(true)}>{__('Remove data', 'cherrylink')}</Btn>
            </Card>
            {confirm && <Confirm title={__('Remove site data?', 'cherrylink')} text={__('This deletes the learned posts and link map for this site from CherryLink servers. Your posts are not changed.', 'cherrylink')}
                action={__('Remove data', 'cherrylink')} busy={busy} onCancel={() => setConfirm(false)}
                onConfirm={() => call('cherrylink_admin_remove_data', {}, __('Site data removed.', 'cherrylink'))} />}
        </>
    );
}
