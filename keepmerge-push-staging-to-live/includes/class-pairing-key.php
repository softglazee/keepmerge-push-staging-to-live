<?php
/**
 * The pairing key format.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and parses the pairing key (blueprint §5.2): one line of plain ASCII, no base64,
 *
 *     SGSP1|<live REST root>|<pairing UUID>|<receiver login>|<bootstrap password>|<bootstrap secret>|<expiry>
 *
 * parsed with explode( '|', $key, 7 ) and one regular expression per part. The key carries
 * only bootstrap credentials: valid for 30 minutes, for status and confirm only, destroyed at
 * finalize.
 */
final class Pairing_Key {

	const PREFIX = 'SGSP1';

	const MAX_LENGTH = 600;

	/**
	 * One pattern per part, in key order.
	 *
	 * The REST root is https only, with no user-info, whitespace, "|" or "#"; plain-permalink
	 * roots ("…/?rest_route=/") are allowed.
	 */
	const PATTERNS = array(
		'prefix'    => '/\ASGSP1\z/',
		'rest_root' => '#\Ahttps://[a-z0-9.-]+(?::[0-9]{1,5})?/[A-Za-z0-9._~!$&\'()*+,;=:/?%-]*\z#i',
		'pairing'   => '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/',
		'login'     => '/\Asoftglaze-sp-[0-9a-f]{8}\z/',
		'password'  => '/\A[A-Za-z0-9]{24}\z/',
		'secret'    => '/\A[0-9a-f]{64}\z/',
		'expires'   => '/\A[0-9]{10}\z/',
	);

	/**
	 * Builds a key.
	 *
	 * @param string $rest_root REST root of the destination (rest_url()).
	 * @param string $pairing   Pairing UUID.
	 * @param string $login     Receiver login.
	 * @param string $password  Bootstrap Application Password.
	 * @param string $secret    Bootstrap secret, 64 hex.
	 * @param int    $expires   Unix expiry.
	 * @return string|WP_Error The key, or WP_Error when a part would not parse back.
	 */
	public static function build( $rest_root, $pairing, $login, $password, $secret, $expires ) {
		$key    = implode( '|', array( self::PREFIX, $rest_root, $pairing, $login, $password, $secret, (string) (int) $expires ) );
		$parsed = self::parse( $key, false );

		return is_wp_error( $parsed ) ? $parsed : $key;
	}

	/**
	 * Parses and validates a key.
	 *
	 * @param mixed $key          Pasted key.
	 * @param bool  $check_expiry Refuse a key whose expiry has passed.
	 * @return array{rest_root:string,pairing:string,login:string,password:string,secret:string,expires:int}|WP_Error
	 */
	public static function parse( $key, $check_expiry = true ) {
		if ( ! is_string( $key ) ) {
			return self::error( 'key_malformed' );
		}

		$key = trim( $key );
		if ( '' === $key || strlen( $key ) > self::MAX_LENGTH ) {
			return self::error( 'key_malformed' );
		}

		$parts = explode( '|', $key, 7 );
		if ( 7 !== count( $parts ) ) {
			return self::error( 'key_malformed' );
		}

		$names  = array_keys( self::PATTERNS );
		$values = array();
		foreach ( $names as $index => $name ) {
			if ( 1 !== preg_match( self::PATTERNS[ $name ], $parts[ $index ] ) ) {
				return self::error( 'rest_root' === $name && 0 === stripos( $parts[ $index ], 'http://' ) ? 'key_not_https' : 'key_malformed' );
			}
			$values[ $name ] = $parts[ $index ];
		}

		$url = wp_parse_url( $values['rest_root'] );
		if ( ! is_array( $url ) || empty( $url['host'] ) || isset( $url['user'] ) || isset( $url['pass'] ) || isset( $url['fragment'] ) || 'https' !== strtolower( (string) $url['scheme'] ) ) {
			return self::error( 'key_not_https' );
		}

		$values['expires'] = (int) $values['expires'];
		if ( $check_expiry && $values['expires'] < time() ) {
			return self::error( 'key_expired' );
		}

		unset( $values['prefix'] );

		return $values;
	}

	/**
	 * An error for the admin, without echoing any part of the key.
	 *
	 * @param string $code Error code.
	 * @return WP_Error
	 */
	private static function error( $code ) {
		$messages = array(
			'key_malformed' => __( 'This is not a Keepmerge pairing key. Copy the whole key from Tools → Staging Push → Receive on the live site and paste it again.', 'keepmerge-push-staging-to-live' ),
			'key_not_https' => __( 'The pairing key points to an address that is not HTTPS. Keepmerge connects over HTTPS only.', 'keepmerge-push-staging-to-live' ),
			'key_expired'   => __( 'This pairing key has expired. Create a new key on the live site.', 'keepmerge-push-staging-to-live' ),
		);

		return new WP_Error( $code, $messages[ $code ] );
	}
}
