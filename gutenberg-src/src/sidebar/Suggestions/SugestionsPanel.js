import React from 'react';
import { SuggestionList } from './SuggestionList';
import { Button, Tooltip } from '@wordpress/components';

export class SuggestionsPanel extends React.Component {
    constructor(props) {
        super(props);
        this.state = { suggestions: [], linkItem: null, inArticle: false, loading: true };
        this.closePanel = this.closePanel.bind(this);
    }

    isInArticle(item, stats) {
        const convertedUrl = this.props.convertUrl(item['data-url']);
        return stats.some(s => convertedUrl === s.url);
    }

    componentDidMount() {
        const { suggestionsData, stats } = this.props;
        const { linkItem } = suggestionsData;
        this.setState({
            linkItem,
            inArticle: this.isInArticle(linkItem, stats),
            loading: true,
        });
        this.fetchSuggestions(linkItem);
    }

    // Anchor suggestions are fetched on demand when this panel opens (one hit at a time),
    // not precomputed for every hit. The API caches candidate embeddings per source article,
    // so opening multiple hits only pays the embedding cost once.
    async fetchSuggestions(linkItem) {
        const postId = (typeof cherrylink_options !== 'undefined' && cherrylink_options['post_id'])
            ? cherrylink_options['post_id'] : 0;
        const payload = {
            post_id: postId,
            target_id: linkItem['data-postid'],
            target_title: linkItem['data-title'] || '',
        };
        try {
            const resp = await fetch(ajax_var.url + '?action=get_linkate_suggestions', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            const data = await resp.json();
            this.setState({ suggestions: (data && data.suggestions) ? data.suggestions : [], loading: false });
        } catch (e) {
            console.warn('CherryLink: suggestions fetch failed', e);
            this.setState({ suggestions: [], loading: false });
        }
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
