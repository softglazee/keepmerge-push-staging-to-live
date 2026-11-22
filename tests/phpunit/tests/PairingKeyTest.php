<?php
/**
 * Pairing_Key: the SGSP1 key format, one regex per part, explode with limit 7.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Pairing_Key;

/**
 * @group pairing
 * @group key
 */
class PairingKeyTest extends WP_UnitTestCase {

	private static function parts( array $override = array() ) {
		return array_replace(
			array(
				0 => 'SGSP1',
				1 => 'https://live.example.com/wp-json/',
				2 => '9f1c2a3b-4d5e-4f60-8a7b-1c2d3e4f5a6b',
				3 => 'softglaze-sp-3a9f12c4',
				4 => 'Ab3dEf6hIj9kLm2nOp5qRs8t',
				5 => str_repeat( 'e1', 32 ),
				6 => (string) ( time() + 1800 ),
			),
			$override
		);
	}

	private static function key( array $override = array() ) {
		return implode( '|', self::parts( $override ) );
	}

	public function test_a_valid_key_parses_into_its_parts() {
		$parsed = Pairing_Key::parse( '  ' . self::key() . "\n" );

		$this->assertIsArray( $parsed );
		$this->assertSame( 'https://live.example.com/wp-json/', $parsed['rest_root'] );
		$this->assertSame( '9f1c2a3b-4d5e-4f60-8a7b-1c2d3e4f5a6b', $parsed['pairing'] );
		$this->assertSame( 'softglaze-sp-3a9f12c4', $parsed['login'] );
		$this->assertSame( 'Ab3dEf6hIj9kLm2nOp5qRs8t', $parsed['password'] );
		$this->assertSame( str_repeat( 'e1', 32 ), $parsed['secret'] );
		$this->assertIsInt( $parsed['expires'] );
	}

	public function test_plain_permalink_rest_roots_are_allowed() {
		$this->assertIsArray( Pairing_Key::parse( self::key( array( 1 => 'https://live.example.com/?rest_route=/' ) ) ) );
		$this->assertIsArray( Pairing_Key::parse( self::key( array( 1 => 'https://live.example.com:443/shop/wp-json/' ) ) ) );
	}

	/**
	 * Hosts and ports the outbound guard refuses still parse, so the source refuses them with
	 * the guard's specific reason (S-21) instead of "not a key".
	 */
	public function test_roots_the_guard_refuses_still_parse() {
		foreach ( array( 'https://[::1]/wp-json/', 'https://live.example.com:8443/wp-json/', 'https://169.254.169.254/wp-json/', 'https://10.0.0.5/wp-json/' ) as $root ) {
			$parsed = Pairing_Key::parse( self::key( array( 1 => $root ) ) );
			$this->assertIsArray( $parsed, $root );
			$this->assertSame( $root, $parsed['rest_root'] );
		}
		$this->assertSame( 'key_not_https', Pairing_Key::parse( self::key( array( 1 => 'http://[::1]/wp-json/' ) ) )->get_error_code() );
		$this->assertSame( 'key_malformed', Pairing_Key::parse( self::key( array( 1 => 'https://[::1/wp-json/' ) ) )->get_error_code() );
	}

	/**
	 * @dataProvider bad_keys
	 *
	 * @param mixed  $key  Key.
	 * @param string $code Expected error code.
	 */
	public function test_bad_keys_are_refused( $key, $code ) {
		$result = Pairing_Key::parse( $key );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function bad_keys() {
		return array(
			'not a string'          => array( array( 'SGSP1' ), 'key_malformed' ),
			'empty'                 => array( '', 'key_malformed' ),
			'six parts'             => array( implode( '|', array_slice( self::parts(), 0, 6 ) ), 'key_malformed' ),
			'eighth part'           => array( self::key() . '|extra', 'key_malformed' ),
			'wrong prefix'          => array( self::key( array( 0 => 'SGSP2' ) ), 'key_malformed' ),
			'http root'             => array( self::key( array( 1 => 'http://live.example.com/wp-json/' ) ), 'key_not_https' ),
			'user-info in root'     => array( self::key( array( 1 => 'https://user@live.example.com/wp-json/' ) ), 'key_malformed' ),
			'fragment in root'      => array( self::key( array( 1 => 'https://live.example.com/wp-json/#x' ) ), 'key_malformed' ),
			'uppercase uuid'        => array( self::key( array( 2 => '9F1C2A3B-4D5E-4F60-8A7B-1C2D3E4F5A6B' ) ), 'key_malformed' ),
			'uuid v1'               => array( self::key( array( 2 => '9f1c2a3b-4d5e-1f60-8a7b-1c2d3e4f5a6b' ) ), 'key_malformed' ),
			'other login'           => array( self::key( array( 3 => 'admin' ) ), 'key_malformed' ),
			'password with space'   => array( self::key( array( 4 => 'Ab3d Ef6h Ij9k Lm2n Op5q' ) ), 'key_malformed' ),
			'base64 password'       => array( self::key( array( 4 => 'QWIzZEVmNmhJajlrTG0ybk9w==' ) ), 'key_malformed' ),
			'short secret'          => array( self::key( array( 5 => str_repeat( 'a', 63 ) ) ), 'key_malformed' ),
			'expiry with letters'   => array( self::key( array( 6 => '17930012x4' ) ), 'key_malformed' ),
			'expired'               => array( self::key( array( 6 => (string) ( time() - 1 ) ) ), 'key_expired' ),
			'too long'              => array( self::key() . str_repeat( 'a', 600 ), 'key_malformed' ),
		);
	}

	public function test_build_round_trips_and_refuses_bad_parts() {
		$p   = self::parts();
		$key = Pairing_Key::build( $p[1], $p[2], $p[3], $p[4], $p[5], (int) $p[6] );
		$this->assertSame( self::key(), $key );

		$this->assertWPError( Pairing_Key::build( 'http://x.example/', $p[2], $p[3], $p[4], $p[5], (int) $p[6] ) );
	}

	public function test_errors_never_echo_the_key() {
		$key    = self::key( array( 0 => 'SGSP0' ) );
		$result = Pairing_Key::parse( $key );
		$this->assertStringNotContainsString( 'Ab3dEf6hIj9kLm2nOp5qRs8t', $result->get_error_message() );
		$this->assertStringNotContainsString( str_repeat( 'e1', 32 ), $result->get_error_message() );
	}
}
