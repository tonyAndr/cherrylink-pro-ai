import { __ } from '@wordpress/i18n';
import { IconBack, IconExternal, IconEdit } from './icons';
import { normalizeUrl } from './opportunities';

export default function LinksView({ links, incoming, titleByUrl, section, onBack, onReveal }) {
    return (
        <>
            <button type="button" className="cl-btn cl-ghost cl-back" onClick={onBack}><IconBack /> {__('Назад', 'cherrylink')}</button>
            <div className="cl-section-label" id="cl-out">{__('Исходящие', 'cherrylink')} ({links.length})</div>
            {!links.length && <div className="cl-empty">{__('В тексте пока нет ссылок.', 'cherrylink')}</div>}
            {links.map((l, i) => (
                <button type="button" key={`${l.url}${i}`} className="cl-row cl-linkrow" onClick={() => onReveal(l.ref)}>
                    <div>
                        <div className="cl-t">{l.anchor || l.url}</div>
                        <div className="cl-meta">{titleByUrl[normalizeUrl(l.url)] || l.url}</div>
                    </div>
                </button>
            ))}
            <div className="cl-section-label" id="cl-in" ref={(el) => { if (el && section === 'in' && el.scrollIntoView) el.scrollIntoView({ block: 'start' }); }}>
                {__('Входящие', 'cherrylink')} ({incoming.length})
            </div>
            {!incoming.length && <div className="cl-empty">{__('Другие статьи пока не ссылаются на эту.', 'cherrylink')}</div>}
            {incoming.map((s, i) => (
                <div className="cl-row" key={`${s.source_id}${i}`}>
                    <div>
                        <div className="cl-t">{s.source_title}</div>
                        <div className="cl-meta">{s.anchor}</div>
                    </div>
                    <div className="cl-rowact">
                        {s.source_url && <a className="cl-iconlink" href={s.source_url} target="_blank" rel="noopener noreferrer" aria-label={__('Открыть', 'cherrylink')}><IconExternal /></a>}
                        {s.edit_url && <a className="cl-iconlink" href={s.edit_url} target="_blank" rel="noopener noreferrer" aria-label={__('Редактировать', 'cherrylink')}><IconEdit /></a>}
                    </div>
                </div>
            ))}
        </>
    );
}
