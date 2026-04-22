const defaultConfig = require("@wordpress/scripts/config/webpack.config");
const path = require( 'path' );
module.exports = {
    ...defaultConfig,
    module: {
        ...defaultConfig.module,
        rules: [
            ...defaultConfig.module.rules,
            {
                test: /\.css$/,
                use: ["style-loader", "css-loader"],
            },
            {
                test: /\.svg$/i,
                issuer: /\.[jt]sx?$/,
                use: ['@svgr/webpack'],
            },
        ]
    },
    optimization: {
        //  minimize: false,
    },
    output: {
		filename: 'cherry-gutenberg.js',
		path: path.resolve( process.cwd(), 'build' ),
	},
};