<?php
/**
 * Rest\Remote_Permission: the remote permission chain and the pairing-state × route ×
 * credential-set table.
 *
 * Requests are built in-process; core's Application Password step is represented by what it
 * leaves behind (the current user and rest_get_authenticated_app_password()).
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Nonce_Store;
use SoftGlaze\SafePush\Pairing_Key;
use SoftGlaze\SafePush\Pairing_Service;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Rest\Remote_Permission;
use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Signer;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Throttle;

/**
 * @group rest
 * @group permission
 */
class RemotePermissionTest extends WP_UnitTestCase {

	const ROUTES = array(
		'status'   => array( 'GET', '/keepmerge/v1/remote/status' ),
		'confirm'  => array( 'POST', '/keepmerge/v1/remote/pair/confirm' ),
		'finalize' => array( 'POST', '/keepmerge/v1/remote/pair/finalize' ),
		'baseline' => array( 'POST', '/keepmerge/v1/remote/baseline' ),
		'manifest' => array( 'POST', '/keepmerge/v1/remote/manifest' ),
		'fetch'    => array( 'POST', '/keepmerge/v1/remote/items/fetch' ),
		'code_manifest' => array( 'POST', '/keepmerge/v1/remote/code-manifest' ),
		'push'          => array( 'POST', '/keepmerge/v1/remote/push' ),
		'push_get'      => array( 'GET', '/keepmerge/v1/remote/push/0f6e2c1a-5b7d-4c3e-9a8b-1c2d3e4f5a6b' ),
		'push_items'    => array( 'POST', '/keepmerge/v1/remote/push/0f6e2c1a-5b7d-4c3e-9a8b-1c2d3e4f5a6b/items' ),
		'push_abort'    => array( 'POST', '/keepmerge/v1/remote/push/0f6e2c1a-5b7d-4c3e-9a8b-1c2d3e4f5a6b/abort' ),
		'push_files'    => array( 'POST', '/keepmerge/v1/remote/push/0f6e2c1a-5b7d-4c3e-9a8b-1c2d3e4f5a6b/files' ),
		'push_chunk'    => array( 'PUT', '/keepmerge/v1/remote/push/0f6e2c1a-5b7d-4c3e-9a8b-1c2d3e4f5a6b/files/1/chunks/0' ),
		'push_commit'   => array( 'POST', '/keepmerge/v1/remote/push/0f6e2c1a-5b7d-4c3e-9a8b-1c2d3e4f5a6b/files/1/commit' ),
		'push_apply'    => array( 'POST', '/keepmerge/v1/remote/push/0f6e2c1a-5b7d-4c3e-9a8b-1c2d3e4f5a6b/apply' ),
	);

	/**
	 * The push session routes, the media routes and apply: an active
	 * pairing with its long-term credentials only.
	 */
	const PUSH_ROUTES = array( 'push', 'push_get', 'push_items', 'push_abort', 'push_files', 'push_chunk', 'push_commit', 'push_apply' );

	/**
	 * The read-only routes of an active pairing.
	 */
	const READ_ROUTES = array( 'baseline', 'manifest', 'fetch' );

	const SOURCE_SITE = '6f1c2a3b-1111-4222-8333-444455556666';

	/**
	 * Administrator ID.
	 *
	 * @var int
	 */
	private static $admin;

	private $uuid;
	private $receiver;
	private $bootstrap;
	private $long_term;
	private $server_backup;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up() {
		parent::set_up();
		global $wpdb;

		$this->server_backup    = $_SERVER;
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$wpdb->query( 'DELETE FROM ' . Tables::table_name( 'throttle' ) );
		$wpdb->query( 'DELETE FROM ' . Tables::table_name( 'nonces' ) );

		Receive_Window::open( 1 );
		$created = Pairing_Service::create_key( self::$admin );
		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_code() : '' );

		$key             = Pairing_Key::parse( $created['key'] );
		$row             = Pairing_Store::find( $key['pairing'] );
		$this->uuid      = $key['pairing'];
		$this->receiver  = (int) $row['receiver_user_id'];
		$this->bootstrap = array(
			'secret' => $key['secret'],
			'uuid'   => $row['bootstrap_password_uuid'],
			'site'   => '-',
		);
	}

	public function tear_down() {
		$_SERVER = $this->server_backup;
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	private function row() {
		return Pairing_Store::find( $this->uuid );
	}

	private function confirm() {
		$res             = Pairing_Service::confirm(
			$this->row(),
			array(
				'site_id'  => self::SOURCE_SITE,
				'home_url' => 'https://staging.example.com/',
			)
		);
		$this->long_term = array(
			'secret' => $res['secret'],
			'uuid'   => $this->row()['app_password_uuid'],
			'site'   => self::SOURCE_SITE,
		);
	}

	private function finalize() {
		$this->confirm();
		Pairing_Service::finalize( $this->row(), '192.0.2.10' );
	}

	private function activate() {
		$this->finalize();
		Pairing_Service::approve( $this->uuid, self::$admin );
	}

	/**
	 * A request signed as the source would sign it, with core's authentication outcome set.
	 *
	 * @param string $route_id status, confirm or finalize.
	 * @param array  $creds    Credential set (secret, uuid, site).
	 * @param array  $o        Overrides.
	 * @return WP_REST_Request
	 */
	private function request( $route_id, array $creds, array $o = array() ) {
		list( $method, $route ) = self::ROUTES[ $route_id ];

		$body      = isset( $o['body'] ) ? $o['body'] : ( 'GET' === $method ? '' : '{}' );
		$ts        = isset( $o['ts'] ) ? $o['ts'] : time();
		$nonce     = isset( $o['nonce'] ) ? $o['nonce'] : Signer::new_nonce();
		$site      = isset( $o['site'] ) ? $o['site'] : $creds['site'];
		$hash      = Signer::body_hash( $body );
		$canonical = Signer::canonical( $method, $route, array(), $this->uuid, $site, $ts, $nonce, $hash );

		$headers = array(
			'X-SGSP-Version'        => '1',
			'X-SGSP-Pairing'        => $this->uuid,
			'X-SGSP-Site'           => $site,
			'X-SGSP-Timestamp'      => (string) $ts,
			'X-SGSP-Nonce'          => $nonce,
			'X-SGSP-Content-SHA256' => isset( $o['hash'] ) ? $o['hash'] : $hash,
			'X-SGSP-Signature'      => isset( $o['signature'] ) ? $o['signature'] : Signer::sign( $canonical, $creds['secret'] ),
			'Authorization'         => 'Basic dGVzdA==',
		);
		foreach ( isset( $o['drop'] ) ? $o['drop'] : array() as $name ) {
			unset( $headers[ $name ] );
		}
		if ( isset( $o['headers'] ) ) {
			$headers = array_merge( $headers, $o['headers'] );
		}

		$request = new WP_REST_Request( $method, $route );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( isset( $o['send_body'] ) ? $o['send_body'] : $body );

		wp_set_current_user( 0 );
		wp_set_current_user( array_key_exists( 'user', $o ) ? $o['user'] : $this->receiver );
		$GLOBALS['wp_rest_application_password_uuid'] = array_key_exists( 'app_uuid', $o ) ? $o['app_uuid'] : $creds['uuid'];

		return $request;
	}

	private function assertRefused( $result, $code, $status ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	private function rows( $table ) {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::table_name( $table ) );
	}

	private function clear_throttle() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Tables::table_name( 'throttle' ) );
	}

	private function throttle_hits() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COALESCE(SUM(hits),0) FROM ' . Tables::table_name( 'throttle' ) );
	}

	// --- The state × route × credential-set table -------------------------------------------

	public function test_pending_without_long_term_credentials() {
		$this->assertTrue( Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' ) );
		$this->assertTrue( Remote_Permission::check( $this->request( 'confirm', $this->bootstrap ), 'confirm' ) );
		$this->assertRefused( Remote_Permission::check( $this->request( 'finalize', $this->bootstrap ), 'finalize' ), 'pairing_invalid', 401 );
	}

	public function test_pending_with_long_term_credentials_issued() {
		$this->confirm();

		$this->assertTrue( Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' ) );
		$this->assertTrue( Remote_Permission::check( $this->request( 'confirm', $this->bootstrap ), 'confirm' ) );
		$this->assertTrue( Remote_Permission::check( $this->request( 'finalize', $this->long_term ), 'finalize' ) );

		// Each credential set only where the state expects it.
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->long_term ), 'status' ), 'credentials_invalid', 401 );
		$this->assertRefused( Remote_Permission::check( $this->request( 'finalize', $this->bootstrap ), 'finalize' ), 'credentials_invalid', 401 );

		// One credential of each set: bootstrap password, long-term secret.
		$mixed = array(
			'secret' => $this->long_term['secret'],
			'uuid'   => $this->bootstrap['uuid'],
			'site'   => '-',
		);
		$this->assertRefused( Remote_Permission::check( $this->request( 'confirm', $mixed ), 'confirm' ), 'signature_invalid', 401 );
	}

	public function test_awaiting_approval_answers_status_only() {
		$this->finalize();

		$this->assertTrue( Remote_Permission::check( $this->request( 'status', $this->long_term ), 'status' ) );
		$this->assertRefused( Remote_Permission::check( $this->request( 'confirm', $this->long_term ), 'confirm' ), 'pairing_not_approved', 403 );
		$this->assertRefused( Remote_Permission::check( $this->request( 'finalize', $this->long_term ), 'finalize' ), 'pairing_not_approved', 403 );
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' ), 'credentials_invalid', 401 );
		foreach ( self::READ_ROUTES as $route_id ) {
			$this->assertRefused( Remote_Permission::check( $this->request( $route_id, $this->long_term ), $route_id ), 'pairing_not_approved', 403 );
		}
	}

	public function test_active() {
		$this->activate();

		$this->assertTrue( Remote_Permission::check( $this->request( 'status', $this->long_term ), 'status' ) );
		$this->assertRefused( Remote_Permission::check( $this->request( 'confirm', $this->long_term ), 'confirm' ), 'pairing_invalid', 401 );
		$this->assertRefused( Remote_Permission::check( $this->request( 'finalize', $this->long_term ), 'finalize' ), 'pairing_invalid', 401 );
		foreach ( self::READ_ROUTES as $route_id ) {
			$this->assertTrue( Remote_Permission::check( $this->request( $route_id, $this->long_term ), $route_id ), $route_id );
		}
	}

	public function test_read_routes_answer_an_active_pairing_only() {
		// pending: neither credential set opens a read route.
		foreach ( self::READ_ROUTES as $route_id ) {
			$this->assertRefused( Remote_Permission::check( $this->request( $route_id, $this->bootstrap ), $route_id ), 'pairing_invalid', 401 );
		}
		$this->confirm();
		foreach ( self::READ_ROUTES as $route_id ) {
			$this->assertRefused( Remote_Permission::check( $this->request( $route_id, $this->long_term ), $route_id ), 'pairing_invalid', 401 );
			$this->assertRefused( Remote_Permission::check( $this->request( $route_id, $this->bootstrap ), $route_id ), 'pairing_invalid', 401 );
		}

		// Each refusal counts against the address; start the next states with a clean count.
		$this->clear_throttle();

		// awaiting_approval: 403 pairing_not_approved (S-08).
		Pairing_Service::finalize( $this->row(), '192.0.2.10' );
		foreach ( self::READ_ROUTES as $route_id ) {
			$this->assertRefused( Remote_Permission::check( $this->request( $route_id, $this->long_term ), $route_id ), 'pairing_not_approved', 403 );
		}

		// active: the long-term set only; the bootstrap set no longer exists.
		$this->clear_throttle();
		Pairing_Service::approve( $this->uuid, self::$admin );
		foreach ( self::READ_ROUTES as $route_id ) {
			$this->assertTrue( Remote_Permission::check( $this->request( $route_id, $this->long_term ), $route_id ), $route_id );
			$this->assertRefused( Remote_Permission::check( $this->request( $route_id, $this->bootstrap ), $route_id ), 'credentials_invalid', 401 );
		}
	}

	public function test_push_routes_answer_an_active_pairing_with_long_term_credentials_only() {
		// pending, bootstrap set: refused like every route past confirm.
		foreach ( self::PUSH_ROUTES as $route_id ) {
			$this->assertRefused( Remote_Permission::check( $this->request( $route_id, $this->bootstrap ), $route_id ), 'pairing_invalid', 401 );
			$this->assertSame( 'not_active', $this->last_reason(), $route_id );
		}
		// Seven routes refused twice would block the address (10 per 10 minutes).
		$this->clear_throttle();
		// pending with long-term credentials issued: still refused.
		$this->confirm();
		foreach ( self::PUSH_ROUTES as $route_id ) {
			$this->assertRefused( Remote_Permission::check( $this->request( $route_id, $this->long_term ), $route_id ), 'pairing_invalid', 401 );
		}
		$this->clear_throttle();

		// awaiting_approval: 403, nothing about pushes is revealed.
		Pairing_Service::finalize( $this->row(), '192.0.2.10' );
		foreach ( self::PUSH_ROUTES as $route_id ) {
			$this->assertRefused( Remote_Permission::check( $this->request( $route_id, $this->long_term ), $route_id ), 'pairing_not_approved', 403 );
		}
		$this->clear_throttle();

		// active: the long-term set opens them; the destroyed bootstrap set does not.
		Pairing_Service::approve( $this->uuid, self::$admin );
		foreach ( self::PUSH_ROUTES as $route_id ) {
			$this->assertTrue( Remote_Permission::check( $this->request( $route_id, $this->long_term ), $route_id ), $route_id );
			$this->assertRefused( Remote_Permission::check( $this->request( $route_id, $this->bootstrap ), $route_id ), 'credentials_invalid', 401 );
		}
		$this->assertSame( 'long_term', Remote_Permission::credential_set( $this->row(), 'push_items' ) );
	}

	private function last_reason() {
		global $wpdb;
		$details = $wpdb->get_var( $wpdb->prepare( 'SELECT details FROM %i WHERE event = %s ORDER BY id DESC LIMIT 1', Tables::table_name( 'audit' ), 'auth_failed' ) );
		$details = json_decode( (string) $details, true );
		return isset( $details['reason'] ) ? $details['reason'] : '';
	}

	public function test_code_manifest_needs_an_active_pairing_and_lives_switch() {
		// Before approval it is refused like every read route, so the switch is never revealed.
		$this->assertRefused( Remote_Permission::check( $this->request( 'code_manifest', $this->bootstrap ), 'code_manifest' ), 'pairing_invalid', 401 );
		$this->finalize();
		$this->assertRefused( Remote_Permission::check( $this->request( 'code_manifest', $this->long_term ), 'code_manifest' ), 'pairing_not_approved', 403 );
		$this->clear_throttle();
		Pairing_Service::approve( $this->uuid, self::$admin );

		// Off (the default): an authentic request gets 403 code_report_disabled, audited, and is
		// not counted as a failure of the address.
		$this->assertFalse( Settings::get()['allow_code_report'] );
		$nonces = $this->rows( 'nonces' );
		$hits   = $this->throttle_hits();
		$this->assertRefused( Remote_Permission::check( $this->request( 'code_manifest', $this->long_term ), 'code_manifest' ), 'code_report_disabled', 403 );
		$this->assertSame( $nonces + 1, $this->rows( 'nonces' ) );
		$this->assertSame( $hits, $this->throttle_hits() );
		global $wpdb;
		$this->assertSame( 'denied', $wpdb->get_var( $wpdb->prepare( 'SELECT result FROM %i WHERE event = %s ORDER BY id DESC LIMIT 1', Tables::table_name( 'audit' ), 'code_manifest_requested' ) ) );

		// A forged request learns nothing about the switch.
		$this->assertRefused( Remote_Permission::check( $this->request( 'code_manifest', $this->long_term, array( 'signature' => str_repeat( 'a', 64 ) ) ), 'code_manifest' ), 'signature_invalid', 401 );

		// On: answered; off again: refused again.
		Settings::save( array( 'allow_code_report' => true ) );
		$this->assertTrue( Remote_Permission::check( $this->request( 'code_manifest', $this->long_term ), 'code_manifest' ) );
		Settings::save( array( 'allow_code_report' => false ) );
		$this->assertRefused( Remote_Permission::check( $this->request( 'code_manifest', $this->long_term ), 'code_manifest' ), 'code_report_disabled', 403 );
	}

	/**
	 * @dataProvider dead_states
	 *
	 * @param string $status Status forced on the row.
	 */
	public function test_dead_states_refuse_every_route( $status ) {
		$this->activate();
		Pairing_Store::update( (int) $this->row()['id'], array( 'status' => $status ) );

		foreach ( array_keys( self::ROUTES ) as $route_id ) {
			// Eleven refusals in a row would block the address (10 per 10 minutes): count each alone.
			$this->clear_throttle();
			$this->assertRefused( Remote_Permission::check( $this->request( $route_id, $this->long_term ), $route_id ), 'pairing_invalid', 401 );
		}
	}

	public function dead_states() {
		return array(
			'repair_needed' => array( 'repair_needed' ),
			'revoked'       => array( 'revoked' ),
		);
	}

	public function test_expired_pending_and_awaiting_pairings_are_invalid() {
		Pairing_Store::update( (int) $this->row()['id'], array( 'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' ), 'pairing_invalid', 401 );

		Pairing_Store::update( (int) $this->row()['id'], array( 'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() + 600 ) ) );
		$this->finalize();
		Pairing_Store::update( (int) $this->row()['id'], array( 'expires_gmt' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->long_term ), 'status' ), 'pairing_invalid', 401 );
	}

	public function test_unknown_pairing_and_another_home_are_invalid() {
		$request = $this->request( 'status', $this->bootstrap );
		$request->set_header( 'X-SGSP-Pairing', wp_generate_uuid4() );
		$this->assertRefused( Remote_Permission::check( $request, 'status' ), 'pairing_invalid', 401 );

		Pairing_Store::update( (int) $this->row()['id'], array( 'local_home_hash' => hash( 'sha256', 'https://another.example' ) ) );
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' ), 'pairing_invalid', 401 );
	}

	// --- The other steps, in order ------------------------------------------------------------

	public function test_step_1_https() {
		$_SERVER['HTTPS'] = 'off';
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' ), 'https_required', 403 );
	}

	/**
	 * @dataProvider bad_headers
	 *
	 * @param array $o Overrides.
	 */
	public function test_step_2_headers( array $o ) {
		$before = $this->rows( 'nonces' );
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap, $o ), 'status' ), 'bad_headers', 400 );
		$this->assertSame( $before, $this->rows( 'nonces' ) );
	}

	public function bad_headers() {
		return array(
			'version 2'          => array( array( 'headers' => array( 'X-SGSP-Version' => '2' ) ) ),
			'version missing'    => array( array( 'drop' => array( 'X-SGSP-Version' ) ) ),
			'signature missing'  => array( array( 'drop' => array( 'X-SGSP-Signature' ) ) ),
			'uppercase nonce'    => array( array( 'nonce' => str_repeat( 'A', 32 ) ) ),
			'short nonce'        => array( array( 'nonce' => str_repeat( 'a', 31 ) ) ),
			'timestamp text'     => array( array( 'ts' => '12e4' ) ),
			'site not a uuid'    => array( array( 'site' => 'staging' ) ),
			'pairing not a uuid' => array( array( 'headers' => array( 'X-SGSP-Pairing' => 'x' ) ) ),
			'two signatures'     => array( array( 'headers' => array( 'X-SGSP-Signature' => array( str_repeat( 'a', 64 ), str_repeat( 'b', 64 ) ) ) ) ),
		);
	}

	public function test_step_3_blocked_address_is_refused_before_signature_work() {
		for ( $i = 0; $i < 10; $i++ ) {
			Throttle::record_failure( '192.0.2.10' );
		}
		$result = Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' );
		$this->assertRefused( $result, 'ip_blocked', 429 );
		$this->assertGreaterThan( 0, $result->get_error_data()['retry_after'] );
		$this->assertSame( 0, $this->rows( 'nonces' ) );
	}

	public function test_step_4_clock() {
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap, array( 'ts' => time() - 301 ) ), 'status' ), 'clock_skew', 401 );
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap, array( 'ts' => time() + 301 ) ), 'status' ), 'clock_skew', 401 );
		$this->assertTrue( Remote_Permission::check( $this->request( 'status', $this->bootstrap, array( 'ts' => time() - 299 ) ), 'status' ) );
		$this->assertSame( 2, $this->throttle_hits() );
	}

	public function test_step_6_user() {
		$r = $this->request( 'status', $this->bootstrap, array( 'user' => 0, 'drop' => array( 'Authorization' ) ) );
		$this->assertRefused( Remote_Permission::check( $r, 'status' ), 'authorization_header_missing', 401 );

		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap, array( 'user' => 0 ) ), 'status' ), 'credentials_invalid', 401 );
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap, array( 'user' => self::$admin ) ), 'status' ), 'credentials_invalid', 401 );
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap, array( 'app_uuid' => null ) ), 'status' ), 'credentials_invalid', 401 );

		get_userdata( $this->receiver )->remove_cap( 'softglaze_sp_receive_push' );
		get_userdata( $this->receiver )->set_role( 'subscriber' );
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' ), 'credentials_invalid', 401 );
	}

	public function test_step_7_body_hash() {
		$r = $this->request( 'confirm', $this->bootstrap, array( 'send_body' => '{"x":1}' ) );
		$this->assertRefused( Remote_Permission::check( $r, 'confirm' ), 'body_hash_mismatch', 400 );
	}

	public function test_step_8_signature() {
		$before = $this->rows( 'nonces' );
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap, array( 'signature' => str_repeat( '0', 64 ) ) ), 'status' ), 'signature_invalid', 401 );
		$r = $this->request( 'confirm', $this->bootstrap, array( 'send_body' => '{"x":1}', 'hash' => Signer::body_hash( '{"x":1}' ) ) );
		$this->assertRefused( Remote_Permission::check( $r, 'confirm' ), 'signature_invalid', 401 );
		$this->assertSame( $before, $this->rows( 'nonces' ), 'no nonce stored before a valid signature' );
	}

	public function test_step_8_undecryptable_secret_needs_a_new_key() {
		Pairing_Store::update( (int) $this->row()['id'], array( 'bootstrap_secret_enc' => 'v1:' . str_repeat( 'ab', 60 ) ) );
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' ), 'signature_invalid', 401 );
		$this->assertSame( 'repair_needed', $this->row()['status'] );
	}

	public function test_step_9_source_site_and_ip_pin() {
		$this->activate();
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->long_term, array( 'site' => wp_generate_uuid4() ) ), 'status' ), 'source_mismatch', 403 );

		Pairing_Service::update( $this->uuid, null, '192.0.2.99' );
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->long_term ), 'status' ), 'source_mismatch', 403 );

		Pairing_Service::update( $this->uuid, null, '192.0.2.10' );
		$this->assertTrue( Remote_Permission::check( $this->request( 'status', $this->long_term ), 'status' ) );

		// An IPv6 pin is one address, not the throttle's /64 (the KM-04 fix kept the pin exact).
		Pairing_Service::update( $this->uuid, null, '2001:DB8::10' );
		$_SERVER['REMOTE_ADDR'] = '2001:db8::11';
		$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->long_term ), 'status' ), 'source_mismatch', 403 );
		$_SERVER['REMOTE_ADDR'] = '2001:0db8:0000:0000:0000:0000:0000:0010';
		$this->assertTrue( Remote_Permission::check( $this->request( 'status', $this->long_term ), 'status' ) );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
	}

	public function test_step_10_replay_and_double_invocation() {
		$nonce = Signer::new_nonce();
		$first = $this->request( 'status', $this->bootstrap, array( 'nonce' => $nonce, 'ts' => time() ) );
		$this->assertTrue( Remote_Permission::check( $first, 'status' ) );
		// Core calls the permission callback again for the Allow header: same outcome, no new row.
		$this->assertTrue( Remote_Permission::check( $first, 'status' ) );
		$this->assertSame( 1, $this->rows( 'nonces' ) );

		$again = $this->request( 'status', $this->bootstrap, array( 'nonce' => $nonce, 'ts' => (int) $first->get_header( 'X-SGSP-Timestamp' ) ) );
		$this->assertRefused( Remote_Permission::check( $again, 'status' ), 'replay_blocked', 409 );
		$this->assertSame( 1, $this->rows( 'nonces' ) );
	}

	public function test_step_11_rate_limit() {
		global $wpdb;
		$id = (int) $this->row()['id'];
		for ( $i = 0; $i < 300; $i++ ) {
			$wpdb->insert( Tables::table_name( 'nonces' ), array( 'pairing_id' => $id, 'nonce' => sprintf( '%032x', $i ), 'received_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
		}
		$result = Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' );
		$this->assertRefused( $result, 'rate_limited', 429 );
		$this->assertSame( Nonce_Store::RATE_WINDOW, $result->get_error_data()['retry_after'] );
	}

	public function test_failures_count_for_the_address_and_successes_do_not() {
		$this->assertTrue( Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' ) );
		$this->assertSame( 0, $this->throttle_hits() );

		Remote_Permission::check( $this->request( 'status', $this->bootstrap, array( 'signature' => str_repeat( '0', 64 ) ) ), 'status' );
		Remote_Permission::check( $this->request( 'status', $this->bootstrap, array( 'ts' => 1 ) ), 'status' );
		$this->assertSame( 2, $this->throttle_hits() );
	}

	public function test_context_holds_the_verified_secret_until_taken() {
		$request = $this->request( 'status', $this->bootstrap );
		$this->assertNull( Remote_Permission::context( $request ) );
		Remote_Permission::check( $request, 'status' );

		$context = Remote_Permission::context( $request );
		$this->assertSame( $this->bootstrap['secret'], $context['secret'] );
		$this->assertSame( Remote_Permission::SET_BOOTSTRAP, $context['set'] );

		Remote_Permission::take_context( $request );
		$this->assertNull( Remote_Permission::context( $request ) );
	}

	/**
	 * KM-04: a request without the protocol headers, or over plain HTTP, needs no secret to refuse
	 * and does not count toward the address block (only the credential and signature steps count),
	 * so header-less requests from a shared address cannot lock the source out.
	 *
	 * @group security
	 * @group review
	 */
	public function test_km04_refusals_before_any_secret_check_do_not_block_the_address() {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap, array( 'drop' => array( 'X-SGSP-Signature' ) ) ), 'status' ), 'bad_headers', 400 );
		}
		unset( $_SERVER['HTTPS'] );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertRefused( Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' ), 'https_required', 403 );
		}
		$_SERVER['HTTPS'] = 'on';
		$this->assertSame( 0, $this->throttle_hits() );
		$this->assertTrue( Remote_Permission::check( $this->request( 'status', $this->bootstrap ), 'status' ), 'the real source still gets through' );

		// Failures that involve a secret still count.
		Remote_Permission::check( $this->request( 'status', $this->bootstrap, array( 'signature' => str_repeat( '0', 64 ) ) ), 'status' );
		$this->assertSame( 1, $this->throttle_hits() );
	}
}

