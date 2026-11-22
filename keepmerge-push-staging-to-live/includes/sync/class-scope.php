<?php
/**
 * What this site includes in a sync, from its own settings.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Woo\Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scope of the sync on this site (blueprint §2, §6.9): included post types and taxonomies,
 * the statuses of items, the allowlisted options and whether the widgets unit is on.
 *
 * Both sites use it. On the source it decides what is offered; on live it decides what a remote
 * request may read (threat model §7.6: only included types, only options on live's own
 * allowlist). Every list is narrowed by the hard denylists; nothing here can widen them.
 */
final class Scope {

	/**
	 * Item statuses that are part of a sync. Auto-drafts, trashed posts and revisions are not
	 * items; attachments have their own status (inherit).
	 */
	const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Site editor post types and the item kind each one is.
	 */
	const SITE_EDITOR = array(
		'wp_template'      => 'template',
		'wp_template_part' => 'template_part',
		'wp_global_styles' => 'global_styles',
		'wp_block'         => 'pattern',
		'wp_navigation'    => 'navigation',
	);

	/**
	 * Internal core post types that are never items, whatever a setting says (threat model §7.4).
	 */
	const NEVER_POST_TYPES = array( 'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'custom_css', 'wp_font_family', 'wp_font_face' );

	/**
	 * Every item kind. menu_item exists only for the baseline (menu items travel in their menu).
	 */
	const KINDS = array( 'post', 'product', 'attachment', 'menu_item', 'pattern', 'navigation', 'template', 'template_part', 'global_styles', 'term', 'menu', 'option', 'widgets' );

	/**
	 * Kinds whose local ID is a post ID.
	 */
	const POST_KINDS = array( 'post', 'product', 'attachment', 'menu_item', 'pattern', 'navigation', 'template', 'template_part', 'global_styles' );

	/**
	 * Kinds whose local ID is a term ID.
	 */
	const TERM_KINDS = array( 'term', 'menu' );

	/**
	 * Per-request cache.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Forgets the cached scope (settings changed, or a test switched something).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = array();
	}

	/**
	 * The ID space of a kind: 'post', 'term' or '' (options and units have no ID).
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	public static function space( $kind ) {
		if ( in_array( $kind, self::POST_KINDS, true ) ) {
			return 'post';
		}
		if ( in_array( $kind, self::TERM_KINDS, true ) ) {
			return 'term';
		}
		return '';
	}

	/**
	 * Post types of kind "post": public types with a UI, minus attachment, the site editor types,
	 * WooCommerce products (kind "product"), the hard denylists and the types the admin skipped.
	 *
	 * @return string[]
	 */
	public static function content_post_types() {
		if ( isset( self::$cache['content'] ) ) {
			return self::$cache['content'];
		}

		$skip = self::skipped_post_types();
		$out  = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			$name = $type->name;
			if ( ! $type->public || 'attachment' === $name || isset( self::SITE_EDITOR[ $name ] ) || in_array( $name, array( 'product', 'product_variation' ), true ) ) {
				continue;
			}
			if ( in_array( $name, $skip, true ) || self::never_post_type( $name ) ) {
				continue;
			}
			$out[] = $name;
		}
		sort( $out );

		self::$cache['content'] = $out;
		return $out;
	}

	/**
	 * Product post types (product, product_variation) when WooCommerce is active and products
	 * are included. Variations follow their product.
	 *
	 * @return string[]
	 */
	public static function product_post_types() {
		if ( ! Guard::active() || ! post_type_exists( 'product' ) || in_array( 'product', self::skipped_post_types(), true ) ) {
			return array();
		}
		return post_type_exists( 'product_variation' ) ? array( 'product', 'product_variation' ) : array( 'product' );
	}

	/**
	 * Included site editor post types, keyed by post type, valued by kind.
	 *
	 * @return array<string,string>
	 */
	public static function site_editor_types() {
		$skip = self::skipped_post_types();
		$out  = array();
		foreach ( self::SITE_EDITOR as $type => $kind ) {
			if ( post_type_exists( $type ) && ! in_array( $type, $skip, true ) ) {
				$out[ $type ] = $kind;
			}
		}
		return $out;
	}

	/**
	 * Whether classic menus (nav_menu terms with their items) are included.
	 *
	 * @return bool
	 */
	public static function menus_included() {
		return ! in_array( 'nav_menu_item', self::skipped_post_types(), true ) && taxonomy_exists( 'nav_menu' );
	}

	/**
	 * Included taxonomies: those with a UI (as the Settings screen offers them) minus nav_menu
	 * and link_category, plus WooCommerce shipping classes when products are included.
	 *
	 * @return string[]
	 */
	public static function taxonomies() {
		if ( isset( self::$cache['taxonomies'] ) ) {
			return self::$cache['taxonomies'];
		}

		$settings = Settings::get();
		$skip     = is_array( $settings['skip_taxonomies'] ) ? $settings['skip_taxonomies'] : array();
		$out      = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'names' ) as $taxonomy ) {
			if ( in_array( $taxonomy, array( 'nav_menu', 'link_category' ), true ) || in_array( $taxonomy, $skip, true ) ) {
				continue;
			}
			$out[] = $taxonomy;
		}
		if ( array() !== self::product_post_types() && taxonomy_exists( 'product_shipping_class' ) && ! in_array( 'product_shipping_class', $skip, true ) ) {
			$out[] = 'product_shipping_class';
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );

		self::$cache['taxonomies'] = $out;
		return $out;
	}

	/**
	 * The kind of an item for a post type, or '' when the type is not part of the sync here.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	public static function kind_for_post_type( $post_type ) {
		$post_type = (string) $post_type;

		if ( '' === $post_type || self::never_post_type( $post_type ) ) {
			return '';
		}
		if ( 'attachment' === $post_type ) {
			return 'attachment';
		}
		if ( 'nav_menu_item' === $post_type ) {
			return self::menus_included() ? 'menu_item' : '';
		}
		$site_editor = self::site_editor_types();
		if ( isset( $site_editor[ $post_type ] ) ) {
			return $site_editor[ $post_type ];
		}
		if ( in_array( $post_type, self::product_post_types(), true ) ) {
			return 'product';
		}
		if ( in_array( $post_type, self::content_post_types(), true ) ) {
			return 'post';
		}
		return '';
	}

	/**
	 * Post types of one kind on this site.
	 *
	 * @param string $kind Kind.
	 * @return string[]
	 */
	public static function post_types_for_kind( $kind ) {
		switch ( $kind ) {
			case 'post':
				return self::content_post_types();
			case 'product':
				return self::product_post_types();
			case 'attachment':
				return array( 'attachment' );
			case 'menu_item':
				return self::menus_included() ? array( 'nav_menu_item' ) : array();
			default:
				return array_keys( array_intersect( self::site_editor_types(), array( $kind ) ) );
		}
	}

	/**
	 * Whether a post is an item of the given kind on this site: right type, a sync status, not
	 * denied. Attachments also need a file outside the protected upload folders.
	 *
	 * @param \WP_Post|null $post Post.
	 * @param string        $kind Expected kind ('' accepts the post's own kind).
	 * @return bool
	 */
	public static function allows_post( $post, $kind = '' ) {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		$own = self::kind_for_post_type( $post->post_type );
		if ( '' === $own || ( '' !== $kind && $own !== $kind ) ) {
			return false;
		}
		if ( 'attachment' === $own ) {
			$file = (string) get_post_meta( $post->ID, '_wp_attached_file', true );
			return 'inherit' === $post->post_status && '' !== $file && ! self::protected_upload( $file );
		}
		if ( 'menu_item' === $own ) {
			return 'publish' === $post->post_status || 'draft' === $post->post_status;
		}
		return in_array( $post->post_status, self::STATUSES, true );
	}

	/**
	 * Whether a term is an item of the given kind on this site.
	 *
	 * @param \WP_Term|null $term Term.
	 * @param string        $kind 'term' or 'menu'.
	 * @return bool
	 */
	public static function allows_term( $term, $kind = 'term' ) {
		if ( ! $term instanceof \WP_Term ) {
			return false;
		}
		if ( 'menu' === $kind ) {
			return 'nav_menu' === $term->taxonomy && self::menus_included();
		}
		return in_array( $term->taxonomy, self::taxonomies(), true );
	}

	/**
	 * Whether a relative upload path is in a folder that is never pushed (WooCommerce downloads,
	 * Keepmerge's own working folder).
	 *
	 * @param string $relative Path relative to the uploads base.
	 * @return bool
	 */
	public static function protected_upload( $relative ) {
		$first = strtolower( (string) strtok( ltrim( (string) $relative, '/' ), '/' ) );
		return Guard::is_protected_upload( $relative ) || 'keepmerge-push-staging-to-live' === $first;
	}

	/**
	 * Whether the widgets unit is included (the Widgets option group is on).
	 *
	 * @return bool
	 */
	public static function widgets_included() {
		$settings = Settings::get();
		return ! empty( $settings['option_groups']['widgets'] );
	}

	/**
	 * Whether a widget_* option is the instance store of a widget registered on this site. The
	 * widgets unit writes only those: any other widget_ name could be a plugin's own setting that
	 * the plugin evaluates (security review 2026-11-22, KM-05).
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function widget_registered( $name ) {
		global $wp_widget_factory;

		if ( ! is_string( $name ) || 0 !== strpos( $name, 'widget_' ) || ! is_object( $wp_widget_factory ) || ! isset( $wp_widget_factory->widgets ) || ! is_array( $wp_widget_factory->widgets ) ) {
			return false;
		}
		foreach ( $wp_widget_factory->widgets as $widget ) {
			if ( is_object( $widget ) && isset( $widget->option_name ) && $name === $widget->option_name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Allowlisted option names that exist on this site, minus the denylist and the widget
	 * options (those travel as the widgets unit). Group entries and custom entries ending in "*"
	 * are expanded against the options table.
	 *
	 * @return string[]
	 */
	public static function option_names() {
		if ( isset( self::$cache['options'] ) ) {
			return self::$cache['options'];
		}

		$entries = self::allowlist_entries();
		$names   = array();
		foreach ( $entries as $entry ) {
			if ( '*' === substr( $entry, -1 ) ) {
				foreach ( self::options_with_prefix( substr( $entry, 0, -1 ) ) as $name ) {
					$names[] = $name;
				}
			} elseif ( false !== get_option( $entry, false ) ) {
				$names[] = $entry;
			}
		}

		$out = array();
		foreach ( array_unique( $names ) as $name ) {
			if ( self::option_allowed( $name ) ) {
				$out[] = $name;
			}
		}
		sort( $out );

		self::$cache['options'] = $out;
		return $out;
	}

	/**
	 * Whether this site's own allowlist contains an option and its denylist does not (blueprint
	 * §6.9: live is authoritative). Widget options are not single options here.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function option_allowed( $name ) {
		$name = (string) $name;
		if ( '' === $name || 1 !== preg_match( '/\A[A-Za-z0-9_.:-]{1,191}\z/', $name ) ) {
			return false;
		}
		if ( 'sidebars_widgets' === $name || 0 === strpos( $name, 'widget_' ) ) {
			return false;
		}
		if ( '' !== Option_Policy::option_denial_reason( $name ) ) {
			return false;
		}
		foreach ( self::allowlist_entries() as $entry ) {
			if ( Option_Policy::matches( $name, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Names of the widget options that exist on this site (sidebars_widgets first).
	 *
	 * @return string[]
	 */
	public static function widget_option_names() {
		if ( ! self::widgets_included() ) {
			return array();
		}
		$names = array();
		if ( false !== get_option( 'sidebars_widgets', false ) ) {
			$names[] = 'sidebars_widgets';
		}
		foreach ( self::options_with_prefix( 'widget_' ) as $name ) {
			if ( '' === Option_Policy::option_denial_reason( $name ) ) {
				$names[] = $name;
			}
		}
		return $names;
	}

	/**
	 * Meta keys excluded on this site: the hard list (blueprint §6.10) plus the Settings entries.
	 *
	 * @return string[]
	 */
	public static function excluded_meta_entries() {
		if ( isset( self::$cache['meta'] ) ) {
			return self::$cache['meta'];
		}
		$settings = Settings::get();
		$extra    = is_array( $settings['excluded_meta'] ) ? $settings['excluded_meta'] : array();

		self::$cache['meta'] = array_values( array_unique( array_merge( Option_Policy::excluded_meta_keys(), $extra ) ) );
		return self::$cache['meta'];
	}

	/**
	 * Whether a meta key is excluded from items on this site.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function meta_excluded( $key ) {
		foreach ( self::excluded_meta_entries() as $entry ) {
			if ( Option_Policy::matches( $key, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The allowlist entries of the switched-on groups plus the custom entries.
	 *
	 * @return string[]
	 */
	private static function allowlist_entries() {
		if ( isset( self::$cache['entries'] ) ) {
			return self::$cache['entries'];
		}

		$settings = Settings::get();
		$entries  = array();
		foreach ( Option_Policy::groups() as $group => $info ) {
			if ( empty( $settings['option_groups'][ $group ] ) || 'widgets' === $group ) {
				continue;
			}
			if ( 'woocommerce' === $info['requires'] && ! Guard::active() ) {
				continue;
			}
			foreach ( $info['options'] as $name ) {
				$entries[] = $name;
			}
		}
		foreach ( is_array( $settings['custom_options'] ) ? $settings['custom_options'] : array() as $entry ) {
			if ( is_string( $entry ) && '' !== $entry ) {
				$entries[] = $entry;
			}
		}

		self::$cache['entries'] = array_values( array_unique( $entries ) );
		return self::$cache['entries'];
	}

	/**
	 * Option names starting with a prefix (at most 500).
	 *
	 * @param string $prefix Prefix.
	 * @return string[]
	 */
	private static function options_with_prefix( $prefix ) {
		global $wpdb;

		if ( strlen( (string) $prefix ) < 3 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lists option names only; there is no core API for a name prefix.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT 500", $wpdb->esc_like( (string) $prefix ) . '%' ) );

		return is_array( $names ) ? array_map( 'strval', $names ) : array();
	}

	/**
	 * Post types the admin turned off in Settings.
	 *
	 * @return string[]
	 */
	private static function skipped_post_types() {
		$settings = Settings::get();
		return is_array( $settings['skip_post_types'] ) ? $settings['skip_post_types'] : array();
	}

	/**
	 * Whether a post type is never an item, whatever a setting says: core internals (the Font
	 * Library's types by their wp_font_ prefix too) and WooCommerce orders and coupons.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function never_post_type( $post_type ) {
		$post_type = (string) $post_type;
		return in_array( $post_type, self::NEVER_POST_TYPES, true ) || 0 === strpos( $post_type, 'wp_font_' ) || Guard::is_denied_post_type( $post_type );
	}
}
