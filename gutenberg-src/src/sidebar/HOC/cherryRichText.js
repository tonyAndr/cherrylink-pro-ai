/**
 * Registers a lightweight format type that gives us access to `value` and `onChange`
 * for whatever rich-text block the user is currently editing.
 *
 * This lets the sidebar Insert button apply a link to the active selection without
 * ever needing to parse `attributeKey` or enumerate block types — Gutenberg hands us
 * the correct updater for any block, including table cells.
 */

const richTextRef = { value: null, onChange: null };

wp.richText.registerFormatType('cherrylink/context', {
    title: 'CherryLink',
    tagName: 'span',
    className: 'cl-sel',
    edit({ value, onChange }) {
        richTextRef.value = value;
        richTextRef.onChange = onChange;
        return null; // no toolbar UI — sidebar handles everything
    },
});

/**
 * Apply a core/link format at the current rich-text selection.
 * Returns true if a selection was found and the link was applied, false otherwise.
 */
export function applyLinkAtSelection(url, title) {
    const { value, onChange } = richTextRef;
    if (!value || !onChange || value.start === value.end) return false;

    onChange(
        wp.richText.applyFormat(
            value,
            { type: 'core/link', attributes: { url, ...(title ? { title } : {}) } },
            value.start,
            value.end
        )
    );
    return true;
}
