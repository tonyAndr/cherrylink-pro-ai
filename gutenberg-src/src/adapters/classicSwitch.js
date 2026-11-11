import { createTinymceAdapter } from './tinymce';
import { createTextareaAdapter } from './textarea';

/**
 * Delegates to the TinyMCE adapter while the visual tab is active, otherwise to the textarea.
 * @param {{ editorId?: string }} opts
 */
export function createClassicAdapter({ editorId = 'content', getEditor, getTextarea } = {}) {
    const tm = () => {
        if (getEditor) return getEditor();
        const t = window.tinymce;
        return t ? (t.get(editorId) || t.activeEditor || null) : null;
    };
    const ta = () => (getTextarea ? getTextarea() : document.getElementById(editorId));

    const visual = createTinymceAdapter(tm);
    const text = createTextareaAdapter(ta);

    const active = () => {
        const e = tm();
        return e && !(e.isHidden && e.isHidden()) && e.getBody && e.getBody() ? visual : text;
    };

    const adapter = {};
    ['getHtml', 'getText', 'getSelection', 'applyLinkToSelection', 'applyLinkToPhrase', 'insertAtCaret', 'removeLink', 'listLinks', 'reveal', 'clearReveal'].forEach((m) => {
        adapter[m] = (...args) => active()[m](...args);
    });
    // Refs from a tab that is no longer active still need to be undone by the adapter that created them.
    adapter.removeLink = (ref) => (ref && ref.el !== undefined ? visual : text).removeLink(ref);
    adapter.onChange = (cb) => {
        const offs = [visual.onChange(cb), text.onChange(cb)];
        return () => offs.forEach((f) => f());
    };
    return adapter;
}
