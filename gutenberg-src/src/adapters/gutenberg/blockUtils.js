export const BlockAttributes = {
    'core/paragraph':    ['content'],
    'core/heading':      ['content'],
    'core/list-item':    ['content'],
    'core/quote':        ['value', 'citation'],
    'core/pullquote':    ['value', 'citation'],
    'core/preformatted': ['content'],
    'core/verse':        ['content'],
    'core/button':       ['text'],
    'core/image':        ['caption'],
    'core/gallery':      ['caption'],
    'core/audio':        ['caption'],
    'core/video':        ['caption'],
    'core/embed':        ['caption'],
};

export function flattenBlocks(blocks) {
    return blocks.reduce(
        (acc, b) => acc.concat([b], flattenBlocks(b.innerBlocks || [])),
        []
    );
}
