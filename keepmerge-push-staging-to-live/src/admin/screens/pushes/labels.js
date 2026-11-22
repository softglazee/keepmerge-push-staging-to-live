import { __, sprintf } from '@wordpress/i18n';

/**
 * Why the markup gate held an item, in words (Apply\Markup_Gate rules).
 *
 * @param {string} rule Rule ID.
 * @return {string} Text.
 */
export function gateRuleLabel( rule ) {
	const labels = {
		kses: __(
			"WordPress's HTML filter would change it",
			'keepmerge-push-staging-to-live'
		),
		styles_css: __( 'custom CSS', 'keepmerge-push-staging-to-live' ),
		insecure_styles: __(
			'style properties WordPress removes',
			'keepmerge-push-staging-to-live'
		),
		too_deep: __(
			'nested too deeply to check',
			'keepmerge-push-staging-to-live'
		),
		'pattern:tag': __(
			'an active HTML tag (script, iframe, form…)',
			'keepmerge-push-staging-to-live'
		),
		'pattern:srcdoc': __(
			'an iframe srcdoc attribute',
			'keepmerge-push-staging-to-live'
		),
		'pattern:javascript_url': __(
			'a javascript: link',
			'keepmerge-push-staging-to-live'
		),
		'pattern:vbscript_url': __(
			'a vbscript: link',
			'keepmerge-push-staging-to-live'
		),
		'pattern:data_html_url': __(
			'a data:text/html link',
			'keepmerge-push-staging-to-live'
		),
		'pattern:event_attribute': __(
			'an event attribute (onclick, onload…)',
			'keepmerge-push-staging-to-live'
		),
		'pattern:escaped_tag': __(
			'an encoded active HTML tag',
			'keepmerge-push-staging-to-live'
		),
	};
	if ( labels[ rule ] ) {
		return labels[ rule ];
	}
	if ( 0 === String( rule ).indexOf( 'pattern:custom_' ) ) {
		return __(
			"text this site's settings look for",
			'keepmerge-push-staging-to-live'
		);
	}
	return rule;
}

/**
 * The kind of an item in words, with its type when that says more.
 *
 * @param {string} kind Kind.
 * @param {string} type Post type, taxonomy or option name.
 * @return {string} Text.
 */
export function kindName( kind, type ) {
	const kinds = {
		post: __( 'Content', 'keepmerge-push-staging-to-live' ),
		product: __( 'Product', 'keepmerge-push-staging-to-live' ),
		attachment: __( 'Media', 'keepmerge-push-staging-to-live' ),
		pattern: __( 'Pattern', 'keepmerge-push-staging-to-live' ),
		navigation: __( 'Navigation', 'keepmerge-push-staging-to-live' ),
		template: __( 'Template', 'keepmerge-push-staging-to-live' ),
		template_part: __( 'Template part', 'keepmerge-push-staging-to-live' ),
		global_styles: __( 'Global styles', 'keepmerge-push-staging-to-live' ),
		term: __( 'Term', 'keepmerge-push-staging-to-live' ),
		menu: __( 'Menu', 'keepmerge-push-staging-to-live' ),
		option: __( 'Option', 'keepmerge-push-staging-to-live' ),
		widgets: __( 'Widgets', 'keepmerge-push-staging-to-live' ),
	};
	const name = kinds[ kind ] || kind;
	if ( type && ( 'post' === kind || 'term' === kind || 'option' === kind ) ) {
		return sprintf(
			/* translators: 1: kind of item, 2: post type, taxonomy or option name. */
			__( '%1$s (%2$s)', 'keepmerge-push-staging-to-live' ),
			name,
			type
		);
	}
	return name;
}
