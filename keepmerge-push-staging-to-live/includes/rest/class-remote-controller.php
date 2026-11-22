<?php
/**
 * Remote REST routes (destination).
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Rest;

use SoftGlaze\SafePush\Apply\Applier;
use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Limits;
use SoftGlaze\SafePush\Nonce_Store;
use SoftGlaze\SafePush\Pairing_Service;
use SoftGlaze\SafePush\Plugin;
use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Signer;
use SoftGlaze\SafePush\Sync\Baseline_Matcher;
use SoftGlaze\SafePush\Sync\Code_Report;
use SoftGlaze\SafePush\Sync\Manifest;
use SoftGlaze\SafePush\Throttle;
use SoftGlaze\SafePush\Transfer\Media_Receiver;
use SoftGlaze\SafePush\Transfer\Push_Session;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes under keepmerge/v1/remote/ that the paired source calls: pairing, read-only routes,
 * push session routes that stage items in Keepmerge's tables, media routes that write only into
 * the push's temporary folder, and push/{id}/apply, the only route that writes live's content
 * (after the snapshot, as the receiver user so core's filters apply, never with held items).
 *
 * Registered only while the receive window is open; every permission_callback is the full chain
 * in Remote_Permission. Route args stay empty because core validates args before the permission
 * callback, so each handler validates its body after the chain.
 */
final class Remote_Controller {

	const PREFIX = '/remote';

	const CONFIRM_MAX_BYTES = 4096;

	const FINALIZE_MAX_BYTES = 1024;

	const BASELINE_MAX_BYTES = 524288;

	const MANIFEST_MAX_BYTES = 262144;

	const FETCH_MAX_BYTES = 32768;

	const CODE_MANIFEST_MAX_BYTES = 1024;

	const ABORT_MAX_BYTES = 1024;

	/**
	 * JSON nesting accepted in an item batch: the envelope's own levels plus typed values, which
	 * Normalizer::decode() limits to 64 levels.
	 */
	const ITEMS_DEPTH = 96;

	/**
	 * An Application Password failure seen during this request, before the route is known.
	 *
	 * @var string
	 */
	private static $app_password_failure = '';

	/**
	 * Hooks that shape and sign responses of routes under /keepmerge/v1/remote/.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'shape_errors' ), 10, 3 );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_signed' ), 10, 4 );
		add_action( 'application_password_failed_authentication', array( __CLASS__, 'remember_app_password_failure' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'count_app_password_failure' ), 10, 3 );
	}

	/**
	 * Registers the remote routes. Call only while the receive window is open.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns = Plugin::REST_NAMESPACE;

		register_rest_route(
			$ns,
			self::PREFIX . '/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( Remote_Permission::class, 'check_status' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/pair/confirm',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'confirm' ),
				'permission_callback' => array( Remote_Permission::class, 'check_confirm' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/pair/finalize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'finalize' ),
				'permission_callback' => array( Remote_Permission::class, 'check_finalize' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/baseline',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'baseline' ),
				'permission_callback' => array( Remote_Permission::class, 'check_baseline' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/manifest',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'manifest' ),
				'permission_callback' => array( Remote_Permission::class, 'check_manifest' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/items/fetch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'fetch_items' ),
				'permission_callback' => array( Remote_Permission::class, 'check_fetch' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/code-manifest',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'code_manifest' ),
				'permission_callback' => array( Remote_Permission::class, 'check_code_manifest' ),
				'args'                => array(),
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/push',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'open_push' ),
				'permission_callback' => array( Remote_Permission::class, 'check_push' ),
				'args'                => array(),
			)
		);

		// The session ID is the only URL parameter: core checks its pattern before the chain runs,
		// with no side effect.
		$id   = '(?P<id>' . Remote_Permission::UUID_PATTERN . ')';
		$args = array(
			'id' => array(
				'type'    => 'string',
				'pattern' => '^' . Remote_Permission::UUID_PATTERN . '$',
			),
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/push/' . $id,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_push' ),
				'permission_callback' => array( Remote_Permission::class, 'check_push_get' ),
				'args'                => $args,
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/push/' . $id . '/items',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'push_items' ),
				'permission_callback' => array( Remote_Permission::class, 'check_push_items' ),
				'args'                => $args,
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/push/' . $id . '/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_push' ),
				'permission_callback' => array( Remote_Permission::class, 'check_push_apply' ),
				'args'                => $args,
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/push/' . $id . '/abort',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'abort_push' ),
				'permission_callback' => array( Remote_Permission::class, 'check_push_abort' ),
				'args'                => $args,
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/push/' . $id . '/files',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'push_files' ),
				'permission_callback' => array( Remote_Permission::class, 'check_push_files' ),
				'args'                => $args,
			)
		);

		// Files and chunks are named by integers, never by a path. A sign is accepted so a negative
		// number is refused by the handler with 400, after the chain.
		$int       = '-?[0-9]{1,9}';
		$file_args = array_merge(
			$args,
			array(
				'ref' => array(
					'type'    => 'string',
					'pattern' => '^' . $int . '$',
				),
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/push/' . $id . '/files/(?P<ref>' . $int . ')/chunks/(?P<n>' . $int . ')',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'push_chunk' ),
				'permission_callback' => array( Remote_Permission::class, 'check_push_chunk' ),
				'args'                => array_merge(
					$file_args,
					array(
						'n' => array(
							'type'    => 'string',
							'pattern' => '^' . $int . '$',
						),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			self::PREFIX . '/push/' . $id . '/files/(?P<ref>' . $int . ')/commit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'push_commit' ),
				'permission_callback' => array( Remote_Permission::class, 'check_push_commit' ),
				'args'                => $file_args,
			)
		);
	}

	/**
	 * GET /remote/status: what the source needs before it confirms or pushes.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function status( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}
		$pairing  = $context['pairing'];
		$settings = Settings::get();
		$window   = Receive_Window::state();

		Audit_Log::write(
			'status_checked',
			array(
				'actor_type'  => 'remote',
				'actor_id'    => 0,
				'pairing_id'  => (int) $pairing['id'],
				'object_type' => 'pairing',
				'object_ref'  => $pairing['pairing_uuid'],
				'collapse'    => true,
				'details'     => array( 'state' => $pairing['status'] ),
			)
		);

		return new WP_REST_Response(
			array(
				'site'                      => array(
					'name'     => get_bloginfo( 'name' ),
					'home_url' => home_url( '/' ),
					'site_id'  => Installer::site_id(),
				),
				'versions'                  => array(
					'wordpress' => get_bloginfo( 'version' ),
					'php'       => PHP_VERSION,
					'plugin'    => SOFTGLAZE_SP_VERSION,
					'protocol'  => (int) Signer::PROTOCOL_VERSION,
				),
				'server_time'               => time(),
				'limits'                    => array_merge(
					array(
						'post_max_size'      => Limits::post_max_size_bytes(),
						'max_execution_time' => Limits::max_execution_time(),
						'memory_limit'       => Limits::memory_limit_bytes(),
						'time_budget'        => Limits::time_budget(),
						'item_batch'         => (int) $settings['item_batch'],
						'requests_per_min'   => Nonce_Store::RATE_LIMIT,
					),
					// Chunk size (≤ half of post_max_size), per-file and per-push caps.
					Media_Receiver::limits()
				),
				'pairing'                   => array(
					'uuid'       => $pairing['pairing_uuid'],
					'state'      => $pairing['status'],
					'expires_at' => Pairing_Service::timestamp( $pairing['expires_gmt'] ),
				),
				'window'                    => array(
					'closes_at' => $window['closes_at'],
				),
				'authorization_header_seen' => get_current_user_id() > 0,
			),
			200
		);
	}

	/**
	 * POST /remote/pair/confirm: issues the long-term credentials. Body fields are read by name;
	 * anything else is dropped.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function confirm( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}

		$body = self::decode_body( $request, self::CONFIRM_MAX_BYTES );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$site_id = isset( $body['site_id'] ) && is_string( $body['site_id'] ) ? strtolower( $body['site_id'] ) : '';
		$home    = isset( $body['home_url'] ) ? self::https_url( $body['home_url'] ) : '';
		if ( 1 !== preg_match( '/\A' . Remote_Permission::UUID_PATTERN . '\z/', $site_id ) || '' === $home ) {
			return self::invalid_body();
		}

		$fields = array(
			'site_id'  => $site_id,
			'home_url' => $home,
		);
		foreach ( array( 'wp_version', 'plugin_version', 'php_version' ) as $name ) {
			if ( ! isset( $body[ $name ] ) ) {
				$fields[ $name ] = '';
				continue;
			}
			if ( ! is_string( $body[ $name ] ) || 1 !== preg_match( '/\A[0-9A-Za-z.+-]{1,20}\z/', $body[ $name ] ) ) {
				return self::invalid_body();
			}
			$fields[ $name ] = $body[ $name ];
		}

		// The site header, when the source already sends its ID, must name the same site.
		if ( '-' !== $context['site'] && ! hash_equals( $context['site'], $site_id ) ) {
			return self::invalid_body();
		}
		if ( hash_equals( Installer::site_id(), $site_id ) ) {
			return new WP_Error( 'same_site', 'same_site', array( 'status' => 400 ) );
		}

		$result = Pairing_Service::confirm( $context['pairing'], $fields );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /remote/pair/finalize: deletes the bootstrap credentials; the pairing then waits for
	 * a live administrator.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function finalize( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}

		$body = self::decode_body( $request, self::FINALIZE_MAX_BYTES );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		return new WP_REST_Response( Pairing_Service::finalize( $context['pairing'], Throttle::client_ip() ), 200 );
	}

	/**
	 * POST /remote/baseline (read-only): match results for at most 500 fingerprints.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function baseline( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}
		$busy = self::busy();
		if ( null !== $busy ) {
			return $busy;
		}

		$body = self::decode_body( $request, self::BASELINE_MAX_BYTES );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$result = Baseline_Matcher::answer( $body );
		if ( is_wp_error( $result ) ) {
			self::audit_read( 'baseline_requested', $context['pairing'], array( 'reason' => $result->get_error_code() ), 'denied' );
			return $result;
		}

		self::audit_read(
			'baseline_requested',
			$context['pairing'],
			array_merge( array( 'fingerprints' => count( $result['results'] ) ), $result['counts'] )
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /remote/manifest (read-only): live and base hashes for at most 500 items.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function manifest( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}
		$busy = self::busy();
		if ( null !== $busy ) {
			return $busy;
		}

		$body = self::decode_body( $request, self::MANIFEST_MAX_BYTES );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$result = Manifest::answer( $context['pairing'], $body );
		if ( is_wp_error( $result ) ) {
			self::audit_read( 'manifest_requested', $context['pairing'], array( 'reason' => $result->get_error_code() ), 'denied' );
			return $result;
		}

		self::audit_read(
			'manifest_requested',
			$context['pairing'],
			array_merge(
				array(
					'items'        => count( $result['items'] ),
					'live_changes' => count( $result['live_changes']['items'] ),
				),
				$result['counts']
			)
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /remote/items/fetch (read-only): live's form of at most 50 items, for diffs.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function fetch_items( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}
		$busy = self::busy();
		if ( null !== $busy ) {
			return $busy;
		}

		$body = self::decode_body( $request, self::FETCH_MAX_BYTES );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$result = Manifest::fetch( $context['pairing'], $body );
		if ( is_wp_error( $result ) ) {
			self::audit_read( 'items_fetched', $context['pairing'], array( 'reason' => $result->get_error_code() ), 'denied' );
			return $result;
		}

		self::audit_read( 'items_fetched', $context['pairing'], array_merge( array( 'count' => count( $result['items'] ) ), $result['counts'] ) );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /remote/code-manifest (read-only): live's plugin and theme inventory or one page of
	 * file hashes; never file content.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function code_manifest( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}
		$busy = self::busy();
		if ( null !== $busy ) {
			return $busy;
		}

		$body = self::decode_body( $request, self::CODE_MANIFEST_MAX_BYTES );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$result = Code_Report::answer( $body );
		if ( is_wp_error( $result ) ) {
			self::audit_read( 'code_manifest_requested', $context['pairing'], array( 'reason' => $result->get_error_code() ), 'denied' );
			return $result;
		}

		if ( isset( $result['inventory'] ) ) {
			$counts = array(
				'part'       => 'inventory',
				'components' => count( $result['inventory']['components'] ),
			);
		} else {
			$counts = array(
				'part'      => 'files',
				'files'     => count( $result['files'] ),
				'cursor'    => (int) $result['cursor'],
				'truncated' => (bool) $result['truncated'],
			);
		}
		self::audit_read( 'code_manifest_requested', $context['pairing'], $counts );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /remote/push: opens a push session and takes the destination lock.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function open_push( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}
		$busy = self::busy();
		if ( null !== $busy ) {
			return $busy;
		}

		$body = self::decode_body( $request, Push_Session::MAX_OPEN_BYTES );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$result = Push_Session::open( $context['pairing'], $body );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /remote/push/{id}: state and progress of this pairing's session.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_push( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}

		$session = Push_Session::for_pairing( (string) $request->get_param( 'id' ), $context['pairing'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return new WP_REST_Response( Push_Session::status( $session ), 200 );
	}

	/**
	 * POST /remote/push/{id}/items: validates and stages one batch of items; nothing is applied.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function push_items( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}
		$busy = self::busy();
		if ( null !== $busy ) {
			return $busy;
		}

		$body = self::decode_body( $request, Push_Session::MAX_BATCH_BYTES, self::ITEMS_DEPTH );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$session = Push_Session::for_pairing( (string) $request->get_param( 'id' ), $context['pairing'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$result = Push_Session::receive( $session, $context['pairing'], $body );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /remote/push/{id}/apply: one time-boxed apply step as the receiver user. A push with
	 * held items answers awaiting_review and writes nothing.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function apply_push( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}
		$busy = self::busy();
		if ( null !== $busy ) {
			return $busy;
		}

		$body = self::decode_body( $request, Applier::MAX_BODY );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$session = Push_Session::for_pairing( (string) $request->get_param( 'id' ), $context['pairing'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$result = Applier::remote( $session, $body );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /remote/push/{id}/abort: purges the staged rows and releases the lock, only
	 * before apply starts.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function abort_push( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}

		$body = self::decode_body( $request, self::ABORT_MAX_BYTES );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$session = Push_Session::for_pairing( (string) $request->get_param( 'id' ), $context['pairing'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$result = Push_Session::abort( $session, $context['pairing'] );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /remote/push/{id}/files: the need list (have, need, renamed); every path passes
	 * Path_Guard.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function push_files( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}
		$busy = self::busy();
		if ( null !== $busy ) {
			return $busy;
		}

		$body = self::decode_body( $request, Media_Receiver::FILES_MAX_BYTES );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$session = Push_Session::for_pairing( (string) $request->get_param( 'id' ), $context['pairing'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$result = Media_Receiver::announce( $session, $context['pairing'], $body );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * PUT /remote/push/{id}/files/{ref}/chunks/{n}: one chunk as a raw octet-stream body,
	 * checked against X-SGSP-Chunk-SHA256 and written into the push's temporary folder.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function push_chunk( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}
		$busy = self::busy();
		if ( null !== $busy ) {
			return $busy;
		}

		$session = Push_Session::for_pairing( (string) $request->get_param( 'id' ), $context['pairing'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$result = Media_Receiver::put_chunk(
			$session,
			$context['pairing'],
			(string) $request->get_param( 'ref' ),
			(string) $request->get_param( 'n' ),
			(string) $request->get_body(),
			$request->get_header( 'X-SGSP-Chunk-SHA256' )
		);
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * POST /remote/push/{id}/files/{ref}/commit: the parts become one file, checked by
	 * size, SHA-256, its real type and (for images) a readable image.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function push_commit( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
		}
		$busy = self::busy();
		if ( null !== $busy ) {
			return $busy;
		}

		$body = self::decode_body( $request, Media_Receiver::COMMIT_MAX_BYTES );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$session = Push_Session::for_pairing( (string) $request->get_param( 'id' ), $context['pairing'] );
		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$result = Media_Receiver::commit( $session, $context['pairing'], (string) $request->get_param( 'ref' ) );
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, 200 );
	}

	/**
	 * Memory guard checked before a working handler starts: above 70 % of memory_limit it answers
	 * 503 server_busy with Retry-After, and the source retries.
	 *
	 * @return WP_Error|null
	 */
	private static function busy() {
		if ( Limits::memory_ok() ) {
			return null;
		}
		return new WP_Error(
			'server_busy',
			'server_busy',
			array(
				'status'      => 503,
				'retry_after' => 30,
			)
		);
	}

	/**
	 * Audit row of a read route: counts only, never content.
	 *
	 * @param string $event   Event.
	 * @param array  $pairing Pairing row.
	 * @param array  $counts  Counts.
	 * @param string $result  ok or denied.
	 * @return void
	 */
	private static function audit_read( $event, array $pairing, array $counts, $result = 'ok' ) {
		Audit_Log::write(
			$event,
			array(
				'result'      => $result,
				'actor_type'  => 'remote',
				'actor_id'    => 0,
				'pairing_id'  => (int) $pairing['id'],
				'object_type' => 'pairing',
				'object_ref'  => $pairing['pairing_uuid'],
				'details'     => $counts,
			)
		);
	}

	/**
	 * Hook: rest_request_after_callbacks. Any error on a remote route (argument validation,
	 * permission chain, handler) leaves with its code only, plus Retry-After where it applies.
	 *
	 * @param mixed           $response Response or error.
	 * @param array           $handler  Route handler.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function shape_errors( $response, $handler, $request ) {
		unset( $handler );

		if ( ! is_wp_error( $response ) || ! $request instanceof WP_REST_Request || ! self::is_remote_route( $request->get_route() ) ) {
			return $response;
		}

		$data   = $response->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
		$out    = new WP_REST_Response( array( 'code' => (string) $response->get_error_code() ), $status );

		if ( is_array( $data ) && ! empty( $data['retry_after'] ) ) {
			$out->header( 'Retry-After', (string) (int) $data['retry_after'] );
		}

		return $out;
	}

	/**
	 * Hook: rest_pre_serve_request. Serves an authenticated remote response itself, so
	 * X-SGSP-Response-Signature covers exactly the bytes sent; earlier failures stay unsigned.
	 *
	 * @param bool             $served  Whether the request has been served.
	 * @param WP_HTTP_Response $result  Result.
	 * @param WP_REST_Request  $request Request.
	 * @param WP_REST_Server   $server  Server.
	 * @return bool
	 */
	public static function serve_signed( $served, $result, $request, $server ) {
		if ( $served || ! $request instanceof WP_REST_Request || ! self::is_remote_route( $request->get_route() ) ) {
			return $served;
		}

		$context = Remote_Permission::take_context( $request );
		if ( null === $context || ! $result instanceof WP_HTTP_Response || ! $server instanceof WP_REST_Server || 'HEAD' === $request->get_method() ) {
			return $served;
		}

		$body = wp_json_encode( $server->response_to_data( $result, false ) );
		if ( ! is_string( $body ) ) {
			return $served;
		}

		$server->send_header( Signer::RESPONSE_HEADER, Signer::sign_response( $context['nonce'], (int) $result->get_status(), $body, $context['secret'] ) );

		// Encoded once above so the body matches its signature.
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode() in a REST response (application/json), not HTML.

		return true;
	}

	/**
	 * Hook: application_password_failed_authentication. Remembers the failure until the route
	 * is known.
	 *
	 * @param mixed $error Core's error.
	 * @return void
	 */
	public static function remember_app_password_failure( $error ) {
		self::$app_password_failure = is_wp_error( $error ) ? sanitize_key( $error->get_error_code() ) : 'unknown';
	}

	/**
	 * Hook: rest_post_dispatch. A failed Application Password on a remote route counts as a
	 * failed authentication for the address; the login is never decoded.
	 *
	 * @param mixed           $result  Response.
	 * @param mixed           $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function count_app_password_failure( $result, $server, $request ) {
		unset( $server );

		if ( '' === self::$app_password_failure || ! $request instanceof WP_REST_Request || ! self::is_remote_route( $request->get_route() ) ) {
			return $result;
		}

		$reason                     = 'application_password_' . self::$app_password_failure;
		self::$app_password_failure = '';

		if ( ! Receive_Window::is_open() ) {
			return $result;
		}

		$ip = Throttle::client_ip();
		if ( '' !== $ip ) {
			Throttle::record_failure( $ip );
		}
		Audit_Log::write(
			'auth_failed',
			array(
				'result'      => 'denied',
				'actor_type'  => 'remote',
				'actor_id'    => 0,
				'object_type' => 'route',
				'details'     => array( 'reason' => $reason ),
			)
		);

		return $result;
	}

	/**
	 * Whether a route belongs to the remote namespace (any letter case: core matches routes
	 * case-insensitively).
	 *
	 * @param mixed $route Route.
	 * @return bool
	 */
	public static function is_remote_route( $route ) {
		return is_string( $route ) && 0 === strpos( strtolower( $route ), '/' . Plugin::REST_NAMESPACE . self::PREFIX . '/' );
	}

	/**
	 * Decodes a JSON object body once, with a size limit.
	 *
	 * @param WP_REST_Request $request   Request.
	 * @param int             $max_bytes Size limit.
	 * @param int             $depth     JSON nesting accepted.
	 * @return array|WP_Error
	 */
	private static function decode_body( WP_REST_Request $request, $max_bytes, $depth = 8 ) {
		$raw = (string) $request->get_body();
		if ( strlen( $raw ) > $max_bytes ) {
			return new WP_Error( 'payload_too_large', 'payload_too_large', array( 'status' => 413 ) );
		}
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$data = json_decode( $raw, true, $depth );
		if ( ! is_array( $data ) || ( array() !== $data && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) ) {
			return self::invalid_body();
		}

		return $data;
	}

	/**
	 * An https URL without user-info, query or fragment, at most 255 characters; '' otherwise.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private static function https_url( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 255 || 1 === preg_match( '/[\s<>"\'\\\\]/', $value ) ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}
		if ( 1 !== preg_match( '/\A[a-z0-9.-]+\z/i', $parts['host'] ) ) {
			return '';
		}
		return esc_url_raw( $value, array( 'https' ) );
	}

	/**
	 * The refusal of a body that fails validation.
	 *
	 * @return WP_Error
	 */
	private static function invalid_body() {
		return new WP_Error( 'invalid_body', 'invalid_body', array( 'status' => 400 ) );
	}
}
