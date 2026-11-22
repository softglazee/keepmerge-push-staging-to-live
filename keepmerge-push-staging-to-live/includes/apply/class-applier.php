<?php
/**
 * The apply of a staged push on live.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply;

use SoftGlaze\SafePush\Apply\Appliers\Attachment;
use SoftGlaze\SafePush\Apply\Appliers\Menu;
use SoftGlaze\SafePush\Apply\Appliers\Option;
use SoftGlaze\SafePush\Apply\Appliers\Post;
use SoftGlaze\SafePush\Apply\Appliers\Site_Editor;
use SoftGlaze\SafePush\Apply\Appliers\Term;
use SoftGlaze\SafePush\Apply\Appliers\Woo_Product;
use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Limits;
use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Sync\Export_Context;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Items;
use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Sync\Sync_State;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Media_Receiver;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Session;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a staged push in time-boxed passes: snapshot every row first, allocate placeholders so
 * every UUID resolves, write in dependency order (each item re-hashed first, so an edit made on live
 * during the push is kept), adopt matched items, then finish. A push with held items waits and is
 * applied only from a live administrator's own session. Whoever runs a step writes as themselves
 * (filters are never removed, the user never switched), and a compare-and-swap on the cursor stops
 * two requests from running the same push at once.
 */
final class Applier {

	const SIDE = 'destination';

	/**
	 * States of a session while its passes run.
	 */
	const RUNNING = array( 'snapshotting', 'applying' );

	/**
	 * Rank of each kind in the write pass; parents come before children inside a rank.
	 */
	const WRITE_RANK = array(
		'term'          => 0,
		'attachment'    => 1,
		'post'          => 2,
		'product'       => 3,
		'variation'     => 4,
		'pattern'       => 5,
		'navigation'    => 5,
		'menu'          => 6,
		'template'      => 7,
		'template_part' => 7,
		'global_styles' => 7,
		'widgets'       => 8,
		'option'        => 9,
	);

	/**
	 * Skip reasons (an item skipped is not a failure: the push goes on).
	 */
	const SKIPS = array( 'changed_during_push', 'review_skipped', 'dependency_skipped', 'reference_missing', 'reference_mismatch', 'theme_missing', 'option_not_allowed', 'type_not_included', 'path_taken', 'woocommerce_inactive', 'attribute_missing', 'target_exists' );

	/**
	 * Most items processed in one step; the softglaze_sp_apply_step_items filter may only lower it.
	 */
	const STEP_ITEMS = 250;

	const RESULTS_PAGE = 200;

	const MAX_BODY = 1024;

	/**
	 * POST /remote/push/{id}/apply, as the receiver user.
	 *
	 * @param array $session Session (this pairing's).
	 * @param array $body    Decoded body: after (int).
	 * @return array|WP_Error
	 */
	public static function remote( array $session, array $body ) {
		$after = 0;
		if ( isset( $body['after'] ) ) {
			if ( ! is_int( $body['after'] ) || $body['after'] < 0 ) {
				return self::error( 'invalid_body', 400 );
			}
			$after = $body['after'];
		}

		$state = (string) $session['state'];
		if ( in_array( $state, array( 'open', 'receiving' ), true ) ) {
			return self::error( 'push_not_staged', 409 );
		}
		if ( 'expired' === $state ) {
			return self::error( 'push_expired', 410 );
		}
		if ( in_array( $state, array( 'aborted', 'failed' ), true ) ) {
			return self::error( 'push_' . $state, 409 );
		}

		if ( 'interrupted' === $state ) {
			$apply = self::cursor( $session );
			if ( 'remote' !== $apply['actor'] ) {
				// A live administrator decides how this push goes on (resume or roll back on live).
				return self::answer( $session, $after );
			}
			if ( ! Receive_Window::state()['open'] ) {
				// A request that passed routing before the window was closed must not undo the
				// interruption the close made; after the window is opened again the source may resume.
				return self::answer( $session, $after );
			}
			// The source drove this apply, so it may resume it.
			$resumed = Recovery::resume( $session, 'remote', 0 );
			if ( is_wp_error( $resumed ) ) {
				return 'push_locked' === $resumed->get_error_code() ? self::error( 'push_locked', 409 ) : self::answer( Push_Session::find( (string) $session['push_uuid'] ), $after );
			}
			$session = $resumed;
		}

		if ( in_array( $state, array( 'staged', 'awaiting_review' ), true ) ) {
			$session = self::gate( $session );
			if ( 'awaiting_review' === $session['state'] ) {
				// Nothing is written while a push has held items; a live administrator finishes it.
				return self::answer( $session, $after );
			}
			$session = self::begin( $session, 'remote', 0 );
		}

		if ( in_array( $session['state'], self::RUNNING, true ) ) {
			$apply = self::cursor( $session );
			if ( 'remote' !== $apply['actor'] ) {
				// A live administrator is finishing this push: the source only reads its state.
				return self::answer( $session, $after );
			}
			$run = self::run( $session );
			if ( is_wp_error( $run ) ) {
				return $run;
			}
			$session = $run;
		}

		return self::answer( $session, $after );
	}

	/**
	 * POST /admin/pushes/{id}/continue: one step of a reviewed push from a live administrator's
	 * session, once every held item is decided and the user holds the rights its approvals need.
	 *
	 * @param array $session Session.
	 * @param int   $user_id Current user.
	 * @return array|WP_Error
	 */
	public static function admin_continue( array $session, $user_id ) {
		$state = (string) $session['state'];
		if ( 'awaiting_review' === $state ) {
			if ( Review::undecided( $session ) > 0 ) {
				return new WP_Error( 'review_incomplete', __( 'Decide every held item before you continue the apply.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
			}
			$refusal = self::approval_rights( $session );
			if ( null !== $refusal ) {
				return $refusal;
			}
			$session = self::begin( $session, 'user', (int) $user_id );
		} elseif ( in_array( $state, self::RUNNING, true ) ) {
			$apply = self::cursor( $session );
			if ( 'user' !== $apply['actor'] ) {
				return new WP_Error( 'push_applying', __( 'The source site is applying this push. Wait until it finishes.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
			}
			$refusal = self::approval_rights( $session );
			if ( null !== $refusal ) {
				return $refusal;
			}
		} elseif ( 'interrupted' === $state ) {
			return new WP_Error( 'push_interrupted', __( 'This push stopped without finishing. Resume it (or roll it back) first.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		} elseif ( 'failed_partial' === $state ) {
			return new WP_Error( 'push_failed_partial', __( 'This push stopped at an item. Retry the item, skip it, or roll the push back.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		} elseif ( 'applied' !== $state ) {
			return new WP_Error( 'nothing_to_continue', __( 'This push is not waiting for review on this site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		if ( in_array( $session['state'], self::RUNNING, true ) ) {
			$run = self::run( $session );
			if ( is_wp_error( $run ) ) {
				return $run;
			}
			$session = $run;
		}
		return self::answer( $session, 0 );
	}

	/**
	 * Apply progress of a session, from its cursor (for the session's presentation).
	 *
	 * @param array $session Session.
	 * @return array
	 */
	public static function progress( array $session ) {
		$apply = self::cursor( $session );
		return array(
			'pass'     => $apply['pass'],
			'actor'    => $apply['actor'],
			'review'   => (bool) $apply['review'],
			'done'     => (int) $apply['done'],
			'total'    => (int) $apply['total'],
			'started'  => (int) $apply['started'],
			'finished' => (int) $apply['finished'],
			'error'    => (string) $apply['error'],
		);
	}

	// --- gate and start --------------------------------------------------------------------------

	/**
	 * The review gate: a session with any held row waits until a live administrator continues it.
	 *
	 * @param array $session Session.
	 * @return array The session.
	 */
	private static function gate( array $session ) {
		$apply = self::cursor( $session );
		if ( 'awaiting_review' === $session['state'] || ! empty( $apply['review'] ) ) {
			return $session;
		}
		$held = self::count_where( $session, "status = 'held'" );
		if ( 0 === $held ) {
			return $session;
		}
		$apply['review'] = true;
		self::save( $session, $apply, array( 'state' => 'awaiting_review' ) );
		Audit_Log::write(
			'items_held',
			array(
				'actor_type'  => 'remote',
				'actor_id'    => 0,
				'pairing_id'  => (int) $session['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => array(
					'held'  => $held,
					'state' => 'awaiting_review',
				),
			)
		);
		return Push_Session::find( (string) $session['push_uuid'] );
	}

	/**
	 * Starts the passes: review-skipped rows are marked, the snapshot pass begins.
	 *
	 * Direct write: core has no API for plugin tables.
	 *
	 * @param array  $session Session.
	 * @param string $actor   'remote' or 'user'.
	 * @param int    $user_id User (for 'user').
	 * @return array
	 */
	private static function begin( array $session, $actor, $user_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; held rows the review skipped are never written.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'skipped', error = 'review_skipped' WHERE push_id = %d AND side = %s AND status = 'held' AND review = 'skip'", Tables::table_name( 'push_items' ), absint( $session['id'] ), self::SIDE ) );

		$apply             = self::cursor( $session );
		$apply['pass']     = 'snapshot';
		$apply['pos']      = 0;
		$apply['actor']    = $actor;
		$apply['user']     = absint( $user_id );
		$apply['started']  = time();
		$apply['total']    = self::count_where( $session, self::PROCESSABLE );
		$apply['done']     = 0;
		$apply['order']    = array();
		$apply['idx']      = 0;
		$apply['counts']   = array();
		$apply['error']    = '';
		$apply['finished'] = 0;
		self::save( $session, $apply, array( 'state' => 'snapshotting' ) );

		Audit_Log::write(
			'user' === $actor ? 'push_resumed' : 'push_opened',
			array(
				'actor_type'  => 'user' === $actor ? 'user' : 'remote',
				'actor_id'    => 'user' === $actor ? absint( $user_id ) : 0,
				'pairing_id'  => (int) $session['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => array(
					'apply' => 'started',
					'items' => (int) $apply['total'],
				),
			)
		);
		return Push_Session::find( (string) $session['push_uuid'] );
	}

	/**
	 * Rows a pass processes: staged ones, and held ones a live administrator approved or filtered.
	 */
	const PROCESSABLE = "( status = 'staged' OR ( status = 'held' AND review IN ('approve','filtered') ) )";

	/**
	 * The rights the approvals of a push need, for the user who continues it.
	 *
	 * @param array $session Session.
	 * @return WP_Error|null
	 */
	public static function approval_rights( array $session ) {
		foreach ( Review::held_items( $session ) as $item ) {
			if ( 'approve' !== $item['review'] || ! in_array( $item['status'], array( 'held', 'applying' ), true ) ) {
				continue;
			}
			$refusal = Review::approve_refusal( $item );
			if ( null !== $refusal ) {
				return $refusal;
			}
		}
		return null;
	}

	// --- the passes ------------------------------------------------------------------------------

	/**
	 * Runs passes until the time budget, the step's item cap or the end.
	 *
	 * @param array $session Session.
	 * @return array|WP_Error The session after the step.
	 */
	private static function run( array $session ) {
		$raw   = Push_Session::raw_cursor( (int) $session['id'] );
		$apply = self::cursor( $session );
		$now   = time();
		if ( (int) $apply['busy_until'] > $now ) {
			return self::error( 'push_busy', 409 );
		}
		$budget              = Limits::time_budget();
		$apply['busy_until'] = $now + $budget + 15;
		$claimed             = json_decode( $raw, true );
		$claimed             = is_array( $claimed ) ? $claimed : array();
		$claimed['apply']    = $apply;
		if ( ! Push_Session::claim_cursor( (int) $session['id'], $raw, $claimed ) ) {
			return self::error( 'push_busy', 409 );
		}

		$lock = self::ensure_lock( $session );
		if ( is_wp_error( $lock ) ) {
			$apply['busy_until'] = 0;
			self::save( $session, $apply, array() );
			return $lock;
		}

		wp_raise_memory_limit( 'admin' );
		// A step the source drives answers within half the budget; an administrator's own step on
		// this site (continue after review, resume) keeps the whole budget.
		$deadline = 'remote' === $apply['actor'] ? Limits::remote_deadline() : Limits::deadline( 2 );
		$cap      = (int) apply_filters( 'softglaze_sp_apply_step_items', self::STEP_ITEMS );
		$cap      = max( 1, min( self::STEP_ITEMS, $cap ) );
		$state    = (string) $session['state'];
		$fields   = array();
		$items    = 0;

		while ( $items < $cap && ! Limits::over( $deadline ) ) {
			$outcome = self::next( $session, $apply );
			if ( 'idle' === $outcome ) {
				continue;
			}
			if ( 'item' === $outcome ) {
				++$items;
				continue;
			}
			if ( 'done' === $outcome ) {
				$state = 'applied';
				break;
			}
			if ( 'failed' === $outcome ) {
				$state = 'failed_partial';
				break;
			}
			if ( 'applying' === $outcome ) {
				$state = 'applying';
			}
		}

		if ( 'snapshotting' === $state && 'snapshot' !== $apply['pass'] ) {
			$state = 'applying';
		}
		$stored = Push_Session::find( (string) $session['push_uuid'] );
		if ( is_array( $stored ) && 'interrupted' === $stored['state'] && in_array( $state, self::RUNNING, true ) ) {
			// The window closed or the pairing was revoked during this step: the live administrator
			// decides, and the progress is kept.
			$state = 'interrupted';
		}
		Push_Lock::heartbeat( (string) $session['push_uuid'] );
		$apply['busy_until'] = 0;
		$fields['state']     = $state;
		if ( in_array( $state, array( 'applied', 'failed_partial' ), true ) ) {
			$fields['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
		}
		if ( 'failed_partial' === $state ) {
			$fields['error_code'] = (string) $apply['error'];
		}
		if ( 'applied' === $state ) {
			$fields['error_code'] = '';
		}
		self::save( $session, $apply, $fields );
		return Push_Session::find( (string) $session['push_uuid'] );
	}

	/**
	 * One unit of work of the current pass.
	 *
	 * @param array $session Session.
	 * @param array $apply   Apply cursor (changed).
	 * @return string item, idle (moved to the next pass), applying, done or failed.
	 */
	private static function next( array $session, array &$apply ) {
		switch ( $apply['pass'] ) {
			case 'snapshot':
				$row = self::next_row( $session, (int) $apply['pos'], self::PROCESSABLE );
				if ( null === $row ) {
					self::audit_snapshot( $session, $apply );
					$apply['pass']  = 'allocate';
					$apply['order'] = self::order( $session, 'allocate' );
					$apply['idx']   = 0;
					return 'applying';
				}
				self::mark( $apply );
				$apply['pos'] = (int) $row['id'];
				$taken        = self::guarded(
					static function () use ( $session, $row ) {
						return self::snapshot_row( $session, $row );
					}
				);
				if ( is_wp_error( $taken ) ) {
					return self::fail( $session, $apply, $row, $taken );
				}
				$apply['snapshot_bytes'] = (int) $apply['snapshot_bytes'] + (int) $taken;
				$moved                   = self::changed_since_plan( $row );
				if ( '' !== $moved ) {
					// Live changed the item after the plan was made: it keeps live's version.
					self::update_row(
						(int) $row['id'],
						array(
							'status' => 'skipped',
							'error'  => $moved,
						)
					);
					self::count( $apply, 'skipped' );
				}
				return 'item';

			case 'allocate':
				if ( (int) $apply['idx'] >= count( $apply['order'] ) ) {
					$apply['pass']  = 'write';
					$apply['order'] = self::order( $session, 'write' );
					$apply['idx']   = 0;
					return 'idle';
				}
				$row = self::row( (int) $apply['order'][ (int) $apply['idx'] ] );
				self::mark( $apply );
				++$apply['idx'];
				if ( null === $row || (int) $row['local_object_id'] > 0 || ! self::processable( $row ) || '' === Scope::space( (string) $row['object_type'] ) ) {
					// Options and units have no ID to allocate: they are written in place.
					return 'idle';
				}
				$allocated = self::guarded(
					static function () use ( $session, $row ) {
						return self::allocate_row( $session, $row );
					}
				);
				if ( is_wp_error( $allocated ) ) {
					return self::skip_or_fail( $session, $apply, $row, $allocated );
				}
				return 'item';

			case 'write':
				if ( (int) $apply['idx'] >= count( $apply['order'] ) ) {
					$apply['pass'] = 'adopt';
					$apply['pos']  = 0;
					return 'idle';
				}
				$row = self::row( (int) $apply['order'][ (int) $apply['idx'] ] );
				if ( null === $row ) {
					++$apply['idx'];
					return 'idle';
				}
				self::mark( $apply );
				if ( 'applying' === $row['status'] ) {
					// A step that died in the middle of this item.
					$again = self::recover( $session, $row );
					if ( 'failed' === $again ) {
						++$apply['idx'];
						return self::fail( $session, $apply, $row, new WP_Error( 'interrupted_item', 'interrupted_item' ) );
					}
					$row = self::row( (int) $row['id'] );
				}
				if ( ! self::processable( $row ) ) {
					++$apply['idx'];
					return 'idle';
				}
				$written = self::guarded(
					static function () use ( $session, $row ) {
						return self::write_row( $session, $row );
					}
				);
				++$apply['idx'];
				++$apply['done'];
				if ( is_wp_error( $written ) ) {
					return self::skip_or_fail( $session, $apply, $row, $written );
				}
				self::count( $apply, $written );
				return 'item';

			case 'adopt':
				$row = self::next_row( $session, (int) $apply['pos'], "action = 'adopt' AND " . self::PROCESSABLE );
				if ( null === $row ) {
					$apply['pass'] = 'finish';
					$apply['pos']  = 0;
					return 'idle';
				}
				self::mark( $apply );
				$apply['pos'] = (int) $row['id'];
				++$apply['done'];
				$adopted = self::guarded(
					static function () use ( $session, $row ) {
						return self::adopt_row( $session, $row );
					}
				);
				if ( is_wp_error( $adopted ) ) {
					return self::skip_or_fail( $session, $apply, $row, $adopted );
				}
				self::count( $apply, 'adopted' );
				return 'item';

			case 'finish':
				$more = self::finish( $session, $apply );
				return $more ? 'item' : 'done';
		}
		return 'done';
	}

	/**
	 * Snapshot of one row (and of the files an attachment update will replace).
	 *
	 * @param array $session Session.
	 * @param array $row     Row.
	 * @return int|WP_Error Bytes recorded.
	 */
	private static function snapshot_row( array $session, array $row ) {
		$taken = Snapshot_Store::take( $session, $row );
		if ( is_wp_error( $taken ) ) {
			return $taken;
		}
		$bytes = (int) $taken['bytes'];
		if ( 'attachment' === $row['object_type'] && 'update' === $row['action'] ) {
			$files = Attachment::snapshot_files( $session, $row );
			if ( is_wp_error( $files ) ) {
				return $files;
			}
			$bytes += (int) $files;
		}
		return $bytes;
	}

	/**
	 * Whether live's item changed since the plan was made, checked against the snapshot just taken.
	 *
	 * Hashes are compared in constant time with hash_equals().
	 *
	 * @param array $row Row (before the snapshot).
	 * @return string The skip reason, or ''.
	 */
	private static function changed_since_plan( array $row ) {
		if ( ! in_array( $row['action'], array( 'update', 'trash', 'adopt' ), true ) || empty( $row['payload']['live_hash'] ) ) {
			return '';
		}
		if ( 'option' === $row['object_type'] && ! Scope::option_allowed( substr( (string) $row['uuid'], strlen( 'option:' ) ) ) ) {
			// Not an item here any more: live's own allowlist changed since the push was staged.
			return 'option_not_allowed';
		}
		$fresh = self::row( (int) $row['id'] );
		return null !== $fresh && ! hash_equals( (string) $row['payload']['live_hash'], (string) $fresh['pre_hash'] ) ? 'changed_during_push' : '';
	}

	/**
	 * Allocate pass for one new item: a placeholder with the item's UUID.
	 *
	 * @param array $session Session.
	 * @param array $row     Row.
	 * @return true|WP_Error
	 */
	private static function allocate_row( array $session, array $row ) {
		$space = Scope::space( (string) $row['object_type'] );
		$found = Identity::find( $space, (string) $row['uuid'] );
		$image = is_array( $row['pre_image'] ) ? $row['pre_image'] : json_decode( (string) $row['pre_image'], true );
		$had   = is_array( $image ) && isset( $image['uuid_owners'] ) && is_array( $image['uuid_owners'] ) ? array_map( 'intval', $image['uuid_owners'] ) : array();
		if ( array() !== array_intersect( $found, $had ) ) {
			// The object carried the UUID before this push wrote anything: it is live's own object
			// outside the scope, not a placeholder, and is left alone.
			return new WP_Error( 'target_exists', 'target_exists' );
		}
		if ( 1 === count( $found ) ) {
			// A step died after creating this placeholder and before recording it: record it rather
			// than make a second one.
			self::update_row(
				(int) $row['id'],
				array(
					'action'          => 'created',
					'local_object_id' => (int) $found[0],
				)
			);
			self::$index = null;
			return true;
		}
		$ctx = Item_Context::build( $session, $row, self::index( $session ), array(), true );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$applier = self::applier( $ctx->kind );
		if ( null === $applier ) {
			return new WP_Error( 'type_not_included', 'type_not_included' );
		}
		$id = call_user_func( array( $applier, 'allocate' ), $ctx );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		self::update_row(
			(int) $row['id'],
			array(
				'action'          => 'created',
				'local_object_id' => (int) $id,
			)
		);
		self::$index = null;
		return true;
	}

	/**
	 * Write pass for one item, only while live still equals its snapshot, so an edit made on live
	 * during the push is never overwritten.
	 *
	 * @param array $session Session.
	 * @param array $row     Row.
	 * @return string|WP_Error The row's final action (created, updated, trashed, option_set, option_added).
	 */
	private static function write_row( array $session, array $row ) {
		$kind   = (string) $row['object_type'];
		$action = (string) $row['action'];
		$ref    = Snapshot_Store::ref( $kind, $row );

		// Optimistic concurrency: live's item must still be what the snapshot saw.
		if ( in_array( $action, array( 'update', 'trash' ), true ) ) {
			$now = self::current_hash( $kind, $ref );
			if ( ! hash_equals( (string) $row['pre_hash'], $now ) ) {
				return new WP_Error( 'changed_during_push', 'changed_during_push' );
			}
		}
		if ( 'created' === $action && ! self::placeholder_ok( $row ) ) {
			return new WP_Error( 'placeholder_missing', 'placeholder_missing' );
		}

		self::update_row( (int) $row['id'], array( 'status' => 'applying' ) );

		if ( 'trash' === $action ) {
			$trashed = wp_trash_post( (int) $ref );
			if ( ! $trashed ) {
				return new WP_Error( 'write_failed', 'write_failed' );
			}
			Sync_State::record_base( (int) $session['pairing_id'], (string) $row['uuid'], $kind, (int) $ref, '', array(), (int) $session['id'] );
			self::applied( $row, 'trashed', (int) $ref, '', array() );
			return 'trashed';
		}

		$ctx = Item_Context::build( $session, $row, self::index( $session ), Media_Receiver::rewrites( (int) $session['id'] ) );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		if ( 'filtered' === $row['review'] ) {
			$ctx->data = Review::filter( $kind, $ctx->data );
		}
		$applier = self::applier( $kind );
		if ( null === $applier ) {
			return new WP_Error( 'type_not_included', 'type_not_included' );
		}

		$result = call_user_func( array( $applier, 'write' ), $ctx );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$live_id  = isset( $result['id'] ) ? (int) $result['id'] : (int) $ctx->live_id;
		$final    = isset( $result['action'] ) ? (string) $result['action'] : ( 'created' === $action ? 'created' : 'updated' );
		$item     = Items::export( $kind, 'option' === $kind || 'widgets' === $kind ? $ref : $live_id, Export_Context::destination() );
		$post     = null === $item ? '' : (string) $item['hash'];
		$groups   = null === $item ? array() : (array) $item['groups'];
		$warnings = array_values( array_unique( array_merge( $ctx->warnings, isset( $result['warnings'] ) ? (array) $result['warnings'] : array() ) ) );

		$space = Scope::space( $kind );
		if ( '' !== $space && $live_id > 0 ) {
			Identity::stamp( $space, $live_id, (string) $row['uuid'] );
		}
		$base_groups = 'product' === $kind ? Woo_Product::base_groups( $ctx, $groups ) : array();
		Sync_State::record_base( (int) $session['pairing_id'], (string) $row['uuid'], $kind, '' === $space ? 0 : $live_id, $post, $base_groups, (int) $session['id'] );

		self::applied( $row, $final, $live_id, $post, $warnings, $groups );
		return $final;
	}

	/**
	 * Adopt pass for one item: UUID and base of a matched, unchanged item.
	 *
	 * Hashes are compared in constant time with hash_equals().
	 *
	 * @param array $session Session.
	 * @param array $row     Row.
	 * @return true|WP_Error
	 */
	private static function adopt_row( array $session, array $row ) {
		$kind = (string) $row['object_type'];
		$ref  = Snapshot_Store::ref( $kind, $row );
		$item = Items::export( $kind, $ref, Export_Context::destination() );
		$now  = null === $item ? '' : (string) $item['hash'];
		if ( '' === $now || ! hash_equals( (string) $row['pre_hash'], $now ) || ! hash_equals( (string) $row['post_hash'], $now ) ) {
			return new WP_Error( 'changed_during_push', 'changed_during_push' );
		}
		$space = Scope::space( $kind );
		if ( '' !== $space ) {
			Identity::stamp( $space, (int) $ref, (string) $row['uuid'] );
		}
		Sync_State::record_base( (int) $session['pairing_id'], (string) $row['uuid'], $kind, '' === $space ? 0 : (int) $ref, $now, (array) $item['groups'], (int) $session['id'] );
		self::applied( $row, 'adopted', is_int( $ref ) ? $ref : 0, $now, array() );
		return true;
	}

	/**
	 * A row left in "applying" by a step that died: recorded as applied when live already holds the
	 * intended hash (never written twice), processed again when live still holds the snapshot, and
	 * otherwise left for the live administrator to decide.
	 *
	 * Hashes are compared in constant time with hash_equals().
	 *
	 * @param array $session Session.
	 * @param array $row     Row (light or full).
	 * @return string applied, again or failed.
	 */
	public static function recover( array $session, array $row ) {
		$row = self::row( (int) $row['id'] );
		if ( null === $row || 'applying' !== $row['status'] ) {
			return 'again';
		}
		$kind = (string) $row['object_type'];
		$ref  = Snapshot_Store::ref( $kind, $row );
		$item = Items::export( $kind, $ref, Export_Context::destination() );
		$now  = null === $item ? '' : (string) $item['hash'];

		$intended = (string) $row['post_hash'];
		$trashed  = 'trash' === $row['action'] && '' === $now;
		if ( $trashed || ( '' !== $intended && '' !== $now && hash_equals( $intended, $now ) ) ) {
			$live_id = 'option' === $kind || 'widgets' === $kind ? 0 : (int) $ref;
			$space   = Scope::space( $kind );
			if ( '' !== $space && $live_id > 0 && ! $trashed ) {
				Identity::stamp( $space, $live_id, (string) $row['uuid'] );
			}
			$groups = array();
			if ( 'product' === $kind && null !== $item ) {
				$ctx          = new Item_Context();
				$ctx->row     = $row;
				$ctx->kind    = $kind;
				$ctx->live_id = $live_id;
				$groups       = Woo_Product::base_groups( $ctx, (array) $item['groups'] );
			}
			Sync_State::record_base( (int) $session['pairing_id'], (string) $row['uuid'], $kind, '' === $space ? 0 : $live_id, $now, $groups, (int) $session['id'] );
			$final = $trashed ? 'trashed' : ( 'created' === $row['action'] ? 'created' : ( 'option' === $kind ? ( empty( $row['pre_image']['raw']['options'][ $ref ]['exists'] ) ? 'option_added' : 'option_set' ) : ( 'widgets' === $kind ? 'option_set' : 'updated' ) ) );
			self::applied( $row, $final, $live_id, $now, array( 'recovered' ), null === $item ? array() : (array) $item['groups'] );
			return 'applied';
		}
		if ( 'created' === $row['action'] || ( '' !== (string) $row['pre_hash'] && hash_equals( (string) $row['pre_hash'], $now ) ) || ( '' === (string) $row['pre_hash'] && ! empty( $row['pre_image']['absent'] ) && '' === $now ) ) {
			self::reset_row( $row );
			return 'again';
		}
		self::update_row(
			(int) $row['id'],
			array(
				'status' => 'failed',
				'error'  => 'interrupted_item',
			)
		);
		return 'failed';
	}

	/**
	 * Puts a row back to be processed by the passes (Retry, resume).
	 *
	 * @param array $row Row.
	 * @return void
	 */
	public static function reset_row( array $row ) {
		self::update_row(
			(int) $row['id'],
			array(
				'status' => '' !== (string) $row['review'] ? 'held' : 'staged',
				'error'  => '',
			)
		);
	}

	/**
	 * Marks a row skipped with a reason (Skip this item).
	 *
	 * @param array  $row  Row.
	 * @param string $code Reason.
	 * @return void
	 */
	public static function skip_row( array $row, $code ) {
		self::update_row(
			(int) $row['id'],
			array(
				'status' => 'skipped',
				'error'  => sanitize_key( $code ),
			)
		);
	}

	/**
	 * Runs one item's work; anything it throws stops the push at that item instead of ending the
	 * request with the row half done.
	 *
	 * @param callable $work Work.
	 * @return mixed|WP_Error
	 */
	private static function guarded( $work ) {
		try {
			return call_user_func( $work );
		} catch ( \Throwable $e ) {
			// The message can carry content; only the fact is kept.
			return new WP_Error( 'item_exception', 'item_exception' );
		}
	}

	/**
	 * Remembers where the cursor was before the current row (Retry goes back there).
	 *
	 * @param array $apply Cursor (changed).
	 * @return void
	 */
	private static function mark( array &$apply ) {
		$apply['mark'] = array(
			'pass' => (string) $apply['pass'],
			'idx'  => (int) $apply['idx'],
			'pos'  => (int) $apply['pos'],
		);
	}

	/**
	 * Finish: payloads reduced in batches, then the end.
	 *
	 * Direct write: core has no API for plugin tables.
	 *
	 * @param array $session Session.
	 * @param array $apply   Cursor (changed).
	 * @return bool Whether more work remains.
	 */
	private static function finish( array $session, array &$apply ) {
		global $wpdb;

		$table = Tables::table_name( 'push_items' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the next rows whose staged payload is cleared.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, object_type, payload FROM %i WHERE push_id = %d AND side = %s AND id > %d AND object_type NOT IN ('file','file_path') ORDER BY id ASC LIMIT 100", $table, absint( $session['id'] ), self::SIDE, (int) $apply['pos'] ), ARRAY_A );
		if ( ! empty( $rows ) ) {
			foreach ( $rows as $row ) {
				$apply['pos'] = (int) $row['id'];
				$payload      = json_decode( (string) $row['payload'], true );
				$payload      = is_array( $payload ) ? $payload : array();
				$codes        = array();
				foreach ( isset( $payload['warnings'] ) ? (array) $payload['warnings'] : array() as $warning ) {
					if ( is_array( $warning ) && isset( $warning['code'] ) ) {
						$codes[] = array( 'code' => (string) $warning['code'] );
					}
				}
				$title = Review::title( (string) $row['object_type'], $payload );
				$kept  = array(
					'v'        => 1,
					'type'     => isset( $payload['type'] ) ? (string) $payload['type'] : '',
					'title'    => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 100 ) : substr( $title, 0, 100 ),
					'warnings' => $codes,
					'cleared'  => true,
				);
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
				$wpdb->update( $table, array( 'payload' => (string) wp_json_encode( $kept ) ), array( 'id' => (int) $row['id'] ) );
			}
			return true;
		}

		Media_Receiver::delete_temp( $session );
		Push_Lock::release( (string) $session['push_uuid'] );
		$apply['finished'] = time();
		$apply['pass']     = 'done';

		$counts = Push_Session::counts_by_status( (int) $session['id'] );
		Audit_Log::write(
			'push_applied',
			array(
				'actor_type'  => 'user' === $apply['actor'] ? 'user' : 'remote',
				'actor_id'    => 'user' === $apply['actor'] ? (int) $apply['user'] : 0,
				'pairing_id'  => (int) $session['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => array_merge(
					(array) $apply['counts'],
					array(
						'applied' => (int) $counts['applied'],
						'skipped' => (int) $counts['skipped'],
						'failed'  => (int) $counts['failed'],
						'refused' => (int) $counts['refused'],
					)
				),
			)
		);
		return false;
	}

	// --- outcomes ---------------------------------------------------------------------------------

	/**
	 * A skip (the push goes on) or a failure (the push stops, failed_partial).
	 *
	 * @param array    $session Session.
	 * @param array    $apply   Cursor (changed).
	 * @param array    $row     Row.
	 * @param WP_Error $error   Error.
	 * @return string item or failed.
	 */
	private static function skip_or_fail( array $session, array &$apply, array $row, WP_Error $error ) {
		$code = sanitize_key( (string) $error->get_error_code() );
		if ( in_array( $code, self::SKIPS, true ) ) {
			$fresh = self::row( (int) $row['id'] );
			if ( null !== $fresh && 'created' === $fresh['action'] && (int) $fresh['local_object_id'] > 0 ) {
				// A placeholder whose item is not written after all does not stay on live as a draft.
				Raw_Restore::remove( $fresh );
			}
			self::update_row(
				(int) $row['id'],
				array(
					'status' => 'skipped',
					'error'  => $code,
				)
			);
			self::count( $apply, 'skipped' );
			return 'item';
		}
		return self::fail( $session, $apply, $row, $error );
	}

	/**
	 * A failed item: the push stops as failed_partial until the live administrator retries, skips
	 * the item or rolls back.
	 *
	 * @param array    $session Session.
	 * @param array    $apply   Cursor (changed).
	 * @param array    $row     Row.
	 * @param WP_Error $error   Error.
	 * @return string failed.
	 */
	private static function fail( array $session, array &$apply, array $row, WP_Error $error ) {
		$code = substr( sanitize_key( (string) $error->get_error_code() ), 0, 60 );
		$code = '' === $code ? 'write_failed' : $code;
		self::update_row(
			(int) $row['id'],
			array(
				'status' => 'failed',
				'error'  => $code,
			)
		);
		$apply['error']  = $code;
		$apply['failed'] = array_merge(
			isset( $apply['mark'] ) && is_array( $apply['mark'] ) ? $apply['mark'] : array(
				'pass' => (string) $apply['pass'],
				'idx'  => (int) $apply['idx'],
				'pos'  => (int) $apply['pos'],
			),
			array( 'row' => (int) $row['id'] )
		);
		Audit_Log::write(
			'push_failed',
			array(
				'result'      => 'error',
				'actor_type'  => 'user' === $apply['actor'] ? 'user' : 'remote',
				'actor_id'    => 'user' === $apply['actor'] ? (int) $apply['user'] : 0,
				'pairing_id'  => (int) $session['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => array(
					'code' => $code,
					'pass' => (string) $apply['pass'],
					'item' => (string) $row['uuid'],
					'kind' => (string) $row['object_type'],
				),
			)
		);
		return 'failed';
	}

	/**
	 * Marks a row applied.
	 *
	 * @param array  $row      Row.
	 * @param string $action   Final action.
	 * @param int    $live_id  Live object ID.
	 * @param string $hash     Live's post-apply hash.
	 * @param array  $warnings Warning codes.
	 * @param array  $groups   Live's product group hashes after the write.
	 * @return void
	 */
	private static function applied( array $row, $action, $live_id, $hash, array $warnings, array $groups = array() ) {
		$transfer = array(
			'staging_hash' => (string) $row['post_hash'],
			'warnings'     => array_values( array_slice( $warnings, 0, 20 ) ),
		);
		if ( isset( $groups['live_owned'] ) && is_string( $groups['live_owned'] ) ) {
			$transfer['post_groups'] = array( 'live_owned' => $groups['live_owned'] );
		}
		self::update_row(
			(int) $row['id'],
			array(
				'status'          => 'applied',
				'action'          => $action,
				'local_object_id' => (int) $live_id,
				'post_hash'       => (string) $hash,
				'transfer'        => (string) wp_json_encode( $transfer ),
				'applied_gmt'     => gmdate( 'Y-m-d H:i:s' ),
				'error'           => '',
			)
		);
	}

	/**
	 * Adds one to a count of the cursor.
	 *
	 * @param array  $apply Cursor (changed).
	 * @param string $name  Count.
	 * @return void
	 */
	private static function count( array &$apply, $name ) {
		$apply['counts'][ $name ] = ( isset( $apply['counts'][ $name ] ) ? (int) $apply['counts'][ $name ] : 0 ) + 1;
	}

	/**
	 * The snapshot_done audit row.
	 *
	 * @param array $session Session.
	 * @param array $apply   Cursor.
	 * @return void
	 */
	private static function audit_snapshot( array $session, array $apply ) {
		Audit_Log::write(
			'snapshot_done',
			array(
				'actor_type'  => 'user' === $apply['actor'] ? 'user' : 'remote',
				'actor_id'    => 'user' === $apply['actor'] ? (int) $apply['user'] : 0,
				'pairing_id'  => (int) $session['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => array(
					'items' => self::count_where( $session, 'snapshot_gmt IS NOT NULL' ),
					'bytes' => (int) $apply['snapshot_bytes'],
				),
			)
		);
	}

	// --- order ------------------------------------------------------------------------------------

	/**
	 * Row IDs of the allocate or write pass in dependency order: by kind rank, then parents before
	 * children, then by row.
	 *
	 * Direct query: core has no API for plugin tables, and a cache could be stale during a push.
	 *
	 * @param array  $session Session.
	 * @param string $pass    allocate or write.
	 * @return int[]
	 */
	private static function order( array $session, $pass ) {
		global $wpdb;

		$where = ( 'allocate' === $pass ? "action IN ('create','recreate')" : "action IN ('create','recreate','created','update','trash')" ) . ' AND ' . self::PROCESSABLE;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Own table; the conditions are constants of this class.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, uuid, object_type, refs FROM %i WHERE push_id = %d AND side = %s AND object_type NOT IN ('file','file_path') AND {$where} ORDER BY id ASC", Tables::table_name( 'push_items' ), absint( $session['id'] ), self::SIDE ), ARRAY_A );
		$rows = is_array( $rows ) ? $rows : array();

		$parent = array();
		$kinds  = array();
		foreach ( $rows as $row ) {
			$refs = json_decode( (string) $row['refs'], true );
			$kind = (string) $row['object_type'];
			foreach ( is_array( $refs ) ? $refs : array() as $ref ) {
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
			$kind   = $kinds[ $row['uuid'] ];
			$rank   = isset( self::WRITE_RANK[ $kind ] ) ? self::WRITE_RANK[ $kind ] : 10;
			$sort[] = array( $rank, $depth, (int) $row['id'] );
		}
		usort(
			$sort,
			static function ( $a, $b ) {
				foreach ( array( 0, 1, 2 ) as $i ) {
					if ( $a[ $i ] !== $b[ $i ] ) {
						return $a[ $i ] < $b[ $i ] ? -1 : 1;
					}
				}
				return 0;
			}
		);
		return array_map(
			static function ( $entry ) {
				return (int) $entry[2];
			},
			$sort
		);
	}

	// --- appliers ---------------------------------------------------------------------------------

	/**
	 * The applier class of a kind.
	 *
	 * @param string $kind Kind.
	 * @return string|null
	 */
	private static function applier( $kind ) {
		switch ( $kind ) {
			case 'post':
				return Post::class;
			case 'term':
				return Term::class;
			case 'menu':
				return Menu::class;
			case 'pattern':
			case 'navigation':
			case 'template':
			case 'template_part':
			case 'global_styles':
				return Site_Editor::class;
			case 'option':
			case 'widgets':
				return Option::class;
			case 'attachment':
				return Attachment::class;
			case 'product':
				return Woo_Product::class;
		}
		return null;
	}

	/**
	 * Live's current hash of an item ('' when it is no longer an item here).
	 *
	 * @param string     $kind Kind.
	 * @param int|string $ref  Local ID or option name.
	 * @return string
	 */
	private static function current_hash( $kind, $ref ) {
		$item = Items::export( $kind, $ref, Export_Context::destination() );
		return null === $item ? '' : (string) $item['hash'];
	}

	/**
	 * Whether a placeholder made by the allocate pass is still there and still this item's.
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	private static function placeholder_ok( array $row ) {
		$space = Scope::space( (string) $row['object_type'] );
		if ( '' === $space ) {
			return true;
		}
		return Identity::get( $space, (int) $row['local_object_id'] ) === (string) $row['uuid'];
	}

	/**
	 * Whether a row is one the passes process.
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	private static function processable( array $row ) {
		return 'staged' === $row['status'] || ( 'held' === $row['status'] && in_array( $row['review'], array( 'approve', 'filtered' ), true ) );
	}

	// --- rows and cursor --------------------------------------------------------------------------

	/**
	 * Index of the push's item rows by UUID, for references (cached for the request).
	 *
	 * @var array|null
	 */
	private static $index = null;

	/**
	 * Push ID the index belongs to.
	 *
	 * @var int
	 */
	private static $index_push = 0;

	/**
	 * Rows of the push by UUID: id, kind, action, status, local_object_id.
	 *
	 * Direct query: core has no API for plugin tables, and a cache could be stale during a push.
	 *
	 * @param array $session Session.
	 * @return array
	 */
	private static function index( array $session ) {
		global $wpdb;

		if ( null !== self::$index && self::$index_push === (int) $session['id'] ) {
			return self::$index;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the push's rows without their content.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, uuid, object_type, action, status, local_object_id FROM %i WHERE push_id = %d AND side = %s AND object_type NOT IN ('file','file_path')", Tables::table_name( 'push_items' ), absint( $session['id'] ), self::SIDE ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['uuid'] ] = array(
				'id'              => (int) $row['id'],
				'kind'            => (string) $row['object_type'],
				'action'          => (string) $row['action'],
				'status'          => (string) $row['status'],
				'local_object_id' => (int) $row['local_object_id'],
			);
		}
		self::$index      = $out;
		self::$index_push = (int) $session['id'];
		return $out;
	}

	/**
	 * One item row with payload, refs and pre-image decoded.
	 *
	 * Direct query: core has no API for plugin tables, and a cache could be stale during a push.
	 *
	 * @param int $id Row ID.
	 * @return array|null
	 */
	private static function row( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND side = %s', Tables::table_name( 'push_items' ), absint( $id ), self::SIDE ), ARRAY_A );
		return is_array( $row ) ? self::decode_row( $row ) : null;
	}

	/**
	 * The next row of a session after a row ID matching a condition of this class.
	 *
	 * Direct query; the condition is a class constant, never input.
	 *
	 * @param array  $session   Session.
	 * @param int    $after     Row ID.
	 * @param string $condition SQL condition (a constant of this class).
	 * @return array|null
	 */
	private static function next_row( array $session, $after, $condition ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table; the condition is built from constants of this class.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE push_id = %d AND side = %s AND id > %d AND object_type NOT IN ('file','file_path') AND {$condition} ORDER BY id ASC LIMIT 1", Tables::table_name( 'push_items' ), absint( $session['id'] ), self::SIDE, absint( $after ) ), ARRAY_A );
		return is_array( $row ) ? self::decode_row( $row ) : null;
	}

	/**
	 * Rows of a session matching a condition of this class.
	 *
	 * Direct query; the condition is a class constant, never input.
	 *
	 * @param array  $session   Session.
	 * @param string $condition SQL condition (a constant of this class).
	 * @return int
	 */
	private static function count_where( array $session, $condition ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Own table; the condition is built from constants of this class.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE push_id = %d AND side = %s AND object_type NOT IN ('file','file_path') AND {$condition}", Tables::table_name( 'push_items' ), absint( $session['id'] ), self::SIDE ) );
	}

	/**
	 * Decodes the JSON columns of a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decode_row( array $row ) {
		foreach ( array( 'payload', 'refs', 'pre_image', 'transfer' ) as $column ) {
			$value          = isset( $row[ $column ] ) ? json_decode( (string) $row[ $column ], true ) : null;
			$row[ $column ] = is_array( $value ) ? $value : array();
		}
		return $row;
	}

	/**
	 * Updates columns of a row.
	 *
	 * Direct write: core has no API for plugin tables.
	 *
	 * @param int   $id     Row ID.
	 * @param array $fields Columns.
	 * @return void
	 */
	private static function update_row( $id, array $fields ) {
		global $wpdb;

		$allowed = array( 'status', 'action', 'local_object_id', 'post_hash', 'transfer', 'applied_gmt', 'error' );
		$data    = array_intersect_key( $fields, array_flip( $allowed ) );
		if ( empty( $data ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Tables::table_name( 'push_items' ), $data, array( 'id' => absint( $id ) ) );
		self::$index = null;
	}

	/**
	 * The apply part of a session's cursor, with defaults.
	 *
	 * @param array $session Session.
	 * @return array
	 */
	private static function cursor( array $session ) {
		$apply = isset( $session['cursor_json']['apply'] ) && is_array( $session['cursor_json']['apply'] ) ? $session['cursor_json']['apply'] : array();
		return array_merge(
			array(
				'pass'           => '',
				'pos'            => 0,
				'idx'            => 0,
				'order'          => array(),
				'actor'          => '',
				'user'           => 0,
				'review'         => false,
				'busy_until'     => 0,
				'started'        => 0,
				'finished'       => 0,
				'done'           => 0,
				'total'          => 0,
				'counts'         => array(),
				'snapshot_bytes' => 0,
				'error'          => '',
			),
			$apply
		);
	}

	/**
	 * Stores the apply cursor (and other session columns).
	 *
	 * @param array $session Session.
	 * @param array $apply   Apply cursor.
	 * @param array $fields  state, finished_gmt, error_code.
	 * @return void
	 */
	private static function save( array $session, array $apply, array $fields ) {
		$fresh                 = Push_Session::find( (string) $session['push_uuid'] );
		$cursor                = is_array( $fresh ) ? $fresh['cursor_json'] : $session['cursor_json'];
		$cursor['apply']       = $apply;
		$fields['cursor_json'] = $cursor;
		Push_Session::update( (int) $session['id'], $fields );
	}

	/**
	 * The destination lock must be this session's (taken again when nothing holds it); another
	 * push's lock stops the step.
	 *
	 * Hashes are compared in constant time with hash_equals().
	 *
	 * @param array $session Session.
	 * @return true|WP_Error
	 */
	private static function ensure_lock( array $session ) {
		$lock = Push_Lock::get();
		if ( null === $lock ) {
			return Push_Lock::acquire( (string) $session['push_uuid'], (int) $session['pairing_id'] ) ? true : self::error( 'push_locked', 409 );
		}
		return hash_equals( (string) $lock['push_uuid'], (string) $session['push_uuid'] ) ? true : self::error( 'push_locked', 409 );
	}

	// --- answers ----------------------------------------------------------------------------------

	/**
	 * The answer of an apply step: state, progress, counts and a page of item results, so the source
	 * can record live's IDs.
	 *
	 * Direct query: core has no API for plugin tables, and a cache could be stale during a push.
	 *
	 * @param array $session Session.
	 * @param int   $after   Row ID cursor of the results.
	 * @return array
	 */
	public static function answer( array $session, $after ) {
		global $wpdb;

		$session = is_array( $session ) ? $session : array();
		// Rows finish in apply order, not in row order: results stop before the first row still open,
		// so the cursor never passes a row whose result is still to come.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the first unfinished row.
		$open    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(id) FROM %i WHERE push_id = %d AND side = %s AND object_type NOT IN ('file','file_path') AND status IN ('staged','held','pending_media','applying')", Tables::table_name( 'push_items' ), absint( $session['id'] ), self::SIDE ) );
		$can_end = in_array( (string) $session['state'], array( 'staged', 'awaiting_review', 'snapshotting', 'applying', 'interrupted', 'failed_partial' ), true );
		$below   = $open > 0 && $can_end ? $open : PHP_INT_MAX;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; item results after a cursor.
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT id, uuid, object_type, action, status, local_object_id, error FROM %i WHERE push_id = %d AND side = %s AND id > %d AND id < %d AND object_type NOT IN ('file','file_path') AND status IN ('applied','skipped','failed') ORDER BY id ASC LIMIT %d", Tables::table_name( 'push_items' ), absint( $session['id'] ), self::SIDE, absint( $after ), $below, self::RESULTS_PAGE ), ARRAY_A );
		$results = array();
		$next    = (int) $after;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$next      = (int) $row['id'];
			$results[] = array(
				'uuid'    => (string) $row['uuid'],
				'kind'    => (string) $row['object_type'],
				'status'  => (string) $row['status'],
				'action'  => (string) $row['action'],
				'live_id' => (int) $row['local_object_id'],
				'reason'  => (string) $row['error'],
			);
		}
		$counts = Push_Session::counts_by_status( (int) $session['id'] );

		return array(
			'push'      => (string) $session['push_uuid'],
			'state'     => (string) $session['state'],
			'apply'     => self::progress( $session ),
			'counts'    => $counts,
			'held'      => (int) $counts['held'],
			'undecided' => 'awaiting_review' === $session['state'] ? Review::undecided( $session ) : 0,
			'results'   => $results,
			'next'      => $next,
			'more'      => count( $results ) >= self::RESULTS_PAGE,
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
