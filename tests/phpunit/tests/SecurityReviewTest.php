<?php
/**
 * Findings of an independent security review (KM-xx) that need a real apply on live: signed
 * item-batch and apply dispatch
 * as the receiver, through the shared apply harness. The stager-level halves are in ItemStagerTest
 * (test_km01_*, test_km02_*, test_km05_*), the throttle in RemotePermissionTest and
 * NonceThrottleTest (test_km04_*).
 *
 * @package SoftGlaze\SafePush\Tests
 */

require_once __DIR__ . '/class-keepmerge-apply-harness.php';

use SoftGlaze\SafePush\Sync\Exporters\Menus;
use SoftGlaze\SafePush\Sync\Identity;

/**
 * @group security
 * @group review
 */
class SecurityReviewTest extends Keepmerge_Apply_Harness {

	private function done( $push ) {
		$answers = $this->apply( $push );
		$last    = end( $answers );
		$this->assertSame( 'applied', $last['state'], wp_json_encode( $this->rows( $push ) ) );
		return $last;
	}

	/**
	 * KM-01: a compromised source names other files in _wp_attachment_backup_sizes of an attachment
	 * in the uploads root; deleting that attachment later must not delete them.
	 */
	public function test_km01_deleting_a_pushed_attachment_never_deletes_files_named_in_its_backup_sizes() {
		$bytes = $this->jpeg( 32, 24, 120 );
		$name  = 'km-root-' . wp_generate_password( 6, false ) . '.jpg';
		$full  = $this->put_file( $name, $bytes );
		$id    = self::factory()->attachment->create_object(
			$name,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'KM root',
			)
		);
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => 32,
				'height' => 24,
				'file'   => $name,
				'sizes'  => array(),
			)
		);
		$env = $this->new_items( array( 'attachment' => array( $id ) ) )[ $id ];
		if ( is_file( $full ) ) {
			unlink( $full );
		}

		$keep                                         = $this->put_file( 'km-protected/keep.txt', 'protected bytes' );
		$env['data']['meta']['_wp_attachment_backup_sizes'] = array(
			array(
				'full-orig' => array( 'file' => 'km-protected/keep.txt' ),
			),
		);
		$env['media'] = array(
			array(
				'path' => $name,
				'ref'  => 1,
			),
		);
		$push         = $this->stage(
			array( $env ),
			array(
				1 => array(
					'path'       => $name,
					'bytes'      => $bytes,
					'attachment' => $env['uuid'],
				),
			)
		);
		$this->done( $push );

		$live = (int) $this->rows( $push )[ $env['uuid'] ]['local_object_id'];
		$this->assertSame( 'attachment', get_post_type( $live ) );
		$this->assertSame( '', get_post_meta( $live, '_wp_attachment_backup_sizes', true ), 'backup sizes never written by a push' );

		wp_delete_attachment( $live, true );
		$this->assertFileExists( $keep, 'a file named in received backup sizes survives the deletion' );
		$this->assertSame( 'protected bytes', file_get_contents( $keep ) );
		$this->remove_tree( $this->base() . '/km-protected' );
	}

	/**
	 * KM-03: an item pushed before and since trashed on live (out of live's scope) comes back as
	 * "recreate": the allocate pass must not adopt and overwrite the trashed object.
	 */
	public function test_km03_the_allocate_pass_never_adopts_an_object_that_had_the_uuid_before_the_push() {
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'KM03 kept in the trash',
				'post_status' => 'publish',
			)
		);
		$env  = $this->envelope(
			'post',
			$page,
			'recreate',
			static function ( $data ) {
				$data['post_title'] = 'KM03 from staging';
				return $data;
			}
		);
		$uuid = Identity::get( 'post', $page );
		$this->assertSame( $env['uuid'], $uuid );
		wp_trash_post( $page );

		$push = $this->stage( array( $env ) );
		$this->done( $push );

		$row = $this->rows( $push )[ $env['uuid'] ];
		$this->assertSame( 'skipped', $row['status'], wp_json_encode( $row ) );
		$this->assertSame( 'target_exists', $row['error'] );
		$this->assertSame( 'trash', get_post_status( $page ), 'the trashed page stays in the trash' );
		$this->assertSame( 'KM03 kept in the trash', get_post( $page )->post_title, 'and keeps its content' );
		$this->assertSame( array( $page ), Identity::find( 'post', $env['uuid'] ), 'no second object with the UUID' );
	}

	/**
	 * KM-07: menu item URL, classes and XFN go through the sanitizers core's
	 * wp_update_nav_menu_item() applies (the target is one of two values already at staging).
	 */
	public function test_km07_menu_item_fields_are_sanitized_as_core_does() {
		$menu = wp_create_nav_menu( 'KM07 menu' );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Custom',
				'menu-item-url'    => 'https://example.net/a',
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			)
		);
		$ids = array_map(
			static function ( $post ) {
				return (int) $post->ID;
			},
			Menus::item_posts( $menu )
		);
		$env = $this->envelope(
			'menu',
			$menu,
			'push',
			static function ( $data ) {
				$data['items'][0]['url']     = 'https://example.net/b"><b x=1>';
				$data['items'][0]['classes'] = array( 'menu-x', 'a"b c' );
				$data['items'][0]['xfn']     = 'friend" met';
				return $data;
			},
			array( 'item_ids' => $ids )
		);
		$push = $this->stage( array( $env ) );
		$this->done( $push );

		$items = Menus::item_posts( $menu );
		$this->assertCount( 1, $items );
		$item = (int) $items[0]->ID;
		$this->assertSame( 'https://example.net/bb%20x=1', get_post_meta( $item, '_menu_item_url', true ) );
		$this->assertSame( array( 'menu-x', 'abc' ), get_post_meta( $item, '_menu_item_classes', true ) );
		$this->assertSame( 'friend met', get_post_meta( $item, '_menu_item_xfn', true ) );
	}
}
