<?php
/**
 * Transfer\Remote_Client: URLs from the REST root, signed requests, response verification bound
 * to the request nonce, discarded unpinned answers, redirects, error mapping and retries.
 *
 * The transport is replaced through pre_http_request. A replaced transport never runs the pin
 * callback, so every answer is discarded as transport_unpinned unless the mock pins the request
 * the way core's cURL transport would (Outbound_Guard::pin_handle on a cURL handle).
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Signer;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Outbound_Guard;
use SoftGlaze\SafePush\Transfer\Remote_Client;

/**
 * @group client
 */
class RemoteClientTest extends WP_UnitTestCase {

	const ROOT = 'https://93.184.216.34/wp-json/';

	const PAIRING = '9f1c2a3b-4d5e-4f60-8a7b-1c2d3e4f5a6b';

	const SITE = '6f1c2a3b-1111-4222-8333-444455556666';

	/**
	 * Requests the mock saw: url, args.
	 *
	 * @var array[]
	 */
	private $seen = array();

	/**
	 * Answers the mock gives, one per request (the last one repeats).
	 *
	 * @var callable[]
	 */
	private $answers = array();

	private $pin = true;

	public function set_up() {
		parent::set_up();
		$this->seen    = array();
		$this->answers = array();
		$this->pin     = true;
		add_filter( 'pre_http_request', array( $this, 'mock' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock' ), 10 );
		delete_option( 'softglaze_sp_last_unpinned' );
		parent::tear_down();
	}

	public function mock( $pre, $args, $url ) {
		$this->seen[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( $this->pin && function_exists( 'curl_init' ) ) {
			Outbound_Guard::pin_handle( curl_init(), $args, $url );
		}
		$index  = min( count( $this->seen ), count( $this->answers ) ) - 1;
		$answer = $this->answers[ $index ];
		return $answer( $args, $url );
	}

	private static function secret() {
		return str_repeat( 'ab', 32 );
	}

	private function client( $root = self::ROOT, $context = 'pairing' ) {
		return new Remote_Client( $root, self::PAIRING, 'softglaze-sp-3a9f12c4', 'Ab3dEf6hIj9kLm2nOp5qRs8t', self::secret(), self::SITE, null, $context );
	}

	/**
	 * A response as the destination would send it, optionally signed over a given nonce.
	 */
	private static function answer( $status, $body, $sign = 'request', $headers = array() ) {
		return static function ( $args ) use ( $status, $body, $sign, $headers ) {
			$raw = is_string( $body ) ? $body : wp_json_encode( $body );
			if ( 'request' === $sign ) {
				$headers['x-sgsp-response-signature'] = Signer::sign_response( $args['headers']['X-SGSP-Nonce'], $status, $raw, self::secret() );
			} elseif ( is_string( $sign ) && '' !== $sign ) {
				$headers['x-sgsp-response-signature'] = Signer::sign_response( $sign, $status, $raw, self::secret() );
			}
			return array(
				'headers'  => $headers,
				'body'     => $raw,
				'response' => array(
					'code'    => $status,
					'message' => '',
				),
				'cookies'  => array(),
			);
		};
	}

	private static function audit_count( $event ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', Tables::table_name( 'audit' ), $event ) );
	}

	public function test_urls_for_pretty_and_plain_rest_roots() {
		$pretty = $this->client( 'https://live.example.com/wp-json/' )->build_url( '/keepmerge/v1/remote/status' );
		$this->assertSame( 'https://live.example.com/wp-json/keepmerge/v1/remote/status', $pretty['url'] );
		$this->assertSame( array(), $pretty['query'] );

		$plain = $this->client( 'https://live.example.com/?rest_route=/' )->build_url( '/keepmerge/v1/remote/status' );
		$this->assertSame( 'https://live.example.com/?rest_route=%2Fkeepmerge%2Fv1%2Fremote%2Fstatus', $plain['url'] );
		$this->assertSame( array(), $plain['query'], 'rest_route is never signed' );

		$extra = $this->client( 'https://live.example.com/shop/?lang=fr&rest_route=/' )->build_url( '/keepmerge/v1/remote/status', array( 'page' => '2' ) );
		$this->assertSame( 'https://live.example.com/shop/?lang=fr&page=2&rest_route=%2Fkeepmerge%2Fv1%2Fremote%2Fstatus', $extra['url'] );

		// The destination signs the query PHP decodes from that URL, minus rest_route: the same string.
		parse_str( (string) wp_parse_url( $extra['url'], PHP_URL_QUERY ), $as_received );
		$this->assertSame( '/keepmerge/v1/remote/status', $as_received['rest_route'] );
		$this->assertSame( Signer::canonical_query( $as_received ), Signer::canonical_query( $extra['query'] ) );
		$this->assertSame( 'lang=fr&page=2', Signer::canonical_query( $extra['query'] ) );
	}

	public function test_a_signed_answer_is_accepted_and_the_request_is_signed() {
		$this->answers = array( self::answer( 200, array( 'ok' => true ) ) );

		$result = $this->client()->get( '/keepmerge/v1/remote/status' );
		$this->assertIsArray( $result );
		$this->assertSame( array( 'ok' => true ), $result['data'] );

		$this->assertCount( 1, $this->seen );
		$args = $this->seen[0]['args'];
		$this->assertSame( self::ROOT . 'keepmerge/v1/remote/status', $this->seen[0]['url'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertTrue( $args['reject_unsafe_urls'], 'wp_safe_remote_request' );
		$this->assertSame( 16777216, $args['limit_response_size'] );
		$this->assertLessThanOrEqual( 30, $args['timeout'] );
		$this->assertSame( 'Basic ' . base64_encode( 'softglaze-sp-3a9f12c4:Ab3dEf6hIj9kLm2nOp5qRs8t' ), $args['headers']['Authorization'] );

		$h         = $args['headers'];
		$canonical = Signer::canonical( 'GET', '/keepmerge/v1/remote/status', array(), self::PAIRING, self::SITE, $h['X-SGSP-Timestamp'], $h['X-SGSP-Nonce'], Signer::body_hash( '' ) );
		$this->assertTrue( Signer::verify( $canonical, self::secret(), $h['X-SGSP-Signature'] ) );
		$this->assertSame( '1', $h['X-SGSP-Version'] );
	}

	public function test_an_answer_that_did_not_pass_the_pin_is_discarded() {
		$this->pin     = false;
		$this->answers = array( self::answer( 200, array( 'ok' => true ) ) );
		$before        = self::audit_count( 'response_rejected' );

		$result = $this->client()->get( '/keepmerge/v1/remote/status' );
		$this->assertWPError( $result );
		$this->assertSame( 'transport_unpinned', $result->get_error_code() );
		$this->assertSame( $before + 1, self::audit_count( 'response_rejected' ) );
		$this->assertGreaterThan( 0, (int) get_option( 'softglaze_sp_last_unpinned' ) );
	}

	public function test_unsigned_and_wrongly_signed_2xx_are_rejected() {
		$this->answers = array( self::answer( 200, array( 'ok' => true ), '' ) );
		$result        = $this->client()->get( '/keepmerge/v1/remote/status' );
		$this->assertSame( 'response_unsigned', $result->get_error_code() );

		// S-13: a captured answer, validly signed for an earlier request's nonce.
		$this->answers = array( self::answer( 200, array( 'ok' => true ), str_repeat( 'c', 32 ) ) );
		$result        = $this->client()->get( '/keepmerge/v1/remote/status' );
		$this->assertSame( 'response_signature_invalid', $result->get_error_code() );

		// Signed with another secret.
		$this->answers = array(
			static function ( $args ) {
				$raw = '{"ok":true}';
				return array(
					'headers'  => array( 'x-sgsp-response-signature' => Signer::sign_response( $args['headers']['X-SGSP-Nonce'], 200, $raw, str_repeat( 'cd', 32 ) ) ),
					'body'     => $raw,
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
				);
			},
		);
		$result = $this->client()->get( '/keepmerge/v1/remote/status' );
		$this->assertSame( 'response_signature_invalid', $result->get_error_code() );
	}

	public function test_redirects_are_never_followed() {
		$this->answers = array( self::answer( 301, '', '', array( 'location' => 'https://203.0.113.1/elsewhere' ) ) );

		$result = $this->client()->get( '/keepmerge/v1/remote/status' );
		$this->assertSame( 'redirect_refused', $result->get_error_code() );
		$this->assertCount( 1, $this->seen, 'the Location is never requested' );
	}

	public function test_live_errors_map_to_messages_with_a_fix() {
		$this->answers = array( self::answer( 404, array( 'code' => 'rest_no_route' ), '' ) );
		$this->assertSame( 'window_closed', $this->client()->get( '/keepmerge/v1/remote/status' )->get_error_code() );

		$this->answers = array( self::answer( 403, array( 'code' => 'pairing_not_approved' ), '' ) );
		$error         = $this->client()->get( '/keepmerge/v1/remote/status' );
		$this->assertSame( 'pairing_not_approved', $error->get_error_code() );
		$this->assertStringContainsString( 'Receive', $error->get_error_message() );

		$this->answers = array( self::answer( 401, array( 'code' => 'incorrect_password' ), '' ) );
		$this->assertSame( 'credentials_refused', $this->client()->get( '/keepmerge/v1/remote/status' )->get_error_code() );
		$this->assertSame( 'key_already_used', $this->client( self::ROOT, 'key' )->get( '/keepmerge/v1/remote/status' )->get_error_code() );

		$this->answers = array( self::answer( 401, array( 'code' => 'invalid_username' ), '' ) );
		$this->assertSame( 'pairing_revoked', $this->client()->get( '/keepmerge/v1/remote/status' )->get_error_code() );
		$this->assertSame( 'key_no_longer_valid', $this->client( self::ROOT, 'key' )->get( '/keepmerge/v1/remote/status' )->get_error_code() );

		$this->answers = array( self::answer( 429, array( 'code' => 'rate_limited' ), '', array( 'retry-after' => '60' ) ) );
		$error         = $this->client()->get( '/keepmerge/v1/remote/status' );
		$this->assertSame( 'rate_limited', $error->get_error_code() );
		$this->assertSame( 60, $error->get_error_data()['retry_after'] );

		foreach ( array( 'clock_skew', 'signature_invalid', 'https_required', 'authorization_header_missing', 'pairing_invalid', 'push_locked', 'storage_full', 'storage_unwritable', 'path_refused', 'code_report_disabled', 'window_closed', 'address_refused', 'transport_unpinned' ) as $code ) {
			$this->assertNotSame( '', Remote_Client::message( $code ), $code );
		}
	}

	public function test_server_errors_are_retried_with_a_new_nonce_and_then_reported() {
		$this->answers = array(
			self::answer( 503, '', '' ),
			self::answer( 502, '', '' ),
			self::answer( 200, array( 'ok' => true ) ),
		);

		$result = $this->client()->request( 'GET', '/keepmerge/v1/remote/status', array(), null, array( 'backoff' => array( 0, 0 ) ) );
		$this->assertIsArray( $result );
		$this->assertCount( 3, $this->seen );
		$nonces = array_unique(
			array_map(
				static function ( $seen ) {
					return $seen['args']['headers']['X-SGSP-Nonce'];
				},
				$this->seen
			)
		);
		$this->assertCount( 3, $nonces, 'each attempt is signed with a fresh nonce' );

		$this->seen    = array();
		$this->answers = array( self::answer( 500, '', '' ) );
		$result        = $this->client()->request( 'GET', '/keepmerge/v1/remote/status', array(), null, array( 'backoff' => array( 0, 0 ) ) );
		$this->assertSame( 'server_error', $result->get_error_code() );
		$this->assertCount( 3, $this->seen, 'two retries, then the error' );

		$this->seen    = array();
		$this->answers = array( self::answer( 401, array( 'code' => 'signature_invalid' ), '' ) );
		$this->client()->request( 'GET', '/keepmerge/v1/remote/status', array(), null, array( 'backoff' => array( 0, 0 ) ) );
		$this->assertCount( 1, $this->seen, '4xx is not retried' );
	}

	public function test_a_refused_address_sends_nothing() {
		$this->answers = array( self::answer( 200, array( 'ok' => true ) ) );
		$before        = self::audit_count( 'address_refused' );

		foreach ( array( 'https://10.0.0.5/wp-json/', 'https://169.254.169.254/wp-json/', 'https://live.example.com:8443/wp-json/', 'https://[::1]/wp-json/' ) as $root ) {
			$result = $this->client( $root )->get( '/keepmerge/v1/remote/status' );
			$this->assertSame( 'address_refused', $result->get_error_code(), $root );
		}

		$this->assertCount( 0, $this->seen );
		$this->assertSame( $before + 4, self::audit_count( 'address_refused' ) );
	}

	public function test_only_remote_routes_can_be_requested() {
		$this->answers = array( self::answer( 200, array() ) );
		$this->assertSame( 'invalid_request', $this->client()->get( '/wp/v2/users' )->get_error_code() );
		$this->assertSame( 'invalid_request', $this->client()->get( 'https://203.0.113.1/keepmerge/v1/remote/status' )->get_error_code() );
		$this->assertCount( 0, $this->seen );
	}
}
