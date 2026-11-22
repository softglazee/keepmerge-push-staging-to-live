<?php
/**
 * Security cases that have no PHPUnit test of their own elsewhere,
 * built as end-to-end checks on the destination: real signed requests dispatched through
 * WP_REST_Server (window open, HTTPS on in the test, receiver user, Application Password UUID,
 * body hash, HMAC, nonce, rate: the whole remote permission chain), admin routes with the
 * wp_rest nonce, and the state each case must leave unchanged (row counts of the plugin's
 * tables, the content tables, options, audit rows).
 *
 * What one process cannot prove (two sites over HTTPS, a real proxy, DNS, a second process) is
 * left to end-to-end tests on two real sites; each test below says how close it gets.
 *
 * @package SoftGlaze\SafePush\Tests
 */

require_once __DIR__ . '/class-keepmerge-recovery-harness.php';

use SoftGlaze\SafePush\Admin\Assets;
use SoftGlaze\SafePush\Crypto;
use SoftGlaze\SafePush\Pairing_Key;
use SoftGlaze\SafePush\Pairing_Service;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Signer;
use SoftGlaze\SafePush\Tables;

/**
 * @group security
 */
class SecuritySuiteTest extends Keepmerge_Recovery_Harness {

	const OTHER_SITE = '6f1c2a3b-3333-4222-8333-444455556666';

	/**
	 * Outbound requests seen by the recorder of S-20.
	 *
	 * @var array[]
	 */
	private $outbound = array();

	public function tear_down() {
		remove_all_filters( 'application_password_is_api_request' );
		remove_filter( 'pre_http_request', array( $this, 'record_outbound' ), 1 );
		$this->remove_tree( $this->base() . '/2033' );
		parent::tear_down();
	}

	// --- helpers -----------------------------------------------------------------------------------

	/**
	 * A signed request as a source would send it, with any part replaced, dispatched through the
	 * REST server. Core's Application Password step is represented by what it leaves behind (the
	 * current user and the authenticated password's UUID), as in the other route tests.
	 *
	 * @param string $method Method.
	 * @param string $route  Route.
	 * @param string $body   Raw body.
	 * @param array  $o      pairing, secret, app_uuid, site, user, headers, drop, query.
	 * @return WP_REST_Response
	 */
	private function signed( $method, $route, $body = '', array $o = array() ) {
		$pairing   = isset( $o['pairing'] ) ? $o['pairing'] : $this->pairing_uuid;
		$secret    = isset( $o['secret'] ) ? $o['secret'] : $this->creds['secret'];
		$site      = isset( $o['site'] ) ? $o['site'] : self::SOURCE_SITE;
		$query     = isset( $o['query'] ) ? $o['query'] : array();
		$ts        = time();
		$nonce     = Signer::new_nonce();
		$hash      = Signer::body_hash( $body );
		$canonical = Signer::canonical( $method, $route, $query, $pairing, $site, $ts, $nonce, $hash );

		$headers = array(
			'X-SGSP-Version'        => '1',
			'X-SGSP-Pairing'        => $pairing,
			'X-SGSP-Site'           => $site,
			'X-SGSP-Timestamp'      => (string) $ts,
			'X-SGSP-Nonce'          => $nonce,
			'X-SGSP-Content-SHA256' => $hash,
			'X-SGSP-Signature'      => Signer::sign( $canonical, $secret ),
			'Authorization'         => 'Basic dGVzdA==',
			'Content-Type'          => 'application/json',
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
		if ( ! empty( $query ) ) {
			$request->set_query_params( $query );
		}
		$request->set_body( $body );

		wp_set_current_user( array_key_exists( 'user', $o ) ? $o['user'] : $this->receiver );
		$GLOBALS['wp_rest_application_password_uuid'] = array_key_exists( 'app_uuid', $o ) ? $o['app_uuid'] : $this->creds['uuid'];
		$response = rest_get_server()->dispatch( $request );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		wp_set_current_user( 0 );
		return $response;
	}

	/**
	 * A second pairing made as the live admin and a source would make it: key, confirm, finalize,
	 * approve. Returns every credential so a test can look for it.
	 */
	private function new_pairing( $approve = true ) {
		$created = Pairing_Service::create_key( self::$admin );
		$this->assertIsArray( $created );
		$key  = Pairing_Key::parse( $created['key'] );
		$row  = Pairing_Store::find( $key['pairing'] );
		$conf = Pairing_Service::confirm(
			$row,
			array(
				'site_id'  => self::OTHER_SITE,
				'home_url' => 'https://staging-two.example.com/',
			)
		);
		Pairing_Service::finalize( Pairing_Store::find( $key['pairing'] ), '192.0.2.10' );
		if ( $approve ) {
			Pairing_Service::approve( $key['pairing'], self::$admin );
		}
		$row = Pairing_Store::find( $key['pairing'] );
		return array(
			'key'          => $created['key'],
			'uuid'         => $key['pairing'],
			'login'        => $conf['receiver_login'],
			'receiver'     => (int) $row['receiver_user_id'],
			'boot_pass'    => $key['password'],
			'boot_secret'  => $key['secret'],
			'app_password' => $conf['app_password'],
			'secret'       => $conf['secret'],
			'app_uuid'     => (string) $row['app_password_uuid'],
			'row'          => $row,
		);
	}

	private function table_rows() {
		global $wpdb;
		$out = array();
		foreach ( Tables::NAMES as $name ) {
			$out[ $name ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::table_name( $name ) ); // phpcs:ignore WordPress.DB
		}
		return $out;
	}

	/**
	 * A digest of live's content (F-04): every row of the content tables and every option except
	 * transients, the cron schedule and Keepmerge's own session options (lock, window).
	 */
	private function content_digest() {
		global $wpdb;
		$parts = array();
		$order = array(
			$wpdb->posts              => 'ID',
			$wpdb->postmeta           => 'meta_id',
			$wpdb->terms              => 'term_id',
			$wpdb->termmeta           => 'meta_id',
			$wpdb->term_taxonomy      => 'term_taxonomy_id',
			$wpdb->term_relationships => 'object_id, term_taxonomy_id',
			Tables::table_name( 'sync_state' ) => 'id',
		);
		foreach ( $order as $table => $by ) {
			$parts[ $table ] = md5( (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM {$table} ORDER BY {$by}", ARRAY_N ) ) ); // phpcs:ignore WordPress.DB
		}
		$parts['options'] = md5( (string) wp_json_encode( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%' AND option_name <> 'cron' AND option_name NOT LIKE 'softglaze\\_sp\\_%' ORDER BY option_name", ARRAY_N ) ) ); // phpcs:ignore WordPress.DB
		return $parts;
	}

	public function record_outbound( $pre, $args, $url ) {
		$this->outbound[] = $url;
		return new WP_Error( 'km_test_no_network', 'blocked by the S-20 recorder' );
	}

	/**
	 * Every route of the namespace with a concrete path for its pattern.
	 */
	private function concrete_routes() {
		$out = array();
		foreach ( rest_get_server()->get_routes( 'keepmerge/v1' ) as $route => $handlers ) {
			if ( '/keepmerge/v1' === $route ) {
				continue;
			}
			$path    = preg_replace_callback(
				'/\(\?P<(\w+)>([^()]*)\)/',
				static function ( $m ) {
					foreach ( array( '1', '0f6e2c1a-5b7d-4c3e-9a8b-1c2d3e4f5a6b' ) as $candidate ) {
						if ( 1 === preg_match( '#\A' . $m[2] . '\z#', $candidate ) ) {
							return $candidate;
						}
					}
					return 'x';
				},
				$route
			);
			$methods = array();
			foreach ( $handlers as $handler ) {
				$methods = array_merge( $methods, array_keys( $handler['methods'] ) );
			}
			$out[ $path ] = array_values( array_diff( array_unique( $methods ), array( 'HEAD' ) ) );
		}
		return $out;
	}

	private function refused_code( WP_REST_Response $response ) {
		$data = $response->get_data();
		return is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
	}

	// --- S-03 ----------------------------------------------------------------------------------------

	/**
	 * S-03: what a database-only leak gives an attacker. The stored ciphertext does not decrypt
	 * with other salts, the stored Application Password hash is not a password for core, and the
	 * ciphertext used as the HMAC key is refused without storing a nonce.
	 */
	public function test_s03_a_database_only_leak_gives_nothing_usable() {
		$p = $this->new_pairing();

		// Control: this site decrypts its own secret.
		$this->assertSame( $p['secret'], Crypto::decrypt( $p['row']['secret_enc'] ) );

		// Another site's salts (the copied value elsewhere) cannot decrypt it.
		$other = static function ( $salt ) {
			return $salt . 'another-site';
		};
		add_filter( 'salt', $other );
		$this->assertWPError( Crypto::decrypt( $p['row']['secret_enc'] ) );
		remove_filter( 'salt', $other );

		// The stored Application Password hash sent as the password: core refuses it.
		add_filter( 'application_password_is_api_request', static function () { return true; } );
		$stored = WP_Application_Passwords::get_user_application_password( $p['receiver'], $p['app_uuid'] );
		$this->assertIsArray( $stored );
		$this->assertNotSame( $p['app_password'], $stored['password'], 'the database holds a hash, not the password' );
		$with_hash = wp_authenticate_application_password( null, $p['login'], $stored['password'] );
		$this->assertWPError( $with_hash );
		$this->assertSame( 'incorrect_password', $with_hash->get_error_code() );
		$this->assertInstanceOf( WP_User::class, wp_authenticate_application_password( null, $p['login'], $p['app_password'] ), 'control: the real password works' );

		// A valid password with the ciphertext as the HMAC key (its bytes, and its digest as a
		// 64-hex secret): 401, no nonce stored.
		$this->clear_tables( array( 'throttle' ) );
		$nonces = $this->table_rows()['nonces'];
		$cipher = (string) $p['row']['secret_enc'];
		$ts     = time();
		$nonce  = Signer::new_nonce();
		$sig    = hash_hmac( 'sha256', Signer::canonical( 'GET', '/keepmerge/v1/remote/status', array(), $p['uuid'], self::OTHER_SITE, $ts, $nonce, Signer::body_hash( '' ) ), $cipher );
		$common = array(
			'pairing'  => $p['uuid'],
			'secret'   => $p['secret'],
			'site'     => self::OTHER_SITE,
			'user'     => $p['receiver'],
			'app_uuid' => $p['app_uuid'],
		);
		$raw    = $this->signed(
			'GET',
			'/keepmerge/v1/remote/status',
			'',
			array_merge(
				$common,
				array(
					'headers' => array(
						'X-SGSP-Timestamp' => (string) $ts,
						'X-SGSP-Nonce'     => $nonce,
						'X-SGSP-Signature' => $sig,
					),
				)
			)
		);
		$this->assertSame( 401, $raw->get_status() );
		$this->assertSame( 'signature_invalid', $this->refused_code( $raw ) );
		$response = $this->signed( 'GET', '/keepmerge/v1/remote/status', '', array_merge( $common, array( 'secret' => hash( 'sha256', $cipher ) ) ) );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'signature_invalid', $this->refused_code( $response ) );
		$this->assertSame( $nonces, $this->table_rows()['nonces'] );

		// Control: the real secret answers.
		$ok = $this->signed(
			'GET',
			'/keepmerge/v1/remote/status',
			'',
			array(
				'pairing'  => $p['uuid'],
				'secret'   => $p['secret'],
				'site'     => self::OTHER_SITE,
				'user'     => $p['receiver'],
				'app_uuid' => $p['app_uuid'],
			)
		);
		$this->assertSame( 200, $ok->get_status() );
	}

	private function clear_tables( array $names ) {
		global $wpdb;
		foreach ( $names as $name ) {
			$wpdb->query( 'DELETE FROM ' . Tables::table_name( $name ) ); // phpcs:ignore WordPress.DB
		}
	}

	// --- S-05 ----------------------------------------------------------------------------------------

	/**
	 * S-05 (destination half, one process): after a full pairing made through the routes, no
	 * credential appears in any admin answer, the admin page's data island, the audit table, the
	 * plugin's options, the pairings table in clear text, the receiver's user meta or the status
	 * answer. The create-key answer is the one place the key is shown, once.
	 */
	public function test_s05_no_credential_reaches_admin_answers_the_page_audit_options_or_tables() {
		$created = $this->admin( 'POST', '/keepmerge/v1/admin/pairings' );
		$this->assertSame( 200, $created->get_status(), wp_json_encode( $created->get_data() ) );
		$this->assertSame( 'no-store', $created->get_headers()['Cache-Control'] );
		$key  = $created->get_data()['key'];
		$p    = Pairing_Key::parse( $key );
		$row  = Pairing_Store::find( $p['pairing'] );
		$recv = (int) $row['receiver_user_id'];

		// pair/confirm with the bootstrap set, as the source sends it.
		$confirm = $this->signed(
			'POST',
			'/keepmerge/v1/remote/pair/confirm',
			(string) wp_json_encode(
				array(
					'site_id'  => self::OTHER_SITE,
					'home_url' => 'https://staging-two.example.com/',
				)
			),
			array(
				'pairing'  => $p['pairing'],
				'secret'   => $p['secret'],
				'site'     => '-',
				'user'     => $recv,
				'app_uuid' => (string) $row['bootstrap_password_uuid'],
			)
		);
		$this->assertSame( 200, $confirm->get_status(), wp_json_encode( $confirm->get_data() ) );
		$long = $confirm->get_data();

		$finalize = $this->signed(
			'POST',
			'/keepmerge/v1/remote/pair/finalize',
			'{}',
			array(
				'pairing'  => $p['pairing'],
				'secret'   => $long['secret'],
				'site'     => self::OTHER_SITE,
				'user'     => $recv,
				'app_uuid' => (string) Pairing_Store::find( $p['pairing'] )['app_password_uuid'],
			)
		);
		$this->assertSame( 200, $finalize->get_status(), wp_json_encode( $finalize->get_data() ) );
		$this->assertSame( 200, $this->admin( 'POST', '/keepmerge/v1/admin/pairings/' . $p['pairing'] . '/approve' )->get_status() );

		$needles = array(
			'key'                 => $key,
			'bootstrap password'  => $p['password'],
			'bootstrap secret'    => $p['secret'],
			'long-term password'  => $long['app_password'],
			'long-term secret'    => $long['secret'],
			'password, spaced'    => WP_Application_Passwords::chunk_password( $long['app_password'] ),
		);

		$haystacks = array();

		// Every admin GET route without a path parameter.
		foreach ( $this->concrete_routes() as $path => $methods ) {
			if ( false !== strpos( $path, '/remote/' ) || ! in_array( 'GET', $methods, true ) || false !== strpos( $path, '0f6e2c1a' ) || 1 === preg_match( '#/1(/|\z)#', $path ) ) {
				continue;
			}
			$answer = $this->admin( 'GET', $path );
			$haystacks[ 'GET ' . $path ] = wp_json_encode( $answer->get_data() ) . wp_json_encode( $answer->get_headers() );
		}
		$this->assertArrayHasKey( 'GET /keepmerge/v1/admin/overview', $haystacks );
		$this->assertArrayHasKey( 'GET /keepmerge/v1/admin/receive', $haystacks );
		$this->assertArrayHasKey( 'GET /keepmerge/v1/admin/audit/export', $haystacks );

		// The Tools page's data island.
		wp_set_current_user( self::$admin );
		Assets::enqueue( 'tools_page_keepmerge' );
		$island = wp_scripts()->get_data( Assets::HANDLE, 'before' );
		wp_set_current_user( 0 );
		$this->assertNotEmpty( $island, 'the data island was built' );
		$haystacks['data island'] = wp_json_encode( $island );

		// The status answer the source reads.
		$status = $this->signed(
			'GET',
			'/keepmerge/v1/remote/status',
			'',
			array(
				'pairing'  => $p['pairing'],
				'secret'   => $long['secret'],
				'site'     => self::OTHER_SITE,
				'user'     => $recv,
				'app_uuid' => (string) Pairing_Store::find( $p['pairing'] )['app_password_uuid'],
			)
		);
		$this->assertSame( 200, $status->get_status() );
		$haystacks['remote status'] = wp_json_encode( $status->get_data() ) . wp_json_encode( $status->get_headers() );

		global $wpdb;
		$haystacks['audit table']    = wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . Tables::table_name( 'audit' ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
		$haystacks['pairings table'] = wp_json_encode( $wpdb->get_results( 'SELECT * FROM ' . Tables::table_name( 'pairings' ), ARRAY_A ) ); // phpcs:ignore WordPress.DB
		$haystacks['options']        = wp_json_encode( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'softglaze\\_sp\\_%'", ARRAY_A ) ); // phpcs:ignore WordPress.DB
		$haystacks['receiver meta']  = wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d", $recv ), ARRAY_A ) );

		$this->assertGreaterThan( 3, $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::table_name( 'audit' ) ) ); // phpcs:ignore WordPress.DB

		foreach ( $haystacks as $where => $text ) {
			foreach ( $needles as $what => $needle ) {
				$this->assertFalse( strpos( (string) $text, $needle ), $what . ' found in ' . $where );
			}
		}

		// The create-key answer is the one place the key is shown.
		$this->assertStringContainsString( $p['secret'], wp_json_encode( $created->get_data() ) );
	}

	// --- S-20 ----------------------------------------------------------------------------------------

	/**
	 * S-20 (closest single-process form): live answers every remote route of a full push with a
	 * media file (open, items, files, chunks, commit, apply) and a rollback from its own admin
	 * screen without making one outbound HTTP request. Every request through core's HTTP API
	 * passes pre_http_request; the recorder counts and blocks them. V10 is the static half
	 * (SecurityStaticTest).
	 */
	public function test_s20_live_makes_no_outbound_request_during_a_push_with_media_and_a_rollback() {
		add_filter( 'pre_http_request', array( $this, 'record_outbound' ), 1, 3 );

		$path = '2033/01/s20.jpg';
		$old  = $this->jpeg( 32, 24, 10 );
		$new  = $this->jpeg( 32, 24, 240 );
		$this->put_file( $path, $old );
		$image = self::factory()->attachment->create_object( $path, 0, array( 'post_mime_type' => 'image/jpeg' ) );
		wp_update_attachment_metadata(
			$image,
			array(
				'file'  => $path,
				'sizes' => array(),
			)
		);
		$media          = $this->envelope(
			'attachment',
			$image,
			'push',
			static function ( $data ) use ( $new ) {
				$data['files'][0]['size']   = strlen( $new );
				$data['files'][0]['sha256'] = hash( 'sha256', $new );
				return $data;
			}
		);
		$media['media'] = array(
			array(
				'path' => $path,
				'ref'  => 1,
			),
		);
		$page           = $this->page( 'S20 page' );
		$push           = $this->stage(
			array( $media, $this->changed_page( $page ) ),
			array(
				1 => array(
					'path'       => $path,
					'bytes'      => $new,
					'attachment' => $media['uuid'],
				),
			)
		);
		$answers = $this->apply( $push );
		$this->assertSame( 'applied', end( $answers )['state'] );
		$this->assertSame( hash( 'sha256', $new ), hash_file( 'sha256', $this->base() . '/' . $path ) );

		$this->rollback( $push );
		$this->assertSame( hash( 'sha256', $old ), hash_file( 'sha256', $this->base() . '/' . $path ) );

		// The receive screen and the push list, as the live admin opens them afterwards.
		$this->assertSame( 200, $this->admin( 'GET', '/keepmerge/v1/admin/receive' )->get_status() );
		$this->assertSame( 200, $this->admin( 'GET', '/keepmerge/v1/admin/overview' )->get_status() );

		$this->assertSame( array(), $this->outbound, 'outbound requests made on live' );
	}

	// --- S-38 ----------------------------------------------------------------------------------------

	/**
	 * S-38 (the route audit in one process): every admin route and method refuses anonymous,
	 * subscriber and editor (each with a valid wp_rest nonce for that user) with 401 or 403; a
	 * method a route lacks is 404 for an administrator; every remote route refuses a request
	 * without the protocol headers (400 bad_headers), one with a forged signature (401/403) and a
	 * wrong method (404); nothing is written to the plugin's state.
	 */
	public function test_s38_every_route_refuses_anonymous_subscriber_editor_and_forged_requests() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$routes     = $this->concrete_routes();
		$admin      = array_filter(
			$routes,
			static function ( $path ) {
				return false === strpos( $path, '/remote/' );
			},
			ARRAY_FILTER_USE_KEY
		);
		$remote     = array_diff_key( $routes, $admin );

		$this->assertGreaterThanOrEqual( 25, count( $admin ), 'admin routes found' );
		$this->assertCount( 15, $remote, 'remote routes found (window open)' );

		global $wpdb;
		$state  = static function () use ( $wpdb ) {
			$out = array();
			foreach ( array( 'pairings', 'nonces', 'sync_state', 'pushes', 'push_items' ) as $name ) {
				$out[ $name ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::table_name( $name ) ); // phpcs:ignore WordPress.DB
			}
			$out['options'] = md5( (string) wp_json_encode( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'softglaze\\_sp\\_%' ORDER BY option_name", ARRAY_N ) ) ); // phpcs:ignore WordPress.DB
			return $out;
		};
		$before = $state();
		$checks = 0;

		foreach ( $admin as $path => $methods ) {
			foreach ( $methods as $method ) {
				$body    = 'GET' === $method ? '' : '{}';
				$request = new WP_REST_Request( $method, $path );
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( $body );
				wp_set_current_user( 0 );
				$status = rest_get_server()->dispatch( $request )->get_status();
				$this->assertContains( $status, array( 401, 403 ), $method . ' ' . $path . ' anonymous' );
				++$checks;

				foreach ( array( 'subscriber' => $subscriber, 'editor' => self::$editor ) as $who => $user ) {
					$response = $this->admin( $method, $path, 'GET' === $method ? array() : array( 'confirm' => 'example.org' ), $user );
					$this->assertContains( $response->get_status(), array( 401, 403 ), $method . ' ' . $path . ' ' . $who );
					++$checks;
				}
			}
			$wrong = in_array( 'PUT', $methods, true ) ? 'OPTIONS' : 'PUT';
			if ( 'OPTIONS' !== $wrong ) {
				$this->assertSame( 404, $this->admin( $wrong, $path, array(), self::$admin )->get_status(), $wrong . ' ' . $path . ' admin, wrong method' );
				++$checks;
			}
		}

		foreach ( $remote as $path => $methods ) {
			$this->clear_tables( array( 'throttle' ) );
			foreach ( $methods as $method ) {
				$body = 'GET' === $method ? '' : '{}';

				$bare = new WP_REST_Request( $method, $path );
				$bare->set_body( $body );
				wp_set_current_user( 0 );
				$response = rest_get_server()->dispatch( $bare );
				$this->assertSame( 400, $response->get_status(), $method . ' ' . $path . ' no headers' );
				$this->assertSame( 'bad_headers', $this->refused_code( $response ) );

				$forged = $this->signed(
					$method,
					$path,
					$body,
					array(
						'pairing'  => wp_generate_uuid4(),
						'secret'   => str_repeat( 'ab', 32 ),
						'user'     => 0,
						'app_uuid' => null,
					)
				);
				$this->assertContains( $forged->get_status(), array( 401, 403 ), $method . ' ' . $path . ' forged' );

				$wrong = $this->signed( 'GET' === $method ? 'DELETE' : 'GET', $path, '' );
				$this->assertSame( 404, $wrong->get_status(), $path . ' wrong method' );
				$checks += 3;
			}
		}

		$this->assertSame( $before, $state(), 'no state written by refused requests' );
		$this->assertGreaterThan( 150, $checks );
	}

	// --- S-51 ----------------------------------------------------------------------------------------

	/**
	 * S-51: items/fetch for 51 items is refused; a fetch is audited with its count.
	 */
	public function test_s51_items_fetch_is_capped_at_50_and_audited_with_counts() {
		$page  = $this->page( 'S51 page' );
		$entry = array(
			'uuid'    => wp_generate_uuid4(),
			'kind'    => 'post',
			'type'    => 'page',
			'live_id' => $page,
		);

		$before = $this->audit_count( 'items_fetched' );
		$too    = $this->signed( 'POST', '/keepmerge/v1/remote/items/fetch', (string) wp_json_encode( array( 'items' => array_fill( 0, 51, $entry ) ) ) );
		$this->assertSame( 400, $too->get_status() );
		$this->assertSame( 'too_many_items', $this->refused_code( $too ) );

		$ok = $this->signed( 'POST', '/keepmerge/v1/remote/items/fetch', (string) wp_json_encode( array( 'items' => array( $entry, array_merge( $entry, array( 'uuid' => wp_generate_uuid4() ) ) ) ) ) );
		$this->assertSame( 200, $ok->get_status(), wp_json_encode( $ok->get_data() ) );
		$this->assertCount( 2, $ok->get_data()['items'] );

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT result, details FROM %i WHERE event = %s ORDER BY id ASC', Tables::table_name( 'audit' ), 'items_fetched' ), ARRAY_A );
		$this->assertSame( $before + 2, count( $rows ) );
		$last = json_decode( (string) end( $rows )['details'], true );
		$this->assertSame( 'ok', end( $rows )['result'] );
		$this->assertSame( 2, $last['count'] );
		$this->assertSame( 'denied', $rows[ count( $rows ) - 2 ]['result'] );
	}

	// --- S-60 ----------------------------------------------------------------------------------------

	/**
	 * S-60: with the window closed no remote route exists; 1,000 requests (bare and with forged
	 * protocol headers, spread over the 15 remote paths) all get 404 and write nothing to any of
	 * the plugin's seven tables.
	 */
	public function test_s60_window_closed_a_thousand_requests_get_404_and_write_nothing() {
		global $wp_rest_server;
		$paths = array_keys(
			array_filter(
				$this->concrete_routes(),
				static function ( $methods, $path ) {
					return false !== strpos( $path, '/remote/' );
				},
				ARRAY_FILTER_USE_BOTH
			)
		);
		$this->assertCount( 15, $paths );

		Receive_Window::close( 'manual' );
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );
		$this->assertSame( array(), array_filter( array_keys( rest_get_server()->get_routes( 'keepmerge/v1' ) ), static function ( $r ) { return false !== strpos( $r, '/remote/' ); } ) );

		$before = $this->table_rows();
		$codes  = array();
		for ( $i = 0; $i < 1000; $i++ ) {
			$path = $paths[ $i % 15 ];
			if ( 0 === $i % 2 ) {
				$request = new WP_REST_Request( 'POST', $path );
				$request->set_body( '{}' );
				wp_set_current_user( 0 );
				$response = rest_get_server()->dispatch( $request );
			} else {
				$response = $this->signed( 'POST', $path, '{}', array( 'user' => 0, 'app_uuid' => null ) );
			}
			$codes[ $response->get_status() . ' ' . $this->refused_code( $response ) ] = isset( $codes[ $response->get_status() . ' ' . $this->refused_code( $response ) ] ) ? $codes[ $response->get_status() . ' ' . $this->refused_code( $response ) ] + 1 : 1;
		}
		$this->assertSame( array( '404 rest_no_route' => 1000 ), $codes );
		$this->assertSame( $before, $this->table_rows(), 'zero rows written to the seven tables' );
	}

	// --- S-61 ----------------------------------------------------------------------------------------

	/**
	 * S-61: window open, 50 requests with valid credentials and a bad signature from one address:
	 * 401 for the first ten, then 429 ip_blocked; at most two audit rows (collapsed); no nonce
	 * stored; the address blocked for the real source too until the block ends.
	 */
	public function test_s61_fifty_bad_signatures_from_one_address_are_blocked_after_ten_and_collapse() {
		$this->clear_tables( array( 'throttle' ) );
		global $wpdb;
		$audit  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::table_name( 'audit' ) ); // phpcs:ignore WordPress.DB
		$nonces = $this->table_rows()['nonces'];
		$codes  = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$response = $this->signed( 'GET', '/keepmerge/v1/remote/status', '', array( 'secret' => str_repeat( 'cd', 32 ) ) );
			$codes[]  = $response->get_status() . ' ' . $this->refused_code( $response );
		}
		$this->assertSame( array_fill( 0, 10, '401 signature_invalid' ), array_slice( $codes, 0, 10 ) );
		$this->assertSame( array_fill( 0, 40, '429 ip_blocked' ), array_slice( $codes, 10 ) );
		$this->assertLessThanOrEqual( 2, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::table_name( 'audit' ) ) - $audit, 'audit rows collapse' ); // phpcs:ignore WordPress.DB
		$this->assertSame( $nonces, $this->table_rows()['nonces'] );

		$real = $this->signed( 'GET', '/keepmerge/v1/remote/status' );
		$this->assertSame( 429, $real->get_status(), 'a blocked address is blocked for every pairing' );
		$this->assertGreaterThan( 0, (int) $real->get_headers()['Retry-After'] );

		// Another address is not affected.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->assertSame( 200, $this->signed( 'GET', '/keepmerge/v1/remote/status' )->get_status() );
	}

	// --- S-62 ----------------------------------------------------------------------------------------

	/**
	 * S-62: valid credentials, 305 requests inside one minute: 300 answered, then 429 rate_limited;
	 * every request stored exactly one nonce; no address failure is counted.
	 */
	public function test_s62_valid_credentials_are_rate_limited_after_300_requests_a_minute() {
		$this->clear_tables( array( 'nonces', 'throttle' ) );
		$codes = array();
		$start = time();
		for ( $i = 0; $i < 305; $i++ ) {
			$response = $this->signed( 'GET', '/keepmerge/v1/remote/status' );
			$codes[]  = $response->get_status() . ' ' . $this->refused_code( $response );
		}
		$this->assertLessThan( 60, time() - $start, 'the burst fits in the rate window' );
		$this->assertSame( array_fill( 0, 300, '200 ' ), array_slice( $codes, 0, 300 ) );
		$this->assertSame( array_fill( 0, 5, '429 rate_limited' ), array_slice( $codes, 300 ) );
		// Step 10 (nonce) runs before step 11 (rate): a refused request's nonce is spent too, so it
		// cannot be replayed once the minute has passed.
		$this->assertSame( 305, $this->table_rows()['nonces'] );
		$this->assertSame( 0, (int) $this->table_rows()['throttle'], 'rate limiting is not an address failure' );
	}

	// --- S-78 ----------------------------------------------------------------------------------------

	/**
	 * S-78: when the Authorization header does not reach WordPress, the request is refused with
	 * authorization_header_missing; credentials in the query string or the body are never a
	 * fallback (core reads Application Passwords from the Authorization header only).
	 */
	public function test_s78_a_missing_authorization_header_has_no_fallback() {
		$missing = $this->signed( 'GET', '/keepmerge/v1/remote/status', '', array( 'user' => 0, 'app_uuid' => null, 'drop' => array( 'Authorization' ) ) );
		$this->assertSame( 401, $missing->get_status() );
		$this->assertSame( 'authorization_header_missing', $this->refused_code( $missing ) );

		// Credentials in the query and the body: core finds no Application Password there.
		$p = $this->new_pairing();
		add_filter( 'application_password_is_api_request', static function () { return true; } );
		$server_backup                  = $_SERVER;
		$_GET['app_password']           = $p['app_password'];
		$_GET['user']                   = $p['login'];
		$_REQUEST['app_password']       = $p['app_password'];
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'] );
		$this->assertFalse( wp_validate_application_password( false ), 'core takes no credential from the query' );
		unset( $_GET['app_password'], $_GET['user'], $_REQUEST['app_password'] );
		$_SERVER = $server_backup;

		$query = $this->signed(
			'GET',
			'/keepmerge/v1/remote/status',
			'',
			array(
				'pairing'  => $p['uuid'],
				'secret'   => $p['secret'],
				'site'     => self::OTHER_SITE,
				'user'     => 0,
				'app_uuid' => null,
				'drop'     => array( 'Authorization' ),
				'query'    => array(
					'app_password' => $p['app_password'],
					'user'         => $p['login'],
				),
			)
		);
		$this->assertSame( 401, $query->get_status() );
		$this->assertSame( 'authorization_header_missing', $this->refused_code( $query ) );

		// With the header core would have authenticated the same password.
		$this->assertInstanceOf( WP_User::class, wp_authenticate_application_password( null, $p['login'], $p['app_password'] ) );
	}

	// --- F-04 ----------------------------------------------------------------------------------------

	/**
	 * F-04: nothing the source does before the live admin's confirmation (status, baseline,
	 * manifest, items/fetch, opening a push, staging items) changes live's content tables, options
	 * or sync state; the apply does (control).
	 */
	public function test_f04_no_live_write_before_the_apply() {
		$page   = $this->page( 'F04 page' );
		$env    = $this->changed_page( $page );
		$before = $this->content_digest();

		$this->assertSame( 200, $this->signed( 'GET', '/keepmerge/v1/remote/status' )->get_status() );
		$post        = get_post( $page );
		$fingerprint = array(
			'k'    => 'post',
			'id'   => $page,
			'type' => 'page',
			'guid' => $post->guid,
			'slug' => $post->post_name,
			'date' => $post->post_date_gmt,
		);
		$baseline    = $this->signed( 'POST', '/keepmerge/v1/remote/baseline', (string) wp_json_encode( array( 'fingerprints' => array( $fingerprint ) ) ) );
		$this->assertSame( 200, $baseline->get_status(), wp_json_encode( $baseline->get_data() ) );
		$entry    = array(
			'uuid'    => $env['uuid'],
			'kind'    => 'post',
			'type'    => 'page',
			'live_id' => $page,
		);
		$manifest = $this->signed( 'POST', '/keepmerge/v1/remote/manifest', (string) wp_json_encode( array( 'items' => array( $entry, array( 'uuid' => 'option:blogname', 'kind' => 'option' ) ) ) ) );
		$this->assertSame( 200, $manifest->get_status(), wp_json_encode( $manifest->get_data() ) );
		$this->assertSame( 200, $this->signed( 'POST', '/keepmerge/v1/remote/items/fetch', (string) wp_json_encode( array( 'items' => array( $entry ) ) ) )->get_status() );
		$this->assertSame( $before, $this->content_digest(), 'parts 1 and 2: baseline, manifest and fetch write nothing' );

		$push = $this->stage( array( $env ) );
		$this->assertSame( $before, $this->content_digest(), 'part 3: staging items writes nothing to content' );

		$answers = $this->apply( $push );
		$this->assertSame( 'applied', end( $answers )['state'] );
		$this->assertNotSame( $before, $this->content_digest(), 'control: the apply writes' );
	}
}
