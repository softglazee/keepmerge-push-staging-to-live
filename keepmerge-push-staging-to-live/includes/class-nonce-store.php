<?php
/**
 * One-time request nonces.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replay guard and per-pairing request count.
 *
 * A nonce is stored only after the signature has been verified, so strangers cannot fill the
 * table. (pairing_id, nonce) is unique, so a second insert is a replay. Rows live 900 seconds,
 * longer than the ±300 s clock window, so older replays fail the clock check instead.
 */
final class Nonce_Store {

	const TTL = 900;

	const PRUNE_BATCH = 500;

	const RATE_WINDOW = 60;

	const RATE_LIMIT = 300;

	/**
	 * Records a nonce.
	 *
	 * @param int    $pairing_id Pairing row ID.
	 * @param string $nonce      32 lowercase hex.
	 * @return bool True when stored, false when this pairing has already used the nonce (or the
	 *              row could not be written, which refuses the request just the same).
	 */
	public static function insert( $pairing_id, $nonce ) {
		global $wpdb;

		$pairing_id = absint( $pairing_id );
		if ( $pairing_id < 1 || ! is_string( $nonce ) || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $nonce ) ) {
			return false;
		}

		self::prune( self::PRUNE_BATCH );

		// INSERT IGNORE: a duplicate key affects no row instead of printing a database error.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the unique key is the replay check.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (pairing_id, nonce, received_gmt) VALUES (%d, %s, %s)',
				Tables::table_name( 'nonces' ),
				$pairing_id,
				$nonce,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		return 1 === (int) $affected;
	}

	/**
	 * Signed requests this pairing made in the last $seconds seconds.
	 *
	 * @param int $pairing_id Pairing row ID.
	 * @param int $seconds    Window.
	 * @return int
	 */
	public static function count_recent( $pairing_id, $seconds = self::RATE_WINDOW ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the rate limit needs the current count.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE pairing_id = %d AND received_gmt >= %s',
				Tables::table_name( 'nonces' ),
				absint( $pairing_id ),
				gmdate( 'Y-m-d H:i:s', time() - max( 1, absint( $seconds ) ) )
			)
		);
	}

	/**
	 * Deletes nonces older than the TTL, in one bounded statement.
	 *
	 * @param int $limit Most rows to delete.
	 * @return int Rows deleted.
	 */
	public static function prune( $limit = self::PRUNE_BATCH ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; bounded delete of expired rows.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE received_gmt < %s ORDER BY received_gmt LIMIT %d',
				Tables::table_name( 'nonces' ),
				gmdate( 'Y-m-d H:i:s', time() - self::TTL ),
				max( 1, absint( $limit ) )
			)
		);
	}

	/**
	 * Deletes every nonce of a pairing (revocation).
	 *
	 * @param int $pairing_id Pairing row ID.
	 * @return void
	 */
	public static function forget_pairing( $pairing_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE pairing_id = %d', Tables::table_name( 'nonces' ), absint( $pairing_id ) ) );
	}
}
