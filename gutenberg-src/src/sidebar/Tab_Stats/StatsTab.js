import React from 'react';
import './StatsTab.css';
import { HorizontalRule } from '@wordpress/components';
import { findBlocksWithUrl, scrollToBlockInEditor } from '../HOC/scrollToEditor';

export class StatsTab extends React.Component {

    constructor(props) {
        super(props);
        this.scrollInto = this.scrollInto.bind(this);
    }

    scrollInto(idx, url) {
        const phrase = this.props.stats[idx]?.keyword || '';
        const clientIds = findBlocksWithUrl(url);
        if (clientIds.length === 0) return;

        const offset = this.props.stats[idx]?.offset || 0;
        const clientId = clientIds[Math.min(offset, clientIds.length - 1)];
        scrollToBlockInEditor(clientId, phrase);
    }

    render() {
        const { stats, incoming } = this.props;
        const placeholder = "Ссылок пока нет...";
        let statsView, incomingView;
        // stats
        if (stats.length > 0) {
            statsView = stats.map((x, i) => {
                let ankor = x.keyword;
                if (!ankor || ankor.trim().length === 0) {
                    ankor = 'Без анкора';
                }
                return (<li key={i}>
                    <span
                        className="cherry-stat-anchor"
                        title="Найти в тексте"
                        onClick={() => this.scrollInto(i, x.url)}
                    >{ankor}</span>
                    <span>Ссылается на <a href={x.url} target="_blank" title="Переход по ссылке">{x.url}</a></span>
                </li>)
            })

            statsView = <ol className="cherry-stats">{statsView}</ol>;
        } else {
            statsView = <div>{placeholder}</div>
        }

        // incoming
        if (incoming.count > 0) {
            incomingView = incoming.links.map((x, i) => {
                let ankor = x.ankor;
                if (!ankor || ankor.trim().length === 0) {
                    ankor = 'Без анкора';
                }
                return (<li key={i}>
                    <span>Ссылка с анкором: <strong className="cherry-incoming-ankor">{ankor}</strong></span>
                    <span>Из статьи: <a href={x.source_url} target="_blank" title="Переход по ссылке">{x.source_url}</a></span>
                </li>)
            })
            incomingView = <ol className="cherry-incoming">{incomingView}</ol>;
        } else {
            incomingView = <div>{placeholder}</div>;
        }

        return (<div className="cherry-stats-container">
            <h2>Входящие ссылки</h2>
            {incomingView}
            <HorizontalRule />
            <h2>Исходящие ссылки</h2>
            {statsView}
        </div>)
    }
}
