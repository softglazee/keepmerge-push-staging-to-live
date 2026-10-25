/**
 * Extends the @wordpress/scripts webpack config with the admin entry point.
 *
 * The admin app lives in src/admin/ (one entry, loaded only on Tools > Staging
 * Push). The output is build/admin.js, build/admin.css and build/admin.asset.php,
 * whose dependency list makes WordPress load React and the @wordpress packages
 * from core instead of from this bundle.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( process.cwd(), 'src', 'admin', 'index.js' ),
	},
};
