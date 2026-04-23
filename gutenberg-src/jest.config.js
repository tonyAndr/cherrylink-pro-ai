module.exports = {
    preset: '@wordpress/jest-preset-default',
    setupFilesAfterEnv: [
        '<rootDir>/src/test/setup.js',
    ],
    // setup.js lives in src/test/ which matches the preset's **/test/* testMatch glob — exclude it
    testPathIgnorePatterns: ['/node_modules/', '/src/test/setup\\.js$'],
    // preset uses bare babel-jest; override to use wp-scripts babel-transform (includes @wordpress/babel-preset-default + JSX)
    transform: {
        '\\.[jt]sx?$': require.resolve('@wordpress/scripts/config/babel-transform'),
    },
    // @wordpress/* packages are WP globals at runtime — provide lightweight mocks
    moduleNameMapper: {
        '\\.(scss|css)$': require.resolve('@wordpress/jest-preset-default/scripts/style-mock.js'),
        '^@wordpress/data$': '<rootDir>/src/test/mocks/@wordpress/data.js',
        '^@wordpress/components$': '<rootDir>/src/test/mocks/@wordpress/components.js',
        '^@wordpress/element$': 'react',
        '^@wordpress/i18n$': '<rootDir>/src/test/mocks/@wordpress/i18n.js',
    },
};
