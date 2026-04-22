const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const path = require( 'path' );

// CSS uses absolute WP runtime URLs (/wp-content/...) that can't be resolved at build time.
const rules = defaultConfig.module.rules.map( rule => {
    if ( ! Array.isArray( rule.use ) ) return rule;
    const use = rule.use.map( loader => {
        const loaderPath = typeof loader === 'string' ? loader : loader.loader || '';
        if ( loaderPath.includes( '/css-loader/' ) ) {
            return { ...loader, options: { ...( loader.options || {} ), url: false } };
        }
        return loader;
    } );
    return { ...rule, use };
} );

module.exports = {
    ...defaultConfig,
    module: { ...defaultConfig.module, rules },
    output: {
        filename: 'cherry-gutenberg.js',
        path: path.resolve( process.cwd(), 'build' ),
    },
};