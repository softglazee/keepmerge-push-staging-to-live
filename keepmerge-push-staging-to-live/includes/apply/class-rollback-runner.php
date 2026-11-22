<?php
/**
 * Rollback of a push on live.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply;

use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Limits;
use SoftGlaze\SafePush\Sync\Export_Context;
use SoftGlaze\SafePush\Sync\Items;
use SoftGlaze\SafePush\Sync\Sync_State;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Media_Receiver;
use SoftGlaze\SafePush\Transfer\Path_Guard;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Session;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rolls a push back from a live administrator's session; there is no remote rollback route.
 * Items go back in reverse apply order (to their snapshot, or removed when the push created them),
 * then replaced files are restored and added files deleted when unused. An item live changed after
 * the push is a conflict and is skipped unless the administrator ticked it. Steps are time-boxed
 * and every row is marked before and after it is touched, so a stopped rollback resumes without
 * restoring anything twice.
 */
final class Rollback_Runner {

	const SIDE = 'destination';

	/**
	 * States a rollback can start from (or continue in).
	 */
	const FROM_STATES = array( 'applied', 'failed_partial', 'interrupted', 'rolling_back' );

	/**
	 * Most rows one step handles; the softglaze_sp_rollback_step_items filter may only lower it.
	 */
	const STEP_ITEMS = 100;

	/**
	 * Row states a finished row carries in transfer.rb.
	 */
	const DONE = array( 'restored', 'removed', 'conflict', 'failed', 'unchanged', 'kept', 'gone' );

	/**
	 * Most conflicts and filtered items listed in a preview.
	 */
	const LIST_MAX = 500;

	// --- preview ----------------------------------------------------------------------------------

	/**
	 * What a rollback would do: counts of restores, removals and conflicts, and the items the
	 * current administrator's own filters would change.
	 *
	 * @param array $session Session.
	 * @return array
	 */
	public static function preview( array $session ) {
		$refusal = self::refusal( $session );
		$out     = array(
			'push'           => (string) $session['push_uuid'],
			'state'          => (string) $session['state'],
			'can'            => null === $refusal,
			'reason'         => null === $refusal ? '' : $refusal->get_error_code(),
			'message'        => null === $refusal ? '' : $refusal->get_error_message(),
			'host'           => self::host(),
			'restore'        => 0,
			'remove'         => 0,
			'files_restore'  => 0,
			'files_remove'   => 0,
			'conflict_count' => 0,
			'conflicts'      => array(),
			'filtered'       => array(),
			'done'           => 0,
			'progress'       => self::progress( $session ),
		);
		if ( 'rolled_back' === $session['state'] || ! empty( $session['cursor_json']['pruned'] ) ) {
			return $out;
		}

		$can_html = current_user_can( 'unfiltered_html' );
		$can_css  = current_user_can( 'edit_css' );
		$deadline = Limits::deadline( 3 );

		$out['complete'] = true;
		foreach ( self::item_rows( $session ) as $row ) {
			if ( in_array( self::rb( $row ), self::DONE, true ) ) {
				++$out['done'];
				continue;
			}
			$op = self::op( $row );
			if ( 'remove' === $op ) {
				++$out['remove'];
			} else {
				++$out['restore'];
			}
			if ( Limits::over( $deadline ) ) {
				// Counted, not checked: the rollback checks every item again when it reaches it.
				$out['complete'] = false;
				continue;
			}
			$conflict = self::conflict( $row );
			if ( '' !== $conflict ) {
				++$out['conflict_count'];
				if ( count( $out['conflicts'] ) < self::LIST_MAX ) {
					$out['conflicts'][] = self::describe( $row, $conflict );
				}
			}
			if ( 'restore' === $op && ( ! $can_html || ! $can_css ) && count( $out['filtered'] ) < self::LIST_MAX ) {
				$fields = self::filtered_fields( $row, $can_html, $can_css );
				if ( ! empty( $fields ) ) {
					$entry             = self::describe( $row, 'restore_filtered' );
					$entry['fields']   = $fields;
					$out['filtered'][] = $entry;
				}
			}
		}
		foreach ( self::file_rows( $session ) as $file ) {
			if ( in_array( self::rb( $file ), self::DONE, true ) ) {
				++$out['done'];
				continue;
			}
			if ( 'file_replaced' === $file['action'] ) {
				++$out['files_restore'];
			} else {
				++$out['files_remove'];
			}
			$conflict = self::file_conflict( $file );
			if ( '' !== $conflict ) {
				++$out['conflict_count'];
				if ( count( $out['conflicts'] ) < self::LIST_MAX ) {
					$out['conflicts'][] = array(
						'uuid'    => (string) $file['uuid'],
						'kind'    => 'file',
						'type'    => '',
						'title'   => (string) self::file_t( $file, 'final' ),
						'live_id' => 0,
						'reason'  => $conflict,
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Why a session cannot be rolled back now, or null.
	 *
	 * @param array $session Session.
	 * @return WP_Error|null
	 */
	public static function refusal( array $session ) {
		if ( 'rolled_back' === $session['state'] ) {
			return new WP_Error( 'already_rolled_back', __( 'This push was rolled back already. A rolled-back push cannot be rolled back again; push again from staging instead.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		if ( ! empty( $session['cursor_json']['pruned'] ) ) {
			return new WP_Error( 'snapshots_pruned', __( 'The snapshots of this push were removed by the retention setting, so it can no longer be rolled back.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		if ( ! in_array( $session['state'], self::FROM_STATES, true ) ) {
			return new WP_Error( 'nothing_to_roll_back', __( 'Nothing of this push was written on this site, so there is nothing to roll back.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		$apply = isset( $session['cursor_json']['apply'] ) && is_array( $session['cursor_json']['apply'] ) ? $session['cursor_json']['apply'] : array();
		if ( 'rolling_back' !== $session['state'] && isset( $apply['busy_until'] ) && (int) $apply['busy_until'] > time() ) {
			return new WP_Error( 'push_applying', __( 'A step of this push is running right now. Wait a moment and try again.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		return null;
	}

	/**
	 * Rollback progress from the session's cursor.
	 *
	 * @param array $session Session.
	 * @return array
	 */
	public static function progress( array $session ) {
		$rb = self::cursor( $session );
		return array(
			'pass'     => (string) $rb['pass'],
			'done'     => (int) $rb['done'],
			'total'    => (int) $rb['total'],
			'counts'   => (array) $rb['counts'],
			'started'  => (int) $rb['started'],
			'finished' => (int) $rb['finished'],
			'user'     => (int) $rb['user'],
		);
	}

	// --- run --------------------------------------------------------------------------------------

	/**
	 * One time-boxed step of a rollback; the first call starts it. The caller checked the typed domain.
	 *
	 * @param array    $session Session.
	 * @param string[] $ticked_uuids UUIDs of conflicts the administrator ticked (first call only).
	 * @param int      $user_id User.
	 * @param string   $actor   'user' or 'cli'.
	 * @return array|WP_Error The step's answer.
	 */
	public static function run( array $session, array $ticked_uuids, $user_id, $actor = 'user' ) {
		$refusal = self::refusal( $session );
		if ( null !== $refusal ) {
			return $refusal;
		}

		$lock = self::ensure_lock( $session );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		if ( 'rolling_back' !== $session['state'] ) {
			$started = self::start( $session, $ticked_uuids, $user_id, $actor );
			if ( is_wp_error( $started ) ) {
				return $started;
			}
			$session = $started;
		}

		// Claim the step.
		$raw = Push_Session::raw_cursor( (int) $session['id'] );
		$rb  = self::cursor( $session );
		$now = time();
		if ( (int) $rb['busy_until'] > $now ) {
			return new WP_Error( 'rollback_busy', __( 'A step of this rollback is running right now. Wait a moment.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		$budget           = Limits::time_budget();
		$rb['busy_until'] = $now + $budget + 15;
		$cursor           = $session['cursor_json'];
		$cursor['rb']     = $rb;
		if ( ! Push_Session::claim_cursor( (int) $session['id'], $raw, $cursor ) ) {
			return new WP_Error( 'rollback_busy', __( 'A step of this rollback is running right now. Wait a moment.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		wp_raise_memory_limit( 'admin' );
		$deadline = Limits::deadline( 2 );
		$cap      = (int) apply_filters( 'softglaze_sp_rollback_step_items', self::STEP_ITEMS );
		$cap      = max( 1, min( self::STEP_ITEMS, $cap ) );
		$handled  = 0;
		$state    = 'rolling_back';

		while ( $handled < $cap && ! Limits::over( $deadline ) ) {
			$outcome = self::next( $session, $rb );
			if ( 'done' === $outcome ) {
				$state = 'rolled_back';
				break;
			}
			if ( 'row' === $outcome ) {
				++$handled;
			}
		}

		Push_Lock::heartbeat( (string) $session['push_uuid'] );
		$rb['busy_until'] = 0;
		$fresh            = Push_Session::find( (string) $session['push_uuid'] );
		$cursor           = is_array( $fresh ) ? $fresh['cursor_json'] : $session['cursor_json'];
		$cursor['rb']     = $rb;
		$fields           = array(
			'state'       => $state,
			'cursor_json' => $cursor,
		);
		if ( 'rolled_back' === $state ) {
			$fields['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
			$fields['error_code']   = '';
		}
		Push_Session::update( (int) $session['id'], $fields );
		$session = Push_Session::find( (string) $session['push_uuid'] );

		return array(
			'push'     => (string) $session['push_uuid'],
			'state'    => (string) $session['state'],
			'rollback' => self::progress( $session ),
		);
	}

	/**
	 * Starts a rollback: checks the ticked UUIDs, fixes the row order and audits the start.
	 *
	 * @param array    $session Session.
	 * @param string[] $ticked_uuids Ticked conflict UUIDs.
	 * @param int      $user_id User.
	 * @param string   $actor   'user' or 'cli'.
	 * @return array|WP_Error The session.
	 */
	private static function start( array $session, array $ticked_uuids, $user_id, $actor ) {
		$rows    = self::item_rows( $session );
		$known   = array();
		$restore = 0;
		$remove  = 0;
		foreach ( $rows as $row ) {
			$known[ (string) $row['uuid'] ] = true;
			if ( 'remove' === self::op( $row ) ) {
				++$remove;
			} else {
				++$restore;
			}
		}
		$file_rows = self::file_rows( $session );
		foreach ( $file_rows as $file ) {
			$known[ (string) $file['uuid'] ] = true;
		}
		// A ticked UUID must name an item of this push; conflicts are checked again per item.
		$ticked = array();
		foreach ( $ticked_uuids as $uuid ) {
			$uuid = (string) $uuid;
			if ( ! isset( $known[ $uuid ] ) ) {
				return new WP_Error( 'invalid_include', __( 'Only items listed as conflicts in the preview can be ticked. Open the preview again.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
			}
			$ticked[ $uuid ] = true;
		}

		$order        = self::order( $session );
		$files        = array_map(
			static function ( $file ) {
				return (int) $file['id'];
			},
			$file_rows
		);
		$rb           = array(
			'pass'       => 'items',
			'order'      => $order,
			'idx'        => 0,
			'files'      => $files,
			'fidx'       => 0,
			'pos'        => 0,
			'include'    => array_keys( $ticked ),
			'user'       => absint( $user_id ),
			'actor'      => 'cli' === $actor ? 'cli' : 'user',
			'from'       => (string) $session['state'],
			'started'    => time(),
			'finished'   => 0,
			'busy_until' => 0,
			'done'       => 0,
			'total'      => count( $order ) + count( $files ),
			'counts'     => array(),
		);
		$cursor       = $session['cursor_json'];
		$cursor['rb'] = $rb;
		Push_Session::update(
			(int) $session['id'],
			array(
				'state'       => 'rolling_back',
				'cursor_json' => $cursor,
			)
		);

		Audit_Log::write(
			'rollback_started',
			array(
				'actor_type'  => 'cli' === $actor ? 'cli' : 'user',
				'actor_id'    => absint( $user_id ),
				'pairing_id'  => (int) $session['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => array(
					'from'     => (string) $session['state'],
					'restore'  => $restore,
					'remove'   => $remove,
					'files'    => count( $files ),
					'included' => count( $ticked ),
				),
			)
		);
		return Push_Session::find( (string) $session['push_uuid'] );
	}

	/**
	 * One unit of work.
	 *
	 * @param array $session Session.
	 * @param array $rb      Rollback cursor (changed).
	 * @return string row, idle or done.
	 */
	private static function next( array $session, array &$rb ) {
		switch ( $rb['pass'] ) {
			case 'items':
				if ( (int) $rb['idx'] >= count( $rb['order'] ) ) {
					$rb['pass'] = 'files';
					return 'idle';
				}
				$row = self::row( (int) $rb['order'][ (int) $rb['idx'] ] );
				++$rb['idx'];
				++$rb['done'];
				if ( null === $row ) {
					return 'idle';
				}
				self::count( $rb, self::item( $session, $row, (array) $rb['include'] ) );
				return 'row';

			case 'files':
				if ( (int) $rb['fidx'] >= count( $rb['files'] ) ) {
					$rb['pass'] = 'finish';
					$rb['pos']  = 0;
					return 'idle';
				}
				$file = self::row( (int) $rb['files'][ (int) $rb['fidx'] ] );
				++$rb['fidx'];
				++$rb['done'];
				if ( null === $file ) {
					return 'idle';
				}
				self::count( $rb, self::file( $session, $file, (array) $rb['include'] ) );
				return 'row';

			case 'finish':
				return self::finish( $session, $rb ) ? 'row' : 'done';
		}
		return 'done';
	}

	/**
	 * Rolls back one item row, only while live still holds what the push wrote, so a later edit on
	 * live is never rolled over unless the administrator ticked it.
	 *
	 * @param array    $session Session.
	 * @param array    $row     Row (decoded).
	 * @param string[] $ticked_uuids Ticked conflicts.
	 * @return string The row's rollback state.
	 */
	private static function item( array $session, array $row, array $ticked_uuids ) {
		$was = self::rb( $row );
		if ( in_array( $was, self::DONE, true ) ) {
			// Done by an earlier step: never twice.
			return 'skipped_again';
		}
		$op = self::op( $row );

		if ( 'restoring' !== $was ) {
			$conflict = self::conflict( $row );
			if ( '' !== $conflict && ! in_array( (string) $row['uuid'], $ticked_uuids, true ) ) {
				self::mark( $row, 'conflict', $conflict );
				return 'conflict';
			}
			if ( 'restore' === $op && 'applied' !== $row['status'] && self::unchanged( $row ) ) {
				// A failed or interrupted item that live still holds as its snapshot: nothing to do.
				self::mark( $row, 'unchanged', '' );
				return 'unchanged';
			}
		}

		self::mark( $row, 'restoring', '' );
		try {
			$result = 'remove' === $op ? Raw_Restore::remove( $row ) : Raw_Restore::restore( $session, $row );
		} catch ( \Throwable $e ) {
			$result = new WP_Error( 'restore_failed', 'restore_failed' );
		}
		if ( is_wp_error( $result ) ) {
			$code = substr( sanitize_key( (string) $result->get_error_code() ), 0, 60 );
			self::mark( $row, 'failed', '' === $code ? 'restore_failed' : $code );
			return 'failed';
		}

		if ( self::pairing_exists( (int) $session['pairing_id'] ) ) {
			$image = is_array( $row['pre_image'] ) ? $row['pre_image'] : array();
			Sync_State::restore( (int) $session['pairing_id'], (string) $row['uuid'], isset( $image['sync'] ) && is_array( $image['sync'] ) ? $image['sync'] : null );
		}

		$note = '';
		if ( 'restore' === $op && '' !== (string) $row['pre_hash'] ) {
			$now = self::current_hash( $row );
			if ( ! hash_equals( (string) $row['pre_hash'], $now ) ) {
				// Restored, but not exactly (for example the admin's own HTML filter changed it).
				$note = current_user_can( 'unfiltered_html' ) ? 'restore_inexact' : 'restore_filtered';
			}
		}
		$state = 'remove' === $op ? 'removed' : 'restored';
		self::mark( $row, $state, $note, true );
		return $state;
	}

	/**
	 * Rolls back one file row: restores or deletes, through WP_Filesystem, only a file the push
	 * itself replaced or added inside the uploads base.
	 *
	 * @param array    $session Session.
	 * @param array    $file    File row (decoded).
	 * @param string[] $ticked_uuids Ticked conflicts.
	 * @return string
	 */
	private static function file( array $session, array $file, array $ticked_uuids ) {
		$was = self::rb( $file );
		if ( in_array( $was, self::DONE, true ) ) {
			return 'skipped_again';
		}
		$final = (string) self::file_t( $file, 'final' );
		$base  = Media_Receiver::uploads_base();
		if ( is_wp_error( $base ) || '' !== Path_Guard::reason( $final ) ) {
			self::mark( $file, 'failed', 'path_refused' );
			return 'failed';
		}
		$full = $base . '/' . $final;

		if ( 'restoring' !== $was ) {
			$conflict = self::file_conflict( $file );
			if ( '' !== $conflict && ! in_array( (string) $file['uuid'], $ticked_uuids, true ) ) {
				self::mark( $file, 'conflict', $conflict );
				return 'conflict';
			}
		}

		if ( 'file_replaced' === $file['action'] ) {
			if ( is_file( $full ) && hash_equals( (string) $file['pre_hash'], (string) hash_file( 'sha256', $full ) ) ) {
				self::mark( $file, 'unchanged', '' );
				return 'unchanged';
			}
			self::mark( $file, 'restoring', '' );
			$done = Snapshot_Store::restore_file( $session, (string) $file['snapshot_file_rel'], $final, (string) $file['pre_hash'] );
			if ( is_wp_error( $done ) ) {
				self::mark( $file, 'failed', $done->get_error_code() );
				return 'failed';
			}
			self::mark( $file, 'restored', '', true );
			return 'restored';
		}

		// A file the push added.
		if ( ! is_file( $full ) || is_link( $full ) ) {
			self::mark( $file, 'gone', '' );
			return 'gone';
		}
		if ( self::referenced( $final ) ) {
			self::mark( $file, 'kept', 'file_in_use' );
			return 'kept';
		}
		self::mark( $file, 'restoring', '' );
		$fs = Media_Receiver::direct_filesystem();
		if ( is_wp_error( $fs ) || ! Media_Receiver::is_inside( dirname( $full ), $base ) || ! $fs->delete( $full ) ) {
			self::mark( $file, 'failed', 'storage_unwritable' );
			return 'failed';
		}
		self::mark( $file, 'removed', '', true );
		return 'removed';
	}

	/**
	 * Finish: payloads reduced in batches, then temporary folder, lock, audit.
	 *
	 * @param array $session Session.
	 * @param array $rb      Cursor (changed).
	 * @return bool Whether more work remains.
	 */
	private static function finish( array $session, array &$rb ) {
		global $wpdb;

		$table = Tables::table_name( 'push_items' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the next rows whose content is still stored.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, payload FROM %i WHERE push_id = %d AND side = %s AND id > %d AND object_type NOT IN ('file','file_path') ORDER BY id ASC LIMIT 100", $table, absint( $session['id'] ), self::SIDE, (int) $rb['pos'] ), ARRAY_A );
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$rb['pos'] = (int) $row['id'];
				$payload   = json_decode( (string) $row['payload'], true );
				$payload   = is_array( $payload ) ? $payload : array();
				if ( ! empty( $payload['cleared'] ) ) {
					continue;
				}
				$title = Review::title( (string) $row['object_type'], $payload );
				$kept  = array(
					'v'       => 1,
					'type'    => isset( $payload['type'] ) ? (string) $payload['type'] : '',
					'title'   => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 100 ) : substr( $title, 0, 100 ),
					'cleared' => true,
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
				$wpdb->update( $table, array( 'payload' => (string) wp_json_encode( $kept ) ), array( 'id' => (int) $row['id'] ) );
			}
			return true;
		}

		Media_Receiver::delete_temp( $session );
		Push_Lock::release( (string) $session['push_uuid'] );
		$rb['finished'] = time();
		$rb['pass']     = 'done';

		Audit_Log::write(
			'rollback_done',
			array(
				'actor_type'  => 'cli' === $rb['actor'] ? 'cli' : 'user',
				'actor_id'    => (int) $rb['user'],
				'pairing_id'  => (int) $session['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => array_map( 'intval', (array) $rb['counts'] ),
			)
		);
		return false;
	}

	// --- shared with Retry and Skip ---------------------------------------------------------------

	/**
	 * Puts one item back as it was before the push, for Skip and Retry: a placeholder is removed, an
	 * item that differs from its snapshot is restored. The row's status is left to the caller.
	 *
	 * @param array $session Session.
	 * @param array $row     Row (decoded, with pre_image).
	 * @param bool  $keep_placeholder Keep a created placeholder (Retry writes over it).
	 * @return string|WP_Error removed, restored or unchanged.
	 */
	public static function undo_row( array $session, array $row, $keep_placeholder = false ) {
		$op = self::op( $row );
		try {
			if ( 'remove' === $op ) {
				if ( $keep_placeholder && ! in_array( (string) $row['object_type'], array( 'option', 'widgets' ), true ) ) {
					return 'unchanged';
				}
				if ( (int) $row['local_object_id'] < 1 && ! in_array( (string) $row['object_type'], array( 'option', 'widgets' ), true ) ) {
					return 'unchanged';
				}
				$done = Raw_Restore::remove( $row );
				return is_wp_error( $done ) ? $done : 'removed';
			}
			if ( empty( $row['snapshot_gmt'] ) || self::unchanged( $row ) ) {
				return 'unchanged';
			}
			$done = Raw_Restore::restore( $session, $row );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'restore_failed', 'restore_failed' );
		}
		return is_wp_error( $done ) ? $done : 'restored';
	}

	/**
	 * A row by ID with its JSON columns decoded (for Recovery).
	 *
	 * Direct query: core has no API for plugin tables, and a cache could be stale during a push.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function row( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND side = %s', Tables::table_name( 'push_items' ), absint( $id ), self::SIDE ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		foreach ( array( 'payload', 'refs', 'pre_image', 'transfer' ) as $column ) {
			$value          = isset( $row[ $column ] ) ? json_decode( (string) $row[ $column ], true ) : null;
			$row[ $column ] = is_array( $value ) ? $value : array();
		}
		return $row;
	}

	/**
	 * Live's current hash of a row's object ('' when it is not an item here).
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public static function current_hash( array $row ) {
		$kind = (string) $row['object_type'];
		$item = Items::export( $kind, Snapshot_Store::ref( $kind, $row ), Export_Context::destination() );
		return null === $item ? '' : (string) $item['hash'];
	}

	// --- rows -------------------------------------------------------------------------------------

	/**
	 * Item rows a rollback handles: rows the apply wrote or started to write (they have a
	 * snapshot), and every placeholder the push created.
	 *
	 * Direct query: core has no API for plugin tables, and a cache could be stale during a push.
	 *
	 * @param array $session Session.
	 * @return array[] Light rows (no pre-image).
	 */
	private static function item_rows( array $session ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the push's written rows without their content.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, uuid, object_type, action, status, local_object_id, pre_hash, post_hash, refs, transfer, snapshot_gmt FROM %i WHERE push_id = %d AND side = %s AND object_type NOT IN ('file','file_path') AND ( ( status IN ('applied','failed','applying') AND snapshot_gmt IS NOT NULL ) OR ( action = 'created' AND local_object_id > 0 ) ) ORDER BY id ASC", Tables::table_name( 'push_items' ), absint( $session['id'] ), self::SIDE ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			foreach ( array( 'refs', 'transfer' ) as $column ) {
				$value          = json_decode( (string) $row[ $column ], true );
				$row[ $column ] = is_array( $value ) ? $value : array();
			}
			$row['pre_image'] = array();
			$out[]            = $row;
		}
		return $out;
	}

	/**
	 * File rows a rollback handles: files the push replaced or added.
	 *
	 * Direct query: core has no API for plugin tables, and a cache could be stale during a push.
	 *
	 * @param array $session Session.
	 * @return array[]
	 */
	private static function file_rows( array $session ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the push's moved files.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, uuid, object_type, action, status, pre_hash, post_hash, transfer, snapshot_file_rel FROM %i WHERE push_id = %d AND side = %s AND object_type = 'file' AND action IN ('file_replaced','file_added') ORDER BY id DESC", Tables::table_name( 'push_items' ), absint( $session['id'] ), self::SIDE ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$value           = json_decode( (string) $row['transfer'], true );
			$row['transfer'] = is_array( $value ) ? $value : array();
			$out[]           = $row;
		}
		return $out;
	}

	/**
	 * Row IDs in rollback order: the apply's write order reversed, adopted rows first, and removals
	 * before restores within a rank, so a created page frees its slug before the old page gets it back.
	 *
	 * @param array $session Session.
	 * @return int[]
	 */
	private static function order( array $session ) {
		$rows   = self::item_rows( $session );
		$parent = array();
		$kinds  = array();
		foreach ( $rows as $row ) {
			$kind = (string) $row['object_type'];
			foreach ( $row['refs'] as $ref ) {
				if ( ! is_array( $ref ) || ! isset( $ref['path'], $ref['uuid'], $ref['res'] ) ) {
					continue;
				}
				if ( 'product' === $kind && 'groups/content/parent' === $ref['path'] ) {
					$kind = 'variation';
				}
				if ( in_array( $ref['path'], array( 'parent', 'post_parent', 'groups/content/parent' ), true ) && 'push' === $ref['res'] ) {
					$parent[ $row['uuid'] ] = (string) $ref['uuid'];
				}
			}
			$kinds[ $row['uuid'] ] = $kind;
		}
		$sort = array();
		foreach ( $rows as $row ) {
			$depth = 0;
			$at    = $row['uuid'];
			while ( isset( $parent[ $at ] ) && $depth < 50 ) {
				$at = $parent[ $at ];
				++$depth;
			}
			$kind    = $kinds[ $row['uuid'] ];
			$rank    = in_array( $row['action'], array( 'adopt', 'adopted' ), true ) ? 100 : ( isset( Applier::WRITE_RANK[ $kind ] ) ? Applier::WRITE_RANK[ $kind ] : 10 );
			$removal = 'remove' === self::op( $row );
			$sort[]  = array( $rank, $depth, $removal ? 1 : 0, (int) $row['id'] );
		}
		usort(
			$sort,
			static function ( $a, $b ) {
				foreach ( array( 0, 1, 2, 3 ) as $i ) {
					if ( $a[ $i ] !== $b[ $i ] ) {
						return $a[ $i ] > $b[ $i ] ? -1 : 1;
					}
				}
				return 0;
			}
		);
		return array_map(
			static function ( $entry ) {
				return (int) $entry[3];
			},
			$sort
		);
	}

	/**
	 * What the rollback does with a row: restore its snapshot or remove what the push created.
	 *
	 * @param array $row Row.
	 * @return string restore or remove.
	 */
	private static function op( array $row ) {
		if ( in_array( $row['action'], array( 'created', 'option_added' ), true ) ) {
			return 'remove';
		}
		return 'restore';
	}

	/**
	 * Why a row is a conflict ('' when it is not): an applied item live changed after the push.
	 *
	 * Hashes are compared in constant time with hash_equals().
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private static function conflict( array $row ) {
		if ( 'applied' !== $row['status'] ) {
			return '';
		}
		$now = self::current_hash( $row );
		if ( ! hash_equals( (string) $row['post_hash'], $now ) ) {
			return 'edited_on_live';
		}
		if ( 'product' === $row['object_type'] && 'created' === $row['action'] && isset( $row['transfer']['post_groups']['live_owned'] ) ) {
			// A product the push created that was ordered since: its stock or sales moved.
			$item = Items::export( 'product', (int) $row['local_object_id'], Export_Context::destination() );
			$live = is_array( $item ) && isset( $item['groups']['live_owned'] ) ? (string) $item['groups']['live_owned'] : '';
			if ( ! hash_equals( (string) $row['transfer']['post_groups']['live_owned'], $live ) ) {
				return 'ordered_since_push';
			}
		}
		return '';
	}

	/**
	 * Why a file row is a conflict: the file the push wrote inside the uploads base has changed.
	 *
	 * @param array $file File row.
	 * @return string
	 */
	private static function file_conflict( array $file ) {
		$base = Media_Receiver::uploads_base();
		if ( is_wp_error( $base ) ) {
			return '';
		}
		$full = $base . '/' . self::file_t( $file, 'final' );
		if ( ! is_file( $full ) ) {
			return 'file_replaced' === $file['action'] ? 'file_changed' : '';
		}
		$now = (string) hash_file( 'sha256', $full );
		if ( hash_equals( (string) $file['post_hash'], $now ) || ( 'file_replaced' === $file['action'] && hash_equals( (string) $file['pre_hash'], $now ) ) ) {
			return '';
		}
		return 'file_changed';
	}

	/**
	 * Whether live still holds an item exactly as its snapshot recorded it.
	 *
	 * Hashes are compared in constant time with hash_equals().
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	private static function unchanged( array $row ) {
		if ( '' === (string) $row['pre_hash'] ) {
			return false;
		}
		return hash_equals( (string) $row['pre_hash'], self::current_hash( $row ) );
	}

	/**
	 * Fields of a pre-image the current administrator's own filters would change on restore.
	 *
	 * @param array $row      Row (light).
	 * @param bool  $can_html Whether the user has unfiltered_html.
	 * @param bool  $can_css  Whether the user has edit_css.
	 * @return string[]
	 */
	private static function filtered_fields( array $row, $can_html, $can_css ) {
		$full = self::row( (int) $row['id'] );
		$raw  = null === $full || ! isset( $full['pre_image']['raw'] ) || ! is_array( $full['pre_image']['raw'] ) ? array() : $full['pre_image']['raw'];
		$out  = array();
		if ( isset( $raw['post'] ) && is_array( $raw['post'] ) ) {
			$post = Raw_Restore::plain( $raw['post'] );
			if ( 'wp_global_styles' === ( isset( $post['post_type'] ) ? $post['post_type'] : '' ) ) {
				if ( ! $can_css && ! empty( Markup_Gate::global_styles_reasons( isset( $post['post_content'] ) ? (string) $post['post_content'] : '' ) ) ) {
					$out[] = 'post_content';
				}
				return $out;
			}
			if ( $can_html ) {
				return $out;
			}
			foreach ( array(
				'post_title'   => 'title_save_pre',
				'post_content' => 'post',
				'post_excerpt' => 'post',
			) as $field => $context ) {
				if ( isset( $post[ $field ] ) && null !== Markup_Gate::kses_change( (string) $post[ $field ], $context ) ) {
					$out[] = $field;
				}
			}
		} elseif ( ! $can_html && isset( $raw['term'] ) && is_array( $raw['term'] ) ) {
			$term = Raw_Restore::plain( $raw['term'] );
			if ( isset( $term['description'] ) && null !== Markup_Gate::kses_change( (string) $term['description'], 'pre_term_description' ) ) {
				$out[] = 'description';
			}
		}
		return $out;
	}

	/**
	 * One row as the preview lists it.
	 *
	 * @param array  $row    Row.
	 * @param string $reason Reason code.
	 * @return array
	 */
	private static function describe( array $row, $reason ) {
		$full    = self::row( (int) $row['id'] );
		$payload = null === $full ? array() : $full['payload'];
		$title   = Review::title( (string) $row['object_type'], $payload );
		return array(
			'uuid'    => (string) $row['uuid'],
			'kind'    => (string) $row['object_type'],
			'type'    => isset( $payload['type'] ) ? (string) $payload['type'] : '',
			'title'   => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 100 ) : substr( $title, 0, 100 ),
			'live_id' => (int) $row['local_object_id'],
			'reason'  => (string) $reason,
		);
	}

	/**
	 * Whether a file (relative to the uploads base) is still used by an attachment on live, checked
	 * before a rollback deletes it.
	 *
	 * @param string $rel Relative path.
	 * @return bool
	 */
	private static function referenced( $rel ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One lookup per file the push added, before deleting it.
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1", (string) $rel ) ) ) {
			return true;
		}
		$like = '%' . $wpdb->esc_like( wp_basename( (string) $rel ) ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Candidates whose metadata names the file; each is checked below.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s LIMIT 20", $like ) );
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$metadata = wp_get_attachment_metadata( (int) $id );
			$paths    = Path_Guard::attachment_paths( (string) get_post_meta( (int) $id, '_wp_attached_file', true ), is_array( $metadata ) ? $metadata : array() );
			if ( in_array( (string) $rel, $paths, true ) ) {
				return true;
			}
		}
		return false;
	}

	// --- small helpers ----------------------------------------------------------------------------

	/**
	 * The rollback state a row carries ('' when the rollback has not reached it).
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private static function rb( array $row ) {
		return isset( $row['transfer']['rb']['s'] ) ? (string) $row['transfer']['rb']['s'] : '';
	}

	/**
	 * A value of a file row's transfer record.
	 *
	 * @param array  $file File row.
	 * @param string $name Key.
	 * @return mixed
	 */
	private static function file_t( array $file, $name ) {
		return isset( $file['transfer'][ $name ] ) ? $file['transfer'][ $name ] : '';
	}

	/**
	 * Records a row's rollback state (and restored_gmt once something was put back).
	 *
	 * Direct write: core has no API for plugin tables.
	 *
	 * @param array  $row      Row.
	 * @param string $state    State.
	 * @param string $reason   Reason code.
	 * @param bool   $restored Whether to set restored_gmt.
	 * @return void
	 */
	private static function mark( array $row, $state, $reason, $restored = false ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the transfer record as stored now.
		$stored   = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT transfer FROM %i WHERE id = %d', Tables::table_name( 'push_items' ), absint( $row['id'] ) ) );
		$transfer = json_decode( $stored, true );
		$transfer = is_array( $transfer ) ? $transfer : array();

		$transfer['rb'] = array(
			's' => (string) $state,
			'r' => (string) $reason,
		);
		$data           = array( 'transfer' => (string) wp_json_encode( $transfer ) );
		if ( $restored ) {
			$data['restored_gmt'] = gmdate( 'Y-m-d H:i:s' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Tables::table_name( 'push_items' ), $data, array( 'id' => absint( $row['id'] ) ) );
	}

	/**
	 * Adds one to a count of the cursor.
	 *
	 * @param array  $rb    Cursor (changed).
	 * @param string $state Row state.
	 * @return void
	 */
	private static function count( array &$rb, $state ) {
		if ( 'skipped_again' === $state ) {
			return;
		}
		$rb['counts'][ $state ] = ( isset( $rb['counts'][ $state ] ) ? (int) $rb['counts'][ $state ] : 0 ) + 1;
	}

	/**
	 * The rollback part of a session's cursor, with defaults.
	 *
	 * @param array $session Session.
	 * @return array
	 */
	private static function cursor( array $session ) {
		$rb = isset( $session['cursor_json']['rb'] ) && is_array( $session['cursor_json']['rb'] ) ? $session['cursor_json']['rb'] : array();
		return array_merge(
			array(
				'pass'       => '',
				'order'      => array(),
				'idx'        => 0,
				'files'      => array(),
				'fidx'       => 0,
				'pos'        => 0,
				'include'    => array(),
				'user'       => 0,
				'actor'      => 'user',
				'from'       => '',
				'started'    => 0,
				'finished'   => 0,
				'busy_until' => 0,
				'done'       => 0,
				'total'      => 0,
				'counts'     => array(),
			),
			$rb
		);
	}

	/**
	 * The destination lock for the rollback: taken when nobody holds it; another push's lock stops
	 * the rollback (release it first if it is stale).
	 *
	 * Hashes are compared in constant time with hash_equals().
	 *
	 * @param array $session Session.
	 * @return true|WP_Error
	 */
	private static function ensure_lock( array $session ) {
		$lock = Push_Lock::get();
		if ( null === $lock ) {
			if ( Push_Lock::acquire( (string) $session['push_uuid'], (int) $session['pairing_id'] ) ) {
				return true;
			}
		} elseif ( hash_equals( (string) $lock['push_uuid'], (string) $session['push_uuid'] ) ) {
			return true;
		}
		return new WP_Error( 'push_locked', __( 'Another push holds this site\'s push lock. Wait until it ends, or release its lock under Tools > Staging Push > Pushes if it has stopped.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
	}

	/**
	 * Whether the pairing still exists and is not revoked (its sync_state rows are restored).
	 *
	 * Direct query: core has no API for plugin tables, and a cache could be stale during a push.
	 *
	 * @param int $pairing_id Pairing row ID.
	 * @return bool
	 */
	private static function pairing_exists( $pairing_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; one non-secret column.
		$status = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE id = %d', Tables::table_name( 'pairings' ), absint( $pairing_id ) ) );
		return '' !== $status && 'revoked' !== $status;
	}

	/**
	 * This site's host, the typed confirmation.
	 *
	 * @return string
	 */
	public static function host() {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
}
