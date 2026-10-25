<?php
/**
 * The destination lock: one push session at a time.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Transfer;

use SoftGlaze\SafePush\Receive_Window;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The lock of blueprint §10.1 in the option softglaze_sp_push_lock (autoload off):
 * { push_uuid, pairing_id, heartbeat, acquired }.
 *
 * It is taken with one INSERT IGNORE of the option row, so two sessions opened at the same moment
 * cannot both get it; core's add_option() checks for the row and inserts in two steps. The owning
 * session renews the heartbeat on each of its calls. A heartbeat older than 5 minutes makes the
 * lock stale: the session shows as interrupted and the receive window may close, but a stale lock
 * is never handed to another push by itself (threat model §7.7: a live administrator releases it,
 * Phase 8, or its own source aborts, or it expires with its session after 24 hours).
 */
final class Push_Lock {

	const OPTION = Receive_Window::LOCK_OPTION;

	/**
	 * Seconds after the last heartbeat during which the lock counts as fresh.
	 */
	const FRESH = Receive_Window::LOCK_FRESH;

	/**
	 * The lock, or null when nobody holds it.
	 *
	 * @return array{push_uuid:string,pairing_id:int,heartbeat:int,acquired:int}|null
	 */
	public static function get() {
		$lock = get_option( self::OPTION, null );
		if ( ! is_array( $lock ) || empty( $lock['push_uuid'] ) || ! is_string( $lock['push_uuid'] ) ) {
			return null;
		}
		return array(
			'push_uuid'  => (string) $lock['push_uuid'],
			'pairing_id' => isset( $lock['pairing_id'] ) ? absint( $lock['pairing_id'] ) : 0,
			'heartbeat'  => isset( $lock['heartbeat'] ) ? absint( $lock['heartbeat'] ) : 0,
			'acquired'   => isset( $lock['acquired'] ) ? absint( $lock['acquired'] ) : 0,
		);
	}

	/**
	 * Takes the lock for a session. Fails when anybody holds it (fresh or stale).
	 *
	 * @param string $push_uuid  Session UUID.
	 * @param int    $pairing_id Pairing row ID.
	 * @return bool Whether this call took it.
	 */
	public static function acquire( $push_uuid, $pairing_id ) {
		global $wpdb;

		$now   = time();
		$value = array(
			'push_uuid'  => (string) $push_uuid,
			'pairing_id' => absint( $pairing_id ),
			'heartbeat'  => $now,
			'acquired'   => $now,
		);

		// One statement decides who holds the lock: the row either did not exist and is inserted
		// (1 row affected) or exists and is left alone (0). The value is serialized the way core
		// stores option values, so get_option() reads it back as the same array.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic test-and-set of the lock row; core's add_option() checks and inserts in two steps. The option caches are cleared below.
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, self::OPTION, maybe_serialize( $value ), 'off' ) );

		self::flush_cache();

		return 1 === (int) $inserted;
	}

	/**
	 * Renews the heartbeat of the session that holds the lock.
	 *
	 * @param string $push_uuid Session UUID.
	 * @return bool Whether the session holds the lock.
	 */
	public static function heartbeat( $push_uuid ) {
		$lock = self::get();
		if ( null === $lock || ! hash_equals( $lock['push_uuid'], (string) $push_uuid ) ) {
			return false;
		}
		$lock['heartbeat'] = time();
		update_option( self::OPTION, $lock, false );
		return true;
	}

	/**
	 * Releases the lock if this session holds it.
	 *
	 * @param string $push_uuid Session UUID.
	 * @return bool Whether it was released.
	 */
	public static function release( $push_uuid ) {
		$lock = self::get();
		if ( null === $lock || ! hash_equals( $lock['push_uuid'], (string) $push_uuid ) ) {
			return false;
		}
		delete_option( self::OPTION );
		self::flush_cache();
		return true;
	}

	/**
	 * Removes a lock whose session is gone, closed or older than its 24 hours, whatever its
	 * heartbeat says (Push_Session decides that and calls this).
	 *
	 * @param string $push_uuid Session UUID the lock names.
	 * @return bool
	 */
	public static function clear_dead( $push_uuid ) {
		return self::release( $push_uuid );
	}

	/**
	 * Whether the lock is held with a heartbeat younger than 5 minutes.
	 *
	 * @param array|null $lock Lock (read when null).
	 * @return bool
	 */
	public static function is_fresh( $lock = null ) {
		$lock = null === $lock ? self::get() : $lock;
		if ( ! is_array( $lock ) ) {
			return false;
		}
		$age = time() - (int) $lock['heartbeat'];
		return $age >= 0 && $age < self::FRESH;
	}

	/**
	 * The lock's state for one session: 'fresh', 'stale' or 'none' (held by another or nobody).
	 *
	 * @param string $push_uuid Session UUID.
	 * @return string
	 */
	public static function state_for( $push_uuid ) {
		$lock = self::get();
		if ( null === $lock || ! hash_equals( $lock['push_uuid'], (string) $push_uuid ) ) {
			return 'none';
		}
		return self::is_fresh( $lock ) ? 'fresh' : 'stale';
	}

	/**
	 * Clears the object cache entries of the option after a direct write.
	 *
	 * @return void
	 */
	private static function flush_cache() {
		wp_cache_delete( self::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
