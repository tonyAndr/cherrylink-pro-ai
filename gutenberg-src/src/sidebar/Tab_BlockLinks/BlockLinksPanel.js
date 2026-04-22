import React from 'react';
import { HorizontalRule, CheckboxControl, Panel, PanelBody, PanelRow, TextControl } from '@wordpress/components';
import { BlockLinksOptions } from './BlockLinksOptions';
import BlockLinksList from './BlockLinksList';
import withPostMeta from '../HOC/withPostMeta';

class BlockLinks extends React.Component {
    constructor(props) {
        super(props);

        this.state = {
            showAfter: false,
            manualEdit: false,
            blockLinks: []
        }

        this.updateManualEdit = this.updateManualEdit.bind(this);
        this.updateShowAfter = this.updateShowAfter.bind(this);
        this.updateBlockLinks = this.updateBlockLinks.bind(this);
        this.updateOptions = this.updateOptions.bind(this);
        this.updateMetaState = this.updateMetaState.bind(this);

    }

    updateShowAfter(showAfter) {
        this.updateOptions("showAfter", showAfter);
    }

    updateManualEdit(manualEdit) {
        this.updateOptions("manualEdit", manualEdit);
    }

    updateBlockLinks(blockLinks) {
        this.updateOptions("blockLinks", blockLinks);
    }

    // key == option name, value == bool/object
    updateOptions(key, value) {
        let { blockLinks, manualEdit, showAfter } = this.props.getMeta();

        if (key === "blockLinks") {
            blockLinks = value;
        }

        if (key === "showAfter") {
            showAfter = value;
        }

        if (key === "manualEdit") {
            manualEdit = value;
        }

        const newState = {
            blockLinks: blockLinks,
            manualEdit: manualEdit,
            showAfter: showAfter
        };
        // this.props.updateMeta(newState)
        this.updateMetaState(newState)
    }

    updateMetaState(newState) {
        if (newState !== undefined) {
            this.props.updateMeta(newState);
            this.setState(prevState => (newState));
        } else {
            this.setState(prevState => {
                const meta = this.props.getMeta()
                return meta;
            }

            );
        }
    }

    componentDidMount() {
        this.updateMetaState();
    }


    // metaUpdate() {
    //     const { showAfter, showAfterChanged, manualEdit, blockLinks } = this.state;
    //     let optionShowAfter = "";
    //     if (showAfterChanged && showAfter)
    //         optionShowAfter = "checked";
    //     else if (showAfterChanged && !showAfter)
    //         optionShowAfter = "off";
    //     else 
    //         optionShowAfter = "";
    //     wp.data.dispatch("core/editor").editPost({
    //         meta: {
    //             'crb-meta-links': blockLinks.map(x => {
    //                             return x.post_id + "[|]" + x.keyword + "[|]" + x.m_keyword + "[|]" + x.use_manual;
    //                         }).join("\n"),
    //             'crb-meta-show': optionShowAfter,
    //             'crb-meta-show-edited': showAfterChanged ? "checked" : "",
    //             'crb-meta-use-manual': manualEdit ? "checked" : ""
    //         }
    //     })
    //     console.log("Block Link options updated")

    // }


    render() {

        // const testData = [
        //     {post_id: "24233", keyword: "Default title here", m_keyword: "Manual title goes here"},
        //     {post_id: "341", keyword: "Default title here", m_keyword: ""},
        //     {post_id: "231", keyword: "Default title here", m_keyword: "Manual adw wa"},
        //     {post_id: "546", keyword: "Default title here", m_keyword: ""},
        //     {post_id: "786", keyword: "Default title here", m_keyword: "Manual ada wdawd"},
        //     {post_id: "678768", keyword: "Default title here", m_keyword: ""},
        // ];
        return (
            <>
                <Panel>
                    <PanelBody>
                        <BlockLinksOptions {...this.state} updateShowAfter={this.updateShowAfter} updateManualEdit={this.updateManualEdit} />
                    </PanelBody>
                    <PanelBody>
                        <BlockLinksList postMeta={this.state} updateBlockLinks={this.updateBlockLinks} updateMetaState={this.updateMetaState}/>
                    </PanelBody>
                </Panel>
            </>
        )
    }
}

export default withPostMeta(BlockLinks);