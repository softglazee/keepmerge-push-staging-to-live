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
 * The one place that reads the client address.
 *
 * TODO(Phase 2A): failure counting per IP in the throttle table, 10 failures per 10 minutes
 * then 429 for 15 minutes with Retry-After (threat model §6 step 3).
 */
final class Throttle {

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
}
