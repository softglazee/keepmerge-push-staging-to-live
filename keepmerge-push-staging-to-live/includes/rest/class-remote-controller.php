<?php
/**
 * Remote REST routes (destination).
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Rest;

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
use SoftGlaze\SafePush\Sync\Manifest;
use SoftGlaze\SafePush\Throttle;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes under keepmerge/v1/remote/ that the paired source calls (threat model §5.1). This
 * version has status, pair/confirm and pair/finalize, and the three read-only routes of an
 * active pairing: baseline, manifest and items/fetch. None of the read-only routes writes to
 * live's content, options or sync state; they write only what the chain writes for every
 * request (the nonce, the last-used time) and one audit row with counts.
 *
 * The routes are registered only while the receive window is open, so a site with the window
 * closed has no remote route at all. Every route's permission_callback is the full chain in
 * Remote_Permission. Route args are left empty: core validates args before the permission
 * callback, so the body is read and validated inside each handler, after the chain.
 */
final class Remote_Controller {

	const PREFIX = '/remote';

	const CONFIRM_MAX_BYTES = 4096;

	const FINALIZE_MAX_BYTES = 1024;

	const BASELINE_MAX_BYTES = 524288;

	const MANIFEST_MAX_BYTES = 262144;

	const FETCH_MAX_BYTES = 32768;

	/**
	 * An Application Password failure seen during this request (core runs that check before any
	 * route is known; the route is checked in count_app_password_failure()).
	 *
	 * @var string
	 */
	private static $app_password_failure = '';

	/**
	 * Hooks that shape and sign remote responses. They run on every REST request and act only
	 * on routes under /keepmerge/v1/remote/.
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
				'limits'                    => array(
					'post_max_size'      => Limits::post_max_size_bytes(),
					'max_execution_time' => Limits::max_execution_time(),
					'memory_limit'       => Limits::memory_limit_bytes(),
					'time_budget'        => Limits::time_budget(),
					'chunk_bytes'        => (int) $settings['chunk_kb'] * 1024,
					'item_batch'         => (int) $settings['item_batch'],
					'requests_per_min'   => Nonce_Store::RATE_LIMIT,
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
	 * POST /remote/pair/confirm: issues the long-term credentials.
	 *
	 * Body (JSON, at most 4 KB): site_id, home_url, wp_version, plugin_version, php_version.
	 * Each field is read by name and validated; anything else is dropped.
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
	 * a live administrator. The body (at most 1 KB) carries nothing this version reads.
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
	 * POST /remote/baseline (R4, read-only): match results for at most 500 fingerprints.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function baseline( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
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
	 * POST /remote/manifest (R5, read-only): live and base hashes for at most 500 items, live-only
	 * changes since the base, and the block types and themes live lacks for the listed items.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function manifest( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
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
	 * POST /remote/items/fetch (R6, read-only): live's normal forms of at most 50 items of
	 * included types, for diffs.
	 *
	 * @param WP_REST_Request $request Request (passed the chain).
	 * @return WP_REST_Response|WP_Error
	 */
	public function fetch_items( WP_REST_Request $request ) {
		$context = Remote_Permission::context( $request );
		if ( null === $context ) {
			return new WP_Error( 'pairing_invalid', 'pairing_invalid', array( 'status' => 401 ) );
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
	 * Audit row of a read route: counts only, never content (blueprint §17).
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
	 * Hook: rest_pre_serve_request. Serves the response of an authenticated remote request
	 * itself, so the bytes covered by X-SGSP-Response-Signature are exactly the bytes sent
	 * (blueprint §5.4). Requests that failed before the signature check are left to core and
	 * stay unsigned.
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

		// The JSON body of a REST response, encoded once above so it matches its signature;
		// served as application/json exactly as WP_REST_Server::serve_request() serves its own.
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON from wp_json_encode() in a REST response (application/json), not HTML.

		return true;
	}

	/**
	 * Hook: application_password_failed_authentication. Core passes only the error; the request
	 * route is not known yet, so the failure is remembered for count_app_password_failure().
	 *
	 * @param mixed $error Core's error.
	 * @return void
	 */
	public static function remember_app_password_failure( $error ) {
		self::$app_password_failure = is_wp_error( $error ) ? sanitize_key( $error->get_error_code() ) : 'unknown';
	}

	/**
	 * Hook: rest_post_dispatch. A failed Application Password on one of our remote routes while
	 * the window is open counts as a failed authentication for the address (blueprint §5.8).
	 * The login is never read: that would need the Authorization header decoded.
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
	 * @return array|WP_Error
	 */
	private static function decode_body( WP_REST_Request $request, $max_bytes ) {
		$raw = (string) $request->get_body();
		if ( strlen( $raw ) > $max_bytes ) {
			return new WP_Error( 'payload_too_large', 'payload_too_large', array( 'status' => 413 ) );
		}
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$data = json_decode( $raw, true, 8 );
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
	 * The step-12 refusal.
	 *
	 * @return WP_Error
	 */
	private static function invalid_body() {
		return new WP_Error( 'invalid_body', 'invalid_body', array( 'status' => 400 ) );
	}
}
