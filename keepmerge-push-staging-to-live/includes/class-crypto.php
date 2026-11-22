<?php
/**
 * Encryption of secrets at rest.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encrypts pairing secrets before they are stored: sodium_crypto_secretbox (XSalsa20-Poly1305)
 * with a fresh nonce per value, from PHP's sodium extension or WordPress's bundled
 * sodium_compat. Stored form: "v1:" + hex( nonce . ciphertext ).
 *
 * The key is an HMAC of a fixed context string under SOFTGLAZE_SP_KEY (when defined with at
 * least 32 characters) or wp_salt( 'secure_auth' ), so it is never the raw salt.
 */
final class Crypto {

	const PREFIX = 'v1:';

	const CONTEXT = 'keepmerge-push-staging-to-live|at-rest|v1';

	const MIN_CONSTANT_LENGTH = 32;

	const SOURCE_CONSTANT = 'constant';

	const SOURCE_CONFIG = 'wp-config salts';

	const SOURCE_DATABASE = 'database salts';

	/**
	 * Encrypts a string, so pairing secrets are unreadable in a database dump or backup.
	 *
	 * @param string $plaintext Value to protect.
	 * @return string "v1:" + hex, or '' when sodium is unavailable (readiness reports that).
	 */
	public static function encrypt( $plaintext ) {
		if ( ! self::available() ) {
			return '';
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( (string) $plaintext, $nonce, self::key() );

		return self::PREFIX . bin2hex( $nonce . $cipher );
	}

	/**
	 * Decrypts a value written by encrypt(). A changed key or a tampered value fails closed with
	 * no fallback key; callers then mark the pairing "Re-pair needed".
	 *
	 * @param string $stored Stored value.
	 * @return string|WP_Error Plaintext, or WP_Error code decrypt_failed.
	 */
	public static function decrypt( $stored ) {
		$failed = new WP_Error( 'decrypt_failed', __( 'A stored credential could not be decrypted. Pair the sites again.', 'keepmerge-push-staging-to-live' ) );

		if ( ! self::available() || ! is_string( $stored ) || 0 !== strpos( $stored, self::PREFIX ) ) {
			return $failed;
		}

		$hex     = substr( $stored, strlen( self::PREFIX ) );
		$min_len = 2 * ( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES );
		if ( strlen( $hex ) < $min_len || 0 !== strlen( $hex ) % 2 || ! ctype_xdigit( $hex ) ) {
			return $failed;
		}

		$raw    = hex2bin( $hex );
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		} catch ( \Exception $e ) {
			return $failed;
		}

		return false === $plain ? $failed : $plain;
	}

	/**
	 * Whether the secretbox functions exist (native extension or core's sodium_compat); the
	 * plugin ships no cryptography of its own.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * Which implementation provides sodium, as the Overview tab reports it.
	 *
	 * @return string 'ext-sodium', 'sodium_compat' or 'none'.
	 */
	public static function implementation() {
		if ( ! self::available() ) {
			return 'none';
		}
		return extension_loaded( 'sodium' ) ? 'ext-sodium' : 'sodium_compat';
	}

	/**
	 * Where the key material comes from.
	 *
	 * @return string One of the SOURCE_* values.
	 */
	public static function key_source() {
		$names = array( 'SOFTGLAZE_SP_KEY', 'AUTH_KEY', 'AUTH_SALT', 'SECURE_AUTH_KEY', 'SECURE_AUTH_SALT', 'LOGGED_IN_KEY', 'LOGGED_IN_SALT', 'NONCE_KEY', 'NONCE_SALT', 'SECRET_KEY', 'SECRET_SALT' );

		$constants = array();
		foreach ( $names as $name ) {
			if ( defined( $name ) ) {
				$constants[ $name ] = constant( $name );
			}
		}

		// Core also treats the localized sample phrase as unset (a localized sample configuration file).
		$localized = get_translations_for_domain( 'default' )->translate( 'put your unique phrase here' );

		return self::classify_key_source( $constants, array( $localized ) );
	}

	/**
	 * Classifies the key source from a set of constant values, mirroring core's wp_salt() for the
	 * secure_auth scheme. Separate from key_source() so it can be tested, since constants cannot
	 * be undefined inside one PHP process.
	 *
	 * @param array    $constants       Constant name => value, only for defined constants.
	 * @param string[] $sample_phrases  Phrases the sample configuration file ships with.
	 * @return string
	 */
	public static function classify_key_source( array $constants, array $sample_phrases = array() ) {
		if ( isset( $constants['SOFTGLAZE_SP_KEY'] ) && is_string( $constants['SOFTGLAZE_SP_KEY'] ) && strlen( $constants['SOFTGLAZE_SP_KEY'] ) >= self::MIN_CONSTANT_LENGTH ) {
			return self::SOURCE_CONSTANT;
		}

		$duplicated = array();
		foreach ( array( 'AUTH', 'SECURE_AUTH', 'LOGGED_IN', 'NONCE', 'SECRET' ) as $first ) {
			foreach ( array( 'KEY', 'SALT' ) as $second ) {
				$name = $first . '_' . $second;
				if ( ! array_key_exists( $name, $constants ) || ! is_scalar( $constants[ $name ] ) ) {
					continue;
				}
				$value                = (string) $constants[ $name ];
				$duplicated[ $value ] = isset( $duplicated[ $value ] );
			}
		}
		$duplicated['put your unique phrase here'] = true;
		foreach ( $sample_phrases as $phrase ) {
			$duplicated[ (string) $phrase ] = true;
		}

		$usable = static function ( $name ) use ( $constants, $duplicated ) {
			if ( ! isset( $constants[ $name ] ) || ! is_scalar( $constants[ $name ] ) ) {
				return false;
			}
			$value = (string) $constants[ $name ];
			return '' !== $value && '0' !== $value && empty( $duplicated[ $value ] );
		};

		$key_from_config  = $usable( 'SECURE_AUTH_KEY' ) || $usable( 'SECRET_KEY' );
		$salt_from_config = $usable( 'SECURE_AUTH_SALT' );

		return ( $key_from_config && $salt_from_config ) ? self::SOURCE_CONFIG : self::SOURCE_DATABASE;
	}

	/**
	 * The 32-byte secretbox key, derived with hash_hmac() so the salt itself is never the key.
	 *
	 * @return string Binary key.
	 */
	private static function key() {
		if ( self::SOURCE_CONSTANT === self::key_source() ) {
			$material = (string) constant( 'SOFTGLAZE_SP_KEY' );
		} else {
			$material = wp_salt( 'secure_auth' );
		}

		return hash_hmac( 'sha256', self::CONTEXT, $material, true );
	}
}
