const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

// We extend the default config to override the entry point.
// `@wordpress/scripts` would normally look for `src/index.js`.
module.exports = {
    ...defaultConfig,
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
