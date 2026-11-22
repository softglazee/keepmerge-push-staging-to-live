<?php
/**
 * Push sessions on the destination.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Transfer;

use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Limits;
use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Tables;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The destination half of blueprint §10.1–§10.2 and §12: a session per push (pushes row, side
 * "destination"), its lock, its staged items (push_items rows, side "destination") and the end
 * of its life (abort, expiry). Nothing here touches live's content: the rows are staged and
 * wait for the apply of Phase 7.
 *
 * States: open → receiving → staged → [awaiting_review] → snapshotting → applying → applied
 * (Apply\Applier); aborted (by its source, before apply), expired (24 hours without apply: rows
 * purged), failed (the final pass found the items not matching the plan), failed_partial (an item
 * failed during the apply: retry, skip or roll back), interrupted (the apply stopped without a
 * heartbeat, or its window closed or pairing was revoked: resume or roll back; Apply\Recovery),
 * rolling_back → rolled_back (Apply\Rollback_Runner).
 *
 * Idempotency (blueprint §12.2): a batch is identified by (push, seq) and must name the same
 * UUIDs when sent again; an item by (push, uuid), the table's unique key. A batch sent again
 * returns the stored results and writes nothing.
 */
final class Push_Session {

	const SIDE = 'destination';

	/**
	 * States in which a session still receives or waits for apply.
	 */
	const LIVE_STATES = array( 'open', 'receiving', 'staged' );

	/**
	 * States in which a session holds the destination lock (blueprint §10.1): receiving, waiting
	 * for review or apply, or being applied.
	 */
	const LOCK_STATES = array( 'open', 'receiving', 'staged', 'awaiting_review', 'snapshotting', 'applying', 'failed_partial', 'interrupted', 'rolling_back' );

	/**
	 * Final states: nothing more happens to such a session (retention may prune its snapshots).
	 */
	const FINAL_STATES = array( 'applied', 'rolled_back', 'aborted', 'expired', 'failed' );

	/**
	 * States in which nothing was written yet and the session may still expire or be aborted.
	 */
	const UNAPPLIED_STATES = array( 'open', 'receiving', 'staged', 'awaiting_review', 'failed' );

	/**
	 * Row actions and the counts a session declares for them.
	 */
	const ACTIONS = array( 'create', 'update', 'recreate', 'trash', 'adopt' );

	/**
	 * Most items a push carries (blueprint §13).
	 */
	const MAX_ITEMS = 5000;

	/**
	 * Most items per batch (threat model R13).
	 */
	const MAX_BATCH = 50;

	/**
	 * Largest item batch body, bytes (threat model R13).
	 */
	const MAX_BATCH_BYTES = 2097152;

	/**
	 * Largest session-open body, bytes (threat model R8).
	 */
	const MAX_OPEN_BYTES = 65536;

	/**
	 * Seconds a session may wait for its apply (blueprint §10.1).
	 */
	const TTL = DAY_IN_SECONDS;

	const STATUSES = array( 'staged', 'held', 'pending_media', 'refused' );

	/**
	 * Row statuses an apply gives (Apply\Applier).
	 */
	const APPLY_STATUSES = array( 'applying', 'applied', 'skipped', 'failed' );

	/**
	 * POST /remote/push: opens (or, for the same push sent again, returns) a session.
	 *
	 * @param array $pairing Pairing row (active, passed the chain).
	 * @param array $body    Decoded body.
	 * @return array|WP_Error The session as presented.
	 */
	public static function open( array $pairing, array $body ) {
		$fields = self::open_fields( $body );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		$existing = self::find( $fields['push'] );
		if ( null !== $existing ) {
			if ( (int) $existing['pairing_id'] !== (int) $pairing['id'] || ! hash_equals( (string) $existing['plan_hash'], $fields['plan_hash'] ) || (int) $existing['counts']['items'] !== $fields['items'] ) {
				return self::error( 'push_exists', 409 );
			}
			if ( self::expire_if_due( $existing ) ) {
				return self::error( 'push_expired', 410 );
			}
			if ( ! in_array( $existing['state'], self::LIVE_STATES, true ) ) {
				return self::error( 'push_' . $existing['state'], 409 );
			}
			// The answer to an earlier open was lost: the same session, its lock renewed.
			Push_Lock::heartbeat( $existing['push_uuid'] );
			return self::present( self::find( $fields['push'] ) );
		}

		if ( self::uuid_taken( $fields['push'] ) ) {
			// The ID belongs to a push this site sends itself (one table holds both sides).
			return self::error( 'push_exists', 409 );
		}

		// The media must fit before anything is taken (S-64): the per-push cap and 80% of the free
		// disk space when the host reports it.
		$storage = Media_Receiver::storage_reason( $fields['file_bytes'] );
		if ( '' !== $storage ) {
			self::audit(
				'push_opened',
				$pairing,
				$fields['push'],
				array(
					'reason' => 'storage_full',
					'cause'  => $storage,
				),
				'denied'
			);
			return self::error( 'storage_full', 400 );
		}

		$lock = Push_Lock::get();
		if ( null !== $lock ) {
			$holder = self::find( $lock['push_uuid'] );
			if ( null !== $holder && self::expire_if_due( $holder ) ) {
				$holder = null;
			}
			if ( null === $holder || ! in_array( $holder['state'], self::LOCK_STATES, true ) ) {
				// The lock names a session that no longer runs: it protects nothing.
				Push_Lock::clear_dead( $lock['push_uuid'] );
			} else {
				self::audit( 'push_opened', $pairing, $fields['push'], array( 'reason' => 'push_locked' ), 'denied' );
				return self::error( 'push_locked', 409 );
			}
		}

		if ( ! Push_Lock::acquire( $fields['push'], (int) $pairing['id'] ) ) {
			self::audit( 'push_opened', $pairing, $fields['push'], array( 'reason' => 'push_locked' ), 'denied' );
			return self::error( 'push_locked', 409 );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table; one insert.
		$ok = $wpdb->insert(
			Tables::table_name( 'pushes' ),
			array(
				'push_uuid'    => $fields['push'],
				'pairing_id'   => (int) $pairing['id'],
				'side'         => self::SIDE,
				'state'        => 'open',
				'plan_hash'    => $fields['plan_hash'],
				'counts'       => wp_json_encode(
					array(
						'declared'   => $fields['counts'],
						'items'      => $fields['items'],
						'files'      => $fields['files'],
						'file_bytes' => $fields['file_bytes'],
					)
				),
				'cursor_json'  => wp_json_encode( self::empty_cursor() ),
				'chunk_size'   => 0,
				'started_gmt'  => gmdate( 'Y-m-d H:i:s' ),
				'initiated_by' => 0,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' )
		);
		if ( ! $ok ) {
			Push_Lock::release( $fields['push'] );
			return self::error( 'push_exists', 409 );
		}

		self::audit(
			'push_opened',
			$pairing,
			$fields['push'],
			array_merge(
				$fields['counts'],
				array(
					'items' => $fields['items'],
					'files' => $fields['files'],
				)
			)
		);

		return self::present( self::find( $fields['push'] ) );
	}

	/**
	 * The session of a pairing named in a route, after its expiry check.
	 *
	 * @param string $push_uuid Session UUID.
	 * @param array  $pairing   Pairing row.
	 * @return array|WP_Error
	 */
	public static function for_pairing( $push_uuid, array $pairing ) {
		$session = self::find( strtolower( (string) $push_uuid ) );
		if ( null === $session || (int) $session['pairing_id'] !== (int) $pairing['id'] ) {
			// Another pairing's session does not exist for this one.
			return self::error( 'push_not_found', 404 );
		}
		self::expire_if_due( $session );
		$session = self::find( $session['push_uuid'] );
		return null === $session ? self::error( 'push_not_found', 404 ) : \SoftGlaze\SafePush\Apply\Recovery::detect( $session );
	}

	/**
	 * GET /remote/push/{id}: the session's state and progress. Read-only: a status call is not
	 * progress, so it does not renew the lock's heartbeat (only open and item batches do).
	 *
	 * @param array $session Session.
	 * @return array
	 */
	public static function status( array $session ) {
		return self::present( $session );
	}

	/**
	 * POST /remote/push/{id}/items: validates and stages one batch.
	 *
	 * @param array $session Session.
	 * @param array $pairing Pairing row.
	 * @param array $body    Decoded body.
	 * @return array|WP_Error
	 */
	public static function receive( array $session, array $pairing, array $body ) {
		if ( ! in_array( $session['state'], self::LIVE_STATES, true ) ) {
			return 'expired' === $session['state'] ? self::error( 'push_expired', 410 ) : self::error( 'push_' . $session['state'], 409 );
		}
		$lock = Push_Lock::get();
		if ( null === $lock || ! hash_equals( $lock['push_uuid'], (string) $session['push_uuid'] ) ) {
			// Only the session holding the lock receives items (a released lock is not taken back).
			return self::error( null === $lock ? 'lock_lost' : 'push_locked', 409 );
		}

		if ( ! isset( $body['seq'], $body['items'] ) || ! is_int( $body['seq'] ) || $body['seq'] < 1 || ! is_array( $body['items'] ) || ! wp_is_numeric_array( $body['items'] ) || array() === $body['items'] ) {
			return self::error( 'invalid_body', 400 );
		}
		if ( count( $body['items'] ) > self::MAX_BATCH ) {
			return self::error( 'too_many_items', 400 );
		}

		$identities = array();
		foreach ( array_values( $body['items'] ) as $raw ) {
			$identity = Item_Stager::identity( $raw );
			if ( null === $identity || isset( $identities[ $identity['uuid'] ] ) ) {
				return self::error( 'invalid_body', 400 );
			}
			$identities[ $identity['uuid'] ] = $identity;
		}

		$seq    = $body['seq'];
		$cursor = $session['cursor_json'];
		$last   = (int) $cursor['last_seq'];
		$sha    = substr( hash( 'sha256', implode( "\n", array_keys( $identities ) ) ), 0, 32 );

		if ( $seq > $last + 1 ) {
			return self::error( 'batch_out_of_order', 409 );
		}
		if ( $seq <= $last ) {
			if ( ! isset( $cursor['batches'][ (string) $seq ] ) || ! hash_equals( (string) $cursor['batches'][ (string) $seq ], $sha ) ) {
				return self::error( 'batch_mismatch', 409 );
			}
			// A batch sent again (its answer was lost): the stored results, nothing written.
			Push_Lock::heartbeat( $session['push_uuid'] );
			$rows    = self::rows_for( (int) $session['id'], array_keys( $identities ) );
			$results = array();
			foreach ( array_keys( $identities ) as $uuid ) {
				$results[] = isset( $rows[ $uuid ] ) ? self::row_result( $rows[ $uuid ], true ) : array(
					'uuid'   => $uuid,
					'status' => 'missing',
				);
			}
			return self::batch_answer( self::find( $session['push_uuid'] ), $seq, $results, true );
		}

		if ( 'staged' === $session['state'] ) {
			return self::error( 'item_count_exceeded', 409 );
		}

		$existing = self::rows_for( (int) $session['id'], array_keys( $identities ) );
		$received = self::received( (int) $session['id'] );
		$new      = count( $identities ) - count( $existing );
		if ( $received + $new > (int) $session['counts']['items'] ) {
			return self::error( 'item_count_exceeded', 409 );
		}

		// Files come before items (blueprint §12.1): the first batch ends the files phase.
		Media_Receiver::close_files( $session );

		$push_id  = (int) $session['id'];
		$stager   = new Item_Stager(
			$pairing,
			self::known( $push_id ),
			static function ( array $refs ) use ( $push_id ) {
				return Media_Receiver::rows( $push_id, $refs );
			}
		);
		$results  = array();
		$batch    = array_fill_keys( self::STATUSES, 0 );
		$deadline = Limits::deadline( 3 );
		$position = 0;
		foreach ( array_values( $body['items'] ) as $raw ) {
			if ( $position++ > 0 && Limits::over( $deadline ) ) {
				// Out of time or memory (blueprint §13): the items staged so far stay, the batch keeps
				// its number, and the source sends the same batch again; items already stored are
				// answered from their rows (idempotent by push and UUID).
				Push_Lock::heartbeat( $session['push_uuid'] );
				return new WP_Error(
					'server_busy',
					'server_busy',
					array(
						'status'      => 503,
						'retry_after' => 5,
					)
				);
			}
			$identity = Item_Stager::identity( $raw );
			$uuid     = $identity['uuid'];
			if ( isset( $existing[ $uuid ] ) ) {
				$results[] = self::row_result( $existing[ $uuid ], true );
				continue;
			}
			$staged = $stager->stage( $identity, $raw );
			if ( ! self::insert_row( (int) $session['id'], $seq, $staged ) ) {
				// Another request stored it a moment ago (unique key): report what is stored.
				$again     = self::rows_for( (int) $session['id'], array( $uuid ) );
				$results[] = isset( $again[ $uuid ] ) ? self::row_result( $again[ $uuid ], true ) : array(
					'uuid'   => $uuid,
					'status' => 'missing',
				);
				continue;
			}
			++$batch[ $staged['status'] ];
			$results[] = self::staged_result( $staged );
		}

		Push_Lock::heartbeat( $session['push_uuid'] );
		$cursor                             = self::find( $session['push_uuid'] )['cursor_json'];
		$cursor['last_seq']                 = max( (int) $cursor['last_seq'], $seq );
		$cursor['batches'][ (string) $seq ] = $sha;
		$cursor['received']                 = self::received( (int) $session['id'] );
		self::update(
			(int) $session['id'],
			array(
				'state'       => 'receiving',
				'cursor_json' => $cursor,
			)
		);

		self::audit(
			'items_staged',
			$pairing,
			$session['push_uuid'],
			array(
				'seq'           => $seq,
				'items'         => count( $results ),
				'staged'        => $batch['staged'],
				'held'          => $batch['held'],
				'pending_media' => $batch['pending_media'],
				'refused'       => $batch['refused'],
			)
		);
		if ( $batch['held'] > 0 ) {
			self::audit(
				'items_held',
				$pairing,
				$session['push_uuid'],
				array(
					'seq'  => $seq,
					'held' => $batch['held'],
				)
			);
		}

		$session = self::find( $session['push_uuid'] );
		if ( (int) $cursor['received'] >= (int) $session['counts']['items'] ) {
			$session = self::finalize( $session, $pairing );
			$rows    = self::rows_for( (int) $session['id'], array_keys( $identities ) );
			foreach ( $results as $i => $result ) {
				if ( isset( $rows[ $result['uuid'] ] ) && 'refused' === $rows[ $result['uuid'] ]['status'] && 'refused' !== $result['status'] ) {
					$results[ $i ] = self::row_result( $rows[ $result['uuid'] ], false );
				}
			}
		}

		return self::batch_answer( $session, $seq, $results, false );
	}

	/**
	 * POST /remote/push/{id}/abort: purges the staged rows and releases the lock, before apply.
	 *
	 * @param array $session Session.
	 * @param array $pairing Pairing row.
	 * @return array|WP_Error
	 */
	public static function abort( array $session, array $pairing ) {
		if ( 'aborted' === $session['state'] ) {
			return self::present( $session );
		}
		if ( 'expired' === $session['state'] ) {
			return self::error( 'push_expired', 410 );
		}
		if ( ! in_array( $session['state'], self::UNAPPLIED_STATES, true ) ) {
			// Once the apply started, only a live administrator can end it (Phase 8 roll back).
			return self::error( 'push_applying', 409 );
		}

		Media_Receiver::delete_temp( $session );
		$purged = self::purge( (int) $session['id'] );
		self::update(
			(int) $session['id'],
			array(
				'state'        => 'aborted',
				'finished_gmt' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		Push_Lock::release( $session['push_uuid'] );

		self::audit(
			'push_aborted',
			$pairing,
			$session['push_uuid'],
			array(
				'reason' => 'source',
				'purged' => $purged,
			)
		);

		return self::present( self::find( $session['push_uuid'] ) );
	}

	/**
	 * Ends a session that never wrote anything on live, from live itself (a live administrator
	 * released its stale lock): staged rows purged, temporary folder deleted, lock released.
	 *
	 * @param array  $session Session (open, receiving, staged or awaiting_review).
	 * @param string $reason  Reason for the audit.
	 * @param int    $user_id User.
	 * @return void
	 */
	public static function abort_local( array $session, $reason, $user_id ) {
		Media_Receiver::delete_temp( $session );
		$purged = self::purge( (int) $session['id'] );
		self::update(
			(int) $session['id'],
			array(
				'state'        => 'aborted',
				'finished_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'error_code'   => sanitize_key( $reason ),
			)
		);
		Push_Lock::release( (string) $session['push_uuid'] );
		Audit_Log::write(
			'push_aborted',
			array(
				'actor_id'    => absint( $user_id ),
				'pairing_id'  => (int) $session['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => array(
					'reason' => sanitize_key( $reason ),
					'purged' => $purged,
				),
			)
		);
	}

	/**
	 * Expires sessions older than 24 hours that were not applied: staged rows purged, temporary
	 * folder deleted, lock released (daily prune; blueprint §14.5). Retention of applied pushes is
	 * prune_retention().
	 *
	 * @param int $limit Most sessions per call.
	 * @return int Sessions expired.
	 */
	public static function expire_old( $limit = 20 ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::TTL );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$uuids = $wpdb->get_col( $wpdb->prepare( "SELECT push_uuid FROM %i WHERE side = %s AND state IN ('open','receiving','staged','awaiting_review','failed') AND started_gmt < %s ORDER BY id ASC LIMIT %d", Tables::table_name( 'pushes' ), self::SIDE, $cutoff, max( 1, absint( $limit ) ) ) );

		$done = 0;
		foreach ( is_array( $uuids ) ? $uuids : array() as $uuid ) {
			$session = self::find( (string) $uuid );
			if ( null !== $session && self::expire_if_due( $session ) ) {
				++$done;
			}
		}
		return $done;
	}

	/**
	 * The session as the remote routes and the screens present it.
	 *
	 * @param array|null $session Session.
	 * @return array
	 */
	public static function present( $session ) {
		if ( ! is_array( $session ) ) {
			return array();
		}
		$counts  = self::counts_by_status( (int) $session['id'] );
		$cursor  = $session['cursor_json'];
		$started = strtotime( (string) $session['started_gmt'] . ' UTC' );
		$lock    = Push_Lock::state_for( $session['push_uuid'] );

		return array(
			'id'            => (int) $session['id'],
			'push'          => (string) $session['push_uuid'],
			'state'         => (string) $session['state'],
			'apply'         => \SoftGlaze\SafePush\Apply\Applier::progress( $session ),
			'rollback'      => \SoftGlaze\SafePush\Apply\Rollback_Runner::progress( $session ),
			// Interrupted: the apply stopped (stored state), or the source stopped in the middle of
			// sending, or a rollback stopped (no heartbeat for 5 minutes).
			'interrupted'   => 'interrupted' === $session['state'] || ( in_array( $session['state'], array( 'open', 'receiving', 'rolling_back' ), true ) && 'none' !== $lock && 'fresh' !== $lock ) || ( 'rolling_back' === $session['state'] && 'none' === $lock ),
			'pruned'        => ! empty( $cursor['pruned'] ),
			'lock'          => $lock,
			'items'         => (int) $session['counts']['items'],
			'declared'      => $session['counts']['declared'],
			'received'      => array_merge( $counts, array( 'total' => array_sum( $counts ) ) ),
			'last_seq'      => (int) $cursor['last_seq'],
			'complete'      => ! empty( $cursor['complete'] ),
			'plan_hash_ok'  => isset( $cursor['plan_hash_ok'] ) ? (bool) $cursor['plan_hash_ok'] : null,
			'started_at'    => false === $started ? null : $started,
			'expires_at'    => false === $started ? null : $started + self::TTL,
			'error'         => (string) $session['error_code'],
			'limits'        => self::limits(),
			'pending_files' => (int) $session['counts']['files'],
			'files'         => array_merge(
				Media_Receiver::summary( (int) $session['id'] ),
				array(
					'declared'       => (int) $session['counts']['files'],
					'declared_bytes' => (int) $session['counts']['file_bytes'],
					'closed'         => ! empty( $cursor['files_closed'] ) || (int) $cursor['last_seq'] > 0,
				)
			),
		);
	}

	/**
	 * Limits the source must keep to: item batches and media (blueprint §13).
	 *
	 * @return array
	 */
	public static function limits() {
		$settings = Settings::get();
		return array_merge(
			array(
				'item_batch' => max( 1, min( self::MAX_BATCH, (int) $settings['item_batch'] ) ),
				'max_bytes'  => self::MAX_BATCH_BYTES,
				'max_items'  => self::MAX_ITEMS,
			),
			Media_Receiver::limits()
		);
	}

	/**
	 * Merges values into a session's cursor (read fresh, so concurrent keys are kept).
	 *
	 * @param int   $id     Session row ID.
	 * @param array $values Keys to set.
	 * @return void
	 */
	public static function cursor_set( $id, array $values ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the cursor as stored now.
		$raw    = $wpdb->get_var( $wpdb->prepare( 'SELECT cursor_json FROM %i WHERE id = %d AND side = %s', Tables::table_name( 'pushes' ), absint( $id ), self::SIDE ) );
		$cursor = json_decode( (string) $raw, true );
		$cursor = is_array( $cursor ) ? $cursor : self::empty_cursor();
		self::update( $id, array( 'cursor_json' => array_merge( $cursor, $values ) ) );
	}

	/**
	 * The cursor of a session exactly as stored (for a compare-and-swap).
	 *
	 * @param int $id Session row ID.
	 * @return string
	 */
	public static function raw_cursor( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the exact stored text for the compare-and-swap.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT cursor_json FROM %i WHERE id = %d AND side = %s', Tables::table_name( 'pushes' ), absint( $id ), self::SIDE ) );
	}

	/**
	 * Claims a step of a session: writes the cursor only if nobody changed it since it was read,
	 * so two requests never run the same session's step at once.
	 *
	 * @param int    $id     Session row ID.
	 * @param string $raw    Cursor as read with raw_cursor().
	 * @param array  $cursor Cursor to store.
	 * @return bool
	 */
	public static function claim_cursor( $id, $raw, array $cursor ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; compare-and-swap on the cursor.
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET cursor_json = %s WHERE id = %d AND side = %s AND cursor_json = %s', Tables::table_name( 'pushes' ), (string) wp_json_encode( $cursor ), absint( $id ), self::SIDE, (string) $raw ) );
		return 1 === (int) $updated;
	}

	/**
	 * Retention of applied pushes (blueprint §11.4, §14.5): pushes in a final state whose snapshots
	 * are older than the retention lose their item rows (snapshots included) and their snapshot
	 * folder, in bounded batches. The newest pushes with snapshots are kept whatever their age (at
	 * least 3, never the newest one); sessions waiting for a decision (failed_partial, interrupted,
	 * rolling_back) are never pruned. The pushes row stays as history, marked pruned.
	 *
	 * @param int $days   Retention in days (7–180).
	 * @param int $keep   Newest pushes kept whatever their age (at least 3).
	 * @param int $budget Most item rows deleted in this run.
	 * @return array{sessions:int,rows:int,folders:int}
	 */
	public static function prune_retention( $days, $keep = 3, $budget = 10000 ) {
		global $wpdb;

		$days   = min( 180, max( 7, absint( $days ) ) );
		$keep   = max( 3, absint( $keep ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$table  = Tables::table_name( 'pushes' );
		$items  = Tables::table_name( 'push_items' );
		$out    = array(
			'sessions' => 0,
			'rows'     => 0,
			'folders'  => 0,
		);

		// The newest destination pushes that wrote something keep their snapshots whatever their age.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$newest  = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE side = %s AND state IN ('applied','rolled_back','failed_partial','interrupted','rolling_back') ORDER BY id DESC LIMIT %d", $table, self::SIDE, $keep ) );
		$protect = array_map( 'intval', is_array( $newest ) ? $newest : array() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$top       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(id) FROM %i WHERE side = %s', $table, self::SIDE ) );
		$protect[] = $top;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; final sessions past retention, oldest first.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, push_uuid, cursor_json FROM %i WHERE side = %s AND state IN ('applied','rolled_back','aborted','expired','failed') AND COALESCE(finished_gmt, started_gmt) < %s ORDER BY id ASC LIMIT 50", $table, self::SIDE, $cutoff ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( in_array( (int) $row['id'], $protect, true ) ) {
				continue;
			}
			$cursor = json_decode( (string) $row['cursor_json'], true );
			$cursor = is_array( $cursor ) ? $cursor : array();
			while ( $out['rows'] < $budget ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; bounded batch delete.
				$n            = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE push_id = %d AND side = %s LIMIT %d', $items, (int) $row['id'], self::SIDE, 500 ) );
				$out['rows'] += $n;
				if ( $n < 500 ) {
					break;
				}
			}
			if ( $out['rows'] >= $budget ) {
				// The rest of this push waits for the next run.
				break;
			}
			if ( empty( $cursor['pruned'] ) ) {
				$session = self::find( (string) $row['push_uuid'] );
				if ( null !== $session && \SoftGlaze\SafePush\Apply\Snapshot_Store::delete_folder( $session ) ) {
					++$out['folders'];
				}
				self::cursor_set( (int) $row['id'], array( 'pruned' => time() ) );
				++$out['sessions'];
			}
		}

		// Source pushes that ended long ago: their plan rows and file rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$sent = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE side = 'source' AND state IN ('done','failed','aborted') AND COALESCE(finished_gmt, started_gmt) < %s ORDER BY id ASC LIMIT 50", $table, $cutoff ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$newest_sent = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM %i WHERE side = 'source'", $table ) );
		foreach ( is_array( $sent ) ? $sent : array() as $id ) {
			if ( (int) $id === $newest_sent || $out['rows'] >= $budget ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; bounded batch delete.
			$out['rows'] += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE push_id = %d AND side IN ('source','source_file') LIMIT %d", $items, absint( $id ), 5000 ) );
		}

		$out['folders'] += \SoftGlaze\SafePush\Apply\Snapshot_Store::prune_orphans();
		return $out;
	}

	/**
	 * A session (destination side) by UUID, counts and cursor decoded, or null.
	 *
	 * @param string $push_uuid UUID.
	 * @return array|null
	 */
	public static function find( $push_uuid ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the session changes between requests.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE push_uuid = %s AND side = %s', Tables::table_name( 'pushes' ), (string) $push_uuid, self::SIDE ), ARRAY_A );
		return is_array( $row ) ? self::decode_session( $row ) : null;
	}

	/**
	 * A session (destination side) by row ID, counts and cursor decoded, or null.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	public static function find_by_id( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the session changes between requests.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND side = %s', Tables::table_name( 'pushes' ), absint( $id ), self::SIDE ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$session = self::decode_session( $row );
		self::expire_if_due( $session );
		$session = self::find( (string) $session['push_uuid'] );
		return null === $session ? null : \SoftGlaze\SafePush\Apply\Recovery::detect( $session );
	}

	/**
	 * A received session as the live Pushes tab shows it in detail (blueprint §16): counts, the
	 * held items with their reasons (plain text), the items skipped, failed or refused, and the
	 * snapshot size.
	 *
	 * @param array $session Session.
	 * @return array
	 */
	public static function detail( array $session ) {
		global $wpdb;

		$out = self::present( $session );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables; credential columns are never selected.
		$source = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT remote_url FROM %i WHERE id = %d', Tables::table_name( 'pairings' ), absint( $session['pairing_id'] ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the rows that did not go through, for the detail screen.
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT uuid, object_type, action, status, error, payload, local_object_id FROM %i WHERE push_id = %d AND side = %s AND object_type NOT IN ('file','file_path') AND status IN ('skipped','failed','refused') ORDER BY id ASC LIMIT 200", Tables::table_name( 'push_items' ), absint( $session['id'] ), self::SIDE ), ARRAY_A );
		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			$parts   = explode( ':', (string) $row['error'], 2 );
			$items[] = array(
				'uuid'    => (string) $row['uuid'],
				'kind'    => (string) $row['object_type'],
				'type'    => is_array( $payload ) && isset( $payload['type'] ) ? (string) $payload['type'] : '',
				'title'   => is_array( $payload ) && isset( $payload['title'] ) ? (string) $payload['title'] : ( 'option' === $row['object_type'] && is_array( $payload ) && isset( $payload['type'] ) ? (string) $payload['type'] : '' ),
				'status'  => (string) $row['status'],
				'reason'  => $parts[0],
				'live_id' => (int) $row['local_object_id'],
			);
		}

		$out['source_url']     = $source;
		$out['host']           = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$out['held_items']     = \SoftGlaze\SafePush\Apply\Review::held_items( $session );
		$out['undecided']      = \SoftGlaze\SafePush\Apply\Review::undecided( $session );
		$out['snapshot_bytes'] = \SoftGlaze\SafePush\Apply\Snapshot_Store::size( $session );
		$out['problems']       = $items;
		$out['can']            = array(
			'unfiltered_html' => current_user_can( 'unfiltered_html' ),
			'edit_css'        => current_user_can( 'edit_css' ),
		);

		// Recovery (blueprint §10.4, §12): what the live administrator can do with this push.
		$failed = null;
		$apply  = isset( $session['cursor_json']['apply'] ) && is_array( $session['cursor_json']['apply'] ) ? $session['cursor_json']['apply'] : array();
		if ( 'failed_partial' === $session['state'] && isset( $apply['failed']['row'] ) ) {
			$row = \SoftGlaze\SafePush\Apply\Rollback_Runner::row( (int) $apply['failed']['row'] );
			if ( null !== $row && 'failed' === $row['status'] ) {
				$failed = array(
					'uuid'    => (string) $row['uuid'],
					'kind'    => (string) $row['object_type'],
					'type'    => isset( $row['payload']['type'] ) ? (string) $row['payload']['type'] : '',
					'title'   => \SoftGlaze\SafePush\Apply\Review::title( (string) $row['object_type'], $row['payload'] ),
					'reason'  => (string) $row['error'],
					'live_id' => (int) $row['local_object_id'],
				);
			}
		}
		$refusal            = \SoftGlaze\SafePush\Apply\Rollback_Runner::refusal( $session );
		$out['failed_item'] = $failed;
		$out['lock_view']   = \SoftGlaze\SafePush\Apply\Recovery::lock_view();
		$out['recovery']    = array(
			'resume'          => 'interrupted' === $session['state'],
			'retry'           => 'failed_partial' === $session['state'] && null !== $failed,
			'skip'            => 'failed_partial' === $session['state'] && null !== $failed,
			'rollback'        => null === $refusal,
			'rollback_reason' => null === $refusal ? '' : $refusal->get_error_code(),
		);
		return $out;
	}

	/**
	 * Whether any push row on this site (either side) has this UUID.
	 *
	 * @param string $push_uuid UUID.
	 * @return bool
	 */
	private static function uuid_taken( $push_uuid ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; unique key lookup.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE push_uuid = %s LIMIT 1', Tables::table_name( 'pushes' ), (string) $push_uuid ) );
	}

	/**
	 * Recent sessions for the Pushes tab, newest first, with the source address.
	 *
	 * @param int $limit Most sessions.
	 * @return array[]
	 */
	public static function recent( $limit = 50 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own tables; credential columns are never selected.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT p.*, r.remote_url AS source_url, r.pairing_uuid FROM %i p LEFT JOIN %i r ON r.id = p.pairing_id WHERE p.side = %s ORDER BY p.id DESC LIMIT %d', Tables::table_name( 'pushes' ), Tables::table_name( 'pairings' ), self::SIDE, max( 1, min( 200, absint( $limit ) ) ) ), ARRAY_A );

		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$session = self::decode_session( $row );
			self::expire_if_due( $session );
			$fresh = self::find( $session['push_uuid'] );
			if ( null === $fresh ) {
				continue;
			}
			$item               = self::present( \SoftGlaze\SafePush\Apply\Recovery::detect( $fresh ) );
			$item['source_url'] = (string) $row['source_url'];
			$item['pairing']    = (string) $row['pairing_uuid'];
			$out[]              = $item;
		}
		return $out;
	}

	/**
	 * Counts of a session's rows per status.
	 *
	 * @param int $push_id Session row ID.
	 * @return array<string,int>
	 */
	public static function counts_by_status( $push_id ) {
		global $wpdb;

		$out = array_fill_keys( array_merge( self::STATUSES, self::APPLY_STATUSES ), 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; item rows only (files are counted by Media_Receiver).
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM %i WHERE push_id = %d AND side = %s AND object_type NOT IN ('file','file_path') GROUP BY status", Tables::table_name( 'push_items' ), absint( $push_id ), self::SIDE ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( isset( $out[ $row['status'] ] ) ) {
				$out[ $row['status'] ] = (int) $row['n'];
			}
		}
		return $out;
	}

	/**
	 * Expires a session when it is older than 24 hours and was not applied.
	 *
	 * @param array $session Session.
	 * @return bool Whether it is (now) expired.
	 */
	private static function expire_if_due( array $session ) {
		if ( 'expired' === $session['state'] ) {
			return true;
		}
		$started = strtotime( (string) $session['started_gmt'] . ' UTC' );
		if ( ! in_array( $session['state'], self::UNAPPLIED_STATES, true ) || false === $started || time() - $started < self::TTL ) {
			return false;
		}

		Media_Receiver::delete_temp( $session );
		$purged = self::purge( (int) $session['id'] );
		self::update(
			(int) $session['id'],
			array(
				'state'        => 'expired',
				'finished_gmt' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		Push_Lock::release( $session['push_uuid'] );

		Audit_Log::write(
			'push_aborted',
			array(
				'actor_type'  => 'system',
				'pairing_id'  => (int) $session['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => $session['push_uuid'],
				'details'     => array(
					'reason' => 'expired',
					'purged' => $purged,
				),
			)
		);
		return true;
	}

	/**
	 * The final pass once every declared item arrived: deferred references, the plan hash and
	 * the counts per action. The session becomes staged, or failed (lock released).
	 *
	 * @param array $session Session.
	 * @param array $pairing Pairing row.
	 * @return array The session.
	 */
	private static function finalize( array $session, array $pairing ) {
		global $wpdb;

		$table = Tables::table_name( 'push_items' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; every item row of the session (file rows are not items) for the final pass.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, uuid, object_type, action, decision, status, refs, post_hash FROM %i WHERE push_id = %d AND side = %s AND object_type NOT IN ('file','file_path') ORDER BY id ASC", $table, (int) $session['id'], self::SIDE ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$map = array();
		foreach ( $rows as $row ) {
			$refs                = json_decode( (string) $row['refs'], true );
			$map[ $row['uuid'] ] = array(
				'kind'   => (string) $row['object_type'],
				'status' => (string) $row['status'],
				'refs'   => is_array( $refs ) ? $refs : array(),
			);
		}

		$changed = Item_Stager::final_pass( $map );
		$refused = 0;
		foreach ( $rows as $row ) {
			if ( ! isset( $changed[ $row['uuid'] ] ) ) {
				continue;
			}
			$change = $changed[ $row['uuid'] ];
			if ( $change['refused'] ) {
				++$refused;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
				$wpdb->update(
					$table,
					array(
						'status'  => 'refused',
						'error'   => 'unresolved_reference',
						'payload' => wp_json_encode( array( 'v' => 1 ) ),
						'refs'    => '[]',
					),
					array( 'id' => (int) $row['id'] )
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
				$wpdb->update( $table, array( 'refs' => (string) wp_json_encode( $change['refs'] ) ), array( 'id' => (int) $row['id'] ) );
			}
		}

		// Live's own check that what arrived is the plan the source confirmed: the plan hash over
		// every row, and the counts per action.
		$lines   = array();
		$actions = array_fill_keys( self::ACTIONS, 0 );
		foreach ( $rows as $row ) {
			$lines[] = $row['uuid'] . ':' . $row['decision'] . ':' . $row['post_hash'];
			if ( isset( $actions[ $row['action'] ] ) ) {
				++$actions[ $row['action'] ];
			}
		}
		sort( $lines, SORT_STRING );
		$hash_ok   = hash_equals( (string) $session['plan_hash'], hash( 'sha256', implode( "\n", $lines ) ) );
		$declared  = array_merge( array_fill_keys( self::ACTIONS, 0 ), array_map( 'intval', (array) $session['counts']['declared'] ) );
		$counts_ok = $declared === $actions;

		$cursor                  = $session['cursor_json'];
		$cursor['complete']      = true;
		$cursor['plan_hash_ok']  = $hash_ok;
		$cursor['counts_ok']     = $counts_ok;
		$cursor['final_refused'] = $refused;

		$fields = array(
			'state'       => $hash_ok && $counts_ok ? 'staged' : 'failed',
			'cursor_json' => $cursor,
		);
		if ( ! $hash_ok || ! $counts_ok ) {
			$fields['error_code'] = $hash_ok ? 'counts_mismatch' : 'plan_hash_mismatch';
		}
		self::update( (int) $session['id'], $fields );
		if ( 'failed' === $fields['state'] ) {
			Media_Receiver::delete_temp( $session );
			Push_Lock::release( $session['push_uuid'] );
		}

		$counts = self::counts_by_status( (int) $session['id'] );
		self::audit(
			'items_staged',
			$pairing,
			$session['push_uuid'],
			array_merge(
				$counts,
				array(
					'complete'     => true,
					'state'        => $fields['state'],
					'plan_hash_ok' => $hash_ok,
					'unresolved'   => $refused,
				)
			),
			'staged' === $fields['state'] ? 'ok' : 'error'
		);

		return self::find( $session['push_uuid'] );
	}

	/**
	 * The answer to an item batch.
	 *
	 * @param array $session Session.
	 * @param int   $seq     Batch.
	 * @param array $results Per item.
	 * @param bool  $replay  Whether the batch was sent before.
	 * @return array
	 */
	private static function batch_answer( array $session, $seq, array $results, $replay ) {
		$batch = array_fill_keys( self::STATUSES, 0 );
		foreach ( $results as $result ) {
			if ( isset( $batch[ $result['status'] ] ) ) {
				++$batch[ $result['status'] ];
			}
		}
		$session_view = self::present( $session );
		return array(
			'push'       => $session_view['push'],
			'seq'        => (int) $seq,
			'replay'     => (bool) $replay,
			'results'    => $results,
			'batch'      => $batch,
			'session'    => $session_view,
			'held_total' => (int) $session_view['received']['held'],
		);
	}

	/**
	 * The per-item answer of a freshly staged row.
	 *
	 * @param array $staged Item_Stager result.
	 * @return array
	 */
	private static function staged_result( array $staged ) {
		$out = array(
			'uuid'     => $staged['identity']['uuid'],
			'status'   => $staged['status'],
			'warnings' => array_values( array_unique( array_column( $staged['warnings'], 'code' ) ) ),
			'held'     => count( $staged['gate'] ),
		);
		if ( 'refused' === $staged['status'] ) {
			$out['reason'] = $staged['reason'];
			$out['field']  = $staged['field'];
		}
		return $out;
	}

	/**
	 * The per-item answer from a stored row.
	 *
	 * @param array $row       Row.
	 * @param bool  $duplicate Whether the item had been received before this batch.
	 * @return array
	 */
	private static function row_result( array $row, $duplicate ) {
		$payload = json_decode( (string) $row['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();
		$out     = array(
			'uuid'      => (string) $row['uuid'],
			'status'    => (string) $row['status'],
			'warnings'  => isset( $payload['warnings'] ) ? array_values( array_unique( array_column( (array) $payload['warnings'], 'code' ) ) ) : array(),
			'held'      => isset( $payload['gate'] ) ? count( (array) $payload['gate'] ) : 0,
			'duplicate' => (bool) $duplicate,
		);
		if ( 'refused' === $row['status'] ) {
			$parts         = explode( ':', (string) $row['error'], 2 );
			$out['reason'] = $parts[0];
			$out['field']  = isset( $parts[1] ) ? $parts[1] : '';
		}
		return $out;
	}

	/**
	 * Stores one staged item (the content only when it was not refused).
	 *
	 * @param int   $push_id Session row ID.
	 * @param int   $seq     Batch.
	 * @param array $staged  Item_Stager result.
	 * @return bool False when a row for the UUID exists already.
	 */
	private static function insert_row( $push_id, $seq, array $staged ) {
		global $wpdb;

		$id      = $staged['identity'];
		$payload = array(
			'v'        => 1,
			'type'     => $id['type'],
			'live_id'  => (int) $staged['target'],
			'warnings' => $staged['warnings'],
		);
		if ( 'refused' !== $staged['status'] ) {
			if ( null !== $staged['data'] ) {
				$payload['data'] = $staged['data'];
			}
			if ( ! empty( $staged['groups'] ) ) {
				$payload['groups'] = $staged['groups'];
			}
			if ( ! empty( $staged['gate'] ) ) {
				$payload['gate'] = $staged['gate'];
			}
			if ( is_array( $staged['media'] ) ) {
				// The attachment's files on live: final paths and refs of the committed files.
				$payload['media'] = $staged['media'];
			}
			foreach ( array( 'scope', 'item_ids', 'group_hashes', 'live_hash' ) as $name ) {
				if ( isset( $staged['extra'][ $name ] ) ) {
					$payload[ $name ] = $staged['extra'][ $name ];
				}
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; INSERT IGNORE keeps the first row of a UUID (items are idempotent by push and UUID).
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (push_id, side, seq, uuid, object_type, action, decision, status, payload, refs, local_object_id, post_hash, error) VALUES (%d, %s, %d, %s, %s, %s, %s, %s, %s, %s, %d, %s, %s)',
				Tables::table_name( 'push_items' ),
				absint( $push_id ),
				self::SIDE,
				absint( $seq ),
				$id['uuid'],
				$id['kind'],
				$id['action'],
				$id['plan_action'],
				$staged['status'],
				(string) wp_json_encode( $payload ),
				(string) wp_json_encode( array_values( $staged['refs'] ) ),
				absint( $staged['target'] ),
				$id['hash'],
				'refused' === $staged['status'] ? trim( $staged['reason'] . ':' . $staged['field'], ':' ) : ''
			)
		);
		return 1 === (int) $result;
	}

	/**
	 * Rows of a session for some UUIDs, keyed by UUID.
	 *
	 * @param int      $push_id Session row ID.
	 * @param string[] $uuids   UUIDs (at most a batch).
	 * @return array<string,array>
	 */
	private static function rows_for( $push_id, array $uuids ) {
		global $wpdb;

		$out = array();
		foreach ( array_chunk( array_values( $uuids ), 50 ) as $chunk ) {
			$slots = array_pad( $chunk, 50, '' );
			// Fifty fixed slots keep the statement one literal string (unused slots are '').
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Own table; the replacements are passed as one array (53 values for 53 placeholders).
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT uuid, object_type, status, payload, error FROM %i WHERE push_id = %d AND side = %s AND uuid IN (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', array_merge( array( Tables::table_name( 'push_items' ), absint( $push_id ), self::SIDE ), $slots ) ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$out[ $row['uuid'] ] = $row;
			}
		}
		return $out;
	}

	/**
	 * Every row of a session as uuid => array( kind, status ), for references.
	 *
	 * @param int $push_id Session row ID.
	 * @return array
	 */
	private static function known( $push_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; item rows only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT uuid, object_type, status FROM %i WHERE push_id = %d AND side = %s AND object_type NOT IN ('file','file_path')", Tables::table_name( 'push_items' ), absint( $push_id ), self::SIDE ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ $row['uuid'] ] = array( (string) $row['object_type'], (string) $row['status'] );
		}
		return $out;
	}

	/**
	 * Rows received by a session.
	 *
	 * @param int $push_id Session row ID.
	 * @return int
	 */
	private static function received( $push_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; item rows only.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE push_id = %d AND side = %s AND object_type NOT IN ('file','file_path')", Tables::table_name( 'push_items' ), absint( $push_id ), self::SIDE ) );
	}

	/**
	 * Deletes a session's staged rows.
	 *
	 * @param int $push_id Session row ID.
	 * @return int Rows deleted.
	 */
	private static function purge( $push_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE push_id = %d AND side = %s', Tables::table_name( 'push_items' ), absint( $push_id ), self::SIDE ) );
	}

	/**
	 * Updates session columns (cursor_json encoded here).
	 *
	 * @param int   $id     Session row ID.
	 * @param array $fields state, cursor_json, finished_gmt, error_code.
	 * @return void
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;

		$data = array();
		foreach ( array( 'state', 'finished_gmt', 'error_code', 'error_detail' ) as $name ) {
			if ( isset( $fields[ $name ] ) ) {
				$data[ $name ] = (string) $fields[ $name ];
			}
		}
		if ( isset( $fields['cursor_json'] ) ) {
			$data['cursor_json'] = (string) wp_json_encode( $fields['cursor_json'] );
		}
		if ( empty( $data ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Tables::table_name( 'pushes' ), $data, array( 'id' => absint( $id ) ) );
	}

	/**
	 * The body of R8, each field read by name.
	 *
	 * @param array $body Body.
	 * @return array|WP_Error
	 */
	private static function open_fields( array $body ) {
		$push = isset( $body['push'] ) && is_string( $body['push'] ) ? $body['push'] : '';
		$hash = isset( $body['plan_hash'] ) && is_string( $body['plan_hash'] ) ? $body['plan_hash'] : '';
		if ( ! wp_is_uuid( $push, 4 ) || strtolower( $push ) !== $push || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $hash ) ) {
			return self::error( 'invalid_body', 400 );
		}
		if ( ! isset( $body['counts'] ) || ! is_array( $body['counts'] ) ) {
			return self::error( 'invalid_body', 400 );
		}
		$counts = array();
		foreach ( self::ACTIONS as $action ) {
			if ( ! isset( $body['counts'][ $action ] ) || ! is_int( $body['counts'][ $action ] ) || $body['counts'][ $action ] < 0 ) {
				return self::error( 'invalid_body', 400 );
			}
			$counts[ $action ] = $body['counts'][ $action ];
		}
		$items = isset( $body['items'] ) && is_int( $body['items'] ) ? $body['items'] : -1;
		if ( array_sum( $counts ) !== $items || $items < 1 ) {
			return self::error( 'invalid_body', 400 );
		}
		if ( $items > self::MAX_ITEMS ) {
			return self::error( 'too_many_items', 400 );
		}
		$files = isset( $body['files'] ) ? $body['files'] : 0;
		$bytes = isset( $body['file_bytes'] ) ? $body['file_bytes'] : 0;
		if ( ! is_int( $files ) || $files < 0 || $files > Media_Receiver::MAX_DECLARED_FILES || ! is_int( $bytes ) || $bytes < 0 ) {
			return self::error( 'invalid_body', 400 );
		}

		return array(
			'push'       => $push,
			'plan_hash'  => $hash,
			'counts'     => $counts,
			'items'      => $items,
			'files'      => $files,
			'file_bytes' => $bytes,
		);
	}

	/**
	 * A cursor of a new session.
	 *
	 * @return array
	 */
	private static function empty_cursor() {
		return array(
			'last_seq' => 0,
			'batches'  => new \stdClass(),
			'received' => 0,
			'complete' => false,
		);
	}

	/**
	 * A session row with counts and cursor decoded.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decode_session( array $row ) {
		$counts = json_decode( (string) $row['counts'], true );
		$cursor = json_decode( (string) $row['cursor_json'], true );
		$counts = is_array( $counts ) ? $counts : array();
		$cursor = is_array( $cursor ) ? $cursor : array();

		$row['counts']      = array(
			'declared'   => isset( $counts['declared'] ) && is_array( $counts['declared'] ) ? $counts['declared'] : array_fill_keys( self::ACTIONS, 0 ),
			'items'      => isset( $counts['items'] ) ? (int) $counts['items'] : 0,
			'files'      => isset( $counts['files'] ) ? (int) $counts['files'] : 0,
			'file_bytes' => isset( $counts['file_bytes'] ) ? (int) $counts['file_bytes'] : 0,
		);
		$row['cursor_json'] = array_merge(
			array(
				'last_seq' => 0,
				'batches'  => array(),
				'received' => 0,
				'complete' => false,
			),
			$cursor
		);
		if ( ! is_array( $row['cursor_json']['batches'] ) ) {
			$row['cursor_json']['batches'] = array();
		}
		return $row;
	}

	/**
	 * Audit row of a session event: counts and UUIDs only.
	 *
	 * @param string $event   Event.
	 * @param array  $pairing Pairing row.
	 * @param string $push    Session UUID.
	 * @param array  $details Counts.
	 * @param string $result  ok, denied or error.
	 * @return void
	 */
	private static function audit( $event, array $pairing, $push, array $details, $result = 'ok' ) {
		Audit_Log::write(
			$event,
			array(
				'result'      => $result,
				'actor_type'  => 'remote',
				'actor_id'    => 0,
				'pairing_id'  => (int) $pairing['id'],
				'object_type' => 'push',
				'object_ref'  => (string) $push,
				'details'     => $details,
			)
		);
	}

	/**
	 * An error that carries only its code.
	 *
	 * @param string $code   Code.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $status ) {
		return new WP_Error( $code, $code, array( 'status' => $status ) );
	}
}
