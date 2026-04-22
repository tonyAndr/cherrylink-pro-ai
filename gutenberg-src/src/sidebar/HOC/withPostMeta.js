import React from 'react';
import { select, dispatch } from '@wordpress/data';


const withPostMeta = OriginalComponent => {
    class NewComponent extends React.Component {
        constructor(props) {
            super(props);

            this.getPostMeta = this.getPostMeta.bind(this);
            this.updatePostMeta = this.updatePostMeta.bind(this);
        }

        componentDidMount() {

        }

        getPostMeta() {
            const { meta } = select("core/editor").getCurrentPost();
            const metaEdited = select("core/editor").getEditedPostAttribute("meta");
            let blockLinksRaw, manualEditRaw, showAfterRaw, showAfterChangedRaw;

            if (metaEdited.updated) {
                blockLinksRaw = metaEdited["crb-meta-links"];
                manualEditRaw = metaEdited["crb-meta-use-manual"]
                showAfterRaw = metaEdited["crb-meta-show"]
                showAfterChangedRaw = metaEdited["crb-meta-show-edited"]
            } else {
                blockLinksRaw = meta["crb-meta-links"];
                manualEditRaw = meta["crb-meta-use-manual"]
                showAfterRaw = meta["crb-meta-show"]
                showAfterChangedRaw = meta["crb-meta-show-edited"]
            }


            const links = blockLinksRaw.length > 0 ? blockLinksRaw.split("\n").map(x => {
                let y = x.split("[|]");
                return {post_id: y[0], keyword: y[1], m_keyword: y[2], use_manual: y[3]};
            }) : [];
            const manualEdit = manualEditRaw === "checked";
            const showAfter = showAfterRaw === "checked";
            const showAfterChanged = showAfterChangedRaw === "checked";
    
            return {
                blockLinks: links,
                manualEdit: manualEdit,
                showAfter: showAfter,
                showAfterChanged: showAfterChanged
            };
        }

        updatePostMeta(updatedMeta) {
            const prevMeta = this.getPostMeta();
            let newMeta = {};

            if (updatedMeta.blockLinks) {
                newMeta['crb-meta-links'] = updatedMeta.blockLinks.map(x => {
                    return x.post_id + "[|]" + x.keyword + "[|]" + x.m_keyword + "[|]" + x.use_manual;
                }).join("\n");
            }

            if (updatedMeta.manualEdit !== undefined) {
                newMeta["crb-meta-use-manual"] = updatedMeta.manualEdit ? "checked" : "off";
            }

            if (updatedMeta.showAfter !== undefined) {
                if (prevMeta.showAfter !== updatedMeta.showAfter) {
                    newMeta["crb-meta-show"] = updatedMeta.showAfter ? "checked" : "off";
                    newMeta["crb-meta-show-edited"] = "checked";
                } else {
                    newMeta["crb-meta-show"] = prevMeta.showAfter ? "checked" : "off";
                    newMeta["crb-meta-show-edited"] = prevMeta.showAfterChanged ? "checked" : "off";
                }
            }

            wp.data.dispatch("core/editor").editPost({
                meta: {
                    'crb-meta-links': newMeta['crb-meta-links'],
                    'crb-meta-use-manual': newMeta['crb-meta-use-manual'],
                    'crb-meta-show': newMeta['crb-meta-show'],
                    'crb-meta-show-edited': newMeta['crb-meta-show-edited'],
                    'updated': true
                }
            })
        }

        render() {
            return <OriginalComponent { ...this.props } getMeta={this.getPostMeta} updateMeta={this.updatePostMeta}/>
        }
    }

    return NewComponent
}

export default withPostMeta;



