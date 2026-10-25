<?php
/**
 * PHP limits and the per-request time budget.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the server's PHP limits; never changes them (work in time-boxed steps
 * instead of raising limits).
 */
final class Limits {

	/**
	 * Longest a single step may run, in seconds, before it hands back to the caller.
	 */
	const MAX_BUDGET = 20;

	/**
	 * PHP max_execution_time in seconds; 0 means no limit.
	 *
	 * @return int
	 */
	public static function max_execution_time() {
		return max( 0, (int) ini_get( 'max_execution_time' ) );
	}

	/**
	 * Time budget per request (blueprint §13): min( 20 s, 0.6 × max_execution_time ), 20 s when
	 * PHP has no limit.
	 *
	 * @return int Seconds, at least 1.
	 */
	public static function time_budget() {
		$max = self::max_execution_time();
		if ( 0 === $max ) {
			return self::MAX_BUDGET;
		}
		return max( 1, min( self::MAX_BUDGET, (int) floor( 0.6 * $max ) ) );
	}

	/**
	 * PHP memory_limit in bytes; -1 means no limit.
	 *
	 * @return int
	 */
	public static function memory_limit_bytes() {
		$raw = (string) ini_get( 'memory_limit' );
		if ( '-1' === trim( $raw ) ) {
			return -1;
		}
		return (int) wp_convert_hr_to_bytes( $raw );
	}
}
