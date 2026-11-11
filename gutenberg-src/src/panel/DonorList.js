import { __ } from '@wordpress/i18n';
import Context from './Context';
import { IconExternal } from './icons';
import { Rel } from './PostList';

export default function DonorList({ donors, status, error, done, busy, targetId, onInsert, onUndo, onRetry }) {
    return (
        <>
            <p className="cl-hint">{__('Статьи, которые могут ссылаться на эту. «Вставить ссылку» добавит её прямо в ту статью, без открытия редактора.', 'cherrylink')}</p>
            {status === 'error' && (
                <div className="cl-empty">
                    <p>{error}</p>
                    <button type="button" className="cl-btn" onClick={onRetry}>{__('Повторить', 'cherrylink')}</button>
                </div>
            )}
            {status === 'loading' && !donors.length && <div className="cl-skel" aria-hidden="true" />}
            {status === 'ready' && !donors.length && <div className="cl-empty">{__('Подходящих статей не нашлось.', 'cherrylink')}</div>}
            {donors.map((d) => {
                const isDone = !!done[d.donor_id];
                const openUrl = d.edit_url ? `${d.edit_url}${d.edit_url.indexOf('?') > -1 ? '&' : '?'}cherrylink_target=${encodeURIComponent(targetId)}` : '';
                const can = d.can_edit !== false && !d.locked_by;
                return (
                    <div className="cl-card" key={d.donor_id}>
                        <div className="cl-target"><span className="cl-arrow">←</span><span className="cl-t">{d.title}</span><Rel score={d.score} /></div>
                        {d.suggestion ? (
                            <Context text={d.suggestion.context} link={isDone} />
                        ) : (
                            <p className="cl-hint">{__('Подходящей фразы не нашлось. Откройте статью и выделите текст вручную.', 'cherrylink')}</p>
                        )}
                        {!can && d.suggestion && (
                            <p className="cl-hint">
                                {d.locked_by
                                    ? `${__('Статью сейчас редактирует', 'cherrylink')} ${d.locked_by}`
                                    : __('У вас нет прав на редактирование этой статьи.', 'cherrylink')}
                            </p>
                        )}
                        <div className="cl-actions">
                            {isDone ? (
                                <>
                                    <span className="cl-linked">✓ {__('Ссылка добавлена', 'cherrylink')}</span>
                                    <button type="button" className="cl-btn cl-ghost" disabled={busy[d.donor_id]} onClick={() => onUndo(d)}>{__('Отменить', 'cherrylink')}</button>
                                </>
                            ) : (
                                d.suggestion && can && (
                                    <button type="button" className="cl-btn cl-primary" disabled={busy[d.donor_id]} onClick={() => onInsert(d)}>{__('Вставить ссылку', 'cherrylink')}</button>
                                )
                            )}
                            {openUrl && (
                                <a className="cl-btn cl-ghost" href={openUrl} target="_blank" rel="noopener noreferrer" title={__('Открыть в редакторе в новой вкладке', 'cherrylink')}>
                                    {__('Открыть', 'cherrylink')} <IconExternal />
                                </a>
                            )}
                            <span className="cl-meta">↓ {d.in} {__('вх', 'cherrylink')}</span>
                        </div>
                    </div>
                );
            })}
        </>
    );
}
