<?php
/**
 * Admin REST routes for pushes.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Rest;

use SoftGlaze\SafePush\Apply\Applier;
use SoftGlaze\SafePush\Apply\Recovery;
use SoftGlaze\SafePush\Apply\Review;
use SoftGlaze\SafePush\Apply\Rollback_Runner;
use SoftGlaze\SafePush\Plugin;
use SoftGlaze\SafePush\Transfer\Push_Runner;
use SoftGlaze\SafePush\Transfer\Push_Session;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes under keepmerge/v1/admin/pushes (threat model §5.2): start a push with the live domain typed and
 * checked here (†), run it step by step, abort it before apply, and the history of both sides. On
 * the destination: a received push in detail, the decisions on its held items, and "Continue
 * apply on this site" (†, blueprint §10.5), and recovery: rollback preview and rollback (†), resume or
 * retry (†), skip the failed item (†), release a stale lock (†). There is no remote rollback. Every
 * route needs manage_options and the wp_rest nonce; every input is read by name.
 */
final class Push_Controller {

	const BASE = '/admin/pushes';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns         = Plugin::REST_NAMESPACE;
		$permission = array( $this, 'permission' );

		register_rest_route(
			$ns,
			self::BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_pushes' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start' ),
					'permission_callback' => $permission,
					'args'                => array(
						'plan'    => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'confirm' => array(
							'type'      => 'string',
							'maxLength' => 253,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/(?P<id>[0-9]{1,18})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_push' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/(?P<id>[0-9]{1,18})/step',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'step' ),
				'permission_callback' => $permission,
				'args'                => array(
					'steps' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/(?P<id>[0-9]{1,18})/held',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'held' ),
				'permission_callback' => $permission,
				'args'                => array(
					'decisions' => array(
						'type' => 'object',
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/(?P<id>[0-9]{1,18})/continue',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'continue_apply' ),
				'permission_callback' => $permission,
				'args'                => array(
					'confirm' => array(
						'type'      => 'string',
						'maxLength' => 253,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/(?P<id>[0-9]{1,18})/abort',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'abort' ),
				'permission_callback' => $permission,
			)
		);

		$confirm = array(
			'type'      => 'string',
			'maxLength' => 253,
		);

		register_rest_route(
			$ns,
			self::BASE . '/(?P<id>[0-9]{1,18})/rollback-preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rollback_preview' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/(?P<id>[0-9]{1,18})/rollback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rollback' ),
				'permission_callback' => $permission,
				'args'                => array(
					'confirm' => $confirm,
					'include' => array(
						'type'     => 'array',
						'maxItems' => 5000,
						'items'    => array(
							'type'      => 'string',
							'maxLength' => 191,
						),
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/(?P<id>[0-9]{1,18})/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resume' ),
				'permission_callback' => $permission,
				'args'                => array( 'confirm' => $confirm ),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/(?P<id>[0-9]{1,18})/skip-item',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'skip_item' ),
				'permission_callback' => $permission,
				'args'                => array(
					'confirm' => $confirm,
					'uuid'    => array(
						'type'      => 'string',
						'maxLength' => 191,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/lock/release',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'release_lock' ),
				'permission_callback' => $permission,
				'args'                => array( 'confirm' => $confirm ),
			)
		);
	}

	/**
	 * GET /admin/pushes/{id}/rollback-preview: what a rollback of a push received here would do.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rollback_preview( WP_REST_Request $request ) {
		$session = Push_Session::find_by_id( absint( $request->get_param( 'id' ) ) );
		if ( null === $session ) {
			return self::not_found();
		}
		return rest_ensure_response( Rollback_Runner::preview( $session ) );
	}

	/**
	 * POST /admin/pushes/{id}/rollback †: one time-boxed step of a rollback, from this
	 * administrator's session (there is no remote rollback). The first call starts it with the
	 * conflicts the admin ticked.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rollback( WP_REST_Request $request ) {
		$typed = self::confirmed( $request, __( 'roll back', 'keepmerge-push-staging-to-live' ) );
		if ( is_wp_error( $typed ) ) {
			return $typed;
		}
		$session = Push_Session::find_by_id( absint( $request->get_param( 'id' ) ) );
		if ( null === $session ) {
			return self::not_found();
		}
		$include = array();
		$raw     = $request->get_param( 'include' );
		foreach ( is_array( $raw ) ? $raw : array() as $uuid ) {
			if ( is_string( $uuid ) && '' !== $uuid ) {
				$include[] = sanitize_text_field( $uuid );
			}
		}
		$result = Rollback_Runner::run( $session, array_values( array_unique( $include ) ), get_current_user_id() );
		return self::with_detail( $session, $result );
	}

	/**
	 * POST /admin/pushes/{id}/resume †: resumes an interrupted apply, or retries the item a push
	 * stopped at (failed_partial), from this administrator's session; then one step runs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resume( WP_REST_Request $request ) {
		$typed = self::confirmed( $request, __( 'resume', 'keepmerge-push-staging-to-live' ) );
		if ( is_wp_error( $typed ) ) {
			return $typed;
		}
		$session = Push_Session::find_by_id( absint( $request->get_param( 'id' ) ) );
		if ( null === $session ) {
			return self::not_found();
		}
		$user   = get_current_user_id();
		$result = 'failed_partial' === $session['state'] ? Recovery::retry( $session, $user ) : Recovery::resume( $session, 'user', $user );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = in_array( $result['state'], Applier::RUNNING, true ) ? Applier::admin_continue( $result, $user ) : Applier::answer( $result, 0 );
		return self::with_detail( $session, $result );
	}

	/**
	 * POST /admin/pushes/{id}/skip-item †: skips the item a push stopped at (it is put back as it
	 * was) and the apply goes on with one step.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function skip_item( WP_REST_Request $request ) {
		$typed = self::confirmed( $request, __( 'skip the item', 'keepmerge-push-staging-to-live' ) );
		if ( is_wp_error( $typed ) ) {
			return $typed;
		}
		$session = Push_Session::find_by_id( absint( $request->get_param( 'id' ) ) );
		if ( null === $session ) {
			return self::not_found();
		}
		$uuid   = $request->get_param( 'uuid' );
		$user   = get_current_user_id();
		$result = Recovery::skip_item( $session, is_string( $uuid ) ? sanitize_text_field( $uuid ) : '', $user );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result = in_array( $result['state'], Applier::RUNNING, true ) ? Applier::admin_continue( $result, $user ) : Applier::answer( $result, 0 );
		return self::with_detail( $session, $result );
	}

	/**
	 * POST /admin/pushes/lock/release †: releases a stale push lock (or one whose push no longer
	 * runs).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function release_lock( WP_REST_Request $request ) {
		$typed = self::confirmed( $request, __( 'release the lock', 'keepmerge-push-staging-to-live' ) );
		if ( is_wp_error( $typed ) ) {
			return $typed;
		}
		$result = Recovery::release_lock( get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * The typed domain of a destructive action on this site (†): it must be this site's host.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $action  What the admin confirms, for the message.
	 * @return true|WP_Error
	 */
	private static function confirmed( WP_REST_Request $request, $action ) {
		$confirm = $request->get_param( 'confirm' );
		$typed   = strtolower( trim( is_string( $confirm ) ? sanitize_text_field( $confirm ) : '' ) );
		$host    = Rollback_Runner::host();
		if ( '' === $host || ! hash_equals( $host, $typed ) ) {
			return new WP_Error(
				'confirm_mismatch',
				/* translators: 1: this site's host name, 2: the action (roll back, resume …). */
				sprintf( __( 'Type this site\'s domain exactly as shown (%1$s) to %2$s.', 'keepmerge-push-staging-to-live' ), $host, $action ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * A step's answer with the push's detail.
	 *
	 * @param array          $session Session.
	 * @param array|WP_Error $result  Answer.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function with_detail( array $session, $result ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$fresh = Push_Session::find_by_id( (int) $session['id'] );
		return rest_ensure_response(
			array_merge(
				$result,
				array( 'detail' => null === $fresh ? null : array_merge( array( 'side' => 'destination' ), Push_Session::detail( $fresh ) ) )
			)
		);
	}

	/**
	 * Requires manage_options plus a valid wp_rest nonce, as on every admin route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission( WP_REST_Request $request ) {
		return ( new Admin_Controller() )->permission( $request );
	}

	/**
	 * GET /admin/pushes: sessions received here (destination) and pushes sent from here (source).
	 *
	 * @return WP_REST_Response
	 */
	public function list_pushes() {
		return rest_ensure_response(
			array(
				'now'      => time(),
				'received' => Push_Session::recent( 50 ),
				'lock'     => Recovery::lock_view(),
				'host'     => Rollback_Runner::host(),
				'sent'     => Push_Runner::recent( 50 ),
			)
		);
	}

	/**
	 * POST /admin/pushes †: starts a push of a planned plan. The live domain the admin typed is
	 * compared with the pairing's live host on the server.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start( WP_REST_Request $request ) {
		$plan    = $request->get_param( 'plan' );
		$confirm = $request->get_param( 'confirm' );
		if ( ! is_int( $plan ) || $plan < 1 ) {
			return new WP_Error( 'plan_not_found', __( 'This plan does not exist (a newer plan replaces older ones). Create a new plan.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
		}

		$result = Push_Runner::start( $plan, is_string( $confirm ) ? sanitize_text_field( $confirm ) : '', get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /admin/pushes/{id}: one push sent from this site.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_push( WP_REST_Request $request ) {
		$id  = absint( $request->get_param( 'id' ) );
		$row = Push_Runner::find( $id );
		if ( null !== $row ) {
			return rest_ensure_response( Push_Runner::present( $row ) );
		}
		$session = Push_Session::find_by_id( $id );
		if ( null === $session ) {
			return self::not_found();
		}
		return rest_ensure_response( array_merge( array( 'side' => 'destination' ), Push_Session::detail( $session ) ) );
	}

	/**
	 * POST /admin/pushes/{id}/held: decisions on held items of a push received here (approve as
	 * is, apply with WordPress's HTML filter, skip). Approving needs unfiltered_html (and edit_css
	 * for global styles with CSS), checked per item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function held( WP_REST_Request $request ) {
		$session = Push_Session::find_by_id( absint( $request->get_param( 'id' ) ) );
		if ( null === $session ) {
			return self::not_found();
		}
		$raw       = $request->get_param( 'decisions' );
		$decisions = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $uuid => $decision ) {
				$decisions[ sanitize_text_field( (string) $uuid ) ] = is_string( $decision ) ? sanitize_key( $decision ) : '';
			}
		}
		$result = Review::decide( $session, $decisions, get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /admin/pushes/{id}/continue †: one time-boxed step of a reviewed push, run from this
	 * administrator's own session. The domain the admin typed is compared with this site's host.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function continue_apply( WP_REST_Request $request ) {
		$session = Push_Session::find_by_id( absint( $request->get_param( 'id' ) ) );
		if ( null === $session ) {
			return self::not_found();
		}
		$confirm = $request->get_param( 'confirm' );
		$typed   = strtolower( trim( is_string( $confirm ) ? sanitize_text_field( $confirm ) : '' ) );
		$host    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || ! hash_equals( $host, $typed ) ) {
			return new WP_Error(
				'confirm_mismatch',
				/* translators: %s: this site's host name. */
				sprintf( __( 'Type this site\'s domain exactly as shown (%s) to continue the apply.', 'keepmerge-push-staging-to-live' ), $host ),
				array( 'status' => 400 )
			);
		}
		$result = Applier::admin_continue( $session, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$fresh = Push_Session::find_by_id( (int) $session['id'] );
		return rest_ensure_response(
			array_merge(
				$result,
				array( 'detail' => null === $fresh ? null : array_merge( array( 'side' => 'destination' ), Push_Session::detail( $fresh ) ) )
			)
		);
	}

	/**
	 * The not-found error.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'push_not_found', __( 'This push does not exist on this site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
	}

	/**
	 * POST /admin/pushes/{id}/step: one time-boxed step (one batch).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function step( WP_REST_Request $request ) {
		$steps = $request->get_param( 'steps' );
		$args  = is_int( $steps ) ? array( 'steps' => $steps ) : array();

		$result = Push_Runner::step( absint( $request->get_param( 'id' ) ), $args );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /admin/pushes/{id}/abort: aborts a push before apply (live purges its staged rows).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function abort( WP_REST_Request $request ) {
		$result = Push_Runner::abort( absint( $request->get_param( 'id' ) ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}
}
