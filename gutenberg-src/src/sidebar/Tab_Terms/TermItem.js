import React from 'react';
import LinkWithInsertAction from '../HOC/withLinkInsert';
import { Tooltip } from '@wordpress/components';

class TermItem extends React.Component {

    constructor(props) {
        super(props);
    }


    render() {
        const { isChild, inArticle, termName, termUrl, children, copyLink, clickHandler } = this.props;
        const className = isChild ? 'term-checkbox-child' : 'term-checkbox-toplvl';
        const containerClass = inArticle ? `term-item-container insert-allowed inArticle` : "term-item-container insert-allowed";
        return (
            <div className={className}>
                <div className={containerClass} onMouseDown={clickHandler}>
                    <span>{termName}</span>
                    <div className='term-toolbar-overlay insert-allowed'>
                        <div className='toolbar-actions insert-allowed'>
                            <div className=" insert-allowed">
                                <Tooltip text="Вставить ссылку">

                                <img className="insert-allowed" src="/wp-content/plugins/cherrylink-pro-ai/assets/gutenberg/insert_link.png" alt="" />
                                </Tooltip>
                            </div>
                        </div>
                        <div className='toolbar-article'>
                            <div onMouseDown={() => copyLink(termUrl)}>
                            <Tooltip text="Копировать ссылку">
                                <img src="/wp-content/plugins/cherrylink-pro-ai/assets/gutenberg/copy_link.png" alt="" />
                                </Tooltip>
                                </div>
                            <div>
                            <Tooltip text="Просмотр таксономии">
                                <a target='_blank' href={termUrl}><img src="/wp-content/plugins/cherrylink-pro-ai/assets/gutenberg/look_page.png"  alt="" /></a>
                                </Tooltip>
                                </div>
                        </div>
                    </div>
                </div>

                {children.length > 0 && children}
            </div>
        )
    }
}

TermItem.fullName = "TermItem";

export default LinkWithInsertAction(TermItem);