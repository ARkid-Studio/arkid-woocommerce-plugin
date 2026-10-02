const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const WooCommerceDependencyExtractionWebpackPlugin = require('@woocommerce/dependency-extraction-webpack-plugin');

module.exports = {
	...defaultConfig,
	entry: {
		index: './src/index.js',
		storefront: './src/storefront/boot.js',
		'blocks/embed/index': './src/blocks/embed/index.js',
		'blocks/embed/view': './src/blocks/embed/view.js',
	},
	plugins: [
		...defaultConfig.plugins.filter(
			(plugin) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new WooCommerceDependencyExtractionWebpackPlugin(),
	],
};
