export const Logo = ({ size = 22 }) => (
    <svg className="cl-logo" width={size} height={size} viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M12 3c-1 3-3 6-6 9M12 3c1 4 3 7 6 9" fill="none" stroke="#3f7a3a" strokeWidth="1.8" strokeLinecap="round" />
        <circle cx="6.5" cy="15.5" r="4.5" fill="#a8123e" />
        <circle cx="17.5" cy="15.5" r="4.5" fill="#a8123e" />
        <circle cx="5.2" cy="14" r="1.2" fill="#fff" opacity=".6" />
        <circle cx="16.2" cy="14" r="1.2" fill="#fff" opacity=".6" />
    </svg>
);

const base = { width: 14, height: 14, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true, focusable: 'false' };

export const IconExternal = () => (
    <svg {...base}><path d="M7 17 17 7M8 7h9v9" /></svg>
);
export const IconEdit = () => (
    <svg {...base}><path d="M4 20h4L19 9l-4-4L4 16v4zM13 7l4 4" /></svg>
);
export const IconBack = () => (
    <svg {...base}><path d="M15 6l-6 6 6 6" /></svg>
);
export const IconClose = () => (
    <svg {...base}><path d="M6 6l12 12M18 6 6 18" /></svg>
);
export const IconChevron = ({ open }) => (
    <svg {...base} width={12} height={12} style={{ transform: open ? 'rotate(90deg)' : 'none' }}><path d="M9 6l6 6-6 6" /></svg>
);
