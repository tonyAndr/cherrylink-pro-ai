import { select, dispatch } from '@wordpress/data';
import { BlockAttributes, flattenBlocks } from './blockUtils';

function getEditorDoc() {
    const iframe = document.querySelector('iframe[name="editor-canvas"], .editor-canvas__iframe');
    return (iframe && iframe.contentDocument) || document;
}

function getEditorWin() {
    const iframe = document.querySelector('iframe[name="editor-canvas"], .editor-canvas__iframe');
    return (iframe && iframe.contentWindow) || window;
}

/**
 * Returns clientIds of all blocks whose HTML content includes the given URL string.
 */
export function findBlocksWithUrl(url) {
    // Also match relative paths: Gutenberg stores internal links as /some-post/
    let urlPath = '';
    try { urlPath = new URL(url).pathname; } catch (_) {}

    const blocks = flattenBlocks(select('core/block-editor').getBlocks());
    return blocks
        .filter(block => {
            const attrs = BlockAttributes[block.name];
            if (!attrs) return false;
            return attrs.some(attr => {
                const html = block.attributes[attr];
                if (!html) return false;
                return html.includes(url) || (urlPath && html.includes(urlPath));
            });
        })
        .map(b => b.clientId);
}

/**
 * Returns clientIds of all blocks whose plain text contains the given phrase.
 */
export function findBlocksWithPhrase(phrase) {
    const blocks = flattenBlocks(select('core/block-editor').getBlocks());
    const phraseLower = phrase.toLowerCase();
    return blocks
        .filter(block => {
            const attrs = BlockAttributes[block.name];
            if (!attrs) return false;
            return attrs.some(attr => {
                const html = block.attributes[attr];
                if (!html) return false;
                const text = wp.richText.getTextContent(wp.richText.create({ html }));
                return text.toLowerCase().includes(phraseLower);
            });
        })
        .map(b => b.clientId);
}

/**
 * Highlights a phrase inside a block element using the browser Selection API.
 * Does not modify any HTML — purely visual.
 */
function highlightPhraseInBlock(clientId, phrase) {
    const doc = getEditorDoc();
    const win = getEditorWin();
    const blockEl = doc.querySelector(`[data-block="${clientId}"]`);
    if (!blockEl || !phrase) return;

    const phraseLower = phrase.toLowerCase();
    const walker = doc.createTreeWalker(blockEl, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) {
        const idx = node.textContent.toLowerCase().indexOf(phraseLower);
        if (idx !== -1) {
            try {
                const range = doc.createRange();
                range.setStart(node, idx);
                range.setEnd(node, idx + phrase.length);
                const sel = win.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            } catch (_) {
                // cross-origin or other DOM errors — silently ignore
            }
            break;
        }
    }
}

/**
 * Selects a block in the editor, scrolls it into view, and optionally highlights a phrase.
 * @param {string} clientId
 * @param {string} [phrase]
 */
export function scrollToBlockInEditor(clientId, phrase) {
    dispatch('core/block-editor').selectBlock(clientId);

    requestAnimationFrame(() => {
        const doc = getEditorDoc();
        const blockEl = doc.querySelector(`[data-block="${clientId}"]`);
        if (blockEl) {
            blockEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        if (phrase) {
            highlightPhraseInBlock(clientId, phrase);
        }
    });
}
