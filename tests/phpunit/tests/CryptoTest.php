<?php
/**
 * Crypto: round trip, tamper detection, key change, key source.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Crypto;

/**
 * @group crypto
 */
class CryptoTest extends WP_UnitTestCase {

	public function test_sodium_is_available_and_reported() {
		$this->assertTrue( Crypto::available() );
		$this->assertContains( Crypto::implementation(), array( 'ext-sodium', 'sodium_compat' ) );
	}

	public function test_round_trip_returns_the_plaintext() {
		$plain  = "pairing secret \x00\xff with binary and unicode é";
		$stored = Crypto::encrypt( $plain );

		$this->assertStringStartsWith( 'v1:', $stored );
		$this->assertTrue( ctype_xdigit( substr( $stored, 3 ) ), 'stored as hex' );
		$this->assertStringNotContainsString( 'pairing secret', $stored );
		$this->assertSame( $plain, Crypto::decrypt( $stored ) );
	}

	public function test_each_encryption_uses_a_fresh_nonce() {
		$this->assertNotSame( Crypto::encrypt( 'same' ), Crypto::encrypt( 'same' ) );
	}

	public function test_tampered_ciphertext_is_rejected() {
		$stored = Crypto::encrypt( 'do not change me' );
		$hex    = substr( $stored, 3 );

		// Flip one hex digit in the ciphertext part (after the 48-digit nonce).
		$pos      = 60;
		$hex[ $pos ] = '0' === $hex[ $pos ] ? '1' : '0';
		$result   = Crypto::decrypt( 'v1:' . $hex );

		$this->assertWPError( $result );
		$this->assertSame( 'decrypt_failed', $result->get_error_code() );
	}

	/**
	 * @dataProvider malformed_values
	 *
	 * @param mixed $value Stored value.
	 */
	public function test_malformed_values_are_rejected( $value ) {
		$result = Crypto::decrypt( $value );
		$this->assertWPError( $result );
		$this->assertSame( 'decrypt_failed', $result->get_error_code() );
	}

	public function malformed_values() {
		return array(
			'empty'          => array( '' ),
			'no prefix'      => array( str_repeat( 'ab', 60 ) ),
			'wrong version'  => array( 'v2:' . str_repeat( 'ab', 60 ) ),
			'not hex'        => array( 'v1:' . str_repeat( 'zz', 60 ) ),
			'odd length'     => array( 'v1:' . str_repeat( 'a', 81 ) ),
			'too short'      => array( 'v1:abcd' ),
			'base64 instead' => array( 'v1:' . 'c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0c2VjcmV0' ),
			'not a string'   => array( array( 'v1:00' ) ),
		);
	}

	public function test_changed_salt_makes_old_values_unreadable() {
		$stored = Crypto::encrypt( 'secret' );

		$change = static function ( $salt ) {
			return $salt . 'changed';
		};
		add_filter( 'salt', $change );
		$result = Crypto::decrypt( $stored );
		remove_filter( 'salt', $change );

		$this->assertWPError( $result, 'no fallback to another key' );
		$this->assertSame( 'secret', Crypto::decrypt( $stored ) );
	}

	public function test_key_source_constant_needs_32_characters() {
		$this->assertSame( 'constant', Crypto::classify_key_source( array( 'SOFTGLAZE_SP_KEY' => str_repeat( 'k', 32 ) ) ) );
		$this->assertSame( 'database salts', Crypto::classify_key_source( array( 'SOFTGLAZE_SP_KEY' => str_repeat( 'k', 31 ) ) ) );
	}

	public function test_key_source_wp_config_salts() {
		$constants = array(
			'AUTH_KEY'         => 'a1-unique',
			'SECURE_AUTH_KEY'  => 'a2-unique',
			'SECURE_AUTH_SALT' => 'a3-unique',
		);
		$this->assertSame( 'wp-config salts', Crypto::classify_key_source( $constants ) );
	}

	public function test_key_source_secret_key_stands_in_for_the_key_but_not_the_salt() {
		$this->assertSame(
			'wp-config salts',
			Crypto::classify_key_source(
				array(
					'SECRET_KEY'       => 'legacy-unique',
					'SECURE_AUTH_SALT' => 'salt-unique',
				)
			)
		);
		$this->assertSame( 'database salts', Crypto::classify_key_source( array( 'SECRET_KEY' => 'legacy-unique' ) ) );
	}

	public function test_key_source_missing_salts_means_database() {
		$this->assertSame( 'database salts', Crypto::classify_key_source( array() ) );
		$this->assertSame( 'database salts', Crypto::classify_key_source( array( 'SECURE_AUTH_KEY' => 'only-the-key' ) ) );
	}

	public function test_key_source_sample_phrase_and_duplicates_mean_database() {
		$sample = array(
			'SECURE_AUTH_KEY'  => 'put your unique phrase here',
			'SECURE_AUTH_SALT' => 'unique-salt',
		);
		$this->assertSame( 'database salts', Crypto::classify_key_source( $sample ) );

		$duplicated = array(
			'AUTH_KEY'         => 'same-value',
			'SECURE_AUTH_KEY'  => 'same-value',
			'SECURE_AUTH_SALT' => 'unique-salt',
		);
		$this->assertSame( 'database salts', Crypto::classify_key_source( $duplicated ) );

		$localized = array(
			'SECURE_AUTH_KEY'  => 'mettez une phrase unique ici',
			'SECURE_AUTH_SALT' => 'unique-salt',
		);
		$this->assertSame( 'database salts', Crypto::classify_key_source( $localized, array( 'mettez une phrase unique ici' ) ) );
	}

	public function test_key_source_on_this_install_is_one_of_three() {
		$this->assertContains( Crypto::key_source(), array( 'constant', 'wp-config salts', 'database salts' ) );
	}
}
