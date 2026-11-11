import { flattenBlocks, BlockAttributes } from '../blockUtils';

describe('flattenBlocks', () => {
    it('returns empty array for empty input', () => {
        expect(flattenBlocks([])).toEqual([]);
    });

    it('returns flat list of blocks with no innerBlocks', () => {
        const blocks = [
            { clientId: 'a', innerBlocks: [] },
            { clientId: 'b', innerBlocks: [] },
        ];
        expect(flattenBlocks(blocks).map(b => b.clientId)).toEqual(['a', 'b']);
    });

    it('flattens nested innerBlocks recursively', () => {
        const blocks = [
            {
                clientId: 'a',
                innerBlocks: [
                    {
                        clientId: 'a1',
                        innerBlocks: [
                            { clientId: 'a1a', innerBlocks: [] },
                        ],
                    },
                ],
            },
            { clientId: 'b', innerBlocks: [] },
        ];
        expect(flattenBlocks(blocks).map(b => b.clientId)).toEqual(['a', 'a1', 'a1a', 'b']);
    });

    it('handles blocks without innerBlocks property', () => {
        const blocks = [{ clientId: 'a' }, { clientId: 'b' }];
        expect(flattenBlocks(blocks).map(b => b.clientId)).toEqual(['a', 'b']);
    });
});

describe('BlockAttributes', () => {
    it('covers core paragraph', () => {
        expect(BlockAttributes['core/paragraph']).toEqual(['content']);
    });

    it('covers core quote with two attributes', () => {
        expect(BlockAttributes['core/quote']).toEqual(['value', 'citation']);
    });

    it('does not include core/table (handled separately)', () => {
        expect(BlockAttributes['core/table']).toBeUndefined();
    });
});
