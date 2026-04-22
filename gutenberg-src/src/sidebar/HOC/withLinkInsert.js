import React from 'react';
import { select, dispatch } from '@wordpress/data';
import { showNotice } from "../Notices";
import { applyLinkAtSelection } from './cherryRichText';
import { BlockAttributes, flattenBlocks } from './blockUtils';

const LinkWithInsertAction = OriginalComponent => {
    class NewComponent extends React.Component {
        constructor(props) {
            super(props);
            this.handleClick           = this.handleClick.bind(this);
            this.copyLink              = this.copyLink.bind(this);
            this.findAndInsertAtPhrase = this.findAndInsertAtPhrase.bind(this);
            this.changeBlockLinks      = this.changeBlockLinks.bind(this);
        }

        // ── Shared helpers ───────────────────────────────────────────────────

        getLinkData() {
            if (OriginalComponent.fullName === "LinkItem" || OriginalComponent.fullName === "SuggestionInsert") {
                const { linkObj } = this.props;
                return { url: linkObj["data-url"] || '', title: linkObj["data-title"] || '' };
            }
            return { url: this.props.termUrl || '', title: this.props.termName || '' };
        }

        afterInsert() {
            showNotice('Ссылка вставлена');
            if (OriginalComponent.fullName === "SuggestionInsert") {
                document.querySelector("#cherry_suggestions_close")?.click();
            }
        }

        // ── Manual selection: fires on onMouseDown (before blur) ─────────────
        // Uses the format-type ref — works for any block including table cells.

        handleClick(e) {
            e.preventDefault();
            if (!e.target.classList.contains("insert-allowed")) return;

            if (this.props.inArticle && cherrylink_options['multilink'] !== 'checked') {
                showNotice('Ссылка уже присутствует в тексте');
                return;
            }

            const { url, title } = this.getLinkData();
            if (applyLinkAtSelection(url, title)) {
                this.afterInsert();
            } else {
                showNotice('Выделите текст для вставки ссылки');
            }
        }

        // ── Phrase-based insert (AI suggestions) ─────────────────────────────
        // No selection needed — searches all blocks for the phrase text.

        findAndInsertAtPhrase(phrase) {
            if (!phrase) return;

            if (this.props.inArticle && cherrylink_options['multilink'] !== 'checked') {
                showNotice('Ссылка уже присутствует в тексте');
                return;
            }

            const { url, title } = this.getLinkData();
            const format      = { type: 'core/link', attributes: { url, ...(title ? { title } : {}) } };
            const blocks      = flattenBlocks(select('core/block-editor').getBlocks());
            const phraseLower = phrase.toLowerCase();

            for (const block of blocks) {
                // ── Standard flat-attribute blocks ──
                const attrs = BlockAttributes[block.name];
                if (attrs && attrs.length > 0) {
                    for (const attr of attrs) {
                        const html = block.attributes[attr];
                        if (!html) continue;

                        const value = wp.richText.create({ html });
                        const text  = wp.richText.getTextContent(value);
                        const idx   = text.toLowerCase().indexOf(phraseLower);

                        if (idx !== -1) {
                            const newHtml = wp.richText.toHTMLString({
                                value: wp.richText.applyFormat(value, format, idx, idx + phrase.length),
                            });
                            dispatch('core/block-editor').updateBlockAttributes(block.clientId, { [attr]: newHtml });
                            this.afterInsert();
                            return;
                        }
                    }
                    continue;
                }

                // ── core/table: search head / body / foot cell by cell ──
                if (block.name === 'core/table') {
                    for (const section of ['head', 'body', 'foot']) {
                        const rows = block.attributes[section] || [];
                        for (let rowIdx = 0; rowIdx < rows.length; rowIdx++) {
                            const cells = rows[rowIdx].cells || [];
                            for (let cellIdx = 0; cellIdx < cells.length; cellIdx++) {
                                const html = cells[cellIdx].content;
                                if (!html) continue;

                                const value = wp.richText.create({ html });
                                const text  = wp.richText.getTextContent(value);
                                const idx   = text.toLowerCase().indexOf(phraseLower);

                                if (idx !== -1) {
                                    const newHtml = wp.richText.toHTMLString({
                                        value: wp.richText.applyFormat(value, format, idx, idx + phrase.length),
                                    });
                                    const newSection = rows.map((row, rI) => ({
                                        ...row,
                                        cells: row.cells.map((c, cI) =>
                                            rI === rowIdx && cI === cellIdx ? { ...c, content: newHtml } : c
                                        ),
                                    }));
                                    dispatch('core/block-editor').updateBlockAttributes(block.clientId, { [section]: newSection });
                                    this.afterInsert();
                                    return;
                                }
                            }
                        }
                    }
                }
            }

            showNotice('Фраза не найдена в тексте статьи');
        }

        // ── Copy link to clipboard ───────────────────────────────────────────

        copyLink(url) {
            const fullUrl = this.fcl_convertRelativeUrl(url);
            if (navigator.clipboard) {
                navigator.clipboard.writeText(fullUrl).then(() => showNotice('Ссылка скопирована в буфер обмена'));
                return;
            }
            const textarea = document.createElement("textarea");
            textarea.value = fullUrl;
            textarea.style.position = "fixed";
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand("copy");
                showNotice('Ссылка скопирована в буфер обмена');
            } catch (ex) {
                console.warn("Copy to clipboard failed.", ex);
            } finally {
                document.body.removeChild(textarea);
            }
        }

        fcl_convertRelativeUrl(url) {
            if (url.indexOf("http") !== -1) return url;
            const host = location.host, proto = location.protocol;
            if (url.length > 0) {
                if (url.indexOf(host) === -1) url = "//" + host + url;
                if (url.indexOf(proto) === -1) url = proto + url;
            }
            return url;
        }

        // ── Block-links panel ────────────────────────────────────────────────

        changeBlockLinks(postId, title, postMeta, updateCallback) {
            const { blockLinks, manualEdit, showAfter } = postMeta;
            let wasInBlock = false, index = 0;
            blockLinks.forEach((el, i) => { if (el.post_id === postId) { wasInBlock = true; index = i; } });

            if (!wasInBlock) {
                blockLinks.push({ post_id: postId, keyword: title, m_keyword: "", use_manual: "false" });
                showNotice('Ссылка добавлена в блок');
            } else {
                blockLinks.splice(index, 1);
                showNotice('Ссылка удалена из блока');
            }

            updateCallback({ blockLinks, manualEdit, showAfter });
        }

        // ── Render ───────────────────────────────────────────────────────────

        render() {
            return (
                <OriginalComponent
                    {...this.props}
                    clickHandler={this.handleClick}
                    copyLink={this.copyLink}
                    changeBlockLinks={this.changeBlockLinks}
                    insertAtPhraseHandler={this.findAndInsertAtPhrase}
                />
            );
        }
    }

    return NewComponent;
}

export default LinkWithInsertAction;
