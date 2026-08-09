import {Button, Tooltip} from '@wordpress/components'
import React, { Component } from 'react'
import { __ } from '@wordpress/i18n'
import LinkWithInsertAction from '../HOC/withLinkInsert'

class SuggestionCopy extends Component {
    render() {
        const {copyLink, url} = this.props;
        return (
            <Button
                className="is-secondary"
                onClick={() => copyLink(url)}><Tooltip text={__('Скопировать адрес ссылки', 'cherrylink')}><span>{__('Скопировать URL', 'cherrylink')}</span></Tooltip></Button>
        )
    }
}
export default LinkWithInsertAction(SuggestionCopy);