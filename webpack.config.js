/**
 * Extends the default @wordpress/scripts build.
 *
 * Blocks are discovered through their block.json. The entries below add the
 * scripts and styles that are not part of a block.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const source = ( file ) => path.resolve( __dirname, 'assets/src', file );

module.exports = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry(),
		admin: source( 'admin/index.js' ),
		editor: source( 'editor/index.js' ),
		frontend: source( 'frontend/index.js' ),
		indicators: source( 'indicators/index.js' ),
	},
};
