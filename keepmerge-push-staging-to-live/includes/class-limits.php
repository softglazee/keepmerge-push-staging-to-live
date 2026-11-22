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
	 * Share of memory_limit at which a step stops (blueprint §13).
	 */
	const MEMORY_SHARE = 0.7;

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
		$max    = self::max_execution_time();
		$budget = 0 === $max ? self::MAX_BUDGET : max( 1, min( self::MAX_BUDGET, (int) floor( 0.6 * $max ) ) );

		/**
		 * Filters the time budget of one step, in seconds. It may only lower it (a host whose proxy
		 * ends requests sooner than PHP does); a higher value is ignored.
		 *
		 * @param int $budget Seconds.
		 */
		$filtered = apply_filters( 'softglaze_sp_time_budget', $budget );
		if ( is_numeric( $filtered ) ) {
			$budget = max( 1, min( $budget, (int) $filtered ) );
		}
		return $budget;
	}

	/**
	 * The end of a step: now plus the time budget minus a reserve for the answer.
	 *
	 * @param int $reserve Seconds kept for the work after the loop.
	 * @return float microtime.
	 */
	public static function deadline( $reserve = 2 ) {
		return microtime( true ) + max( 1, self::time_budget() - max( 0, (int) $reserve ) );
	}

	/**
	 * Whether this request still uses less than the given share of memory_limit (blueprint §13:
	 * a batch stops at 70 %). Always true when PHP has no memory limit.
	 *
	 * @param float $share Share of memory_limit.
	 * @return bool
	 */
	public static function memory_ok( $share = self::MEMORY_SHARE ) {
		$limit = self::memory_limit_bytes();
		if ( $limit < 1 ) {
			return true;
		}
		return memory_get_usage() < (float) $share * $limit;
	}

	/**
	 * Whether a loop must stop now: past its deadline, or above 70 % of memory_limit.
	 *
	 * @param float $deadline microtime.
	 * @return bool
	 */
	public static function over( $deadline ) {
		return microtime( true ) > (float) $deadline || ! self::memory_ok();
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

	/**
	 * PHP post_max_size in bytes; 0 means no limit.
	 *
	 * @return int
	 */
	public static function post_max_size_bytes() {
		return max( 0, (int) wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) );
	}
}
