<?php
/**
 * UUIDs of pushed objects.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Object identity: a UUID v4 in the meta key _softglaze_sp_uuid (post meta, or term meta for
 * terms and menus); options and units are identified by name. assign(), adopt(), renew() and
 * dedupe() run on the source only; live gets its UUIDs from a push, through stamp() inside an
 * apply after the item's snapshot, so a rollback can remove them.
 */
final class Identity {

	const META_KEY = '_softglaze_sp_uuid';

	/**
	 * The UUID of an object, or '' when it has none.
	 *
	 * @param string $space 'post' or 'term'.
	 * @param int    $id    Object ID.
	 * @return string
	 */
	public static function get( $space, $id ) {
		$id = absint( $id );
		if ( $id < 1 ) {
			return '';
		}
		$value = 'term' === $space ? get_term_meta( $id, self::META_KEY, true ) : get_post_meta( $id, self::META_KEY, true );
		return is_string( $value ) && wp_is_uuid( $value, 4 ) ? strtolower( $value ) : '';
	}

	/**
	 * The UUID of an object, creating one when it has none. Source side only.
	 *
	 * @param string $space 'post' or 'term'.
	 * @param int    $id    Object ID.
	 * @return string '' when the object does not exist.
	 */
	public static function assign( $space, $id ) {
		$existing = self::get( $space, $id );
		if ( '' !== $existing ) {
			return $existing;
		}
		if ( ! self::exists( $space, $id ) ) {
			return '';
		}
		$uuid = wp_generate_uuid4();
		self::write( $space, $id, $uuid );
		return $uuid;
	}

	/**
	 * Sets an object's UUID to one live already uses for the matched object.
	 * Source side only.
	 *
	 * @param string $space 'post' or 'term'.
	 * @param int    $id    Object ID.
	 * @param string $uuid  UUID to adopt.
	 * @return bool
	 */
	public static function adopt( $space, $id, $uuid ) {
		if ( ! is_string( $uuid ) || ! wp_is_uuid( $uuid, 4 ) || ! self::exists( $space, $id ) ) {
			return false;
		}
		self::write( $space, $id, strtolower( $uuid ) );
		return true;
	}

	/**
	 * Gives a fresh UUID to an object. Source side only (duplicated meta, see dedupe()).
	 *
	 * @param string $space 'post' or 'term'.
	 * @param int    $id    Object ID.
	 * @return string
	 */
	public static function renew( $space, $id ) {
		if ( ! self::exists( $space, $id ) ) {
			return '';
		}
		$uuid = wp_generate_uuid4();
		self::write( $space, $id, $uuid );
		return $uuid;
	}

	/**
	 * Live, inside an apply: gives an object the UUID the push names for it.
	 *
	 * @param string $space 'post' or 'term'.
	 * @param int    $id    Object ID.
	 * @param string $uuid  UUID.
	 * @return bool
	 */
	public static function stamp( $space, $id, $uuid ) {
		if ( ! is_string( $uuid ) || ! wp_is_uuid( $uuid, 4 ) || ! self::exists( $space, $id ) ) {
			return false;
		}
		$existing = self::get( $space, $id );
		if ( '' !== $existing ) {
			// A UUID another pairing gave this object stays: the pairing's sync state links it.
			return strtolower( $uuid ) === $existing;
		}
		self::write( $space, $id, strtolower( $uuid ) );
		return true;
	}

	/**
	 * Objects that carry a UUID (at most a few: normally one).
	 *
	 * @param string $space 'post' or 'term'.
	 * @param string $uuid  UUID.
	 * @return int[]
	 */
	public static function find( $space, $uuid ) {
		global $wpdb;

		if ( ! is_string( $uuid ) || ! wp_is_uuid( $uuid, 4 ) ) {
			return array();
		}

		if ( 'term' === $space ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Lookup of one indexed meta key; no cache holds UUID-to-object maps.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 5", self::META_KEY, strtolower( $uuid ) ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Lookup of one indexed meta key; no cache holds UUID-to-object maps.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 5", self::META_KEY, strtolower( $uuid ) ) );
		}

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Every UUID of a space keyed by object ID (one query; used to fill exports in bulk).
	 *
	 * @param string $space 'post' or 'term'.
	 * @return array<int,string>
	 */
	public static function all( $space ) {
		global $wpdb;

		if ( 'term' === $space ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bulk read of one meta key.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_id AS id, meta_value AS uuid FROM {$wpdb->termmeta} WHERE meta_key = %s", self::META_KEY ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bulk read of one meta key.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id AS id, meta_value AS uuid FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_KEY ), ARRAY_A );
		}

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( wp_is_uuid( (string) $row['uuid'], 4 ) ) {
				$out[ (int) $row['id'] ] = strtolower( (string) $row['uuid'] );
			}
		}
		return $out;
	}

	/**
	 * Source side: a UUID copied onto a second object (duplicate-post tools copy every meta key)
	 * would make two objects one. The object with the lowest ID keeps the UUID; the others get a
	 * fresh one.
	 *
	 * @param string $space 'post' or 'term'.
	 * @return int Objects that got a fresh UUID.
	 */
	public static function dedupe( $space ) {
		$seen    = array();
		$renewed = 0;
		$all     = self::all( $space );
		ksort( $all );
		foreach ( $all as $id => $uuid ) {
			if ( isset( $seen[ $uuid ] ) ) {
				self::renew( $space, $id );
				++$renewed;
				continue;
			}
			$seen[ $uuid ] = $id;
		}
		return $renewed;
	}

	/**
	 * Whether an object exists.
	 *
	 * @param string $space 'post' or 'term'.
	 * @param int    $id    ID.
	 * @return bool
	 */
	public static function exists( $space, $id ) {
		$id = absint( $id );
		if ( $id < 1 ) {
			return false;
		}
		if ( 'term' === $space ) {
			$term = get_term( $id );
			return $term instanceof \WP_Term;
		}
		return get_post( $id ) instanceof \WP_Post;
	}

	/**
	 * Stores the meta (one value per object).
	 *
	 * @param string $space 'post' or 'term'.
	 * @param int    $id    ID.
	 * @param string $uuid  UUID.
	 * @return void
	 */
	private static function write( $space, $id, $uuid ) {
		if ( 'term' === $space ) {
			update_term_meta( absint( $id ), self::META_KEY, $uuid );
		} else {
			update_post_meta( absint( $id ), self::META_KEY, $uuid );
		}
	}
}
