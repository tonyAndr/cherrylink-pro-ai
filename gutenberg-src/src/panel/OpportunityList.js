import { __, sprintf } from '@wordpress/i18n';
import Context from './Context';
import { IconChevron } from './icons';
import { hiddenKey, topScore } from './opportunities';
import { Rel } from './PostList';

export default function OpportunityList({
    items, loading, hiddenList, showHidden, altIndex, lastInsert,
    onInsert, onAlt, onHide, onRestore, onToggleHidden, onUndo, onReveal, onClearReveal,
}) {
    const top = topScore(items.flatMap((o) => o.targets));
    const hiddenBlock = hiddenList.length ? (
        <>
            <button type="button" className="cl-btn cl-ghost" onClick={onToggleHidden} aria-expanded={showHidden}>
                <IconChevron open={showHidden} /> {sprintf(__('Hidden suggestions (%d)', 'cherrylink'), hiddenList.length)}
            </button>
            {showHidden && hiddenList.map((h) => (
                <div key={h.key} className="cl-done cl-muted">
                    <span>«{h.phrase}» → {h.title}</span>
                    <button type="button" className="cl-btn" onClick={() => onRestore(h.key)}>{__('Restore', 'cherrylink')}</button>
                </div>
            ))}
        </>
    ) : null;

    return (
        <>
            {lastInsert && (
                <div className="cl-done">
                    <span>{__('Added', 'cherrylink')}: «{lastInsert.text}» → {lastInsert.title}</span>
                    <button type="button" className="cl-btn" onClick={onUndo}>{__('Undo', 'cherrylink')}</button>
                </div>
            )}
            {loading && !items.length ? null : !items.length ? (
                <div className="cl-empty">{__('No more suggestions. You can pick a post manually in the “All posts” tab.', 'cherrylink')}</div>
            ) : (
                <p className="cl-hint">{__('Phrases from your text that fit a link. Hover to see the phrase in the text.', 'cherrylink')}</p>
            )}
            {items.map((o) => {
                const phrase = o.suggestion.phrase;
                const idx = (altIndex[phrase.toLowerCase()] || 0) % o.targets.length;
                const t = o.targets[idx];
                const sug = t.suggestions.find((s) => s.phrase.toLowerCase() === phrase.toLowerCase()) || o.suggestion;
                return (
                    <div
                        key={phrase.toLowerCase()}
                        className="cl-card"
                        tabIndex={0}
                        onMouseEnter={() => onReveal(phrase, sug.block_index)}
                        onFocus={() => onReveal(phrase, sug.block_index)}
                        onMouseLeave={onClearReveal}
                        onBlur={onClearReveal}
                    >
                        <Context text={sug.context} />
                        <div className="cl-target"><span className="cl-arrow">→</span><span className="cl-t">{t.title}</span><Rel score={t.score} top={top} /></div>
                        <div className="cl-actions">
                            <button type="button" className="cl-btn cl-primary" onClick={() => onInsert(t, sug)}>{__('Insert link', 'cherrylink')}</button>
                            {o.targets.length > 1 && (
                                <button type="button" className="cl-btn" onClick={() => onAlt(phrase)}>
                                    {sprintf(__('Another post (%1$d/%2$d)', 'cherrylink'), idx + 1, o.targets.length)}
                                </button>
                            )}
                            <button type="button" className="cl-btn cl-ghost" onClick={() => onHide(hiddenKey(t.post_id, phrase), t, phrase)}>{__('Hide', 'cherrylink')}</button>
                        </div>
                    </div>
                );
            })}
            {hiddenBlock}
        </>
    );
}
