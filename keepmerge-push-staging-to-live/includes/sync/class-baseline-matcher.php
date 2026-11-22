<?php
/**
 * Baseline matching on the destination.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

use SoftGlaze\SafePush\Sync\Exporters\Media;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers POST /remote/baseline, read-only. Each fingerprint is strong (same ID with the same
 * tokenized GUID or the same slug and date; or the same template, styles, file path and size,
 * or term slug), medium (same slug and date on another ID, never linked automatically because
 * WordPress gives a page re-created with the same slug the same GUID), none (with the IDs whose
 * slug is taken) or excluded, plus the UUID live already uses for a matched object.
 */
final class Baseline_Matcher {

	const MAX_FINGERPRINTS = 500;

	/**
	 * Fingerprint kinds this route accepts.
	 */
	const KINDS = array( 'post', 'product', 'attachment', 'menu_item', 'pattern', 'navigation', 'template', 'template_part', 'global_styles', 'term', 'menu' );

	/**
	 * Most "slug taken" candidates returned per fingerprint.
	 */
	const MAX_TAKEN = 5;

	/**
	 * Validates the request body field by field (unknown keys dropped) and matches it.
	 *
	 * @param array $body Decoded body.
	 * @return array|WP_Error
	 */
	public static function answer( array $body ) {
		$list = self::parse( $body );
		if ( is_wp_error( $list ) ) {
			return $list;
		}

		$normalizer = Normalizer::for_destination();
		$results    = array();
		$counts     = array(
			'strong'   => 0,
			'medium'   => 0,
			'none'     => 0,
			'excluded' => 0,
		);

		$by_kind = array();
		foreach ( $list as $i => $entry ) {
			$by_kind[ $entry['k'] ][ $i ] = $entry;
		}

		foreach ( $by_kind as $kind => $entries ) {
			switch ( $kind ) {
				case 'attachment':
					$answers = self::match_attachments( $entries );
					break;
				case 'template':
				case 'template_part':
				case 'global_styles':
					$answers = self::match_theme_items( $kind, $entries );
					break;
				case 'term':
				case 'menu':
					$answers = self::match_terms( $kind, $entries );
					break;
				default:
					$answers = self::match_posts( $kind, $entries, $normalizer );
			}
			foreach ( $answers as $i => $answer ) {
				$answer['i']   = (int) $i;
				$results[ $i ] = $answer;
				++$counts[ $answer['m'] ];
			}
		}
		ksort( $results );

		return array(
			'results'   => array_values( $results ),
			'counts'    => $counts,
			'admin_url' => admin_url(),
		);
	}

	/**
	 * Validates the fingerprint list after the permission chain, each field read by name.
	 *
	 * @param array $body Body.
	 * @return array|WP_Error
	 */
	public static function parse( array $body ) {
		if ( ! isset( $body['fingerprints'] ) || ! is_array( $body['fingerprints'] ) || ! wp_is_numeric_array( $body['fingerprints'] ) ) {
			return self::invalid();
		}
		$raw = $body['fingerprints'];
		if ( count( $raw ) > self::MAX_FINGERPRINTS ) {
			return new WP_Error( 'too_many_items', 'too_many_items', array( 'status' => 400 ) );
		}

		$out = array();
		foreach ( array_values( $raw ) as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['k'], $entry['id'] ) || ! in_array( $entry['k'], self::KINDS, true ) || ! is_int( $entry['id'] ) || $entry['id'] < 1 ) {
				return self::invalid();
			}
			$clean = array(
				'k'  => $entry['k'],
				'id' => $entry['id'],
			);
			switch ( $entry['k'] ) {
				case 'attachment':
					if ( ! isset( $entry['file'], $entry['size'] ) || ! is_string( $entry['file'] ) || ! Media::safe_path( $entry['file'] ) || ! is_int( $entry['size'] ) ) {
						return self::invalid();
					}
					$clean['file'] = $entry['file'];
					$clean['size'] = $entry['size'];
					break;
				case 'template':
				case 'template_part':
					if ( ! self::is_slug( $entry, 'slug' ) || ! self::is_stylesheet( $entry, 'theme' ) ) {
						return self::invalid();
					}
					$clean['slug']  = $entry['slug'];
					$clean['theme'] = $entry['theme'];
					break;
				case 'global_styles':
					if ( ! self::is_stylesheet( $entry, 'theme' ) ) {
						return self::invalid();
					}
					$clean['theme'] = $entry['theme'];
					break;
				case 'term':
					if ( ! self::is_name( $entry, 'tax' ) || ! self::is_slug( $entry, 'slug' ) ) {
						return self::invalid();
					}
					$clean['tax']  = $entry['tax'];
					$clean['slug'] = $entry['slug'];
					break;
				case 'menu':
					if ( ! self::is_slug( $entry, 'slug' ) ) {
						return self::invalid();
					}
					$clean['slug'] = $entry['slug'];
					break;
				default:
					if ( ! self::is_name( $entry, 'type' ) || ! isset( $entry['guid'] ) || ! is_string( $entry['guid'] ) || strlen( $entry['guid'] ) > 255 || ! self::is_slug( $entry, 'slug', true ) || ! isset( $entry['date'] ) || ! is_string( $entry['date'] ) || 1 !== preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $entry['date'] ) ) {
						return self::invalid();
					}
					$clean['type'] = $entry['type'];
					$clean['guid'] = $entry['guid'];
					$clean['slug'] = $entry['slug'];
					$clean['date'] = $entry['date'];
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * Posts, products, patterns, navigation and menu items.
	 *
	 * @param string     $kind       Kind.
	 * @param array      $entries    Index => fingerprint.
	 * @param Normalizer $normalizer Live's normalizer (GUID tokens).
	 * @return array Index => answer.
	 */
	private static function match_posts( $kind, array $entries, Normalizer $normalizer ) {
		$types = Scope::post_types_for_kind( $kind );
		$ids   = array();
		$slugs = array();
		foreach ( $entries as $entry ) {
			if ( ! in_array( $entry['type'], $types, true ) ) {
				continue;
			}
			$ids[] = $entry['id'];
			if ( '' !== $entry['slug'] ) {
				$slugs[ $entry['type'] ][] = $entry['slug'];
			}
		}

		$by_id   = self::posts_where( 'ID', $ids );
		$by_slug = array();
		foreach ( $slugs as $type => $names ) {
			foreach ( self::posts_where( 'post_name', $names, $type ) as $row ) {
				$by_slug[ $row['post_type'] . '|' . $row['post_name'] ][] = $row;
			}
		}

		$out = array();
		foreach ( $entries as $i => $entry ) {
			if ( ! in_array( $entry['type'], $types, true ) ) {
				$out[ $i ] = self::result( 'excluded' );
				continue;
			}

			$match = null;
			$by    = '';

			// Same ID: strong when the slug and date also agree, or the tokenized GUID does.
			if ( isset( $by_id[ $entry['id'] ] ) ) {
				$row = $by_id[ $entry['id'] ];
				if ( $row['post_type'] === $entry['type'] && self::row_allowed( $row, $kind ) ) {
					if ( $normalizer->tokenize( (string) $row['guid'], false ) === $entry['guid'] ) {
						$match = $row;
						$by    = 'guid';
					} elseif ( $row['post_name'] === $entry['slug'] && $row['post_date_gmt'] === $entry['date'] ) {
						$match = $row;
						$by    = 'id_slug_date';
					}
				}
			}

			if ( null !== $match ) {
				$out[ $i ] = self::result( 'strong', (int) $match['ID'], $by, Identity::get( 'post', (int) $match['ID'] ) );
				continue;
			}

			$same_slug = array();
			if ( '' !== $entry['slug'] && isset( $by_slug[ $entry['type'] . '|' . $entry['slug'] ] ) ) {
				foreach ( $by_slug[ $entry['type'] . '|' . $entry['slug'] ] as $row ) {
					if ( self::row_allowed( $row, $kind ) ) {
						$same_slug[] = $row;
					}
				}
			}

			$medium = null;
			foreach ( $same_slug as $row ) {
				if ( $row['post_date_gmt'] === $entry['date'] && '0000-00-00 00:00:00' !== $entry['date'] ) {
					$medium = $row;
					break;
				}
			}
			if ( null !== $medium ) {
				$out[ $i ] = self::result( 'medium', (int) $medium['ID'], 'slug_date', Identity::get( 'post', (int) $medium['ID'] ), self::taken_ids( $same_slug, (int) $medium['ID'] ) );
				continue;
			}

			$out[ $i ] = self::result( 'none', 0, '', '', self::taken_ids( $same_slug, 0 ) );
		}
		return $out;
	}

	/**
	 * Attachments: the same relative path and file size.
	 *
	 * @param array $entries Index => fingerprint.
	 * @return array Index => answer.
	 */
	private static function match_attachments( array $entries ) {
		global $wpdb;

		$files = array();
		foreach ( $entries as $entry ) {
			$files[] = $entry['file'];
		}
		$files = array_values( array_unique( $files ) );

		$by_file = array();
		if ( ! empty( $files ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $files ), '%s' ) );
			$args         = array_merge( array( '_wp_attached_file' ), $files );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The placeholders are built from the count of values passed to prepare().
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Read-only lookup of attachments by path for one batch.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ( {$placeholders} )", $args ), ARRAY_A );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$by_file[ (string) $row['meta_value'] ][] = (int) $row['post_id'];
			}
		}

		$base = trailingslashit( (string) wp_upload_dir( null, false )['basedir'] );
		$out  = array();
		foreach ( $entries as $i => $entry ) {
			if ( Scope::protected_upload( $entry['file'] ) ) {
				$out[ $i ] = self::result( 'excluded' );
				continue;
			}
			$candidates = array();
			foreach ( isset( $by_file[ $entry['file'] ] ) ? $by_file[ $entry['file'] ] : array() as $id ) {
				if ( Scope::allows_post( get_post( $id ), 'attachment' ) ) {
					$candidates[] = $id;
				}
			}
			$size  = is_file( $base . $entry['file'] ) ? (int) filesize( $base . $entry['file'] ) : -1;
			$match = 0;
			if ( $size === $entry['size'] && $size >= 0 ) {
				$match = in_array( $entry['id'], $candidates, true ) ? $entry['id'] : (int) reset( $candidates );
			}
			if ( $match > 0 ) {
				$out[ $i ] = self::result( 'strong', $match, 'path_size', Identity::get( 'post', $match ) );
				continue;
			}
			$out[ $i ] = self::result( 'none', 0, '', '', array_slice( $candidates, 0, self::MAX_TAKEN ) );
		}
		return $out;
	}

	/**
	 * Templates and parts (slug and theme) and global styles (theme).
	 *
	 * @param string $kind    Kind.
	 * @param array  $entries Index => fingerprint.
	 * @return array Index => answer.
	 */
	private static function match_theme_items( $kind, array $entries ) {
		$types = Scope::post_types_for_kind( $kind );
		$out   = array();
		if ( empty( $types ) ) {
			foreach ( array_keys( $entries ) as $i ) {
				$out[ $i ] = self::result( 'excluded' );
			}
			return $out;
		}

		$live = array();
		foreach ( self::posts_where( 'post_type', $types ) as $row ) {
			if ( self::row_allowed( $row, $kind ) ) {
				$theme          = Items::theme_of( (int) $row['ID'] );
				$key            = 'global_styles' === $kind ? $theme : $row['post_name'] . '|' . $theme;
				$live[ $key ][] = (int) $row['ID'];
			}
		}

		foreach ( $entries as $i => $entry ) {
			$key = 'global_styles' === $kind ? $entry['theme'] : $entry['slug'] . '|' . $entry['theme'];
			if ( isset( $live[ $key ] ) ) {
				$ids       = $live[ $key ];
				$match     = in_array( $entry['id'], $ids, true ) ? $entry['id'] : (int) min( $ids );
				$out[ $i ] = self::result( 'strong', $match, 'global_styles' === $kind ? 'stylesheet' : 'slug_theme', Identity::get( 'post', $match ) );
				continue;
			}
			$out[ $i ] = self::result( 'none' );
		}
		return $out;
	}

	/**
	 * Terms (taxonomy, slug, ID) and menus (slug, ID).
	 *
	 * @param string $kind    'term' or 'menu'.
	 * @param array  $entries Index => fingerprint.
	 * @return array Index => answer.
	 */
	private static function match_terms( $kind, array $entries ) {
		$out = array();
		foreach ( $entries as $i => $entry ) {
			$taxonomy = 'menu' === $kind ? 'nav_menu' : $entry['tax'];
			$allowed  = 'menu' === $kind ? Scope::menus_included() : in_array( $taxonomy, Scope::taxonomies(), true );
			if ( ! $allowed ) {
				$out[ $i ] = self::result( 'excluded' );
				continue;
			}
			$term = get_term_by( 'slug', $entry['slug'], $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				$out[ $i ] = self::result( 'none' );
				continue;
			}
			$id = (int) $term->term_id;
			if ( $id === $entry['id'] ) {
				$out[ $i ] = self::result( 'strong', $id, 'id_slug', Identity::get( 'term', $id ) );
				continue;
			}
			$out[ $i ] = self::result( 'medium', $id, 'slug', Identity::get( 'term', $id ), array( $id ) );
		}
		return $out;
	}

	/**
	 * Posts by ID, by slug within one type, or by type, read-only. One query per shape, each with
	 * literal placeholders only.
	 *
	 * @param string $column ID, post_name (needs $type) or post_type.
	 * @param array  $values Values.
	 * @param string $type   Post type, for post_name.
	 * @return array<int,array> Rows keyed by ID.
	 */
	private static function posts_where( $column, array $values, $type = '' ) {
		global $wpdb;

		$values = array_values( array_unique( $values ) );
		if ( empty( $values ) ) {
			return array();
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only lookups for one baseline batch; the placeholder lists hold one literal %d or %s per value passed to prepare().
		switch ( $column ) {
			case 'ID':
				$ids          = array_map( 'absint', $values );
				$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
				$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status, post_name, post_date_gmt, guid FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} ) ORDER BY ID ASC", $ids ), ARRAY_A );
				break;
			case 'post_name':
				$names        = array_map( 'strval', $values );
				$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
				$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status, post_name, post_date_gmt, guid FROM {$wpdb->posts} WHERE post_type = %s AND post_name IN ( {$placeholders} ) ORDER BY ID ASC", array_merge( array( (string) $type ), $names ) ), ARRAY_A );
				break;
			case 'post_type':
				$types        = array_map( 'strval', $values );
				$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
				$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status, post_name, post_date_gmt, guid FROM {$wpdb->posts} WHERE post_type IN ( {$placeholders} ) ORDER BY ID ASC", $types ), ARRAY_A );
				break;
			default:
				$rows = array();
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['ID'] ] = $row;
		}
		return $out;
	}

	/**
	 * Whether a live row is an item of the kind (status, type).
	 *
	 * @param array  $row  Row.
	 * @param string $kind Kind.
	 * @return bool
	 */
	private static function row_allowed( array $row, $kind ) {
		if ( Scope::kind_for_post_type( (string) $row['post_type'] ) !== $kind ) {
			return false;
		}
		if ( 'attachment' === $kind ) {
			return 'inherit' === $row['post_status'];
		}
		if ( 'menu_item' === $kind ) {
			return in_array( $row['post_status'], array( 'publish', 'draft' ), true );
		}
		return in_array( $row['post_status'], Scope::STATUSES, true );
	}

	/**
	 * IDs of rows other than the matched one.
	 *
	 * @param array $rows    Rows.
	 * @param int   $matched Matched ID.
	 * @return int[]
	 */
	private static function taken_ids( array $rows, $matched ) {
		$out = array();
		foreach ( $rows as $row ) {
			if ( (int) $row['ID'] !== $matched ) {
				$out[] = (int) $row['ID'];
			}
		}
		return array_slice( $out, 0, self::MAX_TAKEN );
	}

	/**
	 * One answer.
	 *
	 * @param string $confidence strong, medium, none or excluded.
	 * @param int    $id         Live ID.
	 * @param string $by         Basis.
	 * @param string $uuid       Live UUID.
	 * @param int[]  $taken      Slug-taken candidates.
	 * @return array
	 */
	private static function result( $confidence, $id = 0, $by = '', $uuid = '', array $taken = array() ) {
		return array(
			'm'     => $confidence,
			'id'    => (int) $id,
			'by'    => $by,
			'uuid'  => $uuid,
			'taken' => array_values( array_map( 'intval', $taken ) ),
		);
	}

	/**
	 * Whether a field is a slug (sanitize_title() shape, up to 200 characters).
	 *
	 * @param array  $entry       Entry.
	 * @param string $key         Field.
	 * @param bool   $allow_empty Whether '' is accepted (drafts have no slug).
	 * @return bool
	 */
	private static function is_slug( array $entry, $key, $allow_empty = false ) {
		if ( ! isset( $entry[ $key ] ) || ! is_string( $entry[ $key ] ) ) {
			return false;
		}
		if ( '' === $entry[ $key ] ) {
			return $allow_empty;
		}
		return strlen( $entry[ $key ] ) <= 200 && 1 !== preg_match( '/[\s\x00-\x1f<>"\'\\\\]/', $entry[ $key ] );
	}

	/**
	 * Whether a field is a post type or taxonomy name.
	 *
	 * @param array  $entry Entry.
	 * @param string $key   Field.
	 * @return bool
	 */
	private static function is_name( array $entry, $key ) {
		return isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) && 1 === preg_match( '/\A[a-z0-9_-]{1,32}\z/', $entry[ $key ] );
	}

	/**
	 * Whether a field is a stylesheet name.
	 *
	 * @param array  $entry Entry.
	 * @param string $key   Field.
	 * @return bool
	 */
	private static function is_stylesheet( array $entry, $key ) {
		return isset( $entry[ $key ] ) && is_string( $entry[ $key ] ) && 1 === preg_match( '/\A[A-Za-z0-9._-]{0,100}\z/', $entry[ $key ] );
	}

	/**
	 * The refusal of a malformed request body.
	 *
	 * @return WP_Error
	 */
	private static function invalid() {
		return new WP_Error( 'invalid_body', 'invalid_body', array( 'status' => 400 ) );
	}
}
