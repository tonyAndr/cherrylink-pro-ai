let edit;
const applyFormat = jest.fn(() => 'applied');

beforeAll(() => {
    global.wp = {
        richText: {
            registerFormatType: (name, settings) => { edit = settings.edit; },
            applyFormat,
        },
    };
});

function load() {
    return require('../cherryRichText').applyLinkAtSelection;
}

function select(text, start, end) {
    const onChange = jest.fn();
    edit({ value: { text, start, end }, onChange });
    return onChange;
}

describe('applyLinkAtSelection', () => {
    beforeEach(() => applyFormat.mockClear());

    it('applies a link to a real selection', () => {
        const apply = load();
        const onChange = select('hello world', 0, 5);
        expect(apply('http://x')).toBe(true);
        expect(onChange).toHaveBeenCalledWith('applied');
    });

    it('rejects a collapsed selection', () => {
        const apply = load();
        const onChange = select('hello world', 3, 3);
        expect(apply('http://x')).toBe(false);
        expect(onChange).not.toHaveBeenCalled();
    });

    it('rejects a whitespace-only selection', () => {
        const apply = load();
        const onChange = select('hello   world', 5, 8);
        expect(apply('http://x')).toBe(false);
        expect(onChange).not.toHaveBeenCalled();
    });
});
