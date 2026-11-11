import { __, sprintf } from '@wordpress/i18n';

export default function Strip({ out, inc, donorCount, onLinks, onGoIncoming }) {
    return (
        <div className="cl-strip">
            <span className="cl-count">
                <button type="button" className="cl-countbtn" onClick={() => onLinks('out')}>
                    {__('Исходящих', 'cherrylink')} <b>{out}</b>
                </button>
                {' · '}
                <button type="button" className="cl-countbtn" onClick={() => onLinks('in')}>
                    {__('Входящих', 'cherrylink')} <b>{inc}</b>
                </button>
            </span>
            {inc === 0 && donorCount > 0 ? (
                <button type="button" className="cl-chip cl-warn" onClick={onGoIncoming}>
                    {sprintf(__('Нет входящих · %d статьи могут сослаться', 'cherrylink'), donorCount)}
                </button>
            ) : out > 0 ? (
                <span className="cl-chip cl-ok">{__('Ссылки есть', 'cherrylink')}</span>
            ) : null}
        </div>
    );
}
