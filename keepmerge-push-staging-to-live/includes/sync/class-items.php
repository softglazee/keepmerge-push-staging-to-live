<?php
/**
 * The items of this site.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

use SoftGlaze\SafePush\Sync\Exporters\Media;
use SoftGlaze\SafePush\Sync\Exporters\Menus;
use SoftGlaze\SafePush\Sync\Exporters\Options;
use SoftGlaze\SafePush\Sync\Exporters\Posts;
use SoftGlaze\SafePush\Sync\Exporters\Products;
use SoftGlaze\SafePush\Sync\Exporters\Site_Editor;
use SoftGlaze\SafePush\Sync\Exporters\Terms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists this site's items (in a fixed order, so a list can be walked in steps), exports one by
 * kind, and builds the baseline fingerprints.
 */
final class Items {

	/**
	 * Post kinds in listing order.
	 */
	const POST_KIND_ORDER = array( 'post', 'product', 'attachment', 'pattern', 'navigation', 'template', 'template_part', 'global_styles' );

	/**
	 * Exports one item.
	 *
	 * @param string         $kind Kind.
	 * @param int|string     $ref  Local ID, or the option name.
	 * @param Export_Context $ctx  Context.
	 * @return array|null
	 */
	public static function export( $kind, $ref, Export_Context $ctx ) {
		switch ( $kind ) {
			case 'post':
				return ( new Posts( $ctx ) )->export( $ref );
			case 'product':
				return ( new Products( $ctx ) )->export( $ref );
			case 'attachment':
				return ( new Media( $ctx ) )->export( $ref );
			case 'pattern':
			case 'navigation':
			case 'template':
			case 'template_part':
			case 'global_styles':
				return ( new Site_Editor( $ctx ) )->export( $ref );
			case 'term':
				return ( new Terms( $ctx ) )->export( $ref );
			case 'menu':
				return ( new Menus( $ctx ) )->export( $ref );
			case 'option':
				return ( new Options( $ctx ) )->export( $ref );
			case 'widgets':
				return ( new Options( $ctx ) )->export_widgets();
			default:
				return null;
		}
	}

	/**
	 * Every item of this site as kind and reference, in a fixed order.
	 *
	 * @return array<int,array{kind:string,ref:int|string}>
	 */
	public static function enumerate() {
		$out = array();
		foreach ( self::post_rows() as $row ) {
			if ( 'menu_item' !== $row['kind'] ) {
				$out[] = array(
					'kind' => $row['kind'],
					'ref'  => (int) $row['ID'],
				);
			}
		}
		foreach ( self::term_rows( 'term' ) as $term ) {
			$out[] = array(
				'kind' => 'term',
				'ref'  => (int) $term->term_id,
			);
		}
		foreach ( self::term_rows( 'menu' ) as $term ) {
			$out[] = array(
				'kind' => 'menu',
				'ref'  => (int) $term->term_id,
			);
		}
		foreach ( Scope::option_names() as $name ) {
			$out[] = array(
				'kind' => 'option',
				'ref'  => $name,
			);
		}
		if ( Scope::widgets_included() ) {
			$out[] = array(
				'kind' => 'widgets',
				'ref'  => 'widgets',
			);
		}
		return $out;
	}

	/**
	 * Baseline fingerprints of every post-like object, term and menu (menu items included, so
	 * references to them can be matched). GUIDs travel tokenized.
	 *
	 * @param Normalizer $normalizer This site's normalizer (for the GUID tokens).
	 * @return array[]
	 */
	public static function fingerprints( Normalizer $normalizer ) {
		$out   = array();
		$files = null;
		$base  = trailingslashit( (string) wp_upload_dir( null, false )['basedir'] );

		foreach ( self::post_rows() as $row ) {
			$kind = $row['kind'];
			$id   = (int) $row['ID'];

			switch ( $kind ) {
				case 'attachment':
					if ( null === $files ) {
						$files = self::attached_files();
					}
					$file = isset( $files[ $id ] ) ? $files[ $id ] : '';
					if ( '' === $file || Scope::protected_upload( $file ) || ! Media::safe_path( $file ) ) {
						continue 2;
					}
					$out[] = array(
						'k'    => 'attachment',
						'id'   => $id,
						'file' => $file,
						'size' => is_file( $base . $file ) ? (int) filesize( $base . $file ) : -1,
					);
					break;

				case 'template':
				case 'template_part':
					$out[] = array(
						'k'     => $kind,
						'id'    => $id,
						'slug'  => (string) $row['post_name'],
						'theme' => self::theme_of( $id ),
					);
					break;

				case 'global_styles':
					$out[] = array(
						'k'     => $kind,
						'id'    => $id,
						'theme' => self::theme_of( $id ),
					);
					break;

				default:
					$out[] = array(
						'k'    => $kind,
						'id'   => $id,
						'type' => (string) $row['post_type'],
						'guid' => $normalizer->tokenize( (string) $row['guid'], false ),
						'slug' => (string) $row['post_name'],
						'date' => (string) $row['post_date_gmt'],
					);
			}
		}

		foreach ( self::term_rows( 'term' ) as $term ) {
			$out[] = array(
				'k'    => 'term',
				'id'   => (int) $term->term_id,
				'tax'  => $term->taxonomy,
				'slug' => $term->slug,
			);
		}
		foreach ( self::term_rows( 'menu' ) as $term ) {
			$out[] = array(
				'k'    => 'menu',
				'id'   => (int) $term->term_id,
				'slug' => $term->slug,
			);
		}

		return $out;
	}

	/**
	 * Post rows of every included kind with a sync status, in kind order then by ID.
	 *
	 * @return array[] Rows with ID, post_type, post_status, post_name, post_date_gmt, guid, kind.
	 */
	public static function post_rows() {
		global $wpdb;

		$order = array_merge( self::POST_KIND_ORDER, array( 'menu_item' ) );
		$types = array();
		foreach ( $order as $kind ) {
			foreach ( Scope::post_types_for_kind( $kind ) as $type ) {
				$types[ $type ] = $kind;
			}
		}
		if ( empty( $types ) ) {
			return array();
		}

		$names        = array_keys( $types );
		$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholders are built from the count of values passed to prepare().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One read of the fields the baseline compares, for every item at once.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status, post_name, post_date_gmt, guid FROM {$wpdb->posts} WHERE post_type IN ( {$placeholders} ) ORDER BY ID ASC", $names ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$by_kind = array_fill_keys( $order, array() );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$kind = $types[ $row['post_type'] ];
			if ( ! self::status_ok( $kind, (string) $row['post_status'] ) ) {
				continue;
			}
			$row['kind']        = $kind;
			$by_kind[ $kind ][] = $row;
		}

		$out = array();
		foreach ( $by_kind as $rows_of_kind ) {
			foreach ( $rows_of_kind as $row ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Terms of the included taxonomies, or the menus.
	 *
	 * @param string $kind 'term' or 'menu'.
	 * @return \WP_Term[]
	 */
	public static function term_rows( $kind ) {
		if ( 'menu' === $kind ) {
			if ( ! Scope::menus_included() ) {
				return array();
			}
			$taxonomies = array( 'nav_menu' );
		} else {
			$taxonomies = Scope::taxonomies();
		}
		if ( empty( $taxonomies ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'               => $taxonomies,
				'hide_empty'             => false,
				'orderby'                => 'term_id',
				'order'                  => 'ASC',
				'update_term_meta_cache' => false,
			)
		);

		return is_array( $terms ) ? array_values( array_filter( $terms, array( __CLASS__, 'is_term' ) ) ) : array();
	}

	/**
	 * Whether a value is a term (array_filter callback).
	 *
	 * @param mixed $term Value.
	 * @return bool
	 */
	public static function is_term( $term ) {
		return $term instanceof \WP_Term;
	}

	/**
	 * The stylesheet (wp_theme term) of a template, part or global styles post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function theme_of( $post_id ) {
		$names = wp_get_object_terms( (int) $post_id, 'wp_theme', array( 'fields' => 'names' ) );
		return is_wp_error( $names ) || empty( $names ) ? '' : (string) reset( $names );
	}

	/**
	 * Whether a status makes a post an item of its kind.
	 *
	 * @param string $kind   Kind.
	 * @param string $status Status.
	 * @return bool
	 */
	private static function status_ok( $kind, $status ) {
		if ( 'attachment' === $kind ) {
			return 'inherit' === $status;
		}
		if ( 'menu_item' === $kind ) {
			return 'publish' === $status || 'draft' === $status;
		}
		return in_array( $status, Scope::STATUSES, true );
	}

	/**
	 * _wp_attached_file of every attachment, keyed by ID.
	 *
	 * @return array<int,string>
	 */
	private static function attached_files() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One read of one meta key for every attachment.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", '_wp_attached_file' ), ARRAY_A );

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['post_id'] ] = (string) $row['meta_value'];
		}
		return $out;
	}
}
