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
	 * Blueprint §14.5, every part bounded per run: audit rows past retention, expired pairing keys
	 * and approvals (with their receivers), a receive window whose time is up, nonces older than
	 * 900 s, spent throttle rows, expired plans, push sessions never applied within 24 hours, applies
	 * whose lock went stale (marked interrupted), temporary folders older than 24 hours, and the
	 * item rows and snapshot files of pushes past the snapshot retention (never the newest pushes).
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
		$out['expired']     = Transfer\Push_Session::expire_old( 50 );
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
