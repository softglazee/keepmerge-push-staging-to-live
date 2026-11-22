<?php
/**
 * Which options and meta keys may ever be pushed.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hard option denylist, the always-excluded meta keys and the allowlist groups shown in
 * Settings. Entries are an exact name, a "prefix*" or a "*contains*". The denylist filters may
 * only add entries: their result is merged with the hard list, so nothing can be removed.
 */
final class Option_Policy {

	/**
	 * Options that are never pushed or written, whatever any allowlist says.
	 *
	 * @var string[]
	 */
	const HARD_DENYLIST = array(
		'siteurl',
		'home',
		'admin_email',
		'new_admin_email',
		'users_can_register',
		'default_role',
		'*user_roles',
		'active_plugins',
		'active_sitewide_plugins',
		'recently_activated',
		'uninstall_plugins',
		'template',
		'stylesheet',
		'current_theme',
		'cron',
		'rewrite_rules',
		'permalink_structure',
		'category_base',
		'tag_base',
		'upload_path',
		'upload_url_path',
		'WPLANG',
		'db_version',
		'initial_db_version',
		'fresh_site',
		'auth_key',
		'auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'nonce_key',
		'nonce_salt',
		'secret_key',
		'secure_auth_key',
		'secure_auth_salt',
		'recovery_keys',
		'mailserver_*',
		'auto_update_*',
		'auto_core_update_*',
		'wp_force_deactivated_plugins',
		'can_compress_scripts',
		'db_upgraded',
		'_transient_*',
		'_site_transient_*',
		'softglaze_sp_*',
		'woocommerce_version',
		'woocommerce_db_version',
		'woocommerce_*_settings',
		'woocommerce_api_*',
		'woocommerce_store_*',
		'woocommerce_onboarding_*',
		'action_scheduler_*',
		'*secret*',
		'*token*',
		'*password*',
		'*passwd*',
		'*api_key*',
		'*apikey*',
		'*license*',
		'*licence*',
		'*private_key*',
		'*client_id*',
		'*credentials*',
	);

	/**
	 * Post and term meta keys that are never compared or pushed.
	 *
	 * @var string[]
	 */
	const ALWAYS_EXCLUDED_META = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_*',
		'_wp_desired_post_slug',
		'_encloseme',
		'_pingme',
		'_oembed_*',
		'_softglaze_sp_*',
		'_thumbnail_id',
		// Edited-image backups: their files never travel, and core deletes every name listed here
		// together with the attachment.
		'_wp_attachment_backup_sizes',
		'_stock',
		'_stock_status',
		'total_sales',
		'_wc_average_rating',
		'_wc_rating_count',
		'_wc_review_count',
		'_customer_user',
		'_order_*',
		'*secret*',
		'*token*',
		'*password*',
		'*api_key*',
		'*license*',
		'*licence*',
	);

	/**
	 * Prefix entries shorter than this would match too much to be a deliberate choice.
	 */
	const MIN_PREFIX_LENGTH = 3;

	/**
	 * The full option denylist: hard entries plus valid entries added by the filter.
	 *
	 * @return string[]
	 */
	public static function option_denylist() {
		/**
		 * Adds option names to the denylist. Entries are exact names, "prefix*" or "*contains*".
		 * Returning fewer entries than the hard list has no effect: the hard list always applies.
		 *
		 * @param string[] $extra Extra entries (empty by default).
		 * @param string[] $hard  The hard denylist, for reference.
		 */
		$extra = apply_filters( 'softglaze_sp_option_denylist', array(), self::HARD_DENYLIST );

		return self::merge_entries( self::HARD_DENYLIST, $extra );
	}

	/**
	 * The full list of always-excluded meta keys: hard entries plus valid filter additions.
	 *
	 * @return string[]
	 */
	public static function excluded_meta_keys() {
		/**
		 * Adds meta keys that are never compared or pushed. Same entry syntax as the option denylist.
		 *
		 * @param string[] $extra Extra entries (empty by default).
		 * @param string[] $hard  The always-excluded list, for reference.
		 */
		$extra = apply_filters( 'softglaze_sp_excluded_meta_keys', array(), self::ALWAYS_EXCLUDED_META );

		return self::merge_entries( self::ALWAYS_EXCLUDED_META, $extra );
	}

	/**
	 * Why an option may not be pushed, or an empty string when the denylist allows it.
	 *
	 * @param string $name Option name.
	 * @return string Reason shown to the admin, or ''.
	 */
	public static function option_denial_reason( $name ) {
		$entry = self::first_match( (string) $name, self::option_denylist() );
		if ( '' === $entry ) {
			return '';
		}
		return self::reason_for( $entry );
	}

	/**
	 * Whether this site stores an option under a name that differs from $name but that the
	 * database matches for it (MySQL's default collation ignores case and trailing spaces). Reading
	 * or writing $name would then reach that row, which every check made in PHP on $name misses.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function stored_name_differs( $name ) {
		global $wpdb;

		$name = (string) $name;
		// "=" follows the column's collation; LIKE also ignores case where "=" does not (SQLite).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One lookup of the stored option names that match a name; core has no API for that.
		$stored = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name = %s OR option_name LIKE %s LIMIT 5', $wpdb->options, $name, $wpdb->esc_like( $name ) ) );
		foreach ( is_array( $stored ) ? $stored : array() as $other ) {
			if ( (string) $other !== $name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an option name is a theme's theme mods (any case: the database does not tell them apart).
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_theme_mods( $name ) {
		return 0 === stripos( (string) $name, 'theme_mods_' );
	}

	/**
	 * Why a custom allowlist entry (a name or a "prefix*") is refused, or '' when it is accepted.
	 * A prefix that is too short or reaches a denied name or pattern is refused at once, so the
	 * admin does not find out at push time.
	 *
	 * @param string $entry Normalised entry (lowercase, names-only syntax already checked).
	 * @return string Reason, or ''.
	 */
	public static function custom_entry_reason( $entry ) {
		$entry = (string) $entry;

		if ( '*' !== substr( $entry, -1 ) ) {
			return self::option_denial_reason( $entry );
		}

		$prefix = substr( $entry, 0, -1 );
		if ( strlen( $prefix ) < self::MIN_PREFIX_LENGTH ) {
			/* translators: %d: minimum number of characters before the asterisk. */
			return sprintf( __( 'A prefix needs at least %d characters before the *.', 'keepmerge-push-staging-to-live' ), self::MIN_PREFIX_LENGTH );
		}

		$own = self::option_denial_reason( $prefix );
		if ( '' !== $own ) {
			return $own;
		}

		foreach ( self::option_denylist() as $denied ) {
			$denied             = strtolower( $denied );
			$denied_is_contains = '*' === $denied[0];
			if ( $denied_is_contains ) {
				continue;
			}
			$denied_is_prefix = '*' === substr( $denied, -1 );
			$denied_base      = $denied_is_prefix ? substr( $denied, 0, -1 ) : $denied;
			if ( false !== strpos( $denied_base, '*' ) ) {
				// Patterns such as woocommerce_*_settings: compare the part before the inner *.
				$denied_base      = substr( $denied_base, 0, (int) strpos( $denied_base, '*' ) );
				$denied_is_prefix = true;
			}

			$overlaps = 0 === strpos( $denied_base, $prefix ) || ( $denied_is_prefix && 0 === strpos( $prefix, $denied_base ) );
			if ( $overlaps ) {
				return sprintf(
					/* translators: %s: a protected option name or pattern, for example siteurl. */
					__( 'This prefix would also cover %s, which Keepmerge never pushes.', 'keepmerge-push-staging-to-live' ),
					$denied
				);
			}
		}

		return '';
	}

	/**
	 * Why a meta key is excluded, or '' when it may be compared and pushed.
	 *
	 * @param string   $key   Meta key.
	 * @param string[] $extra Additional entries from Settings (names or prefixes).
	 * @return string Matching entry, or ''.
	 */
	public static function meta_exclusion( $key, array $extra = array() ) {
		return self::first_match( (string) $key, self::merge_entries( self::excluded_meta_keys(), $extra ) );
	}

	/**
	 * Whether a name matches one entry (exact, "prefix*", "*contains*", or one inner * as in
	 * woocommerce_*_settings). Matching is case-insensitive because option names are compared
	 * case-insensitively by MySQL's default collation.
	 *
	 * @param string $name  Option name or meta key.
	 * @param string $entry Denylist entry.
	 * @return bool
	 */
	public static function matches( $name, $entry ) {
		$name  = strtolower( (string) $name );
		$entry = strtolower( (string) $entry );

		if ( '' === $entry || '' === $name ) {
			return false;
		}

		$starts = '*' === $entry[0];
		$ends   = '*' === substr( $entry, -1 );

		if ( $starts && $ends && strlen( $entry ) > 2 ) {
			return false !== strpos( $name, substr( $entry, 1, -1 ) );
		}
		if ( $starts ) {
			$suffix = substr( $entry, 1 );
			return substr( $name, -strlen( $suffix ) ) === $suffix;
		}
		if ( $ends ) {
			$prefix = substr( $entry, 0, -1 );
			if ( false === strpos( $prefix, '*' ) ) {
				return 0 === strpos( $name, $prefix );
			}
		}
		if ( false !== strpos( $entry, '*' ) ) {
			$pattern = '/^' . str_replace( '\*', '.*', preg_quote( $entry, '/' ) ) . '$/';
			return 1 === preg_match( $pattern, $name );
		}

		return $name === $entry;
	}

	/**
	 * The option allowlist groups offered in Settings.
	 *
	 * @return array<string,array{label:string,options:string[],requires:string}>
	 */
	public static function groups() {
		$stylesheet = get_stylesheet();

		return array(
			'site_identity' => array(
				'label'    => __( 'Site identity and reading', 'keepmerge-push-staging-to-live' ),
				'options'  => array( 'blogname', 'blogdescription', 'site_icon', 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'date_format', 'time_format', 'start_of_week' ),
				'requires' => '',
			),
			'theme_mods'    => array(
				'label'    => __( 'Theme customizations', 'keepmerge-push-staging-to-live' ),
				'options'  => array( 'theme_mods_' . $stylesheet ),
				'requires' => '',
			),
			'widgets'       => array(
				'label'    => __( 'Widgets (as one unit)', 'keepmerge-push-staging-to-live' ),
				'options'  => array( 'sidebars_widgets', 'widget_*' ),
				'requires' => '',
			),
			'woocommerce'   => array(
				'label'    => __( 'WooCommerce pages and catalog display', 'keepmerge-push-staging-to-live' ),
				'options'  => array( 'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'woocommerce_terms_page_id', 'woocommerce_catalog_columns', 'woocommerce_catalog_rows', 'woocommerce_single_image_width', 'woocommerce_thumbnail_image_width', 'woocommerce_thumbnail_cropping', 'woocommerce_shop_page_display', 'woocommerce_category_archive_display', 'woocommerce_default_catalog_orderby' ),
				'requires' => 'woocommerce',
			),
		);
	}

	/**
	 * The first entry that matches a name, or ''.
	 *
	 * @param string   $name    Name to test.
	 * @param string[] $entries Entries.
	 * @return string
	 */
	private static function first_match( $name, array $entries ) {
		foreach ( $entries as $entry ) {
			if ( self::matches( $name, $entry ) ) {
				return $entry;
			}
		}
		return '';
	}

	/**
	 * Hard entries plus every valid extra entry, without duplicates. Extras that are not strings
	 * or not in the entry syntax are ignored, so a broken filter cannot inject a pattern that
	 * matches nothing and hides a mistake.
	 *
	 * @param string[] $hard  Hard entries.
	 * @param mixed    $extra Filtered additions.
	 * @return string[]
	 */
	private static function merge_entries( array $hard, $extra ) {
		$out = $hard;
		if ( is_array( $extra ) ) {
			foreach ( $extra as $entry ) {
				if ( ! is_string( $entry ) ) {
					continue;
				}
				$entry = strtolower( trim( $entry ) );
				if ( 1 === preg_match( '/^\*?[a-z0-9_\-]{1,191}\*?$/', $entry ) ) {
					$out[] = $entry;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Human reason for a matching denylist entry.
	 *
	 * @param string $entry The entry that matched.
	 * @return string
	 */
	private static function reason_for( $entry ) {
		if ( '*' === $entry[0] && '*' === substr( $entry, -1 ) ) {
			return sprintf(
				/* translators: %s: a word such as "secret" or "api_key". */
				__( 'Names containing "%s" are never pushed, because they usually hold credentials.', 'keepmerge-push-staging-to-live' ),
				substr( $entry, 1, -1 )
			);
		}
		return sprintf(
			/* translators: %s: a protected option name or pattern, for example siteurl. */
			__( '%s is a protected setting that Keepmerge never pushes.', 'keepmerge-push-staging-to-live' ),
			$entry
		);
	}
}
