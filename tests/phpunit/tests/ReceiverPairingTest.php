<?php
/**
 * Receiver_User (role, lock-down hooks, REST allowlist) and Pairing_Service (key, confirm,
 * finalize, approve, reject, revoke, expiry, deactivation, clone).
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Pairing_Key;
use SoftGlaze\SafePush\Pairing_Service;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Receiver_User;
use SoftGlaze\SafePush\Tables;

/**
 * @group pairing
 * @group receiver
 */
class ReceiverPairingTest extends WP_UnitTestCase {

	/**
	 * Administrator ID.
	 *
	 * @var int
	 */
	private static $admin;

	private $server_backup;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up() {
		parent::set_up();
		$this->server_backup    = $_SERVER;
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		Receive_Window::open( 1 );
	}

	public function tear_down() {
		$_SERVER = $this->server_backup;
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function key() {
		$created = Pairing_Service::create_key( self::$admin );
		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_code() : '' );
		return Pairing_Key::parse( $created['key'] );
	}

	private function passwords( $user_id ) {
		return count( WP_Application_Passwords::get_user_application_passwords( $user_id ) );
	}

	private function confirm( $uuid ) {
		return Pairing_Service::confirm(
			Pairing_Store::find( $uuid ),
			array(
				'site_id'  => '6f1c2a3b-1111-4222-8333-444455556666',
				'home_url' => 'https://staging.example.com/',
			)
		);
	}

	// --- Receiver user ------------------------------------------------------------------------

	public function test_key_creates_a_locked_down_receiver_with_one_bootstrap_password() {
		$key  = $this->key();
		$user = get_user_by( 'login', $key['login'] );

		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( array( Installer::ROLE ), $user->roles );
		$this->assertSame( '', $user->user_email );
		$this->assertTrue( user_can( $user, 'softglaze_sp_receive_push' ) );
		$this->assertFalse( user_can( $user, 'read' ) );
		$this->assertFalse( user_can( $user, 'edit_posts' ) );
		$this->assertFalse( user_can( $user, 'assign_terms' ) );
		$this->assertSame( 1, $this->passwords( $user->ID ) );

		$row = Pairing_Store::find( $key['pairing'] );
		$this->assertSame( 'pending', $row['status'] );
		$this->assertSame( Installer::home_hash(), $row['local_home_hash'] );
		$this->assertStringStartsWith( 'v1:', $row['bootstrap_secret_enc'] );
		$this->assertStringNotContainsString( $key['secret'], wp_json_encode( $row ), 'secret only encrypted' );
		$this->assertStringNotContainsString( $key['password'], wp_json_encode( $row ) );
		$this->assertEqualsWithDelta( time() + 1800, $key['expires'], 5 );
	}

	public function test_interactive_login_is_refused_whatever_the_password() {
		$key  = $this->key();
		$user = get_user_by( 'login', $key['login'] );
		wp_set_password( 'Known-Test-Password-1', $user->ID );

		$this->assertWPError( wp_authenticate( $key['login'], 'Known-Test-Password-1' ) );
		$this->assertSame( 'softglaze_sp_receiver_login', wp_authenticate( $key['login'], 'Known-Test-Password-1' )->get_error_code() );
		$this->assertSame( 'softglaze_sp_receiver_login', wp_authenticate( $key['login'], 'wrong' )->get_error_code(), 'no password oracle' );

		$other = self::factory()->user->create( array( 'user_pass' => 'Other-Pass-1' ) );
		$this->assertInstanceOf( WP_User::class, wp_authenticate( get_userdata( $other )->user_login, 'Other-Pass-1' ), 'other users unaffected' );
	}

	public function test_role_is_hidden_from_role_dropdowns() {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		$this->assertArrayNotHasKey( Installer::ROLE, get_editable_roles() );
		$this->assertArrayHasKey( 'editor', get_editable_roles() );
	}

	/**
	 * @dataProvider routes
	 *
	 * @param string $route   Route.
	 * @param bool   $allowed Expected.
	 */
	public function test_rest_allowlist( $route, $allowed ) {
		$this->assertSame( $allowed, Receiver_User::route_allowed( $route ) );
	}

	public function routes() {
		return array(
			'status'                 => array( '/keepmerge/v1/remote/status', true ),
			'confirm'                => array( '/keepmerge/v1/remote/pair/confirm', true ),
			'future push route'      => array( '/keepmerge/v1/remote/push/9f1c2a3b-4d5e-4f60-8a7b-1c2d3e4f5a6b/items', true ),
			'users/me'               => array( '/wp/v2/users/me', false ),
			'posts'                  => array( '/wp/v2/posts', false ),
			'settings'               => array( '/wp/v2/settings', false ),
			'batch'                  => array( '/batch/v1', false ),
			'index'                  => array( '/', false ),
			'our admin routes'       => array( '/keepmerge/v1/admin/overview', false ),
			'uppercase spelling'     => array( '/KEEPMERGE/v1/remote/status', false ),
			'dot segments'           => array( '/keepmerge/v1/remote/../../wp/v2/users', false ),
			'double slash'           => array( '/keepmerge/v1/remote//status', false ),
			'trailing slash'         => array( '/keepmerge/v1/remote/status/', false ),
			'prefix only'            => array( '/keepmerge/v1/remote/', false ),
			'namespace lookalike'    => array( '/keepmerge/v1/remotex/status', false ),
			'encoded'                => array( '/keepmerge/v1/remote/%2e%2e/users', false ),
			'not a string'           => array( null, false ),
		);
	}

	public function test_rest_pre_dispatch_refuses_the_receiver_outside_the_allowlist_only() {
		$key  = $this->key();
		$user = get_user_by( 'login', $key['login'] );

		wp_set_current_user( $user->ID );
		$refused = apply_filters( 'rest_pre_dispatch', null, rest_get_server(), new WP_REST_Request( 'GET', '/wp/v2/users/me' ) );
		$this->assertInstanceOf( WP_REST_Response::class, $refused );
		$this->assertSame( 403, $refused->get_status() );
		$this->assertSame( array( 'code' => 'receiver_scope_refused' ), $refused->get_data() );
		$this->assertNull( apply_filters( 'rest_pre_dispatch', null, rest_get_server(), new WP_REST_Request( 'GET', '/keepmerge/v1/remote/status' ) ) );

		// A role changed by hand does not lift the lock-down while a pairing records the user.
		$user->set_role( 'editor' );
		Receiver_User::flush_cache();
		$this->assertInstanceOf( WP_REST_Response::class, apply_filters( 'rest_pre_dispatch', null, rest_get_server(), new WP_REST_Request( 'GET', '/wp/v2/posts' ) ) );

		wp_set_current_user( self::$admin );
		$this->assertNull( apply_filters( 'rest_pre_dispatch', null, rest_get_server(), new WP_REST_Request( 'GET', '/wp/v2/users/me' ) ), 'administrators unaffected' );
	}

	public function test_delete_refuses_users_that_are_not_receivers() {
		$this->assertFalse( Receiver_User::delete( self::$admin ) );
		$this->assertInstanceOf( WP_User::class, get_userdata( self::$admin ) );
	}

	// --- Pairing lifecycle ----------------------------------------------------------------------

	public function test_no_key_while_the_window_is_closed_or_without_https() {
		Receive_Window::close();
		$this->assertSame( 'window_closed', Pairing_Service::create_key( self::$admin )->get_error_code() );

		Receive_Window::open( 1 );
		$_SERVER['HTTPS'] = 'off';
		$this->assertSame( 'https_required', Pairing_Service::create_key( self::$admin )->get_error_code() );
	}

	public function test_no_key_when_application_passwords_are_off_for_the_receiver() {
		add_filter( 'wp_is_application_passwords_available_for_user', '__return_false' );
		$before = count( get_users( array( 'role' => Installer::ROLE ) ) );

		$result = Pairing_Service::create_key( self::$admin );

		remove_filter( 'wp_is_application_passwords_available_for_user', '__return_false' );
		$this->assertSame( 'app_passwords_unavailable_for_user', $result->get_error_code() );
		$this->assertSame( $before, count( get_users( array( 'role' => Installer::ROLE ) ) ), 'receiver deleted again' );
	}

	public function test_repeated_confirm_replaces_the_unfinalized_long_term_password() {
		$key  = $this->key();
		$user = get_user_by( 'login', $key['login'] );

		$first = $this->confirm( $key['pairing'] );
		$this->assertSame( 2, $this->passwords( $user->ID ), 'bootstrap + long-term' );
		$uuid1 = Pairing_Store::find( $key['pairing'] )['app_password_uuid'];

		$second = $this->confirm( $key['pairing'] );
		$this->assertSame( 2, $this->passwords( $user->ID ), 'still bootstrap + one long-term' );
		$this->assertNotSame( $uuid1, Pairing_Store::find( $key['pairing'] )['app_password_uuid'] );
		$this->assertNotSame( $first['secret'], $second['secret'] );
		$this->assertNotSame( $first['app_password'], $second['app_password'] );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{64}\z/', $second['secret'] );
	}

	public function test_finalize_leaves_one_password_and_no_bootstrap_secret_then_approve() {
		$key  = $this->key();
		$user = get_user_by( 'login', $key['login'] );
		$this->confirm( $key['pairing'] );

		$result = Pairing_Service::finalize( Pairing_Store::find( $key['pairing'] ), '192.0.2.10' );
		$row    = Pairing_Store::find( $key['pairing'] );

		$this->assertSame( 'awaiting_approval', $result['status'] );
		$this->assertSame( 1, $this->passwords( $user->ID ) );
		$this->assertSame( '', $row['bootstrap_password_uuid'] );
		$this->assertNull( $row['bootstrap_secret_enc'] );
		$this->assertSame( '192.0.2.10', $row['source_ip'] );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, strtotime( $row['expires_gmt'] . ' UTC' ), 5 );

		$this->assertSame( 'active', Pairing_Service::approve( $key['pairing'], self::$admin )['status'] );
		$row = Pairing_Store::find( $key['pairing'] );
		$this->assertSame( (string) self::$admin, $row['approved_by'] );
		$this->assertNull( $row['expires_gmt'] );
		$this->assertSame( 'not_awaiting_approval', Pairing_Service::approve( $key['pairing'], self::$admin )->get_error_code() );
	}

	public function test_reject_and_revoke_delete_the_receiver_and_keep_the_row() {
		$key = $this->key();
		$this->confirm( $key['pairing'] );
		Pairing_Service::finalize( Pairing_Store::find( $key['pairing'] ), '192.0.2.10' );

		$this->assertSame( 'revoked', Pairing_Service::reject( $key['pairing'] )['status'] );
		$this->assertFalse( get_user_by( 'login', $key['login'] ) );
		$row = Pairing_Store::find( $key['pairing'] );
		$this->assertNull( $row['secret_enc'] );
		$this->assertNotNull( $row['revoked_gmt'] );
		$this->assertSame( 'already_revoked', Pairing_Service::revoke( $key['pairing'] )->get_error_code() );

		$key2 = $this->key();
		$this->assertSame( 'revoked', Pairing_Service::revoke( $key2['pairing'] )['status'] );
		$this->assertFalse( get_user_by( 'login', $key2['login'] ) );
	}

	public function test_expiry_cleanup_removes_pending_after_30_minutes_and_awaiting_after_24_hours() {
		$pending = $this->key();
		$waiting = $this->key();
		$this->confirm( $waiting['pairing'] );
		Pairing_Service::finalize( Pairing_Store::find( $waiting['pairing'] ), '192.0.2.10' );
		$fresh = $this->key();

		Pairing_Store::update( (int) Pairing_Store::find( $pending['pairing'] )['id'], array( 'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		Pairing_Store::update( (int) Pairing_Store::find( $waiting['pairing'] )['id'], array( 'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );

		$this->assertSame( 2, Pairing_Service::cleanup_expired() );
		$this->assertNull( Pairing_Store::find( $pending['pairing'] ) );
		$this->assertNull( Pairing_Store::find( $waiting['pairing'] ) );
		$this->assertFalse( get_user_by( 'login', $pending['login'] ) );
		$this->assertFalse( get_user_by( 'login', $waiting['login'] ) );
		$this->assertNotNull( Pairing_Store::find( $fresh['pairing'] ), 'unexpired key stays' );
	}

	public function test_deactivation_revokes_every_receiver_credential_and_activation_deletes_orphans() {
		$key = $this->key();
		$this->confirm( $key['pairing'] );
		Pairing_Service::finalize( Pairing_Store::find( $key['pairing'] ), '192.0.2.10' );
		Pairing_Service::approve( $key['pairing'], self::$admin );
		$user = get_user_by( 'login', $key['login'] );

		Installer::deactivate();

		$row = Pairing_Store::find( $key['pairing'] );
		$this->assertSame( 'repair_needed', $row['status'] );
		$this->assertSame( 0, $this->passwords( $user->ID ) );
		$this->assertNull( $row['secret_enc'] );
		$this->assertFalse( Receive_Window::state()['open'] );

		Installer::activate();
		$this->assertFalse( get_userdata( $user->ID ), 'orphaned receiver deleted on activation' );
	}

	public function test_clone_detection_resets_pairings_bound_to_the_other_home() {
		global $wpdb;
		$key = $this->key();
		$this->confirm( $key['pairing'] );
		Pairing_Service::finalize( Pairing_Store::find( $key['pairing'] ), '192.0.2.10' );
		Pairing_Service::approve( $key['pairing'], self::$admin );
		$user     = get_user_by( 'login', $key['login'] );
		$original = Installer::ensure_site_id();

		// What a host clone leaves behind: the copy's URL search-replaced into the stored URL,
		// the hash of the original home untouched.
		$stored         = get_option( Installer::SITE_ID_OPTION );
		$stored['home'] = Installer::normalised_home();
		$stored['home_hash'] = hash( 'sha256', 'https://live.example.com' );
		update_option( Installer::SITE_ID_OPTION, $stored );
		Pairing_Store::update( (int) Pairing_Store::find( $key['pairing'] )['id'], array( 'local_home_hash' => hash( 'sha256', 'https://live.example.com' ) ) );

		$new = Installer::ensure_site_id();

		$this->assertNotSame( $original, $new );
		$this->assertFalse( Receive_Window::state()['open'] );
		$row = Pairing_Store::find( $key['pairing'] );
		$this->assertSame( 'repair_needed', $row['status'] );
		$this->assertSame( 0, $this->passwords( $user->ID ) );
		$this->assertNull( $row['secret_enc'] );
		$audit = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event = %s ORDER BY id DESC LIMIT 1', Tables::table_name( 'audit' ), 'clone_detected' ), ARRAY_A );
		$this->assertSame( 1, json_decode( $audit['details'], true )['pairings_reset'] );
	}

	public function test_update_validates_author_and_ip_pin() {
		$key    = $this->key();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$sub    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( $editor, Pairing_Service::update( $key['pairing'], $editor, null )['default_author']['id'] );
		$this->assertSame( 'invalid_author', Pairing_Service::update( $key['pairing'], $sub, null )->get_error_code() );
		$receiver = get_user_by( 'login', $key['login'] )->ID;
		$this->assertSame( 'invalid_author', Pairing_Service::update( $key['pairing'], $receiver, null )->get_error_code() );
		$this->assertSame( '2001:db8::1', Pairing_Service::update( $key['pairing'], null, '2001:DB8:0:0:0:0:0:1' )['pinned_ip'] );
		$this->assertSame( 'invalid_ip', Pairing_Service::update( $key['pairing'], null, '10.0.0.0/8' )->get_error_code() );
		$this->assertSame( '', Pairing_Service::update( $key['pairing'], null, '' )['pinned_ip'] );
	}

	public function test_present_never_contains_credentials() {
		$key = $this->key();
		$this->confirm( $key['pairing'] );
		$json = wp_json_encode( Pairing_Service::list_destination() );
		foreach ( array( 'secret', 'app_password', 'password_uuid', 'v1:' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
		$this->assertStringNotContainsString( $key['secret'], $json );
	}
}
