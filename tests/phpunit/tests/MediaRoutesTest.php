<?php
/**
 * The media routes on live (files, chunks, commit): every request is
 * a real signed request dispatched through WP_REST_Server, so the whole permission chain runs
 * before the handler (window open, HTTPS, headers, receiver, body hash, signature, nonce, rate).
 * Each test asserts the HTTP code and the side effect: audit rows, file rows, and the uploads
 * tree outside keepmerge-push-staging-to-live/tmp/ listed before and after.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Pairing_Key;
use SoftGlaze\SafePush\Pairing_Service;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Signer;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Media_Receiver;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Session;

/**
 * @group media
 * @group rest
 */
class MediaRoutesTest extends WP_UnitTestCase {

	const SOURCE_SITE = '6f1c2a3b-2222-4222-8333-444455556666';

	const PLAN_HASH = 'abababababababababababababababababababababababababababababababab';

	const CHUNK = 65536;

	private static $admin;
	private $uuid;
	private $receiver;
	private $creds;
	private $server_backup;
	private $made = array();

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );
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

		// The test site's home is http://; with HTTPS on, home_url() turns https:// and the site
		// ID would be taken for a clone when the REST server starts (rest_api_init). A live site
		// served over HTTPS has an https:// home: record the site ID with it first.
		update_option( 'home', 'https://example.org' );
		update_option( 'siteurl', 'https://example.org' );
		Installer::ensure_site_id();

		Receive_Window::open( 1 );
		$created        = Pairing_Service::create_key( self::$admin );
		$key            = Pairing_Key::parse( $created['key'] );
		$this->uuid     = $key['pairing'];
		$row            = Pairing_Store::find( $this->uuid );
		$this->receiver = (int) $row['receiver_user_id'];
		$confirmed      = Pairing_Service::confirm(
			$row,
			array(
				'site_id'  => self::SOURCE_SITE,
				'home_url' => 'https://staging.example.com/',
			)
		);
		$this->creds    = array(
			'secret' => $confirmed['secret'],
			'uuid'   => Pairing_Store::find( $this->uuid )['app_password_uuid'],
		);
		Pairing_Service::finalize( Pairing_Store::find( $this->uuid ), '192.0.2.10' );
		Pairing_Service::approve( $this->uuid, self::$admin );

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
		parent::tear_down();
	}

	// --- helpers ------------------------------------------------------------------------------

	private function base() {
		return untrailingslashit( wp_normalize_path( wp_upload_dir( null, false )['basedir'] ) );
	}

	private function plugin_dir() {
		return $this->base() . '/keepmerge-push-staging-to-live';
	}

	private function remove_tree( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * Every file of the uploads tree outside keepmerge-push-staging-to-live/tmp/: size and MD5.
	 */
	private function uploads_outside_tmp() {
		$base = $this->base();
		$out  = array();
		if ( ! is_dir( $base ) ) {
			return $out;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			$rel = substr( wp_normalize_path( $item->getPathname() ), strlen( $base ) + 1 );
			if ( 0 === strpos( $rel, 'keepmerge-push-staging-to-live/tmp/' ) ) {
				continue;
			}
			$out[ $rel ] = $item->getSize() . ':' . md5_file( $item->getPathname() );
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Every file under the push temp folders.
	 */
	private function tmp_files() {
		$dir = $this->plugin_dir() . '/tmp';
		$out = array();
		if ( ! is_dir( $dir ) ) {
			return $out;
		}
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			$out[] = substr( wp_normalize_path( $item->getPathname() ), strlen( $dir ) + 1 );
		}
		sort( $out );
		return $out;
	}

	/**
	 * A signed request as the source sends it, dispatched through the REST server.
	 */
	private function call( $method, $route, $body = '', array $o = array() ) {
		$ts        = time();
		$nonce     = Signer::new_nonce();
		$hash      = Signer::body_hash( $body );
		$canonical = Signer::canonical( $method, $route, array(), $this->uuid, self::SOURCE_SITE, $ts, $nonce, $hash );

		$request = new WP_REST_Request( $method, $route );
		$headers = array(
			'X-SGSP-Version'        => '1',
			'X-SGSP-Pairing'        => $this->uuid,
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
		$request->set_body( isset( $o['send_body'] ) ? $o['send_body'] : $body );

		wp_set_current_user( $this->receiver );
		$GLOBALS['wp_rest_application_password_uuid'] = $this->creds['uuid'];
		$response = rest_get_server()->dispatch( $request );
		wp_set_current_user( 0 );
		return $response;
	}

	private function open_push( $files, $bytes, $items = 1 ) {
		$push     = wp_generate_uuid4();
		$response = $this->call(
			'POST',
			'/keepmerge/v1/remote/push',
			wp_json_encode(
				array(
					'push'       => $push,
					'plan_hash'  => self::PLAN_HASH,
					'counts'     => array(
						'create'   => $items,
						'update'   => 0,
						'recreate' => 0,
						'trash'    => 0,
						'adopt'    => 0,
					),
					'items'      => $items,
					'files'      => $files,
					'file_bytes' => $bytes,
				)
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $push;
	}

	private function entry( $ref, $path, $bytes, $attachment = null ) {
		return array(
			'ref'        => $ref,
			'path'       => $path,
			'size'       => strlen( $bytes ),
			'sha256'     => hash( 'sha256', $bytes ),
			'attachment' => null === $attachment ? wp_generate_uuid4() : $attachment,
		);
	}

	private function announce( $push, array $entries, $chunk = self::CHUNK ) {
		return $this->call(
			'POST',
			'/keepmerge/v1/remote/push/' . $push . '/files',
			wp_json_encode(
				array(
					'chunk_size' => $chunk,
					'files'      => $entries,
				)
			)
		);
	}

	private function chunk( $push, $ref, $n, $bytes, array $o = array() ) {
		return $this->call(
			'PUT',
			'/keepmerge/v1/remote/push/' . $push . '/files/' . $ref . '/chunks/' . $n,
			$bytes,
			array_merge(
				array(
					'raw'       => true,
					'chunk_sha' => hash( 'sha256', $bytes ),
				),
				$o
			)
		);
	}

	private function commit( $push, $ref ) {
		return $this->call( 'POST', '/keepmerge/v1/remote/push/' . $push . '/files/' . $ref . '/commit', '{}' );
	}

	/** Sends every chunk of a file. */
	private function send( $push, $ref, $bytes, $chunk = self::CHUNK ) {
		$n = 0;
		foreach ( str_split( $bytes, $chunk ) as $part ) {
			$response = $this->chunk( $push, $ref, $n++, $part );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		}
	}

	private function session( $push ) {
		return Push_Session::find( $push );
	}

	private function audit_count( $event, $reason = null ) {
		global $wpdb;
		$rows  = $wpdb->get_col( $wpdb->prepare( 'SELECT details FROM %i WHERE event = %s', Tables::table_name( 'audit' ), $event ) );
		$count = 0;
		foreach ( $rows as $details ) {
			$details = json_decode( (string) $details, true );
			if ( null === $reason || ( isset( $details['reason'] ) && $reason === $details['reason'] ) ) {
				$count += isset( $details['count'] ) ? (int) $details['count'] : 1;
			}
		}
		return $count;
	}

	private function file_rows( $push ) {
		global $wpdb;
		$session = $this->session( $push );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE push_id = %d AND side = 'destination' AND object_type = 'file'", Tables::table_name( 'push_items' ), (int) $session['id'] ) );
	}

	private function jpeg( $w = 64, $h = 48 ) {
		$image = imagecreatetruecolor( $w, $h );
		imagefilledrectangle( $image, 0, 0, $w - 1, $h - 1, imagecolorallocate( $image, 200, 80, 40 ) );
		ob_start();
		imagejpeg( $image, null, 90 );
		return (string) ob_get_clean();
	}

	private function bytes( $length, $seed ) {
		$out = '';
		$i   = 0;
		while ( strlen( $out ) < $length ) {
			$out .= hash( 'sha512', $seed . ':' . $i++, true );
		}
		return substr( $out, 0, $length );
	}

	private function put_live_file( $relative, $bytes ) {
		$full = $this->base() . '/' . $relative;
		wp_mkdir_p( dirname( $full ) );
		file_put_contents( $full, $bytes );
		$this->made[] = $full;
		return $full;
	}

	/**
	 * A refused need list: 400 with the code, one more audit row (or count) for the reason, no
	 * file row, nothing written anywhere in uploads (not even the temp folder).
	 */
	private function assert_path_refused( $push, $path, $code = 'path_refused' ) {
		$before = $this->uploads_outside_tmp();
		$audit  = $this->audit_count( 'file_refused', $code );
		$rows   = $this->file_rows( $push );

		$response = $this->announce( $push, array( $this->entry( 1, $path, 'x' ) ) );

		$this->assertSame( 400, $response->get_status(), $path );
		$this->assertSame( $code, $response->get_data()['code'], $path );
		$this->assertSame( $audit + 1, $this->audit_count( 'file_refused', $code ), 'audit file_refused: ' . $path );
		$this->assertSame( $rows, $this->file_rows( $push ), 'no file row: ' . $path );
		$this->assertSame( $before, $this->uploads_outside_tmp(), 'uploads unchanged: ' . $path );
		$this->assertSame( array(), $this->tmp_files(), 'nothing in temp: ' . $path );
	}

	// --- S-40 to S-46: paths, at the files step -------------------------------------------

	/**
	 * @group security
	 */
	public function test_s40_a_path_to_wp_config_is_refused_with_an_audit_row() {
		$push = $this->open_push( 1, 1 );
		$this->assert_path_refused( $push, '../wp-config.php' );
	}

	/**
	 * @group security
	 */
	public function test_s41_traversal_out_of_a_year_folder_is_refused() {
		$push = $this->open_push( 1, 1 );
		$this->assert_path_refused( $push, '2026/10/../../../index.php' );
	}

	/**
	 * @group security
	 */
	public function test_s42_encoded_and_double_encoded_traversal_is_refused() {
		$push = $this->open_push( 1, 1 );
		foreach ( array( '%2e%2e/x.jpg', '..%2fx.jpg', '%2e%2e%2fx.jpg', '%252e%252e/x.jpg', '2026/%2e%2e/%2e%2e/x.jpg', '..%252fx.jpg' ) as $path ) {
			$this->assert_path_refused( $push, $path );
		}
	}

	/**
	 * @group security
	 */
	public function test_s43_script_names_double_extensions_and_hidden_files_are_refused() {
		$push = $this->open_push( 1, 1 );
		foreach ( array( 'shell.php', 'shell.phtml', 'x.php.jpg', 'x.jpg.php', '.htaccess', '.user.ini', 'x.phar', '2026/10/shell.php5', '2026/10/x.PHP' ) as $path ) {
			$this->assert_path_refused( $push, $path );
		}
	}

	/**
	 * @group security
	 */
	public function test_s44_an_svg_with_script_is_refused() {
		$push = $this->open_push( 1, 100 );
		$svg  = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
		$this->assert_path_refused( $push, '2026/10/image.svg' );

		// Sent as an image name it is refused when committed: its bytes are not an image.
		$before   = $this->uploads_outside_tmp();
		$response = $this->announce( $push, array( $this->entry( 1, '2026/10/image.png', $svg ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->send( $push, 1, $svg );
		$response = $this->commit( $push, 1 );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'file_type_refused', $response->get_data()['code'] );
		$this->assertSame( array( '.htaccess', 'index.php' ), array_values( preg_grep( '#/#', $this->tmp_files(), PREG_GREP_INVERT ) ) );
		$this->assertSame( array(), preg_grep( '#\.(bin|part\d+)$#', $this->tmp_files() ), 'nothing of it left in temp' );
		$this->assertSame( $before, $this->uploads_outside_tmp() );
	}

	/**
	 * @group security
	 */
	public function test_s45_absolute_drive_backslash_and_nul_paths_are_refused() {
		$push = $this->open_push( 1, 1 );
		foreach ( array( '/etc/passwd', 'C:\\Windows\\win.ini', 'C:/Windows/win.ini', '2026\\10\\x.jpg', "2026/10/x.jpg\0.php", "x\0.jpg" ) as $path ) {
			$this->assert_path_refused( $push, $path );
		}
	}

	/**
	 * @group security
	 */
	public function test_s46_protected_upload_folders_are_refused() {
		$push = $this->open_push( 1, 1 );
		foreach ( array( 'woocommerce_uploads/x.zip', 'keepmerge-push-staging-to-live/tmp/x.jpg', 'fonts/x.woff2', 'wc-logs/x.jpg', 'sites/2/10/x.jpg' ) as $path ) {
			$this->assert_path_refused( $push, $path );
		}
	}

	// --- S-47, S-48: chunks and commit --------------------------------------------------------

	/**
	 * @group security
	 */
	public function test_s47_chunk_hash_range_other_push_and_oversize_chunks_are_refused() {
		global $wpdb;

		// Another push of this live site has a file with ref 2 (a session that already staged).
		$wpdb->insert(
			Tables::table_name( 'pushes' ),
			array(
				'push_uuid'   => wp_generate_uuid4(),
				'pairing_id'  => (int) Pairing_Store::find( $this->uuid )['id'],
				'side'        => 'destination',
				'state'       => 'staged',
				'counts'      => '{}',
				'cursor_json' => '{}',
				'started_gmt' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$other = (int) $wpdb->insert_id;
		$wpdb->insert(
			Tables::table_name( 'push_items' ),
			array(
				'push_id'     => $other,
				'side'        => 'destination',
				'seq'         => 2,
				'uuid'        => 'file:2',
				'object_type' => 'file',
				'status'      => 'need',
				'transfer'    => wp_json_encode( array( 'path' => 'a.jpg', 'size' => 10, 'chunk' => self::CHUNK, 'chunks' => 1, 'got' => '0' ) ),
			)
		);

		$bytes = $this->bytes( 150000, 's47' );
		$push  = $this->open_push( 1, strlen( $bytes ) );
		$this->assertSame( 200, $this->announce( $push, array( $this->entry( 1, '2026/10/clip.mp4', $bytes ) ) )->get_status() );
		$part = substr( $bytes, 0, self::CHUNK );

		$cases = array(
			'hash mismatch'     => array( 1, '0', $part, array( 'chunk_sha' => hash( 'sha256', 'other' ) ), 400, 'chunk_hash_mismatch' ),
			'hash missing'      => array( 1, '0', $part, array( 'chunk_sha' => 'zz' ), 400, 'chunk_hash_mismatch' ),
			'n negative'        => array( 1, '-1', $part, array(), 400, 'chunk_out_of_range' ),
			'n = chunks'        => array( 1, '3', $part, array(), 400, 'chunk_out_of_range' ),
			'n far beyond'      => array( 1, '999999999', $part, array(), 400, 'chunk_out_of_range' ),
			'ref zero'          => array( 0, '0', $part, array(), 400, 'ref_out_of_range' ),
			'ref negative'      => array( -4, '0', $part, array(), 400, 'ref_out_of_range' ),
			'ref of other push' => array( 2, '0', $part, array(), 404, 'file_not_found' ),
			'larger than agreed' => array( 1, '0', $part . 'x', array(), 413, 'chunk_too_large' ),
			'shorter than agreed' => array( 1, '0', substr( $part, 1 ), array(), 400, 'chunk_size_mismatch' ),
		);
		foreach ( $cases as $label => $case ) {
			list( $ref, $n, $body, $o, $status, $code ) = $case;
			$response = $this->call( 'PUT', '/keepmerge/v1/remote/push/' . $push . '/files/' . $ref . '/chunks/' . $n, $body, array_merge( array( 'raw' => true, 'chunk_sha' => hash( 'sha256', $body ) ), $o ) );
			$this->assertSame( $status, $response->get_status(), $label );
			$this->assertSame( $code, $response->get_data()['code'], $label );
			$this->assertSame( array(), preg_grep( '#\.part\d+$#', $this->tmp_files() ), 'no part stored: ' . $label );
		}
		$this->assertGreaterThanOrEqual( 2, $this->audit_count( 'file_refused', 'chunk_hash_mismatch' ) );
		$this->assertGreaterThanOrEqual( 1, $this->audit_count( 'file_refused', 'chunk_too_large' ) );

		// The other push's file stays untouched.
		$this->assertSame( 'need', $wpdb->get_var( $wpdb->prepare( 'SELECT status FROM %i WHERE push_id = %d', Tables::table_name( 'push_items' ), $other ) ) );

		// The right chunk is stored; the same chunk again is a no-op.
		$first = $this->chunk( $push, 1, 0, $part );
		$this->assertSame( 200, $first->get_status() );
		$this->assertFalse( $first->get_data()['duplicate'] );
		$this->assertSame( 1, $first->get_data()['next'] );
		$again = $this->chunk( $push, 1, 0, $part );
		$this->assertTrue( $again->get_data()['duplicate'] );
		$this->assertCount( 1, preg_grep( '#\.part\d+$#', $this->tmp_files() ) );

		// Commit before every chunk is there.
		$this->assertSame( 409, $this->commit( $push, 1 )->get_status() );
	}

	/**
	 * @group security
	 */
	public function test_s48_a_jpg_whose_bytes_are_php_is_refused_at_commit() {
		$php    = "<?php echo 'owned'; system( \$_GET['c'] ); ?>\n" . str_repeat( '/* pad */', 40 );
		$jpeg   = $this->jpeg();
		$push   = $this->open_push( 2, strlen( $php ) + strlen( $jpeg ) );
		$before = $this->uploads_outside_tmp();
		$audit  = $this->audit_count( 'file_refused' );

		$this->assertSame( 200, $this->announce( $push, array( $this->entry( 1, '2026/10/photo.jpg', $php ) ) )->get_status() );
		$this->send( $push, 1, $php );
		$this->assertCount( 1, preg_grep( '#\.part0$#', $this->tmp_files() ), 'the chunk was stored in temp' );

		$response = $this->commit( $push, 1 );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'file_type_refused', $response->get_data()['code'] );
		$this->assertSame( $audit + 1, $this->audit_count( 'file_refused' ) );
		$this->assertSame( array(), preg_grep( '#\.(bin|part\d+)$#', $this->tmp_files() ), 'parts and bin deleted' );
		$this->assertSame( $before, $this->uploads_outside_tmp() );
		$this->assertSame( 'refused', Media_Receiver::rows( (int) $this->session( $push )['id'], array( 1 ) )[1]['status'] );

		// Asked again, the refusal stands; no more chunks are taken for it.
		$this->assertSame( 'file_type_refused', $this->commit( $push, 1 )->get_data()['code'] );
		$this->assertSame( 409, $this->chunk( $push, 1, 0, $php )->get_status() );

		// A real JPEG under a .png name is refused as a mismatch, never renamed.
		$this->assertSame( 200, $this->announce( $push, array( $this->entry( 2, '2026/10/photo.png', $jpeg ) ) )->get_status() );
		$this->send( $push, 2, $jpeg );
		$response = $this->commit( $push, 2 );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'file_type_mismatch', $response->get_data()['code'] );
		$this->assertSame( array(), preg_grep( '#\.(bin|part\d+)$#', $this->tmp_files() ) );
		$this->assertSame( $before, $this->uploads_outside_tmp() );
	}

	/**
	 * @group security
	 */
	public function test_s49_attachment_metadata_paths_refuse_the_item() {
		$push = $this->open_push( 0, 0, 2 );
		$item = function ( array $metadata ) {
			return array(
				'uuid'   => wp_generate_uuid4(),
				'kind'   => 'attachment',
				'type'   => 'attachment',
				'action' => 'create',
				'hash'   => str_repeat( 'c', 64 ),
				'data'   => array(
					'post_type'   => 'attachment',
					'post_title'  => 'Photo',
					'post_status' => 'inherit',
					'mime_type'   => 'image/jpeg',
					'file'        => '2026/10/a.jpg',
					'metadata'    => $metadata,
					'files'       => array(
						array(
							'path'   => '2026/10/a.jpg',
							'size'   => 10,
							'sha256' => str_repeat( 'd', 64 ),
						),
					),
				),
			);
		};
		$before   = $this->uploads_outside_tmp();
		$response = $this->call(
			'POST',
			'/keepmerge/v1/remote/push/' . $push . '/items',
			wp_json_encode(
				array(
					'seq'   => 1,
					'items' => array(
						$item( array( 'sizes' => array( 'thumbnail' => array( 'file' => '../../x.php' ) ) ) ),
						$item( array( 'original_image' => '2026/10/b.jpg' ) ),
					),
				)
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$results = $response->get_data()['results'];
		$this->assertSame( array( 'refused', 'refused' ), array_column( $results, 'status' ) );
		$this->assertSame( array( 'path_refused', 'path_refused' ), array_column( $results, 'reason' ) );
		$this->assertSame( array( 'metadata.sizes', 'metadata.original_image' ), array_column( $results, 'field' ) );

		// The refused rows keep their identity only, never the data.
		global $wpdb;
		$payloads = $wpdb->get_col( $wpdb->prepare( "SELECT payload FROM %i WHERE push_id = %d AND side = 'destination'", Tables::table_name( 'push_items' ), (int) $this->session( $push )['id'] ) );
		foreach ( $payloads as $payload ) {
			$this->assertArrayNotHasKey( 'data', json_decode( $payload, true ) );
		}
		$this->assertSame( $before, $this->uploads_outside_tmp() );
	}

	// --- S-64, S-65: disk and size caps -------------------------------------------------------

	/**
	 * @group security
	 */
	public function test_s64_media_over_the_cap_or_the_free_disk_space_is_refused_at_open() {
		global $wpdb;
		$sessions = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::table_name( 'pushes' ) );
		$audit    = $this->audit_count( 'push_opened', 'storage_full' );
		$open     = function ( $bytes ) {
			return $this->call(
				'POST',
				'/keepmerge/v1/remote/push',
				wp_json_encode(
					array(
						'push'       => wp_generate_uuid4(),
						'plan_hash'  => self::PLAN_HASH,
						'counts'     => array( 'create' => 1, 'update' => 0, 'recreate' => 0, 'trash' => 0, 'adopt' => 0 ),
						'items'      => 1,
						'files'      => 3,
						'file_bytes' => $bytes,
					)
				)
			);
		};

		// Above the 2 GB per-push cap.
		$response = $open( Media_Receiver::MAX_PUSH_BYTES + 1 );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'storage_full', $response->get_data()['code'] );

		// A filter cannot raise the caps.
		$raise = static function () {
			return array( 'max_file' => PHP_INT_MAX, 'max_push' => PHP_INT_MAX );
		};
		add_filter( 'softglaze_sp_limits', $raise );
		$this->assertSame( 'storage_full', $open( Media_Receiver::MAX_PUSH_BYTES + 1 )->get_data()['code'] );
		remove_filter( 'softglaze_sp_limits', $raise );

		// Low free disk space: 1 MB free, 1 MB of media (more than 80% of it).
		$low = static function () {
			return 1048576.0;
		};
		add_filter( 'softglaze_sp_free_space', $low );
		$this->assertSame( 'storage_full', $open( 1048576 )->get_data()['code'] );
		$this->assertSame( 200, $open( 500000 )->get_status(), 'within 80% of the free space' );
		remove_filter( 'softglaze_sp_free_space', $low );
		Push_Lock::release( Push_Lock::get()['push_uuid'] );

		// A filter cannot report more space than the host has.
		$more = static function () {
			return 1e20;
		};
		add_filter( 'softglaze_sp_free_space', $more );
		$this->assertLessThan( 1e20, Media_Receiver::free_space() );
		remove_filter( 'softglaze_sp_free_space', $more );

		$this->assertSame( $sessions + 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::table_name( 'pushes' ) ), 'only the push within the space was opened' );
		$this->assertSame( $audit + 3, $this->audit_count( 'push_opened', 'storage_full' ) );
		$this->assertSame( array(), $this->tmp_files() );
	}

	/**
	 * @group security
	 */
	public function test_s65_a_declared_600_mb_file_is_refused_at_the_files_step() {
		$size = 600 * 1048576;
		$push = $this->open_push( 1, $size );
		$audit = $this->audit_count( 'file_refused', 'file_too_large' );

		$response = $this->announce(
			$push,
			array(
				array(
					'ref'        => 1,
					'path'       => '2026/10/film.mp4',
					'size'       => $size,
					'sha256'     => str_repeat( 'e', 64 ),
					'attachment' => wp_generate_uuid4(),
				),
			)
		);
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'file_too_large', $response->get_data()['code'] );
		$this->assertSame( $audit + 1, $this->audit_count( 'file_refused', 'file_too_large' ) );
		$this->assertSame( 0, $this->file_rows( $push ) );

		// More files or bytes than the push declared are refused too.
		$small = $this->open_push_after_abort( $push, 1, 10 );
		$this->assertSame( 'file_bytes_exceeded', $this->announce( $small, array( $this->entry( 1, 'a.jpg', str_repeat( 'a', 11 ) ) ) )->get_data()['code'] );
		$this->assertSame( 'file_count_exceeded', $this->announce( $small, array( $this->entry( 1, 'a.jpg', 'a' ), $this->entry( 2, 'b.jpg', 'b' ) ) )->get_data()['code'] );
		$this->assertSame( 0, $this->file_rows( $small ) );

		// A need list of 2,001 files (S-63's files part).
		$many = array();
		for ( $i = 1; $i <= 2001; $i++ ) {
			$many[] = $this->entry( $i, 'f' . $i . '.jpg', 'x' );
		}
		$this->assertSame( 'too_many_files', $this->announce( $small, $many )->get_data()['code'] );
	}

	private function open_push_after_abort( $push, $files, $bytes ) {
		$this->assertSame( 200, $this->call( 'POST', '/keepmerge/v1/remote/push/' . $push . '/abort', '{}' )->get_status() );
		return $this->open_push( $files, $bytes );
	}

	// --- F-17 at the route, I-03 at the route ---------------------------------------------------

	public function test_f17_an_identical_file_is_had_a_foreign_file_gets_a_new_name_and_an_own_file_is_replaced() {
		$same     = $this->jpeg( 40, 30 );
		$foreign  = $this->jpeg( 50, 30 );
		$incoming = $this->jpeg( 60, 30 );
		$own      = $this->jpeg( 70, 30 );
		$this->put_live_file( '2026/10/same.jpg', $same );
		$this->put_live_file( '2026/10/taken.jpg', $foreign );
		$this->put_live_file( '2026/10/mine.jpg', $foreign );

		// Live's own attachment with this UUID owns mine.jpg: replacing it is that attachment's update.
		$mine = self::factory()->post->create( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/jpeg' ) );
		update_post_meta( $mine, '_wp_attached_file', '2026/10/mine.jpg' );
		$mine_uuid = Identity::assign( 'post', $mine );

		$before = $this->uploads_outside_tmp();
		$push   = $this->open_push( 4, strlen( $same ) + strlen( $incoming ) * 2 + strlen( $own ) );
		$taken  = wp_generate_uuid4();

		$response = $this->announce(
			$push,
			array(
				$this->entry( 1, '2026/10/same.jpg', $same ),
				$this->entry( 2, '2026/10/taken.jpg', $incoming, $taken ),
				$this->entry( 3, '2026/10/taken-1.jpg', $incoming ),
				$this->entry( 4, '2026/10/mine.jpg', $own, $mine_uuid ),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$files = array_column( $response->get_data()['files'], null, 'ref' );
		$this->assertSame( 'have', $files[1]['status'] );
		$this->assertSame( 0, $files[1]['chunks'], 'nothing to send' );
		$this->assertSame( 'renamed', $files[2]['status'] );
		$this->assertSame( '2026/10/taken-1.jpg', $files[2]['path'] );
		// staging's own taken-1.jpg must not land on the name just given to taken.jpg.
		$this->assertSame( 'renamed', $files[3]['status'] );
		$this->assertSame( '2026/10/taken-1-2.jpg', $files[3]['path'] );
		$this->assertSame( 'need', $files[4]['status'], 'own file: replaced at apply, not renamed' );
		$this->assertSame( '2026/10/mine.jpg', $files[4]['path'] );

		// The identical file takes no chunk.
		$this->assertSame( 409, $this->chunk( $push, 1, 0, $same )->get_status() );

		// The renamed file travels and commits under its new name; the rewrite list has it.
		$this->send( $push, 2, $incoming );
		$committed = $this->commit( $push, 2 );
		$this->assertSame( 200, $committed->get_status() );
		$this->assertSame( '2026/10/taken-1.jpg', $committed->get_data()['path'] );
		$session  = $this->session( $push );
		$rewrites = Media_Receiver::rewrites( (int) $session['id'] );
		$this->assertSame( '2026/10/taken-1.jpg', $rewrites['2026/10/taken.jpg'] );
		$this->assertSame( '2026/10/taken-1-2.jpg', $rewrites['2026/10/taken-1.jpg'] );
		$this->assertArrayNotHasKey( '2026/10/same.jpg', $rewrites );
		$this->assertSame( hash( 'sha256', $incoming ), hash_file( 'sha256', Media_Receiver::committed_file( $session, 2 ) ) );

		// Live's own files are untouched: the foreign one, the identical one, the owned one.
		$this->assertSame( $before, $this->uploads_outside_tmp() );
		$this->assertSame( 1, $this->audit_count( 'files_needed' ) );
	}

	/**
	 * @group recovery
	 */
	public function test_i03_a_chunk_damaged_on_the_way_is_refused_then_sent_again_and_the_commit_hash_matches() {
		$bytes = $this->jpeg( 400, 300 ) . $this->bytes( 100000, 'i03' );
		$push  = $this->open_push( 1, strlen( $bytes ) );
		$this->assertSame( 200, $this->announce( $push, array( $this->entry( 1, '2026/10/large.jpg', $bytes ) ) )->get_status() );
		$parts = str_split( $bytes, self::CHUNK );

		// Damaged after signing (the proxy changed bytes): the chain refuses it before the handler.
		$damaged          = $parts[1];
		$damaged[100]     = chr( ord( $damaged[100] ) ^ 0xff );
		$response         = $this->chunk( $push, 1, 1, $parts[1], array( 'send_body' => $damaged ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'body_hash_mismatch', $response->get_data()['code'] );
		// The chunk header does not match its body: refused by the handler.
		$response = $this->chunk( $push, 1, 1, $damaged, array( 'chunk_sha' => hash( 'sha256', $parts[1] ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'chunk_hash_mismatch', $response->get_data()['code'] );
		$this->assertSame( array(), preg_grep( '#\.part\d+$#', $this->tmp_files() ) );

		// Sent again intact, every chunk: the commit's SHA-256 is the source's.
		$this->send( $push, 1, $bytes );
		$committed = $this->commit( $push, 1 );
		$this->assertSame( 200, $committed->get_status() );
		$this->assertSame( 'committed', $committed->get_data()['status'] );
		$this->assertSame( hash( 'sha256', $bytes ), hash_file( 'sha256', Media_Receiver::committed_file( $this->session( $push ), 1 ) ) );
		$this->assertSame( 1, $this->audit_count( 'file_committed' ) );

		// Committing again answers from the record.
		$this->assertSame( 'committed', $this->commit( $push, 1 )->get_data()['status'] );
		$this->assertSame( 1, $this->audit_count( 'file_committed' ) );
	}

	// --- the temp folder ----------------------------------------------------------------------

	public function test_files_land_only_in_a_random_protected_temp_folder_and_a_pdf_commits() {
		$pdf    = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
		$before = $this->uploads_outside_tmp();
		$push   = $this->open_push( 1, strlen( $pdf ) );
		$this->assertSame( 200, $this->announce( $push, array( $this->entry( 1, '2026/10/menu.pdf', $pdf ) ) )->get_status() );
		$this->send( $push, 1, $pdf );
		$this->assertSame( 200, $this->commit( $push, 1 )->get_status() );

		$files = $this->tmp_files();
		$this->assertContains( '.htaccess', $files );
		$this->assertContains( 'index.php', $files );
		$dirs = array_values( array_unique( array_map( 'dirname', preg_grep( '#/#', $files ) ) ) );
		$this->assertCount( 1, $dirs );
		$this->assertMatchesRegularExpression( '/\A[0-9a-f]{32}\z/', $dirs[0] );
		$this->assertSame( array( $dirs[0] . '/.htaccess', $dirs[0] . '/1.bin', $dirs[0] . '/index.php' ), array_values( preg_grep( '#/#', $files ) ) );
		$this->assertStringContainsString( 'Require all denied', file_get_contents( $this->plugin_dir() . '/tmp/' . $dirs[0] . '/.htaccess' ) );
		$this->assertSame( $before, $this->uploads_outside_tmp() );
		$this->assertFileDoesNotExist( $this->plugin_dir() . '/index.php', 'nothing outside tmp/' );
	}

	public function test_the_first_item_batch_closes_the_files_and_abort_deletes_the_temp_folder() {
		$bytes = $this->bytes( 100000, 'close' );
		$push  = $this->open_push( 2, 200000, 2 );
		$this->assertSame( 200, $this->announce( $push, array( $this->entry( 1, '2026/10/a.mp4', $bytes ), $this->entry( 2, '2026/10/b.mp4', $bytes ) ) )->get_status() );
		$this->send( $push, 1, $bytes );
		$this->assertSame( 200, $this->commit( $push, 1 )->get_status() );
		$this->assertSame( 200, $this->chunk( $push, 2, 0, substr( $bytes, 0, self::CHUNK ) )->get_status() );

		// The first item batch: the files phase ends, the parts of b.mp4 go, a.mp4 stays.
		$item     = array(
			'uuid'   => 'option:blogname',
			'kind'   => 'option',
			'type'   => 'blogname',
			'action' => 'push',
			'hash'   => str_repeat( 'c', 64 ),
			'data'   => array(
				'name'  => 'blogname',
				'value' => 'X',
			),
		);
		$response = $this->call( 'POST', '/keepmerge/v1/remote/push/' . $push . '/items', wp_json_encode( array( 'seq' => 1, 'items' => array( $item ) ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), preg_grep( '#\.part\d+$#', $this->tmp_files() ) );
		$this->assertCount( 1, preg_grep( '#1\.bin$#', $this->tmp_files() ) );
		$this->assertSame( 'files_closed', $this->announce( $push, array( $this->entry( 3, 'c.jpg', 'c' ) ) )->get_data()['code'] );
		$this->assertSame( 'files_closed', $this->chunk( $push, 2, 1, substr( $bytes, self::CHUNK ) )->get_data()['code'] );
		$this->assertTrue( $response->get_data()['session']['files']['closed'] );
		$this->assertSame( 1, $response->get_data()['session']['files']['committed'] );

		// Abort: the session's rows and its temp folder go.
		$this->assertSame( 200, $this->call( 'POST', '/keepmerge/v1/remote/push/' . $push . '/abort', '{}' )->get_status() );
		$this->assertSame( array( '.htaccess', 'index.php' ), $this->tmp_files() );
		$this->assertSame( 0, $this->file_rows( $push ) );
	}

	public function test_expiry_and_the_daily_prune_remove_temp_folders() {
		global $wpdb;
		$bytes = $this->bytes( 70000, 'expire' );
		$push  = $this->open_push( 1, strlen( $bytes ) );
		$this->assertSame( 200, $this->announce( $push, array( $this->entry( 1, '2026/10/a.mp4', $bytes ) ) )->get_status() );
		$this->send( $push, 1, $bytes );
		$this->assertNotEmpty( preg_grep( '#\.part\d+$#', $this->tmp_files() ) );

		// An orphan folder older than 24 hours and a fresh one.
		$old = $this->plugin_dir() . '/tmp/' . str_repeat( 'a', 32 );
		$new = $this->plugin_dir() . '/tmp/' . str_repeat( 'b', 32 );
		wp_mkdir_p( $old );
		wp_mkdir_p( $new );
		file_put_contents( $old . '/1.bin', 'x' );
		touch( $old, time() - DAY_IN_SECONDS - 60 );
		$this->assertSame( 1, Media_Receiver::prune_temp() );
		$this->assertDirectoryDoesNotExist( $old );
		$this->assertDirectoryExists( $new );
		$this->assertNotEmpty( preg_grep( '#\.part\d+$#', $this->tmp_files() ), 'a live session keeps its folder' );

		// The session expires after 24 hours: its rows and folder go.
		$session = $this->session( $push );
		$wpdb->update( Tables::table_name( 'pushes' ), array( 'started_gmt' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS - 5 ) ), array( 'id' => (int) $session['id'] ) );
		$this->assertSame( 1, Push_Session::expire_old() );
		$this->assertSame( array(), preg_grep( '#\.part\d+$#', $this->tmp_files() ) );
		$this->assertSame( 0, $this->file_rows( $push ) );
	}

	public function test_without_the_direct_filesystem_method_nothing_is_written() {
		$bytes = $this->bytes( 1000, 'ftp' );
		$push  = $this->open_push( 1, strlen( $bytes ) );
		$this->assertSame( 200, $this->announce( $push, array( $this->entry( 1, '2026/10/a.mp4', $bytes ) ) )->get_status() );

		$ftp = static function () {
			return 'ftpext';
		};
		add_filter( 'filesystem_method', $ftp );
		$response = $this->chunk( $push, 1, 0, $bytes );
		remove_filter( 'filesystem_method', $ftp );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'storage_unwritable', $response->get_data()['code'] );
		$this->assertSame( array(), $this->tmp_files() );
	}

	public function test_the_same_need_list_again_answers_from_the_record_and_a_changed_entry_is_refused() {
		$bytes = $this->bytes( 200000, 'again' );
		$push  = $this->open_push( 1, strlen( $bytes ) );
		$entry = $this->entry( 1, '2026/10/a.mp4', $bytes );
		$first = $this->announce( $push, array( $entry ) )->get_data()['files'][0];
		$this->assertSame( 4, $first['chunks'] );
		$this->chunk( $push, 1, 0, substr( $bytes, 0, self::CHUNK ) );

		$again = $this->announce( $push, array( $entry ) )->get_data()['files'][0];
		$this->assertSame( 1, $again['next'], 'resume at the next missing chunk' );

		// Halved chunk size: the file starts again at that size.
		$half = $this->announce( $push, array( $entry ), self::CHUNK )->get_data()['files'][0];
		$this->assertSame( 1, $half['next'] );
		$this->assertSame( 'invalid_chunk_size', $this->announce( $push, array( $entry ), 1024 )->get_data()['code'] );

		$changed           = $entry;
		$changed['sha256'] = str_repeat( 'f', 64 );
		$this->assertSame( 'file_mismatch', $this->announce( $push, array( $changed ) )->get_data()['code'] );
		$this->assertSame( 1, $this->file_rows( $push ) );
	}
}
