<?php
/**
 * The daily prune.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and runs softglaze_sp_daily_prune (blueprint §14.5). It deletes only expired
 * plugin data and takes no input.
 */
final class Cron {

	const HOOK = 'softglaze_sp_daily_prune';

	/**
	 * Puts the prune on the schedule if it is not there yet.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	/**
	 * Removes every scheduled prune (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The prune itself.
	 *
	 * The audit log already has a retention setting, so its prune runs now; the rest of the
	 * list arrives with the data it prunes.
	 *
	 * @return void
	 */
	public static function daily_prune() {
		$settings = Settings::get();
		Audit_Log::prune( (int) $settings['audit_days'] );

		// TODO(Phase 8): nonces older than 900 s, expired pending and awaiting_approval pairings,
		// throttle rows, temp directories older than 24 h, expired push sessions, push_items rows
		// and snapshot files past retention (never the newest push's snapshot).
	}
}
