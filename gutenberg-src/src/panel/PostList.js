import { __, sprintf } from '@wordpress/i18n';
import { IconExternal, IconEdit } from './icons';
import { isRowLinked, relevance, topScore } from './opportunities';

const keepSelection = (e) => e.preventDefault();

export default function PostList({
    rows, links, status, error, hasMore, query, category, categories, showCatFilter,
    onQuery, onCategory, onHere, onPill, onMore, onRetry,
}) {
    const top = topScore(rows);
    return (
        <>
            <input
                className="cl-search"
                type="search"
                placeholder={__('Найти статьи по теме…', 'cherrylink')}
                aria-label={__('Найти статьи по теме', 'cherrylink')}
                value={query}
                onChange={(e) => onQuery(e.target.value)}
            />
            {showCatFilter && categories.length > 0 && (
                <select className="cl-search" value={category} onChange={(e) => onCategory(e.target.value)} aria-label={__('Рубрика', 'cherrylink')}>
                    <option value="">{__('Все рубрики', 'cherrylink')}</option>
                    {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
            )}
            <p className="cl-hint">{__('Выделите текст в статье и нажмите «Вставить» или выберите готовую фразу.', 'cherrylink')}</p>
            {status === 'error' && (
                <div className="cl-empty">
                    <p>{error}</p>
                    <button type="button" className="cl-btn" onClick={onRetry}>{__('Повторить', 'cherrylink')}</button>
                </div>
            )}
            {status === 'loading' && !rows.length && <Skeleton />}
            {status === 'ready' && !rows.length && <div className="cl-empty">{__('Ничего не найдено.', 'cherrylink')}</div>}
            {rows.map((r) => {
                const linked = isRowLinked(r, links);
                return (
                    <div className="cl-row" key={r.post_id}>
                        <div>
                            <div className="cl-t">{r.title}</div>
                            <div className="cl-meta">↑ {r.out} {__('исх', 'cherrylink')} · ↓ {r.in} {__('вх', 'cherrylink')}</div>
                        </div>
                        <div className="cl-rowside">
                            <Rel score={r.score} top={top} />
                            <div className="cl-rowact">
                            {linked ? (
                                <span className="cl-linked">✓ {__('ссылка есть', 'cherrylink')}</span>
                            ) : (
                                <button type="button" className="cl-btn" title={__('Сделать выделенный текст ссылкой на эту статью', 'cherrylink')} onMouseDown={keepSelection} onClick={() => onHere(r)}>{__('Вставить', 'cherrylink')}</button>
                            )}
                            {r.view_url && (
                                <a className="cl-iconlink" href={r.view_url} target="_blank" rel="noopener noreferrer" title={__('Открыть', 'cherrylink')} aria-label={__('Открыть', 'cherrylink')}><IconExternal /></a>
                            )}
                            {r.edit_url && (
                                <a className="cl-iconlink" href={r.edit_url} target="_blank" rel="noopener noreferrer" title={__('Редактировать', 'cherrylink')} aria-label={__('Редактировать', 'cherrylink')}><IconEdit /></a>
                            )}
                            </div>
                        </div>
                        {!linked && (r.suggestions || []).length > 0 && (
                            <div className="cl-anchors">
                                {r.suggestions.slice(0, 3).map((s) => (
                                    <button type="button" key={s.phrase} className="cl-pill" onClick={() => onPill(r, s)}>{s.phrase}</button>
                                ))}
                            </div>
                        )}
                    </div>
                );
            })}
            {hasMore && status !== 'error' && (
                <button type="button" className="cl-btn" onClick={onMore} disabled={status === 'loading'}>{__('Показать ещё', 'cherrylink')}</button>
            )}
        </>
    );
}

export function Skeleton() {
    return (
        <>
            {[0, 1, 2].map((i) => <div key={i} className="cl-skel" aria-hidden="true" />)}
        </>
    );
}

/** Small relevance meter; renders nothing without a score. */
export function Rel({ score, top }) {
    const pct = relevance(score, top);
    if (pct === null) return null;
    const tier = pct >= 60 ? 'hi' : pct >= 30 ? 'mid' : 'lo';
    return (
        <span className={`cl-rel cl-rel-${tier}`} title={sprintf(__('Релевантность %1$d%% (сходство %2$s)', 'cherrylink'), pct, score.toFixed(3))}>
            <b aria-hidden="true"><i style={{ width: `${pct}%` }} /></b>
            {pct}%
        </span>
    );
}
