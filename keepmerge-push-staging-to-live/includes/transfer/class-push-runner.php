<?php
/**
 * Sending a plan to live (source side).
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Transfer;

use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Limits;
use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Source_Pairing;
use SoftGlaze\SafePush\Sync\Export_Context;
use SoftGlaze\SafePush\Sync\Items;
use SoftGlaze\SafePush\Sync\Plan_Store;
use SoftGlaze\SafePush\Sync\Planner;
use SoftGlaze\SafePush\Sync\Reference_Mapper;
use SoftGlaze\SafePush\Sync\Sync_State;
use SoftGlaze\SafePush\Tables;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The source side of a push: a planned plan is sent to live as files, then item batches, then
 * applied by live in time-boxed calls (pausing on network or server trouble, stopping at
 * awaiting_review when live holds items). Each step is claimed with a compare-and-swap on the
 * cursor and records a batch's range before sending it, so a step that dies resends the same batch
 * and live answers it idempotently.
 */
final class Push_Runner {

	/**
	 * States of a push on the source (after planned).
	 */
	const STATES = array( 'sending_files', 'sending_items', 'paused', 'staged', 'applying', 'awaiting_review', 'done', 'failed', 'aborted' );

	/**
	 * States in which steps run.
	 */
	const ACTIVE = array( 'sending_files', 'sending_items', 'paused', 'staged', 'applying', 'awaiting_review' );

	/**
	 * The state of each sending phase.
	 */
	const PHASE_STATES = array(
		'files'  => 'sending_files',
		'items'  => 'sending_items',
		'apply'  => 'applying',
		'review' => 'awaiting_review',
	);

	const ROUTE = '/keepmerge/v1/remote/push';

	/**
	 * Most items in one push.
	 */
	const MAX_ITEMS = 5000;

	/**
	 * Most bytes of item envelopes per batch, below live's 2 MB body limit.
	 */
	const MAX_BATCH_BYTES = 1900000;

	/**
	 * Smallest byte budget halving goes down to.
	 */
	const MIN_BATCH_BYTES = 65536;

	const LOG_MAX = 60;

	const SAMPLES = 50;

	/**
	 * Codes after which the push pauses for Resume rather than failing (live's session is intact).
	 */
	const PAUSE_CODES = array( 'window_closed', 'network_error', 'server_error', 'tls_failed', 'rate_limited', 'ip_blocked', 'clock_skew', 'authorization_header_missing', 'https_required', 'transport_unpinned', 'response_invalid', 'response_unsigned', 'response_signature_invalid', 'address_refused', 'replay_blocked', 'redirect_refused', 'unexpected_status' );

	/**
	 * Starts a push from a planned plan: checks the typed domain and the plan, opens the session
	 * on live (POST /remote/push) and stores the ordered list of items to send.
	 *
	 * @param int        $plan_id Plan ID.
	 * @param string     $confirm The live domain as the admin typed it.
	 * @param int        $user_id User.
	 * @param mixed|null $client  Client with get()/post() (tests); the pairing's own by default.
	 * @return array|WP_Error The push as presented.
	 */
	public static function start( $plan_id, $confirm, $user_id, $client = null ) {
		$plan = Planner::usable( $plan_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( 'planned' !== $plan['state'] ) {
			return new WP_Error( 'plan_not_ready', __( 'This plan is not ready to push: wait until it is finished, or create a new plan.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		$pairing_uuid = isset( $plan['cursor_json']['pairing'] ) ? (string) $plan['cursor_json']['pairing'] : '';
		$pairing      = Source_Pairing::find( $pairing_uuid );
		if ( null === $pairing ) {
			return new WP_Error( 'pairing_not_found', __( 'This pairing does not exist on this site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
		}
		$host = self::host( $pairing );

		// The typed domain is checked here, on the server: a forged request cannot skip it.
		$typed = strtolower( trim( (string) $confirm ) );
		if ( '' === $host || ! hash_equals( $host, $typed ) ) {
			return new WP_Error(
				'confirm_mismatch',
				/* translators: %s: live site host name. */
				sprintf( __( 'Type the live site\'s domain exactly as shown (%s) to confirm the push.', 'keepmerge-push-staging-to-live' ), $host ),
				array( 'status' => 400 )
			);
		}

		$summary = is_array( $plan['counts'] ) ? $plan['counts'] : array();
		if ( ! empty( $summary['undecided'] ) || ! empty( $summary['blocked'] ) ) {
			return new WP_Error( 'plan_not_ready', __( 'Decide every conflict and clear every blocked item before pushing.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		$running = self::latest( (int) $pairing['id'] );
		if ( null !== $running && in_array( $running['state'], self::ACTIVE, true ) ) {
			return new WP_Error( 'push_in_progress', __( 'A push to this live site is still in progress. Resume or abort it first.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		$list        = array();
		$lines       = array();
		$attachments = array();
		$declared    = array_fill_keys( Push_Session::ACTIONS, 0 );
		foreach ( Plan_Store::all_items( (int) $plan['id'] ) as $row ) {
			$action = Planner::push_action( $row );
			if ( '' === $action ) {
				continue;
			}
			$list[]  = array( (string) $row['uuid'], $action );
			$lines[] = $row['uuid'] . ':' . $action . ':' . ( isset( $row['payload']['s'] ) ? (string) $row['payload']['s'] : '' );
			++$declared[ Item_Stager::PLAN_ACTIONS[ $action ] ];
			if ( 'attachment' === $row['object_type'] && in_array( $action, Media_Sender::SEND_ACTIONS, true ) && (int) $row['local_object_id'] > 0 ) {
				$attachments[] = array( (string) $row['uuid'], (int) $row['local_object_id'] );
			}
		}
		if ( empty( $list ) ) {
			return new WP_Error( 'nothing_to_push', __( 'Nothing to push: live already has every change you chose.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		if ( count( $list ) > self::MAX_ITEMS ) {
			return new WP_Error(
				'too_many_items',
				/* translators: 1: items in the plan, 2: most items per push. */
				sprintf( __( 'This plan would push %1$s items; a push carries at most %2$s. Plan again with "Only some kinds" and push in parts.', 'keepmerge-push-staging-to-live' ), number_format_i18n( count( $list ) ), number_format_i18n( self::MAX_ITEMS ) ),
				array( 'status' => 400 )
			);
		}
		$plan_hash = Planner::plan_hash( $lines );
		if ( ! hash_equals( (string) $plan['plan_hash'], $plan_hash ) ) {
			return new WP_Error( 'plan_changed', __( 'The plan changed since its summary was made. Plan again.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		// The media size goes first so live can refuse a push it has no room for.
		$media = Media_Sender::plan( $attachments );
		if ( is_wp_error( $media ) ) {
			return $media;
		}

		$row = null;
		if ( null === $client ) {
			$active = Source_Pairing::active_client( $pairing_uuid );
			if ( is_wp_error( $active ) ) {
				return $active;
			}
			$row    = $active['row'];
			$client = $active['client'];
		}

		$answer = $client->post(
			self::ROUTE,
			array(
				'push'       => (string) $plan['push_uuid'],
				'plan_hash'  => $plan_hash,
				'counts'     => $declared,
				'items'      => count( $list ),
				'files'      => (int) $media['files'],
				'file_bytes' => (int) $media['bytes'],
			),
			array( 'retries' => 3 )
		);
		if ( null !== $row ) {
			Source_Pairing::after_call( $row, $client, is_wp_error( $answer ) ? $answer : null );
		}
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}

		$live  = isset( $answer['data'] ) && is_array( $answer['data'] ) ? $answer['data'] : array();
		$limit = isset( $live['limits']['item_batch'] ) && is_int( $live['limits']['item_batch'] ) ? $live['limits']['item_batch'] : Push_Session::MAX_BATCH;
		$own   = (int) Settings::get()['item_batch'];

		$phase          = $media['files'] > 0 ? 'files' : 'items';
		$cursor         = $plan['cursor_json'];
		$cursor['push'] = array(
			'list'        => $list,
			'total'       => count( $list ),
			'declared'    => $declared,
			'phase'       => $phase,
			'media'       => $media['media'],
			'files'       => Media_Sender::cursor( $media, Media_Sender::chunk_from( isset( $live['limits'] ) ? $live['limits'] : null ) ),
			'pos'         => 0,
			'seq'         => 0,
			'pending'     => null,
			'stubs'       => $media['stubs'],
			'batch_size'  => max( 1, min( Push_Session::MAX_BATCH, $limit, $own ) ),
			'batch_bytes' => self::MAX_BATCH_BYTES,
			'counts'      => array_fill_keys( Push_Session::STATUSES, 0 ),
			'held_total'  => 0,
			'refused'     => array(),
			'held'        => array(),
			'log'         => array(),
			'steps'       => 0,
			'busy_until'  => 0,
			'wait_until'  => 0,
			'host'        => $host,
			'started'     => time(),
			'finished'    => 0,
			'live_state'  => isset( $live['state'] ) ? sanitize_key( (string) $live['state'] ) : 'open',
		);
		self::log(
			$cursor,
			'open',
			array(
				'items' => count( $list ),
				'files' => (int) $media['files'],
				'bytes' => (int) $media['bytes'],
			)
		);

		Plan_Store::update(
			(int) $plan['id'],
			array(
				'state'       => self::PHASE_STATES[ $phase ],
				'cursor_json' => $cursor,
				'error_code'  => '',
			)
		);

		Audit_Log::write(
			'push_opened',
			array(
				'actor_id'    => (int) $user_id,
				'pairing_id'  => (int) $pairing['id'],
				'object_type' => 'push',
				'object_ref'  => (string) $plan['push_uuid'],
				'details'     => array_merge(
					$declared,
					array(
						'items' => count( $list ),
						'files' => (int) $media['files'],
					)
				),
			)
		);

		return self::present( Plan_Store::find( (int) $plan['id'] ) );
	}

	/**
	 * One step: one batch sent, with its retries inside the time budget.
	 *
	 * @param int        $push_id Push (plan) ID.
	 * @param array      $args    steps (int): the step count the screen last saw.
	 * @param mixed|null $client  Client (tests).
	 * @return array|WP_Error
	 */
	public static function step( $push_id, array $args = array(), $client = null ) {
		$row = self::find( $push_id );
		if ( null === $row ) {
			return self::not_found();
		}
		if ( ! in_array( $row['state'], self::ACTIVE, true ) ) {
			return self::present( $row );
		}

		$raw    = self::raw_cursor( (int) $row['id'] );
		$cursor = json_decode( $raw, true );
		if ( ! is_array( $cursor ) || ! isset( $cursor['push'] ) ) {
			return self::not_found();
		}
		$p = $cursor['push'];
		if ( isset( $args['steps'] ) && (int) $args['steps'] !== (int) $p['steps'] ) {
			return new WP_Error( 'push_moved', __( 'The push moved on in another window or tab. The screen now shows where it is.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		$now = time();
		if ( (int) $p['busy_until'] > $now ) {
			return new WP_Error( 'push_busy', __( 'A step of this push is running in another window or tab. Wait a moment.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		if ( (int) $p['wait_until'] > $now ) {
			return self::present( $row );
		}

		// Only the request whose compare-and-swap succeeds sends; a paused push resumes its phase.
		$phase                        = isset( $p['phase'], self::PHASE_STATES[ $p['phase'] ] ) ? (string) $p['phase'] : 'items';
		$budget                       = Limits::time_budget();
		$cursor['push']['busy_until'] = $now + $budget + 15;
		$cursor['push']['steps']      = (int) $p['steps'] + 1;
		$cursor['push']['wait_until'] = 0;
		$resumed                      = 'paused' === $row['state'];
		if ( $resumed ) {
			self::log( $cursor, 'resume', array() );
		}
		if ( ! self::claim( (int) $row['id'], $raw, $cursor, self::PHASE_STATES[ $phase ] ) ) {
			return new WP_Error( 'push_moved', __( 'The push moved on in another window or tab. The screen now shows where it is.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		$pairing_row = null;
		if ( null === $client ) {
			$active = Source_Pairing::active_client( (string) $cursor['pairing'] );
			if ( is_wp_error( $active ) ) {
				return self::stop( (int) $row['id'], $cursor, 'paused', $active );
			}
			$pairing_row = $active['row'];
			$client      = $active['client'];
		}

		$deadline = microtime( true ) + max( 3, $budget - 3 );
		$state    = self::PHASE_STATES[ $phase ];
		$error    = null;
		$ctx      = Export_Context::source( (int) $row['pairing_id'] );

		if ( 'files' === $phase ) {
			// Every file first: live checks attachments against them.
			$outcome = Media_Sender::step( $row, $cursor, $client, $ctx, $deadline );
			$state   = $outcome['state'];
			$error   = $outcome['error'];
			if ( 'sending_items' === $state ) {
				$cursor['push']['phase'] = 'items';
			}
		} elseif ( 'apply' === $phase || 'review' === $phase ) {
			$outcome = self::apply_step( $row, $cursor, $client, $deadline, 'review' === $phase );
			$state   = $outcome['state'];
			$error   = $outcome['error'];
		} else {
			$links = array(
				'post' => Sync_State::linked_map( (int) $row['pairing_id'], 'post' ),
				'term' => Sync_State::linked_map( (int) $row['pairing_id'], 'term' ),
			);
			for ( $round = 0; $round < 12; $round++ ) {
				$outcome = self::batch( $row, $cursor, $client, $ctx, $links, $deadline );
				if ( 'retry' === $outcome['next'] && microtime( true ) < $deadline - 2 ) {
					continue;
				}
				$state = $outcome['state'];
				$error = $outcome['error'];
				break;
			}
		}

		if ( null !== $pairing_row ) {
			Source_Pairing::after_call( $pairing_row, $client, $error instanceof WP_Error ? $error : null );
		}

		$cursor['push']['busy_until'] = 0;
		$fields                       = array(
			'state'       => $state,
			'cursor_json' => $cursor,
			'error_code'  => $error instanceof WP_Error ? $error->get_error_code() : '',
		);
		if ( $error instanceof WP_Error ) {
			$fields['error_detail'] = $error->get_error_message();
		}
		if ( 'staged' === $state ) {
			// Every item is staged on live: the next step starts the apply.
			self::audit_end( $row, $cursor, $state );
			Media_Sender::forget( (int) $row['id'] );
			$cursor['push']['phase'] = 'apply';
			$fields['cursor_json']   = $cursor;
		}
		if ( in_array( $state, array( 'done', 'failed' ), true ) ) {
			$cursor['push']['finished'] = time();
			$fields['cursor_json']      = $cursor;
			$fields['finished_gmt']     = gmdate( 'Y-m-d H:i:s' );
			if ( 'failed' === $state ) {
				self::audit_end( $row, $cursor, $state );
			}
			Media_Sender::forget( (int) $row['id'] );
		}
		Plan_Store::update( (int) $row['id'], $fields );

		return self::present( Plan_Store::find( (int) $row['id'] ) );
	}

	/**
	 * Steps until the push leaves sending (WP-CLI), sleeping through any Retry-After wait.
	 *
	 * @param int        $push_id Push ID.
	 * @param mixed|null $client  Client.
	 * @param int        $max     Most steps.
	 * @return array|WP_Error
	 */
	public static function run( $push_id, $client = null, $max = 500 ) {
		$push = self::present( self::find( $push_id ) );
		// Calling this on a paused push is the resume (the pause message names this command): one
		// step resumes it; if it pauses again, the loop below stops instead of retrying.
		$resume = isset( $push['state'] ) && 'paused' === $push['state'];
		for ( $i = 0; $i < $max; $i++ ) {
			if ( $resume ) {
				$resume = false;
			} elseif ( ! isset( $push['state'] ) || ( ! in_array( $push['state'], self::PHASE_STATES, true ) && 'staged' !== $push['state'] ) || 'awaiting_review' === $push['state'] ) {
				// Stops at awaiting_review: a live administrator finishes the push on live.
				break;
			}
			if ( $push['wait'] > 0 ) {
				sleep( min( 60, (int) $push['wait'] ) );
			}
			$push = self::step( $push_id, array(), $client );
			if ( is_wp_error( $push ) ) {
				return $push;
			}
		}
		return $push;
	}

	/**
	 * Aborts a push before apply: live purges what it staged and releases its lock.
	 *
	 * @param int        $push_id Push ID.
	 * @param int        $user_id User.
	 * @param mixed|null $client  Client (tests).
	 * @return array|WP_Error
	 */
	public static function abort( $push_id, $user_id = 0, $client = null ) {
		$row = self::find( $push_id );
		if ( null === $row ) {
			return self::not_found();
		}
		if ( 'aborted' === $row['state'] ) {
			return self::present( $row );
		}
		if ( in_array( $row['state'], array( 'applying', 'done' ), true ) ) {
			// Once live writes, only its administrator can undo it (roll back on live).
			return new WP_Error( 'push_applying', __( 'Live is applying this push or has applied it. A live administrator can roll it back on the live site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		$cursor = $row['cursor_json'];
		if ( (int) $cursor['push']['busy_until'] > time() ) {
			return new WP_Error( 'push_busy', __( 'A step of this push is running in another window or tab. Wait a moment.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		$pairing_row = null;
		if ( null === $client ) {
			$active = Source_Pairing::active_client( (string) $cursor['pairing'] );
			if ( is_wp_error( $active ) ) {
				return $active;
			}
			$pairing_row = $active['row'];
			$client      = $active['client'];
		}

		$answer = $client->post( self::ROUTE . '/' . $row['push_uuid'] . '/abort', array(), array( 'retries' => 3 ) );
		if ( null !== $pairing_row ) {
			Source_Pairing::after_call( $pairing_row, $client, is_wp_error( $answer ) ? $answer : null );
		}
		// A session live no longer has (expired or never opened) has nothing left to purge.
		if ( is_wp_error( $answer ) && ! in_array( $answer->get_error_code(), array( 'push_not_found', 'push_expired' ), true ) ) {
			return $answer;
		}

		self::log( $cursor, 'abort', array() );
		$cursor['push']['finished'] = time();
		Plan_Store::update(
			(int) $row['id'],
			array(
				'state'        => 'aborted',
				'cursor_json'  => $cursor,
				'finished_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'error_code'   => '',
			)
		);
		Media_Sender::forget( (int) $row['id'] );
		Audit_Log::write(
			'push_aborted',
			array(
				'actor_id'    => (int) $user_id,
				'pairing_id'  => (int) $row['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $row['push_uuid'],
				'details'     => array(
					'reason' => 'admin',
					'sent'   => (int) $cursor['push']['pos'],
				),
			)
		);

		return self::present( Plan_Store::find( (int) $row['id'] ) );
	}

	/**
	 * A push row (a plan row that left planning), or null.
	 *
	 * @param int $push_id ID.
	 * @return array|null
	 */
	public static function find( $push_id ) {
		$row = Plan_Store::find( absint( $push_id ) );
		if ( null === $row || ! in_array( $row['state'], self::STATES, true ) || ! isset( $row['cursor_json']['push'] ) ) {
			return null;
		}
		return $row;
	}

	/**
	 * The newest push of a pairing, or null.
	 *
	 * @param int $pairing_id Pairing row ID.
	 * @return array|null
	 */
	public static function latest( $pairing_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE pairing_id = %d AND side = %s AND state IN ('sending_files','sending_items','paused','staged','applying','awaiting_review','done','failed','aborted') ORDER BY id DESC LIMIT 1", Tables::table_name( 'pushes' ), absint( $pairing_id ), Plan_Store::SIDE ) );
		return $id > 0 ? self::find( $id ) : null;
	}

	/**
	 * Recent pushes sent from this site, newest first.
	 *
	 * @param int $limit Most pushes.
	 * @return array[]
	 */
	public static function recent( $limit = 50 ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE side = %s AND state IN ('sending_files','sending_items','paused','staged','applying','awaiting_review','done','failed','aborted') ORDER BY id DESC LIMIT %d", Tables::table_name( 'pushes' ), Plan_Store::SIDE, max( 1, min( 200, absint( $limit ) ) ) ) );
		$out = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			$push = self::find( (int) $id );
			if ( null !== $push ) {
				$item = self::present( $push );
				unset( $item['log'] );
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * A push as the Send tab, the Pushes tab and WP-CLI show it.
	 *
	 * @param array|null $row Row.
	 * @return array
	 */
	public static function present( $row ) {
		if ( ! is_array( $row ) || ! isset( $row['cursor_json']['push'] ) ) {
			return array();
		}
		$p       = $row['cursor_json']['push'];
		$pairing = Source_Pairing::find( isset( $row['cursor_json']['pairing'] ) ? (string) $row['cursor_json']['pairing'] : '' );
		$admin   = is_array( $pairing ) ? Planner::live_admin( $pairing ) : '';
		$now     = time();
		$files   = isset( $p['files'] ) && is_array( $p['files'] ) ? $p['files'] : Media_Sender::cursor(
			array(
				'files' => 0,
				'bytes' => 0,
			),
			0
		);

		return array(
			'id'          => (int) $row['id'],
			'push'        => (string) $row['push_uuid'],
			'pairing'     => isset( $row['cursor_json']['pairing'] ) ? (string) $row['cursor_json']['pairing'] : '',
			'state'       => (string) $row['state'],
			'phase'       => isset( $p['phase'] ) ? (string) $p['phase'] : 'items',
			'files'       => array(
				'total'      => (int) $files['total'],
				'bytes'      => (int) $files['bytes'],
				'sent_bytes' => (int) min( (int) $files['bytes'], (int) $files['sent_bytes'] ),
				'have'       => (int) $files['have'],
				'need'       => (int) $files['need'],
				'renamed'    => (int) $files['renamed'],
				'committed'  => (int) $files['committed'],
				'refused'    => (int) $files['refused'],
				'chunk'      => (int) $files['chunk'],
				'current'    => is_array( $files['current'] ) ? $files['current'] : null,
			),
			'host'        => (string) $p['host'],
			'total'       => (int) $p['total'],
			'sent'        => (int) $p['pos'],
			'seq'         => (int) $p['seq'],
			'batch_size'  => (int) $p['batch_size'],
			'declared'    => $p['declared'],
			'counts'      => $p['counts'],
			'held_total'  => (int) $p['held_total'],
			'refused'     => array_values( $p['refused'] ),
			'held'        => array_values( $p['held'] ),
			'log'         => array_values( $p['log'] ),
			'steps'       => (int) $p['steps'],
			'busy'        => (int) $p['busy_until'] > $now,
			'wait'        => max( 0, (int) $p['wait_until'] - $now ),
			'started_at'  => (int) $p['started'],
			'finished_at' => (int) $p['finished'],
			'live_pushes' => '' === $admin ? '' : $admin . 'tools.php?page=keepmerge#pushes',
			'apply'       => isset( $p['apply'] ) && is_array( $p['apply'] ) ? $p['apply'] : null,
			'applied'     => isset( $p['applied'] ) && is_array( $p['applied'] ) ? $p['applied'] : array(),
			'problems'    => isset( $p['problems'] ) && is_array( $p['problems'] ) ? array_values( $p['problems'] ) : array(),
			'error'       => '' === (string) $row['error_code'] ? null : array(
				'code'    => (string) $row['error_code'],
				'message' => (string) $row['error_detail'],
			),
		);
	}

	/**
	 * Sends (or sends again) one batch and records the answer.
	 *
	 * @param array          $row      Push row.
	 * @param array          $cursor   Cursor (changed).
	 * @param mixed          $client   Client.
	 * @param Export_Context $ctx  Export context.
	 * @param array          $links    Linked local => live IDs per space.
	 * @param float          $deadline Deadline.
	 * @return array{next:string,state:string,error:WP_Error|null}
	 */
	private static function batch( array $row, array &$cursor, $client, Export_Context $ctx, array $links, $deadline ) {
		$p = &$cursor['push'];

		if ( null === $p['pending'] ) {
			if ( (int) $p['pos'] >= (int) $p['total'] ) {
				return self::finish( $row, $cursor, $client );
			}
			$built        = self::build( $row, $cursor, (int) $p['pos'], null, $ctx, $links );
			$p['pending'] = array(
				'seq'  => (int) $p['seq'] + 1,
				'from' => (int) $p['pos'],
				'to'   => (int) $p['pos'] + count( $built['items'] ),
			);
			// Stored before sending, so a step that dies resends exactly this batch.
			Plan_Store::update( (int) $row['id'], array( 'cursor_json' => $cursor ) );
		} else {
			$built = self::build( $row, $cursor, (int) $p['pending']['from'], (int) $p['pending']['to'], $ctx, $links );
		}

		$pending = $p['pending'];
		$budget  = (int) max( 1, floor( $deadline - microtime( true ) ) );
		$answer  = $client->post(
			self::ROUTE . '/' . $row['push_uuid'] . '/items',
			array(
				'seq'   => (int) $pending['seq'],
				'items' => $built['items'],
			),
			array(
				'retries' => 5,
				'budget'  => $budget,
				'timeout' => 30,
			)
		);

		if ( ! is_wp_error( $answer ) ) {
			return self::acknowledge( $row, $cursor, $answer['data'], $built['rows'] );
		}

		$data   = (array) $answer->get_error_data();
		$status = isset( $data['remote_status'] ) ? (int) $data['remote_status'] : 0;
		$code   = $answer->get_error_code();

		$busy = 503 === $status && isset( $data['remote_code'] ) && 'server_busy' === $data['remote_code'];
		if ( $busy && (int) $pending['to'] - (int) $pending['from'] > 1 ) {
			// Live stopped the batch at its time or memory limit: smaller batches from here.
			$p['batch_size']  = max( 1, intdiv( (int) $pending['to'] - (int) $pending['from'], 2 ) );
			$p['batch_bytes'] = max( self::MIN_BATCH_BYTES, intdiv( (int) $p['batch_bytes'], 2 ) );
			$p['pending']     = null;
			self::log( $cursor, 'halved', array( 'batch' => $p['batch_size'] ) );
			return self::next( 'retry' );
		}

		if ( 413 === $status ) {
			$size = (int) $pending['to'] - (int) $pending['from'];
			if ( $size > 1 ) {
				$p['batch_size']  = max( 1, intdiv( $size, 2 ) );
				$p['batch_bytes'] = max( self::MIN_BATCH_BYTES, intdiv( (int) $p['batch_bytes'], 2 ) );
				$p['pending']     = null;
				self::log( $cursor, 'halved', array( 'batch' => $p['batch_size'] ) );
			} else {
				// One item alone is over live's limit: it goes as a refusal stub.
				$uuid                = (string) $p['list'][ (int) $pending['from'] ][0];
				$p['stubs'][ $uuid ] = 'too_large';
				self::log( $cursor, 'too_large', array( 'seq' => (int) $pending['seq'] ) );
			}
			return self::next( 'retry' );
		}

		if ( 'rate_limited' === $code || 'ip_blocked' === $code ) {
			$wait = isset( $data['retry_after'] ) ? max( 1, (int) $data['retry_after'] ) : 60;
			self::log(
				$cursor,
				'wait',
				array(
					'code'    => $code,
					'seconds' => $wait,
				)
			);
			if ( microtime( true ) + $wait < $deadline - 2 ) {
				sleep( $wait );
				return self::next( 'retry' );
			}
			$p['wait_until'] = time() + $wait;
			return self::next( 'stop', 'sending_items' );
		}

		if ( 'batch_mismatch' === $code ) {
			// Live has a batch with this number and other items: ask live where it is.
			$status_answer = $client->get( self::ROUTE . '/' . $row['push_uuid'], array(), array( 'retries' => 2 ) );
			if ( ! is_wp_error( $status_answer ) && isset( $status_answer['data']['last_seq'] ) && (int) $status_answer['data']['last_seq'] >= (int) $pending['seq'] ) {
				$p['pos']     = (int) $pending['to'];
				$p['seq']     = (int) $pending['seq'];
				$p['pending'] = null;
				self::log( $cursor, 'reconciled', array( 'seq' => (int) $pending['seq'] ) );
				return self::next( 'stop', 'sending_items' );
			}
		}

		self::log(
			$cursor,
			in_array( $code, self::PAUSE_CODES, true ) ? 'paused' : 'failed',
			array( 'code' => $code )
		);
		return self::next( 'stop', in_array( $code, self::PAUSE_CODES, true ) ? 'paused' : 'failed', $answer );
	}

	/**
	 * One step of the apply phase: calls live's apply route and records the item results, or, while
	 * live reviews held items, only asks live's state until the push is applied.
	 *
	 * @param array $row      Push row.
	 * @param array $cursor   Cursor (changed).
	 * @param mixed $client   Client.
	 * @param float $deadline Deadline.
	 * @param bool  $review   Whether the push waits for review on live.
	 * @return array{next:string,state:string,error:WP_Error|null}
	 */
	private static function apply_step( array $row, array &$cursor, $client, $deadline, $review ) {
		$p = &$cursor['push'];

		if ( $review ) {
			$answer = $client->get( self::ROUTE . '/' . $row['push_uuid'], array(), array( 'retries' => 2 ) );
			if ( is_wp_error( $answer ) ) {
				$code = $answer->get_error_code();
				return self::next( 'stop', in_array( $code, self::PAUSE_CODES, true ) ? 'awaiting_review' : 'failed', $answer );
			}
			$live            = isset( $answer['data']['state'] ) ? sanitize_key( (string) $answer['data']['state'] ) : '';
			$p['live_state'] = $live;
			if ( in_array( $live, array( 'rolling_back', 'rolled_back' ), true ) ) {
				self::log( $cursor, 'failed', array( 'code' => 'push_' . $live ) );
				return self::next( 'stop', 'failed', new WP_Error( 'push_' . $live, Remote_Client::message( 'push_' . $live ) ) );
			}
			if ( 'applied' !== $live ) {
				// A live administrator is reviewing, applying or deciding after a failure.
				$waiting = in_array( $live, array( 'failed_partial', 'interrupted' ), true ) ? new WP_Error( 'push_' . $live, Remote_Client::message( 'push_' . $live ) ) : null;
				return self::next( 'stop', 'awaiting_review', $waiting );
			}
			$p['phase'] = 'apply';
			self::log( $cursor, 'reviewed', array( 'state' => $live ) );
		}

		for ( $round = 0; $round < 20; $round++ ) {
			if ( $round > 0 && $deadline - microtime( true ) < Limits::remote_wait() ) {
				// Too little time left to wait for live's step: the next step asks (the first call of
				// a step always goes out, so a short budget never stops the push).
				break;
			}
			$answer = $client->post(
				self::ROUTE . '/' . $row['push_uuid'] . '/apply',
				array( 'after' => isset( $p['apply_after'] ) ? (int) $p['apply_after'] : 0 ),
				array(
					'retries' => 3,
					'budget'  => (int) max( 1, floor( $deadline - microtime( true ) ) ),
					'timeout' => 30,
				)
			);
			if ( is_wp_error( $answer ) ) {
				$code = $answer->get_error_code();
				if ( 'push_busy' === $code ) {
					// Another step of this push is running on live: the next step asks again.
					return self::next( 'stop', 'applying' );
				}
				self::log( $cursor, in_array( $code, self::PAUSE_CODES, true ) ? 'paused' : 'failed', array( 'code' => $code ) );
				return self::next( 'stop', in_array( $code, self::PAUSE_CODES, true ) ? 'paused' : 'failed', $answer );
			}

			$data = isset( $answer['data'] ) && is_array( $answer['data'] ) ? $answer['data'] : array();
			foreach ( isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array() as $result ) {
				self::record_result( $row, $cursor, $result );
			}
			$live             = isset( $data['state'] ) ? sanitize_key( (string) $data['state'] ) : '';
			$p['live_state']  = $live;
			$p['apply_after'] = isset( $data['next'] ) ? (int) $data['next'] : ( isset( $p['apply_after'] ) ? (int) $p['apply_after'] : 0 );
			$p['apply']       = array(
				'state'     => $live,
				'pass'      => isset( $data['apply']['pass'] ) ? sanitize_key( (string) $data['apply']['pass'] ) : '',
				'done'      => isset( $data['apply']['done'] ) ? (int) $data['apply']['done'] : 0,
				'total'     => isset( $data['apply']['total'] ) ? (int) $data['apply']['total'] : 0,
				'held'      => isset( $data['held'] ) ? (int) $data['held'] : 0,
				'undecided' => isset( $data['undecided'] ) ? (int) $data['undecided'] : 0,
			);

			if ( 'awaiting_review' === $live ) {
				$p['phase'] = 'review';
				self::log( $cursor, 'awaiting_review', array( 'held' => (int) $p['apply']['held'] ) );
				return self::next( 'stop', 'awaiting_review' );
			}
			if ( in_array( $live, array( 'failed_partial', 'interrupted' ), true ) ) {
				// A live administrator decides on live; "Check status" collects the results later.
				$p['phase'] = 'review';
				self::log( $cursor, 'failed', array( 'code' => 'push_' . $live ) );
				return self::next( 'stop', 'awaiting_review', new WP_Error( 'push_' . $live, Remote_Client::message( 'push_' . $live ) ) );
			}
			if ( in_array( $live, array( 'rolling_back', 'rolled_back' ), true ) ) {
				self::log( $cursor, 'failed', array( 'code' => 'push_' . $live ) );
				return self::next( 'stop', 'failed', new WP_Error( 'push_' . $live, Remote_Client::message( 'push_' . $live ) ) );
			}
			if ( 'applied' === $live ) {
				if ( ! empty( $data['more'] ) && microtime( true ) < $deadline - 1 ) {
					continue;
				}
				if ( ! empty( $data['more'] ) ) {
					return self::next( 'stop', 'applying' );
				}
				self::log( $cursor, 'done', isset( $p['applied'] ) && is_array( $p['applied'] ) ? $p['applied'] : array() );
				Audit_Log::write(
					'push_applied',
					array(
						'pairing_id'  => (int) $row['pairing_id'],
						'object_type' => 'push',
						'object_ref'  => (string) $row['push_uuid'],
						'details'     => isset( $p['applied'] ) && is_array( $p['applied'] ) ? $p['applied'] : array(),
					)
				);
				return self::next( 'stop', 'done' );
			}
			if ( microtime( true ) > $deadline - 2 ) {
				break;
			}
		}
		return self::next( 'stop', 'applying' );
	}

	/**
	 * Records one item result of the apply; an applied item's live ID becomes this site's link.
	 *
	 * @param array $row    Push row.
	 * @param array $cursor Cursor (changed).
	 * @param mixed $result Result as live sent it.
	 * @return void
	 */
	private static function record_result( array $row, array &$cursor, $result ) {
		if ( ! is_array( $result ) || ! isset( $result['uuid'], $result['status'] ) || ! is_string( $result['uuid'] ) || ! is_string( $result['status'] ) ) {
			return;
		}
		$plan = Plan_Store::item( (int) $row['id'], $result['uuid'] );
		if ( null === $plan || isset( $plan['payload']['applied'] ) ) {
			return;
		}
		$status  = sanitize_key( $result['status'] );
		$live_id = isset( $result['live_id'] ) && is_int( $result['live_id'] ) ? max( 0, $result['live_id'] ) : 0;
		$reason  = isset( $result['reason'] ) && is_string( $result['reason'] ) ? sanitize_key( $result['reason'] ) : '';

		$payload            = $plan['payload'];
		$payload['applied'] = array(
			'status'  => $status,
			'live_id' => $live_id,
			'reason'  => $reason,
		);
		Plan_Store::update_item( (int) $plan['id'], array( 'payload' => $payload ) );

		$p = &$cursor['push'];
		if ( ! isset( $p['applied'] ) || ! is_array( $p['applied'] ) ) {
			$p['applied'] = array();
		}
		$p['applied'][ $status ] = ( isset( $p['applied'][ $status ] ) ? (int) $p['applied'][ $status ] : 0 ) + 1;
		if ( 'applied' !== $status && ( ! isset( $p['problems'] ) || count( $p['problems'] ) < self::SAMPLES ) ) {
			$p['problems'][ $result['uuid'] ] = array(
				'uuid'   => (string) $result['uuid'],
				'title'  => isset( $plan['payload']['title'] ) ? (string) $plan['payload']['title'] : '',
				'kind'   => (string) $plan['object_type'],
				'status' => $status,
				'reason' => $reason,
			);
		}

		$kind  = (string) $plan['object_type'];
		$space = \SoftGlaze\SafePush\Sync\Scope::space( $kind );
		if ( 'applied' === $status && '' !== $space && $live_id > 0 && (int) $plan['local_object_id'] > 0 ) {
			Sync_State::upsert( (int) $row['pairing_id'], (string) $result['uuid'], $kind, (int) $plan['local_object_id'], $live_id, 'pushed' );
		}
	}

	/**
	 * Records live's answer to a batch.
	 *
	 * @param array $row    Push row.
	 * @param array $cursor Cursor (changed).
	 * @param array $data   Answer.
	 * @param array $rows   Plan rows of the batch, by UUID.
	 * @return array
	 */
	private static function acknowledge( array $row, array &$cursor, array $data, array $rows ) {
		$p       = &$cursor['push'];
		$pending = $p['pending'];
		$results = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();
		$batch   = array_fill_keys( Push_Session::STATUSES, 0 );

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || ! isset( $result['uuid'], $result['status'], $rows[ $result['uuid'] ] ) || ! is_string( $result['status'] ) ) {
				continue;
			}
			$status = sanitize_key( $result['status'] );
			$plan   = $rows[ $result['uuid'] ];
			$live   = array(
				'status' => $status,
				'reason' => isset( $result['reason'] ) && is_string( $result['reason'] ) ? sanitize_key( $result['reason'] ) : '',
				'field'  => isset( $result['field'] ) && is_string( $result['field'] ) ? substr( sanitize_text_field( $result['field'] ), 0, 64 ) : '',
				'held'   => isset( $result['held'] ) ? (int) $result['held'] : 0,
			);
			// Each item counts once, also when a replayed batch is answered again.
			if ( ! isset( $plan['payload']['live'] ) && isset( $batch[ $status ] ) ) {
				++$batch[ $status ];
				++$p['counts'][ $status ];
			}
			$sample = array(
				'uuid'   => (string) $result['uuid'],
				'title'  => isset( $plan['payload']['title'] ) ? (string) $plan['payload']['title'] : '',
				'kind'   => (string) $plan['object_type'],
				'reason' => $live['reason'],
				'field'  => $live['field'],
			);
			if ( 'refused' === $status && count( $p['refused'] ) < self::SAMPLES && ! isset( $p['refused'][ $sample['uuid'] ] ) ) {
				$p['refused'][ $sample['uuid'] ] = $sample;
			}
			if ( 'held' === $status && count( $p['held'] ) < self::SAMPLES && ! isset( $p['held'][ $sample['uuid'] ] ) ) {
				$p['held'][ $sample['uuid'] ] = $sample;
			}
			$payload         = $plan['payload'];
			$payload['live'] = $live;
			Plan_Store::update_item( (int) $plan['id'], array( 'payload' => $payload ) );
		}

		$session         = isset( $data['session'] ) && is_array( $data['session'] ) ? $data['session'] : array();
		$p['pos']        = (int) $pending['to'];
		$p['seq']        = (int) $pending['seq'];
		$p['pending']    = null;
		$p['held_total'] = isset( $data['held_total'] ) ? (int) $data['held_total'] : (int) $p['held_total'];
		$p['live_state'] = isset( $session['state'] ) ? sanitize_key( (string) $session['state'] ) : $p['live_state'];

		self::log(
			$cursor,
			'batch',
			array_merge(
				array(
					'seq'    => (int) $pending['seq'],
					'items'  => count( $results ),
					'replay' => ! empty( $data['replay'] ),
				),
				$batch
			)
		);

		if ( 'staged' === $p['live_state'] ) {
			self::log( $cursor, 'staged', $p['counts'] );
			return self::next( 'stop', 'staged' );
		}
		if ( 'failed' === $p['live_state'] ) {
			return self::next( 'stop', 'failed', self::live_failure( $session ) );
		}
		return self::next( 'stop', 'sending_items' );
	}

	/**
	 * Every batch was acknowledged: live's session must say staged.
	 *
	 * @param array $row    Push row.
	 * @param array $cursor Cursor (changed).
	 * @param mixed $client Client.
	 * @return array
	 */
	private static function finish( array $row, array &$cursor, $client ) {
		$answer = $client->get( self::ROUTE . '/' . $row['push_uuid'], array(), array( 'retries' => 2 ) );
		if ( is_wp_error( $answer ) ) {
			$code = $answer->get_error_code();
			return self::next( 'stop', in_array( $code, self::PAUSE_CODES, true ) ? 'paused' : 'failed', $answer );
		}
		$session                      = $answer['data'];
		$cursor['push']['live_state'] = isset( $session['state'] ) ? sanitize_key( (string) $session['state'] ) : '';
		if ( 'staged' === $cursor['push']['live_state'] ) {
			self::log( $cursor, 'staged', $cursor['push']['counts'] );
			return self::next( 'stop', 'staged' );
		}
		return self::next( 'stop', 'failed', 'failed' === $cursor['push']['live_state'] ? self::live_failure( $session ) : new WP_Error( 'count_mismatch', __( 'Live did not receive every item of this push. Abort it and push again.', 'keepmerge-push-staging-to-live' ) ) );
	}

	/**
	 * The envelopes of a batch: from a position, as many as fit (or exactly a recorded range).
	 *
	 * @param array          $row    Push row.
	 * @param array          $cursor Cursor.
	 * @param int            $from   First position.
	 * @param int|null       $to     End position of a recorded range, or null to fill a new batch.
	 * @param Export_Context $ctx    Context.
	 * @param array          $links  Links.
	 * @return array{items:array,rows:array}
	 */
	private static function build( array $row, array $cursor, $from, $to, Export_Context $ctx, array $links ) {
		$p     = $cursor['push'];
		$items = array();
		$rows  = array();
		$bytes = 0;
		$end   = null === $to ? (int) $p['total'] : (int) $to;

		for ( $i = $from; $i < $end; $i++ ) {
			if ( null === $to && count( $items ) >= (int) $p['batch_size'] ) {
				break;
			}
			list( $uuid, $action ) = $p['list'][ $i ];
			$plan_row              = Plan_Store::item( (int) $row['id'], $uuid );
			$envelope              = self::envelope( $plan_row, $uuid, $action, $p['stubs'], $ctx, $links );
			$size                  = strlen( (string) wp_json_encode( $envelope ) );
			if ( $size > (int) $p['batch_bytes'] ) {
				$envelope = self::stub( $envelope, 'too_large' );
				$size     = strlen( (string) wp_json_encode( $envelope ) );
			}
			if ( null === $to && ! empty( $items ) && $bytes + $size > (int) $p['batch_bytes'] ) {
				break;
			}
			$items[] = $envelope;
			$bytes  += $size;
			if ( null !== $plan_row ) {
				$rows[ $uuid ] = $plan_row;
			}
		}

		return array(
			'items' => $items,
			'rows'  => $rows,
		);
	}

	/**
	 * One item envelope: identity and fresh export, or a stub that says why the item is not sent.
	 *
	 * @param array|null     $plan_row Plan row.
	 * @param string         $uuid     UUID.
	 * @param string         $action   Plan action.
	 * @param array          $stubs    uuid => stub reason.
	 * @param Export_Context $ctx      Context.
	 * @param array          $links    Links.
	 * @return array
	 */
	private static function envelope( $plan_row, $uuid, $action, array $stubs, Export_Context $ctx, array $links ) {
		$p    = is_array( $plan_row ) ? $plan_row['payload'] : array();
		$kind = is_array( $plan_row ) ? (string) $plan_row['object_type'] : '';
		$hint = isset( $p['confidence'], $p['remote_id'] ) && in_array( $p['confidence'], array( 'strong', 'medium', 'pushed' ), true ) ? (int) $p['remote_id'] : 0;

		$envelope = array(
			'uuid'    => $uuid,
			'kind'    => $kind,
			'type'    => isset( $p['type'] ) ? (string) $p['type'] : '',
			'action'  => $action,
			'hash'    => isset( $p['s'] ) ? (string) $p['s'] : '',
			'live_id' => $hint,
		);
		if ( 'option' === $kind ) {
			$envelope['type'] = substr( $uuid, strlen( 'option:' ) );
		} elseif ( 'widgets' === $kind ) {
			$envelope['type'] = 'widgets';
		}

		if ( ! is_array( $plan_row ) ) {
			return self::stub( $envelope, 'not_exportable' );
		}
		if ( isset( $stubs[ $uuid ] ) ) {
			return self::stub( $envelope, (string) $stubs[ $uuid ] );
		}
		// Live's hash when the plan was made: live skips the item if it changed since.
		if ( isset( $p['l'] ) && is_string( $p['l'] ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $p['l'] ) && in_array( $action, array( 'push', 'use_staging', 'trash_on_live', 'record_base' ), true ) ) {
			$envelope['live_hash'] = $p['l'];
		}
		if ( in_array( $action, array( 'trash_on_live', 'record_base' ), true ) ) {
			return $envelope;
		}

		$ref = 'option' === $kind ? $envelope['type'] : ( 'widgets' === $kind ? 'widgets' : (int) $plan_row['local_object_id'] );
		$ctx->normalizer->reset();
		$item = Items::export( $kind, $ref, $ctx );
		if ( null === $item || ! empty( $item['unsupported'] ) ) {
			return self::stub( $envelope, 'not_exportable' );
		}
		// Only what the admin reviewed is sent: an item changed since the plan waits for a new one.
		if ( ! hash_equals( (string) $envelope['hash'], (string) $item['hash'] ) ) {
			return self::stub( $envelope, 'changed_since_plan' );
		}

		$refs = array();
		foreach ( $item['refs'] as $one ) {
			$space  = isset( Reference_Mapper::KIND_SPACE[ $one['kind'] ] ) ? Reference_Mapper::KIND_SPACE[ $one['kind'] ] : 'post';
			$refs[] = array(
				'path'    => (string) $one['path'],
				'kind'    => (string) $one['kind'],
				'id'      => (int) $one['id'],
				'uuid'    => (string) $one['uuid'],
				'live_id' => isset( $links[ $space ][ (int) $one['id'] ] ) ? (int) $links[ $space ][ (int) $one['id'] ] : 0,
			);
		}
		$envelope['data']  = $item['data'];
		$envelope['refs']  = $refs;
		$envelope['scope'] = self::scope( $kind, $envelope['type'] );
		if ( 'menu' === $kind ) {
			// The source IDs of the menu's items in item order: live resolves parents inside the unit.
			$envelope['item_ids'] = array_map(
				static function ( $post ) {
					return (int) $post->ID;
				},
				\SoftGlaze\SafePush\Sync\Exporters\Menus::item_posts( (int) $plan_row['local_object_id'] )
			);
		}

		if ( 'attachment' === $kind ) {
			// Which file rows of this push hold the attachment's files (sent before the items).
			$envelope['media'] = Media_Sender::media_map( (int) $plan_row['push_id'], isset( $item['data']['files'] ) ? $item['data']['files'] : array() );
		}

		if ( 'product' === $kind && isset( $p['groups'] ) && is_array( $p['groups'] ) ) {
			$groups = array();
			foreach ( Item_Stager::GROUP_NAMES as $name ) {
				if ( isset( $p['groups'][ $name ] ) && is_array( $p['groups'][ $name ] ) ) {
					$group           = $p['groups'][ $name ];
					$groups[ $name ] = ! empty( $group['decision'] ) ? (string) $group['decision'] : (string) $group['action'];
				}
			}
			$envelope['groups']       = $groups;
			$envelope['group_hashes'] = $item['groups'];
		}

		return $envelope;
	}

	/**
	 * The taxonomies and excluded meta this site manages, so live never clears what it does not send.
	 *
	 * @param string $kind Kind.
	 * @param string $type Post type.
	 * @return array
	 */
	private static function scope( $kind, $type ) {
		$settings = Settings::get();
		$out      = array(
			'meta_skip' => array_values( array_filter( is_array( $settings['excluded_meta'] ) ? $settings['excluded_meta'] : array(), 'is_string' ) ),
		);
		$taxes    = array();
		if ( 'post' === $kind && '' !== $type ) {
			$taxes = get_object_taxonomies( $type );
		} elseif ( 'pattern' === $kind ) {
			$taxes = array( 'wp_pattern_category' );
		} elseif ( 'product' === $kind ) {
			$taxes = array( 'product_cat', 'product_tag', 'product_brand' );
		}
		if ( ! empty( $taxes ) ) {
			$out['taxonomies'] = array_values( array_intersect( $taxes, \SoftGlaze\SafePush\Sync\Scope::taxonomies() ) );
		}
		return $out;
	}

	/**
	 * A stub: the item's identity and why it is not sent.
	 *
	 * @param array  $envelope Envelope.
	 * @param string $reason   Reason (Item_Stager::SKIPS).
	 * @return array
	 */
	private static function stub( array $envelope, $reason ) {
		return array(
			'uuid'    => $envelope['uuid'],
			'kind'    => $envelope['kind'],
			'type'    => $envelope['type'],
			'action'  => $envelope['action'],
			'hash'    => $envelope['hash'],
			'live_id' => $envelope['live_id'],
			'skip'    => $reason,
		);
	}

	/**
	 * Takes a step: writes the claimed cursor only if nobody changed it since it was read.
	 *
	 * @param int    $id     Push ID.
	 * @param string $raw    Cursor as read.
	 * @param array  $cursor Claimed cursor.
	 * @param string $state  State of the phase the step runs (sending_files or sending_items).
	 * @return bool
	 */
	private static function claim( $id, $raw, array $cursor, $state ) {
		global $wpdb;

		$state = in_array( $state, self::PHASE_STATES, true ) ? $state : 'sending_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; compare-and-swap on the cursor so one step runs at a time.
		$updated = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET cursor_json = %s, state = %s WHERE id = %d AND side = %s AND cursor_json = %s', Tables::table_name( 'pushes' ), (string) wp_json_encode( $cursor ), $state, absint( $id ), Plan_Store::SIDE, (string) $raw ) );
		return 1 === (int) $updated;
	}

	/**
	 * The cursor as stored.
	 *
	 * @param int $id Push ID.
	 * @return string
	 */
	private static function raw_cursor( $id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the exact stored text for the compare-and-swap.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT cursor_json FROM %i WHERE id = %d AND side = %s', Tables::table_name( 'pushes' ), absint( $id ), Plan_Store::SIDE ) );
	}

	/**
	 * Ends a step that could not start (pairing unusable).
	 *
	 * @param int      $id     Push ID.
	 * @param array    $cursor Cursor.
	 * @param string   $state  State.
	 * @param WP_Error $error  Error.
	 * @return WP_Error
	 */
	private static function stop( $id, array $cursor, $state, WP_Error $error ) {
		$cursor['push']['busy_until'] = 0;
		self::log( $cursor, 'paused', array( 'code' => $error->get_error_code() ) );
		Plan_Store::update(
			$id,
			array(
				'state'        => $state,
				'cursor_json'  => $cursor,
				'error_code'   => $error->get_error_code(),
				'error_detail' => $error->get_error_message(),
			)
		);
		return $error;
	}

	/**
	 * The error of a session live marked failed.
	 *
	 * @param array $session Session as live presented it.
	 * @return WP_Error
	 */
	private static function live_failure( array $session ) {
		$code = isset( $session['error'] ) && is_string( $session['error'] ) && '' !== $session['error'] ? sanitize_key( $session['error'] ) : 'push_failed';
		return new WP_Error( $code, __( 'Live checked the items it received against the plan and they did not match, so it set the push aside. Plan again and push again.', 'keepmerge-push-staging-to-live' ) );
	}

	/**
	 * Audit row at the end of a push on this site.
	 *
	 * @param array  $row    Push row.
	 * @param array  $cursor Cursor.
	 * @param string $state  staged or failed.
	 * @return void
	 */
	private static function audit_end( array $row, array $cursor, $state ) {
		Audit_Log::write(
			'staged' === $state ? 'items_staged' : 'push_failed',
			array(
				'result'      => 'staged' === $state ? 'ok' : 'error',
				'pairing_id'  => (int) $row['pairing_id'],
				'object_type' => 'push',
				'object_ref'  => (string) $row['push_uuid'],
				'details'     => array_merge(
					$cursor['push']['counts'],
					array(
						'items'    => (int) $cursor['push']['total'],
						'complete' => 'staged' === $state,
					)
				),
			)
		);
	}

	/**
	 * Adds an entry to the push's step log (kept short).
	 *
	 * @param array  $cursor Cursor (changed).
	 * @param string $event  open, batch, halved, too_large, wait, reconciled, paused, failed, resume, staged, abort.
	 * @param array  $facts  Counts and codes.
	 * @return void
	 */
	private static function log( array &$cursor, $event, array $facts ) {
		$cursor['push']['log'][] = array_merge(
			array(
				't'     => time(),
				'event' => $event,
			),
			$facts
		);
		if ( count( $cursor['push']['log'] ) > self::LOG_MAX ) {
			$cursor['push']['log'] = array_slice( $cursor['push']['log'], -self::LOG_MAX );
		}
	}

	/**
	 * The outcome of a batch.
	 *
	 * @param string        $next  retry or stop.
	 * @param string        $state State after the step.
	 * @param WP_Error|null $error Error.
	 * @return array
	 */
	private static function next( $next, $state = 'sending_items', $error = null ) {
		return array(
			'next'  => $next,
			'state' => $state,
			'error' => $error,
		);
	}

	/**
	 * The live site's host name, lower case.
	 *
	 * @param array $pairing Source pairing row.
	 * @return string
	 */
	private static function host( array $pairing ) {
		$host = wp_parse_url( isset( $pairing['remote_url'] ) ? (string) $pairing['remote_url'] : '', PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * The not-found error.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'push_not_found', __( 'This push does not exist on this site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
	}
}
