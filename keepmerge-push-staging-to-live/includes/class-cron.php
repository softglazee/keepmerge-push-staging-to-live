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
	 * Audit rows past retention, expired pairing keys and approvals (with their receivers), a
	 * receive window whose time is up, nonces older than 900 s and spent throttle rows; the rest
	 * of the list arrives with the data it prunes.
	 *
	 * @return void
	 */
	public static function daily_prune() {
		$settings = Settings::get();
		Audit_Log::prune( (int) $settings['audit_days'] );

		Pairing_Service::cleanup_expired();
		Receive_Window::is_open();
		Nonce_Store::prune( 5000 );
		Throttle::prune();
		Sync\Plan_Store::prune( 0 );
		Transfer\Push_Session::expire_old( 50 );

		// TODO(Phase 8): temp directories older than 24 h, push_items rows and snapshot files past
		// retention (never the newest push's snapshot).
	}
}
