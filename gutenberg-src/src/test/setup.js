require('@testing-library/jest-dom');

// WordPress globals expected by plugin components
global.ajax_var = { url: 'http://localhost/wp-admin/admin-ajax.php' };

global.cherrylink_options = {
    post_id: '42',
    multilink: '',
    show_cat_filter: 'false',
};

global.wp = {
    data: {
        select: jest.fn(() => ({
            getCurrentPostId: jest.fn(() => 42),
            getEditedPostAttribute: jest.fn(() => ''),
            getBlocks: jest.fn(() => []),
        })),
        subscribe: jest.fn(() => jest.fn()), // returns unsubscribe fn
        dispatch: jest.fn(() => ({
            updateBlockAttributes: jest.fn(),
            selectBlock: jest.fn(),
        })),
    },
    richText: {
        create: jest.fn(({ html }) => ({ text: html || '', formats: [], replacements: [], start: 0, end: 0 })),
        getTextContent: jest.fn((value) => value.text || ''),
        applyFormat: jest.fn((value) => value),
        toHTMLString: jest.fn(({ value }) => value.text || ''),
        registerFormatType: jest.fn(),
    },
};
