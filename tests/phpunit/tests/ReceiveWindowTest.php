<?php
/**
 * Receive_Window: 1–24 whole hours, auto-close as the system, push-lock extension capped at
 * 24 hours after opening; remote routes exist only while open.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Tables;

/**
 * @group window
 */
class ReceiveWindowTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Receive_Window::OPTION );
		delete_option( Receive_Window::LOCK_OPTION );
	}

	private function closed_rows_by_system() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s AND actor_type = %s', Tables::table_name( 'audit' ), 'receive_closed', 'system' ) );
	}

	private function remote_routes() {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		$routes = array_filter(
			array_keys( $wp_rest_server->get_routes( 'keepmerge/v1' ) ),
			function ( $route ) {
				return false !== strpos( $route, '/remote/' );
			}
		);
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		return array_values( $routes );
	}

	public function test_closed_by_default_with_no_remote_routes() {
		$this->assertFalse( Receive_Window::is_open() );
		$this->assertSame( array(), $this->remote_routes() );
	}

	/**
	 * @dataProvider valid_hours
	 *
	 * @param mixed $hours Hours.
	 * @param int   $want  Expected hours.
	 */
	public function test_valid_durations( $hours, $want ) {
		$state = Receive_Window::open( $hours );
		$this->assertIsArray( $state );
		$this->assertSame( $want * HOUR_IN_SECONDS, $state['closes_at'] - $state['opened_at'] );
		$this->assertTrue( Receive_Window::is_open() );
		$this->assertSame( array( '/keepmerge/v1/remote/status', '/keepmerge/v1/remote/pair/confirm', '/keepmerge/v1/remote/pair/finalize', '/keepmerge/v1/remote/baseline', '/keepmerge/v1/remote/manifest', '/keepmerge/v1/remote/items/fetch', '/keepmerge/v1/remote/code-manifest', '/keepmerge/v1/remote/push', '/keepmerge/v1/remote/push/(?P<id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})', '/keepmerge/v1/remote/push/(?P<id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/items', '/keepmerge/v1/remote/push/(?P<id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/apply', '/keepmerge/v1/remote/push/(?P<id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/abort', '/keepmerge/v1/remote/push/(?P<id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/files', '/keepmerge/v1/remote/push/(?P<id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/files/(?P<ref>-?[0-9]{1,9})/chunks/(?P<n>-?[0-9]{1,9})', '/keepmerge/v1/remote/push/(?P<id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})/files/(?P<ref>-?[0-9]{1,9})/commit' ), $this->remote_routes() );
	}

	public function valid_hours() {
		return array(
			'1'           => array( 1, 1 ),
			'8'           => array( 8, 8 ),
			'24'          => array( 24, 24 ),
			'"12" string' => array( '12', 12 ),
		);
	}

	/**
	 * @dataProvider invalid_hours
	 *
	 * @param mixed $hours Hours.
	 */
	public function test_out_of_range_durations_are_refused( $hours ) {
		$result = Receive_Window::open( $hours );
		$this->assertWPError( $result );
		$this->assertSame( 'hours_out_of_range', $result->get_error_code() );
		$this->assertFalse( Receive_Window::is_open() );
	}

	public function invalid_hours() {
		return array(
			'0'         => array( 0 ),
			'25'        => array( 25 ),
			'48'        => array( 48 ),
			'-1'        => array( -1 ),
			'1.5'       => array( 1.5 ),
			'"1.5"'     => array( '1.5' ),
			'"8h"'      => array( '8h' ),
			'empty'     => array( '' ),
			'null'      => array( null ),
			'true'      => array( true ),
			'forever'   => array( 'forever' ),
		);
	}

	public function test_expiry_closes_it_as_the_system_and_routes_disappear() {
		Receive_Window::open( 1 );
		$before = $this->closed_rows_by_system();
		$state  = Receive_Window::state();
		$state['closes_at'] = time() - 1;
		update_option( Receive_Window::OPTION, $state );

		$this->assertSame( array(), $this->remote_routes() );
		$this->assertFalse( Receive_Window::state()['open'] );
		$this->assertSame( $before + 1, $this->closed_rows_by_system() );
	}

	public function test_a_fresh_push_lock_keeps_it_open_and_a_stale_one_does_not() {
		Receive_Window::open( 1 );
		$state              = Receive_Window::state();
		$state['closes_at'] = time() - 1;
		update_option( Receive_Window::OPTION, $state );

		update_option( Receive_Window::LOCK_OPTION, array( 'push_uuid' => 'x', 'heartbeat' => time() - 10 ) );
		$this->assertTrue( Receive_Window::is_open() );

		update_option( Receive_Window::LOCK_OPTION, array( 'push_uuid' => 'x', 'heartbeat' => time() - 301 ) );
		$this->assertFalse( Receive_Window::is_open() );
	}

	public function test_no_lock_keeps_it_open_beyond_24_hours_after_opening() {
		update_option(
			Receive_Window::OPTION,
			array(
				'open'      => true,
				'opened_at' => time() - DAY_IN_SECONDS - 1,
				'closes_at' => time() - 1,
			)
		);
		update_option( Receive_Window::LOCK_OPTION, array( 'push_uuid' => 'x', 'heartbeat' => time() ) );

		$this->assertFalse( Receive_Window::is_open() );
	}

	public function test_closing_by_hand_is_immediate_and_audited_once() {
		global $wpdb;
		Receive_Window::open( 24 );
		$this->assertTrue( Receive_Window::close( 'manual' ) );
		$this->assertFalse( Receive_Window::is_open() );
		$this->assertFalse( Receive_Window::close( 'manual' ), 'already closed' );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event = %s ORDER BY id DESC LIMIT 1', Tables::table_name( 'audit' ), 'receive_closed' ), ARRAY_A );
		$this->assertSame( 'manual', json_decode( $row['details'], true )['reason'] );
	}
}
