<?php
/**
 * Signer: canonical string and HMAC vectors. The expected HMACs were computed
 * outside the plugin with hash_hmac() over the literal canonical strings below.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Signer;

/**
 * @group signer
 */
class SignerTest extends WP_UnitTestCase {

	const SECRET = '000102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f';

	const PAIRING = '9f1c2a3b-4d5e-4f60-8a7b-1c2d3e4f5a6b';

	const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

	public function test_vector_get_without_query_or_body() {
		$canonical = Signer::canonical( 'get', '/keepmerge/v1/remote/status', array(), self::PAIRING, '-', 1793001234, '0123456789abcdef0123456789abcdef', Signer::body_hash( '' ) );

		$this->assertSame(
			"SGSP1-HMAC-SHA256\nGET\n/keepmerge/v1/remote/status\n\n" . self::PAIRING . "\n-\n1793001234\n0123456789abcdef0123456789abcdef\n" . self::EMPTY_HASH,
			$canonical
		);
		$this->assertSame( '9512591a9f7e8b5d8305b7453f11806a99f0ef68443caaa4790510daf44ce49f', Signer::sign( $canonical, self::SECRET ) );
	}

	public function test_vector_post_with_sorted_encoded_query_and_body() {
		$body  = '{"site_id":"6f1c2a3b-1111-4222-8333-444455556666"}';
		$query = array(
			'z'          => 'é',
			'page'       => '2',
			'a b'        => 'c&d',
			'rest_route' => '/keepmerge/v1/remote/pair/confirm',
		);

		$this->assertSame( 'a%20b=c%26d&page=2&z=%C3%A9', Signer::canonical_query( $query ) );
		$this->assertSame( '141cfb63e7f4f872edd866ac249b72e2dceae9e5611de2d869057b5db71b5b33', Signer::body_hash( $body ) );

		$canonical = Signer::canonical( 'POST', '/keepmerge/v1/remote/pair/confirm', $query, self::PAIRING, '6f1c2a3b-1111-4222-8333-444455556666', '1793001234', str_repeat( 'f', 32 ), Signer::body_hash( $body ) );

		$this->assertSame( 'b5c66aba1dccd68c645a83ea830de34b51f37183e8fd1bf9babcb9151e6c4dcb', Signer::sign( $canonical, self::SECRET ) );
		$this->assertTrue( Signer::verify( $canonical, self::SECRET, 'b5c66aba1dccd68c645a83ea830de34b51f37183e8fd1bf9babcb9151e6c4dcb' ) );
	}

	public function test_vector_response() {
		$this->assertSame(
			'c357c490bb6e10c095e35ac5b2cb6453df0875356f828aa5b240e8fa39cd492d',
			Signer::sign_response( '0123456789abcdef0123456789abcdef', 200, '{"ok":true}', self::SECRET )
		);
		$this->assertTrue( Signer::verify_response( '0123456789abcdef0123456789abcdef', 200, '{"ok":true}', self::SECRET, 'c357c490bb6e10c095e35ac5b2cb6453df0875356f828aa5b240e8fa39cd492d' ) );
	}

	/**
	 * @dataProvider tampered_responses
	 *
	 * @param string $nonce  Nonce.
	 * @param int    $status Status.
	 * @param string $body   Body.
	 */
	public function test_response_signature_is_bound_to_nonce_status_and_body( $nonce, $status, $body ) {
		$this->assertFalse( Signer::verify_response( $nonce, $status, $body, self::SECRET, 'c357c490bb6e10c095e35ac5b2cb6453df0875356f828aa5b240e8fa39cd492d' ) );
	}

	public function tampered_responses() {
		return array(
			'another request nonce' => array( 'ffffffffffffffffffffffffffffffff', 200, '{"ok":true}' ),
			'another status'        => array( '0123456789abcdef0123456789abcdef', 201, '{"ok":true}' ),
			'another body'          => array( '0123456789abcdef0123456789abcdef', 200, '{"ok":false}' ),
		);
	}

	public function test_every_canonical_field_changes_the_signature() {
		$base = array( 'GET', '/keepmerge/v1/remote/status', array(), self::PAIRING, '-', '1793001234', str_repeat( 'a', 32 ), self::EMPTY_HASH );
		$sig  = Signer::sign( Signer::canonical( ...$base ), self::SECRET );

		$changes = array(
			0 => 'POST',
			1 => '/keepmerge/v1/remote/statuS',
			2 => array( 'x' => '1' ),
			3 => '9f1c2a3b-4d5e-4f60-8a7b-1c2d3e4f5a6c',
			4 => '6f1c2a3b-1111-4222-8333-444455556666',
			5 => '1793001235',
			6 => str_repeat( 'b', 32 ),
			7 => str_repeat( '0', 64 ),
		);
		foreach ( $changes as $index => $value ) {
			$args           = $base;
			$args[ $index ] = $value;
			$this->assertFalse( Signer::verify( Signer::canonical( ...$args ), self::SECRET, $sig ), "field $index" );
		}
	}

	public function test_wrong_secret_and_malformed_inputs_fail() {
		$canonical = Signer::canonical( 'GET', '/keepmerge/v1/remote/status', array(), self::PAIRING, '-', '1', str_repeat( 'a', 32 ), self::EMPTY_HASH );
		$sig       = Signer::sign( $canonical, self::SECRET );

		$this->assertFalse( Signer::verify( $canonical, str_repeat( 'f', 64 ), $sig ) );
		$this->assertFalse( Signer::verify( $canonical, 'not-a-secret', $sig ) );
		$this->assertSame( '', Signer::sign( $canonical, strtoupper( self::SECRET ) ) );
		$this->assertFalse( Signer::verify( $canonical, self::SECRET, strtoupper( $sig ) ) );
		$this->assertFalse( Signer::verify( $canonical, self::SECRET, null ) );
	}

	public function test_nested_query_values_cannot_be_signed() {
		$this->assertFalse( Signer::canonical_query( array( 'a' => array( 'b' ) ) ) );
		$this->assertFalse( Signer::canonical( 'GET', '/x', array( 'a' => array( 'b' ) ), self::PAIRING, '-', '1', str_repeat( 'a', 32 ), self::EMPTY_HASH ) );
	}

	public function test_nonce_and_secret_shapes() {
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{32}\z/', Signer::new_nonce() );
		$this->assertNotSame( Signer::new_nonce(), Signer::new_nonce() );
		$this->assertTrue( Signer::is_secret( Signer::new_secret() ) );
	}
}
