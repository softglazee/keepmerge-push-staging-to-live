<?php
/**
 * Nonce_Store (replay guard, bounded prune, rate count) and Throttle (per-address failures).
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Nonce_Store;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Throttle;

/**
 * @group nonce
 * @group throttle
 */
class NonceThrottleTest extends WP_UnitTestCase {

	/**
	 * Previous REMOTE_ADDR.
	 *
	 * @var mixed
	 */
	private $remote_addr;

	public function set_up() {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Tables::table_name( 'nonces' ) );
		$wpdb->query( 'DELETE FROM ' . Tables::table_name( 'throttle' ) );
		$this->remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
	}

	public function tear_down() {
		if ( null === $this->remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		}
		parent::tear_down();
	}

	private function nonce_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::table_name( 'nonces' ) );
	}

	public function test_a_nonce_is_accepted_once_per_pairing() {
		$nonce = str_repeat( 'ab', 16 );

		$this->assertTrue( Nonce_Store::insert( 7, $nonce ) );
		$this->assertFalse( Nonce_Store::insert( 7, $nonce ), 'replay' );
		$this->assertTrue( Nonce_Store::insert( 8, $nonce ), 'same nonce, other pairing' );
		$this->assertSame( 2, $this->nonce_rows() );
	}

	public function test_malformed_nonces_and_pairings_are_refused_without_a_row() {
		$this->assertFalse( Nonce_Store::insert( 7, 'ABCDEF' ) );
		$this->assertFalse( Nonce_Store::insert( 7, str_repeat( 'g', 32 ) ) );
		$this->assertFalse( Nonce_Store::insert( 0, str_repeat( 'a', 32 ) ) );
		$this->assertSame( 0, $this->nonce_rows() );
	}

	public function test_prune_deletes_only_rows_older_than_900_seconds_and_is_bounded() {
		global $wpdb;
		$table = Tables::table_name( 'nonces' );
		for ( $i = 0; $i < 5; $i++ ) {
			$wpdb->insert( $table, array( 'pairing_id' => 1, 'nonce' => sprintf( '%032x', $i ), 'received_gmt' => gmdate( 'Y-m-d H:i:s', time() - 901 ) ) );
		}
		$wpdb->insert( $table, array( 'pairing_id' => 1, 'nonce' => sprintf( '%032x', 99 ), 'received_gmt' => gmdate( 'Y-m-d H:i:s', time() - 899 ) ) );

		$this->assertSame( 2, Nonce_Store::prune( 2 ), 'bounded' );
		$this->assertSame( 3, Nonce_Store::prune( 500 ) );
		$this->assertSame( 1, $this->nonce_rows(), 'the young row stays' );
	}

	public function test_count_recent_counts_one_pairing_inside_the_window() {
		global $wpdb;
		$table = Tables::table_name( 'nonces' );
		$wpdb->insert( $table, array( 'pairing_id' => 3, 'nonce' => sprintf( '%032x', 1 ), 'received_gmt' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ) );
		Nonce_Store::insert( 3, sprintf( '%032x', 2 ) );
		Nonce_Store::insert( 3, sprintf( '%032x', 3 ) );
		Nonce_Store::insert( 4, sprintf( '%032x', 4 ) );

		$this->assertSame( 2, Nonce_Store::count_recent( 3, 60 ) );
		$this->assertSame( 3, Nonce_Store::count_recent( 3, 300 ) );
	}

	public function test_ten_failures_block_the_address_for_fifteen_minutes() {
		for ( $i = 1; $i <= 9; $i++ ) {
			$this->assertFalse( Throttle::record_failure( '203.0.113.5' ), "failure $i" );
			$this->assertSame( 0, Throttle::blocked_for( '203.0.113.5' ) );
		}

		$this->assertTrue( Throttle::record_failure( '203.0.113.5' ), 'tenth failure blocks' );
		$left = Throttle::blocked_for( '203.0.113.5' );
		$this->assertGreaterThan( 890, $left );
		$this->assertLessThanOrEqual( 900, $left );
		$this->assertSame( 0, Throttle::blocked_for( '203.0.113.6' ), 'other addresses unaffected' );
	}

	public function test_the_counter_restarts_after_its_window() {
		global $wpdb;
		for ( $i = 0; $i < 9; $i++ ) {
			Throttle::record_failure( '198.51.100.7' );
		}
		$wpdb->update( Tables::table_name( 'throttle' ), array( 'window_start' => gmdate( 'Y-m-d H:i:s', time() - 601 ) ), array( 'bucket' => 'ip:198.51.100.7' ) );

		$this->assertFalse( Throttle::record_failure( '198.51.100.7' ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE bucket = %s', Tables::table_name( 'throttle' ), 'ip:198.51.100.7' ) ) );
	}

	public function test_ipv6_addresses_share_one_canonical_bucket() {
		$this->assertSame( 'ip:2001:db8::/64', Throttle::bucket( '2001:0DB8:0000:0000:0000:0000:0000:0001' ) );
		$this->assertSame( '', Throttle::bucket( 'not-an-ip' ) );
		$this->assertFalse( Throttle::record_failure( 'not-an-ip' ) );
	}

	public function test_client_ip_comes_from_remote_addr_only() {
		$_SERVER['REMOTE_ADDR']          = '192.0.2.44';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.0.0.1';
		$_SERVER['HTTP_X_REAL_IP']       = '10.0.0.2';
		$this->assertSame( '192.0.2.44', Throttle::client_ip() );

		$_SERVER['REMOTE_ADDR'] = '192.0.2.44, 10.0.0.1';
		$this->assertSame( '', Throttle::client_ip() );

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'] );
	}

	public function test_prune_removes_spent_rows_only() {
		global $wpdb;
		$table = Tables::table_name( 'throttle' );
		$wpdb->insert( $table, array( 'bucket' => 'ip:192.0.2.1', 'hits' => 3, 'window_start' => gmdate( 'Y-m-d H:i:s', time() - 700 ) ) );
		$wpdb->insert( $table, array( 'bucket' => 'ip:192.0.2.2', 'hits' => 10, 'window_start' => gmdate( 'Y-m-d H:i:s', time() - 700 ), 'blocked_until' => gmdate( 'Y-m-d H:i:s', time() + 200 ) ) );
		$wpdb->insert( $table, array( 'bucket' => 'ip:192.0.2.3', 'hits' => 1, 'window_start' => gmdate( 'Y-m-d H:i:s', time() - 10 ) ) );

		$this->assertSame( 1, Throttle::prune() );
		$this->assertSame( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" ) );
	}

	/**
	 * KM-04: one IPv6 /64 is one bucket, so rotating addresses inside
	 * it neither escapes the block nor adds a row per address; an IPv4-mapped address is its IPv4.
	 *
	 * @group security
	 * @group review
	 */
	public function test_km04_an_ipv6_slash_64_is_one_bucket_and_mapped_ipv4_is_ipv4() {
		global $wpdb;
		for ( $i = 1; $i <= 10; $i++ ) {
			Throttle::record_failure( '2001:db8:aa:bb:' . dechex( $i ) . '::' . dechex( 100 + $i ) );
		}
		$this->assertGreaterThan( 0, Throttle::blocked_for( '2001:db8:aa:bb:ffff::1' ), 'the whole /64 is blocked' );
		$this->assertSame( 0, Throttle::blocked_for( '2001:db8:aa:bc::1' ), 'the next /64 is not' );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE bucket LIKE %s', Tables::table_name( 'throttle' ), 'ip:2001:db8:aa:bb%' ) ) );

		$this->assertSame( 'ip:192.0.2.9', Throttle::bucket( '::ffff:192.0.2.9' ) );
		$this->assertSame( 'ip:192.0.2.9', Throttle::bucket( '192.0.2.9' ) );
		$this->assertNotSame( Throttle::bucket( '::ffff:192.0.2.9' ), Throttle::bucket( '::ffff:192.0.2.10' ) );
	}
}
