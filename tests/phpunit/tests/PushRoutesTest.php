<?php
/**
 * Admin routes of pushes (T8): manage_options and the wp_rest nonce on every
 * route; POST /admin/pushes checks the typed live domain on the server (S-70 at the route; the
 * cookie-without-nonce half runs over HTTP in the security group, where core logs the cookie out).
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Sync\Plan_Store;

/**
 * @group rest
 * @group staging
 */
class PushRoutesTest extends WP_UnitTestCase {

	private static $admin;
	private static $editor;
	private $uuid;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );

		$this->uuid = wp_generate_uuid4();
		Pairing_Store::insert(
			array(
				'pairing_uuid'    => $this->uuid,
				'role'            => 'source',
				'status'          => 'active',
				'remote_url'      => 'https://live.example.com/',
				'rest_root'       => 'https://live.example.com/wp-json/',
				'local_home_url'  => Installer::normalised_home(),
				'local_home_hash' => Installer::home_hash(),
				'created_gmt'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	private function request( $method, $route, array $params = array(), $nonce = true ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		if ( ! empty( $params ) ) {
			$request->set_body_params( $params );
		}
		return rest_get_server()->dispatch( $request );
	}

	/** A plan row in state planned with a summary (no live needed for the checks before sending). */
	private function planned() {
		$id = Plan_Store::create( (int) Pairing_Store::find( $this->uuid )['id'], self::$admin, array( 'pairing' => $this->uuid ), array( 'undecided' => 0, 'blocked' => 0 ) );
		Plan_Store::update( $id, array( 'state' => 'planned' ) );
		return $id;
	}

	public function test_every_push_route_needs_manage_options_and_the_nonce() {
		$plan   = $this->planned();
		$routes = array(
			array( 'GET', '/keepmerge/v1/admin/pushes' ),
			array( 'POST', '/keepmerge/v1/admin/pushes' ),
			array( 'GET', '/keepmerge/v1/admin/pushes/' . $plan ),
			array( 'POST', '/keepmerge/v1/admin/pushes/' . $plan . '/step' ),
			array( 'POST', '/keepmerge/v1/admin/pushes/' . $plan . '/abort' ),
			array( 'POST', '/keepmerge/v1/admin/pushes/' . $plan . '/held' ),
			array( 'POST', '/keepmerge/v1/admin/pushes/' . $plan . '/continue' ),
		);
		foreach ( $routes as $route ) {
			wp_set_current_user( 0 );
			$this->assertSame( 401, $this->request( $route[0], $route[1] )->get_status(), implode( ' ', $route ) . ' anonymous' );
			wp_set_current_user( self::$editor );
			$this->assertSame( 403, $this->request( $route[0], $route[1] )->get_status(), implode( ' ', $route ) . ' editor' );
			wp_set_current_user( self::$admin );
			$this->assertSame( 'rest_cookie_invalid_nonce', $this->request( $route[0], $route[1], array(), false )->get_data()['code'], implode( ' ', $route ) . ' no nonce' );
		}
	}

	public function test_s70_the_typed_domain_is_checked_on_the_server() {
		wp_set_current_user( self::$admin );
		$plan = $this->planned();

		foreach ( array( '', 'live.example.co', 'staging.example.com', 'live.example.com.evil.net', 'https://live.example.com/' ) as $typed ) {
			$response = $this->request(
				'POST',
				'/keepmerge/v1/admin/pushes',
				array(
					'plan'    => $plan,
					'confirm' => $typed,
				)
			);
			$this->assertSame( 400, $response->get_status(), $typed );
			$this->assertSame( 'confirm_mismatch', $response->get_data()['code'], $typed );
		}
		$this->assertSame( 'planned', Plan_Store::find( $plan )['state'], 'nothing started' );

		// The right domain passes the check (and stops later at the empty plan).
		$response = $this->request(
			'POST',
			'/keepmerge/v1/admin/pushes',
			array(
				'plan'    => $plan,
				'confirm' => 'live.example.com',
			)
		);
		$this->assertNotSame( 'confirm_mismatch', $response->get_data()['code'] );
	}

	public function test_the_history_lists_both_sides() {
		wp_set_current_user( self::$admin );
		$data = $this->request( 'GET', '/keepmerge/v1/admin/pushes' )->get_data();
		$this->assertSame( array( 'now', 'received', 'lock', 'host', 'sent' ), array_keys( $data ) );
		$this->assertSame( 404, $this->request( 'GET', '/keepmerge/v1/admin/pushes/999999' )->get_status() );
	}
}
