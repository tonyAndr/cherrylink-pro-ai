import { __ } from '@wordpress/i18n';
import { IconBack, IconExternal, IconEdit } from './icons';
import { normalizeUrl } from './opportunities';

export default function LinksView({ links, incoming, titleByUrl, section, onBack, onReveal }) {
    return (
        <>
            <button type="button" className="cl-btn cl-ghost cl-back" onClick={onBack}><IconBack /> {__('Back', 'cherrylink-ai')}</button>
            <div className="cl-section-label" id="cl-out">{__('Outgoing links', 'cherrylink-ai')} <span className="cl-n">{links.length}</span></div>
            {!links.length && <div className="cl-empty">{__('There are no links in the text yet.', 'cherrylink-ai')}</div>}
            {links.map((l, i) => (
                <button type="button" key={`${l.url}${i}`} className="cl-row cl-linkrow" onClick={() => onReveal(l.ref)}>
                    <div className="cl-minw">
                        <div className="cl-t cl-anchor-text">{l.anchor || l.url}</div>
                        <div className="cl-meta cl-clip">→ {titleByUrl[normalizeUrl(l.url)] || l.url}</div>
                    </div>
                </button>
            ))}
            <div className="cl-section-label" id="cl-in" ref={(el) => { if (el && section === 'in' && el.scrollIntoView) el.scrollIntoView({ block: 'start' }); }}>
                {__('Incoming links', 'cherrylink-ai')} <span className="cl-n">{incoming.length}</span>
            </div>
            {!incoming.length && <div className="cl-empty">{__('No other posts link to this one yet.', 'cherrylink-ai')}</div>}
            {incoming.map((s, i) => (
                <div className="cl-row" key={`${s.source_id}${i}`}>
                    <div className="cl-minw">
                        <div className="cl-t">{s.source_title}</div>
                        {s.anchor && <div className="cl-meta cl-clip">← <span className="cl-anchor-text">{s.anchor}</span></div>}
                    </div>
                    <div className="cl-rowact">
                        {s.source_url && <a className="cl-iconlink" href={s.source_url} target="_blank" rel="noopener noreferrer" aria-label={__('Open', 'cherrylink-ai')}><IconExternal /></a>}
                        {s.edit_url && <a className="cl-iconlink" href={s.edit_url} target="_blank" rel="noopener noreferrer" aria-label={__('Edit', 'cherrylink-ai')}><IconEdit /></a>}
                    </div>
                </div>
            ))}
        </>
    );
}
