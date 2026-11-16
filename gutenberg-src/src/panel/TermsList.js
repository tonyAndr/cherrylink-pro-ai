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
                    <span className="cl-linked">✓ {__('link exists', 'cherrylink-ai')}</span>
                ) : (
                    <button type="button" className="cl-btn" title={__('Turn the selected text into a link to this category', 'cherrylink-ai')} onMouseDown={keepSelection} onClick={() => onHere({ ...term, title: name })}>{__('Insert', 'cherrylink-ai')}</button>
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
                <button type="button" className="cl-btn" onClick={onRetry}>{__('Retry', 'cherrylink-ai')}</button>
            </div>
        );
    }
    if (status !== 'ready') return <Skeleton />;
    if (!terms.length) return <div className="cl-empty">{__('No categories found', 'cherrylink-ai')}</div>;
    return (
        <>
            {terms.map((t, i) =>
                (t.is_divider === true || t.is_divider === 'yes') ? (
                    <div key={`d${i}`} className="cl-section-label">{t.title || t.name} {__('on the site', 'cherrylink-ai')}</div>
                ) : (
                    <Term key={`${t.taxonomy || ''}${t.name || t.title}${i}`} term={t} depth={0} links={links} onHere={onHere} />
                )
            )}
        </>
    );
}
