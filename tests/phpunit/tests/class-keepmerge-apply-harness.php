<?php
/**
 * Shared harness of the apply tests: a real destination pairing on this test site, signed
 * requests dispatched through WP_REST_Server so the whole remote chain runs as the receiver user,
 * and helpers that stage crafted envelopes (an export of an object of this site with the intended
 * "staging" changes applied to the exported data) and run the apply. Live and staging share one
 * database here, so "staging" objects are exported with the source context (which gives them
 * UUIDs) and new items are exported from scratch objects that are deleted before the push.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Pairing_Key;
use SoftGlaze\SafePush\Pairing_Service;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Signer;
use SoftGlaze\SafePush\Sync\Export_Context;
use SoftGlaze\SafePush\Sync\Items;
use SoftGlaze\SafePush\Sync\Planner;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Item_Stager;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Session;

/**
 * Base class of the apply tests.
 */
abstract class Keepmerge_Apply_Harness extends WP_UnitTestCase {

	const SOURCE_SITE = '6f1c2a3b-2222-4222-8333-444455556666';

	protected static $admin;
	protected static $editor;
	protected $pairing_uuid;
	protected $receiver;
	protected $creds;
	protected $server_backup;
	protected $made = array();

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin  = $factory->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'km-admin',
			)
		);
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function set_up() {
		parent::set_up();
		global $wpdb, $wp_rest_server;

		$this->server_backup    = $_SERVER;
		$_SERVER['HTTPS']       = 'on';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$wpdb->query( 'DELETE FROM ' . Tables::table_name( 'throttle' ) );
		$wpdb->query( 'DELETE FROM ' . Tables::table_name( 'nonces' ) );
		delete_option( Push_Lock::OPTION );
		$this->remove_tree( $this->plugin_dir() );

		update_option( 'home', 'https://example.org' );
		update_option( 'siteurl', 'https://example.org' );
		Installer::ensure_site_id();

		Receive_Window::open( 1 );
		$created            = Pairing_Service::create_key( self::$admin );
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

	public function tear_down() {
		global $wp_rest_server;
		$_SERVER = $this->server_backup;
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		wp_set_current_user( 0 );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$this->remove_tree( $this->plugin_dir() );
		foreach ( $this->made as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		remove_all_filters( 'softglaze_sp_apply_step_items' );
		parent::tear_down();
	}

	// --- files -------------------------------------------------------------------------------------

	protected function base() {
		return untrailingslashit( wp_normalize_path( wp_upload_dir( null, false )['basedir'] ) );
	}

	protected function plugin_dir() {
		return $this->base() . '/keepmerge-push-staging-to-live';
	}

	protected function remove_tree( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	protected function put_file( $relative, $bytes ) {
		$full = $this->base() . '/' . $relative;
		wp_mkdir_p( dirname( $full ) );
		file_put_contents( $full, $bytes );
		$this->made[] = $full;
		return $full;
	}

	protected function jpeg( $w = 32, $h = 24, $red = 200 ) {
		$image = imagecreatetruecolor( $w, $h );
		imagefilledrectangle( $image, 0, 0, $w - 1, $h - 1, imagecolorallocate( $image, $red, 80, 40 ) );
		ob_start();
		imagejpeg( $image, null, 90 );
		return (string) ob_get_clean();
	}

	// --- signed requests ---------------------------------------------------------------------------

	/**
	 * A signed request as the source sends it, dispatched through the REST server as the receiver.
	 */
	protected function call( $method, $route, $body = '', array $o = array() ) {
		$ts        = time();
		$nonce     = Signer::new_nonce();
		$hash      = Signer::body_hash( $body );
		$canonical = Signer::canonical( $method, $route, array(), $this->pairing_uuid, self::SOURCE_SITE, $ts, $nonce, $hash );

		$request = new WP_REST_Request( $method, $route );
		$headers = array(
			'X-SGSP-Version'        => '1',
			'X-SGSP-Pairing'        => $this->pairing_uuid,
			'X-SGSP-Site'           => self::SOURCE_SITE,
			'X-SGSP-Timestamp'      => (string) $ts,
			'X-SGSP-Nonce'          => $nonce,
			'X-SGSP-Content-SHA256' => $hash,
			'X-SGSP-Signature'      => Signer::sign( $canonical, $this->creds['secret'] ),
			'Authorization'         => 'Basic dGVzdA==',
			'Content-Type'          => isset( $o['raw'] ) ? 'application/octet-stream' : 'application/json',
		);
		if ( isset( $o['chunk_sha'] ) ) {
			$headers['X-SGSP-Chunk-SHA256'] = $o['chunk_sha'];
		}
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( $body );

		wp_set_current_user( $this->receiver );
		$GLOBALS['wp_rest_application_password_uuid'] = $this->creds['uuid'];
		$response = rest_get_server()->dispatch( $request );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		wp_set_current_user( 0 );
		return $response;
	}

	/**
	 * An admin route as a cookie-authenticated user with the wp_rest nonce.
	 */
	protected function admin( $method, $route, array $params = array(), $user = null ) {
		wp_set_current_user( null === $user ? self::$admin : $user );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		if ( ! empty( $params ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}
		$response = rest_get_server()->dispatch( $request );
		wp_set_current_user( 0 );
		return $response;
	}

	// --- envelopes ---------------------------------------------------------------------------------

	/**
	 * The envelope of an object of this site as the source would send it, after $change edits the
	 * exported data. A live ID hint names the object to update.
	 */
	protected function envelope( $kind, $ref, $action = 'push', $change = null, array $extra = array() ) {
		$live = $this->live_hash( $kind, $ref );
		$item = Items::export( $kind, $ref, Export_Context::source() );
		$this->assertIsArray( $item, 'exportable ' . $kind . ' ' . $ref );
		$data = $item['data'];
		if ( is_callable( $change ) ) {
			$data = call_user_func( $change, $data );
		}
		$refs = array();
		foreach ( $item['refs'] as $one ) {
			$refs[] = array(
				'path'    => $one['path'],
				'kind'    => $one['kind'],
				'id'      => $one['id'],
				'uuid'    => $one['uuid'],
				'live_id' => 0,
			);
		}
		$envelope = array(
			'uuid'    => $item['uuid'],
			'kind'    => $kind,
			'type'    => $item['type'],
			'action'  => $action,
			'hash'    => hash( 'sha256', (string) wp_json_encode( $data ) ),
			'live_id' => in_array( $action, array( 'create', 'recreate' ), true ) || ! is_int( $ref ) ? 0 : $ref,
			'data'    => $data,
			'refs'    => $refs,
		);
		if ( in_array( $action, array( 'push', 'use_staging', 'trash_on_live', 'record_base' ), true ) && '' !== $live ) {
			// Live's hash at plan time, as the source's manifest returned it.
			$envelope['live_hash'] = $live;
		}
		return array_merge( $envelope, $extra );
	}

	/**
	 * Envelopes of new items: scratch objects exported (UUIDs given), then deleted, so their source
	 * IDs name nothing here and every reference among them resolves inside the push.
	 *
	 * @param array    $objects kind => list of IDs (deleted afterwards).
	 * @param callable $change  Optional function( $kind, $data ) returning data.
	 * @return array[] Envelopes keyed by the scratch ID.
	 */
	protected function new_items( array $objects, $change = null ) {
		$out = array();
		foreach ( $objects as $kind => $ids ) {
			foreach ( $ids as $id ) {
				$out[ $id ] = $this->envelope(
					$kind,
					$id,
					'create',
					null === $change ? null : static function ( $data ) use ( $change, $kind ) {
						return call_user_func( $change, $kind, $data );
					}
				);
			}
		}
		foreach ( $objects as $kind => $ids ) {
			foreach ( $ids as $id ) {
				if ( in_array( $kind, array( 'term', 'menu' ), true ) ) {
					$term = get_term( $id );
					wp_delete_term( $id, $term->taxonomy );
				} else {
					wp_delete_post( $id, true );
				}
			}
		}
		return $out;
	}

	/**
	 * Opens a push for the envelopes, sends them in batches and expects the session staged.
	 */
	protected function stage( array $envelopes, array $files = array() ) {
		$counts = array_fill_keys( Push_Session::ACTIONS, 0 );
		$lines  = array();
		foreach ( $envelopes as $envelope ) {
			++$counts[ Item_Stager::PLAN_ACTIONS[ $envelope['action'] ] ];
			$lines[] = $envelope['uuid'] . ':' . $envelope['action'] . ':' . $envelope['hash'];
		}
		$push     = wp_generate_uuid4();
		$response = $this->call(
			'POST',
			'/keepmerge/v1/remote/push',
			(string) wp_json_encode(
				array(
					'push'       => $push,
					'plan_hash'  => Planner::plan_hash( $lines ),
					'counts'     => $counts,
					'items'      => count( $envelopes ),
					'files'      => count( $files ),
					'file_bytes' => array_sum(
						array_map(
							static function ( $file ) {
								return strlen( $file['bytes'] );
							},
							$files
						)
					),
				)
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		if ( ! empty( $files ) ) {
			$this->send_files( $push, $files );
		}

		$seq = 0;
		foreach ( array_chunk( array_values( $envelopes ), 50 ) as $batch ) {
			$response = $this->call(
				'POST',
				'/keepmerge/v1/remote/push/' . $push . '/items',
				(string) wp_json_encode(
					array(
						'seq'   => ++$seq,
						'items' => $batch,
					)
				)
			);
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		}
		$this->assertSame( 'staged', Push_Session::find( $push )['state'], wp_json_encode( $this->rows( $push ) ) );
		return $push;
	}

	/**
	 * Files phase: $files is ref => array( path, bytes, attachment uuid ).
	 */
	protected function send_files( $push, array $files ) {
		$entries = array();
		foreach ( $files as $ref => $file ) {
			$entries[] = array(
				'ref'        => $ref,
				'path'       => $file['path'],
				'size'       => strlen( $file['bytes'] ),
				'sha256'     => hash( 'sha256', $file['bytes'] ),
				'attachment' => $file['attachment'],
			);
		}
		$response = $this->call(
			'POST',
			'/keepmerge/v1/remote/push/' . $push . '/files',
			(string) wp_json_encode(
				array(
					'chunk_size' => 65536,
					'files'      => $entries,
				)
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		foreach ( $files as $ref => $file ) {
			$n = 0;
			foreach ( str_split( $file['bytes'], 65536 ) as $part ) {
				$chunk = $this->call(
					'PUT',
					'/keepmerge/v1/remote/push/' . $push . '/files/' . $ref . '/chunks/' . $n++,
					$part,
					array(
						'raw'       => true,
						'chunk_sha' => hash( 'sha256', $part ),
					)
				);
				if ( 200 !== $chunk->get_status() ) {
					break;
				}
			}
			$commit = $this->call( 'POST', '/keepmerge/v1/remote/push/' . $push . '/files/' . $ref . '/commit', '{}' );
			$this->assertContains( $commit->get_status(), array( 200, 409 ), wp_json_encode( $commit->get_data() ) );
		}
		return $response->get_data();
	}

	/**
	 * Calls R14 until the push leaves the running states; returns every answer.
	 */
	protected function apply( $push, $max = 60 ) {
		$answers = array();
		$after   = 0;
		for ( $i = 0; $i < $max; $i++ ) {
			$response = $this->call( 'POST', '/keepmerge/v1/remote/push/' . $push . '/apply', (string) wp_json_encode( array( 'after' => $after ) ) );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$data      = $response->get_data();
			$answers[] = $data;
			$after     = (int) $data['next'];
			if ( ! in_array( $data['state'], array( 'snapshotting', 'applying', 'staged' ), true ) ) {
				break;
			}
		}
		return $answers;
	}

	/**
	 * Destination rows of a push keyed by UUID (JSON columns decoded).
	 */
	protected function rows( $push ) {
		global $wpdb;
		$session = Push_Session::find( $push );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE push_id = %d AND side = 'destination' AND object_type NOT IN ('file','file_path') ORDER BY id ASC", Tables::table_name( 'push_items' ), (int) $session['id'] ), ARRAY_A );
		$out     = array();
		foreach ( $rows as $row ) {
			foreach ( array( 'payload', 'refs', 'pre_image', 'transfer' ) as $column ) {
				$row[ $column ] = json_decode( (string) $row[ $column ], true );
			}
			$out[ $row['uuid'] ] = $row;
		}
		return $out;
	}

	/**
	 * The SQL proof of the acceptance criteria: applied rows without a snapshot, or with a snapshot
	 * later than the write.
	 */
	protected function applied_without_snapshot() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE side = 'destination' AND status = 'applied' AND (snapshot_gmt IS NULL OR snapshot_gmt > applied_gmt)", Tables::table_name( 'push_items' ) ) );
	}

	protected function audit_count( $event ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', Tables::table_name( 'audit' ), $event ) );
	}

	/**
	 * Live's export hash of an item now.
	 */
	protected function live_hash( $kind, $ref ) {
		$item = Items::export( $kind, $ref, Export_Context::destination() );
		return null === $item ? '' : $item['hash'];
	}

	/**
	 * Row counts of live's content tables (posts, postmeta, terms, termmeta, relationships, options
	 * minus Keepmerge's own and transient churn).
	 */
	protected function content_counts() {
		global $wpdb;
		$out = array();
		foreach ( array( 'posts', 'postmeta', 'terms', 'termmeta', 'term_relationships' ) as $table ) {
			$out[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->$table}" ); // phpcs:ignore WordPress.DB
		}
		$out['options'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name NOT LIKE 'softglaze\\_sp\\_%' AND option_name NOT LIKE '\\_transient%' AND option_name NOT LIKE '\\_site\\_transient%'" ); // phpcs:ignore WordPress.DB
		return $out;
	}
}
