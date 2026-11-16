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
                placeholder={__('Find related posts…', 'cherrylink-ai')}
                aria-label={__('Find related posts', 'cherrylink-ai')}
                value={query}
                onChange={(e) => onQuery(e.target.value)}
            />
            {showCatFilter && categories.length > 0 && (
                <select className="cl-search" value={category} onChange={(e) => onCategory(e.target.value)} aria-label={__('Category', 'cherrylink-ai')}>
                    <option value="">{__('All categories', 'cherrylink-ai')}</option>
                    {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
            )}
            <p className="cl-hint">{__('Select text in the post and click “Insert”, or pick a ready-made phrase.', 'cherrylink-ai')}</p>
            {status === 'error' && (
                <div className="cl-empty">
                    <p>{error}</p>
                    <button type="button" className="cl-btn" onClick={onRetry}>{__('Retry', 'cherrylink-ai')}</button>
                </div>
            )}
            {status === 'loading' && !rows.length && <Skeleton />}
            {status === 'ready' && !rows.length && <div className="cl-empty">{__('Nothing found.', 'cherrylink-ai')}</div>}
            {rows.map((r) => {
                const linked = isRowLinked(r, links);
                return (
                    <div className="cl-row" key={r.post_id}>
                        <div>
                            <div className="cl-t">{r.title}{r.is_key && <KeyBadge />}</div>
                            <div className="cl-meta">↑ {r.out} {__('out', 'cherrylink-ai')} · ↓ {r.in} {__('in', 'cherrylink-ai')}{r.match === 'text' && <> · {__('word found in the text', 'cherrylink-ai')}</>}</div>
                        </div>
                        <div className="cl-rowside">
                            <Rel score={r.score} top={top} />
                            <div className="cl-rowact">
                            {linked ? (
                                <span className="cl-linked">✓ {__('link exists', 'cherrylink-ai')}</span>
                            ) : (
                                <button type="button" className="cl-btn" title={__('Turn the selected text into a link to this post', 'cherrylink-ai')} onMouseDown={keepSelection} onClick={() => onHere(r)}>{__('Insert', 'cherrylink-ai')}</button>
                            )}
                            {r.view_url && (
                                <a className="cl-iconlink" href={r.view_url} target="_blank" rel="noopener noreferrer" title={__('Open', 'cherrylink-ai')} aria-label={__('Open', 'cherrylink-ai')}><IconExternal /></a>
                            )}
                            {r.edit_url && (
                                <a className="cl-iconlink" href={r.edit_url} target="_blank" rel="noopener noreferrer" title={__('Edit', 'cherrylink-ai')} aria-label={__('Edit', 'cherrylink-ai')}><IconEdit /></a>
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
                <button type="button" className="cl-btn" onClick={onMore} disabled={status === 'loading'}>{__('Show more', 'cherrylink-ai')}</button>
            )}
        </>
    );
}

/** Marks a suggestion that is a key (hub) page of its topic. */
export const KeyBadge = () => <span className="cl-keybadge">{__('Key page', 'cherrylink-ai')}</span>;

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
        <span className={`cl-rel cl-rel-${tier}`} title={sprintf(__('Relevance %1$d%% (similarity %2$s)', 'cherrylink-ai'), pct, score.toFixed(3))}>
            <b aria-hidden="true"><i style={{ width: `${pct}%` }} /></b>
            {pct}%
        </span>
    );
}
