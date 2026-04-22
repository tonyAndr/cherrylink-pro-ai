import { Button, Tooltip } from '@wordpress/components';
import React, { Component } from 'react';
import LinkWithInsertAction from '../HOC/withLinkInsert';

class SuggestionInsert extends Component {
    render() {
        const { insertAtPhraseHandler, phrase } = this.props;
        return (
            <Tooltip text="Найти фразу в тексте и вставить ссылку">
                <Button
                    className="insert-allowed is-secondary suggestion-item__insert"
                    onClick={(e) => insertAtPhraseHandler(phrase, e)}
                >
                    Вставить
                </Button>
            </Tooltip>
        );
    }
}

SuggestionInsert.fullName = 'SuggestionInsert';
export default LinkWithInsertAction(SuggestionInsert);
