import { registerPlugin } from '@wordpress/plugins';
import { __ } from '@wordpress/i18n';
import App from './panel/App';
import { createGutenbergAdapter } from './adapters/gutenberg';
import Icons from './adapters/gutenberg/Icons';

// PluginSidebar moved from @wordpress/edit-post (WP <= 6.5) to @wordpress/editor (WP 6.6+).
function getPluginSidebarComponents() {
    const src = window.wp && window.wp.editor && window.wp.editor.PluginSidebar ? window.wp.editor : window.wp.editPost;
    return { PluginSidebar: src.PluginSidebar, PluginSidebarMoreMenuItem: src.PluginSidebarMoreMenuItem };
}

let adapter;

function CherryLinkSidebar() {
    const { PluginSidebar, PluginSidebarMoreMenuItem } = getPluginSidebarComponents();
    adapter = adapter || createGutenbergAdapter();
    const config = window.cherrylinkPanel;
    const title = __('CherryLink', 'cherrylink-ai');
    return (
        <>
            <PluginSidebarMoreMenuItem target="cherrylink-sidebar">{title}</PluginSidebarMoreMenuItem>
            <PluginSidebar name="cherrylink-sidebar" title={title}>
                {config ? <App adapter={adapter} config={{ ...config, host: 'gutenberg' }} /> : null}
            </PluginSidebar>
        </>
    );
}

registerPlugin('cherrylink-sidebar', { icon: Icons.main, render: CherryLinkSidebar });
