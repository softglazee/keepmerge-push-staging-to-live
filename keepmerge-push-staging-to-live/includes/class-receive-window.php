<?php
/**
 * The receive window.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Receive pushes" on the destination (blueprint §5.3, threat model T20).
 *
 * Remote routes exist only while the window is open. An administrator opens it for a whole
 * number of hours from 1 to 24; there is no "until I close it". When the time is up it closes by
 * itself on the next request, except while a push holds a fresh lock (heartbeat younger than
 * 5 minutes), and never later than 24 hours after it was opened. Closing by hand is immediate.
 */
final class Receive_Window {

	const OPTION = 'softglaze_sp_receive';

	/**
	 * Push lock of the destination's push session (Transfer\Push_Lock):
	 * array( 'push_uuid' => string, 'pairing_id' => int, 'heartbeat' => unix seconds, 'acquired' => unix seconds ).
	 */
	const LOCK_OPTION = 'softglaze_sp_push_lock';

	const MIN_HOURS = 1;

	const MAX_HOURS = 24;

	const DEFAULT_HOURS = 1;

	/**
	 * Durations the screen offers.
	 */
	const CHOICES = array( 1, 8, 24 );

	const LOCK_FRESH = 300;

	/**
	 * Stored state with defaults; changes nothing.
	 *
	 * @return array{open:bool,opened_at:int,closes_at:int}
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'open'      => ! empty( $stored['open'] ),
			'opened_at' => isset( $stored['opened_at'] ) ? absint( $stored['opened_at'] ) : 0,
			'closes_at' => isset( $stored['closes_at'] ) ? absint( $stored['closes_at'] ) : 0,
		);
	}

	/**
	 * Whether remote requests are accepted right now. Closes the window, audited as the system,
	 * when its time is up and no fresh push lock keeps it open.
	 *
	 * @return bool
	 */
	public static function is_open() {
		$state = self::state();
		if ( ! $state['open'] ) {
			return false;
		}

		$now = time();
		if ( $state['closes_at'] > $now ) {
			return true;
		}

		$hard_limit = $state['opened_at'] + self::MAX_HOURS * HOUR_IN_SECONDS;
		if ( $state['opened_at'] > 0 && $now < $hard_limit && self::push_lock_is_fresh() ) {
			return true;
		}

		self::close( 'expired', 'system' );

		return false;
	}

	/**
	 * Opens the window.
	 *
	 * @param mixed  $hours      Whole number of hours, 1 to 24.
	 * @param string $actor_type Audit actor type; detected when ''.
	 * @return array|WP_Error New state, or WP_Error hours_out_of_range / not_supported.
	 */
	public static function open( $hours, $actor_type = '' ) {
		$hours = self::valid_hours( $hours );
		if ( null === $hours ) {
			return new WP_Error(
				'hours_out_of_range',
				/* translators: 1: minimum hours, 2: maximum hours. */
				sprintf( __( 'Choose a whole number of hours from %1$d to %2$d. The window always closes by itself.', 'keepmerge-push-staging-to-live' ), self::MIN_HOURS, self::MAX_HOURS ),
				array( 'status' => 400 )
			);
		}

		if ( is_multisite() ) {
			return new WP_Error( 'not_supported', __( 'Receiving pushes on a multisite network is not supported in this version.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}

		$now   = time();
		$state = array(
			'open'      => true,
			'opened_at' => $now,
			'closes_at' => $now + $hours * HOUR_IN_SECONDS,
		);
		update_option( self::OPTION, $state, true );

		$args = array(
			'details' => array(
				'hours'     => $hours,
				'closes_at' => gmdate( 'Y-m-d H:i:s', $state['closes_at'] ),
			),
		);
		if ( '' !== $actor_type ) {
			$args['actor_type'] = $actor_type;
		}
		Audit_Log::write( 'receive_opened', $args );

		return $state;
	}

	/**
	 * Closes the window now. Does nothing (and audits nothing) when it is already closed.
	 *
	 * @param string $reason     manual, expired, deactivated or clone_detected.
	 * @param string $actor_type Audit actor type; detected when ''.
	 * @return bool Whether it was open.
	 */
	public static function close( $reason = 'manual', $actor_type = '' ) {
		$state = self::state();

		update_option(
			self::OPTION,
			array(
				'open'      => false,
				'opened_at' => 0,
				'closes_at' => 0,
			),
			true
		);

		if ( ! $state['open'] ) {
			return false;
		}

		$args = array( 'details' => array( 'reason' => sanitize_key( $reason ) ) );
		if ( '' !== $actor_type ) {
			$args['actor_type'] = $actor_type;
		}
		Audit_Log::write( 'receive_closed', $args );

		return true;
	}

	/**
	 * A valid duration, or null.
	 *
	 * Accepts integers and digit-only strings (REST and WP-CLI deliver strings); refuses
	 * fractions, signs, units and anything outside 1–24.
	 *
	 * @param mixed $hours Candidate.
	 * @return int|null
	 */
	public static function valid_hours( $hours ) {
		if ( is_int( $hours ) ) {
			$value = $hours;
		} elseif ( is_string( $hours ) && 1 === preg_match( '/\A[0-9]{1,2}\z/', $hours ) ) {
			$value = (int) $hours;
		} else {
			return null;
		}

		return ( $value >= self::MIN_HOURS && $value <= self::MAX_HOURS ) ? $value : null;
	}

	/**
	 * Whether a push holds the destination lock with a heartbeat younger than 5 minutes.
	 *
	 * @return bool
	 */
	public static function push_lock_is_fresh() {
		return Transfer\Push_Lock::is_fresh();
	}
}
