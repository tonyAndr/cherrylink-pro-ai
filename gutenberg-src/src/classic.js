import { useState, useEffect, useRef, createElement, render } from '@wordpress/element';
import * as wpElement from '@wordpress/element';
import App from './panel/App';
import { createClassicAdapter } from './adapters/classicSwitch';

const POS_KEY = 'cherrylink_panel_pos';
const WIDTH = 340;

function loadPos() {
    try {
        const p = JSON.parse(window.localStorage.getItem(POS_KEY));
        if (p && typeof p.left === 'number' && typeof p.top === 'number') return p;
    } catch (e) { /* storage unavailable */ }
    return null;
}
function savePos(p) {
    try { window.localStorage.setItem(POS_KEY, JSON.stringify(p)); } catch (e) { /* ignore */ }
}
const clamp = (p) => ({
    left: Math.max(0, Math.min(p.left, window.innerWidth - 80)),
    top: Math.max(0, Math.min(p.top, window.innerHeight - 60)),
});

function Host({ config, adapter }) {
    const [open, setOpen] = useState(false);
    const [pos, setPos] = useState(() => clamp(loadPos() || { left: window.innerWidth - WIDTH - 18, top: 96 }));
    const [dragging, setDragging] = useState(false);
    const drag = useRef(null);

    useEffect(() => {
        const onClick = (e) => {
            const t = e.target.closest && e.target.closest('#cherrylink-panel-toggle, .cherrylink-button');
            if (!t) return;
            e.preventDefault();
            setOpen((o) => !o);
        };
        document.addEventListener('click', onClick);
        return () => document.removeEventListener('click', onClick);
    }, []);

    const headProps = {
        onPointerDown(e) {
            if (e.target.closest('button')) return;
            drag.current = { dx: e.clientX - pos.left, dy: e.clientY - pos.top };
            setDragging(true);
            if (e.currentTarget.setPointerCapture) e.currentTarget.setPointerCapture(e.pointerId);
        },
        onPointerMove(e) {
            if (drag.current) setPos(clamp({ left: e.clientX - drag.current.dx, top: e.clientY - drag.current.dy }));
        },
        onPointerUp() {
            if (!drag.current) return;
            drag.current = null;
            setDragging(false);
            setPos((p) => { savePos(p); return p; });
        },
    };

    if (!open) return null;
    return (
        <div className={`cl-host${dragging ? ' cl-dragging' : ''}`} style={{ left: pos.left, top: pos.top }} role="dialog" aria-label="CherryLink">
            <App adapter={adapter} config={{ ...config, host: 'classic' }} onClose={() => setOpen(false)} headProps={headProps} />
        </div>
    );
}

function boot() {
    const config = window.cherrylinkPanel;
    if (!config) return;
    // Term screens with a plain description textarea get no media buttons: add our own toggle.
    if (!document.querySelector('#cherrylink-panel-toggle, .cherrylink-button')) {
        const field = document.getElementById(config.isTerm ? 'description' : 'content');
        if (field) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.id = 'cherrylink-panel-toggle';
            btn.className = 'button';
            btn.style.marginTop = '6px';
            btn.textContent = 'CherryLink';
            field.insertAdjacentElement('afterend', btn);
        }
    }
    const mount = document.createElement('div');
    mount.id = 'cherrylink-panel-root';
    document.body.appendChild(mount);
    const adapter = createClassicAdapter({ editorId: config.isTerm ? 'description' : 'content' });
    const el = createElement(Host, { config, adapter });
    if (wpElement.createRoot) wpElement.createRoot(mount).render(el);
    else render(el, mount);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
else boot();
