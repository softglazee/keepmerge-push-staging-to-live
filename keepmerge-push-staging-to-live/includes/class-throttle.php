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
 * The one place that reads the client address, and the per-address failure counter (blueprint
 * §5.6, threat model §6 step 3): 10 failed authentications within 10 minutes block the address
 * for 15 minutes; a blocked address gets 429 ip_blocked with Retry-After before any signature
 * work.
 */
final class Throttle {

	const MAX_FAILURES = 10;

	const WINDOW = 600;

	const BLOCK = 900;

	/**
	 * The client IP address, from REMOTE_ADDR only.
	 *
	 * Forwarded-for headers are never read: anyone can send them, and the address is used for
	 * throttling and the optional IP pin (threat model §6).
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
	 * Counts one failed authentication for an address and blocks it at the limit.
	 *
	 * The counter restarts when its window has passed. One statement creates or updates the
	 * row, so parallel failures are not lost.
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
	 * Throttle bucket for an address: "ip:" plus the address in its canonical text form.
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
		$text   = false === $packed ? $valid : inet_ntop( $packed );

		return 'ip:' . strtolower( (string) $text );
	}
}
