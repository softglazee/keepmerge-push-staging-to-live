<?php
/**
 * Pairing rows.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every query on the pairings table. Rows returned by find() include the encrypted credential
 * columns for the classes that verify requests; summary() strips them before anything is shown.
 */
final class Pairing_Store {

	const STATUSES = array( 'pending', 'awaiting_approval', 'active', 'repair_needed', 'revoked' );

	/**
	 * Columns that hold credentials or credential references; never sent to a screen.
	 */
	const SECRET_COLUMNS = array( 'app_password_enc', 'secret_enc', 'bootstrap_secret_enc', 'app_password_uuid', 'bootstrap_password_uuid' );

	/**
	 * Columns update() may write, with their formats.
	 */
	const FORMATS = array(
		'pairing_uuid'            => '%s',
		'role'                    => '%s',
		'remote_url'              => '%s',
		'rest_root'               => '%s',
		'local_home_url'          => '%s',
		'local_home_hash'         => '%s',
		'status'                  => '%s',
		'receiver_user_id'        => '%d',
		'app_password_uuid'       => '%s',
		'app_password_enc'        => '%s',
		'secret_enc'              => '%s',
		'bootstrap_password_uuid' => '%s',
		'bootstrap_secret_enc'    => '%s',
		'source_site_id'          => '%s',
		'source_ip'               => '%s',
		'default_author_id'       => '%d',
		'pinned_ip'               => '%s',
		'expires_gmt'             => '%s',
		'created_gmt'             => '%s',
		'confirmed_gmt'           => '%s',
		'approved_gmt'            => '%s',
		'approved_by'             => '%d',
		'last_used_gmt'           => '%s',
		'revoked_gmt'             => '%s',
		'remote_login'            => '%s',
		'remote_info'             => '%s',
		'resolved_ip'             => '%s',
		'resolved_gmt'            => '%s',
	);

	/**
	 * One pairing by UUID.
	 *
	 * @param string $uuid Pairing UUID.
	 * @return array|null
	 */
	public static function find( $uuid ) {
		global $wpdb;

		if ( ! is_string( $uuid ) || ! wp_is_uuid( $uuid, 4 ) ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; authentication must read the current row.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE pairing_uuid = %s', Tables::table_name( 'pairings' ), $uuid ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Inserts a row.
	 *
	 * @param array $data Column => value; only known columns are written.
	 * @return int Row ID, 0 on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$data = array_intersect_key( $data, self::FORMATS );
		$fmt  = array();
		foreach ( array_keys( $data ) as $column ) {
			$fmt[] = self::FORMATS[ $column ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table; $wpdb->insert prepares the statement.
		$ok = $wpdb->insert( Tables::table_name( 'pairings' ), $data, $fmt );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Updates columns of one row. A null value stores SQL NULL.
	 *
	 * @param int   $id   Row ID.
	 * @param array $data Column => value; only known columns are written.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$data = array_intersect_key( $data, self::FORMATS );
		if ( empty( $data ) ) {
			return false;
		}

		$fmt = array();
		foreach ( array_keys( $data ) as $column ) {
			$fmt[] = self::FORMATS[ $column ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; $wpdb->update prepares the statement.
		return false !== $wpdb->update( Tables::table_name( 'pairings' ), $data, array( 'id' => absint( $id ) ), $fmt, array( '%d' ) );
	}

	/**
	 * Deletes one row.
	 *
	 * @param int $id Row ID.
	 * @return void
	 */
	public static function delete( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->delete( Tables::table_name( 'pairings' ), array( 'id' => absint( $id ) ), array( '%d' ) );
	}

	/**
	 * Rows of one role, optionally limited to some statuses, newest first.
	 *
	 * @param string   $role     source or destination.
	 * @param string[] $statuses Statuses; all when empty.
	 * @param int      $limit    Most rows.
	 * @return array[]
	 */
	public static function by_role( $role, array $statuses = array(), $limit = 200 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; lifecycle changes need the current rows.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE role = %s ORDER BY id DESC LIMIT %d', Tables::table_name( 'pairings' ), (string) $role, max( 1, absint( $limit ) ) ),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		if ( empty( $statuses ) ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $statuses ) {
					return in_array( $row['status'], $statuses, true );
				}
			)
		);
	}

	/**
	 * Every row not yet revoked (any role).
	 *
	 * @return array[]
	 */
	public static function not_revoked() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; lifecycle changes need the current rows.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status <> %s ORDER BY id ASC LIMIT %d', Tables::table_name( 'pairings' ), 'revoked', 1000 ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * User IDs that are receivers of a pairing in one of the given statuses.
	 *
	 * @param string[] $statuses Statuses; all when empty.
	 * @return int[]
	 */
	public static function receiver_ids( array $statuses = array() ) {
		$ids = array();
		foreach ( self::by_role( 'destination', $statuses, 1000 ) as $row ) {
			if ( (int) $row['receiver_user_id'] > 0 ) {
				$ids[] = (int) $row['receiver_user_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * A row with every credential column removed, for screens and REST responses.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function summary( array $row ) {
		return array_diff_key( $row, array_flip( self::SECRET_COLUMNS ) );
	}
}
