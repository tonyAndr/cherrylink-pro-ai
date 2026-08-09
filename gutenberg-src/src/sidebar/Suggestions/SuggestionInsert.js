import { Button, Tooltip } from '@wordpress/components';
import React, { Component } from 'react';
import { __ } from '@wordpress/i18n';
import LinkWithInsertAction from '../HOC/withLinkInsert';

class SuggestionInsert extends Component {
    render() {
        const { insertAtPhraseHandler, phrase } = this.props;
        return (
            <Tooltip text={__('Найти фразу в тексте и вставить ссылку', 'cherrylink')}>
                <Button
                    className="insert-allowed is-secondary suggestion-item__insert"
                    onClick={(e) => insertAtPhraseHandler(phrase, e)}
                >
                    {__('Вставить', 'cherrylink')}
                </Button>
            </Tooltip>
        );
    }
}

SuggestionInsert.fullName = 'SuggestionInsert';
export default LinkWithInsertAction(SuggestionInsert);
