<?php
/**
 * Sync exporters: the normal form of each kind of item.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Sync\Export_Context;
use SoftGlaze\SafePush\Sync\Items;
use SoftGlaze\SafePush\Sync\Scope;

/**
 * @group sync
 * @group exporters
 */
class ExportersTest extends WP_UnitTestCase {

	public function tear_down() {
		Scope::flush();
		parent::tear_down();
	}

	private function export( $kind, $ref ) {
		return Items::export( $kind, $ref, Export_Context::destination() );
	}

	public function test_post_fields_terms_featured_image_and_meta() {
		$cat   = self::factory()->category->create();
		$image = self::factory()->attachment->create_object( 'p.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$page  = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_title'   => 'Exported',
				'post_content' => 'See ' . home_url( '/about/' ),
			)
		);
		wp_set_post_categories( $page, array( $cat ) );
		set_post_thumbnail( $page, $image );
		update_post_meta( $page, 'custom_field', array( 'a' => 1.5 ) );
		update_post_meta( $page, '_edit_lock', '1:1' );
		update_post_meta( $page, 'my_api_key', 'never' );
		update_post_meta( $page, '_wp_page_template', 'default' );

		$item = $this->export( 'post', $page );
		$data = $item['data'];

		$this->assertSame( 'post', $item['kind'] );
		$this->assertSame( 'Exported', $data['post_title'] );
		$this->assertSame( 'See {{sgsp:home}}/about/', $data['post_content'] );
		$this->assertSame( $image, $data['featured_image'] );
		$this->assertSame( array( $cat ), $data['terms']['category'] );
		$this->assertSame( 'default', $data['page_template'] );
		$this->assertSame( array( 'a' => array( '__sgsp' => 'float', 'v' => '1.5' ) ), $data['meta']['custom_field'][0] );
		foreach ( array( '_edit_lock', 'my_api_key', '_thumbnail_id', '_wp_page_template' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $data['meta'], $key );
		}
		$this->assertArrayNotHasKey( 'post_modified', $data );
		$this->assertArrayNotHasKey( 'guid', $data );
		$this->assertSame( 64, strlen( $item['hash'] ) );
	}

	public function test_denied_and_skipped_types_are_never_exported() {
		$revision = self::factory()->post->create( array( 'post_type' => 'revision' ) );
		$this->assertNull( $this->export( 'post', $revision ) );

		register_post_type(
			'shop_coupon',
			array(
				'public'  => true,
				'show_ui' => true,
			)
		);
		Scope::flush();
		$coupon = self::factory()->post->create( array( 'post_type' => 'shop_coupon' ) );
		$this->assertNull( $this->export( 'post', $coupon ) );
		$this->assertNotContains( 'shop_coupon', Scope::content_post_types() );
		unregister_post_type( 'shop_coupon' );

		$draft = self::factory()->post->create( array( 'post_status' => 'trash' ) );
		$this->assertNull( $this->export( 'post', $draft ) );

		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->assertNotNull( $this->export( 'post', $page ) );
		Settings::save( array_merge( Settings::get(), array( 'skip_post_types' => array( 'page' ) ) ) );
		Scope::flush();
		$this->assertNull( $this->export( 'post', $page ) );
	}

	public function test_site_editor_items() {
		$template = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_name'    => 'page-landing',
				'post_title'   => 'Landing',
				'post_content' => '<!-- wp:template-part {"slug":"header"} /-->',
				'post_status'  => 'publish',
			)
		);
		wp_set_object_terms( $template, 'twentytwentyfive', 'wp_theme' );
		update_post_meta( $template, 'origin', 'theme' );

		$part = self::factory()->post->create(
			array(
				'post_type'   => 'wp_template_part',
				'post_name'   => 'header',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $part, 'twentytwentyfive', 'wp_theme' );
		wp_set_object_terms( $part, 'header', 'wp_template_part_area' );

		$styles = self::factory()->post->create(
			array(
				'post_type'    => 'wp_global_styles',
				'post_name'    => 'wp-global-styles-twentytwentyfive',
				'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true}',
				'post_status'  => 'publish',
			)
		);
		wp_set_object_terms( $styles, 'twentytwentyfive', 'wp_theme' );

		$category = self::factory()->term->create( array( 'taxonomy' => 'wp_pattern_category' ) );
		$pattern  = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'Unsynced',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $pattern, 'wp_pattern_sync_status', 'unsynced' );
		wp_set_object_terms( $pattern, array( $category ), 'wp_pattern_category' );

		$item = $this->export( 'template', $template );
		$this->assertSame( 'template', $item['kind'] );
		$this->assertSame( 'twentytwentyfive', $item['data']['theme'] );
		$this->assertSame( 'theme', $item['data']['origin'] );
		$this->assertSame( 'page-landing', $item['data']['post_name'] );
		$this->assertSame( 'twentytwentyfive', $item['stylesheet'] );
		$this->assertContains( 'core/template-part', $item['block_types'] );

		$item = $this->export( 'template_part', $part );
		$this->assertSame( 'header', $item['data']['area'] );

		$item = $this->export( 'global_styles', $styles );
		$this->assertSame( 'global_styles', $item['kind'] );
		$this->assertSame( 'twentytwentyfive', $item['data']['theme'] );

		$item = $this->export( 'pattern', $pattern );
		$this->assertSame( 'unsynced', $item['data']['sync_status'] );
		$this->assertSame( array( $category ), $item['data']['terms']['wp_pattern_category'] );
	}

	public function test_menu_unit_with_items_in_order() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$menu = wp_create_nav_menu( 'Unit menu' );
		$one  = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-object-id' => $page,
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
				'menu-item-position'  => 1,
			)
		);
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'     => 'Partner',
				'menu-item-url'       => 'https://partner.example.net/',
				'menu-item-type'      => 'custom',
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => $one,
				'menu-item-position'  => 2,
			)
		);

		$item = $this->export( 'menu', $menu );
		$this->assertSame( 'menu', $item['kind'] );
		$this->assertCount( 2, $item['data']['items'] );
		$this->assertSame( 'post_type', $item['data']['items'][0]['type'] );
		$this->assertSame( $page, $item['data']['items'][0]['object_id'] );
		$this->assertSame( 'https://partner.example.net/', $item['data']['items'][1]['url'] );
		$this->assertSame( $one, $item['data']['items'][1]['parent'] );
		$paths = array_column( $item['refs'], 'path' );
		$this->assertContains( 'items/0/object_id', $paths );
		$this->assertContains( 'items/1/parent', $paths );
	}

	public function test_options_on_the_allowlist_only_and_theme_mods_without_custom_css() {
		$this->assertNotNull( $this->export( 'option', 'blogname' ) );
		$this->assertNull( $this->export( 'option', 'admin_email' ) );
		$this->assertNull( $this->export( 'option', 'siteurl' ) );
		$this->assertNull( $this->export( 'option', 'blog_public' ) );

		$name = 'theme_mods_' . get_stylesheet();
		update_option(
			$name,
			array(
				'custom_css_post_id' => 99,
				'custom_logo'        => 5,
			)
		);
		Scope::flush();
		$item = $this->export( 'option', $name );
		$this->assertArrayNotHasKey( 'custom_css_post_id', $item['data']['value'] );
		$this->assertSame( 5, $item['data']['value']['custom_logo'] );
		$this->assertSame( 'option:' . $name, $item['uuid'] );

		Settings::save( array_merge( Settings::get(), array( 'custom_options' => array( 'acme_*' ) ) ) );
		update_option( 'acme_color', 'blue' );
		update_option( 'acme_api_key', 'never' );
		Scope::flush();
		$this->assertContains( 'acme_color', Scope::option_names() );
		$this->assertNotContains( 'acme_api_key', Scope::option_names() );
		$this->assertNull( $this->export( 'option', 'acme_api_key' ) );
	}

	public function test_widgets_unit() {
		Settings::save( array_merge( Settings::get(), array( 'option_groups' => array_merge( Settings::get()['option_groups'], array( 'widgets' => true ) ) ) ) );
		update_option( 'widget_text', array( 2 => array( 'text' => 'Hello' ), '_multiwidget' => 1 ) );
		Scope::flush();
		$item = $this->export( 'widgets', 'widgets' );
		$this->assertSame( 'unit:widgets', $item['uuid'] );
		$this->assertSame( 'Hello', $item['data']['widgets']['widget_text'][2]['text'] );
		$this->assertArrayHasKey( 'sidebars_widgets', $item['data'] );
	}

	public function test_attachment_file_list_with_sizes_and_sha256() {
		$upload = wp_upload_dir( null, false );
		wp_mkdir_p( $upload['basedir'] . '/2026/10' );
		file_put_contents( $upload['basedir'] . '/2026/10/km-a.jpg', 'original-bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $upload['basedir'] . '/2026/10/km-a-150x150.jpg', 'thumb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$id = self::factory()->attachment->create_object( '2026/10/km-a.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		wp_update_attachment_metadata(
			$id,
			array(
				'file'  => '2026/10/km-a.jpg',
				'sizes' => array(
					'thumbnail' => array( 'file' => 'km-a-150x150.jpg' ),
					'evil'      => array( 'file' => '../../x.php' ),
				),
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', 'Alt text' );

		$item = $this->export( 'attachment', $id );
		$this->assertSame( 'Alt text', $item['data']['alt'] );
		$this->assertSame(
			array(
				array(
					'path'   => '2026/10/km-a-150x150.jpg',
					'size'   => 5,
					'sha256' => hash( 'sha256', 'thumb' ),
				),
				array(
					'path'   => '2026/10/km-a.jpg',
					'size'   => 14,
					'sha256' => hash( 'sha256', 'original-bytes' ),
				),
			),
			$item['data']['files']
		);

		$protected = self::factory()->attachment->create_object( 'woocommerce_uploads/x.zip', 0, array( 'post_mime_type' => 'application/zip' ) );
		$this->assertNull( $this->export( 'attachment', $protected ) );
	}

	public function test_php_object_in_meta_makes_the_item_unsupported() {
		$page = self::factory()->post->create();
		update_post_meta( $page, 'stored_object', new ArrayObject( array( 1 ) ) );
		$item = $this->export( 'post', $page );
		$this->assertTrue( $item['unsupported'] );
		$this->assertSame( array( 'unsupported_object:ArrayObject' ), $item['problems'] );
	}
}
