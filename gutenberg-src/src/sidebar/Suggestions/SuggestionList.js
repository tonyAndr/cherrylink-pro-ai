import React from 'react';
import { __ } from '@wordpress/i18n';
import './Suggestions.css';
import SuggestionInsert from './SuggestionInsert';
import SuggestionCopy from './SuggestionCopy';
import { findBlocksWithPhrase, scrollToBlockInEditor } from '../HOC/scrollToEditor';

/**
 * Renders the context string returned by the API.
 * The API marks the matched phrase with [phrase], e.g. "про [выбор хостинга] и создание".
 * We render it with the phrase highlighted.
 */
function SuggestionContext({ context }) {
    if (!context) return null;

    const parts = context.split(/\[([^\]]+)\]/);
    return (
        <span className="suggestion-context">
            {parts.map((part, i) =>
                i % 2 === 1
                    ? <strong key={i}>{part}</strong>
                    : <span key={i}>{part}</span>
            )}
        </span>
    );
}

export class SuggestionList extends React.Component {
    constructor(props) {
        super(props);
        this.state = { scrollIndices: {} };
        this.handleSuggestionClick = this.handleSuggestionClick.bind(this);
    }

    componentDidUpdate(prevProps) {
        if (prevProps.linkItem !== this.props.linkItem) {
            this.setState({ scrollIndices: {} });
        }
    }

    handleSuggestionClick(phrase, suggIdx, e) {
        // Don't scroll when clicking the Insert button
        if (e.target.closest('button, .components-button')) return;

        const blocks = findBlocksWithPhrase(phrase);
        if (blocks.length === 0) return;

        const current = this.state.scrollIndices[suggIdx] || 0;
        const idx = current % blocks.length;
        scrollToBlockInEditor(blocks[idx], phrase);
        this.setState(prev => ({
            scrollIndices: { ...prev.scrollIndices, [suggIdx]: idx + 1 }
        }));
    }

    render() {
        const { suggestions, linkItem, inArticle, loading } = this.props;

        if (!linkItem) return null;

        if (loading) {
            return <p className="suggestion-hint">{__('Подбираем анкорные подсказки…', 'cherrylink')}</p>;
        }

        if (!suggestions || suggestions.length === 0) {
            return (
                <>
                    <p className="suggestion-hint">
                        {__('Для данной ссылки анкорных подсказок не найдено. Убедитесь, что статья проиндексирована через Cherry Vector API.', 'cherrylink')}
                    </p>
                </>
            );
        }

        return (
            <>
                <p className="suggestion-hint">{__('Подсказки для статьи:', 'cherrylink')}</p>
                <p className="suggestion-hint"><strong>{linkItem['data-title']}</strong></p>
                <hr />
                <div className="suggestion-action-buttons">
                    <SuggestionCopy url={linkItem['data-url']} />
                </div>
                <p className="suggestion-hint">
                    {__('Нажмите', 'cherrylink')} <strong>{__('Вставить', 'cherrylink')}</strong> {__('рядом с нужным анкором, чтобы добавить ссылку в соответствующее место текста.', 'cherrylink')}
                </p>
                <div className="suggestion-block">
                    <div className="block-name">{__('Найденные анкоры', 'cherrylink')}</div>
                    <ol className="suggestion-list suggestion-list--ai">
                        {suggestions.map((s, i) => (
                            <li key={i} className="suggestion-item" onClick={(e) => this.handleSuggestionClick(s.phrase, i, e)}>
                                <div className="suggestion-item__phrase">
                                    <span className="suggestion-item__anchor">{s.phrase}</span>
                                    <SuggestionInsert
                                        linkObj={linkItem}
                                        phrase={s.phrase}
                                        inArticle={inArticle}
                                    />
                                </div>
                                {s.context && (
                                    <div className="suggestion-item__context">
                                        <SuggestionContext context={s.context} />
                                    </div>
                                )}
                            </li>
                        ))}
                    </ol>
                </div>
            </>
        );
    }
}
