<?php
/**
 * Admin REST routes for pushes.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Rest;

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
 * checked here (†), run it step by step, abort it before apply, and the history of both sides.
 * Every route needs manage_options and the wp_rest nonce; every input is read by name.
 *
 * TODO(Phase 7): GET pushes/{id} on the destination with the held items, held decisions and
 * "Continue apply on this site". TODO(Phase 8): resume, skip-item, lock release, rollback.
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
			self::BASE . '/(?P<id>[0-9]{1,18})/abort',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'abort' ),
				'permission_callback' => $permission,
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
		$row = Push_Runner::find( absint( $request->get_param( 'id' ) ) );
		if ( null === $row ) {
			return new WP_Error( 'push_not_found', __( 'This push does not exist on this site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( Push_Runner::present( $row ) );
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
