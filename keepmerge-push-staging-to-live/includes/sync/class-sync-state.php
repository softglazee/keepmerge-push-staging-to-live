<?php
/**
 * The sync_state table.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

use SoftGlaze\SafePush\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every query on sync_state (blueprint §6.1, §14.1): per pairing, a UUID's local object, the
 * remote object it is linked to, how it was matched, and (on live, from Phase 7) the base hash.
 *
 * On the source the baseline writes rows here; it is the only table it writes besides the UUID
 * meta of its own objects. Live reads its rows for base hashes and never writes them in this
 * version (blueprint §6.4 step 3).
 */
final class Sync_State {

	/**
	 * Match confidences a row can carry. strong rows (and, from Phase 7, pushed rows) link a
	 * local object to a remote one; the others are recorded for the plan, never followed.
	 */
	const CONFIDENCES = array( 'strong', 'medium', 'none', 'duplicate', 'excluded', 'pushed' );

	/**
	 * Confidences that link two objects.
	 */
	const LINKED = array( 'strong', 'pushed' );

	/**
	 * Inserts or updates the row of a UUID in a pairing.
	 *
	 * @param int    $pairing_id       Pairing row ID.
	 * @param string $uuid             UUID, option:<name> or unit:<name>.
	 * @param string $object_type      Kind.
	 * @param int    $object_id        Local object ID.
	 * @param int    $remote_object_id Remote object ID (0 when none).
	 * @param string $confidence       One of CONFIDENCES.
	 * @return bool
	 */
	public static function upsert( $pairing_id, $uuid, $object_type, $object_id, $remote_object_id, $confidence ) {
		global $wpdb;

		if ( ! in_array( $confidence, self::CONFIDENCES, true ) || '' === (string) $uuid ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; one prepared upsert.
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (pairing_id, uuid, object_type, object_id, remote_object_id, match_confidence) VALUES (%d, %s, %s, %d, %d, %s)
				ON DUPLICATE KEY UPDATE object_type = VALUES(object_type), object_id = VALUES(object_id), remote_object_id = VALUES(remote_object_id), match_confidence = VALUES(match_confidence)',
				Tables::table_name( 'sync_state' ),
				absint( $pairing_id ),
				substr( (string) $uuid, 0, 191 ),
				substr( (string) $object_type, 0, 32 ),
				absint( $object_id ),
				absint( $remote_object_id ),
				$confidence
			)
		);

		return false !== $result;
	}

	/**
	 * Deletes a pairing's rows that carry no base hash (a baseline that starts again). Rows with
	 * a base hash come from pushes and stay.
	 *
	 * @param int $pairing_id Pairing row ID.
	 * @return int Rows deleted.
	 */
	public static function clear_unbased( $pairing_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE pairing_id = %d AND base_hash = ''", Tables::table_name( 'sync_state' ), absint( $pairing_id ) ) );

		return (int) $deleted;
	}

	/**
	 * Deletes every row of a pairing.
	 *
	 * @param int $pairing_id Pairing row ID.
	 * @return int Rows deleted.
	 */
	public static function delete_pairing( $pairing_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE pairing_id = %d', Tables::table_name( 'sync_state' ), absint( $pairing_id ) ) );

		return (int) $deleted;
	}

	/**
	 * Linked local IDs mapped to remote IDs for one ID space ('post' or 'term').
	 *
	 * @param int    $pairing_id Pairing row ID.
	 * @param string $space      'post' or 'term'.
	 * @return array<int,int>
	 */
	public static function linked_map( $pairing_id, $space ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_type, object_id, remote_object_id, match_confidence FROM %i WHERE pairing_id = %d AND remote_object_id > 0', Tables::table_name( 'sync_state' ), absint( $pairing_id ) ), ARRAY_A );

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! in_array( $row['match_confidence'], self::LINKED, true ) || Scope::space( $row['object_type'] ) !== $space ) {
				continue;
			}
			$out[ (int) $row['object_id'] ] = (int) $row['remote_object_id'];
		}
		return $out;
	}

	/**
	 * Rows of a pairing keyed by "kind:local id".
	 *
	 * @param int $pairing_id Pairing row ID.
	 * @return array<string,array>
	 */
	public static function by_object( $pairing_id ) {
		$out = array();
		foreach ( self::all( $pairing_id ) as $row ) {
			$out[ $row['object_type'] . ':' . (int) $row['object_id'] ] = $row;
		}
		return $out;
	}

	/**
	 * Every row of a pairing.
	 *
	 * @param int $pairing_id Pairing row ID.
	 * @return array[]
	 */
	public static function all( $pairing_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE pairing_id = %d ORDER BY id ASC', Tables::table_name( 'sync_state' ), absint( $pairing_id ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The row of a UUID in a pairing.
	 *
	 * @param int    $pairing_id Pairing row ID.
	 * @param string $uuid       UUID.
	 * @return array|null
	 */
	public static function find( $pairing_id, $uuid ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE pairing_id = %d AND uuid = %s', Tables::table_name( 'sync_state' ), absint( $pairing_id ), (string) $uuid ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Base hashes of several UUIDs in a pairing (rows without a base are left out).
	 *
	 * @param int      $pairing_id Pairing row ID.
	 * @param string[] $uuids      UUIDs.
	 * @return array<string,array> uuid => row.
	 */
	public static function rows_for( $pairing_id, array $uuids ) {
		$out = array();
		if ( empty( $uuids ) ) {
			return $out;
		}
		$wanted = array_flip( $uuids );
		foreach ( self::all( $pairing_id ) as $row ) {
			if ( isset( $wanted[ $row['uuid'] ] ) ) {
				$out[ $row['uuid'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Rows with a base hash after a cursor, for the live-only part of a manifest.
	 *
	 * @param int $pairing_id Pairing row ID.
	 * @param int $after_id   Row ID cursor.
	 * @param int $limit      Most rows.
	 * @return array[]
	 */
	public static function based_after( $pairing_id, $after_id, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE pairing_id = %d AND base_hash <> '' AND id > %d ORDER BY id ASC LIMIT %d", Tables::table_name( 'sync_state' ), absint( $pairing_id ), absint( $after_id ), max( 1, absint( $limit ) ) ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Row counts of a pairing per confidence.
	 *
	 * @param int $pairing_id Pairing row ID.
	 * @return array<string,int>
	 */
	public static function counts( $pairing_id ) {
		global $wpdb;

		$out = array_fill_keys( self::CONFIDENCES, 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT match_confidence AS c, COUNT(*) AS n FROM %i WHERE pairing_id = %d GROUP BY match_confidence', Tables::table_name( 'sync_state' ), absint( $pairing_id ) ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $out[ $row['c'] ] ) ) {
				$out[ $row['c'] ] = (int) $row['n'];
			}
		}
		return $out;
	}

	/**
	 * A page of a pairing's rows with one confidence, by row ID.
	 *
	 * @param int    $pairing_id Pairing row ID.
	 * @param string $confidence One of CONFIDENCES.
	 * @param int    $page       1-based page.
	 * @param int    $per_page   Rows per page.
	 * @return array{rows:array[],total:int}
	 */
	public static function page( $pairing_id, $confidence, $page, $per_page ) {
		global $wpdb;

		$table    = Tables::table_name( 'sync_state' );
		$per_page = max( 1, min( 100, absint( $per_page ) ) );
		$offset   = ( max( 1, absint( $page ) ) - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pairing_id = %d AND match_confidence = %s', $table, absint( $pairing_id ), (string) $confidence ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE pairing_id = %d AND match_confidence = %s ORDER BY id ASC LIMIT %d OFFSET %d', $table, absint( $pairing_id ), (string) $confidence, $per_page, $offset ), ARRAY_A );

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Whether a UUID is used by another pairing's rows on this site.
	 *
	 * @param string $uuid       UUID.
	 * @param int    $pairing_id The pairing to leave out.
	 * @return bool
	 */
	public static function used_elsewhere( $uuid, $pairing_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE uuid = %s AND pairing_id <> %d', Tables::table_name( 'sync_state' ), (string) $uuid, absint( $pairing_id ) ) );

		return $count > 0;
	}
}
