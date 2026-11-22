<?php
/**
 * Source-side security cases end to end through Source_Pairing (the code the
 * Send tab and `wp keepmerge connect` run), with core's transport replaced by pre_http_request.
 * A replaced transport never runs core's cURL hook, so the mock pins the request with the
 * plugin's own callback (Outbound_Guard::pin_handle on a cURL handle), as core's cURL transport
 * would (RemoteClientTest). Nothing leaves the process.
 *
 * S-21 here: every refused REST root through the real connect path (the guard's
 * range table is OutboundGuardTest), and a 301 from live. S-75: a certificate the trust bundle
 * does not hold (the transport's cURL error 60), and an http:// key.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Pairing_Key;
use SoftGlaze\SafePush\Source_Pairing;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Outbound_Guard;

/**
 * @group security
 * @group source
 */
class SecuritySourceTest extends WP_UnitTestCase {

	private $seen = array();

	private $answer;

	private $server_backup;

	public function set_up() {
		parent::set_up();
		$this->server_backup = $_SERVER;
		$_SERVER['HTTPS']    = 'on';
		update_option( 'home', 'https://example.org' );
		update_option( 'siteurl', 'https://example.org' );
		$this->seen   = array();
		$this->answer = null;
		add_filter( 'pre_http_request', array( $this, 'mock' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock' ), 10 );
		$_SERVER = $this->server_backup;
		parent::tear_down();
	}

	public function mock( $pre, $args, $url ) {
		$this->seen[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( function_exists( 'curl_init' ) ) {
			Outbound_Guard::pin_handle( curl_init(), $args, $url );
		}
		$answer = $this->answer;
		return $answer( $args, $url );
	}

	private static function key( $rest_root ) {
		$key = Pairing_Key::build( 'https://93.184.216.34/wp-json/', wp_generate_uuid4(), 'softglaze-sp-0a1b2c3d', 'Ab3dEf6hIj9kLm2nOp5qRs8t', str_repeat( '7a', 32 ), time() + 1200 );
		return str_replace( 'https://93.184.216.34/wp-json/', $rest_root, $key );
	}

	private static function source_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE role = %s', Tables::table_name( 'pairings' ), 'source' ) );
	}

	private static function audit_count( $event ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', Tables::table_name( 'audit' ), $event ) );
	}

	/**
	 * S-21: keys naming refused addresses are refused before any request, through preview and
	 * connect; a live that answers 301 to another host is not followed.
	 */
	public function test_s21_refused_rest_roots_send_nothing_and_a_redirect_is_not_followed() {
		$this->answer = static function () {
			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			);
		};
		// Root => array( code, reason the plugin's own check gives ). The private ranges are also
		// refused by core's URL check, so the reason proves the plugin's range table answered.
		$cases        = array(
			'https://169.254.169.254/wp-json/'    => array( 'address_refused', 'private_address' ),
			'https://100.64.0.1/wp-json/'         => array( 'address_refused', 'private_address' ),
			'https://127.0.0.1/wp-json/'          => array( 'address_refused', 'private_address' ),
			'https://10.0.0.5/wp-json/'           => array( 'address_refused', 'private_address' ),
			'https://[::1]/wp-json/'              => array( 'address_refused', 'ipv6_unsupported' ),
			'https://example.org/wp-json/'        => array( 'address_refused', 'own_host' ),
			'https://93.184.216.34:8443/wp-json/' => array( 'address_refused', 'port' ),
			'http://93.184.216.34/wp-json/'       => array( 'key_not_https', '' ),
		);
		$refused      = self::audit_count( 'address_refused' );
		foreach ( $cases as $root => $expected ) {
			$key = self::key( $root );
			foreach ( array( 'preview', 'connect' ) as $how ) {
				$result = Source_Pairing::$how( $key );
				$this->assertWPError( $result, $how . ' ' . $root );
				$this->assertSame( $expected[0], $result->get_error_code(), $how . ' ' . $root );
				if ( '' !== $expected[1] ) {
					$this->assertSame( $expected[1], $result->get_error_data()['reason'], $how . ' ' . $root );
				}
			}
		}
		$this->assertSame( array(), $this->seen, 'no request made for any refused root' );
		$this->assertSame( 0, self::source_rows() );
		$this->assertSame( $refused + 14, self::audit_count( 'address_refused' ), 'each address refusal audited on the source' );

		// A 301 from live to another host: one request, the Location never requested.
		$this->answer = static function () {
			return array(
				'headers'  => array( 'location' => 'https://203.0.113.50/wp-json/keepmerge/v1/remote/status' ),
				'body'     => '',
				'response' => array( 'code' => 301 ),
				'cookies'  => array(),
			);
		};
		$result       = Source_Pairing::connect( self::key( 'https://93.184.216.34/wp-json/' ) );
		$this->assertSame( 'redirect_refused', $result->get_error_code() );
		$this->assertCount( 1, $this->seen );
		$this->assertSame( 0, $this->seen[0]['args']['redirection'] );
		$this->assertStringStartsWith( 'https://93.184.216.34/', $this->seen[0]['url'] );
		$this->assertSame( 0, self::source_rows() );
	}

	/**
	 * S-75: a certificate the trust bundle does not hold (cURL error 60 from the transport) is
	 * refused as tls_failed, never retried, nothing stored; every request asked for verification.
	 * An http:// key is refused before any request.
	 */
	public function test_s75_an_unverifiable_certificate_and_an_http_key_are_refused() {
		$this->answer = static function () {
			return new WP_Error( 'http_request_failed', 'cURL error 60: SSL certificate problem: self-signed certificate in certificate chain' );
		};
		$rejected     = self::audit_count( 'response_rejected' );

		foreach ( array( 'preview', 'connect' ) as $how ) {
			$this->seen = array();
			$result     = Source_Pairing::$how( self::key( 'https://93.184.216.34/wp-json/' ) );
			$this->assertWPError( $result );
			$this->assertSame( 'tls_failed', $result->get_error_code(), $how );
			$this->assertStringContainsString( 'certificate', $result->get_error_message() );
			$this->assertCount( 1, $this->seen, $how . ': a certificate failure is not retried' );
			$this->assertTrue( $this->seen[0]['args']['sslverify'], 'verification asked for' );
		}
		$this->assertSame( 0, self::source_rows(), 'nothing stored' );
		$this->assertGreaterThanOrEqual( $rejected, self::audit_count( 'response_rejected' ) );

		$this->seen = array();
		$http       = Source_Pairing::connect( self::key( 'http://93.184.216.34/wp-json/' ) );
		$this->assertSame( 'key_not_https', $http->get_error_code() );
		$this->assertSame( array(), $this->seen );
	}
}
