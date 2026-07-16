import React from 'react';
import LinkWithInsertAction from '../HOC/withLinkInsert';
import { showNotice } from '../Notices';
import { Tooltip } from '@wordpress/components';
import { findBlocksWithUrl, scrollToBlockInEditor } from '../HOC/scrollToEditor';

class LinkItem extends React.Component {

    constructor(props) {
        super(props);
        this.state = { scrollIndex: 0 };
        this.handleContainerClick = this.handleContainerClick.bind(this);
    }

    handleContainerClick(e) {
        // Ignore clicks on action buttons, links, images
        if (e.target.closest('.main-action, .toolbar-article, .toolbar-stats, a, button')) return;

        const { linkObj } = this.props;
        const url = linkObj['data-url'];
        const blocks = findBlocksWithUrl(url);
        if (blocks.length === 0) return;

        const idx = this.state.scrollIndex % blocks.length;
        scrollToBlockInEditor(blocks[idx]);
        this.setState({ scrollIndex: idx + 1 });
    }

    findSuggestions(sugg) {
        const { showSuggestions, linkObj } = this.props;

        // Saving scroll position of links list to be able to return 
        // to the same place after suggestions will be closed
        let element = document.querySelector(".editor-sidebar")
        if (element === null) {
            element = document.querySelector(".edit-post-sidebar")
        }
        const scrollPos = element.scrollTop;

        showSuggestions({ rawKeywords: sugg, linkItem: linkObj }, scrollPos);
    }


    decodeHtml(html) {
        const txt = document.createElement("textarea");
        txt.innerHTML = html;
        return txt.value;
    }

    render() {

        const { linkObj, editUrl, clickHandler, copyLink, inArticle, isIncoming } = this.props;
        const postId = linkObj["data-postid"],
            url = linkObj["data-url"],
            title = this.decodeHtml(linkObj["data-title"]),
            incoming = linkObj["data-incoming"],
            outgoing = linkObj["data-outgoing"],
            sugg = linkObj["data-suggestions"];

        const containerClass = inArticle ? `link-item-container insert-allowed inArticle` : "link-item-container insert-allowed";

        return (
            <div className={containerClass} onClick={this.handleContainerClick}>
                <span className={isIncoming ? 'incoming-link' : 'not-incoming'}>{title}</span>
                <div className='link-toolbar-overlay  insert-allowed'>
                    <div className='toolbar-actions'>
                        <div onMouseDown={clickHandler} className="insert-allowed main-action">
                            <Tooltip text="Вставить ссылку">
                                <img className="insert-allowed" src="/wp-content/plugins/cherrylink-pro-ai/assets/gutenberg/insert_link.png" alt="" />
                            </Tooltip>
                        </div>
                        <div className="main-action" onMouseDown={(e) => {
                            e.stopPropagation();
                            this.findSuggestions(sugg)
                        }}>
                            <Tooltip text="Подсказки анкоров">
                                <img src="/wp-content/plugins/cherrylink-pro-ai/assets/gutenberg/find_suggestions.png" alt="Подсказки" />
                            </Tooltip>
                        </div>
                    </div>
                    <div className='toolbar-article'>
                        <div onMouseDown={() => copyLink(url)}>
                            <Tooltip text="Копировать ссылку">
                                <img src="/wp-content/plugins/cherrylink-pro-ai/assets/gutenberg/copy_link.png" alt="" />
                            </Tooltip>
                        </div>
                        <div>
                        <Tooltip text="Просмотр статьи">
                            <a target='_blank' href={url}><img src="/wp-content/plugins/cherrylink-pro-ai/assets/gutenberg/look_page.png" alt="" /></a>
                        </Tooltip>
                        </div>
                        <div>
                            <Tooltip text="Редактировать статью">
                            <a target='_blank' href={`${editUrl}?post=${postId}&action=edit`}>
                            <img src="/wp-content/plugins/cherrylink-pro-ai/assets/gutenberg/edit_page.png" alt="" /></a>
                            </Tooltip>
                        </div>
                    </div>
                    <div className='toolbar-stats'>
                        <div>
                            <div><Tooltip text="Исходящие ссылки"><span>↑ {outgoing}</span></Tooltip></div><div><Tooltip text="Входящие ссылки"><span>↓ {incoming}</span></Tooltip></div>
                        </div>
                    </div>
                </div>
            </div>
        )
    }
}

LinkItem.fullName = "LinkItem";

export default LinkWithInsertAction(LinkItem);