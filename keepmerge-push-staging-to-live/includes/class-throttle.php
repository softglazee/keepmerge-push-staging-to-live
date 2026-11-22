<?php
/**
 * Client address and failed-authentication throttling.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place that reads the client address, and the per-address failure counter: 10 failed
 * authentications within 10 minutes block the address for 15 minutes (429 before any signature
 * work).
 */
final class Throttle {

	const MAX_FAILURES = 10;

	const WINDOW = 600;

	const BLOCK = 900;

	/**
	 * The client IP address, from REMOTE_ADDR only: forwarded-for headers can be sent by anyone,
	 * and the address drives throttling and the IP pin.
	 *
	 * @return string Validated IPv4 or IPv6 address, or '' when there is none.
	 */
	public static function client_ip() {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}

		$ip = filter_var( wp_unslash( $_SERVER['REMOTE_ADDR'] ), FILTER_VALIDATE_IP ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated as an IP address by filter_var.

		return is_string( $ip ) ? $ip : '';
	}

	/**
	 * Seconds until a blocked address may try again.
	 *
	 * @param string $ip Address.
	 * @return int 0 when not blocked.
	 */
	public static function blocked_for( $ip ) {
		global $wpdb;

		$bucket = self::bucket( $ip );
		if ( '' === $bucket ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; a cached answer would let a blocked address through.
		$until = $wpdb->get_var( $wpdb->prepare( 'SELECT blocked_until FROM %i WHERE bucket = %s', Tables::table_name( 'throttle' ), $bucket ) );
		if ( ! is_string( $until ) || '' === $until ) {
			return 0;
		}

		$left = (int) strtotime( $until . ' UTC' ) - time();

		return max( 0, $left );
	}

	/**
	 * Counts one failed authentication for an address and blocks it at the limit. One statement
	 * creates or updates the row, so parallel failures are not lost.
	 *
	 * @param string $ip Address.
	 * @return bool True when the address is blocked after this failure.
	 */
	public static function record_failure( $ip ) {
		global $wpdb;

		$bucket = self::bucket( $ip );
		if ( '' === $bucket ) {
			return false;
		}

		$table  = Tables::table_name( 'throttle' );
		$now    = gmdate( 'Y-m-d H:i:s' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::WINDOW );

		// MySQL evaluates the assignments left to right; both IF()s read the old window_start.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; atomic upsert of the counter.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (bucket, hits, window_start, blocked_until) VALUES (%s, 1, %s, NULL) ON DUPLICATE KEY UPDATE hits = IF(window_start < %s, 1, hits + 1), window_start = IF(window_start < %s, %s, window_start)',
				$table,
				$bucket,
				$now,
				$cutoff,
				$cutoff,
				$now
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$blocked = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET blocked_until = %s WHERE bucket = %s AND hits >= %d AND ( blocked_until IS NULL OR blocked_until < %s )',
				$table,
				gmdate( 'Y-m-d H:i:s', time() + self::BLOCK ),
				$bucket,
				self::MAX_FAILURES,
				$now
			)
		);

		return (int) $blocked > 0 || self::blocked_for( $ip ) > 0;
	}

	/**
	 * Deletes rows whose window and block have both passed.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune() {
		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; bounded delete of expired rows.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE window_start < %s AND ( blocked_until IS NULL OR blocked_until < %s ) LIMIT %d',
				Tables::table_name( 'throttle' ),
				gmdate( 'Y-m-d H:i:s', time() - self::WINDOW ),
				$now,
				5000
			)
		);
	}

	/**
	 * An address in its canonical text form (lower case, IPv6 compressed), or '' when it is not a
	 * valid address. The IP pin compares exact addresses with this; the throttle uses bucket().
	 *
	 * @param string $ip Address.
	 * @return string
	 */
	public static function canonical( $ip ) {
		$valid = is_string( $ip ) ? filter_var( $ip, FILTER_VALIDATE_IP ) : false;
		if ( ! is_string( $valid ) ) {
			return '';
		}
		$packed = inet_pton( $valid );
		return strtolower( false === $packed ? $valid : (string) inet_ntop( $packed ) );
	}

	/**
	 * Throttle bucket for an address: the IPv4 address, or an IPv6 address's /64 network, since
	 * one IPv6 host usually holds a whole /64 and could rotate past a per-address block.
	 *
	 * @param string $ip Address.
	 * @return string '' when the address is not valid.
	 */
	public static function bucket( $ip ) {
		$valid = is_string( $ip ) ? filter_var( $ip, FILTER_VALIDATE_IP ) : false;
		if ( ! is_string( $valid ) ) {
			return '';
		}

		$packed = inet_pton( $valid );
		if ( false === $packed ) {
			return 'ip:' . strtolower( $valid );
		}
		if ( 4 === strlen( $packed ) ) {
			return 'ip:' . inet_ntop( $packed );
		}
		if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			return 'ip:' . inet_ntop( substr( $packed, 12 ) );
		}

		return 'ip:' . strtolower( (string) inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) ) . '/64';
	}
}
