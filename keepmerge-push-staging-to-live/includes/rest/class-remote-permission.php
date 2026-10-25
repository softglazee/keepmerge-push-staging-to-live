<?php
/**
 * The remote permission chain.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Rest;

use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Crypto;
use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Nonce_Store;
use SoftGlaze\SafePush\Pairing_Service;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Signer;
use SoftGlaze\SafePush\Throttle;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The permission_callback of every remote route: threat model §6, steps 1 to 11, in that
 * order. Step 0 (routes exist only while the receive window is open) is Plugin's job and step
 * 12 (the body schema) is each handler's.
 *
 * The first failure ends the request. Nothing is written for a request that fails before the
 * nonce is stored except the address's failure counter and one collapsed audit row per address
 * and reason. Error codes are fixed; the details stay in the local audit log.
 */
final class Remote_Permission {

	const ROUTE_STATUS = 'status';

	const ROUTE_CONFIRM = 'confirm';

	const ROUTE_FINALIZE = 'finalize';

	const SET_BOOTSTRAP = 'bootstrap';

	const SET_LONG_TERM = 'long_term';

	/**
	 * Largest accepted difference between the request timestamp and this server's clock.
	 */
	const MAX_SKEW = 300;

	const CAPABILITY = 'softglaze_sp_receive_push';

	const UUID_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

	/**
	 * Context of requests that passed the signature check: the pairing, the verified secret (to
	 * sign the response) and the request nonce. Keyed by the request object itself, so an ID of
	 * a finished request can never be mistaken for a new one.
	 *
	 * @var \SplObjectStorage|null
	 */
	private static $contexts = null;

	/**
	 * Outcome of the chain per request object. Core calls a route's permission_callback a second
	 * time in rest_send_allow_header() (rest_post_dispatch) to build the Allow header; the second
	 * call must return the first outcome instead of storing the nonce again or counting the
	 * failure twice.
	 *
	 * @var \SplObjectStorage|null
	 */
	private static $outcomes = null;

	/**
	 * Chain for GET /remote/status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_status( WP_REST_Request $request ) {
		return self::check( $request, self::ROUTE_STATUS );
	}

	/**
	 * Chain for POST /remote/pair/confirm.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_confirm( WP_REST_Request $request ) {
		return self::check( $request, self::ROUTE_CONFIRM );
	}

	/**
	 * Chain for POST /remote/pair/finalize.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function check_finalize( WP_REST_Request $request ) {
		return self::check( $request, self::ROUTE_FINALIZE );
	}

	/**
	 * The chain.
	 *
	 * @param WP_REST_Request $request  Request.
	 * @param string          $route_id One of the ROUTE_* values.
	 * @return true|WP_Error
	 */
	public static function check( WP_REST_Request $request, $route_id ) {
		$outcomes = self::outcomes();
		if ( $outcomes->contains( $request ) ) {
			return $outcomes[ $request ];
		}

		$outcome              = self::run_chain( $request, $route_id );
		$outcomes[ $request ] = $outcome;

		return $outcome;
	}

	/**
	 * Steps 1 to 11.
	 *
	 * @param WP_REST_Request $request  Request.
	 * @param string          $route_id Route.
	 * @return true|WP_Error
	 */
	private static function run_chain( WP_REST_Request $request, $route_id ) {
		$ip = Throttle::client_ip();

		// 1. HTTPS.
		if ( ! is_ssl() ) {
			return self::fail( 'https_required', 403, $ip, null, $route_id );
		}

		// 2. Required headers, well formed.
		$headers = self::headers( $request );
		if ( null === $headers ) {
			return self::fail( 'bad_headers', 400, $ip, null, $route_id );
		}

		// 3. Address not blocked; checked before any signature work.
		$blocked = Throttle::blocked_for( $ip );
		if ( $blocked > 0 ) {
			Audit_Log::write( 'ip_blocked', self::audit_args( null, 'ip_blocked', $route_id ) );
			return self::error( 'ip_blocked', 429, $blocked );
		}

		// 4. Clock.
		if ( abs( time() - (int) $headers['timestamp'] ) > self::MAX_SKEW ) {
			return self::fail( 'clock_skew', 401, $ip, null, $route_id );
		}

		// 5. Pairing and the state it is in.
		$pairing = Pairing_Store::find( $headers['pairing'] );
		$set     = self::credential_set( $pairing, $route_id );
		if ( is_wp_error( $set ) ) {
			$data = $set->get_error_data();
			return self::fail( $set->get_error_code(), (int) $data['status'], $ip, $pairing, $route_id, (string) $data['reason'] );
		}

		// 6. The user core authenticated from the Application Password is this pairing's
		// receiver, with the password of the credential set this state expects.
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			if ( '' === trim( (string) $request->get_header( 'authorization' ) ) ) {
				return self::fail( 'authorization_header_missing', 401, $ip, $pairing, $route_id );
			}
			return self::fail( 'credentials_invalid', 401, $ip, $pairing, $route_id, 'no_user' );
		}
		if ( (int) $pairing['receiver_user_id'] !== $user_id ) {
			return self::fail( 'credentials_invalid', 401, $ip, $pairing, $route_id, 'receiver_mismatch' );
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return self::fail( 'credentials_invalid', 401, $ip, $pairing, $route_id, 'capability_missing' );
		}
		$expected_password = self::SET_BOOTSTRAP === $set ? (string) $pairing['bootstrap_password_uuid'] : (string) $pairing['app_password_uuid'];
		$used_password     = rest_get_authenticated_app_password();
		if ( '' === $expected_password || ! is_string( $used_password ) || ! hash_equals( $expected_password, $used_password ) ) {
			return self::fail( 'credentials_invalid', 401, $ip, $pairing, $route_id, 'credential_set_mismatch' );
		}

		// 7. Body hash.
		if ( ! hash_equals( Signer::body_hash( $request->get_body() ), $headers['content_sha256'] ) ) {
			return self::fail( 'body_hash_mismatch', 400, $ip, $pairing, $route_id );
		}

		// 8. Signature, with the secret of the same credential set.
		$secret = Crypto::decrypt( self::SET_BOOTSTRAP === $set ? $pairing['bootstrap_secret_enc'] : $pairing['secret_enc'] );
		if ( is_wp_error( $secret ) || ! Signer::is_secret( $secret ) ) {
			self::mark_undecryptable( $pairing );
			return self::fail( 'signature_invalid', 401, $ip, $pairing, $route_id, 'decrypt_failed' );
		}
		$canonical = Signer::canonical(
			$request->get_method(),
			$request->get_route(),
			$request->get_query_params(),
			$headers['pairing'],
			$headers['site'],
			$headers['timestamp'],
			$headers['nonce'],
			$headers['content_sha256']
		);
		if ( false === $canonical ) {
			return self::fail( 'signature_invalid', 401, $ip, $pairing, $route_id, 'query_unsignable' );
		}
		if ( ! Signer::verify( $canonical, $secret, $headers['signature'] ) ) {
			return self::fail( 'signature_invalid', 401, $ip, $pairing, $route_id );
		}

		// From here on the request is authentic: its responses, errors included, are signed.
		self::contexts()->attach(
			$request,
			array(
				'pairing'  => $pairing,
				'set'      => $set,
				'secret'   => $secret,
				'nonce'    => $headers['nonce'],
				'site'     => $headers['site'],
				'route_id' => $route_id,
			)
		);

		// 9. Source identity after confirm, and the optional IP pin.
		if ( self::SET_LONG_TERM === $set ) {
			if ( ! hash_equals( (string) $pairing['source_site_id'], $headers['site'] ) ) {
				return self::fail( 'source_mismatch', 403, $ip, $pairing, $route_id, 'site_id' );
			}
			if ( '' !== (string) $pairing['pinned_ip'] && Throttle::bucket( $ip ) !== Throttle::bucket( (string) $pairing['pinned_ip'] ) ) {
				return self::fail( 'source_mismatch', 403, $ip, $pairing, $route_id, 'ip_pin' );
			}
		}

		// 10. One-time nonce: the first write this request causes.
		if ( ! Nonce_Store::insert( (int) $pairing['id'], $headers['nonce'] ) ) {
			return self::fail( 'replay_blocked', 409, $ip, $pairing, $route_id, 'replay_blocked', 'replay_blocked' );
		}

		// 11. Per-pairing rate.
		if ( Nonce_Store::count_recent( (int) $pairing['id'], Nonce_Store::RATE_WINDOW ) > Nonce_Store::RATE_LIMIT ) {
			Audit_Log::write( 'rate_limited', self::audit_args( $pairing, 'rate_limited', $route_id ) );
			return self::error( 'rate_limited', 429, Nonce_Store::RATE_WINDOW );
		}

		self::touch( $pairing );

		return true;
	}

	/**
	 * Which credential set a pairing in its current state accepts on a route (the state × route
	 * table of the phase plan), or the refusal.
	 *
	 * @param array|null $pairing  Pairing row.
	 * @param string     $route_id Route.
	 * @return string|WP_Error SET_BOOTSTRAP or SET_LONG_TERM; WP_Error with data status and reason.
	 */
	public static function credential_set( $pairing, $route_id ) {
		if ( ! is_array( $pairing ) ) {
			return self::refusal( 'pairing_invalid', 401, 'unknown_pairing' );
		}
		if ( Pairing_Service::ROLE !== $pairing['role'] ) {
			return self::refusal( 'pairing_invalid', 401, 'wrong_role' );
		}
		if ( ! hash_equals( Installer::home_hash(), (string) $pairing['local_home_hash'] ) ) {
			return self::refusal( 'pairing_invalid', 401, 'home_mismatch' );
		}
		if ( Pairing_Service::is_expired( $pairing ) ) {
			return self::refusal( 'pairing_invalid', 401, 'expired' );
		}

		switch ( $pairing['status'] ) {
			case 'pending':
				if ( self::ROUTE_STATUS === $route_id || self::ROUTE_CONFIRM === $route_id ) {
					if ( '' === (string) $pairing['bootstrap_password_uuid'] || empty( $pairing['bootstrap_secret_enc'] ) ) {
						return self::refusal( 'pairing_invalid', 401, 'no_bootstrap' );
					}
					return self::SET_BOOTSTRAP;
				}
				if ( self::ROUTE_FINALIZE === $route_id && '' !== (string) $pairing['app_password_uuid'] && ! empty( $pairing['secret_enc'] ) ) {
					return self::SET_LONG_TERM;
				}
				return self::refusal( 'pairing_invalid', 401, 'not_confirmed' );

			case 'awaiting_approval':
				if ( self::ROUTE_STATUS === $route_id ) {
					return self::SET_LONG_TERM;
				}
				return self::refusal( 'pairing_not_approved', 403, 'pairing_not_approved' );

			case 'active':
				if ( self::ROUTE_STATUS === $route_id ) {
					return self::SET_LONG_TERM;
				}
				return self::refusal( 'pairing_invalid', 401, 'already_active' );

			default:
				return self::refusal( 'pairing_invalid', 401, 'status_' . sanitize_key( (string) $pairing['status'] ) );
		}
	}

	/**
	 * The well-formed SGSP headers, or null when one is missing or malformed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array{pairing:string,site:string,timestamp:string,nonce:string,content_sha256:string,signature:string}|null
	 */
	public static function headers( WP_REST_Request $request ) {
		$uuid     = self::UUID_PATTERN;
		$patterns = array(
			'version'        => '/\A1\z/',
			'pairing'        => '/\A' . $uuid . '\z/',
			'site'           => '/\A(?:-|' . $uuid . ')\z/',
			'timestamp'      => '/\A[0-9]{1,12}\z/',
			'nonce'          => '/\A[0-9a-f]{32}\z/',
			'content_sha256' => '/\A[0-9a-f]{64}\z/',
			'signature'      => '/\A[0-9a-f]{64}\z/',
		);
		$names    = array(
			'version'        => 'X-SGSP-Version',
			'pairing'        => 'X-SGSP-Pairing',
			'site'           => 'X-SGSP-Site',
			'timestamp'      => 'X-SGSP-Timestamp',
			'nonce'          => 'X-SGSP-Nonce',
			'content_sha256' => 'X-SGSP-Content-SHA256',
			'signature'      => 'X-SGSP-Signature',
		);

		$out = array();
		foreach ( $names as $key => $header ) {
			$value = $request->get_header( $header );
			if ( ! is_string( $value ) || 1 !== preg_match( $patterns[ $key ], $value ) ) {
				return null;
			}
			$out[ $key ] = $value;
		}
		unset( $out['version'] );

		return $out;
	}

	/**
	 * The context of a request that passed the signature check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|null
	 */
	public static function context( WP_REST_Request $request ) {
		$contexts = self::contexts();
		return $contexts->contains( $request ) ? $contexts[ $request ] : null;
	}

	/**
	 * Returns and forgets the context, so the secret does not outlive the response.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array|null
	 */
	public static function take_context( WP_REST_Request $request ) {
		$context = self::context( $request );
		self::contexts()->detach( $request );
		if ( self::outcomes()->contains( $request ) ) {
			self::outcomes()->detach( $request );
		}
		return $context;
	}

	/**
	 * The context store.
	 *
	 * @return \SplObjectStorage
	 */
	private static function contexts() {
		if ( null === self::$contexts ) {
			self::$contexts = new \SplObjectStorage();
		}
		return self::$contexts;
	}

	/**
	 * The outcome store.
	 *
	 * @return \SplObjectStorage
	 */
	private static function outcomes() {
		if ( null === self::$outcomes ) {
			self::$outcomes = new \SplObjectStorage();
		}
		return self::$outcomes;
	}

	/**
	 * A failed check: counts the failure for the address, writes the collapsed audit row and
	 * returns the error.
	 *
	 * @param string     $code     Error code (also the reason when none is given).
	 * @param int        $status   HTTP status.
	 * @param string     $ip       Client address.
	 * @param array|null $pairing  Pairing row when identified.
	 * @param string     $route_id Route.
	 * @param string     $reason   Detailed reason for the audit log.
	 * @param string     $event    Audit event.
	 * @return WP_Error
	 */
	private static function fail( $code, $status, $ip, $pairing, $route_id, $reason = '', $event = 'auth_failed' ) {
		if ( '' !== $ip ) {
			Throttle::record_failure( $ip );
		}

		Audit_Log::write( $event, self::audit_args( $pairing, '' === $reason ? $code : $reason, $route_id ) );

		return self::error( $code, $status );
	}

	/**
	 * Audit arguments for a refused request.
	 *
	 * @param array|null $pairing  Pairing row.
	 * @param string     $reason   Reason.
	 * @param string     $route_id Route.
	 * @return array
	 */
	private static function audit_args( $pairing, $reason, $route_id ) {
		return array(
			'result'      => 'denied',
			'actor_type'  => 'remote',
			'actor_id'    => 0,
			'pairing_id'  => is_array( $pairing ) ? (int) $pairing['id'] : 0,
			'object_type' => 'route',
			'object_ref'  => $route_id,
			'details'     => array( 'reason' => $reason ),
		);
	}

	/**
	 * An error that carries only its code; the response shaper drops everything else.
	 *
	 * @param string $code        Code.
	 * @param int    $status      HTTP status.
	 * @param int    $retry_after Seconds for Retry-After, 0 for none.
	 * @return WP_Error
	 */
	private static function error( $code, $status, $retry_after = 0 ) {
		return new WP_Error(
			$code,
			$code,
			array(
				'status'      => $status,
				'retry_after' => (int) $retry_after,
			)
		);
	}

	/**
	 * A state refusal for credential_set().
	 *
	 * @param string $code   Code.
	 * @param int    $status HTTP status.
	 * @param string $reason Audit reason.
	 * @return WP_Error
	 */
	private static function refusal( $code, $status, $reason ) {
		return new WP_Error(
			$code,
			$code,
			array(
				'status' => $status,
				'reason' => $reason,
			)
		);
	}

	/**
	 * A secret that no longer decrypts (salts or SOFTGLAZE_SP_KEY changed) cannot recover:
	 * the pairing needs a new key. Nothing falls back to another key.
	 *
	 * @param array $pairing Pairing row.
	 * @return void
	 */
	private static function mark_undecryptable( array $pairing ) {
		if ( 'repair_needed' === $pairing['status'] ) {
			return;
		}

		Pairing_Store::update( (int) $pairing['id'], array( 'status' => 'repair_needed' ) );

		Audit_Log::write(
			'pairing_repair_needed',
			array(
				'actor_type'  => 'system',
				'pairing_id'  => (int) $pairing['id'],
				'object_type' => 'pairing',
				'object_ref'  => $pairing['pairing_uuid'],
				'details'     => array(
					'reason'          => 'decrypt_failed',
					'previous_status' => $pairing['status'],
				),
			)
		);
	}

	/**
	 * Records the last use, at most once a minute.
	 *
	 * @param array $pairing Pairing row.
	 * @return void
	 */
	private static function touch( array $pairing ) {
		$last = Pairing_Service::timestamp( $pairing['last_used_gmt'] );
		if ( null !== $last && time() - $last < MINUTE_IN_SECONDS ) {
			return;
		}
		Pairing_Store::update( (int) $pairing['id'], array( 'last_used_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
	}
}
