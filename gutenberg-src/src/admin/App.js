import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { createApi } from './api';
import { Ctx } from './ctx';
import { Card, Link, Title } from './ui';
import Wizard from './Wizard';
import Dashboard from './Dashboard';
import LinkHealth from './LinkHealth';
import IndexPage from './IndexPage';
import Settings from './Settings';

const PAGES = { dashboard: Dashboard, links: LinkHealth, index: IndexPage, settings: Settings };

export default function App({ boot }) {
    const [state, setState] = useState(boot.state);
    const [api] = useState(() => createApi(boot));
    const ctx = { boot, api, state, setState, urls: boot.urls };
    const page = boot.page;

    let body;
    if (page === 'dashboard' && (!state.connected || !state.setup_done)) {
        body = <Wizard />;
    } else if (!state.connected) {
        body = (
            <Card>
                <Title>{__('Connect CherryLink first', 'cherrylink-ai')}</Title>
                <p>{__('This page needs a connected site.', 'cherrylink-ai')}</p>
                <Link kind="btn" href={boot.urls.dashboard}>{__('Connect CherryLink', 'cherrylink-ai')}</Link>
            </Card>
        );
    } else {
        const Page = PAGES[page] || Dashboard;
        body = <Page />;
    }
    return <Ctx.Provider value={ctx}><div className="cl-wrap">{body}</div></Ctx.Provider>;
}
