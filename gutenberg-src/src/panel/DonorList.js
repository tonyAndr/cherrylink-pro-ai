import { __ } from '@wordpress/i18n';
import Context from './Context';
import { IconExternal } from './icons';
import { Rel } from './PostList';
import { topScore } from './opportunities';

export default function DonorList({ donors, status, error, done, busy, targetId, onInsert, onUndo, onRetry }) {
    return (
        <>
            <p className="cl-hint">{__('Posts that could link to this one. “Insert link” adds it directly to that post, without opening the editor.', 'cherrylink')}</p>
            {status === 'error' && (
                <div className="cl-empty">
                    <p>{error}</p>
                    <button type="button" className="cl-btn" onClick={onRetry}>{__('Retry', 'cherrylink')}</button>
                </div>
            )}
            {status === 'loading' && !donors.length && <div className="cl-skel" aria-hidden="true" />}
            {status === 'ready' && !donors.length && <div className="cl-empty">{__('No suitable posts found.', 'cherrylink')}</div>}
            {donors.map((d) => {
                const isDone = !!done[d.donor_id];
                const openUrl = d.edit_url ? `${d.edit_url}${d.edit_url.indexOf('?') > -1 ? '&' : '?'}cherrylink_target=${encodeURIComponent(targetId)}` : '';
                const can = d.can_edit !== false && !d.locked_by;
                return (
                    <div className="cl-card" key={d.donor_id}>
                        <div className="cl-target"><span className="cl-arrow">←</span><span className="cl-t">{d.title}</span><Rel score={d.score} top={topScore(donors)} /></div>
                        {d.suggestion ? (
                            <Context text={d.suggestion.context} link={isDone} />
                        ) : (
                            <p className="cl-hint">{__('No suitable phrase found. Open the post and select the text manually.', 'cherrylink')}</p>
                        )}
                        {!can && d.suggestion && (
                            <p className="cl-hint">
                                {d.locked_by
                                    ? `${__('Currently being edited by', 'cherrylink')} ${d.locked_by}`
                                    : __('You do not have permission to edit this post.', 'cherrylink')}
                            </p>
                        )}
                        <div className="cl-actions">
                            {isDone ? (
                                <>
                                    <span className="cl-linked">✓ {__('Link added', 'cherrylink')}</span>
                                    <button type="button" className="cl-btn cl-ghost" disabled={busy[d.donor_id]} onClick={() => onUndo(d)}>{__('Undo', 'cherrylink')}</button>
                                </>
                            ) : (
                                d.suggestion && can && (
                                    <button type="button" className="cl-btn cl-primary" disabled={busy[d.donor_id]} onClick={() => onInsert(d)}>{__('Insert link', 'cherrylink')}</button>
                                )
                            )}
                            {openUrl && (
                                <a className="cl-btn cl-ghost" href={openUrl} target="_blank" rel="noopener noreferrer" title={__('Open in the editor in a new tab', 'cherrylink')}>
                                    {__('Open', 'cherrylink')} <IconExternal />
                                </a>
                            )}
                            <span className="cl-meta">↓ {d.in} {__('in', 'cherrylink')}</span>
                        </div>
                    </div>
                );
            })}
        </>
    );
}
