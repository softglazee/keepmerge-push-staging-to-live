<?php
/**
 * The source side of the media transfer (Transfer\Media_Sender through Push_Runner): the
 * sending_files state before the items, dedup, renaming, chunk size from live, the caps, per-chunk
 * resume (I-02), a damaged chunk (I-03), 413 halving, and the acceptance sizes.
 *
 * Live is played by this site's own destination classes (Push_Session, Media_Receiver) under a
 * different push UUID, with its own uploads folder (upload_dir filtered while live answers), so a
 * file on "live" is a different file from the one on "staging". Bodies take a JSON round trip;
 * chunks travel as raw bytes with their SHA-256, the way Remote_Client sends them.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Sync\Baseline;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Manifest;
use SoftGlaze\SafePush\Sync\Planner;
use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Sync\Sync_State;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Media_Receiver;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Runner;
use SoftGlaze\SafePush\Transfer\Push_Session;

/**
 * Live for the media runner.
 */
class Keepmerge_Test_Media_Live {

	public $pairing   = array( 'id' => 5555 );
	public $overrides = array();
	public $calls     = array();
	public $puts      = array();
	public $hook      = null;
	public $foreign   = false;
	public $base      = '';
	public $dead      = false;
	public $lost      = false;
	public $damaged   = false;

	public function live_uuid( $uuid ) {
		$uuid = (string) $uuid;
		return ( 'f' === $uuid[0] ? 'e' : 'f' ) . substr( $uuid, 1 );
	}

	public function live_dirs( $dirs ) {
		$dirs['basedir'] = $this->base;
		$dirs['path']    = $this->base . $dirs['subdir'];
		$dirs['baseurl'] = 'https://live.example.com/wp-content/uploads';
		$dirs['url']     = $dirs['baseurl'] . $dirs['subdir'];
		return $dirs;
	}

	/** Runs a call as live: live's own uploads folder. */
	public function as_live( $fn ) {
		add_filter( 'upload_dir', array( $this, 'live_dirs' ) );
		try {
			return $fn();
		} finally {
			remove_filter( 'upload_dir', array( $this, 'live_dirs' ) );
		}
	}

	public function post( $route, array $body = array(), array $opts = array() ) {
		unset( $opts );
		$this->calls[] = 'POST ' . $route;
		$body          = json_decode( wp_json_encode( empty( $body ) ? new stdClass() : $body ), true );

		if ( is_callable( $this->hook ) ) {
			$out = call_user_func( $this->hook, 'POST', $route, $body, $this );
			if ( null !== $out ) {
				return $out;
			}
		}
		if ( '/keepmerge/v1/remote/manifest' === $route ) {
			$answer = Manifest::answer( array( 'id' => 5556 ), $body );
			foreach ( $answer['items'] as $i => $item ) {
				if ( isset( $this->overrides[ $item['uuid'] ] ) ) {
					$answer['items'][ $i ] = array_merge( $item, $this->overrides[ $item['uuid'] ] );
				}
			}
			$answer['woocommerce'] = false;
			return $this->wrap( $answer );
		}
		if ( '/keepmerge/v1/remote/baseline' === $route ) {
			return $this->wrap( array( 'results' => array() ) );
		}
		if ( '/keepmerge/v1/remote/push' === $route ) {
			$body['push'] = $this->live_uuid( $body['push'] );
			return $this->wrap( $this->as_live( function () use ( $body ) {
				return Push_Session::open( $this->pairing, $body );
			} ) );
		}
		if ( 1 === preg_match( '#^/keepmerge/v1/remote/push/([0-9a-f-]{36})/(items|abort|files)$#', $route, $m ) ) {
			if ( 'files' === $m[2] && $this->foreign ) {
				// Live has no attachment with these UUIDs (new attachments on staging).
				foreach ( $body['files'] as $i => $file ) {
					$body['files'][ $i ]['attachment'] = $this->live_uuid( $file['attachment'] );
				}
			}
			return $this->wrap( $this->session_call( $m[1], $m[2], $body ) );
		}
		if ( 1 === preg_match( '#^/keepmerge/v1/remote/push/([0-9a-f-]{36})/files/([0-9]+)/commit$#', $route, $m ) ) {
			return $this->wrap( $this->session_call( $m[1], 'commit', array(), $m[2] ) );
		}
		return new WP_Error( 'code_report_disabled', 'off', array( 'status' => 409, 'remote_status' => 403 ) );
	}

	public function put_chunk( $route, $bytes, array $opts = array() ) {
		unset( $opts );
		$this->calls[] = 'PUT ' . $route;
		$sha           = hash( 'sha256', (string) $bytes );
		if ( 1 !== preg_match( '#^/keepmerge/v1/remote/push/([0-9a-f-]{36})/files/([0-9]+)/chunks/([0-9]+)$#', $route, $m ) ) {
			return self::error( 'rest_no_route', 404 );
		}
		$this->puts[] = $m[2] . ':' . $m[3];
		if ( is_callable( $this->hook ) ) {
			$out = call_user_func( $this->hook, 'PUT', $route, array( 'ref' => (int) $m[2], 'n' => (int) $m[3], 'bytes' => &$bytes ), $this );
			if ( null !== $out ) {
				return $out;
			}
		}
		// What the chain does with a body changed on the way: the content hash no longer matches
		// (step 7, an unsigned 400).
		if ( ! hash_equals( $sha, hash( 'sha256', (string) $bytes ) ) ) {
			return self::error( 'body_hash_mismatch', 400, array( 'signed' => false ) );
		}
		return $this->wrap( $this->session_call( $m[1], 'chunk', array(), $m[2], $m[3], (string) $bytes, $sha ) );
	}

	public function get( $route, array $query = array(), array $opts = array() ) {
		unset( $query, $opts );
		$this->calls[] = 'GET ' . $route;
		if ( 1 === preg_match( '#^/keepmerge/v1/remote/push/([0-9a-f-]{36})$#', $route, $m ) ) {
			$session = Push_Session::for_pairing( $this->live_uuid( $m[1] ), $this->pairing );
			return $this->wrap( is_wp_error( $session ) ? $session : Push_Session::status( $session ) );
		}
		return self::error( 'unexpected_status', 404 );
	}

	public function session_call( $uuid, $what, array $body, $ref = '', $n = '', $bytes = '', $sha = '' ) {
		return $this->as_live(
			function () use ( $uuid, $what, $body, $ref, $n, $bytes, $sha ) {
				$session = Push_Session::for_pairing( $this->live_uuid( $uuid ), $this->pairing );
				if ( is_wp_error( $session ) ) {
					return $session;
				}
				switch ( $what ) {
					case 'items':
						return Push_Session::receive( $session, $this->pairing, $body );
					case 'abort':
						return Push_Session::abort( $session, $this->pairing );
					case 'files':
						return Media_Receiver::announce( $session, $this->pairing, $body );
					case 'chunk':
						return Media_Receiver::put_chunk( $session, $this->pairing, $ref, $n, $bytes, $sha );
					default:
						return Media_Receiver::commit( $session, $this->pairing, $ref );
				}
			}
		);
	}

	public function wrap( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			return self::error( $result->get_error_code(), isset( $data['status'] ) ? (int) $data['status'] : 500 );
		}
		return array(
			'status' => 200,
			'data'   => json_decode( wp_json_encode( $result ), true ),
		);
	}

	public static function error( $code, $status, array $extra = array() ) {
		return new WP_Error(
			$code,
			'message for ' . $code,
			array_merge(
				array(
					'status'        => 409,
					'remote_status' => $status,
					'remote_code'   => $code,
					'signed'        => true,
					'retryable'     => false,
				),
				$extra
			)
		);
	}
}

/**
 * @group media
 * @group runner
 */
class MediaSenderTest extends WP_UnitTestCase {

	const X = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

	private $uuid;
	private $pairing_id;
	private $live;
	private $made = array();

	public function set_up() {
		parent::set_up();
		delete_option( Push_Lock::OPTION );
		Settings::save( array( 'item_batch' => 10, 'chunk_kb' => 256 ) );
		Scope::flush();

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
		$this->pairing_id = (int) Pairing_Store::find( $this->uuid )['id'];
		update_option(
			Baseline::OPTION,
			array(
				$this->uuid => array(
					'state'         => 'done',
					'clone_date'    => '2026-10-20',
					'clone_gmt'     => strtotime( '2026-10-20 00:00:00 UTC' ),
					'clone_unknown' => false,
				),
			),
			false
		);
		$this->live       = new Keepmerge_Test_Media_Live();
		$this->live->base = wp_normalize_path( WP_CONTENT_DIR ) . '/km-live-uploads';
		$this->remove_tree( $this->live->base );
		wp_mkdir_p( $this->live->base );
	}

	public function tear_down() {
		$this->remove_tree( $this->live->base );
		$this->remove_tree( $this->source_base() . '/keepmerge-push-staging-to-live' );
		foreach ( $this->made as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		parent::tear_down();
	}

	// --- helpers ------------------------------------------------------------------------------

	private function source_base() {
		return untrailingslashit( wp_normalize_path( wp_upload_dir( null, false )['basedir'] ) );
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

	private function write( $base, $relative, $bytes ) {
		$full = $base . '/' . $relative;
		wp_mkdir_p( dirname( $full ) );
		file_put_contents( $full, $bytes );
		$this->made[] = $full;
		return $full;
	}

	/** Writes a big file in 1 MB blocks (each block different) without holding it in memory. */
	private function write_big( $relative, $head, $size, $seed ) {
		$full = $this->source_base() . '/' . $relative;
		wp_mkdir_p( dirname( $full ) );
		$handle = fopen( $full, 'wb' );
		fwrite( $handle, $head );
		$block = '';
		while ( strlen( $block ) < 1048576 ) {
			$block .= hash( 'sha512', $seed . strlen( $block ), true );
		}
		$written = strlen( $head );
		for ( $i = 0; $written < $size; $i++ ) {
			$piece    = substr( pack( 'N', $i ) . $block, 0, min( 1048576, $size - $written ) );
			$written += fwrite( $handle, $piece );
		}
		fclose( $handle );
		$this->made[] = $full;
		return $full;
	}

	private function jpeg( $w, $h ) {
		$image = imagecreatetruecolor( $w, $h );
		for ( $x = 0; $x < $w; $x += 4 ) {
			imagefilledrectangle( $image, $x, 0, $x + 3, $h - 1, imagecolorallocate( $image, ( $x * 7 ) % 255, ( $x * 13 ) % 255, ( $x * 3 ) % 255 ) );
		}
		ob_start();
		imagejpeg( $image, null, 95 );
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

	/**
	 * An attachment on staging, linked to "live" and changed since the base (plan: push).
	 *
	 * @param string $relative Path.
	 * @param string $mime     Mime type.
	 * @param array  $sizes    Size name => file name (written next to it) for the metadata.
	 * @param array  $bytes    path => bytes (null: already written).
	 * @return array{0:int,1:string} ID and UUID.
	 */
	private function attachment( $relative, $mime, array $sizes = array() ) {
		$id       = self::factory()->post->create(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => $mime,
				'post_title'     => wp_basename( $relative ),
			)
		);
		$metadata = array( 'file' => $relative );
		if ( 0 === strpos( $mime, 'image/' ) ) {
			$metadata['width']  = 400;
			$metadata['height'] = 300;
		}
		foreach ( $sizes as $name => $file ) {
			$metadata['sizes'][ $name ] = array(
				'file'      => $file,
				'width'     => 150,
				'height'    => 150,
				'mime-type' => $mime,
			);
		}
		update_post_meta( $id, '_wp_attached_file', $relative );
		update_post_meta( $id, '_wp_attachment_metadata', $metadata );
		$uuid = Identity::assign( 'post', $id );
		Sync_State::upsert( $this->pairing_id, $uuid, 'attachment', $id, $id, 'strong' );
		$this->live->overrides[ $uuid ] = array(
			'hash'      => self::X,
			'base_hash' => self::X,
		);
		return array( $id, $uuid );
	}

	private function plan() {
		$plan = Planner::start( $this->uuid, array( 'media' ), 1 );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_code() : '' );
		for ( $i = 0; $i < 50 && 'planning' === $plan['state']; $i++ ) {
			$plan = Planner::step( $plan['id'], array(), $this->live );
		}
		$this->assertSame( 'planned', $plan['state'] );
		return $plan;
	}

	private function start( array $plan ) {
		return Push_Runner::start( $plan['id'], 'live.example.com', 1, $this->live );
	}

	private function steps( $push, $max = 200 ) {
		for ( $i = 0; $i < $max && in_array( $push['state'], array( 'sending_files', 'sending_items' ), true ); $i++ ) {
			$push = Push_Runner::step( $push['id'], array(), $this->live );
			$this->assertIsArray( $push, is_wp_error( $push ) ? $push->get_error_code() . ' ' . $push->get_error_message() : '' );
		}
		return $push;
	}

	private function live_session( array $push ) {
		return Push_Session::find( $this->live->live_uuid( $push['push'] ) );
	}

	private function live_tmp( array $push ) {
		$session = $this->live_session( $push );
		return $this->live->as_live(
			function () use ( $session ) {
				return dirname( Media_Receiver::committed_file( $session, 1 ) );
			}
		);
	}

	private function committed_sha( array $push, $ref ) {
		$file = $this->live_tmp( $push ) . '/' . $ref . '.bin';
		return is_file( $file ) ? hash_file( 'sha256', $file ) : '';
	}

	private function live_rows( array $push ) {
		global $wpdb;
		$session = $this->live_session( $push );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT seq, uuid, status, decision, transfer, payload FROM %i WHERE push_id = %d AND side = 'destination' ORDER BY id", Tables::table_name( 'push_items' ), (int) $session['id'] ), ARRAY_A );
		$out     = array();
		foreach ( $rows as $row ) {
			$out[ $row['uuid'] ] = $row;
		}
		return $out;
	}

	private function events( array $push ) {
		return array_column( $push['log'], 'event' );
	}

	/** The live uploads tree outside the push temp folder. */
	private function live_outside_tmp() {
		$out   = array();
		$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->live->base, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $items as $item ) {
			$rel = substr( wp_normalize_path( $item->getPathname() ), strlen( $this->live->base ) + 1 );
			if ( 0 !== strpos( $rel, 'keepmerge-push-staging-to-live/tmp/' ) ) {
				$out[ $rel ] = md5_file( $item->getPathname() );
			}
		}
		ksort( $out );
		return $out;
	}

	// --- the push -----------------------------------------------------------------------------

	public function test_files_go_before_items_and_every_attachment_is_staged_against_its_committed_files() {
		$image = $this->jpeg( 400, 300 );
		$thumb = $this->jpeg( 150, 150 );
		$pdf   = "%PDF-1.4\n" . str_repeat( "% filler line\n", 40000 ) . "%%EOF\n";
		$this->write( $this->source_base(), '2026/10/photo.jpg', $image );
		$this->write( $this->source_base(), '2026/10/photo-150x150.jpg', $thumb );
		$this->write( $this->source_base(), '2026/10/menu.pdf', $pdf );
		list( , $photo ) = $this->attachment( '2026/10/photo.jpg', 'image/jpeg', array( 'thumbnail' => 'photo-150x150.jpg' ) );
		list( , $menu )  = $this->attachment( '2026/10/menu.pdf', 'application/pdf' );
		$before          = $this->live_outside_tmp();

		$plan = $this->plan();
		$push = $this->start( $plan );
		$this->assertSame( 'sending_files', $push['state'] );
		$this->assertSame( 3, $push['files']['total'] );
		$this->assertSame( strlen( $image ) + strlen( $thumb ) + strlen( $pdf ), $push['files']['bytes'] );
		$this->assertSame( 262144, $push['files']['chunk'], "live's chunk size" );
		$open = $this->live_session( $push );
		$this->assertSame( 3, $open['counts']['files'] );

		$push = $this->steps( $push );
		$this->assertSame( 'staged', $push['state'], wp_json_encode( $push['error'] ) );
		$this->assertSame( 3, $push['files']['committed'] );
		$this->assertSame( 0, $push['files']['refused'] );
		$this->assertSame( $push['files']['bytes'], $push['files']['sent_bytes'] );
		$this->assertSame( 2, $push['counts']['staged'] );

		// Files first: every PUT and commit came before the first item batch.
		$first_items = min( array_keys( preg_grep( '#/items$#', $this->live->calls ) ) );
		$last_file   = max( array_keys( preg_grep( '#/(chunks/\d+|commit)$#', $this->live->calls ) ) );
		$this->assertLessThan( $first_items, $last_file );
		$this->assertContains( 'files_done', $this->events( $push ) );

		// The committed temp files are byte-identical to staging's.
		$rows   = $this->live_rows( $push );
		$by_ref = array();
		foreach ( $rows as $uuid => $row ) {
			if ( 0 === strpos( $uuid, 'file:' ) ) {
				$t                                = json_decode( $row['transfer'], true );
				$by_ref[ $t['path'] ]             = (int) $row['seq'];
				$this->assertSame( 'committed', $row['status'] );
			}
		}
		$this->assertSame( hash( 'sha256', $image ), $this->committed_sha( $push, $by_ref['2026/10/photo.jpg'] ) );
		$this->assertSame( hash( 'sha256', $thumb ), $this->committed_sha( $push, $by_ref['2026/10/photo-150x150.jpg'] ) );
		$this->assertSame( hash( 'sha256', $pdf ), $this->committed_sha( $push, $by_ref['2026/10/menu.pdf'] ) );

		// The attachments are staged with their files' final paths for the apply.
		foreach ( array( $photo, $menu ) as $uuid ) {
			$this->assertSame( 'staged', $rows[ $uuid ]['status'] );
			$payload = json_decode( $rows[ $uuid ]['payload'], true );
			$this->assertSame( 'ready', $payload['media']['state'] );
		}
		$this->assertCount( 2, json_decode( $rows[ $photo ]['payload'], true )['media']['files'] );
		$this->assertTrue( $this->live_session( $push )['cursor_json']['plan_hash_ok'] );

		// Live's uploads outside its push temp folder: unchanged. Staging keeps no file rows.
		$this->assertSame( $before, $this->live_outside_tmp() );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE side = 'source_file'", Tables::table_name( 'push_items' ) ) ) );
	}

	public function test_f17_an_identical_file_is_never_sent_and_a_foreign_file_gets_a_new_name_in_the_rewrite_list() {
		$same    = $this->jpeg( 200, 100 );
		$mine    = $this->jpeg( 300, 100 );
		$foreign = $this->jpeg( 120, 100 );
		$this->write( $this->source_base(), '2026/10/same.jpg', $same );
		$this->write( $this->source_base(), '2026/10/banner.jpg', $mine );
		// Live already has same.jpg (identical) and a different banner.jpg of its own.
		$this->write( $this->live->base, '2026/10/same.jpg', $same );
		$this->write( $this->live->base, '2026/10/banner.jpg', $foreign );
		list( , $same_uuid )   = $this->attachment( '2026/10/same.jpg', 'image/jpeg' );
		list( , $banner_uuid ) = $this->attachment( '2026/10/banner.jpg', 'image/jpeg' );
		$this->live->foreign   = true;
		$before                = $this->live_outside_tmp();

		$push = $this->steps( $this->start( $this->plan() ) );
		$this->assertSame( 'staged', $push['state'], wp_json_encode( $push['error'] ) );
		$this->assertSame( 1, $push['files']['have'] );
		$this->assertSame( 1, $push['files']['renamed'] );
		$this->assertSame( 1, $push['files']['committed'] );

		// The identical file: no chunk request at all.
		$rows = $this->live_rows( $push );
		$refs = array();
		foreach ( $rows as $uuid => $row ) {
			if ( 0 === strpos( $uuid, 'file:' ) ) {
				$refs[ json_decode( $row['transfer'], true )['path'] ] = (int) $row['seq'];
			}
		}
		$this->assertSame( 'have', $rows[ 'file:' . $refs['2026/10/same.jpg'] ]['status'] );
		$this->assertSame( array(), preg_grep( '#^' . $refs['2026/10/same.jpg'] . ':#', $this->live->puts ), 'no chunk request for the identical file' );
		$this->assertNotEmpty( preg_grep( '#^' . $refs['2026/10/banner.jpg'] . ':#', $this->live->puts ) );

		// The foreign file stays; ours gets a new name, which is in the push's rewrite list.
		$session  = $this->live_session( $push );
		$rewrites = Media_Receiver::rewrites( (int) $session['id'] );
		$this->assertSame( array( '2026/10/banner.jpg' => '2026/10/banner-1.jpg' ), $rewrites );
		$this->assertSame( hash( 'sha256', $mine ), $this->committed_sha( $push, $refs['2026/10/banner.jpg'] ) );
		$this->assertSame( $before, $this->live_outside_tmp(), "live's banner.jpg untouched" );

		// Both attachments are staged; the renamed one carries its final path.
		$this->assertSame( 'staged', $rows[ $same_uuid ]['status'] );
		$files = json_decode( $rows[ $banner_uuid ]['payload'], true )['media']['files'];
		$this->assertSame( '2026/10/banner-1.jpg', $files[0]['final'] );
		$this->assertSame( 'committed', $files[0]['status'] );
	}

	/**
	 * @group recovery
	 */
	public function test_i02_a_step_that_dies_mid_file_resumes_at_the_next_chunk_live_is_missing() {
		$video = $this->bytes( 262144 * 6 + 1000, 'i02' );
		$this->write( $this->source_base(), '2026/10/clip.mp4', $video );
		$this->attachment( '2026/10/clip.mp4', 'video/mp4' );
		$push = $this->start( $this->plan() );

		// The process dies right after live stored chunk 3, before staging heard the answer.
		$this->live->hook = function ( $method, $route, $data, $live ) {
			if ( 'PUT' === $method && 3 === $data['n'] && empty( $live->dead ) ) {
				$live->dead = true;
				$live->wrap( $live->session_call( substr( $route, 26, 36 ), 'chunk', array(), (string) $data['ref'], '3', $data['bytes'], hash( 'sha256', $data['bytes'] ) ) );
				throw new RuntimeException( 'php-staging stopped' );
			}
			return null;
		};
		try {
			Push_Runner::step( $push['id'], array(), $this->live );
			$this->fail( 'the step should have died' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'php-staging stopped', $e->getMessage() );
		}
		$this->live->hook = null;

		$live_file = Media_Receiver::rows( (int) $this->live_session( $push )['id'], array( 1 ) )[1];
		$this->assertSame( '1111000', $live_file['t']['got'], 'live has chunks 0 to 3' );
		$this->assertSame( array( '1:0', '1:1', '1:2', '1:3' ), $this->live->puts );

		// The dead step's claim runs out; the next step asks live and sends chunk 4 onwards.
		$row                          = Push_Runner::find( $push['id'] );
		$cursor                       = $row['cursor_json'];
		$cursor['push']['busy_until'] = time() - 1;
		SoftGlaze\SafePush\Sync\Plan_Store::update( $push['id'], array( 'cursor_json' => $cursor ) );

		$push = $this->steps( Push_Runner::present( Push_Runner::find( $push['id'] ) ) );
		$this->assertSame( 'staged', $push['state'], wp_json_encode( $push['error'] ) );
		$this->assertSame( array( '1:0', '1:1', '1:2', '1:3', '1:4', '1:5', '1:6' ), $this->live->puts, 'resumed at chunk 4, nothing sent twice' );
		$this->assertSame( hash( 'sha256', $video ), $this->committed_sha( $push, 1 ) );
	}

	/**
	 * @group recovery
	 */
	public function test_i02_an_answer_lost_mid_file_pauses_and_resume_skips_the_chunk_live_already_has() {
		$video = $this->bytes( 262144 * 3 + 7, 'i02b' );
		$this->write( $this->source_base(), '2026/10/lost.mp4', $video );
		$this->attachment( '2026/10/lost.mp4', 'video/mp4' );
		$push = $this->start( $this->plan() );

		$this->live->hook = function ( $method, $route, $data, $live ) {
			if ( 'PUT' === $method && 1 === $data['n'] && empty( $live->lost ) ) {
				$live->lost = true;
				$live->session_call( substr( $route, 26, 36 ), 'chunk', array(), (string) $data['ref'], '1', $data['bytes'], hash( 'sha256', $data['bytes'] ) );
				return Keepmerge_Test_Media_Live::error( 'network_error', 0 );
			}
			return null;
		};
		$push = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 'paused', $push['state'] );
		$this->assertSame( 'files', $push['phase'] );
		$this->assertSame( 'network_error', $push['error']['code'] );

		$this->live->hook = null;
		$push             = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertContains( 'resume', $this->events( $push ) );
		$push = $this->steps( $push );
		$this->assertSame( 'staged', $push['state'] );
		$this->assertSame( array( '1:0', '1:1', '1:2', '1:3' ), $this->live->puts, 'chunk 1 was not sent again' );
		$this->assertSame( hash( 'sha256', $video ), $this->committed_sha( $push, 1 ) );
	}

	/**
	 * @group recovery
	 */
	public function test_i03_a_chunk_damaged_on_the_way_is_sent_again_and_the_commit_hash_matches() {
		$video = $this->bytes( 262144 * 2 + 500, 'i03' );
		$this->write( $this->source_base(), '2026/10/damaged.mp4', $video );
		$this->attachment( '2026/10/damaged.mp4', 'video/mp4' );
		$push = $this->start( $this->plan() );

		$this->live->hook = function ( $method, $route, $data, $live ) {
			if ( 'PUT' === $method && 1 === $data['n'] && empty( $live->damaged ) ) {
				$live->damaged       = true;
				$data['bytes'][1000] = chr( ord( $data['bytes'][1000] ) ^ 0x5a );
			}
			return null;
		};
		$push = $this->steps( $push );
		$this->assertSame( 'staged', $push['state'], wp_json_encode( $push['error'] ) );
		$this->assertContains( 'chunk_retry', $this->events( $push ) );
		$this->assertSame( array( '1:0', '1:1', '1:1', '1:2' ), $this->live->puts, 'chunk 1 refused, then sent again' );
		$this->assertSame( hash( 'sha256', $video ), $this->committed_sha( $push, 1 ) );
	}

	public function test_413_halves_the_chunk_and_live_starts_the_file_again_at_that_size() {
		$video = $this->bytes( 262144 * 2 + 10, 'halve' );
		$this->write( $this->source_base(), '2026/10/proxy.mp4', $video );
		$this->attachment( '2026/10/proxy.mp4', 'video/mp4' );
		$push = $this->start( $this->plan() );

		// A proxy in front of live takes at most 200 KB per request.
		$this->live->hook = function ( $method, $route, $data ) {
			if ( 'PUT' === $method && strlen( $data['bytes'] ) > 204800 ) {
				return Keepmerge_Test_Media_Live::error( 'unexpected_status', 413, array( 'signed' => false ) );
			}
			return null;
		};
		$push = $this->steps( $push );
		$this->assertSame( 'staged', $push['state'], wp_json_encode( $push['error'] ) );
		$this->assertContains( 'chunk_halved', $this->events( $push ) );
		$this->assertSame( 131072, $push['files']['chunk'] );
		$this->assertSame( hash( 'sha256', $video ), $this->committed_sha( $push, 1 ) );
		$live_file = Media_Receiver::rows( (int) $this->live_session( $push )['id'], array( 1 ) )[1];
		$this->assertSame( 131072, $live_file['t']['chunk'] );
	}

	public function test_caps_at_start_a_file_over_the_cap_is_not_sent_and_a_push_over_the_cap_never_starts() {
		$this->write( $this->source_base(), '2026/10/small.jpg', $this->jpeg( 100, 80 ) );
		$this->write( $this->source_base(), '2026/10/big.mp4', $this->bytes( 300000, 'big' ) );
		list( , $small ) = $this->attachment( '2026/10/small.jpg', 'image/jpeg' );
		list( , $big )   = $this->attachment( '2026/10/big.mp4', 'video/mp4' );
		$plan            = $this->plan();

		// The whole push over the per-push cap: refused before any request.
		$push_cap = static function () {
			return array( 'max_push' => 200000 );
		};
		add_filter( 'softglaze_sp_limits', $push_cap );
		$error = $this->start( $plan );
		remove_filter( 'softglaze_sp_limits', $push_cap );
		$this->assertWPError( $error );
		$this->assertSame( 'media_too_large', $error->get_error_code() );
		$this->assertSame( array(), preg_grep( '#/remote/push#', $this->live->calls ), 'nothing sent' );

		// One file over the per-file cap: its attachment goes as a too_large stub, the rest is sent.
		$file_cap = static function () {
			return array( 'max_file' => 200000 );
		};
		add_filter( 'softglaze_sp_limits', $file_cap );
		$push = $this->start( $plan );
		$this->assertSame( 1, $push['files']['total'] );
		$push = $this->steps( $push );
		remove_filter( 'softglaze_sp_limits', $file_cap );
		$this->assertSame( 'staged', $push['state'] );
		$rows = $this->live_rows( $push );
		$this->assertSame( 'staged', $rows[ $small ]['status'] );
		$this->assertSame( 'refused', $rows[ $big ]['status'] );
		$this->assertSame( array(), preg_grep( '#big#', array_map( 'strval', array_column( array_map( function ( $r ) { return json_decode( (string) $r['transfer'], true ); }, $rows ), 'path' ) ) ) );
		$this->assertSame( 'too_large', $push['refused'][0]['reason'] );
	}

	public function test_storage_full_on_live_pauses_the_files_and_resume_finishes_them() {
		$video = $this->bytes( 262144 + 5, 'full' );
		$this->write( $this->source_base(), '2026/10/full.mp4', $video );
		$this->attachment( '2026/10/full.mp4', 'video/mp4' );
		$push = $this->start( $this->plan() );

		$low = static function () {
			return 1000.0;
		};
		add_filter( 'softglaze_sp_free_space', $low );
		$push = Push_Runner::step( $push['id'], array(), $this->live );
		remove_filter( 'softglaze_sp_free_space', $low );
		$this->assertSame( 'paused', $push['state'] );
		$this->assertSame( 'storage_full', $push['error']['code'] );

		$push = $this->steps( Push_Runner::step( $push['id'], array(), $this->live ) );
		$this->assertSame( 'staged', $push['state'] );
		$this->assertSame( hash( 'sha256', $video ), $this->committed_sha( $push, 1 ) );
	}

	public function test_abort_during_the_files_removes_live_temp_folder_and_staging_rows() {
		$video = $this->bytes( 262144 * 4, 'abort' );
		$this->write( $this->source_base(), '2026/10/abort.mp4', $video );
		$this->attachment( '2026/10/abort.mp4', 'video/mp4' );
		$push = $this->start( $this->plan() );

		$this->live->hook = function ( $method, $route, $data ) {
			return 'PUT' === $method && 2 === $data['n'] ? Keepmerge_Test_Media_Live::error( 'network_error', 0 ) : null;
		};
		$push = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 'paused', $push['state'] );
		$tmp = $this->live_tmp( $push );
		$this->assertDirectoryExists( $tmp );

		$aborted = Push_Runner::abort( $push['id'], 1, $this->live );
		$this->assertSame( 'aborted', $aborted['state'] );
		$this->assertDirectoryDoesNotExist( $tmp );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE side = 'source_file'", Tables::table_name( 'push_items' ) ) ) );
		$this->assertNull( Push_Lock::get() );
	}

	/**
	 * The acceptance sizes: a 30 MB image and a 200 MB video, sha256 of the
	 * committed temp files equal to staging's.
	 *
	 * @group large
	 */
	public function test_acceptance_a_30_mb_image_and_a_200_mb_video_arrive_byte_identical() {
		Settings::save( array( 'chunk_kb' => 1024 ) );
		$image = $this->write_big( '2026/11/poster.jpg', $this->jpeg( 1600, 1200 ), 30 * 1048576, 'img' );
		$video = $this->write_big( '2026/11/film.mp4', "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2mp41", 200 * 1048576, 'vid' );
		$this->attachment( '2026/11/poster.jpg', 'image/jpeg' );
		$this->attachment( '2026/11/film.mp4', 'video/mp4' );

		$started = microtime( true );
		$memory  = memory_get_peak_usage( true );
		$push    = $this->steps( $this->start( $this->plan() ), 500 );
		$seconds = round( microtime( true ) - $started, 1 );
		$this->assertSame( 'staged', $push['state'], wp_json_encode( $push['error'] ) );
		$this->assertSame( 2, $push['files']['committed'] );
		$this->assertSame( 1048576, $push['files']['chunk'] );

		$rows = $this->live_rows( $push );
		$refs = array();
		foreach ( $rows as $uuid => $row ) {
			if ( 0 === strpos( $uuid, 'file:' ) ) {
				$refs[ json_decode( $row['transfer'], true )['path'] ] = (int) $row['seq'];
			}
		}
		$this->assertSame( hash_file( 'sha256', $image ), $this->committed_sha( $push, $refs['2026/11/poster.jpg'] ) );
		$this->assertSame( hash_file( 'sha256', $video ), $this->committed_sha( $push, $refs['2026/11/film.mp4'] ) );
		fwrite( STDERR, sprintf( "\n[acceptance] 30 MB + 200 MB through the loopback: %s s, %d PUT requests, peak memory %s MB (before %s MB)\n", $seconds, count( $this->live->puts ), round( memory_get_peak_usage( true ) / 1048576 ), round( $memory / 1048576 ) ) );
	}
}
