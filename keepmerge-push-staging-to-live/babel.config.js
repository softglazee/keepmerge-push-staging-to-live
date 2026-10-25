/**
 * Babel configuration for the admin build.
 *
 * The default preset compiles JSX with the automatic runtime, which imports
 * react/jsx-runtime. WordPress only registers the matching script handle
 * (react-jsx-runtime) from 6.6, and this plugin supports 6.5, where the admin
 * script would then never load. The classic runtime compiles JSX to
 * createElement() calls instead, so every JSX file imports createElement and
 * Fragment from @wordpress/element, which core has shipped since 5.0.
 *
 * This plugin is listed before the preset's own JSX plugin, so it transforms
 * the JSX first and the automatic transform finds nothing left to do.
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
