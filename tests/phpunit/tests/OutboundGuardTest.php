<?php
/**
 * Transfer\Outbound_Guard: scheme, port, user-info, own host, the full range list, core's
 * filter as the only exception, the pin cache and the pin callback.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Transfer\Outbound_Guard;

/**
 * @group outbound
 */
class OutboundGuardTest extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'http_request_host_is_external' );
		parent::tear_down();
	}

	/**
	 * @dataProvider refused_addresses
	 *
	 * @param string $ip    Address.
	 * @param string $range Range it falls in.
	 */
	public function test_every_special_purpose_range_is_refused( $ip, $range ) {
		$this->assertSame( $range, Outbound_Guard::refused_range( $ip ) );

		$result = Outbound_Guard::check( 'https://' . $ip . '/wp-json/' );
		$this->assertWPError( $result, $ip );
		$this->assertSame( 'address_refused', $result->get_error_code() );
		$this->assertSame( 'private_address', $result->get_error_data()['reason'] );
	}

	public function refused_addresses() {
		return array(
			'this network'           => array( '0.0.0.1', '0.0.0.0/8' ),
			'private 10/8 start'     => array( '10.0.0.0', '10.0.0.0/8' ),
			'private 10.0.0.5'       => array( '10.0.0.5', '10.0.0.0/8' ),
			'private 10/8 end'       => array( '10.255.255.255', '10.0.0.0/8' ),
			'CGNAT 100.64.0.1'       => array( '100.64.0.1', '100.64.0.0/10' ),
			'CGNAT end'              => array( '100.127.255.255', '100.64.0.0/10' ),
			'loopback'               => array( '127.0.0.1', '127.0.0.0/8' ),
			'cloud metadata'         => array( '169.254.169.254', '169.254.0.0/16' ),
			'private 172.16/12'      => array( '172.16.0.1', '172.16.0.0/12' ),
			'private 172.31'         => array( '172.31.255.254', '172.16.0.0/12' ),
			'IETF protocol 192.0.0'  => array( '192.0.0.8', '192.0.0.0/24' ),
			'TEST-NET-1 192.0.2.1'   => array( '192.0.2.1', '192.0.2.0/24' ),
			'6to4 relay'             => array( '192.88.99.1', '192.88.99.0/24' ),
			'private 192.168'        => array( '192.168.1.1', '192.168.0.0/16' ),
			'benchmarking 198.18.0.1' => array( '198.18.0.1', '198.18.0.0/15' ),
			'benchmarking 198.19'    => array( '198.19.255.255', '198.18.0.0/15' ),
			'TEST-NET-2'             => array( '198.51.100.7', '198.51.100.0/24' ),
			'TEST-NET-3'             => array( '203.0.113.9', '203.0.113.0/24' ),
			'multicast 224.0.0.1'    => array( '224.0.0.1', '224.0.0.0/4' ),
			'multicast end'          => array( '239.255.255.255', '224.0.0.0/4' ),
			'reserved 240.0.0.1'     => array( '240.0.0.1', '240.0.0.0/4' ),
			'broadcast'              => array( '255.255.255.255', '240.0.0.0/4' ),
		);
	}

	/**
	 * @dataProvider public_addresses
	 *
	 * @param string $ip Address next to a refused range.
	 */
	public function test_public_neighbours_of_the_ranges_pass( $ip ) {
		$this->assertSame( '', Outbound_Guard::refused_range( $ip ) );

		$result = Outbound_Guard::check( 'https://' . $ip . '/wp-json/' );
		$this->assertIsArray( $result );
		$this->assertSame( $ip, $result['ip'] );
		$this->assertTrue( $result['fresh'] );
	}

	public function public_addresses() {
		return array(
			array( '1.0.0.1' ),
			array( '9.255.255.255' ),
			array( '11.0.0.0' ),
			array( '100.63.255.255' ),
			array( '100.128.0.0' ),
			array( '126.255.255.255' ),
			array( '128.0.0.0' ),
			array( '169.253.255.255' ),
			array( '169.255.0.0' ),
			array( '172.15.255.255' ),
			array( '172.32.0.0' ),
			array( '192.0.1.255' ),
			array( '192.0.3.0' ),
			array( '192.88.98.255' ),
			array( '192.167.255.255' ),
			array( '192.169.0.0' ),
			array( '198.17.255.255' ),
			array( '198.20.0.0' ),
			array( '198.51.99.255' ),
			array( '203.0.112.255' ),
			array( '223.255.255.255' ),
			array( '93.184.216.34' ),
		);
	}

	/**
	 * @dataProvider refused_urls
	 *
	 * @param string $url    URL.
	 * @param string $reason Expected reason.
	 */
	public function test_urls_are_refused_before_any_lookup( $url, $reason ) {
		$result = Outbound_Guard::check( $url );
		$this->assertWPError( $result, $url );
		$this->assertSame( 'address_refused', $result->get_error_code() );
		$this->assertSame( $reason, $result->get_error_data()['reason'], $url );
	}

	public function refused_urls() {
		return array(
			'http'                  => array( 'http://93.184.216.34/wp-json/', 'scheme' ),
			'port 8443'             => array( 'https://live.example.com:8443/wp-json/', 'port' ),
			'port 80 on https'      => array( 'https://live.example.com:80/wp-json/', 'port' ),
			'user-info'             => array( 'https://user:pass@live.example.com/wp-json/', 'userinfo' ),
			'fragment'              => array( 'https://live.example.com/wp-json/#x', 'fragment' ),
			'IPv6 loopback literal' => array( 'https://[::1]/wp-json/', 'ipv6_unsupported' ),
			'IPv6 mapped literal'   => array( 'https://[::ffff:127.0.0.1]/wp-json/', 'ipv6_unsupported' ),
			'decimal address'       => array( 'https://2130706433/wp-json/', 'invalid_host' ),
			'hex address'           => array( 'https://0x7f.1/wp-json/', 'invalid_host' ),
			'short dotted address'  => array( 'https://127.1/wp-json/', 'invalid_host' ),
			'trailing dot'          => array( 'https://live.example.com./wp-json/', 'invalid_host' ),
			'underscore'            => array( 'https://live_site.example.com/wp-json/', 'invalid_host' ),
			'own host'              => array( 'https://example.org/wp-json/', 'own_host' ),
			'own host, other case'  => array( 'https://EXAMPLE.org/wp-json/', 'own_host' ),
			'not a URL'             => array( 'live.example.com', 'invalid_url' ),
		);
	}

	public function test_explicit_port_443_is_accepted() {
		$result = Outbound_Guard::check( 'https://93.184.216.34:443/wp-json/' );
		$this->assertIsArray( $result );
	}

	public function test_a_host_without_an_ipv4_address_is_refused() {
		$result = Outbound_Guard::check( 'https://no-such-host.invalid/wp-json/' );
		$this->assertWPError( $result );
		$this->assertSame( 'no_ipv4', $result->get_error_data()['reason'] );
		$this->assertStringContainsString( 'IPv4', $result->get_error_message() );
	}

	public function test_core_filter_is_the_only_exception_and_gets_cores_arguments() {
		$seen = array();
		add_filter(
			'http_request_host_is_external',
			static function ( $external, $host, $url ) use ( &$seen ) {
				$seen[] = array( $external, $host, $url );
				return '10.1.2.3' === $host;
			},
			10,
			3
		);

		$url    = 'https://10.1.2.3/wp-json/keepmerge/v1/remote/status';
		$result = Outbound_Guard::check( $url );
		$this->assertIsArray( $result );
		$this->assertSame( '10.1.2.3', $result['ip'] );
		$this->assertSame( array( array( false, '10.1.2.3', $url ) ), $seen );

		$other = Outbound_Guard::check( 'https://10.1.2.4/wp-json/' );
		$this->assertWPError( $other );
	}

	public function test_public_addresses_never_ask_the_filter() {
		$asked = 0;
		add_filter(
			'http_request_host_is_external',
			static function ( $external ) use ( &$asked ) {
				++$asked;
				return $external;
			}
		);
		Outbound_Guard::check( 'https://93.184.216.34/wp-json/' );
		$this->assertSame( 0, $asked );
	}

	public function test_a_cached_check_is_reused_for_600_seconds_then_resolved_again() {
		$url = 'https://pinned-host.invalid/wp-json/keepmerge/v1/remote/status';
		$pin = array(
			'host'       => 'pinned-host.invalid',
			'ip'         => '93.184.216.34',
			'checked_at' => time() - 599,
		);

		$reused = Outbound_Guard::check( $url, $pin );
		$this->assertIsArray( $reused );
		$this->assertFalse( $reused['fresh'] );
		$this->assertSame( '93.184.216.34', $reused['ip'] );

		$pin['checked_at'] = time() - 600;
		$again             = Outbound_Guard::check( $url, $pin );
		$this->assertWPError( $again, 'expired pin must resolve again' );
		$this->assertSame( 'no_ipv4', $again->get_error_data()['reason'] );

		$pin['checked_at'] = time() + 120;
		$this->assertWPError( Outbound_Guard::check( $url, $pin ), 'a pin from the future is not trusted' );

		$pin['checked_at'] = time();
		$pin['host']       = 'other-host.invalid';
		$this->assertWPError( Outbound_Guard::check( $url, $pin ), 'a pin for another host is not used' );
	}

	public function test_the_pin_callback_only_touches_armed_requests_with_the_protocol_header() {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL extension missing in the test container.' );
		}

		$url     = 'https://live.example.com/wp-json/keepmerge/v1/remote/status';
		$headers = array(
			'X-SGSP-Version' => '1',
			'X-SGSP-Nonce'   => 'aaaa0000aaaa0000aaaa0000aaaa0000',
		);

		$handle = curl_init();
		Outbound_Guard::arm( $headers['X-SGSP-Nonce'], 'live.example.com', '93.184.216.34' );
		Outbound_Guard::pin_handle( $handle, array( 'headers' => $headers ), $url );
		$this->assertTrue( Outbound_Guard::take( $headers['X-SGSP-Nonce'] ), 'armed request is pinned' );
		$this->assertFalse( Outbound_Guard::take( $headers['X-SGSP-Nonce'] ), 'take() forgets the request' );

		// Not armed.
		Outbound_Guard::pin_handle( curl_init(), array( 'headers' => $headers ), $url );
		$this->assertFalse( Outbound_Guard::take( $headers['X-SGSP-Nonce'] ) );

		// No protocol header.
		Outbound_Guard::arm( $headers['X-SGSP-Nonce'], 'live.example.com', '93.184.216.34' );
		Outbound_Guard::pin_handle( curl_init(), array( 'headers' => array( 'X-SGSP-Nonce' => $headers['X-SGSP-Nonce'] ) ), $url );
		$this->assertFalse( Outbound_Guard::take( $headers['X-SGSP-Nonce'] ) );

		// Another host.
		Outbound_Guard::arm( $headers['X-SGSP-Nonce'], 'live.example.com', '93.184.216.34' );
		Outbound_Guard::pin_handle( curl_init(), array( 'headers' => $headers ), 'https://other.example.com/wp-json/' );
		$this->assertFalse( Outbound_Guard::take( $headers['X-SGSP-Nonce'] ) );

		// Another port.
		Outbound_Guard::arm( $headers['X-SGSP-Nonce'], 'live.example.com', '93.184.216.34' );
		Outbound_Guard::pin_handle( curl_init(), array( 'headers' => $headers ), 'https://live.example.com:8443/wp-json/' );
		$this->assertFalse( Outbound_Guard::take( $headers['X-SGSP-Nonce'] ) );
	}

	public function test_the_pin_callback_is_hooked_on_http_api_curl() {
		$this->assertSame( 10, has_action( 'http_api_curl', array( Outbound_Guard::class, 'pin_handle' ) ) );
	}
}
