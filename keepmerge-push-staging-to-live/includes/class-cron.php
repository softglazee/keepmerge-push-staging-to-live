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
 * Schedules and runs softglaze_sp_daily_prune, which deletes only expired plugin data and takes
 * no input.
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
	 * The prune itself, each part bounded per run: expired audit rows, keys, approvals, nonces,
	 * throttle rows, plans, every due unapplied session (time-boxed), stale applies (marked
	 * interrupted), old temporary folders, and snapshots past retention (never the newest pushes).
	 *
	 * @return array Counts of what was pruned (for WP-CLI and tests).
	 */
	public static function daily_prune() {
		$settings = Settings::get();
		$out      = array(
			'audit'    => Audit_Log::prune( (int) $settings['audit_days'] ),
			'pairings' => Pairing_Service::cleanup_expired(),
		);
		Receive_Window::is_open();
		$out['nonces'] = Nonce_Store::prune( 5000 );
		Throttle::prune();
		Sync\Plan_Store::prune( 0 );
		$out['expired']     = Transfer\Push_Session::expire_all_due( 50 );
		$out['interrupted'] = self::detect_stale();
		$out['temp']        = Transfer\Media_Receiver::prune_temp();
		$out['retention']   = Transfer\Push_Session::prune_retention( (int) $settings['snapshot_days'] );
		return $out;
	}

	/**
	 * Applies whose lock went stale without anybody looking at them are marked interrupted.
	 *
	 * @return int Sessions marked.
	 */
	private static function detect_stale() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$uuids = $wpdb->get_col( $wpdb->prepare( "SELECT push_uuid FROM %i WHERE side = 'destination' AND state IN ('snapshotting','applying') LIMIT 20", Tables::table_name( 'pushes' ) ) );
		$done  = 0;
		foreach ( is_array( $uuids ) ? $uuids : array() as $uuid ) {
			$session = Transfer\Push_Session::find( (string) $uuid );
			if ( null !== $session && 'interrupted' === Apply\Recovery::detect( $session )['state'] ) {
				++$done;
			}
		}
		return $done;
	}
}
