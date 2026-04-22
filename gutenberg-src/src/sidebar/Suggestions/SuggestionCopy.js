import {Button, Tooltip} from '@wordpress/components'
import React, { Component } from 'react'
import LinkWithInsertAction from '../HOC/withLinkInsert'

class SuggestionCopy extends Component {
    render() {
        const {copyLink, url} = this.props;
        return (
            <Button
                className="is-secondary"
                onClick={() => copyLink(url)}><Tooltip text="Скопировать адрес ссылки"><span>Скопировать URL</span></Tooltip></Button>
        )
    }
}
// SuggestionInsert.contextType = NoticeContext;
export default LinkWithInsertAction(SuggestionCopy);