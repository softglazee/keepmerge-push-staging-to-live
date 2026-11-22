<?php
/**
 * Rollback on live: RB-01 to RB-06 and RB-08 through the admin
 * routes, as a live administrator with the wp_rest nonce and the typed domain; the pushes are
 * applied through the signed apply route first. S-77
 * (no remote rollback; admin rollback needs the capability, the nonce and the typed domain).
 *
 * @package SoftGlaze\SafePush\Tests
 */

require_once __DIR__ . '/class-keepmerge-recovery-harness.php';

use SoftGlaze\SafePush\Apply\Raw_Restore;
use SoftGlaze\SafePush\Apply\Rollback_Runner;
use SoftGlaze\SafePush\Apply\Snapshot_Store;
use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Sync\Exporters\Menus;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Sync\Sync_State;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Media_Receiver;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Session;

/**
 * @group rollback
 */
class RollbackTest extends Keepmerge_Recovery_Harness {

	const YEAR = '2032/03';

	public function tear_down() {
		$this->remove_tree( $this->base() . '/2032' );
		parent::tear_down();
	}

	private function applied( $push ) {
		$answers = $this->apply( $push );
		$last    = end( $answers );
		$this->assertSame( 'applied', $last['state'], wp_json_encode( $this->rows( $push ) ) );
		return $last;
	}

	// --- RB-01 --------------------------------------------------------------------------------------

	public function test_rb01_a_full_push_rolled_back_puts_every_item_back_to_its_pre_hash() {
		Settings::save(
			array_merge(
				Settings::get(),
				array(
					'custom_options' => array( 'km_custom_*' ),
					'option_groups'  => array_merge( Settings::get()['option_groups'], array( 'widgets' => true ) ),
				)
			)
		);
		Scope::flush();

		$page    = $this->page( 'RB01 page', '', array( 'km_plain' => 'old plain', 'km_list' => array( 'a' => 1, 'b' => array( 2, 3 ) ) ) );
		$post    = self::factory()->post->create( array( 'post_title' => 'RB01 post' ) );
		$cat     = self::factory()->category->create( array( 'name' => 'RB01 category', 'description' => 'Old description' ) );
		$pattern = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'RB01 pattern',
				'post_content' => '<!-- wp:paragraph --><p>Old pattern</p><!-- /wp:paragraph -->',
			)
		);
		$menu    = wp_create_nav_menu( 'RB01 menu' );
		foreach ( array( 'One', 'Two', 'Three' ) as $title ) {
			wp_update_nav_menu_item(
				$menu,
				0,
				array(
					'menu-item-title'  => $title,
					'menu-item-url'    => 'https://example.net/' . strtolower( $title ),
					'menu-item-type'   => 'custom',
					'menu-item-status' => 'publish',
				)
			);
		}
		$item_ids = array_map(
			static function ( $p ) {
				return (int) $p->ID;
			},
			Menus::item_posts( $menu )
		);
		update_option( 'blogdescription', 'RB01 live tagline' );
		update_option( 'km_custom_list', array( 'x' => 1.5, 'y' => array( 'z' ) ) );
		update_option( 'widget_text', array( 2 => array( 'title' => 'Old widget' ), '_multiwidget' => 1 ) );

		$envs   = array();
		$envs[] = $this->envelope(
			'post',
			$page,
			'push',
			static function ( $data ) {
				$data['post_title']         = 'RB01 page (staging)';
				$data['meta']['km_plain']   = array( 'new plain' );
				$data['meta']['km_list']    = array( array( 'a' => 9 ) );
				$data['meta']['km_added']   = array( 'added by the push' );
				return $data;
			},
			array( 'scope' => array( 'taxonomies' => array(), 'meta_skip' => array() ) )
		);
		$envs[] = $this->changed_page( $post );
		$envs[] = $this->envelope(
			'term',
			$cat,
			'push',
			static function ( $data ) {
				$data['name']        = 'RB01 category (staging)';
				$data['description'] = 'New description';
				return $data;
			}
		);
		$envs[] = $this->envelope(
			'pattern',
			$pattern,
			'push',
			static function ( $data ) {
				$data['post_content'] = '<!-- wp:paragraph --><p>New pattern</p><!-- /wp:paragraph -->';
				return $data;
			}
		);
		$menu_env = $this->envelope(
			'menu',
			$menu,
			'push',
			static function ( $data ) {
				$data['items']             = array_slice( $data['items'], 0, 2 );
				$data['items'][1]['title'] = 'Two renamed';
				$data['name']              = 'RB01 menu renamed';
				return $data;
			},
			array( 'item_ids' => array_slice( $item_ids, 0, 2 ) )
		);
		$menu_env['refs'] = array();
		$envs[]           = $menu_env;
		$envs[]           = $this->envelope(
			'option',
			'blogdescription',
			'push',
			static function ( $data ) {
				$data['value'] = 'RB01 staging tagline';
				return $data;
			}
		);
		$envs[]           = $this->envelope(
			'option',
			'km_custom_list',
			'push',
			static function ( $data ) {
				$data['value'] = array( 'x' => 2 );
				return $data;
			}
		);
		$envs[]           = $this->envelope(
			'widgets',
			'widgets',
			'push',
			static function ( $data ) {
				$data['widgets']['widget_text'][2]['title'] = 'New widget';
				return $data;
			}
		);

		// What live had: hashes, raw option texts, sync rows.
		$before_options = array();
		foreach ( array( 'blogdescription', 'km_custom_list', 'widget_text' ) as $name ) {
			$before_options[ $name ] = Snapshot_Store::raw_option( $name );
		}
		$before_meta = get_post_meta( $page );

		$push = $this->stage( $envs );
		$this->applied( $push );
		$this->assertSame( 'RB01 page (staging)', get_post( $page )->post_title );
		$this->assertSame( 'Two renamed', Menus::item_posts( $menu )[1]->post_title );
		$this->assertSame( 'trash', get_post_status( $item_ids[2] ), 'the push trashed the third menu item' );
		$session = Push_Session::find( $push );
		$pairing = (int) $session['pairing_id'];
		$this->assertNotNull( Sync_State::find( $pairing, $envs[0]['uuid'] ), 'the apply recorded a base' );

		$preview = $this->preview( $push );
		$this->assertSame( 200, $preview->get_status() );
		$data = $preview->get_data();
		$this->assertTrue( $data['can'] );
		$this->assertSame( count( $envs ), $data['restore'] );
		$this->assertSame( 0, $data['remove'] );
		$this->assertSame( 0, $data['conflict_count'] );
		$this->assertTrue( $data['complete'] );

		$result = $this->rollback( $push );
		$this->assertSame( count( $envs ), (int) $result['rollback']['counts']['restored'] );

		// Every item's hash equals its pre_hash.
		$this->assertSame( count( $envs ), $this->assert_restored_to_pre_hash( $push ) );
		$this->assertSame( 'RB01 page', get_post( $page )->post_title );
		$this->assertSame( 'RB01 category', get_term( $cat )->name );
		$this->assertSame( 'Old description', get_term( $cat )->description );
		$this->assertStringContainsString( 'Old pattern', get_post( $pattern )->post_content );
		$this->assertSame( 'RB01 menu', get_term( $menu )->name );
		$this->assertSame( array( 'One', 'Two', 'Three' ), wp_list_pluck( Menus::item_posts( $menu ), 'post_title' ), 'the trashed item is back' );
		foreach ( $before_options as $name => $raw ) {
			$this->assertSame( $raw['value'], Snapshot_Store::raw_option( $name )['value'], 'exact stored text of ' . $name );
		}
		$after_meta = get_post_meta( $page );
		foreach ( array( 'km_plain', 'km_list' ) as $key ) {
			$this->assertSame( $before_meta[ $key ], $after_meta[ $key ], 'meta ' . $key . ' as stored' );
		}
		$this->assertArrayNotHasKey( 'km_added', $after_meta, 'meta the push added is gone' );

		// sync_state back to before the push (there was no row), lock released, audit.
		foreach ( $envs as $env ) {
			$this->assertNull( Sync_State::find( $pairing, $env['uuid'] ), 'sync_state restored: ' . $env['uuid'] );
		}
		$this->assertNull( Push_Lock::get() );
		$this->assertSame( 'rolled_back', Push_Session::find( $push )['state'] );
		$this->assertSame( 1, $this->audit_count( 'rollback_started' ) );
		$this->assertSame( 1, $this->audit_count( 'rollback_done' ) );
		$this->assertSame( 0, $this->applied_without_snapshot() );
	}

	// --- RB-02 --------------------------------------------------------------------------------------

	public function test_rb02_created_items_go_to_the_trash_or_are_deleted_and_files_only_when_unreferenced() {
		$parent = $this->page( 'RB02 parent' );
		$cat    = self::factory()->category->create( array( 'name' => 'RB02 category' ) );
		$envs   = $this->new_items(
			array(
				'post' => array( $parent ),
				'term' => array( $cat ),
			)
		);

		// Two new attachments with their files.
		$files = array();
		$atts  = array();
		foreach ( array( 1 => 'rb02-free.jpg', 2 => 'rb02-shared.jpg' ) as $ref => $name ) {
			$bytes = $this->jpeg( 32, 24, 40 * $ref );
			$path  = self::YEAR . '/' . $name;
			$full  = $this->put_file( $path, $bytes );
			$id    = self::factory()->attachment->create_object( $path, 0, array( 'post_mime_type' => 'image/jpeg' ) );
			wp_update_attachment_metadata(
				$id,
				array(
					'file'  => $path,
					'sizes' => array(),
				)
			);
			$env          = $this->new_items( array( 'attachment' => array( $id ) ) )[ $id ];
			$env['media'] = array(
				array(
					'path' => $path,
					'ref'  => $ref,
				),
			);
			if ( is_file( $full ) ) {
				unlink( $full );
			}
			$atts[ $ref ]  = $env;
			$files[ $ref ] = array(
				'path'       => $path,
				'bytes'      => $bytes,
				'attachment' => $env['uuid'],
			);
		}

		$push = $this->stage( array_merge( array_values( $envs ), array_values( $atts ) ), $files );
		$this->applied( $push );
		$rows   = $this->rows( $push );
		$l_page = (int) $rows[ $envs[ $parent ]['uuid'] ]['local_object_id'];
		$l_cat  = (int) $rows[ $envs[ $cat ]['uuid'] ]['local_object_id'];
		$l_att1 = (int) $rows[ $atts[1]['uuid'] ]['local_object_id'];
		$l_att2 = (int) $rows[ $atts[2]['uuid'] ]['local_object_id'];
		$this->assertSame( 'publish', get_post_status( $l_page ) );
		$this->assertFileExists( $this->base() . '/' . $files[1]['path'] );

		// Someone on live made another media item that uses the second file.
		$other = self::factory()->attachment->create_object( $files[2]['path'], 0, array( 'post_mime_type' => 'image/jpeg' ) );

		$data = $this->preview( $push )->get_data();
		$this->assertSame( 4, $data['remove'] );
		$this->assertSame( 2, $data['files_remove'] );

		$this->rollback( $push );
		$this->assertSame( 'trash', get_post_status( $l_page ), 'a created page goes to the Trash' );
		$this->assertSame( '', Identity::get( 'post', $l_page ), 'without its UUID, so the item can be pushed again' );
		$this->assertNull( get_term( $l_cat ), 'a created term is deleted' );
		$this->assertNull( get_post( $l_att1 ), 'a created attachment is deleted' );
		$this->assertNull( get_post( $l_att2 ) );
		$this->assertFileDoesNotExist( $this->base() . '/' . $files[1]['path'], 'an unreferenced file is removed' );
		$this->assertFileExists( $this->base() . '/' . $files[2]['path'], 'a file another attachment uses stays' );
		$this->assertSame( 'attachment', get_post_type( $other ) );
		$this->assertSame( 'removed', $this->rb_state( $push, $envs[ $parent ]['uuid'] ) );
	}

	// --- RB-03 --------------------------------------------------------------------------------------

	public function test_rb03_options_set_get_their_stored_text_back_and_added_options_are_deleted() {
		Settings::save( array_merge( Settings::get(), array( 'custom_options' => array( 'km_custom_*' ) ) ) );
		Scope::flush();
		update_option( 'km_custom_set', array( 'nested' => array( 'a' => true, 'b' => null ), 'n' => 7 ) );
		update_option( 'km_custom_new', 'scratch' );
		$set    = $this->envelope(
			'option',
			'km_custom_set',
			'push',
			static function ( $data ) {
				$data['value'] = array( 'nested' => array( 'a' => false ) );
				return $data;
			}
		);
		$added  = $this->envelope( 'option', 'km_custom_new', 'create' );
		$before = Snapshot_Store::raw_option( 'km_custom_set' );
		delete_option( 'km_custom_new' );

		$push = $this->stage( array( $set, $added ) );
		$this->applied( $push );
		$rows = $this->rows( $push );
		$this->assertSame( 'option_set', $rows['option:km_custom_set']['action'] );
		$this->assertSame( 'option_added', $rows['option:km_custom_new']['action'] );
		$this->assertSame( 'scratch', get_option( 'km_custom_new' ) );

		$this->rollback( $push );
		$this->assertSame( $before['value'], Snapshot_Store::raw_option( 'km_custom_set' )['value'], 'the stored text, byte for byte' );
		$this->assertFalse( Snapshot_Store::raw_option( 'km_custom_new' )['exists'], 'the option the push added is deleted' );
		$this->assertSame( 'restored', $this->rb_state( $push, 'option:km_custom_set' ) );
		$this->assertSame( 'removed', $this->rb_state( $push, 'option:km_custom_new' ) );
	}

	// --- RB-04 --------------------------------------------------------------------------------------

	public function test_rb04_a_replaced_media_file_comes_back_byte_identical() {
		$path = self::YEAR . '/rb04.jpg';
		$old  = $this->jpeg( 32, 24, 10 );
		$new  = $this->jpeg( 32, 24, 250 );
		$this->put_file( $path, $old );
		$id = self::factory()->attachment->create_object( $path, 0, array( 'post_mime_type' => 'image/jpeg' ) );
		wp_update_attachment_metadata(
			$id,
			array(
				'file'  => $path,
				'sizes' => array(),
			)
		);
		$env          = $this->envelope(
			'attachment',
			$id,
			'push',
			static function ( $data ) use ( $new ) {
				$data['files'][0]['size']   = strlen( $new );
				$data['files'][0]['sha256'] = hash( 'sha256', $new );
				$data['post_title']         = 'RB04 (staging)';
				return $data;
			}
		);
		$env['media'] = array(
			array(
				'path' => $path,
				'ref'  => 1,
			),
		);
		$push         = $this->stage(
			array( $env ),
			array(
				1 => array(
					'path'       => $path,
					'bytes'      => $new,
					'attachment' => $env['uuid'],
				),
			)
		);
		$this->applied( $push );
		$this->assertSame( hash( 'sha256', $new ), hash_file( 'sha256', $this->base() . '/' . $path ) );

		$data = $this->preview( $push )->get_data();
		$this->assertSame( 1, $data['files_restore'] );
		$this->rollback( $push );
		$this->assertSame( hash( 'sha256', $old ), hash_file( 'sha256', $this->base() . '/' . $path ), 'byte-identical restore (SHA-256)' );
		$this->assertSame( 1, $this->assert_restored_to_pre_hash( $push ) );
		global $wpdb;
		$file = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE push_id = %d AND object_type = 'file'", Tables::table_name( 'push_items' ), $this->session_id( $push ) ), ARRAY_A );
		$this->assertNotNull( $file['restored_gmt'] );
	}

	// --- RB-05 --------------------------------------------------------------------------------------

	public function test_rb05_a_live_edit_after_the_push_is_a_conflict_skipped_by_default_and_restored_when_ticked() {
		$edited = $this->page( 'RB05 edited later' );
		$plain  = $this->page( 'RB05 plain' );
		$e1     = $this->changed_page( $edited );
		$e2     = $this->changed_page( $plain );
		$push   = $this->stage( array( $e1, $e2 ) );
		$this->applied( $push );

		wp_update_post(
			array(
				'ID'         => $edited,
				'post_title' => 'Edited on live after the push',
			)
		);
		$data = $this->preview( $push )->get_data();
		$this->assertSame( 1, $data['conflict_count'] );
		$this->assertSame( $e1['uuid'], $data['conflicts'][0]['uuid'] );
		$this->assertSame( 'edited_on_live', $data['conflicts'][0]['reason'] );

		$this->rollback( $push );
		$this->assertSame( 'Edited on live after the push', get_post( $edited )->post_title, 'skipped by default: the live edit stays' );
		$this->assertSame( 'RB05 plain', get_post( $plain )->post_title );
		$this->assertSame( 'conflict', $this->rb_state( $push, $e1['uuid'] ) );

		// The same situation, the conflict ticked: restored.
		$third = $this->page( 'RB05 ticked' );
		$e3    = $this->changed_page( $third );
		$push2 = $this->stage( array( $e3 ) );
		$this->applied( $push2 );
		wp_update_post(
			array(
				'ID'         => $third,
				'post_title' => 'Edited on live, then ticked',
			)
		);
		$this->rollback( $push2, array( $e3['uuid'] ) );
		$this->assertSame( 'RB05 ticked', get_post( $third )->post_title, 'restored when ticked' );
		$this->assertSame( 1, $this->assert_restored_to_pre_hash( $push2 ) );
	}

	// --- RB-06 --------------------------------------------------------------------------------------

	public function test_rb06_a_rollback_that_stops_mid_way_resumes_and_restores_nothing_twice() {
		$pages = array();
		$envs  = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$pages[ $i ] = $this->page( 'RB06 page ' . $i );
			$envs[ $i ]  = $this->changed_page( $pages[ $i ] );
		}
		$push = $this->stage( $envs );
		$this->applied( $push );

		add_filter(
			'softglaze_sp_rollback_step_items',
			static function () {
				return 1;
			}
		);
		$writes   = array();
		$callback = $this->count_writes( $writes );

		// Step 1 starts the rollback and restores one item.
		$this->assertSame( 200, $this->rollback_step( $push )->get_status() );
		$saved = $this->raw_cursor( $push );
		// Step 2 restores the next item, then the process dies before it saves its cursor.
		$this->assertSame( 200, $this->rollback_step( $push )->get_status() );
		$this->crash( $push, $saved, 'rolling_back' );

		$view = Push_Session::present( Push_Session::find( $push ) );
		$this->assertTrue( $view['interrupted'], 'a rollback without a heartbeat shows as interrupted' );

		// A third item was being restored when it died: marked restoring, nothing written yet.
		$this->set_row( $push, $envs[2]['uuid'], array( 'transfer' => wp_json_encode( array( 'rb' => array( 's' => 'restoring', 'r' => '' ) ) ) ) );

		// Resume: the same route continues it.
		$this->rollback( $push );
		remove_action( 'post_updated', $callback );

		foreach ( $pages as $i => $id ) {
			$this->assertSame( 1, isset( $writes[ $id ] ) ? $writes[ $id ] : 0, 'page ' . $i . ' restored exactly once' );
			$this->assertSame( 'RB06 page ' . $i, get_post( $id )->post_title );
		}
		$this->assertSame( 5, $this->assert_restored_to_pre_hash( $push ) );
		$this->assertNull( Push_Lock::get(), 'lock released' );
	}

	/**
	 * Where the process dies inside the removal of a created page (a hook of core's trash or of the
	 * UUID's removal).
	 */
	public function rb06_removal_moments() {
		return array(
			'before core trashes it'                   => array( 'pre_trash_post' ),
			'after the trash, before its UUID is gone' => array( 'trashed_post' ),
			'after its UUID is gone'                   => array( 'deleted_post_meta' ),
		);
	}

	/**
	 * Found in a two-site end-to-end run (RB-06): the rollback was killed while it moved a
	 * page the push had created to the Trash. The page stayed published without its UUID, and the
	 * resumed rollback refused it as created_object_changed and finished rolled_back with that page
	 * still on live. Wherever a step dies inside the removal of a created page, the resumed rollback
	 * removes it.
	 *
	 * @dataProvider rb06_removal_moments
	 *
	 * @param string $hook The hook at which the process dies.
	 */
	public function test_rb06_a_rollback_that_dies_while_removing_a_created_page_removes_it_when_resumed( $hook ) {
		add_filter(
			'softglaze_sp_rollback_step_items',
			static function () {
				return 1;
			}
		);
		$first  = $this->page( 'RB06 created first ' . $hook );
		$second = $this->page( 'RB06 created second ' . $hook );
		$envs   = $this->new_items( array( 'post' => array( $first, $second ) ) );
		$push   = $this->stage( array_values( $envs ) );
		$this->applied( $push );

		// Step 1 starts the rollback and removes one of the two pages.
		$this->assertSame( 200, $this->rollback_step( $push )->get_status(), $hook );
		$saved = $this->raw_cursor( $push );
		$dying = null;
		foreach ( $this->rows( $push ) as $uuid => $row ) {
			if ( 'removed' !== $this->rb_state( $push, $uuid ) ) {
				$dying = $row;
			}
		}
		$this->assertNotNull( $dying, $hook . ': one page left for step 2' );
		$local = (int) $dying['local_object_id'];
		$this->assertSame( 'publish', get_post_status( $local ), $hook );

		// Step 2 marks the other page restoring, as Rollback_Runner::item() does, and the process
		// dies at $hook inside the removal (the exception stands in for the kill).
		$transfer       = is_array( $dying['transfer'] ) ? $dying['transfer'] : array();
		$transfer['rb'] = array(
			's' => 'restoring',
			'r' => '',
		);
		$this->set_row( $push, $dying['uuid'], array( 'transfer' => wp_json_encode( $transfer ) ) );
		$die = static function ( ...$hook_args ) use ( $hook, $local ) {
			if ( 'deleted_post_meta' === $hook && ( (int) $hook_args[1] !== $local || Identity::META_KEY !== $hook_args[2] ) ) {
				return;
			}
			throw new RuntimeException( 'killed' );
		};
		add_filter( $hook, $die, 10, 4 );
		$killed = false;
		try {
			Raw_Restore::remove( Rollback_Runner::row( (int) $dying['id'] ) );
		} catch ( RuntimeException $e ) {
			$killed = 'killed' === $e->getMessage();
		}
		remove_filter( $hook, $die, 10 );
		$this->assertTrue( $killed, $hook . ': the removal reached ' . $hook );
		$this->crash( $push, $saved, 'rolling_back' );

		// Resume: the same route continues it.
		$this->rollback( $push );
		$this->assertSame( 'removed', $this->rb_state( $push, $dying['uuid'] ), $hook . ': the resumed rollback removed the page; ' . wp_json_encode( $this->rows( $push )[ $dying['uuid'] ]['transfer']['rb'] ) . ', post status ' . get_post_status( $local ) );
		$this->assertSame( 'trash', get_post_status( $local ), $hook . ': the created page is in the Trash' );
		$this->assertSame( '', Identity::get( 'post', $local ), $hook . ': without its UUID' );
		$this->assertSame( 'rolled_back', Push_Session::find( $push )['state'], $hook );
		$this->assertNull( Push_Lock::get(), $hook . ': lock released' );
	}

	// --- RB-08 --------------------------------------------------------------------------------------

	public function test_rb08_a_rolled_back_push_cannot_be_rolled_back_again_and_prune_keeps_the_newest_snapshots() {
		global $wpdb;
		$pushes = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$id         = $this->page( 'RB08 page ' . $i );
			$pushes[ $i ] = $this->stage( array( $this->changed_page( $id ) ) );
			$this->applied( $pushes[ $i ] );
		}
		$this->rollback( $pushes[4] );
		$again = $this->rollback_step( $pushes[4] );
		$this->assertSame( 409, $again->get_status() );
		$this->assertSame( 'already_rolled_back', $again->get_data()['code'] );

		// A snapshot folder for the oldest push, and an orphaned temporary folder.
		$old_session = Push_Session::find( $pushes[0] );
		$folder      = Snapshot_Store::folder( $old_session, true );
		$this->assertIsString( $folder );
		file_put_contents( $folder . '/1.bin', 'snapshot bytes' );
		$tmp = $this->plugin_dir() . '/tmp/' . str_repeat( 'a', 32 );
		wp_mkdir_p( $tmp );
		touch( $tmp, time() - 2 * DAY_IN_SECONDS );

		// Every push finished 40 days ago: past the 30-day retention.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET finished_gmt = %s WHERE side = 'destination'", Tables::table_name( 'pushes' ), gmdate( 'Y-m-d H:i:s', time() - 40 * DAY_IN_SECONDS ) ) );
		$rows_before = array();
		foreach ( $pushes as $i => $push ) {
			$rows_before[ $i ] = count( $this->rows( $push ) );
		}

		$out = Push_Session::prune_retention( 30 );
		$this->assertSame( 2, $out['sessions'], 'the two oldest pushes are pruned' );
		foreach ( $pushes as $i => $push ) {
			$rows = count( $this->rows( $push ) );
			if ( $i < 2 ) {
				$this->assertSame( 0, $rows, 'push ' . $i . ' rows pruned' );
				$this->assertTrue( Push_Session::present( Push_Session::find( $push ) )['pruned'] );
			} else {
				$this->assertSame( $rows_before[ $i ], $rows, 'push ' . $i . ' kept (one of the newest three)' );
			}
		}
		$this->assertDirectoryDoesNotExist( $folder, 'the pruned push\'s snapshot folder is gone' );
		$this->assertSame( 'snapshots_pruned', $this->rollback_step( $pushes[0] )->get_data()['code'] );

		// The daily prune: the newest push always stays, no orphaned temporary folder remains.
		$out = SoftGlaze\SafePush\Cron::daily_prune();
		$this->assertSame( 0, $out['retention']['sessions'], 'the newest pushes are never pruned' );
		$this->assertGreaterThanOrEqual( 1, $out['temp'] );
		$this->assertDirectoryDoesNotExist( $tmp );
		$this->assertSame( array(), glob( $this->plugin_dir() . '/tmp/*', GLOB_ONLYDIR ) ?: array() );
		$this->assertSame( $rows_before[4], count( $this->rows( $pushes[4] ) ), 'the newest push keeps its snapshot' );
	}

	// --- preview: filtered pre-images ----------------------------------------------------------------

	public function test_the_preview_lists_pre_images_the_admins_own_filter_would_change() {
		global $wpdb;
		$id = $this->page( 'RB filtered' );
		$wpdb->update( $wpdb->posts, array( 'post_content' => '<p>Old</p><script>live();</script>' ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		$push = $this->stage( array( $this->changed_page( $id ) ) );
		$this->applied( $push );

		$data = $this->preview( $push )->get_data();
		$this->assertSame( array(), $data['filtered'], 'an admin with unfiltered_html restores it as it was' );

		$deny = static function ( $caps, $cap ) {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		$data = $this->preview( $push )->get_data();
		remove_filter( 'map_meta_cap', $deny, 10 );
		$this->assertCount( 1, $data['filtered'] );
		$this->assertSame( array( 'post_content' ), $data['filtered'][0]['fields'] );
	}

	// --- S-77 ---------------------------------------------------------------------------------------

	/**
	 * @group security
	 */
	public function test_s77_no_remote_rollback_route_and_admin_rollback_needs_capability_nonce_and_domain() {
		$remote = array();
		foreach ( array_keys( rest_get_server()->get_routes() ) as $route ) {
			if ( 0 === strpos( $route, '/keepmerge/v1/remote' ) ) {
				$remote[] = $route;
			}
		}
		$this->assertCount( 15, $remote, 'R1–R15 only: ' . implode( ', ', $remote ) );
		foreach ( $remote as $route ) {
			$this->assertDoesNotMatchRegularExpression( '/rollback|resume|release|skip|lock|held|continue/', $route );
		}

		$id   = $this->page( 'S77 page' );
		$push = $this->stage( array( $this->changed_page( $id ) ) );
		$this->applied( $push );
		$sid   = $this->session_id( $push );
		$route = '/keepmerge/v1/admin/pushes/' . $sid . '/rollback';
		$body  = array( 'confirm' => self::HOST );

		// Anonymous.
		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );

		// Editor with a valid nonce.
		$this->assertSame( 403, $this->admin( 'POST', $route, $body, self::$editor )->get_status() );
		$this->assertSame( 403, $this->admin( 'GET', '/keepmerge/v1/admin/pushes/' . $sid . '/rollback-preview', array(), self::$editor )->get_status() );

		// Administrator without the nonce, and with a wrong one.
		wp_set_current_user( self::$admin );
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
		$request->set_header( 'X-WP-Nonce', 'not-a-nonce' );
		$this->assertSame( 'rest_cookie_invalid_nonce', rest_get_server()->dispatch( $request )->get_data()['code'] );
		wp_set_current_user( 0 );

		// Administrator, nonce, wrong typed domain: refused, nothing written.
		$wrong = $this->rollback_step( $push, array(), 'staging.example.com' );
		$this->assertSame( 400, $wrong->get_status() );
		$this->assertSame( 'confirm_mismatch', $wrong->get_data()['code'] );
		$this->assertSame( 'applied', Push_Session::find( $push )['state'] );
		$this->assertSame( 0, $this->audit_count( 'rollback_started' ) );
		foreach ( array( 'resume', 'skip-item' ) as $what ) {
			$this->assertSame( 'confirm_mismatch', $this->admin( 'POST', '/keepmerge/v1/admin/pushes/' . $sid . '/' . $what, array( 'confirm' => 'x' ) )->get_data()['code'] );
		}
		$this->assertSame( 'confirm_mismatch', $this->admin( 'POST', '/keepmerge/v1/admin/pushes/lock/release', array( 'confirm' => 'x' ) )->get_data()['code'] );

		// All three present: the rollback runs.
		$this->rollback( $push );
		$this->assertSame( 'S77 page', get_post( $id )->post_title );
	}

	/**
	 * Found in a two-site end-to-end run (F-01): on a block theme WooCommerce
	 * stores its block template "archive-product" in the Shop page's _wp_page_template. Core's
	 * classic page template list does not name it, and wp_update_post() merges the stored value back
	 * into the update and refuses the whole write with invalid_page_template, so every push that
	 * touched the Shop page stopped failed_partial there, and a rollback could not restore it.
	 */
	public function test_a_page_whose_stored_template_core_does_not_list_is_updated_and_rolled_back() {
		$shop = $this->page( 'Shop', '', array( '_wp_page_template' => 'archive-product' ) );

		// Control: core alone refuses any update of this page.
		$control = wp_update_post(
			array(
				'ID'         => $shop,
				'post_title' => 'Shop',
			),
			true
		);
		$this->assertWPError( $control );
		$this->assertSame( 'invalid_page_template', $control->get_error_code() );

		$push    = $this->stage( array( $this->changed_page( $shop ) ) );
		$answers = $this->apply( $push );
		$this->assertSame( 'applied', end( $answers )['state'], wp_json_encode( end( $answers ) ) );
		$this->assertSame( 'Shop (staging)', get_post( $shop )->post_title );
		$this->assertSame( 'archive-product', get_post_meta( $shop, '_wp_page_template', true ), 'the stored template is left as it was' );

		$this->rollback( $push );
		$this->assertSame( 'Shop', get_post( $shop )->post_title );
		$this->assertSame( 'archive-product', get_post_meta( $shop, '_wp_page_template', true ) );
		$this->assert_restored_to_pre_hash( $push );
	}
}
