<?php
/**
 * Request and response signatures.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HMAC-SHA256 signatures over every remote request and response.
 *
 * The secret is the pairing's 64-hex value; the HMAC key is its 32 raw bytes. Every comparison
 * uses hash_equals(). The class uses plain PHP only, so both sites and the test client compute
 * byte-identical canonical strings.
 */
final class Signer {

	/**
	 * Value of the X-SGSP-Version header.
	 */
	const PROTOCOL_VERSION = '1';

	/**
	 * First line of every request canonical string.
	 */
	const REQUEST_PREFIX = 'SGSP1-HMAC-SHA256';

	/**
	 * First line of every response canonical string.
	 */
	const RESPONSE_PREFIX = 'SGSP1-RESPONSE';

	/**
	 * Header carrying the response signature.
	 */
	const RESPONSE_HEADER = 'X-SGSP-Response-Signature';

	/**
	 * Query keys that never take part in the canonical query: plain-permalink sites carry the
	 * route itself in rest_route, and the route has its own line.
	 */
	const EXCLUDED_QUERY_KEYS = array( 'rest_route' );

	/**
	 * A fresh one-time nonce for the replay guard: 32 hex characters from random_bytes().
	 *
	 * @return string
	 */
	public static function new_nonce() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * A fresh pairing secret (the HMAC key): 64 hex characters from random_bytes().
	 *
	 * @return string
	 */
	public static function new_secret() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Hex SHA-256 of a raw body; the hash of the empty string when there is no body.
	 *
	 * @param string $body Raw body.
	 * @return string
	 */
	public static function body_hash( $body ) {
		return hash( 'sha256', (string) $body );
	}

	/**
	 * Canonical query: keys sorted byte-wise, key and value rawurlencode'd, "k=v" joined by "&".
	 *
	 * Only scalar values are signed; a nested value cannot be represented the same way on both
	 * sites, so its presence makes the query unsignable and the request fails.
	 *
	 * @param array $query Query parameters as parsed by PHP.
	 * @return string|false Canonical query ('' when empty), false when a value is not scalar.
	 */
	public static function canonical_query( array $query ) {
		foreach ( self::EXCLUDED_QUERY_KEYS as $excluded ) {
			unset( $query[ $excluded ] );
		}

		$pairs = array();
		foreach ( $query as $key => $value ) {
			if ( ! is_scalar( $value ) && null !== $value ) {
				return false;
			}
			if ( is_bool( $value ) ) {
				$value = $value ? '1' : '';
			}
			$pairs[ (string) $key ] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
		}

		ksort( $pairs, SORT_STRING );

		return implode( '&', $pairs );
	}

	/**
	 * The request canonical string (LF-separated, no trailing LF).
	 *
	 * @param string       $method    HTTP method.
	 * @param string       $route     REST route, for example /keepmerge/v1/remote/status.
	 * @param array|string $query     Query parameters, or an already canonical query string.
	 * @param string       $pairing   Pairing UUID.
	 * @param string       $site      Source site ID, or "-" before confirm.
	 * @param string|int   $timestamp Unix seconds.
	 * @param string       $nonce     32 hex.
	 * @param string       $body_hash 64 hex.
	 * @return string|false False when the query cannot be signed.
	 */
	public static function canonical( $method, $route, $query, $pairing, $site, $timestamp, $nonce, $body_hash ) {
		$canonical_query = is_array( $query ) ? self::canonical_query( $query ) : (string) $query;
		if ( false === $canonical_query ) {
			return false;
		}

		return implode(
			"\n",
			array(
				self::REQUEST_PREFIX,
				strtoupper( (string) $method ),
				(string) $route,
				$canonical_query,
				(string) $pairing,
				(string) $site,
				(string) $timestamp,
				(string) $nonce,
				(string) $body_hash,
			)
		);
	}

	/**
	 * Signs a canonical string with HMAC-SHA256 through PHP's hash_hmac(); no custom cryptography.
	 *
	 * @param string $canonical  Canonical string.
	 * @param string $secret_hex Pairing secret, 64 hex.
	 * @return string 64 lowercase hex, or '' when the secret is malformed.
	 */
	public static function sign( $canonical, $secret_hex ) {
		$key = self::key( $secret_hex );
		if ( '' === $key ) {
			return '';
		}
		return hash_hmac( 'sha256', (string) $canonical, $key );
	}

	/**
	 * Verifies a signature with hash_equals(), so the time taken says nothing about how much of a
	 * forged signature matched.
	 *
	 * @param string $canonical  Canonical string.
	 * @param string $secret_hex Pairing secret, 64 hex.
	 * @param string $signature  Signature from the request, 64 hex.
	 * @return bool
	 */
	public static function verify( $canonical, $secret_hex, $signature ) {
		$expected = self::sign( $canonical, $secret_hex );
		if ( '' === $expected || ! is_string( $signature ) ) {
			return false;
		}
		return hash_equals( $expected, $signature );
	}

	/**
	 * The response canonical string: binds a response to the nonce of the request it answers,
	 * so a captured response cannot satisfy a different request.
	 *
	 * @param string $request_nonce Nonce of the request.
	 * @param int    $status        HTTP status sent.
	 * @param string $body          Raw response body sent.
	 * @return string
	 */
	public static function response_canonical( $request_nonce, $status, $body ) {
		return implode( "\n", array( self::RESPONSE_PREFIX, (string) $request_nonce, (string) (int) $status, self::body_hash( $body ) ) );
	}

	/**
	 * Signs a response.
	 *
	 * @param string $request_nonce Nonce of the request.
	 * @param int    $status        HTTP status.
	 * @param string $body          Raw body.
	 * @param string $secret_hex    Pairing secret, 64 hex.
	 * @return string 64 hex, or '' when the secret is malformed.
	 */
	public static function sign_response( $request_nonce, $status, $body, $secret_hex ) {
		return self::sign( self::response_canonical( $request_nonce, $status, $body ), $secret_hex );
	}

	/**
	 * Verifies a response signature in constant time.
	 *
	 * @param string $request_nonce Nonce the request carried.
	 * @param int    $status        HTTP status received.
	 * @param string $body          Raw body received.
	 * @param string $secret_hex    Pairing secret, 64 hex.
	 * @param string $signature     Value of X-SGSP-Response-Signature.
	 * @return bool
	 */
	public static function verify_response( $request_nonce, $status, $body, $secret_hex, $signature ) {
		return self::verify( self::response_canonical( $request_nonce, $status, $body ), $secret_hex, $signature );
	}

	/**
	 * Whether a value is a well-formed secret.
	 *
	 * @param mixed $secret_hex Candidate.
	 * @return bool
	 */
	public static function is_secret( $secret_hex ) {
		return is_string( $secret_hex ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $secret_hex );
	}

	/**
	 * The HMAC key: the secret's 32 raw bytes.
	 *
	 * @param string $secret_hex Secret.
	 * @return string Binary key, or '' when malformed.
	 */
	private static function key( $secret_hex ) {
		if ( ! self::is_secret( $secret_hex ) ) {
			return '';
		}
		return (string) hex2bin( $secret_hex );
	}
}
