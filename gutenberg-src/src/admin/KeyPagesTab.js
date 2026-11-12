import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Badge, Btn, num } from './ui';

export const KEY_HELP = __('Key pages are the main page of a topic. CherryLink finds them automatically from what your posts are about; the other posts of the topic should link to them. On the free plan only learned posts count.', 'cherrylink');

/** "Key pages" tab of Link health: hub pages, their group, and who still misses the link. */
export default function KeyPagesTab({ keys, busyId, onPin }) {
    const [open, setOpen] = useState({});
    const pages = (keys && keys.key_pages) || [];
    const demoted = (keys && keys.demoted) || [];
    return (
        <>
            <p className="cl-muted">{KEY_HELP}</p>
            {!pages.length && <p className="cl-muted">{__('No key pages found yet. They appear when several posts cover the same topic.', 'cherrylink')}</p>}
            {pages.length > 0 && (
                <div className="cl-tablewrap">
                    <table className="cl-table">
                        <thead><tr>
                            <th>{__('Key page', 'cherrylink')}</th><th>{__('Group size', 'cherrylink')}</th><th>{__('Incoming links', 'cherrylink')}</th>
                            <th>{__('Not linking yet', 'cherrylink')}</th><th><span className="screen-reader-text">{__('Actions', 'cherrylink')}</span></th>
                        </tr></thead>
                        <tbody>
                            {pages.map((p) => {
                                const missing = (p.members || []).filter((m) => !m.links_to_key);
                                const isOpen = !!open[p.post_id];
                                return (
                                    <tr key={p.post_id}>
                                        <td>
                                            <a className="cl-link" href={p.edit_url}>{p.title || __('(no title)', 'cherrylink')}</a>{' '}
                                            {p.pinned && <Badge tone="accent">{__('Pinned', 'cherrylink')}</Badge>}{' '}
                                            {p.underlinked && <Badge tone="danger">{__('Underlinked', 'cherrylink')}</Badge>}
                                        </td>
                                        <td>{num((p.members || []).length + 1)}</td>
                                        <td>{num(p.inbound)}</td>
                                        <td>
                                            {missing.length > 0 ? (
                                                <button type="button" className="cl-textbtn" aria-expanded={isOpen} onClick={() => setOpen((o) => ({ ...o, [p.post_id]: !isOpen }))}>
                                                    {sprintf(_n('%d post', '%d posts', missing.length, 'cherrylink'), missing.length)}
                                                </button>
                                            ) : <span className="cl-muted">{__('All link here', 'cherrylink')}</span>}
                                            {isOpen && (
                                                <ul className="cl-donors">
                                                    {missing.map((m) => (
                                                        <li key={m.post_id}>{m.title} <a className="cl-link" href={m.edit_url}>{__('Edit', 'cherrylink')}</a></li>
                                                    ))}
                                                </ul>
                                            )}
                                        </td>
                                        <td>
                                            <span className="cl-row">
                                                {p.pinned
                                                    ? <Btn kind="outline" disabled={busyId === p.post_id} onClick={() => onPin(p.post_id, 'clear')}>{__('Unpin', 'cherrylink')}</Btn>
                                                    : <Btn kind="outline" disabled={busyId === p.post_id} onClick={() => onPin(p.post_id, 'pin')}>{__('Pin', 'cherrylink')}</Btn>}
                                                {!p.pinned && <Btn kind="ghost" disabled={busyId === p.post_id} onClick={() => onPin(p.post_id, 'demote')}>{__('Demote', 'cherrylink')}</Btn>}
                                            </span>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
            {demoted.length > 0 && (
                <>
                    <h2 className="cl-eyebrow">{__('Demoted pages', 'cherrylink')}</h2>
                    <ul className="cl-donors">
                        {demoted.map((d) => (
                            <li key={d.post_id}>{d.title}{' '}
                                <button type="button" className="cl-textbtn" disabled={busyId === d.post_id} onClick={() => onPin(d.post_id, 'clear')}>{__('Restore', 'cherrylink')}</button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </>
    );
}
