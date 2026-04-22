import React from 'react';
import { SuggestionList } from './SuggestionList';
import { Button, Tooltip } from '@wordpress/components';

export class SuggestionsPanel extends React.Component {
    constructor(props) {
        super(props);
        this.state = { suggestions: [], linkItem: null, inArticle: false };
        this.closePanel = this.closePanel.bind(this);
    }

    isInArticle(item, stats) {
        const convertedUrl = this.props.convertUrl(item['data-url']);
        return stats.some(s => convertedUrl === s.url);
    }

    componentDidMount() {
        const { suggestionsData, stats } = this.props;
        const { linkItem } = suggestionsData;

        let suggestions = [];
        const raw = linkItem['data-suggestions'];
        if (raw) {
            try {
                suggestions = JSON.parse(raw);
            } catch (e) {
                console.warn('CherryLink: failed to parse suggestions JSON', e);
            }
        }

        this.setState({
            suggestions,
            linkItem,
            inArticle: this.isInArticle(linkItem, stats),
        });
    }

    closePanel() {
        this.props.toggleSuggestionsPanel(false);
    }

    render() {
        return (
            <div className="suggestion-panel">
                <Tooltip text="Выйти к списку ссылок">
                    <Button id="cherry_suggestions_close" className="is-tertiary" onClick={this.closePanel}>
                        ← Назад
                    </Button>
                </Tooltip>
                <SuggestionList {...this.state} />
            </div>
        );
    }
}
