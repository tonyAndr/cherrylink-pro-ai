import { createRoot, render } from '@wordpress/element';
import App from './App';
import './admin.css';

const el = document.getElementById('cherrylink-admin');
const boot = window.cherrylinkAdmin;
if (el && boot) {
    el.classList.add('cherrylink-admin');
    const node = <App boot={{ ...boot, page: el.dataset.page || boot.page }} />;
    if (createRoot) createRoot(el).render(node);
    else render(node, el);
}
