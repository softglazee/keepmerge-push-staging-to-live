<?php
/**
 * Source_Pairing: stored credentials never shown, revoke and deactivation delete them, live's
 * permanent refusals retire the pairing, approval on live is noticed, readiness checks.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Crypto;
use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Readiness;
use SoftGlaze\SafePush\Signer;
use SoftGlaze\SafePush\Source_Pairing;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Outbound_Guard;

/**
 * @group source
 */
class SourcePairingTest extends WP_UnitTestCase {

	const PASSWORD = 'Zx9yWv8uTs7rQp6oNm5lKj4i';

	private $secret;

	private $answer;

	public function set_up() {
		parent::set_up();
		$this->secret = str_repeat( '5e', 32 );
		$this->answer = null;
		add_filter( 'pre_http_request', array( $this, 'mock' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock' ), 10 );
		parent::tear_down();
	}

	public function mock( $pre, $args, $url ) {
		if ( function_exists( 'curl_init' ) ) {
			Outbound_Guard::pin_handle( curl_init(), $args, $url );
		}
		$answer = $this->answer;
		return $answer( $args );
	}

	private function row( $status = 'active' ) {
		$uuid = wp_generate_uuid4();
		Pairing_Store::insert(
			array(
				'pairing_uuid'     => $uuid,
				'role'             => 'source',
				'status'           => $status,
				'remote_url'       => 'https://93.184.216.34/',
				'rest_root'        => 'https://93.184.216.34/wp-json/',
				'local_home_url'   => Installer::normalised_home(),
				'local_home_hash'  => Installer::home_hash(),
				'remote_login'     => 'softglaze-sp-0a1b2c3d',
				'app_password_enc' => Crypto::encrypt( self::PASSWORD ),
				'secret_enc'       => Crypto::encrypt( $this->secret ),
				'remote_info'      => wp_json_encode( array( 'site' => array( 'site_id' => '6f1c2a3b-1111-4222-8333-444455556666' ) ) ),
				'created_gmt'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return $uuid;
	}

	private function signed( $status, array $body ) {
		$secret = $this->secret;
		return static function ( $args ) use ( $status, $body, $secret ) {
			$raw = wp_json_encode( $body );
			return array(
				'headers'  => array( 'x-sgsp-response-signature' => Signer::sign_response( $args['headers']['X-SGSP-Nonce'], $status, $raw, $secret ) ),
				'body'     => $raw,
				'response' => array( 'code' => $status ),
				'cookies'  => array(),
			);
		};
	}

	private static function unsigned( $status, array $body ) {
		return static function () use ( $status, $body ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $body ),
				'response' => array( 'code' => $status ),
				'cookies'  => array(),
			);
		};
	}

	private function status_body( $uuid, $state ) {
		return array(
			'site'                      => array(
				'name'     => 'Live <b>store</b>',
				'home_url' => 'https://93.184.216.34/',
				'site_id'  => '6f1c2a3b-1111-4222-8333-444455556666',
			),
			'versions'                  => array(
				'wordpress' => '7.1.3',
				'php'       => '8.3.27',
				'plugin'    => '1.0.0',
				'protocol'  => 1,
			),
			'server_time'               => time(),
			'pairing'                   => array(
				'uuid'       => $uuid,
				'state'      => $state,
				'expires_at' => null,
			),
			'window'                    => array( 'closes_at' => time() + 3600 ),
			'authorization_header_seen' => true,
		);
	}

	public function test_present_never_contains_a_credential() {
		$uuid = $this->row();
		$row  = Pairing_Store::find( $uuid );
		$json = wp_json_encode( Source_Pairing::present( $row ) ) . wp_json_encode( Source_Pairing::list_pairings() );

		$this->assertStringNotContainsString( self::PASSWORD, $json );
		$this->assertStringNotContainsString( $this->secret, $json );
		$this->assertStringNotContainsString( (string) $row['app_password_enc'], $json );
		$this->assertStringNotContainsString( (string) $row['secret_enc'], $json );
		$this->assertStringNotContainsString( 'app_password', $json );
	}

	public function test_a_signed_active_status_marks_the_pairing_active() {
		$uuid         = $this->row( 'awaiting_approval' );
		$this->answer = $this->signed( 200, $this->status_body( $uuid, 'active' ) );

		$result = Source_Pairing::check( $uuid );
		$this->assertSame( 'active', $result['status'] );
		$this->assertTrue( $result['last_check']['ok'] );
		$this->assertSame( 'Live store', $result['live']['name'], 'names from live are kept as plain text' );
		$this->assertTrue( $result['authorization_header_seen'] );
		$this->assertSame( '93.184.216.34', $result['address'] );
	}

	public function test_an_unsigned_active_status_changes_nothing() {
		$uuid         = $this->row( 'awaiting_approval' );
		$this->answer = self::unsigned( 200, $this->status_body( $uuid, 'active' ) );

		$result = Source_Pairing::check( $uuid );
		$this->assertSame( 'awaiting_approval', $result['status'] );
		$this->assertSame( 'response_unsigned', $result['last_check']['code'] );
	}

	/**
	 * @dataProvider permanent_refusals
	 *
	 * @param string $live_code Code live sends.
	 * @param string $reason    Reason the row keeps.
	 */
	public function test_permanent_refusals_retire_the_pairing_and_delete_its_credentials( $live_code, $reason ) {
		$uuid         = $this->row();
		$this->answer = self::unsigned( 401, array( 'code' => $live_code ) );

		$result = Source_Pairing::check( $uuid );
		$this->assertSame( 'repair_needed', $result['status'] );
		$this->assertSame( $reason, $result['reason'] );

		$row = Pairing_Store::find( $uuid );
		$this->assertNull( $row['app_password_enc'] );
		$this->assertNull( $row['secret_enc'] );
	}

	public function permanent_refusals() {
		return array(
			array( 'pairing_invalid', 'refused_by_live' ),
			array( 'invalid_username', 'revoked_on_live' ),
			array( 'incorrect_password', 'credentials_reset_on_live' ),
			array( 'signature_invalid', 'signature_refused' ),
		);
	}

	public function test_transient_refusals_keep_the_pairing() {
		foreach ( array( 'rest_no_route' => 404, 'authorization_header_missing' => 401, 'clock_skew' => 401, 'credentials_invalid' => 401 ) as $code => $status ) {
			$uuid         = $this->row();
			$this->answer = self::unsigned( $status, array( 'code' => $code ) );
			$result       = Source_Pairing::check( $uuid );
			$this->assertSame( 'active', $result['status'], $code );
			$this->assertFalse( $result['last_check']['ok'], $code );
			$this->assertNotSame( '', $result['last_check']['message'], $code );
			if ( 'authorization_header_missing' === $code ) {
				$this->assertFalse( $result['authorization_header_seen'] );
			}
		}
	}

	public function test_revoke_deletes_the_credentials_and_names_the_step_on_live() {
		$uuid   = $this->row();
		$result = Source_Pairing::revoke( $uuid );

		$this->assertSame( 'revoked', $result['status'] );
		$this->assertStringContainsString( $uuid, $result['next_step'] );
		$row = Pairing_Store::find( $uuid );
		$this->assertNull( $row['app_password_enc'] );
		$this->assertNull( $row['secret_enc'] );
		$this->assertWPError( Source_Pairing::revoke( $uuid ) );
		$this->assertCount( 0, wp_list_filter( Source_Pairing::list_pairings(), array( 'uuid' => $uuid ) ) );
	}

	public function test_deactivation_marks_source_pairings_repair_needed_without_credentials() {
		$active  = $this->row( 'active' );
		$waiting = $this->row( 'awaiting_approval' );

		Installer::deactivate();

		foreach ( array( $active, $waiting ) as $uuid ) {
			$row = Pairing_Store::find( $uuid );
			$this->assertSame( 'repair_needed', $row['status'] );
			$this->assertNull( $row['app_password_enc'] );
			$this->assertNull( $row['secret_enc'] );
			$this->assertSame( 'deactivated', Source_Pairing::present( $row )['reason'] );
		}

		Installer::activate();
	}

	public function test_readiness_reports_curl_and_the_authorization_header_after_a_status_call() {
		$ids = wp_list_pluck( Readiness::checks(), 'id' );
		$this->assertContains( 'pinned_transport', $ids );
		$this->assertNotContains( 'authorization_header', $ids, 'only after a status call' );

		$uuid         = $this->row();
		$this->answer = self::unsigned( 401, array( 'code' => 'authorization_header_missing' ) );
		Source_Pairing::check( $uuid );

		$checks = wp_list_filter( Readiness::checks(), array( 'id' => 'authorization_header' ) );
		$this->assertCount( 1, $checks );
		$this->assertSame( 'error', array_values( $checks )[0]['status'] );
	}

	public function test_the_admin_revoke_route_handles_source_pairings() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$uuid    = $this->row();
		$request = new WP_REST_Request( 'DELETE', '/keepmerge/v1/admin/pairings/' . $uuid );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'revoked', $response->get_data()['status'] );
		wp_set_current_user( 0 );
	}
}
