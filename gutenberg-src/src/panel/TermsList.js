import { __ } from '@wordpress/i18n';
import { Skeleton } from './PostList';
import { isRowLinked } from './opportunities';

const keepSelection = (e) => e.preventDefault();

function Term({ term, depth, links, onHere }) {
    const name = term.name || term.title;
    const linked = isRowLinked({ url: term.url }, links);
    return (
        <>
            <div className="cl-row cl-term" style={{ marginLeft: depth * 12 }}>
                <div className="cl-t">{name}</div>
                {linked ? (
                    <span className="cl-linked">✓ {__('ссылка есть', 'cherrylink')}</span>
                ) : (
                    <button type="button" className="cl-btn" onMouseDown={keepSelection} onClick={() => onHere({ ...term, title: name })}>{__('Сюда', 'cherrylink')}</button>
                )}
            </div>
            {(term.children || []).map((c, i) => (
                <Term key={`${c.name || c.title}${i}`} term={c} depth={depth + 1} links={links} onHere={onHere} />
            ))}
        </>
    );
}

export default function TermsList({ terms, status, error, links, onHere, onRetry }) {
    if (status === 'error') {
        return (
            <div className="cl-empty">
                <p>{error}</p>
                <button type="button" className="cl-btn" onClick={onRetry}>{__('Повторить', 'cherrylink')}</button>
            </div>
        );
    }
    if (status !== 'ready') return <Skeleton />;
    if (!terms.length) return <div className="cl-empty">{__('Рубрики не найдены', 'cherrylink')}</div>;
    return (
        <>
            {terms.map((t, i) =>
                (t.is_divider === true || t.is_divider === 'yes') ? (
                    <div key={`d${i}`} className="cl-section-label">{t.title || t.name} {__('на сайте', 'cherrylink')}</div>
                ) : (
                    <Term key={`${t.taxonomy || ''}${t.name || t.title}${i}`} term={t} depth={0} links={links} onHere={onHere} />
                )
            )}
        </>
    );
}
