const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const DependencyExtractionWebpackPlugin = require("@wordpress/dependency-extraction-webpack-plugin");
const path = require( 'path' );
const fs = require( 'fs' );

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

// After each build, copy artifacts to ../js/ so the live WP bind-mount picks them up.
class CopyToJsPlugin {
    apply( compiler ) {
        compiler.hooks.afterEmit.tap( 'CopyToJsPlugin', ( compilation ) => {
            const src = path.resolve( __dirname, 'build' );
            const dest = path.resolve( __dirname, '../js' );
            const copyMap = {
                'cherry-gutenberg.js': 'cherry-gutenberg.js',
                'cherry-gutenberg.asset.php': 'cherry-gutenberg.asset.php',
                'index.css': 'cherry-gutenberg.css',
            };
            for ( const [ srcFile, destFile ] of Object.entries( copyMap ) ) {
                try {
                    fs.copyFileSync( path.join( src, srcFile ), path.join( dest, destFile ) );
                } catch ( e ) {
                    compilation.warnings.push( new Error( `CopyToJsPlugin: ${ e.message }` ) );
                }
            }
        } );
    }
}

module.exports = {
    ...defaultConfig,
    module: { ...defaultConfig.module, rules },
    output: {
        ...defaultConfig.output,
        filename: 'cherry-gutenberg.js',
        path: path.resolve( process.cwd(), 'build' ),
    },
    plugins: [
        // Replace the default DependencyExtractionWebpackPlugin so we can bundle
        // react/jsx-runtime inline — WP 6.5 doesn't register react-jsx-runtime.
        ...( defaultConfig.plugins || [] ).filter(
            p => !( p instanceof DependencyExtractionWebpackPlugin )
        ),
        new DependencyExtractionWebpackPlugin( {
            requestToExternal( request ) {
                if ( request === 'react/jsx-runtime' ) return false;
            },
        } ),
        new CopyToJsPlugin(),
    ],
};