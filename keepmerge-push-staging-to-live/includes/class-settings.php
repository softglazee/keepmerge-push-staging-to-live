<?php
/**
 * Plugin settings.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

use SoftGlaze\SafePush\Sync\Option_Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The softglaze_sp_settings option (blueprint §16 Settings, F15).
 *
 * Every field is validated on its own. Name fields accept names only, so no setting can hold
 * CSS, JavaScript, PHP or HTML: an entry is trimmed and lowercased, then it must match
 * NAME_PATTERN as it is. Nothing is stripped to make it fit, because stripping the brackets
 * from a script tag would leave "script" and quietly accept it.
 *
 * Content types are stored as the types to skip. Blueprint §2 includes every public type with
 * a UI, so a type registered later is included until the admin turns it off, and a skip list
 * can only narrow what is pushed.
 */
final class Settings {

	const OPTION = 'softglaze_sp_settings';

	/**
	 * A name, or a prefix ending in "*" (F15).
	 */
	const NAME_PATTERN = '/^[a-z0-9_\-]{1,191}\*?$/';

	/**
	 * Exact names only, for post types and taxonomies.
	 */
	const TYPE_PATTERN = '/^[a-z0-9_\-]{1,191}$/';

	const MAX_ENTRIES = 200;

	/**
	 * Integer fields and their allowed ranges (blueprint §11.4, §13).
	 *
	 * @var array<string,array{0:int,1:int}>
	 */
	const RANGES = array(
		'item_batch'    => array( 10, 200 ),
		'chunk_kb'      => array( 256, 8192 ),
		'snapshot_days' => array( 7, 180 ),
		'audit_days'    => array( 30, 365 ),
	);

	/**
	 * Registers the option with its sanitize callback.
	 *
	 * @return void
	 */
	public static function register() {
		register_setting(
			'softglaze_sp',
			self::OPTION,
			array(
				'type'              => 'object',
				'description'       => 'Keepmerge settings',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Defaults for a fresh install. "Allow code report requests" and "Remove all Keepmerge data
	 * on uninstall" are off.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'skip_post_types'          => array(),
			'skip_taxonomies'          => array(),
			'option_groups'            => array(
				'site_identity' => true,
				'theme_mods'    => true,
				'widgets'       => ! self::is_block_theme(),
				'woocommerce'   => false,
			),
			'custom_options'           => array(),
			'excluded_meta'            => array(),
			'item_batch'               => 50,
			'chunk_kb'                 => 1024,
			'snapshot_days'            => 30,
			'audit_days'               => 180,
			'allow_code_report'        => false,
			'remove_data_on_uninstall' => false,
		);
	}

	/**
	 * Current settings, with defaults for anything missing.
	 *
	 * @return array
	 */
	public static function get() {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();

		$settings                  = array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
		$settings['option_groups'] = array_merge(
			$defaults['option_groups'],
			is_array( $settings['option_groups'] ) ? array_intersect_key( $settings['option_groups'], $defaults['option_groups'] ) : array()
		);

		return $settings;
	}

	/**
	 * The register_setting() sanitize callback: keeps every valid field and entry and drops the
	 * rest. Messages are not shown from here; the admin route validates first and refuses the
	 * whole save with reasons (validate()).
	 *
	 * @param mixed $value Proposed option value.
	 * @return array
	 */
	public static function sanitize( $value ) {
		$result = self::validate( is_array( $value ) ? $value : array(), self::defaults() );
		return $result['settings'];
	}

	/**
	 * Validates proposed values field by field.
	 *
	 * @param array      $input Proposed values; a field that is absent keeps its base value.
	 * @param array|null $base  Values to start from; the current settings when null.
	 * @return array{settings:array,errors:array<string,array<int,array{entry:string,reason:string}>>}
	 */
	public static function validate( array $input, $base = null ) {
		$settings = is_array( $base ) ? array_merge( self::defaults(), $base ) : self::get();
		$errors   = array();

		foreach ( array( 'skip_post_types', 'skip_taxonomies' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				list( $settings[ $field ], $field_errors ) = self::validate_names( $input[ $field ], self::TYPE_PATTERN, false );
				if ( $field_errors ) {
					$errors[ $field ] = $field_errors;
				}
			}
		}

		if ( array_key_exists( 'custom_options', $input ) ) {
			list( $settings['custom_options'], $field_errors ) = self::validate_names( $input['custom_options'], self::NAME_PATTERN, true );
			if ( $field_errors ) {
				$errors['custom_options'] = $field_errors;
			}
		}

		if ( array_key_exists( 'excluded_meta', $input ) ) {
			list( $settings['excluded_meta'], $field_errors ) = self::validate_names( $input['excluded_meta'], self::NAME_PATTERN, false );
			if ( $field_errors ) {
				$errors['excluded_meta'] = $field_errors;
			}
		}

		if ( array_key_exists( 'option_groups', $input ) ) {
			if ( is_array( $input['option_groups'] ) ) {
				$groups = is_array( $settings['option_groups'] ) ? $settings['option_groups'] : array();
				foreach ( array_keys( self::defaults()['option_groups'] ) as $group ) {
					if ( array_key_exists( $group, $input['option_groups'] ) ) {
						$groups[ $group ] = self::to_bool( $input['option_groups'][ $group ] );
					}
				}
				$settings['option_groups'] = array_intersect_key( $groups, self::defaults()['option_groups'] );
			} else {
				$errors['option_groups'][] = array(
					'entry'  => '',
					'reason' => __( 'Option groups must be a set of on/off choices.', 'keepmerge-push-staging-to-live' ),
				);
			}
		}

		foreach ( self::RANGES as $field => $range ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			$raw = $input[ $field ];
			if ( is_int( $raw ) || ( is_string( $raw ) && 1 === preg_match( '/^\d{1,6}$/', $raw ) ) ) {
				$number = (int) $raw;
				if ( $number >= $range[0] && $number <= $range[1] ) {
					$settings[ $field ] = $number;
					continue;
				}
			}
			$errors[ $field ][] = array(
				'entry'  => is_scalar( $raw ) ? substr( (string) $raw, 0, 20 ) : '',
				/* translators: 1: lowest allowed number, 2: highest allowed number. */
				'reason' => sprintf( __( 'Enter a whole number from %1$d to %2$d.', 'keepmerge-push-staging-to-live' ), $range[0], $range[1] ),
			);
		}

		foreach ( array( 'allow_code_report', 'remove_data_on_uninstall' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$settings[ $field ] = self::to_bool( $input[ $field ] );
			}
		}

		return array(
			'settings' => array_intersect_key( $settings, self::defaults() ),
			'errors'   => $errors,
		);
	}

	/**
	 * Keys whose values differ between two settings arrays.
	 *
	 * @param array $before Previous settings.
	 * @param array $after  New settings.
	 * @return string[]
	 */
	public static function changed_keys( array $before, array $after ) {
		$changed = array();
		foreach ( array_keys( self::defaults() ) as $key ) {
			$a = isset( $before[ $key ] ) ? $before[ $key ] : null;
			$b = isset( $after[ $key ] ) ? $after[ $key ] : null;
			if ( $a !== $b ) {
				$changed[] = $key;
			}
		}
		return $changed;
	}

	/**
	 * Saves validated settings and audits the change: settings_changed with the changed keys
	 * only (never values), plus code_report_enabled or code_report_disabled when that switch
	 * flipped (blueprint §6.11).
	 *
	 * @param array $input Proposed values, read by name by the caller.
	 * @return array{saved:bool,settings:array,changed:string[],errors:array}
	 */
	public static function save( array $input ) {
		$old    = self::get();
		$result = self::validate( $input, $old );

		if ( ! empty( $result['errors'] ) ) {
			return array(
				'saved'    => false,
				'settings' => $old,
				'changed'  => array(),
				'errors'   => $result['errors'],
			);
		}

		$changed = self::changed_keys( $old, $result['settings'] );
		if ( $changed ) {
			update_option( self::OPTION, $result['settings'], true );

			Audit_Log::write( 'settings_changed', array( 'details' => array( 'keys' => $changed ) ) );

			if ( in_array( 'allow_code_report', $changed, true ) ) {
				Audit_Log::write( $result['settings']['allow_code_report'] ? 'code_report_enabled' : 'code_report_disabled' );
			}
		}

		return array(
			'saved'    => true,
			'settings' => self::get(),
			'changed'  => $changed,
			'errors'   => array(),
		);
	}

	/**
	 * Post types and taxonomies offered under "Content types", grouped for the screen.
	 *
	 * @return array<int,array{name:string,label:string,group:string,kind:string}>
	 */
	public static function content_type_choices() {
		$choices = array();

		// Public types with a UI (blueprint §2). Internal types, including every order, refund,
		// coupon and subscription type, are not public. Attachments travel with the items that use
		// them, so they are not a choice of their own.
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name || ! $type->public ) {
				continue;
			}
			$choices[] = array(
				'name'  => $type->name,
				'label' => $type->labels->name,
				'group' => 'content',
				'kind'  => 'post_type',
			);
		}

		$site_editor = array(
			'wp_template'      => __( 'Templates', 'keepmerge-push-staging-to-live' ),
			'wp_template_part' => __( 'Template parts', 'keepmerge-push-staging-to-live' ),
			'wp_global_styles' => __( 'Global styles', 'keepmerge-push-staging-to-live' ),
			'wp_block'         => __( 'Patterns', 'keepmerge-push-staging-to-live' ),
			'wp_navigation'    => __( 'Navigation menus (blocks)', 'keepmerge-push-staging-to-live' ),
		);
		foreach ( $site_editor as $name => $label ) {
			if ( post_type_exists( $name ) ) {
				$choices[] = array(
					'name'  => $name,
					'label' => $label,
					'group' => 'site_editor',
					'kind'  => 'post_type',
				);
			}
		}

		$choices[] = array(
			'name'  => 'nav_menu_item',
			'label' => __( 'Classic menus and their locations', 'keepmerge-push-staging-to-live' ),
			'group' => 'menus',
			'kind'  => 'post_type',
		);

		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $taxonomy ) {
			if ( in_array( $taxonomy->name, array( 'nav_menu', 'link_category' ), true ) ) {
				continue;
			}
			$choices[] = array(
				'name'  => $taxonomy->name,
				'label' => $taxonomy->labels->name,
				'group' => 'terms',
				'kind'  => 'taxonomy',
			);
		}

		return $choices;
	}

	/**
	 * Validates a list of names.
	 *
	 * @param mixed  $raw         Proposed list.
	 * @param string $pattern     Pattern each entry must match after trim and lowercase.
	 * @param bool   $check_deny  Whether entries are option names checked against the denylist.
	 * @return array{0:string[],1:array<int,array{entry:string,reason:string}>}
	 */
	private static function validate_names( $raw, $pattern, $check_deny ) {
		$clean  = array();
		$errors = array();

		if ( ! is_array( $raw ) ) {
			$errors[] = array(
				'entry'  => '',
				'reason' => __( 'This field takes a list of names.', 'keepmerge-push-staging-to-live' ),
			);
			return array( array(), $errors );
		}

		foreach ( array_values( $raw ) as $entry ) {
			if ( ! is_string( $entry ) ) {
				$errors[] = array(
					'entry'  => '',
					'reason' => __( 'Each entry must be text.', 'keepmerge-push-staging-to-live' ),
				);
				continue;
			}

			$name = strtolower( trim( $entry ) );
			if ( '' === $name ) {
				continue;
			}

			// The refused text goes back to the admin as JSON data so they can see which entry was
			// meant; the screen renders it as text. Control characters and broken UTF-8 are removed.
			$shown = wp_check_invalid_utf8( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', substr( $entry, 0, 80 ) ), true );

			if ( 1 !== preg_match( $pattern, $name ) ) {
				$errors[] = array(
					'entry'  => $shown,
					'reason' => self::TYPE_PATTERN === $pattern
						? __( 'Only a name is allowed: lowercase letters, digits, _ and -.', 'keepmerge-push-staging-to-live' )
						: __( 'Only a name is allowed: lowercase letters, digits, _ and -, with an optional * at the end for a prefix. Code and markup are never accepted here.', 'keepmerge-push-staging-to-live' ),
				);
				continue;
			}

			if ( $check_deny ) {
				$reason = Option_Policy::custom_entry_reason( $name );
				if ( '' !== $reason ) {
					$errors[] = array(
						'entry'  => $shown,
						'reason' => $reason,
					);
					continue;
				}
			}

			if ( ! in_array( $name, $clean, true ) ) {
				$clean[] = $name;
			}
		}

		if ( count( $clean ) > self::MAX_ENTRIES ) {
			$errors[] = array(
				'entry'  => '',
				/* translators: %d: maximum number of entries. */
				'reason' => sprintf( __( 'At most %d entries are allowed.', 'keepmerge-push-staging-to-live' ), self::MAX_ENTRIES ),
			);
			$clean    = array_slice( $clean, 0, self::MAX_ENTRIES );
		}

		return array( $clean, $errors );
	}

	/**
	 * Strict boolean reading: true, 1, "1", "true", "yes" and "on" are on; everything else off.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function to_bool( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 1 === $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), array( '1', 'true', 'yes', 'on' ), true );
		}
		return false;
	}

	/**
	 * Whether the active theme is a block theme; widgets default on for classic themes only.
	 *
	 * @return bool
	 */
	private static function is_block_theme() {
		if ( ! did_action( 'after_setup_theme' ) ) {
			return false;
		}
		return wp_is_block_theme();
	}
}
