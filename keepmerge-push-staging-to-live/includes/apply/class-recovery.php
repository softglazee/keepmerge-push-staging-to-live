<?php
/**
 * Interrupted and failed pushes on live: detection, resume, retry, skip, lock release.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply;

use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Session;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blueprint §10.4 and §12: what live does when an apply stops.
 *
 * - Detection: a session in snapshotting or applying whose lock is not fresh (no heartbeat for 5
 *   minutes, or no lock at all) and whose step claim has passed is "interrupted". Closing the
 *   receive window or revoking the pairing interrupts an apply the source drives at once.
 * - Resume (interrupted): rows left "applying" by the step that died are checked: live's hash
 *   equals the intended post_hash → recorded as applied (never written twice); equals the pre_hash →
 *   processed again; anything else → failed_partial for the administrator's decision.
 * - Retry / Skip this item (failed_partial): the failed item is first put back to its snapshot (it
 *   may be half-written), then processed again, or marked skipped and the push goes on.
 * - Release lock: only a stale lock, or one whose session no longer runs.
 *
 * Every decision here is taken by a live administrator on live (admin routes, WP-CLI), except
 * that the source may resume an apply it was driving itself (R14, as the receiver).
 */
final class Recovery {

	const SIDE = 'destination';

	/**
	 * States in which a session holding the lock is doing work.
	 */
	const WORKING = array( 'open', 'receiving', 'snapshotting', 'applying', 'rolling_back' );

	/**
	 * Marks a running apply interrupted when its lock went stale (or away) and no step is running.
	 *
	 * @param array $session Session.
	 * @return array The session as it is now.
	 */
	public static function detect( array $session ) {
		if ( ! in_array( $session['state'], Applier::RUNNING, true ) ) {
			return $session;
		}
		$apply = isset( $session['cursor_json']['apply'] ) && is_array( $session['cursor_json']['apply'] ) ? $session['cursor_json']['apply'] : array();
		if ( isset( $apply['busy_until'] ) && (int) $apply['busy_until'] > time() ) {
			return $session;
		}
		if ( 'fresh' === Push_Lock::state_for( (string) $session['push_uuid'] ) ) {
			return $session;
		}
		$gone = null === Push_Lock::get();
		self::mark( $session, $gone ? 'lock_gone' : 'lock_stale' );
		$fresh = Push_Session::find( (string) $session['push_uuid'] );
		return null === $fresh ? $session : $fresh;
	}

	/**
	 * Interrupts the applies a source drives (the receive window closed, the pairing was revoked).
	 *
	 * @param string $reason     window_closed or pairing_revoked.
	 * @param int    $pairing_id Only this pairing's sessions (0: all).
	 * @return int Sessions interrupted.
	 */
	public static function interrupt_running( $reason, $pairing_id = 0 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$uuids = $wpdb->get_col( $wpdb->prepare( "SELECT push_uuid FROM %i WHERE side = %s AND state IN ('snapshotting','applying') AND ( 0 = %d OR pairing_id = %d )", Tables::table_name( 'pushes' ), self::SIDE, absint( $pairing_id ), absint( $pairing_id ) ) );
		$done  = 0;
		foreach ( is_array( $uuids ) ? $uuids : array() as $uuid ) {
			$session = Push_Session::find( (string) $uuid );
			if ( null === $session ) {
				continue;
			}
			$apply = Applier::progress( $session );
			if ( 'remote' !== $apply['actor'] ) {
				// A live administrator is finishing it from their own session: it does not need the
				// window or the pairing.
				continue;
			}
			self::mark( $session, $reason );
			++$done;
		}
		return $done;
	}

	/**
	 * Resumes an interrupted apply (blueprint §12.2).
	 *
	 * @param array  $session Session (interrupted).
	 * @param string $actor   'remote' (the source, through R14) or 'user'.
	 * @param int    $user_id User (for 'user').
	 * @return array|WP_Error The session.
	 */
	public static function resume( array $session, $actor, $user_id ) {
		if ( 'interrupted' !== $session['state'] ) {
			return new WP_Error( 'nothing_to_resume', __( 'This push is not interrupted.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		$apply = self::apply_cursor( $session );
		if ( 'remote' === $actor && 'remote' !== $apply['actor'] ) {
			return new WP_Error( 'push_applying', 'push_applying', array( 'status' => 409 ) );
		}
		if ( 'user' === $actor ) {
			$refusal = Applier::approval_rights( $session );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		$lock = self::take_lock( $session );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		$failed = null;
		foreach ( self::rows_with_status( $session, 'applying' ) as $row ) {
			$outcome = Applier::recover( $session, $row );
			if ( 'failed' === $outcome ) {
				$failed = $row;
				break;
			}
		}

		if ( null !== $failed ) {
			$apply['error']  = 'interrupted_item';
			$apply['failed'] = array(
				'row'  => (int) $failed['id'],
				'pass' => (string) $apply['pass'],
				'idx'  => isset( $apply['idx'] ) ? (int) $apply['idx'] : 0,
				'pos'  => isset( $apply['pos'] ) ? (int) $apply['pos'] : 0,
			);
			self::save( $session, $apply, 'failed_partial', 'interrupted_item' );
			Audit_Log::write(
				'push_failed',
				array(
					'result'      => 'error',
					'actor_type'  => 'remote' === $actor ? 'remote' : 'user',
					'actor_id'    => absint( $user_id ),
					'pairing_id'  => (int) $session['pairing_id'],
					'object_type' => 'push',
					'object_ref'  => (string) $session['push_uuid'],
					'details'     => array(
						'code' => 'interrupted_item',
						'item' => (string) $failed['uuid'],
						'kind' => (string) $failed['object_type'],
					),
				)
			);
			return Push_Session::find( (string) $session['push_uuid'] );
		}

		$apply['actor']      = 'remote' === $actor ? 'remote' : 'user';
		$apply['user']       = 'remote' === $actor ? 0 : absint( $user_id );
		$apply['busy_until'] = 0;
		$apply['error']      = '';
		self::save( $session, $apply, 'snapshot' === $apply['pass'] ? 'snapshotting' : 'applying', '' );
		self::audit_resumed( $session, $actor, $user_id, 'interrupted', '' );
		return Push_Session::find( (string) $session['push_uuid'] );
	}

	/**
	 * Retry (failed_partial): the failed item goes back to its snapshot if live differs from it,
	 * then the passes process it again.
	 *
	 * @param array $session Session.
	 * @param int   $user_id User.
	 * @return array|WP_Error The session.
	 */
	public static function retry( array $session, $user_id ) {
		$checked = self::failed_row( $session, '' );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		list( $apply, $row ) = $checked;

		$refusal = Applier::approval_rights( $session );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$lock = self::take_lock( $session );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		if ( in_array( $row['action'], array( 'update', 'trash', 'adopt' ), true ) && ! empty( $row['snapshot_gmt'] ) ) {
			$undone = Rollback_Runner::undo_row( $session, $row, true );
			if ( is_wp_error( $undone ) ) {
				return new WP_Error( 'undo_failed', __( 'The item could not be put back to its snapshot before the retry. Roll back the push instead.', 'keepmerge-push-staging-to-live' ), array( 'status' => 500 ) );
			}
		}
		Applier::reset_row( $row );

		$mark = isset( $apply['failed'] ) && is_array( $apply['failed'] ) ? $apply['failed'] : array();
		if ( isset( $mark['pass'] ) && $mark['pass'] === $apply['pass'] ) {
			$apply['idx'] = isset( $mark['idx'] ) ? (int) $mark['idx'] : (int) $apply['idx'];
			$apply['pos'] = isset( $mark['pos'] ) ? (int) $mark['pos'] : (int) $apply['pos'];
			if ( in_array( $apply['pass'], array( 'write', 'adopt' ), true ) ) {
				$apply['done'] = max( 0, (int) $apply['done'] - 1 );
			}
		}
		$apply['failed']     = null;
		$apply['error']      = '';
		$apply['actor']      = 'user';
		$apply['user']       = absint( $user_id );
		$apply['busy_until'] = 0;
		self::save( $session, $apply, 'snapshot' === $apply['pass'] ? 'snapshotting' : 'applying', '' );
		self::audit_resumed( $session, 'user', $user_id, 'retry', (string) $row['uuid'] );
		return Push_Session::find( (string) $session['push_uuid'] );
	}

	/**
	 * Skip this item and continue (failed_partial): the item is put back as it was before the push
	 * (its placeholder removed, its snapshot restored), marked skipped, and the passes go on.
	 *
	 * @param array  $session Session.
	 * @param string $uuid    The failed item's UUID (the screen names it).
	 * @param int    $user_id User.
	 * @return array|WP_Error The session.
	 */
	public static function skip_item( array $session, $uuid, $user_id ) {
		$checked = self::failed_row( $session, (string) $uuid );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}
		list( $apply, $row ) = $checked;

		$refusal = Applier::approval_rights( $session );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$lock = self::take_lock( $session );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$undone = Rollback_Runner::undo_row( $session, $row );
		if ( is_wp_error( $undone ) ) {
			return new WP_Error( 'undo_failed', __( 'The item could not be put back to its snapshot. Roll back the push instead.', 'keepmerge-push-staging-to-live' ), array( 'status' => 500 ) );
		}
		Applier::skip_row( $row, 'admin_skipped' );

		// The cursor goes on after the item.
		$mark = isset( $apply['failed'] ) && is_array( $apply['failed'] ) ? $apply['failed'] : array();
		if ( isset( $mark['pass'] ) && $mark['pass'] === $apply['pass'] ) {
			if ( in_array( $apply['pass'], array( 'allocate', 'write' ), true ) ) {
				$apply['idx'] = (int) $mark['idx'] + 1;
			} else {
				$apply['pos'] = (int) $row['id'];
			}
		}
		$apply['failed']     = null;
		$apply['error']      = '';
		$apply['actor']      = 'user';
		$apply['user']       = absint( $user_id );
		$apply['busy_until'] = 0;
		self::save( $session, $apply, 'snapshot' === $apply['pass'] ? 'snapshotting' : 'applying', '' );
		self::audit_resumed( $session, 'user', $user_id, 'skip_item', (string) $row['uuid'] );
		return Push_Session::find( (string) $session['push_uuid'] );
	}

	/**
	 * Releases the destination lock (threat model §7.7 "lock starvation"): only when it is stale or
	 * its session no longer runs. A holder that never wrote anything ends (aborted); one that was
	 * writing becomes interrupted; sessions waiting for a decision keep their state.
	 *
	 * @param int $user_id User.
	 * @return array|WP_Error
	 */
	public static function release_lock( $user_id ) {
		$lock = Push_Lock::get();
		if ( null === $lock ) {
			return new WP_Error( 'no_lock', __( 'No push holds this site\'s push lock.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		$holder = Push_Session::find( $lock['push_uuid'] );
		$fresh  = Push_Lock::is_fresh( $lock );
		if ( null !== $holder ) {
			$holder = self::detect( $holder );
		}
		if ( $fresh && null !== $holder && in_array( $holder['state'], self::WORKING, true ) ) {
			return new WP_Error( 'lock_in_use', __( 'A push is running on this site right now (its lock is fresh). Release the lock only when the push has stopped: a lock goes stale after 5 minutes without a heartbeat.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		Push_Lock::release( $lock['push_uuid'] );
		$state = null === $holder ? '' : (string) $holder['state'];
		if ( null !== $holder && in_array( $state, array( 'open', 'receiving' ), true ) ) {
			Push_Session::abort_local( $holder, 'lock_released', $user_id );
		} elseif ( null !== $holder && in_array( $state, Applier::RUNNING, true ) ) {
			self::mark( $holder, 'lock_released' );
		}

		Audit_Log::write(
			'lock_released',
			array(
				'actor_id'    => absint( $user_id ),
				'pairing_id'  => (int) $lock['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $lock['push_uuid'],
				'details'     => array(
					'state' => sanitize_key( $state ),
					'stale' => ! $fresh,
				),
			)
		);

		return array(
			'released' => true,
			'push'     => (string) $lock['push_uuid'],
			'state'    => null === $holder ? '' : (string) Push_Session::find( $lock['push_uuid'] )['state'],
		);
	}

	/**
	 * The lock as the live Pushes screen shows it.
	 *
	 * @return array|null
	 */
	public static function lock_view() {
		$lock = Push_Lock::get();
		if ( null === $lock ) {
			return null;
		}
		$holder = Push_Session::find( $lock['push_uuid'] );
		$fresh  = Push_Lock::is_fresh( $lock );
		return array(
			'push'          => (string) $lock['push_uuid'],
			'id'            => null === $holder ? 0 : (int) $holder['id'],
			'state'         => null === $holder ? '' : (string) $holder['state'],
			'heartbeat'     => (int) $lock['heartbeat'],
			'fresh'         => $fresh,
			'releasable'    => ! $fresh || null === $holder || ! in_array( $holder['state'], self::WORKING, true ),
			'stale_seconds' => Push_Lock::FRESH,
		);
	}

	// --- helpers ----------------------------------------------------------------------------------

	/**
	 * Marks a session interrupted and audits it.
	 *
	 * @param array  $session Session.
	 * @param string $reason  Reason code.
	 * @return void
	 */
	private static function mark( array $session, $reason ) {
		Push_Session::update(
			(int) $session['id'],
			array(
				'state'      => 'interrupted',
				'error_code' => sanitize_key( $reason ),
			)
		);
		$apply = self::apply_cursor( $session );
		Audit_Log::write(
			'push_interrupted',
			array(
				'actor_type'  => 'system',
				'pairing_id'  => (int) $session['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => array(
					'reason' => sanitize_key( $reason ),
					'pass'   => (string) $apply['pass'],
				),
			)
		);
	}

	/**
	 * The failed row of a failed_partial session (and, when a UUID is given, that it is this one).
	 *
	 * @param array  $session Session.
	 * @param string $uuid    UUID the caller names ('' for any).
	 * @return array|WP_Error array( apply cursor, row ).
	 */
	private static function failed_row( array $session, $uuid ) {
		if ( 'failed_partial' !== $session['state'] ) {
			return new WP_Error( 'not_failed', __( 'This push did not stop at a failed item.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		$apply = self::apply_cursor( $session );
		$mark  = isset( $apply['failed'] ) && is_array( $apply['failed'] ) ? $apply['failed'] : array();
		$row   = isset( $mark['row'] ) ? Rollback_Runner::row( (int) $mark['row'] ) : null;
		if ( null === $row ) {
			// A failure recorded before marks existed: the push's one failed row.
			$rows = self::rows_with_status( $session, 'failed' );
			$row  = 1 === count( $rows ) ? Rollback_Runner::row( (int) $rows[0]['id'] ) : null;
		}
		if ( null === $row || (int) $row['push_id'] !== (int) $session['id'] || 'failed' !== $row['status'] ) {
			return new WP_Error( 'failed_item_missing', __( 'The failed item of this push could not be found. Roll back the push instead.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		if ( '' !== $uuid && ! hash_equals( (string) $row['uuid'], $uuid ) ) {
			return new WP_Error( 'not_the_failed_item', __( 'Only the item the push stopped at can be skipped. Reload the screen.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}
		return array( $apply, $row );
	}

	/**
	 * Rows of a session with a status, light.
	 *
	 * @param array  $session Session.
	 * @param string $status  Status.
	 * @return array[]
	 */
	private static function rows_with_status( array $session, $status ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, uuid, object_type FROM %i WHERE push_id = %d AND side = %s AND status = %s AND object_type NOT IN ('file','file_path') ORDER BY id ASC LIMIT 50", Tables::table_name( 'push_items' ), absint( $session['id'] ), self::SIDE, (string) $status ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The lock for a session that continues: taken when free, kept when it is its own.
	 *
	 * @param array $session Session.
	 * @return true|WP_Error
	 */
	private static function take_lock( array $session ) {
		$lock = Push_Lock::get();
		if ( null === $lock ) {
			if ( Push_Lock::acquire( (string) $session['push_uuid'], (int) $session['pairing_id'] ) ) {
				return true;
			}
		} elseif ( hash_equals( (string) $lock['push_uuid'], (string) $session['push_uuid'] ) ) {
			Push_Lock::heartbeat( (string) $session['push_uuid'] );
			return true;
		}
		return new WP_Error( 'push_locked', __( 'Another push holds this site\'s push lock. Wait until it ends, or release its lock under Tools > Staging Push > Pushes if it has stopped.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
	}

	/**
	 * The apply cursor of a session with defaults.
	 *
	 * @param array $session Session.
	 * @return array
	 */
	private static function apply_cursor( array $session ) {
		$apply = isset( $session['cursor_json']['apply'] ) && is_array( $session['cursor_json']['apply'] ) ? $session['cursor_json']['apply'] : array();
		return array_merge(
			array(
				'pass'       => '',
				'pos'        => 0,
				'idx'        => 0,
				'actor'      => '',
				'user'       => 0,
				'done'       => 0,
				'busy_until' => 0,
				'error'      => '',
			),
			$apply
		);
	}

	/**
	 * Stores the apply cursor and the state.
	 *
	 * @param array  $session Session.
	 * @param array  $apply   Apply cursor.
	 * @param string $state   State.
	 * @param string $error   Error code.
	 * @return void
	 */
	private static function save( array $session, array $apply, $state, $error ) {
		$fresh           = Push_Session::find( (string) $session['push_uuid'] );
		$cursor          = is_array( $fresh ) ? $fresh['cursor_json'] : $session['cursor_json'];
		$cursor['apply'] = $apply;
		Push_Session::update(
			(int) $session['id'],
			array(
				'state'       => $state,
				'cursor_json' => $cursor,
				'error_code'  => $error,
			)
		);
	}

	/**
	 * Audits a resumed push.
	 *
	 * @param array  $session Session.
	 * @param string $actor   remote or user.
	 * @param int    $user_id User.
	 * @param string $reason  interrupted, retry or skip_item.
	 * @param string $item    Item UUID.
	 * @return void
	 */
	private static function audit_resumed( array $session, $actor, $user_id, $reason, $item ) {
		Audit_Log::write(
			'push_resumed',
			array(
				'actor_type'  => 'remote' === $actor ? 'remote' : 'user',
				'actor_id'    => 'remote' === $actor ? 0 : absint( $user_id ),
				'pairing_id'  => (int) $session['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => array(
					'reason' => $reason,
					'item'   => $item,
				),
			)
		);
	}
}
