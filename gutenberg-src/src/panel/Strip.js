import { __, sprintf } from '@wordpress/i18n';

export const countWords = (text) => (String(text || '').match(/[\p{L}\p{N}]+/gu) || []).length;

/**
 * Soft link-count advice. Not a Google rule: common practice is ~1 internal link per 200–300 words,
 * more than ~1 per 100 reads as spammy, 0 incoming = orphan, under 3 incoming = weak.
 * ponytail: fixed heuristics; make them settings if users ask.
 */
export function linkAdvice(words, out, inc) {
    const rec = words >= 100 ? { min: Math.max(1, Math.floor(words / 300)), max: Math.max(1, Math.ceil(words / 200)) } : null;
    let status = 'ok';
    if (inc === 0) status = 'orphan';
    else if (rec && out < rec.min) status = 'few-out';
    else if (out > Math.max(3, Math.ceil(words / 100))) status = 'many-out';
    else if (inc < 3) status = 'few-in';
    return { rec, status };
}

const fmt = (n) => String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');

/** Shown only when the plan limit actually cuts the index: more posts than the plan allows. */
export const planLimited = (plan) => !!(plan && plan.post_limit && plan.total > plan.post_limit);

export default function Strip({ out, inc, words, donorCount, plan, postLearned, upgradeUrl, onLinks, onGoIncoming, onGoSuggestions }) {
    const { rec, status } = linkAdvice(words, out, inc);
    const range = rec && (rec.min === rec.max ? String(rec.min) : `${rec.min}–${rec.max}`);
    let chip = null;
    if (status === 'orphan') {
        chip = donorCount > 0 ? (
            <button type="button" className="cl-chip cl-warn" onClick={onGoIncoming}>
                {sprintf(__('Нет входящих · %d статьи могут сослаться', 'cherrylink'), donorCount)}
            </button>
        ) : <span className="cl-chip cl-warn cl-static">{__('Нет входящих ссылок', 'cherrylink')}</span>;
    } else if (status === 'few-out') {
        chip = <button type="button" className="cl-chip cl-warn" onClick={onGoSuggestions}>{sprintf(__('Мало исходящих · нужно %s', 'cherrylink'), range)}</button>;
    } else if (status === 'many-out') {
        chip = <span className="cl-chip cl-warn cl-static" title={__('Больше одной ссылки на ~100 слов мешает читать и выглядит как спам.', 'cherrylink')}>{__('Много ссылок', 'cherrylink')}</span>;
    } else if (status === 'few-in') {
        chip = <button type="button" className="cl-chip cl-soft" onClick={onGoIncoming}>{__('Мало входящих', 'cherrylink')}</button>;
    } else if (out > 0) {
        chip = <span className="cl-chip cl-ok">{__('Ссылок достаточно', 'cherrylink')}</span>;
    }
    return (
        <div className="cl-strip">
            <span className="cl-count">
                <button type="button" className="cl-countbtn" onClick={() => onLinks('out')}
                    title={rec ? sprintf(__('Рекомендуется %1$s исходящих для %2$d слов', 'cherrylink'), range, words) : undefined}>
                    {__('Исходящих', 'cherrylink')} <b>{out}</b>{rec && <span className="cl-rec">/{range}</span>}
                </button>
                {' · '}
                <button type="button" className="cl-countbtn" onClick={() => onLinks('in')}>
                    {__('Входящих', 'cherrylink')} <b>{inc}</b>
                </button>
            </span>
            {chip}
            {planLimited(plan) && (
                <span className="cl-plan">
                    {sprintf(__('Проиндексировано %1$s из %2$s', 'cherrylink'), fmt(plan.indexed == null ? plan.post_limit : plan.indexed), fmt(plan.total))}
                    {upgradeUrl && <> · <a href={upgradeUrl} target="_blank" rel="noopener noreferrer">{__('Расширить', 'cherrylink')}</a></>}
                </span>
            )}
            {planLimited(plan) && postLearned === false && (
                <p className="cl-plan-note">
                    {sprintf(
                        /* translators: %s: plan post limit. "Learned" = post analysed for suggestions; counts toward the plan limit. */
                        __('This post is outside your %s learned posts, so other posts won\'t suggest links to it. Its own suggestions and link health still work. Updating it makes it one of your newest posts, so it gets learned.', 'cherrylink'),
                        fmt(plan.post_limit))}
                    {upgradeUrl && <> <a href={upgradeUrl} target="_blank" rel="noopener noreferrer">{__('Upgrade to learn every post', 'cherrylink')}</a></>}
                </p>
            )}
        </div>
    );
}
