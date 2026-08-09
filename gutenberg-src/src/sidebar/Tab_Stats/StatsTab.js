import React from 'react';
import { __ } from '@wordpress/i18n';
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
        const placeholder = __('Ссылок пока нет...', 'cherrylink');
        let statsView, incomingView;
        // stats
        if (stats.length > 0) {
            statsView = stats.map((x, i) => {
                let ankor = x.keyword;
                if (!ankor || ankor.trim().length === 0) {
                    ankor = __('Без анкора', 'cherrylink');
                }
                return (<li key={i}>
                    <span
                        className="cherry-stat-anchor"
                        title={__('Найти в тексте', 'cherrylink')}
                        onClick={() => this.scrollInto(i, x.url)}
                    >{ankor}</span>
                    <span>{__('Ссылается на', 'cherrylink')} <a href={x.url} target="_blank" title={__('Переход по ссылке', 'cherrylink')}>{x.url}</a></span>
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
                    ankor = __('Без анкора', 'cherrylink');
                }
                return (<li key={i}>
                    <span>{__('Ссылка с анкором:', 'cherrylink')} <strong className="cherry-incoming-ankor">{ankor}</strong></span>
                    <span>{__('Из статьи:', 'cherrylink')} <a href={x.source_url} target="_blank" title={__('Переход по ссылке', 'cherrylink')}>{x.source_url}</a></span>
                </li>)
            })
            incomingView = <ol className="cherry-incoming">{incomingView}</ol>;
        } else {
            incomingView = <div>{placeholder}</div>;
        }

        return (<div className="cherry-stats-container">
            <h2>{__('Входящие ссылки', 'cherrylink')}</h2>
            {incomingView}
            <HorizontalRule />
            <h2>{__('Исходящие ссылки', 'cherrylink')}</h2>
            {statsView}
        </div>)
    }
}
