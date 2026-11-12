import { render, screen, fireEvent } from '@testing-library/react';
import Strip, { countWords, linkAdvice, planLimited } from '../Strip';

describe('linkAdvice', () => {
    it('recommends ~1 link per 200-300 words', () => {
        expect(linkAdvice(1200, 4, 5).rec).toEqual({ min: 4, max: 6 });
        expect(linkAdvice(1200, 4, 5).status).toBe('ok');
        expect(linkAdvice(60, 0, 5).rec).toBeNull();
    });
    it('ranks orphan > few outgoing > too many > few incoming', () => {
        expect(linkAdvice(1200, 0, 0).status).toBe('orphan');
        expect(linkAdvice(1200, 1, 1).status).toBe('few-out');
        expect(linkAdvice(300, 5, 1).status).toBe('many-out');
        expect(linkAdvice(1200, 5, 2).status).toBe('few-in');
    });
    it('counts words in any script', () => {
        expect(countWords('Привет, мир! Hello — wörld 42')).toBe(5);
    });
});

describe('Strip plan line', () => {
    const base = { out: 3, inc: 4, words: 900, donorCount: 0, onLinks() {}, onGoIncoming() {}, onGoSuggestions() {} };

    it('is limited only when total exceeds the plan limit', () => {
        expect(planLimited({ post_limit: 1000, total: 2340 })).toBe(true);
        expect(planLimited({ post_limit: 1000, total: 1000 })).toBe(false);
        expect(planLimited({ post_limit: null, total: 5000 })).toBe(false);
        expect(planLimited(null)).toBe(false);
    });

    it('renders "indexed of total" with an upgrade link', () => {
        render(<Strip {...base} plan={{ post_limit: 1000, indexed: 1000, total: 2340 }} upgradeUrl="https://example.test/up" />);
        expect(screen.getByText(/Indexed 1\s000 of 2\s340/)).toBeInTheDocument();
        expect(screen.getByText('Upgrade')).toHaveAttribute('href', 'https://example.test/up');
    });

    it('explains why a post outside the limit is not suggested elsewhere', () => {
        const plan = { post_limit: 1000, indexed: 1000, total: 2340 };
        const { rerender } = render(<Strip {...base} plan={plan} postLearned={false} upgradeUrl="https://example.test/up" />);
        expect(screen.getByText(/other posts won't suggest links to it/)).toBeInTheDocument();
        expect(screen.getByText('Upgrade to learn every post')).toHaveAttribute('href', 'https://example.test/up');
        rerender(<Strip {...base} plan={plan} postLearned={true} upgradeUrl="https://example.test/up" />);
        expect(screen.queryByText(/other posts won't suggest links to it/)).toBeNull();
    });

    it('tells the user the site data was removed and links to the dashboard', () => {
        const { rerender } = render(<Strip {...base} plan={null} indexExpired dashboardUrl="https://example.test/wp-admin/admin.php?page=cherrylink" />);
        expect(screen.getByText(/site data was removed after 180 days/)).toBeInTheDocument();
        expect(screen.getByText('Analyze site')).toHaveAttribute('href', 'https://example.test/wp-admin/admin.php?page=cherrylink');
        rerender(<Strip {...base} plan={null} indexExpired={false} />);
        expect(screen.queryByText(/site data was removed/)).toBeNull();
    });

    it('renders nothing extra without a limit', () => {
        render(<Strip {...base} plan={null} />);
        expect(screen.queryByText(/Indexed/)).toBeNull();
    });
});

describe('Strip key page hints', () => {
    const base = { out: 3, inc: 4, words: 900, donorCount: 0, plan: null, onLinks() {}, onGoIncoming() {}, onGoSuggestions() {} };
    const key = { post_id: 5, title: 'Hub guide', url: 'https://s.test/hub/', view_url: 'https://s.test/hub/' };

    it('hints at the key page until the live links include it, and opens suggestions', () => {
        const go = jest.fn();
        const kp = { is_key: false, key, members_not_linking: 0 };
        const { rerender } = render(<Strip {...base} links={[{ url: '/other/' }]} keyPage={kp} onGoSuggestions={go} />);
        fireEvent.click(screen.getByText('Doesn\'t link to key page “Hub guide” yet'));
        expect(go).toHaveBeenCalled();
        rerender(<Strip {...base} links={[{ url: 'https://s.test/hub' }]} keyPage={kp} onGoSuggestions={go} />);
        expect(screen.queryByText(/key page “Hub guide”/)).toBeNull();
    });

    it('a key page shows how many related posts do not link here (singular via the i18n mock) and opens incoming', () => {
        const go = jest.fn();
        const kp = { is_key: true, key, members_not_linking: 1 };
        render(<Strip {...base} links={[]} keyPage={kp} onGoIncoming={go} />);
        fireEvent.click(screen.getByText('Key page · 1 related post doesn\'t link here'));
        expect(go).toHaveBeenCalled();
    });

    it('offers mark / unmark only to users who may pin', () => {
        const pin = jest.fn();
        const kp = { is_key: true, pinned: false, key, members_not_linking: 0 };
        const { rerender } = render(<Strip {...base} links={[]} keyPage={kp} canKeyPin onKeyPin={pin} />);
        fireEvent.click(screen.getByText('Unmark key page'));
        expect(pin).toHaveBeenCalledWith('demote');
        rerender(<Strip {...base} links={[]} keyPage={{ ...kp, is_key: false, key: null }} canKeyPin onKeyPin={pin} />);
        fireEvent.click(screen.getByText('Mark as key page'));
        expect(pin).toHaveBeenLastCalledWith('pin');
        rerender(<Strip {...base} links={[]} keyPage={kp} canKeyPin={false} onKeyPin={pin} />);
        expect(screen.queryByText('Unmark key page')).toBeNull();
    });
});
