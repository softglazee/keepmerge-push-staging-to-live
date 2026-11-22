<?php
/**
 * Hardening: the receiver's REST scope is the final answer
 * of rest_pre_dispatch, and the home hash that binds the site ID and the pairings does not follow
 * is_ssl() (an http:// home served over HTTPS through a TLS-terminating proxy), while clone
 * detection (S-80) still fires for a copied database.
 *
 * @package SoftGlaze\SafePush\Tests
 */

require_once __DIR__ . '/class-keepmerge-apply-harness.php';

use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Pairing_Key;
use SoftGlaze\SafePush\Pairing_Service;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Receiver_User;
use SoftGlaze\SafePush\Tables;

/**
 * @group hardening
 * @group security
 */
class HardeningTest extends Keepmerge_Apply_Harness {

	private function clone_events() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', Tables::table_name( 'audit' ), 'clone_detected' ) );
	}

	/**
	 * The harness's pairing steps again, on the home URL the test has set.
	 */
	private function pair_again() {
		global $wp_rest_server;

		Receive_Window::open( 1 );
		$created            = Pairing_Service::create_key( self::$admin );
		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_code() : '' );
		$key                = Pairing_Key::parse( $created['key'] );
		$this->pairing_uuid = $key['pairing'];
		$row                = Pairing_Store::find( $this->pairing_uuid );
		$this->receiver     = (int) $row['receiver_user_id'];
		$confirmed          = Pairing_Service::confirm(
			$row,
			array(
				'site_id'  => self::SOURCE_SITE,
				'home_url' => 'https://staging.example.com/',
			)
		);
		$this->creds        = array(
			'secret' => $confirmed['secret'],
			'uuid'   => Pairing_Store::find( $this->pairing_uuid )['app_password_uuid'],
		);
		Pairing_Service::finalize( Pairing_Store::find( $this->pairing_uuid ), '192.0.2.10' );
		Pairing_Service::approve( $this->pairing_uuid, self::$admin );

		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function test_the_receivers_scope_refusal_is_the_final_answer_of_rest_pre_dispatch() {
		// Another plugin answering the same filter later than core's default priority must not get
		// a receiver past the lock-down.
		$other = static function () {
			return new WP_REST_Response( array( 'served' => 'by another filter' ), 200 );
		};
		add_filter( 'rest_pre_dispatch', $other, 1000 );
		try {
			wp_set_current_user( $this->receiver );
			$refused = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/users/me' ) );
			wp_set_current_user( self::$admin );
			$admin = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/wp/v2/users/me' ) );
			wp_set_current_user( 0 );
		} finally {
			remove_filter( 'rest_pre_dispatch', $other, 1000 );
		}

		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( array( 'code' => 'receiver_scope_refused' ), $refused->get_data() );
		$this->assertSame( array( 'served' => 'by another filter' ), $admin->get_data(), 'other users are left to the other filter' );

		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_pre_dispatch', array( Receiver_User::class, 'limit_rest_scope' ) ) );

		// The receiver's own routes still pass through the chain (a signed status call answers).
		$this->assertSame( 200, $this->call( 'GET', '/keepmerge/v1/remote/status' )->get_status() );
	}

	public function test_the_home_hash_does_not_follow_is_ssl_for_an_http_home_behind_a_tls_proxy() {
		// The test site's home option is http://, the request arrives over HTTPS (proxy sets HTTPS=on).
		update_option( 'home', 'http://example.org' );
		update_option( 'siteurl', 'http://example.org' );
		$_SERVER['HTTPS'] = 'on';
		$this->assertSame( 'https://example.org', home_url(), 'core follows is_ssl()' );

		$id     = Installer::ensure_site_id();
		$events = $this->clone_events();
		$this->pair_again();

		// Cron, WP-CLI and plain HTTP requests see the http:// home.
		unset( $_SERVER['HTTPS'] );
		$this->assertSame( 'http://example.org', home_url() );
		$this->assertSame( $id, Installer::ensure_site_id(), 'no new site ID outside HTTPS' );
		$this->assertSame( 'active', Pairing_Store::find( $this->pairing_uuid )['status'] );

		$_SERVER['HTTPS'] = 'on';
		$this->assertSame( $id, Installer::ensure_site_id(), 'no new site ID over HTTPS' );
		$this->assertSame( $events, $this->clone_events(), 'no clone_detected event' );

		$response = $this->call( 'GET', '/keepmerge/v1/remote/status' );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'active', Pairing_Store::find( $this->pairing_uuid )['status'] );
	}

	public function test_s80_a_copied_database_on_another_home_is_still_detected_over_https_and_from_the_cli() {
		$id   = Installer::ensure_site_id();
		$user = $this->receiver;
		$this->assertSame( 'https://example.org', Installer::normalised_home() );

		// What a host clone leaves: the copy's URL search-replaced into the home option and into the
		// stored record, the hash of the original home untouched.
		update_option( 'home', 'https://staging.example.org' );
		update_option( 'siteurl', 'https://staging.example.org' );
		$stored         = get_option( Installer::SITE_ID_OPTION );
		$stored['home'] = 'https://staging.example.org';
		update_option( Installer::SITE_ID_OPTION, $stored );
		$events = $this->clone_events();

		unset( $_SERVER['HTTPS'] );
		$new = Installer::ensure_site_id();

		$this->assertNotSame( $id, $new );
		$this->assertSame( $events + 1, $this->clone_events() );
		$this->assertFalse( Receive_Window::state()['open'] );
		$row = Pairing_Store::find( $this->pairing_uuid );
		$this->assertSame( 'repair_needed', $row['status'] );
		$this->assertNull( $row['secret_enc'] );
		$this->assertCount( 0, WP_Application_Passwords::get_user_application_passwords( $user ) );

		// The pairing's credentials are refused on the copy, whatever the scheme of the request.
		$_SERVER['HTTPS'] = 'on';
		$this->assertSame( $new, Installer::ensure_site_id() );
		$this->assertSame( 401, $this->call( 'GET', '/keepmerge/v1/remote/status' )->get_status() );
	}

	public function test_the_normalised_home_comes_from_the_stored_option_and_keeps_its_scheme() {
		update_option( 'home', 'HTTP://Live.Example.COM:8443/Shop/' );
		$_SERVER['HTTPS'] = 'on';
		$this->assertSame( 'http://live.example.com:8443/Shop', Installer::normalised_home() );
		unset( $_SERVER['HTTPS'] );
		$this->assertSame( 'http://live.example.com:8443/Shop', Installer::normalised_home() );

		// A home_url filter (multilingual plugins rewrite it per request) does not move the binding.
		$filter = static function ( $url ) {
			return str_replace( 'live.example.com', 'fr.live.example.com', $url );
		};
		add_filter( 'home_url', $filter );
		try {
			$this->assertSame( 'http://live.example.com:8443/Shop', Installer::normalised_home() );
		} finally {
			remove_filter( 'home_url', $filter );
		}
	}
}
