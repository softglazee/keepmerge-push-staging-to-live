/**
 * Babel configuration for the admin build.
 *
 * WordPress registers react-jsx-runtime only from 6.6 and this plugin supports
 * 6.5, so JSX is compiled with the classic runtime to createElement() and
 * Fragment from @wordpress/element. Listed before the preset's own JSX plugin,
 * this transform runs first and leaves the automatic one nothing to do.
 *
 * @param {Object} api Babel config API.
 * @return {Object} Babel config.
 */
module.exports = ( api ) => {
	api.cache( true );

	return {
		presets: [ require.resolve( '@wordpress/babel-preset-default' ) ],
		plugins: [
			[
				require.resolve( '@babel/plugin-transform-react-jsx' ),
				{
					runtime: 'classic',
					pragma: 'createElement',
					pragmaFrag: 'Fragment',
				},
			],
		],
	};
};
