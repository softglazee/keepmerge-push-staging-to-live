<?php
/**
 * Findings of the final independent security review, KMF-01 … KMF-09. Each finding's tests failed
 * before its fix, and one regression test of the KMF-07 fix failed before its correction.
 *
 * @package SoftGlaze\SafePush\Tests
 */

require_once __DIR__ . '/class-keepmerge-recovery-harness.php';

use SoftGlaze\SafePush\Apply\Markup_Gate;
use SoftGlaze\SafePush\Apply\Recovery;
use SoftGlaze\SafePush\Apply\Rollback_Runner;
use SoftGlaze\SafePush\Cron;
use SoftGlaze\SafePush\Sync\Exporters\Menus;
use SoftGlaze\SafePush\Sync\Normalizer;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Session;

/**
 * @group security
 * @group review
 * @group review-final
 */
class SecurityReviewFinalTest extends Keepmerge_Recovery_Harness {

	const DIR = '2026/11';

	private function done( $push ) {
		$answers = $this->apply( $push );
		$last    = end( $answers );
		$this->assertSame( 'applied', $last['state'], wp_json_encode( $this->rows( $push ) ) );
		return $last;
	}

	private function decide( $push, array $decisions, $user = null ) {
		return $this->admin( 'POST', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) . '/held', array( 'decisions' => $decisions ), $user );
	}

	private function continue_apply( $push ) {
		$last = null;
		for ( $i = 0; $i < 30; $i++ ) {
			$last = $this->admin( 'POST', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) . '/continue', array( 'confirm' => self::HOST ) );
			if ( 200 !== $last->get_status() || ! in_array( $last->get_data()['state'], array( 'snapshotting', 'applying' ), true ) ) {
				break;
			}
		}
		return $last;
	}

	private function warning_codes( array $row ) {
		$warnings = isset( $row['payload']['warnings'] ) ? (array) $row['payload']['warnings'] : array();
		return array_values( array_unique( array_column( $warnings, 'code' ) ) );
	}

	/**
	 * A new attachment as the source sends it: a real JPEG in $path, exported, then removed here
	 * (with its post) so the push brings it. Returns the envelope (with its media map) and the bytes.
	 */
	private function new_attachment( $path ) {
		$bytes = $this->jpeg( 32, 24, wp_rand( 1, 250 ) );
		$full  = $this->put_file( $path, $bytes );
		$id    = self::factory()->attachment->create_object( $path, 0, array( 'post_mime_type' => 'image/jpeg' ) );
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => 32,
				'height' => 24,
				'file'   => $path,
				'sizes'  => array(),
			)
		);
		$env = $this->new_items( array( 'attachment' => array( $id ) ) )[ $id ];
		if ( is_file( $full ) ) {
			unlink( $full );
		}
		$env['media'] = array(
			array(
				'path' => $path,
				'ref'  => 1,
			),
		);
		return array( $env, $bytes );
	}

	/** A live attachment of this site with its file on disk. */
	private function live_attachment( $path, $bytes, array $sizes = array() ) {
		$this->put_file( $path, $bytes );
		$id = self::factory()->attachment->create_object( $path, 0, array( 'post_mime_type' => 'image/jpeg' ) );
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => 30,
				'height' => 20,
				'file'   => $path,
				'sizes'  => $sizes,
			)
		);
		return $id;
	}

	/**
	 * Live's answer to a files announcement of one file for an attachment UUID (a second push of a
	 * compromised source); the push is aborted afterwards.
	 */
	private function announce_one( $path, $bytes, $attachment ) {
		$push     = wp_generate_uuid4();
		$response = $this->call(
			'POST',
			'/keepmerge/v1/remote/push',
			(string) wp_json_encode(
				array(
					'push'       => $push,
					'plan_hash'  => str_repeat( 'a', 64 ),
					'counts'     => array(
						'create'   => 0,
						'update'   => 1,
						'recreate' => 0,
						'trash'    => 0,
						'adopt'    => 0,
					),
					'items'      => 1,
					'files'      => 1,
					'file_bytes' => strlen( $bytes ),
				)
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$response = $this->call(
			'POST',
			'/keepmerge/v1/remote/push/' . $push . '/files',
			(string) wp_json_encode(
				array(
					'chunk_size' => 65536,
					'files'      => array(
						array(
							'ref'        => 1,
							'path'       => $path,
							'size'       => strlen( $bytes ),
							'sha256'     => hash( 'sha256', $bytes ),
							'attachment' => $attachment,
						),
					),
				)
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$answer = $response->get_data()['files'][0];
		$this->assertSame( 200, $this->call( 'POST', '/keepmerge/v1/remote/push/' . $push . '/abort', '{}' )->get_status() );
		return $answer;
	}

	// --- KMF-01 -----------------------------------------------------------------------------------

	/**
	 * KMF-01: attachment metadata naming sibling files the push never sent (another attachment's
	 * file, a file of another plugin) is not written; deleting the attachment keeps those files, and
	 * a later push cannot claim them as the attachment's own.
	 */
	public function test_kmf01_size_names_the_push_never_sent_are_not_written_and_survive_deleting_the_attachment() {
		$victim      = $this->put_file( self::DIR . '/kmf01-victim.jpg', 'a file another plugin keeps here' );
		$other_bytes = $this->jpeg( 20, 20, 10 );
		$this->live_attachment( self::DIR . '/kmf01-other.jpg', $other_bytes );
		$other_file = $this->base() . '/' . self::DIR . '/kmf01-other.jpg';

		list( $env, $bytes )              = $this->new_attachment( self::DIR . '/kmf01-a.jpg' );
		$env['data']['metadata']['sizes'] = array(
			'thumbnail' => array(
				'file'      => 'kmf01-victim.jpg',
				'width'     => 1,
				'height'    => 1,
				'mime-type' => 'image/jpeg',
			),
			'medium'    => array(
				'file'      => 'kmf01-other.jpg',
				'width'     => 1,
				'height'    => 1,
				'mime-type' => 'image/jpeg',
			),
		);
		$push                             = $this->stage(
			array( $env ),
			array(
				1 => array(
					'path'       => self::DIR . '/kmf01-a.jpg',
					'bytes'      => $bytes,
					'attachment' => $env['uuid'],
				),
			)
		);
		$this->done( $push );

		$row  = $this->rows( $push )[ $env['uuid'] ];
		$live = (int) $row['local_object_id'];
		$meta = wp_get_attachment_metadata( $live );
		$this->assertSame( array(), isset( $meta['sizes'] ) ? $meta['sizes'] : array(), 'sizes whose files this push did not bring are not written' );
		$this->assertContains( 'size_file_missing', $this->warning_codes( $row ) );

		// A second push cannot replace the other plugin's file as if it were this attachment's.
		$answer = $this->announce_one( self::DIR . '/kmf01-victim.jpg', 'bytes from a compromised source', $env['uuid'] );
		$this->assertSame( 'renamed', $answer['status'] );
		$this->assertNotSame( self::DIR . '/kmf01-victim.jpg', $answer['path'] );

		wp_delete_attachment( $live, true );
		$this->assertFileExists( $victim, 'deleting the pushed attachment keeps a file it never brought' );
		$this->assertSame( 'a file another plugin keeps here', file_get_contents( $victim ) );
		$this->assertFileExists( $other_file, 'and another attachment\'s file' );
		$this->assertSame( $other_bytes, file_get_contents( $other_file ) );
	}

	/**
	 * KMF-01: a file an attachment took as it already was on live ("have") never becomes that
	 * attachment's own: a later push gets a new name for it instead of replacing it in place.
	 */
	public function test_kmf01_a_file_taken_as_it_was_on_live_is_never_replaced_in_place_later() {
		$doc_bytes = $this->jpeg( 10, 10, 30 );
		$doc       = $this->put_file( self::DIR . '/kmf01-doc.jpg', $doc_bytes );

		list( $env, $bytes )              = $this->new_attachment( self::DIR . '/kmf01-b.jpg' );
		$env['data']['metadata']['sizes'] = array(
			'thumbnail' => array(
				'file'      => 'kmf01-doc.jpg',
				'width'     => 10,
				'height'    => 10,
				'mime-type' => 'image/jpeg',
			),
		);
		$env['data']['files'][]           = array(
			'path'   => self::DIR . '/kmf01-doc.jpg',
			'size'   => strlen( $doc_bytes ),
			'sha256' => hash( 'sha256', $doc_bytes ),
		);
		$env['media'][]                   = array(
			'path' => self::DIR . '/kmf01-doc.jpg',
			'ref'  => 2,
		);
		$push                             = $this->stage(
			array( $env ),
			array(
				1 => array(
					'path'       => self::DIR . '/kmf01-b.jpg',
					'bytes'      => $bytes,
					'attachment' => $env['uuid'],
				),
				2 => array(
					'path'       => self::DIR . '/kmf01-doc.jpg',
					'bytes'      => $doc_bytes,
					'attachment' => $env['uuid'],
				),
			)
		);
		$this->done( $push );
		$live = (int) $this->rows( $push )[ $env['uuid'] ]['local_object_id'];
		$this->assertSame( 'attachment', get_post_type( $live ) );

		$answer = $this->announce_one( self::DIR . '/kmf01-doc.jpg', 'bytes from a compromised source', $env['uuid'] );
		$this->assertSame( 'renamed', $answer['status'], 'a file the push did not move there is not the attachment\'s to replace' );
		$this->assertSame( $doc_bytes, file_get_contents( $doc ) );

		// The attachment's own file, which the push moved there, is still replaced in place.
		$answer = $this->announce_one( self::DIR . '/kmf01-b.jpg', $this->jpeg( 32, 24, 7 ), $env['uuid'] );
		$this->assertSame( 'need', $answer['status'] );
	}

	/**
	 * KMF-01: an update never replaces in place a file that another attachment names as well.
	 */
	public function test_kmf01_a_file_another_attachment_also_names_is_not_replaced_in_place() {
		$x_bytes = $this->jpeg( 30, 20, 40 );
		$x       = $this->live_attachment( self::DIR . '/kmf01-x.jpg', $x_bytes );
		$this->live_attachment(
			self::DIR . '/kmf01-y.jpg',
			$this->jpeg( 30, 20, 90 ),
			array(
				'thumbnail' => array(
					'file'      => 'kmf01-x.jpg',
					'width'     => 30,
					'height'    => 20,
					'mime-type' => 'image/jpeg',
				),
			)
		);

		$new          = $this->jpeg( 30, 20, 250 );
		$env          = $this->envelope(
			'attachment',
			$x,
			'push',
			static function ( $data ) use ( $new ) {
				$data['files'][0]['size']   = strlen( $new );
				$data['files'][0]['sha256'] = hash( 'sha256', $new );
				return $data;
			}
		);
		$env['media'] = array(
			array(
				'path' => self::DIR . '/kmf01-x.jpg',
				'ref'  => 1,
			),
		);
		$push         = $this->stage(
			array( $env ),
			array(
				1 => array(
					'path'       => self::DIR . '/kmf01-x.jpg',
					'bytes'      => $new,
					'attachment' => $env['uuid'],
				),
			)
		);
		$this->apply( $push );

		$this->assertSame( $x_bytes, file_get_contents( $this->base() . '/' . self::DIR . '/kmf01-x.jpg' ), 'the shared file keeps its bytes' );
	}

	// --- KMF-02 -----------------------------------------------------------------------------------

	/**
	 * KMF-02: an option name that differs from live's stored name only in case is refused (MySQL
	 * would update the stored row through its case-insensitive collation).
	 */
	public function test_kmf02_an_option_name_differing_from_the_stored_one_only_in_case_is_refused() {
		$name = 'theme_mods_' . get_stylesheet();
		update_option( $name, array( 'kmf02' => 'live' ) );
		$variant = 'Theme_Mods_' . get_stylesheet();

		$env                  = $this->envelope( 'option', $name, 'push' );
		$env['uuid']          = 'option:' . $variant;
		$env['type']          = $variant;
		$env['data']['name']  = $variant;
		$env['data']['value'] = array(
			'custom_css_post_id' => 4242,
			'kmf02'              => 'staging',
		);
		$push                 = $this->stage( array( $env ) );

		$row = $this->rows( $push )[ 'option:' . $variant ];
		$this->assertSame( 'refused', $row['status'], wp_json_encode( $row ) );
		$this->assertSame( 0, strpos( (string) $row['error'], 'option_denied' ) );
		$this->assertSame( array( 'kmf02' => 'live' ), get_option( $name ) );
	}

	/**
	 * KMF-02: theme mods under any case of the prefix keep live's own custom_css_post_id.
	 */
	public function test_kmf02_theme_mods_under_any_case_keep_lives_custom_css_post_id() {
		$name = 'theme_mods_' . get_stylesheet();
		update_option( $name, array( 'kmf02' => 'source' ) );
		$variant = 'Theme_Mods_' . get_stylesheet();

		$env = $this->envelope( 'option', $name, 'create' );
		delete_option( $name );
		$env['uuid']          = 'option:' . $variant;
		$env['type']          = $variant;
		$env['data']['name']  = $variant;
		$env['data']['value'] = array(
			'custom_css_post_id' => 4242,
			'kmf02'              => 'staging',
		);
		$push                 = $this->stage( array( $env ) );
		$this->done( $push );

		$value = get_option( $variant );
		$this->assertIsArray( $value );
		$this->assertSame( 'staging', $value['kmf02'] );
		$this->assertArrayNotHasKey( 'custom_css_post_id', $value, 'the source never sets which post holds Additional CSS' );
		delete_option( $variant );
	}

	// --- KMF-03 -----------------------------------------------------------------------------------

	/**
	 * KMF-03: whether "Apply with WordPress's HTML filter" is offered comes from every reason, also
	 * when more reasons exist than the row keeps.
	 */
	public function test_kmf03_a_reason_past_the_kept_ones_still_rules_out_the_html_filter() {
		$menu = wp_create_nav_menu( 'KMF03 menu' );
		for ( $i = 0; $i < 11; $i++ ) {
			wp_update_nav_menu_item(
				$menu,
				0,
				array(
					'menu-item-title'    => 'Item ' . $i,
					'menu-item-url'      => 'https://example.net/' . $i,
					'menu-item-type'     => 'custom',
					'menu-item-status'   => 'publish',
					'menu-item-position' => $i + 1,
				)
			);
		}
		$ids  = array_map(
			static function ( $post ) {
				return (int) $post->ID;
			},
			Menus::item_posts( $menu )
		);
		$env  = $this->envelope(
			'menu',
			$menu,
			'push',
			static function ( $data ) {
				for ( $i = 0; $i < 10; $i++ ) {
					$data['items'][ $i ]['title'] = '<u>x</u>';
				}
				$data['items'][10]['meta'] = array( '_mega_html' => array( '<script>alert(1)</script>' ) );
				return $data;
			},
			array( 'item_ids' => $ids )
		);
		$push = $this->stage( array( $env ) );
		$answers = $this->apply( $push );
		$this->assertSame( 'awaiting_review', end( $answers )['state'] );

		$held = $this->admin( 'GET', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) )->get_data()['held_items'];
		$this->assertCount( 1, $held );
		$this->assertFalse( $held[0]['can_filter'], 'script in item meta: the HTML filter does not reach it' );
		$this->assertContains( 'pattern:tag', array_column( $held[0]['reasons'], 'rule' ), 'the meta reason is among the reasons shown' );
		$this->assertSame( 11, $held[0]['reasons_total'] );
		$this->assertSame( 400, $this->decide( $push, array( $env['uuid'] => 'filtered' ) )->get_status() );
	}

	/**
	 * KMF-03: a row applied "with WordPress's HTML filter" is checked by the gate again after the
	 * filter; anything still held is not written.
	 */
	public function test_kmf03_a_filtered_row_with_anything_left_for_the_gate_is_not_written() {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'KMF03 filtered',
				'post_content' => 'old',
			)
		);
		$env  = $this->envelope(
			'post',
			$page,
			'push',
			static function ( $data ) {
				$data['post_content'] = '<p>Hi</p><script>x()</script>';
				return $data;
			}
		);
		$push = $this->stage( array( $env ) );
		$answers = $this->apply( $push );
		$this->assertSame( 'awaiting_review', end( $answers )['state'] );
		$this->assertSame( 200, $this->decide( $push, array( $env['uuid'] => 'filtered' ) )->get_status() );

		// The stored row holds more than its reasons said (as a truncated reason list once allowed).
		global $wpdb;
		$row                                 = $this->rows( $push )[ $env['uuid'] ];
		$payload                             = $row['payload'];
		$payload['data']['meta']['_kmf03_x'] = array( '<script>y()</script>' );
		$wpdb->update( Tables::table_name( 'push_items' ), array( 'payload' => wp_json_encode( $payload ) ), array( 'id' => (int) $row['id'] ) );

		$this->continue_apply( $push );
		$this->assertSame( '', get_post_meta( $page, '_kmf03_x', true ), 'the script in meta is not written' );
		$this->assertSame( 'old', get_post( $page )->post_content );
		$this->assertNotSame( 'applied', $this->rows( $push )[ $env['uuid'] ]['status'] );
	}

	// --- KMF-04 -----------------------------------------------------------------------------------

	/**
	 * KMF-04: page-builder JSON nested deeper than the decoder's limit is held, not passed.
	 */
	public function test_kmf04_builder_json_too_deep_to_decode_is_held() {
		$pad     = str_repeat( '[', 70 ) . str_repeat( ']', 70 );
		$json    = '[{"settings":{"html":"<scr\u0069pt>alert(1)<\/script>","pad":' . $pad . '}}]';
		$reasons = Markup_Gate::check( 'post', array( 'meta' => array( '_builder_data' => array( $json ) ) ) );
		$this->assertNotEmpty( $reasons, 'held' );
	}

	/**
	 * KMF-04: JSON escapes inside a tag name do not hide it (a JSON string scalar, a value that is
	 * not a JSON document, and JSON strings nested past the decoded levels).
	 */
	public function test_kmf04_json_unicode_escapes_are_decoded_before_the_patterns() {
		$cases = array(
			'json string'   => '"<scr\u0069pt>alert(1)</scr\u0069pt>"',
			'not json'      => 'var a = "<scr\u0069pt>alert(1)</scr\u0069pt>";',
			'hex escape'    => 'var a = "<scr\x69pt>alert(1)";',
			'event escaped' => '<img src=x \u006fnerror=alert(1)>',
		);
		foreach ( $cases as $label => $value ) {
			$this->assertNotEmpty( Markup_Gate::scan( $value, 'meta._x' ), $label );
		}

		$inner = '<scr\u0069pt>alert(1)</scr\u0069pt>';
		for ( $i = 0; $i < Markup_Gate::MAX_JSON_DEPTH + 2; $i++ ) {
			$inner = (string) wp_json_encode( array( 'v' => $inner ) );
		}
		$this->assertNotEmpty( Markup_Gate::scan( $inner, 'meta._x' ), 'nested past the decoded levels' );

		// Ordinary values stay unheld.
		foreach ( array( '"plain text"', '[gallery ids="1,2"]', '{"a":"b\u00e9"}', 'C:\\data\\file' ) as $value ) {
			$this->assertSame( array(), Markup_Gate::scan( $value, 'meta._x' ), $value );
		}
	}

	// --- KMF-05 -----------------------------------------------------------------------------------

	/**
	 * KMF-05: serialized text core's is_serialized() misses (C:, trailing bytes, leading space) and
	 * structures past the depth limit are refused, not passed.
	 */
	public function test_kmf05_serialized_text_residuals_and_depth_fail_closed() {
		$cases = array(
			'custom object'  => 'C:11:"ArrayObject":21:{x:i:0;a:0:{};m:a:0:{}}',
			'trailing bytes' => 'O:6:"Gadget":0:{}X',
			'array trailing' => 'a:1:{i:0;O:6:"Gadget":0:{}} ',
			'leading space'  => "  O:6:\"Gadget\":0:{}\n",
			'enum'           => 'E:7:"Foo:Bar";',
		);
		foreach ( $cases as $label => $text ) {
			$this->assertTrue( Normalizer::holds_serialized_text( array( 'meta' => array( '_x' => array( $text ) ) ) ), $label );
		}

		$deep = 'O:8:"stdClass":0:{}';
		for ( $i = 0; $i < Normalizer::MAX_DEPTH + 5; $i++ ) {
			$deep = array( $deep );
		}
		$this->assertTrue( Normalizer::holds_serialized_text( $deep ), 'past the depth limit: refused' );

		// Object markers: checked at the same depth decode() reads them.
		$objects = 'O:8:"stdClass":0:{}';
		for ( $i = 0; $i < 40; $i++ ) {
			$objects = array(
				Normalizer::MARK => 'object',
				'props'          => array( 'p' => $objects ),
			);
		}
		$this->assertFalse( is_wp_error( Normalizer::decode( $objects ) ), 'decode() reads this depth' );
		$this->assertTrue( Normalizer::holds_serialized_text( $objects ) );

		foreach ( array( 'Category: 5 items', 'a:b', 'O:5', 'Order C: 12', 'Note: a:1:{x}' ) as $text ) {
			$this->assertFalse( Normalizer::holds_serialized_text( $text ), $text );
		}
	}

	// --- KMF-06 -----------------------------------------------------------------------------------

	/**
	 * KMF-06: a finalize that fails (plan hash or counts the source controls) purges the staged rows.
	 */
	public function test_kmf06_a_failed_finalize_purges_the_staged_rows() {
		global $wpdb;
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$env  = $this->envelope(
			'post',
			$page,
			'push',
			static function ( $data ) {
				$data['post_content'] = str_repeat( 'x', 100000 );
				return $data;
			}
		);
		$push = wp_generate_uuid4();
		$this->assertSame(
			200,
			$this->call(
				'POST',
				'/keepmerge/v1/remote/push',
				(string) wp_json_encode(
					array(
						'push'       => $push,
						'plan_hash'  => str_repeat( 'a', 64 ),
						'counts'     => array(
							'create'   => 0,
							'update'   => 1,
							'recreate' => 0,
							'trash'    => 0,
							'adopt'    => 0,
						),
						'items'      => 1,
						'files'      => 0,
						'file_bytes' => 0,
					)
				)
			)->get_status()
		);
		$response = $this->call(
			'POST',
			'/keepmerge/v1/remote/push/' . $push . '/items',
			(string) wp_json_encode(
				array(
					'seq'   => 1,
					'items' => array( $env ),
				)
			)
		);
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$session = Push_Session::find( $push );
		$this->assertSame( 'failed', $session['state'] );
		$this->assertSame( 'plan_hash_mismatch', $session['error_code'] );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE push_id = %d', Tables::table_name( 'push_items' ), (int) $session['id'] ) ), 'no staged row is kept' );
		$this->assertNull( Push_Lock::get() );
	}

	/**
	 * KMF-06: the daily prune expires every due session, not a fixed number of them.
	 */
	public function test_kmf06_the_daily_prune_expires_every_due_session() {
		global $wpdb;
		$pairing = (int) \SoftGlaze\SafePush\Pairing_Store::find( $this->pairing_uuid )['id'];
		$old     = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		for ( $i = 0; $i < 120; $i++ ) {
			$wpdb->insert(
				Tables::table_name( 'pushes' ),
				array(
					'push_uuid'    => wp_generate_uuid4(),
					'pairing_id'   => $pairing,
					'side'         => 'destination',
					'state'        => 'failed',
					'plan_hash'    => str_repeat( 'a', 64 ),
					'counts'       => '{}',
					'cursor_json'  => '{}',
					'chunk_size'   => 0,
					'started_gmt'  => $old,
					'initiated_by' => 0,
				)
			);
		}
		Cron::daily_prune();
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE side = 'destination' AND state = 'failed' AND started_gmt = %s", Tables::table_name( 'pushes' ), $old ) ) );
	}

	// --- KMF-07 -----------------------------------------------------------------------------------

	/**
	 * KMF-07: items a live administrator's session writes without having approved them go through
	 * WordPress's HTML filter, as they would when the receiver writes them.
	 */
	public function test_kmf07_an_admin_finished_apply_filters_items_it_did_not_approve() {
		$staged  = '<p style="color: red; margin: 0">Styled</p>';
		$plain   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'old',
			)
		);
		$held    = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'old',
			)
		);
		$plain_e = $this->envelope(
			'post',
			$plain,
			'push',
			static function ( $data ) use ( $staged ) {
				$data['post_content'] = $staged;
				return $data;
			}
		);
		$held_e  = $this->envelope(
			'post',
			$held,
			'push',
			static function ( $data ) {
				$data['post_content'] = '<p>Hi</p><script>x()</script>';
				return $data;
			}
		);
		$push    = $this->stage( array( $plain_e, $held_e ) );
		$this->assertSame( 'staged', $this->rows( $push )[ $plain_e['uuid'] ]['status'], 'the gate passes the styled page' );
		$answers = $this->apply( $push );
		$this->assertSame( 'awaiting_review', end( $answers )['state'] );
		$this->assertSame( 200, $this->decide( $push, array( $held_e['uuid'] => 'approve' ) )->get_status() );
		$this->assertSame( 'applied', $this->continue_apply( $push )->get_data()['state'] );

		$this->assertSame( wp_kses( $staged, 'post' ), get_post( $plain )->post_content, 'written as the receiver would write it' );
		$this->assertSame( '<p>Hi</p><script>x()</script>', get_post( $held )->post_content, 'the approved item as it is' );
	}

	/**
	 * KMF-07: global styles the filter leaves unchanged keep staging's JSON text when a live
	 * administrator finishes the apply (the hash compares the text; the gate already holds global
	 * styles that core's filters would change). A regression test of the KMF-07 fix itself: it passes
	 * on 35924f9 and failed on 9febb2b (red-kmf07-global-styles-text-9febb2b.txt), fixed in eb678c2.
	 */
	public function test_kmf07_global_styles_the_filter_does_not_change_keep_their_text() {
		$theme = wp_get_theme( 'twentytwentyfive' )->exists() ? 'twentytwentyfive' : 'twentytwentyfour';
		switch_theme( $theme );
		$styles = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		wp_set_object_terms( $styles, $theme, 'wp_theme' );
		$held   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => 'old',
			)
		);
		$text   = '';
		$env    = $this->envelope(
			'global_styles',
			$styles,
			'push',
			static function ( $data ) use ( &$text ) {
				$json                                       = json_decode( $data['post_content'], true );
				$json['styles']['color']['background']      = '#123456';
				$json['styles']['spacing']['padding']['top'] = 'calc(1rem / 2)';
				$text                                       = (string) wp_json_encode( $json );
				$data['post_content']                       = $text;
				return $data;
			}
		);
		$held_e = $this->envelope(
			'post',
			$held,
			'push',
			static function ( $data ) {
				$data['post_content'] = '<p>Hi</p><script>x()</script>';
				return $data;
			}
		);
		$push   = $this->stage( array( $env, $held_e ) );
		$this->assertSame( 'staged', $this->rows( $push )[ $env['uuid'] ]['status'], wp_json_encode( $this->rows( $push )[ $env['uuid'] ]['payload']['gate'] ?? null ) );
		$answers = $this->apply( $push );
		$this->assertSame( 'awaiting_review', end( $answers )['state'] );
		$this->assertSame( 200, $this->decide( $push, array( $held_e['uuid'] => 'approve' ) )->get_status() );
		$this->assertSame( 'applied', $this->continue_apply( $push )->get_data()['state'] );

		$this->assertSame( $text, get_post( $styles )->post_content, 'the same text as on staging' );
	}

	// --- KMF-08 -----------------------------------------------------------------------------------

	/**
	 * KMF-08: meta keys are compared without case for the reserved and kept keys, and keys a
	 * database collation could read as another key (non-ASCII, edge spaces) are dropped.
	 */
	public function test_kmf08_meta_keys_matching_reserved_keys_through_a_collation_are_refused_or_dropped() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$bad  = $this->envelope(
			'post',
			$page,
			'push',
			static function ( $data ) {
				$data['meta']['_WP_ATTACHED_FILE'] = array( '../x.jpg' );
				return $data;
			}
		);
		$push = $this->stage( array( $bad ) );
		$row  = $this->rows( $push )[ $bad['uuid'] ];
		$this->assertSame( 'refused', $row['status'] );
		$this->assertSame( 0, strpos( (string) $row['error'], 'reserved_meta' ) );
		Push_Lock::release( $push );

		$page2 = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$env   = $this->envelope(
			'post',
			$page2,
			'push',
			static function ( $data ) {
				$data['meta']['_ſoftglaze_sp_uuid']   = array( '11111111-2222-4333-8444-555555555555' );
				$data['meta']['_wp_page_template ']   = array( 'x' );
				$data['meta']['_WP_PAGE_TEMPLATE']    = array( 'x' );
				$data['meta']['Größe']                = array( 'L' );
				$data['meta']['kmf08 kept field']     = array( 'kept' );
				return $data;
			}
		);
		$push2 = $this->stage( array( $env ) );
		$row   = $this->rows( $push2 )[ $env['uuid'] ];
		$this->assertSame( 'staged', $row['status'] );
		$keys = array_keys( (array) $row['payload']['data']['meta'] );
		foreach ( array( '_ſoftglaze_sp_uuid', '_wp_page_template ', '_WP_PAGE_TEMPLATE', 'Größe' ) as $key ) {
			$this->assertNotContains( $key, $keys, $key );
		}
		$this->assertContains( 'kmf08 kept field', $keys );
	}

	// --- KMF-09 -----------------------------------------------------------------------------------

	/** A source-driven push left interrupted, with its lock. */
	private function interrupted_push() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$push = $this->stage( array( $this->changed_page( $page ) ) );
		$id   = $this->session_id( $push );
		$cursor          = Push_Session::find( $push )['cursor_json'];
		$cursor['apply'] = array(
			'pass'       => 'write',
			'pos'        => 0,
			'idx'        => 0,
			'actor'      => 'remote',
			'user'       => 0,
			'done'       => 0,
			'busy_until' => 0,
			'error'      => '',
			'order'      => array(),
		);
		Push_Session::update(
			$id,
			array(
				'state'       => 'interrupted',
				'cursor_json' => $cursor,
			)
		);
		return $push;
	}

	/**
	 * KMF-09: a resume leaves "interrupted" only when the push is still interrupted (a rollback
	 * that started meanwhile is not overwritten).
	 */
	public function test_kmf09_a_resume_does_not_overwrite_a_rollback_that_started_meanwhile() {
		$push  = $this->interrupted_push();
		$stale = Push_Session::find( $push );
		Push_Session::update( (int) $stale['id'], array( 'state' => 'rolling_back' ) );

		$resumed = Recovery::resume( $stale, 'remote', 0 );
		$this->assertWPError( $resumed );
		$this->assertSame( 'rolling_back', Push_Session::find( $push )['state'] );
	}

	/**
	 * KMF-09: a rollback starts only from the state it read (a resume that started meanwhile is not
	 * overwritten).
	 */
	public function test_kmf09_a_rollback_does_not_start_over_a_resume_that_started_meanwhile() {
		$push  = $this->interrupted_push();
		$stale = Push_Session::find( $push );
		Push_Session::update( (int) $stale['id'], array( 'state' => 'applying' ) );

		$started = Rollback_Runner::run( $stale, array(), self::$admin, 'user' );
		$this->assertWPError( $started );
		$this->assertSame( 'applying', Push_Session::find( $push )['state'] );
	}
}
