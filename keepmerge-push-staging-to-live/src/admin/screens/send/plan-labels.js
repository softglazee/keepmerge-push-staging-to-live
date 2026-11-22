import { __, sprintf } from '@wordpress/i18n';

/**
 * Words for the plan: classes, actions, badges, warnings, field paths and stages. Every status
 * is shown with words (and an icon through Badge), never by colour alone.
 */

/**
 * A class as a badge: label, tone and a sentence that says what it means.
 *
 * @param {string} cls Class.
 * @return {Object} { label, tone, note }.
 */
export function classInfo( cls ) {
	switch ( cls ) {
		case 'staging_changed':
			return {
				label: __( 'Changed here', 'keepmerge-push-staging-to-live' ),
				tone: 'info',
				note: __(
					'Changed on this site since the last push; live has not changed.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'live_changed':
			return {
				label: __(
					'Changed on live',
					'keepmerge-push-staging-to-live'
				),
				tone: 'neutral',
				note: __(
					'Changed on live only. Live keeps its version unless you choose otherwise.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'both_same':
			return {
				label: __(
					'Same change on both',
					'keepmerge-push-staging-to-live'
				),
				tone: 'success',
				note: __(
					'Both sites made the same change; only the base is recorded.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'conflict':
			return {
				label: __( 'Conflict', 'keepmerge-push-staging-to-live' ),
				tone: 'danger',
				note: __(
					'Both sites changed it differently. Choose which version live keeps.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'new_staging':
			return {
				label: __( 'New here', 'keepmerge-push-staging-to-live' ),
				tone: 'info',
				note: __(
					'Only on this site.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'deleted_staging':
			return {
				label: __( 'Deleted here', 'keepmerge-push-staging-to-live' ),
				tone: 'warning',
				note: __(
					'Deleted on this site; live keeps it unless you choose to trash it there.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'deleted_live_chg':
			return {
				label: __(
					'Deleted on live, changed here',
					'keepmerge-push-staging-to-live'
				),
				tone: 'danger',
				note: __(
					'Live deleted it after this site changed it. Recreate it on live or leave it deleted.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'deleted_live':
			return {
				label: __(
					'Deleted on live',
					'keepmerge-push-staging-to-live'
				),
				tone: 'neutral',
				note: __(
					'Live deleted it and this site did not change it: it stays deleted.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'unsupported':
			return {
				label: __(
					'Cannot be pushed',
					'keepmerge-push-staging-to-live'
				),
				tone: 'warning',
				note: __(
					'It holds data Keepmerge does not transfer (for example a PHP object). Skipped.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'excluded':
			return {
				label: __(
					'Not accepted by live',
					'keepmerge-push-staging-to-live'
				),
				tone: 'warning',
				note: __(
					'Live does not take this item (its type, its theme or its settings). Skipped.',
					'keepmerge-push-staging-to-live'
				),
			};
		default:
			return {
				label: __( 'Unchanged', 'keepmerge-push-staging-to-live' ),
				tone: 'success',
				note: __(
					'The same on both sites.',
					'keepmerge-push-staging-to-live'
				),
			};
	}
}

/**
 * An action as words.
 *
 * @param {string} action Action ('' = undecided).
 * @return {string} Label.
 */
export function actionLabel( action ) {
	const labels = {
		'': __( 'Decide…', 'keepmerge-push-staging-to-live' ),
		none: __( 'Nothing to do', 'keepmerge-push-staging-to-live' ),
		push: __( 'Push', 'keepmerge-push-staging-to-live' ),
		skip: __( 'Skip', 'keepmerge-push-staging-to-live' ),
		keep_live: __( 'Keep live', 'keepmerge-push-staging-to-live' ),
		use_staging: __(
			"Use this site's version",
			'keepmerge-push-staging-to-live'
		),
		record_base: __(
			'Record as in sync',
			'keepmerge-push-staging-to-live'
		),
		create: __( 'Create on live', 'keepmerge-push-staging-to-live' ),
		keep_on_live: __( 'Keep on live', 'keepmerge-push-staging-to-live' ),
		trash_on_live: __(
			'Move to Trash on live',
			'keepmerge-push-staging-to-live'
		),
		recreate: __( 'Recreate on live', 'keepmerge-push-staging-to-live' ),
		leave_deleted: __( 'Leave deleted', 'keepmerge-push-staging-to-live' ),
		push_stock: __( 'Push stock', 'keepmerge-push-staging-to-live' ),
		out_of_scope: __(
			'Not in this plan',
			'keepmerge-push-staging-to-live'
		),
	};
	return undefined === labels[ action ] ? action : labels[ action ];
}

/**
 * A badge code as words and tone.
 *
 * @param {string} badge Badge code.
 * @return {Object|null} { label, tone } or null for badges not shown.
 */
export function badgeInfo( badge ) {
	switch ( badge ) {
		case 'by_dates':
			return {
				label: __(
					'Decided by dates',
					'keepmerge-push-staging-to-live'
				),
				tone: 'warning',
			};
		case 'no_history':
			return {
				label: __(
					'Differs, no history',
					'keepmerge-push-staging-to-live'
				),
				tone: 'neutral',
			};
		case 'changed_both_since_clone':
			return {
				label: __(
					'Both changed since the clone',
					'keepmerge-push-staging-to-live'
				),
				tone: 'neutral',
			};
		case 'matched_slug_date':
			return {
				label: __(
					'Matched by slug and date',
					'keepmerge-push-staging-to-live'
				),
				tone: 'neutral',
			};
		case 'slug_taken':
			return {
				label: __(
					'Slug taken on live',
					'keepmerge-push-staging-to-live'
				),
				tone: 'warning',
			};
		case 'theme_missing':
			return {
				label: __(
					'Theme missing on live',
					'keepmerge-push-staging-to-live'
				),
				tone: 'warning',
			};
		case 'woo_not_on_live':
			return {
				label: __(
					'WooCommerce not active on live',
					'keepmerge-push-staging-to-live'
				),
				tone: 'warning',
			};
		case 'stock_changed_on_live':
			return {
				label: __(
					'Stock changed on live',
					'keepmerge-push-staging-to-live'
				),
				tone: 'neutral',
			};
		default:
			return null;
	}
}

/**
 * A warning code as a title and an explanation.
 *
 * @param {string} code Warning code.
 * @return {Object} { title, text }.
 */
export function warningInfo( code ) {
	switch ( code ) {
		case 'held_for_review':
			return {
				title: __(
					'Will wait for review on live',
					'keepmerge-push-staging-to-live'
				),
				text: __(
					'These items carry markup that WordPress would filter, or script-like text in meta or option values. Live holds them until a live administrator approves, filters or skips each one.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'missing_blocks':
			return {
				title: __(
					'Block types not registered on live',
					'keepmerge-push-staging-to-live'
				),
				text: __(
					'Live does not have the plugin that provides these blocks. The content is pushed as it is; the blocks show as unsupported on live until the plugin is installed there.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'theme_missing':
			return {
				title: __(
					'Theme not installed on live',
					'keepmerge-push-staging-to-live'
				),
				text: __(
					'Templates, template parts and global styles of a theme live does not have are skipped.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'unmapped_refs':
			return {
				title: __(
					'Unmapped numeric references',
					'keepmerge-push-staging-to-live'
				),
				text: __(
					'IDs inside builder data that Keepmerge cannot map to an item. They are pushed unchanged and may point to the wrong item on live.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'unsupported':
			return {
				title: __(
					'Items that cannot be pushed',
					'keepmerge-push-staging-to-live'
				),
				text: __(
					'They hold PHP objects or other data Keepmerge does not transfer. Nothing is guessed: they are skipped.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'excluded_on_live':
			return {
				title: __(
					'Not accepted by live',
					'keepmerge-push-staging-to-live'
				),
				text: __(
					"Live's own settings leave out these types or options. Live decides what it accepts.",
					'keepmerge-push-staging-to-live'
				),
			};
		case 'woo_not_on_live':
			return {
				title: __(
					'WooCommerce is not active on live',
					'keepmerge-push-staging-to-live'
				),
				text: __(
					'Products are skipped while WooCommerce runs on this site only.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'slug_taken':
			return {
				title: __(
					'Slug already used on live',
					'keepmerge-push-staging-to-live'
				),
				text: __(
					'Live has a different item with the same slug. Skipped unless you choose to create it anyway (WordPress then gives it a new slug).',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'blocked':
			return {
				title: __(
					'Waiting for a dependency',
					'keepmerge-push-staging-to-live'
				),
				text: __(
					'These items reference an item that is in conflict, or that will not exist on live. Decide the dependency first, or skip the item.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'dependency_missing':
			return {
				title: __(
					'References to items outside the plan',
					'keepmerge-push-staging-to-live'
				),
				text: __(
					'These items reference objects Keepmerge does not push (an excluded type, for example). Those references may not resolve on live.',
					'keepmerge-push-staging-to-live'
				),
			};
		case 'stock_changed_on_live':
			return {
				title: __(
					'Stock changed on live',
					'keepmerge-push-staging-to-live'
				),
				text: __(
					"Stock is owned by live. It is never pushed while live's stock changed since the last push.",
					'keepmerge-push-staging-to-live'
				),
			};
		default:
			return { title: code, text: '' };
	}
}

/**
 * A warning's detail in words (the markup gate's rule names and field paths).
 *
 * @param {Object} warning { code, detail }.
 * @return {string} Detail.
 */
export function warningDetail( warning ) {
	if ( 'held_for_review' !== warning.code ) {
		return warning.detail;
	}
	const at = warning.detail.lastIndexOf( ': ' );
	const field = -1 === at ? '' : warning.detail.slice( 0, at );
	const rule = -1 === at ? warning.detail : warning.detail.slice( at + 2 );
	let words;
	if ( 'kses' === rule ) {
		words = __(
			"WordPress's HTML filter would change it",
			'keepmerge-push-staging-to-live'
		);
	} else if ( 'styles_css' === rule ) {
		words = __( 'custom CSS', 'keepmerge-push-staging-to-live' );
	} else if ( 'insecure_styles' === rule ) {
		words = __(
			'styles WordPress would remove',
			'keepmerge-push-staging-to-live'
		);
	} else if ( 'code_setting' === rule ) {
		words = __(
			'a code-like setting (script, CSS or HTML)',
			'keepmerge-push-staging-to-live'
		);
	} else if ( 0 === rule.indexOf( 'pattern:' ) ) {
		words = sprintf(
			/* translators: %s: rule name such as tag or event_attribute. */
			__( 'script-like text (%s)', 'keepmerge-push-staging-to-live' ),
			rule.slice( 8 ).replace( /_/g, ' ' )
		);
	} else {
		words = rule;
	}
	return field
		? sprintf(
				/* translators: 1: what was found, 2: field path. */
				__( '%1$s in %2$s', 'keepmerge-push-staging-to-live' ),
				words,
				field
			)
		: words;
}

/**
 * Sub-tabs of the diff.
 *
 * @return {Array} [ { id, label } ].
 */
export function tabList() {
	return [
		{
			id: 'summary',
			label: __( 'Summary', 'keepmerge-push-staging-to-live' ),
		},
		{
			id: 'content',
			label: __( 'Content', 'keepmerge-push-staging-to-live' ),
		},
		{
			id: 'woocommerce',
			label: __( 'WooCommerce', 'keepmerge-push-staging-to-live' ),
		},
		{ id: 'media', label: __( 'Media', 'keepmerge-push-staging-to-live' ) },
		{ id: 'menus', label: __( 'Menus', 'keepmerge-push-staging-to-live' ) },
		{
			id: 'site_editor',
			label: __( 'Site editor', 'keepmerge-push-staging-to-live' ),
		},
		{
			id: 'options',
			label: __( 'Options', 'keepmerge-push-staging-to-live' ),
		},
		{ id: 'code', label: __( 'Code', 'keepmerge-push-staging-to-live' ) },
		{
			id: 'warnings',
			label: __( 'Warnings', 'keepmerge-push-staging-to-live' ),
		},
	];
}

/**
 * Scope groups a plan can be limited to.
 *
 * @return {Array} [ { id, label } ].
 */
export function scopeList() {
	return [
		{
			id: 'content',
			label: __(
				'Posts, pages and other content',
				'keepmerge-push-staging-to-live'
			),
		},
		{
			id: 'products',
			label: __(
				'WooCommerce products',
				'keepmerge-push-staging-to-live'
			),
		},
		{ id: 'media', label: __( 'Media', 'keepmerge-push-staging-to-live' ) },
		{
			id: 'terms',
			label: __(
				'Categories, tags and other terms',
				'keepmerge-push-staging-to-live'
			),
		},
		{
			id: 'menus',
			label: __( 'Classic menus', 'keepmerge-push-staging-to-live' ),
		},
		{
			id: 'site_editor',
			label: __(
				'Site editor (templates, parts, styles, patterns, navigation)',
				'keepmerge-push-staging-to-live'
			),
		},
		{
			id: 'options',
			label: __(
				'Options and widgets',
				'keepmerge-push-staging-to-live'
			),
		},
	];
}

/**
 * What a planning stage is doing.
 *
 * @param {Object} plan Plan.
 * @return {string} Sentence.
 */
export function stageLabel( plan ) {
	const p = plan.progress || {};
	switch ( plan.stage ) {
		case 'export':
			return sprintf(
				/* translators: 1: items read so far, 2: all items. */
				__(
					"Reading this site's items: %1$s of %2$s",
					'keepmerge-push-staging-to-live'
				),
				p.done || 0,
				p.total || 0
			);
		case 'deleted':
			return __(
				'Looking for items deleted on this site',
				'keepmerge-push-staging-to-live'
			);
		case 'manifest':
			return __(
				'Asking live for its version of each item (read only)',
				'keepmerge-push-staging-to-live'
			);
		case 'taken':
			return __(
				'Checking whether live already uses the slugs of new items',
				'keepmerge-push-staging-to-live'
			);
		case 'titles':
			return __(
				'Reading the names of deleted items on live',
				'keepmerge-push-staging-to-live'
			);
		case 'code':
			return __(
				'Asking live for its plugin and theme versions',
				'keepmerge-push-staging-to-live'
			);
		case 'classify':
			return __(
				'Comparing both sites with the last push',
				'keepmerge-push-staging-to-live'
			);
		case 'closure':
			return __(
				'Checking dependencies and warnings',
				'keepmerge-push-staging-to-live'
			);
		default:
			return __( 'Finishing', 'keepmerge-push-staging-to-live' );
	}
}

/**
 * A readable name for a field path of the diff.
 *
 * @param {string} path Path such as post_content, meta._price or groups.pricing.regular_price.
 * @return {string} Label.
 */
export function fieldLabel( path ) {
	const fixed = {
		post_title: __( 'Title', 'keepmerge-push-staging-to-live' ),
		post_content: __( 'Content', 'keepmerge-push-staging-to-live' ),
		post_excerpt: __( 'Excerpt', 'keepmerge-push-staging-to-live' ),
		post_status: __( 'Status', 'keepmerge-push-staging-to-live' ),
		post_name: __( 'Slug', 'keepmerge-push-staging-to-live' ),
		post_password: __(
			'Password protection',
			'keepmerge-push-staging-to-live'
		),
		post_date_gmt: __(
			'Publish date (GMT)',
			'keepmerge-push-staging-to-live'
		),
		post_parent: __( 'Parent', 'keepmerge-push-staging-to-live' ),
		menu_order: __( 'Order', 'keepmerge-push-staging-to-live' ),
		comment_status: __( 'Comments', 'keepmerge-push-staging-to-live' ),
		ping_status: __( 'Pings', 'keepmerge-push-staging-to-live' ),
		author: __( 'Author', 'keepmerge-push-staging-to-live' ),
		page_template: __( 'Page template', 'keepmerge-push-staging-to-live' ),
		featured_image: __(
			'Featured image',
			'keepmerge-push-staging-to-live'
		),
		terms: __( 'Categories and tags', 'keepmerge-push-staging-to-live' ),
		name: __( 'Name', 'keepmerge-push-staging-to-live' ),
		slug: __( 'Slug', 'keepmerge-push-staging-to-live' ),
		description: __( 'Description', 'keepmerge-push-staging-to-live' ),
		parent: __( 'Parent', 'keepmerge-push-staging-to-live' ),
		items: __( 'Menu items', 'keepmerge-push-staging-to-live' ),
		value: __( 'Value', 'keepmerge-push-staging-to-live' ),
		alt: __( 'Alternative text', 'keepmerge-push-staging-to-live' ),
		metadata: __( 'Image metadata', 'keepmerge-push-staging-to-live' ),
		files: __( 'Files', 'keepmerge-push-staging-to-live' ),
		sync_status: __(
			'Pattern type (synced or not)',
			'keepmerge-push-staging-to-live'
		),
		theme: __( 'Theme', 'keepmerge-push-staging-to-live' ),
		area: __( 'Template part area', 'keepmerge-push-staging-to-live' ),
		'groups.content.name': __(
			'Product name',
			'keepmerge-push-staging-to-live'
		),
		'groups.content.description': __(
			'Description',
			'keepmerge-push-staging-to-live'
		),
		'groups.content.short_description': __(
			'Short description',
			'keepmerge-push-staging-to-live'
		),
		'groups.content.image_id': __(
			'Product image',
			'keepmerge-push-staging-to-live'
		),
		'groups.content.gallery_image_ids': __(
			'Gallery',
			'keepmerge-push-staging-to-live'
		),
		'groups.content.terms': __(
			'Categories and tags',
			'keepmerge-push-staging-to-live'
		),
		'groups.content.attributes': __(
			'Attributes',
			'keepmerge-push-staging-to-live'
		),
		'groups.pricing.regular_price': __(
			'Regular price',
			'keepmerge-push-staging-to-live'
		),
		'groups.pricing.sale_price': __(
			'Sale price',
			'keepmerge-push-staging-to-live'
		),
		'groups.pricing.date_on_sale_from': __(
			'Sale starts',
			'keepmerge-push-staging-to-live'
		),
		'groups.pricing.date_on_sale_to': __(
			'Sale ends',
			'keepmerge-push-staging-to-live'
		),
		'groups.pricing.tax_status': __(
			'Tax status',
			'keepmerge-push-staging-to-live'
		),
		'groups.pricing.tax_class': __(
			'Tax class',
			'keepmerge-push-staging-to-live'
		),
		'groups.identity.sku': __( 'SKU', 'keepmerge-push-staging-to-live' ),
		'groups.identity.global_unique_id': __(
			'GTIN, UPC, EAN or ISBN',
			'keepmerge-push-staging-to-live'
		),
		'groups.live_owned.stock_quantity': __(
			'Stock quantity (live-owned)',
			'keepmerge-push-staging-to-live'
		),
		'groups.live_owned.stock_status': __(
			'Stock status (live-owned)',
			'keepmerge-push-staging-to-live'
		),
		'groups.live_owned.total_sales': __(
			'Total sales (live-owned)',
			'keepmerge-push-staging-to-live'
		),
	};
	if ( fixed[ path ] ) {
		return fixed[ path ];
	}
	const meta = path.match( /^(?:groups\.[a-z_]+\.)?meta\.(.+)$/ );
	if ( meta ) {
		return sprintf(
			/* translators: %s: meta key. */
			__( 'Meta: %s', 'keepmerge-push-staging-to-live' ),
			meta[ 1 ]
		);
	}
	return path;
}

/**
 * The label of a product field group.
 *
 * @param {string} name content, pricing, identity or live_owned.
 * @return {string} Label.
 */
export function groupLabel( name ) {
	switch ( name ) {
		case 'content':
			return __( 'Content', 'keepmerge-push-staging-to-live' );
		case 'pricing':
			return __( 'Pricing', 'keepmerge-push-staging-to-live' );
		case 'identity':
			return __( 'SKU and GTIN', 'keepmerge-push-staging-to-live' );
		case 'live_owned':
			return __( 'Stock and sales', 'keepmerge-push-staging-to-live' );
		default:
			return name;
	}
}

/**
 * Whole numbers in the reader's locale.
 *
 * @param {number} value Number.
 * @return {string} Formatted.
 */
export const number = ( value ) => new Intl.NumberFormat().format( value || 0 );
