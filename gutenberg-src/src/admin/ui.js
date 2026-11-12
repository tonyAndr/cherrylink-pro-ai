import { __ } from '@wordpress/i18n';
import { Modal } from '@wordpress/components';

export const Mark = ({ size = 28 }) => (
    <svg width={size} height={size} viewBox="0 0 28 28" aria-hidden="true" focusable="false">
        <path d="M14 3c0 6-4 9-7 12M14 3c2 5 5 8 8 11" fill="none" stroke="#3f6212" strokeWidth="2" strokeLinecap="round" />
        <path d="M14 3c3-1 6 0 7 2" fill="none" stroke="#3f6212" strokeWidth="2" strokeLinecap="round" />
        <circle cx="7.5" cy="19" r="6" fill="#be123c" />
        <circle cx="20.5" cy="18" r="6" fill="#9f1239" />
    </svg>
);

export const Title = ({ children, extra }) => (
    <header className="cl-head">
        <Mark />
        <h1>{children}</h1>
        {extra}
    </header>
);

export const Btn = ({ kind = 'primary', className = '', ...p }) => <button type="button" {...p} className={`cl-btn cl-btn-${kind} ${className}`} />;
export const Link = ({ kind = 'link', ...p }) => <a className={kind === 'btn' ? 'cl-btn cl-btn-primary' : 'cl-link'} {...p} />;
export const Badge = ({ tone = 'ok', children }) => <span className={`cl-badge cl-badge-${tone}`}>{children}</span>;
export const Card = ({ children, tone, className = 'cl-card', ...p }) => <section {...p} className={`${className}${tone ? ` cl-card-${tone}` : ''}`}>{children}</section>;
export const Notice = ({ tone = 'warn', children }) => <div className={`cl-notice cl-notice-${tone}`} role={tone === 'danger' ? 'alert' : 'status'}>{children}</div>;
export const Bar = ({ value, total }) => (
    <div className="cl-bar" role="progressbar" aria-valuemin={0} aria-valuemax={total} aria-valuenow={value}>
        <div style={{ width: `${total ? Math.min(100, Math.round((value / total) * 100)) : 0}%` }} />
    </div>
);

export const Confirm = ({ title, text, action, onConfirm, onCancel, busy }) => (
    <Modal title={title} onRequestClose={onCancel}>
        <p>{text}</p>
        <div className="cl-row">
            <Btn kind="danger" onClick={onConfirm} disabled={busy}>{action}</Btn>
            <Btn kind="ghost" onClick={onCancel}>{__('Cancel', 'cherrylink')}</Btn>
        </div>
    </Modal>
);

export const num = (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString());

/** Shown when the API purged a free site's index after 180 days without contact. */
export const ExpiredNotice = ({ onStart, busy }) => (
    <Notice tone="warn">
        <div className="cl-stack">
            <strong>{__('Your site data was removed', 'cherrylink')}</strong>
            <span>{__('CherryLink deletes the data of free sites after 180 days without contact. Analyze the site again to get suggestions and link health back. It takes about a minute for 1,000 posts.', 'cherrylink')}</span>
            <div><Btn onClick={onStart} disabled={busy}>{__('Analyze site', 'cherrylink')}</Btn></div>
        </div>
    </Notice>
);
