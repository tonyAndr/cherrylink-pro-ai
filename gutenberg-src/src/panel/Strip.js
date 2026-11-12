import { __, _n, sprintf } from '@wordpress/i18n';

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

export default function Strip({ out, inc, words, donorCount, plan, postLearned, upgradeUrl, indexExpired, dashboardUrl, onLinks, onGoIncoming, onGoSuggestions }) {
    const { rec, status } = linkAdvice(words, out, inc);
    const range = rec && (rec.min === rec.max ? String(rec.min) : `${rec.min}–${rec.max}`);
    let chip = null;
    if (status === 'orphan') {
        chip = donorCount > 0 ? (
            <button type="button" className="cl-chip cl-warn" onClick={onGoIncoming}>
                {sprintf(_n('No incoming links · %d post could link here', 'No incoming links · %d posts could link here', donorCount, 'cherrylink'), donorCount)}
            </button>
        ) : <span className="cl-chip cl-warn cl-static">{__('No incoming links', 'cherrylink')}</span>;
    } else if (status === 'few-out') {
        chip = <button type="button" className="cl-chip cl-warn" onClick={onGoSuggestions}>{sprintf(__('Too few outgoing links · need %s', 'cherrylink'), range)}</button>;
    } else if (status === 'many-out') {
        chip = <span className="cl-chip cl-warn cl-static" title={__('More than one link per ~100 words hurts readability and looks like spam.', 'cherrylink')}>{__('Too many links', 'cherrylink')}</span>;
    } else if (status === 'few-in') {
        chip = <button type="button" className="cl-chip cl-soft" onClick={onGoIncoming}>{__('Few incoming links', 'cherrylink')}</button>;
    } else if (out > 0) {
        chip = <span className="cl-chip cl-ok">{__('Enough links', 'cherrylink')}</span>;
    }
    return (
        <div className="cl-strip">
            <span className="cl-count">
                <button type="button" className="cl-countbtn" onClick={() => onLinks('out')}
                    title={rec ? sprintf(__('Recommended: %1$s outgoing links for %2$d words', 'cherrylink'), range, words) : undefined}>
                    {__('Outgoing', 'cherrylink')} <b>{out}</b>{rec && <span className="cl-rec">/{range}</span>}
                </button>
                {' · '}
                <button type="button" className="cl-countbtn" onClick={() => onLinks('in')}>
                    {__('Incoming', 'cherrylink')} <b>{inc}</b>
                </button>
            </span>
            {chip}
            {planLimited(plan) && (
                <span className="cl-plan">
                    {sprintf(__('Indexed %1$s of %2$s', 'cherrylink'), fmt(plan.indexed == null ? plan.post_limit : plan.indexed), fmt(plan.total))}
                    {upgradeUrl && <> · <a href={upgradeUrl} target="_blank" rel="noopener noreferrer">{__('Upgrade', 'cherrylink')}</a></>}
                </span>
            )}
            {indexExpired && (
                <p className="cl-plan-note">
                    {__('Your site data was removed after 180 days of inactivity. Analyze the site to get suggestions back.', 'cherrylink')}
                    {dashboardUrl && <> <a href={dashboardUrl}>{__('Analyze site', 'cherrylink')}</a></>}
                </p>
            )}
            {planLimited(plan) && postLearned === false && (
                <p className="cl-plan-note">
                    {sprintf(
                        /* translators: %s: plan post limit. "Learned" = post analyzed for suggestions; counts toward the plan limit. */
                        __('This post is outside your %s learned posts, so other posts won\'t suggest links to it. Its own suggestions and link health still work. Updating it makes it one of your newest posts, so it gets learned.', 'cherrylink'),
                        fmt(plan.post_limit))}
                    {upgradeUrl && <> <a href={upgradeUrl} target="_blank" rel="noopener noreferrer">{__('Upgrade to learn every post', 'cherrylink')}</a></>}
                </p>
            )}
        </div>
    );
}
