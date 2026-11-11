import { countWords, linkAdvice } from '../Strip';

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
