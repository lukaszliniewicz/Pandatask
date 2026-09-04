const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');
const path = require('path');

// We extend the default config to override the entry point.
// `@wordpress/scripts` would normally look for `src/index.js`.
module.exports = {
    ...defaultConfig,
    plugins: defaultConfig.plugins.map((plugin) => {
        if (!(plugin instanceof MiniCssExtractPlugin)) {
            return plugin;
        }

        return new MiniCssExtractPlugin({
            ...plugin.options,
            // Lazy CSS chunks otherwise have stable numeric URLs (for example,
            // 471.css), so browsers and CDNs can pair a new JS release with an
            // obsolete stylesheet. Keep the entry stylesheet stable because
            // WordPress versions it, and content-hash every lazy stylesheet.
            chunkFilename: '[name].[contenthash:8].css',
        });
    }),
    module: {
        ...defaultConfig.module,
        rules: [
            ...defaultConfig.module.rules,
            {
                test: /node_modules[\\/]mermaid[\\/]dist[\\/]mermaid\.min\.js$/,
                type: 'asset/resource',
                generator: {
                    filename: 'assets/[name].[contenthash:8][ext]',
                },
            },
        ],
    },
    entry: {
        // We define 'main' as our entry point.
        // This will read `src/index.jsx` and output `build/main.js`
        // and any imported CSS/SCSS to `build/main.css`.
        main: path.resolve(process.cwd(), 'src/index.jsx'),
    },
    output: {
        ...defaultConfig.output,
        // The output is placed in the `build` directory in the plugin root.
        path: path.resolve(process.cwd(), 'build'),
        filename: '[name].js', // This will be main.js
    },
};
