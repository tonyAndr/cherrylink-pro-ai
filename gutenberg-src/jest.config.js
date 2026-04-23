module.exports = {
    preset: '@wordpress/jest-preset-default',
    setupFilesAfterFramework: [],
    setupFilesAfterEnv: [
        '<rootDir>/src/test/setup.js',
    ],
    // @wordpress/* packages are WP globals at runtime — provide lightweight mocks
    moduleNameMapper: {
        '\\.(scss|css)$': require.resolve('@wordpress/jest-preset-default/scripts/style-mock.js'),
        '^@wordpress/data$': '<rootDir>/src/test/mocks/@wordpress/data.js',
        '^@wordpress/components$': '<rootDir>/src/test/mocks/@wordpress/components.js',
        '^@wordpress/element$': 'react',
        '^@wordpress/i18n$': '<rootDir>/src/test/mocks/@wordpress/i18n.js',
    },
};
