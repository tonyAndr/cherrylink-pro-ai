import { TabPanel } from '@wordpress/components';

export const TabsPanel = (props) => (
        <TabPanel className="cherrylink-tabs-panel"
            activeClass="tab-general-active"
            onSelect={ props.onChange }
            orientation="horizontal"
            tabs={ [
                {
                    name: 'tab-links',
                    title: '',
                    className: 'tab-general-style tab-links'
                },
                {
                    name: 'tab-tax',
                    title: '',
                    className: 'tab-general-style tab-taxonomies'
                },
                {
                    name: 'tab-stats',
                    title: '',
                    className: 'tab-general-style tab-stats'
                },
                // {
                //     name: 'tab-block',
                //     title: '',
                //     className: 'tab-general-style tab-blocklinks'
                // }
            ] }>
            {
                ( tab ) => <></>
            }
        </TabPanel>
);