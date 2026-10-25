<?php
/**
 * Signed requests to the paired destination (source only).
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Transfer;

use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Limits;
use SoftGlaze\SafePush\Signer;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only class in the plugin that makes HTTP requests (compliance V10). It is used by the
 * source side only; the destination never calls out (blueprint §4.1).
 *
 * Every request: Outbound_Guard first, then core's own URL check, then wp_safe_remote_request()
 * with certificate verification, no redirects, a 16 MB response limit and a timeout of at most
 * 30 seconds. Every request is signed (blueprint §5.4); every 2xx response must carry a valid
 * response signature bound to this request's nonce, or it is discarded. Nothing found in a
 * response is ever requested as a URL.
 *
 * Credentials live in this object for one operation only and are never returned, logged or put
 * into an error.
 */
final class Remote_Client {

	const DEFAULT_TIMEOUT = 15;

	const MAX_TIMEOUT = 30;

	const MAX_RESPONSE_BYTES = 16777216;

	/**
	 * Seconds to wait before each retry of a network error or 5xx (blueprint §12.2).
	 */
	const BACKOFF = array( 1, 2, 4, 8, 16 );

	/**
	 * Retries for an interactive call (pairing, status), inside the time budget.
	 */
	const INTERACTIVE_RETRIES = 2;

	const ROUTE_PATTERN = '#\A/keepmerge/v1/remote/[a-z0-9_-]+(?:/[a-z0-9_-]+)*\z#';

	/**
	 * Transport error numbers (as cURL reports them) of TLS and certificate failures: never
	 * retried, reported as tls_failed.
	 */
	const TLS_ERRORS = array( 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 );

	/**
	 * REST root of the destination.
	 *
	 * @var string
	 */
	private $rest_root;

	/**
	 * Pairing UUID.
	 *
	 * @var string
	 */
	private $pairing;

	/**
	 * Receiver login on the destination.
	 *
	 * @var string
	 */
	private $login;

	/**
	 * Application Password of the receiver.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * HMAC secret, 64 hex.
	 *
	 * @var string
	 */
	private $secret;

	/**
	 * Value of X-SGSP-Site: this site's ID, or "-" before confirm.
	 *
	 * @var string
	 */
	private $site;

	/**
	 * The address check this client connects with: host, ip, checked_at.
	 *
	 * @var array|null
	 */
	private $pin;

	/**
	 * Whether the pin changed during this client's life (the caller stores it).
	 *
	 * @var bool
	 */
	private $pin_changed = false;

	/**
	 * Context for messages: 'key' while the bootstrap credentials of a pairing key are used.
	 *
	 * @var string
	 */
	private $context;

	/**
	 * Creates a client for one pairing.
	 *
	 * @param string     $rest_root REST root of the destination.
	 * @param string     $pairing   Pairing UUID.
	 * @param string     $login     Receiver login.
	 * @param string     $password  Application Password.
	 * @param string     $secret    Secret, 64 hex.
	 * @param string     $site      This site's ID, or "-".
	 * @param array|null $pin       Cached address check.
	 * @param string     $context   'key' for bootstrap credentials, 'pairing' otherwise.
	 */
	public function __construct( $rest_root, $pairing, $login, $password, $secret, $site, $pin = null, $context = 'pairing' ) {
		$this->rest_root = (string) $rest_root;
		$this->pairing   = (string) $pairing;
		$this->login     = (string) $login;
		$this->password  = (string) $password;
		$this->secret    = (string) $secret;
		$this->site      = (string) $site;
		$this->pin       = is_array( $pin ) ? $pin : null;
		$this->context   = 'key' === $context ? 'key' : 'pairing';
	}

	/**
	 * The address check last used, for the caller to keep with the pairing.
	 *
	 * @return array|null host, ip, checked_at.
	 */
	public function pin() {
		return $this->pin;
	}

	/**
	 * Whether the address was resolved and checked again during this client's life.
	 *
	 * @return bool
	 */
	public function pin_changed() {
		return $this->pin_changed;
	}

	/**
	 * Switches to other credentials of the same pairing (confirm → finalize).
	 *
	 * @param string $login    Receiver login.
	 * @param string $password Application Password.
	 * @param string $secret   Secret.
	 * @param string $site     This site's ID.
	 * @return void
	 */
	public function use_credentials( $login, $password, $secret, $site ) {
		$this->login    = (string) $login;
		$this->password = (string) $password;
		$this->secret   = (string) $secret;
		$this->site     = (string) $site;
		$this->context  = 'pairing';
	}

	/**
	 * GET request.
	 *
	 * @param string $route Route under /keepmerge/v1/remote/.
	 * @param array  $query Query parameters (scalars).
	 * @param array  $opts  See request().
	 * @return array|WP_Error
	 */
	public function get( $route, array $query = array(), array $opts = array() ) {
		return $this->request( 'GET', $route, $query, null, $opts );
	}

	/**
	 * POST request with a JSON body.
	 *
	 * @param string $route Route under /keepmerge/v1/remote/.
	 * @param array  $body  Body (encoded as a JSON object).
	 * @param array  $opts  See request().
	 * @return array|WP_Error
	 */
	public function post( $route, array $body = array(), array $opts = array() ) {
		return $this->request( 'POST', $route, array(), $body, $opts );
	}

	/**
	 * One signed request, retried for network errors and 5xx inside the time budget.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $route  Route under /keepmerge/v1/remote/.
	 * @param array      $query  Query parameters (scalars).
	 * @param array|null $body   Body for POST/PUT, encoded as a JSON object.
	 * @param array      $opts   {
	 *     Optional.
	 *
	 *     @type int   $retries Retries (default 2, at most 5).
	 *     @type int   $budget  Seconds the whole call may take (default Limits::time_budget()).
	 *     @type int   $timeout Seconds per attempt (default 15, at most 30).
	 *     @type int[] $backoff Seconds before each retry (default 1, 2, 4, 8, 16).
	 *     @type int   $depth   JSON nesting accepted in a 2xx answer (default 16, at most 128).
	 * }
	 * @return array{status:int,data:array}|WP_Error
	 */
	public function request( $method, $route, array $query = array(), $body = null, array $opts = array() ) {
		$method = strtoupper( (string) $method );
		if ( ! in_array( $method, array( 'GET', 'POST', 'PUT' ), true ) || 1 !== preg_match( self::ROUTE_PATTERN, (string) $route ) ) {
			return $this->failure( 'invalid_request', 0, '', false );
		}

		$retries  = isset( $opts['retries'] ) ? min( 5, max( 0, (int) $opts['retries'] ) ) : self::INTERACTIVE_RETRIES;
		$budget   = isset( $opts['budget'] ) ? max( 1, (int) $opts['budget'] ) : Limits::time_budget();
		$backoff  = isset( $opts['backoff'] ) && is_array( $opts['backoff'] ) ? array_values( array_map( 'absint', $opts['backoff'] ) ) : self::BACKOFF;
		$deadline = microtime( true ) + $budget;
		$attempt  = 0;

		while ( true ) {
			$result = $this->attempt( $method, (string) $route, $query, $body, $opts, $deadline );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$data      = $result->get_error_data();
			$retryable = is_array( $data ) && ! empty( $data['retryable'] );
			$wait      = isset( $backoff[ $attempt ] ) ? $backoff[ $attempt ] : (int) end( $backoff );

			if ( ! $retryable || $attempt >= $retries || microtime( true ) + $wait + 1 >= $deadline ) {
				return $result;
			}

			++$attempt;
			if ( $wait > 0 ) {
				sleep( $wait );
			}
		}
	}

	/**
	 * The request URL for a route under the REST root. A plain-permalink root (…?rest_route=/)
	 * carries the route in rest_route; its other query parameters are kept (and signed).
	 *
	 * @param string $route Route.
	 * @param array  $query Query parameters.
	 * @return array{url:string,query:array} URL and the full query that is signed (rest_route excluded by the Signer).
	 */
	public function build_url( $route, array $query = array() ) {
		$root  = $this->rest_root;
		$parts = wp_parse_url( $root );
		$base  = $root;
		$own   = array();

		if ( is_array( $parts ) && isset( $parts['query'] ) ) {
			$base = substr( $root, 0, (int) strpos( $root, '?' ) );
			parse_str( $parts['query'], $own );
		}

		$plain = array_key_exists( 'rest_route', $own );
		$all   = array_merge( $own, $query );

		if ( $plain ) {
			$all['rest_route'] = $route;
			$url               = $base;
		} else {
			$url = rtrim( $base, '/' ) . $route;
		}

		ksort( $all, SORT_STRING );
		if ( ! empty( $all ) ) {
			$url .= '?' . http_build_query( $all, '', '&', PHP_QUERY_RFC3986 );
		}

		unset( $all['rest_route'] );

		return array(
			'url'   => $url,
			'query' => $all,
		);
	}

	/**
	 * One attempt.
	 *
	 * @param string     $method   Method.
	 * @param string     $route    Route.
	 * @param array      $query    Query.
	 * @param array|null $body     Body.
	 * @param array      $opts     Options.
	 * @param float      $deadline Deadline (microtime).
	 * @return array|WP_Error
	 */
	private function attempt( $method, $route, array $query, $body, array $opts, $deadline ) {
		$target = $this->build_url( $route, $query );
		$url    = $target['url'];

		$checked = Outbound_Guard::check( $url, $this->pin );
		if ( is_wp_error( $checked ) ) {
			$this->audit_refusal( $checked );
			return $checked;
		}
		if ( $checked['fresh'] ) {
			$this->pin_changed = true;
		}
		$this->pin = array(
			'host'       => $checked['host'],
			'ip'         => $checked['ip'],
			'checked_at' => $checked['checked_at'],
		);

		// Core's own check of the URL, so a refusal by core is reported as one, before any request.
		if ( ! wp_http_validate_url( $url ) ) {
			$refused = new WP_Error(
				'address_refused',
				__( 'WordPress\'s own address check refused the live site\'s address (it resolved to a private, local or reserved address).', 'keepmerge-push-staging-to-live' ),
				array(
					'status' => 400,
					'reason' => 'core_url_check',
					'host'   => $checked['host'],
				)
			);
			$this->audit_refusal( $refused );
			return $refused;
		}

		$raw_body = '';
		if ( null !== $body ) {
			$raw_body = (string) wp_json_encode( empty( $body ) ? new \stdClass() : $body );
		}

		$nonce     = Signer::new_nonce();
		$timestamp = (string) time();
		$hash      = Signer::body_hash( $raw_body );
		$canonical = Signer::canonical( $method, $route, $target['query'], $this->pairing, $this->site, $timestamp, $nonce, $hash );
		$signature = false === $canonical ? '' : Signer::sign( $canonical, $this->secret );
		if ( '' === $signature ) {
			return $this->failure( 'invalid_request', 0, '', false );
		}

		$headers = array(
			'Authorization'         => 'Basic ' . base64_encode( $this->login . ':' . $this->password ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication (RFC 7617) for core Application Passwords; the plugin's only base64 call.
			'Accept'                => 'application/json',
			'X-SGSP-Version'        => Signer::PROTOCOL_VERSION,
			'X-SGSP-Pairing'        => $this->pairing,
			'X-SGSP-Site'           => $this->site,
			'X-SGSP-Timestamp'      => $timestamp,
			'X-SGSP-Nonce'          => $nonce,
			'X-SGSP-Content-SHA256' => $hash,
			'X-SGSP-Signature'      => $signature,
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}

		$timeout = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : self::DEFAULT_TIMEOUT;
		$timeout = (int) max( 1, min( self::MAX_TIMEOUT, $timeout, floor( $deadline - microtime( true ) ) ) );

		Outbound_Guard::arm( $nonce, $checked['host'], $checked['ip'] );

		$response = wp_safe_remote_request(
			$url,
			array(
				'method'              => $method,
				'headers'             => $headers,
				'body'                => null === $body ? null : $raw_body,
				'timeout'             => $timeout,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'httpversion'         => '1.1',
				'blocking'            => true,
			)
		);

		$pinned = Outbound_Guard::take( $nonce );

		if ( ! $pinned ) {
			// The request did not go through the pinned cURL handle (another transport, or a
			// plugin answered it before the transport ran): whatever came back is discarded.
			$this->audit_rejected( 'transport_unpinned', $checked['host'] );
			update_option( 'softglaze_sp_last_unpinned', time(), false );
			return $this->failure( 'transport_unpinned', 0, '', false );
		}

		if ( is_wp_error( $response ) ) {
			return $this->transport_failure( $response );
		}

		$status    = (int) wp_remote_retrieve_response_code( $response );
		$raw       = (string) wp_remote_retrieve_body( $response );
		$sig       = wp_remote_retrieve_header( $response, 'x-sgsp-response-signature' );
		$sig       = is_string( $sig ) ? $sig : '';
		$signed    = '' !== $sig && Signer::verify_response( $nonce, $status, $raw, $this->secret, $sig );
		$remote    = self::remote_code( $raw );
		$retry_for = (int) wp_remote_retrieve_header( $response, 'retry-after' );

		if ( $status >= 300 && $status < 400 ) {
			// Redirects are never followed and the Location header is never read.
			$this->audit_rejected( 'redirect_refused', $checked['host'], $status );
			return $this->failure( 'redirect_refused', $status, '', false );
		}

		if ( $status >= 200 && $status < 300 ) {
			if ( ! $signed ) {
				$code = '' === $sig ? 'response_unsigned' : 'response_signature_invalid';
				$this->audit_rejected( $code, $checked['host'], $status );
				return $this->failure( $code, $status, '', false );
			}

			// Normal forms of items (items/fetch) nest deeper than the pairing answers.
			$depth = isset( $opts['depth'] ) ? min( 128, max( 16, (int) $opts['depth'] ) ) : 16;
			$data  = json_decode( $raw, true, $depth );
			if ( ! is_array( $data ) ) {
				return $this->failure( 'response_invalid', $status, '', true );
			}

			if ( get_option( 'softglaze_sp_last_unpinned', false ) ) {
				delete_option( 'softglaze_sp_last_unpinned' );
			}

			return array(
				'status' => $status,
				'data'   => $data,
			);
		}

		if ( '' !== $sig && ! $signed ) {
			// An error that claims to be signed but is not: nothing in it is trusted.
			$this->audit_rejected( 'response_signature_invalid', $checked['host'], $status );
			return $this->failure( 'response_signature_invalid', $status, $remote, false );
		}

		if ( 404 === $status && ( '' === $remote || 'rest_no_route' === $remote ) ) {
			return $this->failure( 'window_closed', $status, $remote, $signed );
		}

		if ( $status >= 500 ) {
			return $this->failure( 'server_error', $status, $remote, $signed, true );
		}

		$error = $this->failure( '' === $remote ? 'unexpected_status' : $remote, $status, $remote, $signed );
		if ( 429 === $status && $retry_for > 0 ) {
			$error->add_data( array_merge( (array) $error->get_error_data(), array( 'retry_after' => $retry_for ) ) );
		}

		return $error;
	}

	/**
	 * An error from the transport: certificate problems are never retried, other network errors are.
	 *
	 * @param WP_Error $error Error from wp_safe_remote_request().
	 * @return WP_Error
	 */
	private function transport_failure( WP_Error $error ) {
		$message = (string) $error->get_error_message();
		$number  = 0;
		if ( 1 === preg_match( '/cURL error (\d+)/', $message, $m ) ) {
			$number = (int) $m[1];
		}

		if ( in_array( $number, self::TLS_ERRORS, true ) ) {
			return $this->failure( 'tls_failed', 0, '', false, false, $number );
		}

		return $this->failure( 'network_error', 0, '', false, true, $number );
	}

	/**
	 * Builds an error with the message the admin sees and the facts the caller needs.
	 *
	 * @param string $code       Error code.
	 * @param int    $status     HTTP status received (0 when no response).
	 * @param string $remote     Code in the destination's answer.
	 * @param bool   $signed     Whether the answer carried a valid signature.
	 * @param bool   $retryable  Whether request() may retry.
	 * @param int    $transport_errno Transport error number, when there was one.
	 * @return WP_Error
	 */
	private function failure( $code, $status, $remote, $signed, $retryable = false, $transport_errno = 0 ) {
		$code = self::translate_code( (string) $code, $this->context );

		return new WP_Error(
			$code,
			self::message( $code, (int) $status ),
			array(
				'status'        => in_array( $code, array( 'network_error', 'tls_failed', 'server_error', 'response_invalid', 'response_unsigned', 'response_signature_invalid', 'redirect_refused', 'transport_unpinned' ), true ) ? 502 : 409,
				'remote_status' => (int) $status,
				'remote_code'   => (string) $remote,
				'signed'        => (bool) $signed,
				'retryable'     => (bool) $retryable,
				'errno'         => (int) $transport_errno,
			)
		);
	}

	/**
	 * Codes that mean something more specific while a pairing key's bootstrap credentials are
	 * used, or that core sends under its own name.
	 *
	 * @param string $code    Code.
	 * @param string $context 'key' or 'pairing'.
	 * @return string
	 */
	public static function translate_code( $code, $context ) {
		if ( 'key' === $context ) {
			if ( 'incorrect_password' === $code ) {
				return 'key_already_used';
			}
			if ( in_array( $code, array( 'invalid_username', 'invalid_email', 'pairing_invalid' ), true ) ) {
				return 'key_no_longer_valid';
			}
		}
		if ( 'invalid_username' === $code || 'invalid_email' === $code ) {
			return 'pairing_revoked';
		}
		if ( 'incorrect_password' === $code ) {
			return 'credentials_refused';
		}
		return '' === $code ? 'unexpected_status' : $code;
	}

	/**
	 * The message for an error code, with what to do about it (blueprint §12.2).
	 *
	 * @param string $code   Code.
	 * @param int    $status HTTP status received.
	 * @return string
	 */
	public static function message( $code, $status = 0 ) {
		$receive = __( 'On the live site, open Tools → Staging Push → Receive.', 'keepmerge-push-staging-to-live' );

		switch ( $code ) {
			case 'window_closed':
				return __( 'The live site is not receiving: its receive window is closed (or Keepmerge is not active there).', 'keepmerge-push-staging-to-live' ) . ' ' . $receive . ' ' . __( 'Open the window there, then try again.', 'keepmerge-push-staging-to-live' );
			case 'key_already_used':
				return __( 'This pairing key was already used. If you did not use it yourself, someone else did: on the live site, reject the staging site waiting for approval under Tools → Staging Push → Receive, then create a new key.', 'keepmerge-push-staging-to-live' );
			case 'key_no_longer_valid':
				return __( 'This pairing key no longer works on the live site: it expired, or the pairing was rejected or revoked there. Create a new key on the live site.', 'keepmerge-push-staging-to-live' );
			case 'pairing_invalid':
				return __( 'The live site no longer accepts this pairing (it was revoked, expired, or reset when Keepmerge was deactivated there). Revoke it here and connect again with a new key.', 'keepmerge-push-staging-to-live' );
			case 'pairing_revoked':
				return __( 'The live site no longer has this pairing\'s receiver account: the pairing was revoked or rejected there, it expired, or it was reset when Keepmerge was deactivated on live. Revoke it here and connect again with a new key if you still want to push.', 'keepmerge-push-staging-to-live' );
			case 'credentials_refused':
				return __( 'The live site no longer accepts this site\'s credentials (they were reset there, for example when Keepmerge was deactivated on live). Revoke the pairing here and connect again with a new key.', 'keepmerge-push-staging-to-live' );
			case 'pairing_not_approved':
				return __( 'The live site has not approved this site yet.', 'keepmerge-push-staging-to-live' ) . ' ' . $receive . ' ' . __( 'Approve the staging site there.', 'keepmerge-push-staging-to-live' );
			case 'clock_skew':
				return __( 'The clocks of the two servers differ by more than 5 minutes. Ask the host of the site whose clock is wrong to synchronise it (NTP), then try again.', 'keepmerge-push-staging-to-live' );
			case 'signature_invalid':
				return __( 'The live site refused this site\'s request signature: the stored secret no longer matches (for example after a database restore). Revoke the pairing here and connect again with a new key.', 'keepmerge-push-staging-to-live' );
			case 'credentials_invalid':
				return __( 'The live site did not accept this site\'s credentials. If Application Passwords were turned off on live (often by a security plugin), turn them on again; otherwise revoke the pairing here and connect again.', 'keepmerge-push-staging-to-live' );
			case 'https_required':
				return __( 'The live site says the request did not arrive over HTTPS. Make sure the live site is served over HTTPS end to end, including any proxy in front of it.', 'keepmerge-push-staging-to-live' );
			case 'authorization_header_missing':
				return __( 'The Authorization header does not reach WordPress on the live site: the web server or a proxy removes it. Ask the host to pass it to PHP (on Apache: CGIPassAuth On, or a SetEnvIf Authorization rule in .htaccess).', 'keepmerge-push-staging-to-live' );
			case 'rate_limited':
				return __( 'Too many requests in one minute. Wait a minute and try again.', 'keepmerge-push-staging-to-live' );
			case 'ip_blocked':
				return __( 'The live site has blocked this server\'s address for 15 minutes after repeated failed attempts. Wait, then try again; the live site\'s audit log shows what failed.', 'keepmerge-push-staging-to-live' );
			case 'replay_blocked':
				return __( 'The live site saw the same request twice and refused the second. Try again.', 'keepmerge-push-staging-to-live' );
			case 'source_mismatch':
				return __( 'The live site expects this pairing from another address or site ID (its IP pin, or a copied database). Check the IP pin on live, or connect again with a new key.', 'keepmerge-push-staging-to-live' );
			case 'bad_headers':
			case 'body_hash_mismatch':
			case 'invalid_body':
			case 'payload_too_large':
				return __( 'The live site did not understand the request. Make sure both sites run the same version of Keepmerge.', 'keepmerge-push-staging-to-live' );
			case 'same_site':
				return __( 'This key belongs to this site itself. Create the key on the live site.', 'keepmerge-push-staging-to-live' );
			case 'push_locked':
				return __( 'Another push is running on the live site. Wait until it ends, or release a stale lock on the live site\'s Pushes tab.', 'keepmerge-push-staging-to-live' );
			case 'storage_full':
			case 'storage_unwritable':
				return __( 'The live site cannot store the files of this push (disk full or uploads folder not writable). Free space or fix the permissions on live.', 'keepmerge-push-staging-to-live' );
			case 'path_refused':
				return __( 'The live site refused a file path in this push.', 'keepmerge-push-staging-to-live' );
			case 'code_report_disabled':
				return __( 'The live site does not allow code report requests. Switch on "Allow code report requests" in the live site\'s Settings tab to see the report.', 'keepmerge-push-staging-to-live' );
			case 'transport_unpinned':
				return __( 'The request did not use PHP\'s cURL transport (another plugin or the server setup changed WordPress\'s HTTP transport), so Keepmerge could not make sure it went to the checked address. The answer was discarded. Make sure the cURL extension is enabled and that no plugin replaces the HTTP transport.', 'keepmerge-push-staging-to-live' );
			case 'response_unsigned':
			case 'response_signature_invalid':
				return __( 'The live site\'s answer was not signed with this pairing\'s secret, so it was discarded. If this repeats, something between the two sites changes the answers: connect again with a new key and check the network path.', 'keepmerge-push-staging-to-live' );
			case 'response_invalid':
				return __( 'The live site\'s answer could not be read. Try again.', 'keepmerge-push-staging-to-live' );
			case 'redirect_refused':
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'The live site answered with a redirect (HTTP %d). Keepmerge does not follow redirects. Create the key on the live site at its own address (the one in its WordPress Address setting) and paste that key.', 'keepmerge-push-staging-to-live' ), (int) $status );
			case 'tls_failed':
				return __( 'The live site\'s HTTPS certificate could not be verified. Keepmerge connects only to sites with a valid certificate: fix the certificate on live, or the certificate bundle on this server.', 'keepmerge-push-staging-to-live' );
			case 'network_error':
				return __( 'The live site could not be reached from this server. Check that it is online and that this server may connect to it, then try again.', 'keepmerge-push-staging-to-live' );
			case 'server_error':
				/* translators: %d: HTTP status code. */
				return sprintf( __( 'The live site answered with a server error (HTTP %d). Check its PHP error log, then try again.', 'keepmerge-push-staging-to-live' ), (int) $status );
			case 'invalid_request':
				return __( 'This request cannot be signed. Try again.', 'keepmerge-push-staging-to-live' );
			default:
				/* translators: 1: HTTP status code, 2: error code. */
				return sprintf( __( 'The live site refused the request (HTTP %1$d, %2$s).', 'keepmerge-push-staging-to-live' ), (int) $status, sanitize_key( $code ) );
		}
	}

	/**
	 * The code field of a JSON error body, as a key; '' when absent.
	 *
	 * @param string $raw Body.
	 * @return string
	 */
	private static function remote_code( $raw ) {
		if ( '' === $raw || strlen( $raw ) > 65536 ) {
			return '';
		}
		$data = json_decode( $raw, true, 4 );
		if ( ! is_array( $data ) || ! isset( $data['code'] ) || ! is_string( $data['code'] ) ) {
			return '';
		}
		return substr( sanitize_key( $data['code'] ), 0, 64 );
	}

	/**
	 * Audits a refusal before any request (address or transport).
	 *
	 * @param WP_Error $error Refusal.
	 * @return void
	 */
	private function audit_refusal( WP_Error $error ) {
		$data = (array) $error->get_error_data();

		Audit_Log::write(
			'address_refused' === $error->get_error_code() ? 'address_refused' : 'response_rejected',
			array(
				'result'      => 'denied',
				'object_type' => 'pairing',
				'object_ref'  => $this->pairing,
				'details'     => array(
					'reason'  => isset( $data['reason'] ) ? (string) $data['reason'] : $error->get_error_code(),
					'host'    => isset( $data['host'] ) ? (string) $data['host'] : '',
					'address' => isset( $data['address'] ) ? (string) $data['address'] : '',
				),
			)
		);
	}

	/**
	 * Audits a discarded answer.
	 *
	 * @param string $reason Reason code.
	 * @param string $host   Host.
	 * @param int    $status HTTP status, when there was a response.
	 * @return void
	 */
	private function audit_rejected( $reason, $host, $status = 0 ) {
		Audit_Log::write(
			'response_rejected',
			array(
				'result'      => 'denied',
				'object_type' => 'pairing',
				'object_ref'  => $this->pairing,
				'details'     => array(
					'reason' => $reason,
					'host'   => $host,
					'status' => (int) $status,
				),
			)
		);
	}
}
