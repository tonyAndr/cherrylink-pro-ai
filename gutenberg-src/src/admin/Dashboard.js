import { __, _n, sprintf } from '@wordpress/i18n';
import { useAdmin, useLinkHealth, useSync } from './ctx';
import { Badge, Card, ExpiredNotice, Link, Notice, Title, num } from './ui';

export const planName = (p) => ({ free: __('Free', 'cherrylink-ai'), pro: __('Pro', 'cherrylink-ai'), legacy: __('Legacy', 'cherrylink-ai') }[p] || __('Unknown plan', 'cherrylink-ai'));

export default function Dashboard() {
    const { state, urls } = useAdmin();
    const expired = !!state.index_expired;
    const { data, busy, error } = useLinkHealth(expired);
    const { sync, error: syncError, start } = useSync();
    const { connection, counts } = state;
    const s = data && data.summary;
    const limit = connection.post_limit;
    const orphans = (data ? data.rows : []).filter((r) => r.orphan && r.learned).slice(0, 3);
    const rows = s ? [
        [__('Broken links', 'cherrylink-ai'), s.broken, 'broken'],
        [__('No outgoing links', 'cherrylink-ai'), s.no_outgoing, 'no_outgoing'],
        [__('Repeated links', 'cherrylink-ai'), s.repeats, 'repeats'],
        [__('Links to itself', 'cherrylink-ai'), s.self_links, 'self'],
    ] : [];

    return (
        <>
            <Title extra={
                <span className="cl-plan">
                    <Badge tone={connection.plan === 'free' ? 'accent' : 'ok'}>{planName(connection.plan)}</Badge>
                    <span>{limit
                        ? sprintf(
                            /* translators: 1: posts learned (analyzed for suggestions; counts toward the plan limit), 2: total posts on the site */
                            __('%1$s / %2$s learned', 'cherrylink-ai'), num(counts.learned), num(counts.total))
                        : sprintf(
                            /* translators: %s: posts learned (analyzed for suggestions) */
                            __('%s learned', 'cherrylink-ai'), num(counts.learned))}</span>
                    {connection.plan === 'free' && <Link href={urls.upgrade} target="_blank" rel="noreferrer">{__('Upgrade', 'cherrylink-ai')}</Link>}
                </span>
            }>CherryLink</Title>

            {expired && <ExpiredNotice onStart={start} />}
            {syncError && <Notice tone="danger">{syncError}</Notice>}
            {sync.status === 'running' && (
                <Notice tone="info">{__('Analysis is running in the background.', 'cherrylink-ai')} <a href={urls.index}>{__('See progress', 'cherrylink-ai')}</a></Notice>
            )}
            {error && <Notice tone="danger">{error}</Notice>}

            {!expired && <Card aria-label={__('Biggest opportunity', 'cherrylink-ai')} className="cl-card cl-hero">
                <div className="cl-stack">
                    <span className="cl-eyebrow">{__('Biggest opportunity', 'cherrylink-ai')}</span>
                    <span className="cl-huge">{busy && !s ? '…' : num(s ? s.orphans : null)}</span>
                    <strong>{__('pages nobody links to', 'cherrylink-ai')}</strong>
                    <span className="cl-muted">{__('Orphan pages are hard for readers and search engines to find from other posts. Each one is a good place to add an internal link.', 'cherrylink-ai')}</span>
                </div>
                <div className="cl-stack">
                    {orphans.length > 0 && <span className="cl-eyebrow">{__('Easiest wins: orphans that are learned', 'cherrylink-ai')}</span>}
                    {orphans.map((r) => (
                        <a key={r.post_id} className="cl-winrow" href={`${urls.links}#orphans-${r.post_id}`}><span>{r.title}</span><span className="cl-link">{__('Add links →', 'cherrylink-ai')}</span></a>
                    ))}
                    {s && s.orphans > 0 && <a className="cl-link" href={`${urls.links}#orphans`}>{sprintf(
                        /* translators: %s: number of orphan pages */
                        __('See all %s orphan pages', 'cherrylink-ai'), num(s.orphans))}</a>}
                </div>
            </Card>}

            {!expired && <Card aria-label={__('Link health', 'cherrylink-ai')}>
                <div className="cl-between"><h2>{__('Link health', 'cherrylink-ai')}</h2>
                    {s && s.links_total !== null && <span className="cl-muted">{sprintf(
                        /* translators: 1: internal links, 2: posts checked (links read for link health; whole site, no limit) */
                        __('%1$s internal links across %2$s posts', 'cherrylink-ai'), num(s.links_total), num(s.posts_checked ?? counts.checked))}</span>}
                </div>
                <div className="cl-grid4">
                    {rows.map(([label, n, tab]) => (
                        <a key={tab} className="cl-stat" href={`${urls.links}#${tab}`}><span>{label}</span><strong>{num(n)}</strong></a>
                    ))}
                </div>
                {data && data.checked_at && <span className="cl-muted">{sprintf(
                    /* translators: %s: date and time of the last link health check */
                    __('Checked %s.', 'cherrylink-ai'), new Date(data.checked_at).toLocaleString())} <a className="cl-link" href={urls.links}>{__('Open link health', 'cherrylink-ai')}</a></span>}
            </Card>}

            <Card aria-label={__('Plan', 'cherrylink-ai')} className="cl-card cl-between">
                <span>
                    {limit ? (
                        <>
                            <strong>{sprintf(
                                /* translators: 1: posts learned (analyzed for suggestions; counts toward the plan limit), 2: total posts on the site */
                                __('%1$s of %2$s posts learned.', 'cherrylink-ai'), num(counts.learned), num(counts.total))}</strong>{' '}
                            {sprintf(
                                /* translators: %s: plan limit of learned posts */
                                __('Suggestions come from your newest %s; the rest are still covered by link health.', 'cherrylink-ai'), num(limit))}
                        </>
                    ) : (
                        <strong>{sprintf(
                            /* translators: %s: posts learned */
                            _n('%s post learned.', '%s posts learned.', counts.learned || 0, 'cherrylink-ai'), num(counts.learned))}</strong>
                    )}
                </span>
                {limit && <a className="cl-link" href={urls.limits} target="_blank" rel="noreferrer">{__('How the limit works', 'cherrylink-ai')}</a>}
            </Card>
        </>
    );
}
