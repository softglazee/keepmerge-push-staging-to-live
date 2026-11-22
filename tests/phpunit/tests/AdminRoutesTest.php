<?php
/**
 * Admin REST routes: only /admin/ routes exist, permission (capability and nonce), settings
 * validation through the route, audit routes.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Tables;

/**
 * @group rest
 */
class AdminRoutesTest extends WP_UnitTestCase {

	/**
	 * Administrator ID.
	 *
	 * @var int
	 */
	private static $admin;

	/**
	 * Editor ID.
	 *
	 * @var int
	 */
	private static $editor;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		delete_option( Settings::OPTION );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	/**
	 * A request carrying a valid wp_rest nonce for the current user.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @return WP_REST_Request
	 */
	private function request( $method, $route ) {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		return $request;
	}

	public function test_only_admin_routes_exist_in_the_namespace() {
		$routes = array_keys( rest_get_server()->get_routes( 'keepmerge/v1' ) );

		$this->assertNotEmpty( $routes );
		foreach ( $routes as $route ) {
			$this->assertTrue( '/keepmerge/v1' === $route || 0 === strpos( $route, '/keepmerge/v1/admin/' ), $route );
		}
		$this->assertContains( '/keepmerge/v1/admin/overview', $routes );
		$this->assertContains( '/keepmerge/v1/admin/settings', $routes );
		$this->assertContains( '/keepmerge/v1/admin/audit', $routes );
		$this->assertContains( '/keepmerge/v1/admin/audit/export', $routes );
		foreach ( $routes as $route ) {
			$this->assertStringNotContainsString( '/remote/', $route );
		}
	}

	public function test_every_route_has_a_real_permission_callback() {
		foreach ( rest_get_server()->get_routes( 'keepmerge/v1' ) as $route => $handlers ) {
			if ( '/keepmerge/v1' === $route ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				$this->assertArrayHasKey( 'permission_callback', $handler, $route );
				$this->assertNotSame( '__return_true', $handler['permission_callback'], $route );
				$this->assertIsCallable( $handler['permission_callback'], $route );
			}
		}
	}

	public function test_unauthenticated_requests_get_401() {
		wp_set_current_user( 0 );
		foreach ( array( '/keepmerge/v1/admin/overview', '/keepmerge/v1/admin/settings', '/keepmerge/v1/admin/audit', '/keepmerge/v1/admin/audit/export' ) as $route ) {
			$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );
			$this->assertSame( 401, $response->get_status(), $route );
		}
	}

	public function test_admin_without_nonce_is_refused() {
		wp_set_current_user( self::$admin );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/keepmerge/v1/admin/overview' ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_cookie_invalid_nonce', $response->get_data()['code'] );

		$bad = new WP_REST_Request( 'GET', '/keepmerge/v1/admin/overview' );
		$bad->set_header( 'X-WP-Nonce', 'abc123' );
		$this->assertSame( 403, rest_do_request( $bad )->get_status() );
	}

	public function test_editor_is_refused_even_with_a_nonce() {
		wp_set_current_user( self::$editor );
		$response = rest_do_request( $this->request( 'GET', '/keepmerge/v1/admin/settings' ) );
		$this->assertSame( 403, $response->get_status() );
	}

	public function test_overview_for_admin() {
		wp_set_current_user( self::$admin );
		$response = rest_do_request( $this->request( 'GET', '/keepmerge/v1/admin/overview' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( wp_is_uuid( $data['site']['site_id'] ) );
		$this->assertFalse( $data['receive']['open'] );
		$this->assertSame( array(), $data['pairings']['sends_to'] );
		$this->assertNull( $data['last_push'] );
		$ids = wp_list_pluck( $data['readiness'], 'id' );
		$this->assertSame( array( 'https', 'application_passwords', 'sodium', 'filesystem', 'time_limit', 'memory_limit', 'key_source', 'pinned_transport' ), $ids );
	}

	public function test_settings_route_returns_defaults_and_policy() {
		wp_set_current_user( self::$admin );
		$data = rest_do_request( $this->request( 'GET', '/keepmerge/v1/admin/settings' ) )->get_data();

		$this->assertFalse( $data['settings']['allow_code_report'] );
		$this->assertFalse( $data['settings']['remove_data_on_uninstall'] );
		$this->assertContains( 'siteurl', $data['policy']['option_denylist'] );
		$this->assertSame( array( 'min' => 10, 'max' => 200 ), $data['ranges']['item_batch'] );
		$this->assertNotEmpty( $data['choices']['content_types'] );
	}

	public function test_settings_post_rejects_denylisted_and_code_entries_with_reasons() {
		wp_set_current_user( self::$admin );
		$before = Settings::get();

		$request = $this->request( 'POST', '/keepmerge/v1/admin/settings' );
		$request->set_body_params(
			array(
				'custom_options' => array( 'siteurl', 'my_api_key', 'body{color:red}', '<script>alert(1)</script>', '<?php echo 1; ?>' ),
				'audit_days'     => 90,
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'invalid_settings', $data['code'] );
		$errors = $data['data']['errors']['custom_options'];
		$this->assertCount( 5, $errors );
		foreach ( $errors as $error ) {
			$this->assertNotEmpty( $error['reason'] );
		}
		$this->assertSame( $before, Settings::get(), 'nothing saved' );
	}

	public function test_settings_post_saves_and_audits_keys_only() {
		global $wpdb;
		wp_set_current_user( self::$admin );

		$request = $this->request( 'POST', '/keepmerge/v1/admin/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'allow_code_report' => true,
					'excluded_meta'     => array( '_my_cache_*' ),
					'option_groups'     => array( 'widgets' => false ),
				)
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['settings']['allow_code_report'] );
		$this->assertSame( array( '_my_cache_*' ), $data['settings']['excluded_meta'] );
		$this->assertContains( 'allow_code_report', $data['changed'] );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event = %s ORDER BY id DESC LIMIT 1', Tables::table_name( 'audit' ), 'settings_changed' ), ARRAY_A );
		$this->assertStringNotContainsString( '_my_cache_', $row['details'] );
		$this->assertContains( 'excluded_meta', json_decode( $row['details'], true )['keys'] );
	}

	public function test_settings_post_rejects_wrong_types_before_the_handler() {
		wp_set_current_user( self::$admin );
		$request = $this->request( 'POST', '/keepmerge/v1/admin/settings' );
		$request->set_body_params( array( 'allow_code_report' => 'yes please' ) );
		$response = rest_do_request( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );

		$long = $this->request( 'POST', '/keepmerge/v1/admin/settings' );
		$long->set_body_params( array( 'item_batch' => str_repeat( 'x', 21 ) ) );
		$response = rest_do_request( $long );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
	}

	/**
	 * A number field the admin empties reaches the handler and gets the field's
	 * own range message (it used to get core's "Invalid parameter(s): audit_days" with no field
	 * marked). Nothing is saved.
	 */
	public function test_an_emptied_or_wrong_number_field_gets_its_range_message_and_saves_nothing() {
		wp_set_current_user( self::$admin );
		Settings::save( array( 'audit_days' => 200 ) );
		$before = get_option( Settings::OPTION );

		foreach ( array( 'audit_days' => '', 'item_batch' => 'lots', 'chunk_kb' => -5, 'snapshot_days' => '7; DROP' ) as $field => $value ) {
			$request = $this->request( 'POST', '/keepmerge/v1/admin/settings' );
			$request->set_body_params( array( $field => $value ) );
			$response = rest_do_request( $request );
			$this->assertSame( 400, $response->get_status(), $field );
			$error = $response->as_error();
			$this->assertSame( 'invalid_settings', $error->get_error_code(), $field );
			$data  = $error->get_error_data();
			$range = Settings::RANGES[ $field ];
			$this->assertSame( sprintf( 'Enter a whole number from %1$d to %2$d.', $range[0], $range[1] ), $data['errors'][ $field ][0]['reason'], $field );
			$this->assertSame( substr( (string) $value, 0, 20 ), $data['errors'][ $field ][0]['entry'], $field );
		}

		$this->assertSame( $before, get_option( Settings::OPTION ) );

		$ok = $this->request( 'POST', '/keepmerge/v1/admin/settings' );
		$ok->set_body_params( array( 'audit_days' => '90', 'item_batch' => 20 ) );
		$response = rest_do_request( $ok );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 90, Settings::get()['audit_days'] );
		$this->assertSame( 20, Settings::get()['item_batch'] );
	}

	/**
	 * On a site without HTTPS core offers no Application Passwords; the readiness
	 * row names HTTPS as the reason, not a security plugin. With HTTPS and a plugin turning them
	 * off, it names the plugin case.
	 */
	public function test_the_application_passwords_row_names_the_real_reason() {
		wp_set_current_user( self::$admin );
		$row = function () {
			$data = rest_do_request( $this->request( 'GET', '/keepmerge/v1/admin/overview' ) )->get_data();
			foreach ( $data['readiness'] as $check ) {
				if ( 'application_passwords' === $check['id'] ) {
					return $check;
				}
			}
			return null;
		};

		$this->assertNotSame( 'local', wp_get_environment_type(), 'the test needs a non-local environment type' );
		unset( $_SERVER['HTTPS'] );
		$check = $row();
		$this->assertSame( 'error', $check['status'] );
		$this->assertStringContainsString( 'only on sites served over HTTPS', $check['detail'] );
		$this->assertStringNotContainsString( 'security plugin', $check['detail'] );

		$_SERVER['HTTPS'] = 'on';
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		$check = $row();
		$this->assertSame( 'error', $check['status'] );
		$this->assertStringContainsString( 'security plugin', $check['detail'] );

		remove_filter( 'wp_is_application_passwords_available', '__return_false' );
		$check = $row();
		$this->assertSame( 'ok', $check['status'] );

		unset( $_SERVER['HTTPS'] );
	}

	public function test_audit_routes() {
		wp_set_current_user( self::$admin );
		Settings::save( array( 'audit_days' => 200 ) );

		$request = $this->request( 'GET', '/keepmerge/v1/admin/audit' );
		$request->set_query_params( array( 'event' => 'settings_changed' ) );
		$data = rest_do_request( $request )->get_data();
		$this->assertGreaterThanOrEqual( 1, $data['total'] );
		$this->assertSame( 'settings_changed', $data['rows'][0]['event'] );
		$this->assertCount( 42, $data['events'] );

		$bad = $this->request( 'GET', '/keepmerge/v1/admin/audit' );
		$bad->set_query_params( array( 'event' => "' OR 1=1 --" ) );
		$this->assertSame( 400, rest_do_request( $bad )->get_status() );

		$export = $this->request( 'GET', '/keepmerge/v1/admin/audit/export' );
		$data   = rest_do_request( $export )->get_data();
		$this->assertStringStartsWith( '"id","created_gmt"', $data['csv'] );
		$this->assertMatchesRegularExpression( '/^keepmerge-audit-\d{4}-\d{2}-\d{2}\.csv$/', $data['filename'] );
	}
}
