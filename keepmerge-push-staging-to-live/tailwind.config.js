/**
 * Tailwind configuration (Tailwind 3.4).
 *
 * Every utility carries the sgsp- prefix (sgsp-flex, md:sgsp-grid-cols-2) and
 * Preflight is off, so nothing reaches wp-admin's own markup. Tailwind 4 would
 * turn the prefix into a variant (sgsp:flex); 3.4 keeps the sgsp- form the
 * project rules use.
 *
 * Colours point at the CSS variables in src/admin/admin.css, so the tokens are
 * defined once and utilities and component styles cannot drift apart.
 */
module.exports = {
	prefix: 'sgsp-',
	corePlugins: {
		preflight: false,
	},
	content: [ './src/**/*.{js,jsx}' ],
	theme: {
		extend: {
			colors: {
				navy: 'var(--sgsp-navy)',
				gold: 'var(--sgsp-gold)',
				success: 'var(--sgsp-success)',
				warning: 'var(--sgsp-warning)',
				danger: 'var(--sgsp-danger)',
			},
			borderRadius: {
				card: 'var(--sgsp-radius-card)',
				control: 'var(--sgsp-radius-control)',
			},
			fontFamily: {
				sans: [ 'var(--sgsp-font-sans)' ],
			},
		},
	},
	plugins: [],
};
