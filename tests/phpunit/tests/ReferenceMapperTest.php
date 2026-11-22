<?php
/**
 * Sync\Reference_Mapper: the reference registry on fixture content.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Sync\Reference_Mapper;

/**
 * @group sync
 * @group mapper
 */
class ReferenceMapperTest extends WP_UnitTestCase {

	private static $img1;
	private static $img2;
	private static $page;
	private static $page2;
	private static $pattern;
	private static $nav;
	private static $cat;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$img1    = $factory->attachment->create_object( 'one.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		self::$img2    = $factory->attachment->create_object( 'two.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		self::$page    = $factory->post->create( array( 'post_type' => 'page' ) );
		self::$page2   = $factory->post->create( array( 'post_type' => 'page' ) );
		self::$pattern = $factory->post->create( array( 'post_type' => 'wp_block' ) );
		self::$nav     = $factory->post->create( array( 'post_type' => 'wp_navigation' ) );
		self::$cat     = $factory->category->create( array( 'name' => 'Ref cat' ) );
	}

	public function tear_down() {
		remove_all_filters( 'softglaze_sp_reference_rules' );
		Reference_Mapper::flush_rules();
		parent::tear_down();
	}

	private function mapper( $canon = true ) {
		return new Reference_Mapper(
			static function ( $space, $id ) {
				return 'uuid-' . $space . '-' . $id;
			},
			$canon ? static function ( $space, $id ) {
				return 'C' . $id;
			} : null,
			static function ( $url ) {
				return $url;
			}
		);
	}

	/**
	 * path => "kind:id" for every sidecar entry.
	 */
	private static function refs( array $scan ) {
		$out = array();
		foreach ( $scan['refs'] as $ref ) {
			$out[ $ref['path'] ] = $ref['kind'] . ':' . $ref['id'];
		}
		return $out;
	}

	private function fixture() {
		$a1   = self::$img1;
		$a2   = self::$img2;
		$page = self::$page;
		$pg2  = self::$page2;
		$cat  = self::$cat;

		return implode(
			"\n",
			array(
				'<!-- wp:image {"id":' . $a1 . ',"sizeSlug":"large"} --><figure class="wp-block-image"><img src="x.jpg" class="wp-image-' . $a1 . '"/></figure><!-- /wp:image -->',
				'<!-- wp:gallery {"ids":[' . $a1 . ',' . $a2 . ']} --><figure class="wp-block-gallery"><!-- wp:image {"id":' . $a2 . '} --><figure class="wp-block-image"><img class="wp-image-' . $a2 . '"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->',
				'<!-- wp:cover {"id":' . $a1 . '} --><div class="wp-block-cover"></div><!-- /wp:cover -->',
				'<!-- wp:media-text {"mediaId":' . $a2 . '} --><div class="wp-block-media-text"></div><!-- /wp:media-text -->',
				'<!-- wp:file {"id":' . $a1 . '} --><div class="wp-block-file"></div><!-- /wp:file -->',
				'<!-- wp:block {"ref":' . self::$pattern . '} /-->',
				'<!-- wp:navigation {"ref":' . self::$nav . '} /-->',
				'<!-- wp:navigation-link {"id":' . $page . ',"kind":"post-type","type":"page"} /-->',
				'<!-- wp:navigation-link {"id":' . $cat . ',"kind":"taxonomy","type":"category"} /-->',
				'<!-- wp:navigation-link {"id":5,"kind":"custom"} /-->',
				'<!-- wp:page-list {"parentPageID":' . $page . '} /-->',
				'<!-- wp:latest-posts {"categories":[{"id":' . $cat . ',"value":"Ref cat"}]} /-->',
				'<!-- wp:woocommerce/single-product {"productId":' . $page . '} /-->',
				'<!-- wp:woocommerce/handpicked-products {"products":[' . $page . ',' . $pg2 . ']} /-->',
				'<!-- wp:paragraph --><p>[gallery ids="' . $a1 . ',' . $a2 . '"] [caption id="attachment_' . $a1 . '"]Cap[/caption] [product id="' . $page . '"] [products ids="' . $page . ',' . $pg2 . '"] [add_to_cart id="' . $page . '"] [product_page id="' . $pg2 . '"]</p><!-- /wp:paragraph -->',
				'<!-- wp:image {"id":987654} /-->',
			)
		);
	}

	public function test_every_block_class_and_shortcode_of_the_registry() {
		$scan = $this->mapper()->scan( 'post', array( 'post_content' => $this->fixture() ) );
		$refs = self::refs( $scan );
		$a1   = self::$img1;
		$a2   = self::$img2;

		$expected = array(
			'post_content/blocks/0/attrs/id'                        => 'attachment:' . $a1,
			'post_content/blocks/0/html/0/wp-image/0'               => 'attachment:' . $a1,
			'post_content/blocks/2/attrs/ids/0'                     => 'attachment:' . $a1,
			'post_content/blocks/2/attrs/ids/1'                     => 'attachment:' . $a2,
			'post_content/blocks/2.0/attrs/id'                      => 'attachment:' . $a2,
			'post_content/blocks/2.0/html/0/wp-image/0'             => 'attachment:' . $a2,
			'post_content/blocks/4/attrs/id'                        => 'attachment:' . $a1,
			'post_content/blocks/6/attrs/mediaId'                   => 'attachment:' . $a2,
			'post_content/blocks/8/attrs/id'                        => 'attachment:' . $a1,
			'post_content/blocks/10/attrs/ref'                      => 'pattern:' . self::$pattern,
			'post_content/blocks/12/attrs/ref'                      => 'navigation:' . self::$nav,
			'post_content/blocks/14/attrs/id'                       => 'post:' . self::$page,
			'post_content/blocks/16/attrs/id'                       => 'term:' . self::$cat,
			'post_content/blocks/20/attrs/parentPageID'             => 'post:' . self::$page,
			'post_content/blocks/22/attrs/categories/0/id'          => 'term:' . self::$cat,
			'post_content/blocks/24/attrs/productId'                => 'post:' . self::$page,
			'post_content/blocks/26/attrs/products/0'               => 'post:' . self::$page,
			'post_content/blocks/26/attrs/products/1'               => 'post:' . self::$page2,
			'post_content/blocks/28/html/0/shortcode/0/ids/0'       => 'attachment:' . $a1,
			'post_content/blocks/28/html/0/shortcode/0/ids/1'       => 'attachment:' . $a2,
			'post_content/blocks/28/html/0/shortcode/1/id'          => 'attachment:' . $a1,
			'post_content/blocks/28/html/0/shortcode/2/id/0'        => 'post:' . self::$page,
			'post_content/blocks/28/html/0/shortcode/3/ids/0'       => 'post:' . self::$page,
			'post_content/blocks/28/html/0/shortcode/3/ids/1'       => 'post:' . self::$page2,
			'post_content/blocks/28/html/0/shortcode/4/id/0'        => 'post:' . self::$page,
			'post_content/blocks/28/html/0/shortcode/5/id/0'        => 'post:' . self::$page2,
		);
		foreach ( $expected as $path => $value ) {
			$this->assertArrayHasKey( $path, $refs, $path . ' in ' . wp_json_encode( array_keys( $refs ) ) );
			$this->assertSame( $value, $refs[ $path ], $path );
		}
		$this->assertCount( count( $expected ), $refs, wp_json_encode( $refs ) );

		// The custom navigation link has no ID reference; the missing image is reported.
		$this->assertSame(
			array(
				array(
					'path'  => 'post_content/blocks/30/attrs/id',
					'value' => 987654,
				),
			),
			$scan['unmapped']
		);

		// UUIDs come from the lookup.
		$this->assertSame( 'uuid-post-' . $a1, $scan['refs'][0]['uuid'] );

		$this->assertContains( 'core/gallery', $scan['block_types'] );
		$this->assertContains( 'woocommerce/single-product', $scan['block_types'] );
	}

	public function test_scan_without_canon_never_changes_the_content() {
		$content = $this->fixture();
		$scan    = $this->mapper( false )->scan( 'post', array( 'post_content' => $content ) );
		$this->assertSame( $content, $scan['hash_form']['post_content'] );
		$this->assertNotEmpty( $scan['refs'] );
	}

	public function test_hash_form_replaces_each_location_with_its_canonical_token() {
		$a1   = self::$img1;
		$html = '<!-- wp:image {"id":' . $a1 . '} --><figure><img class="wp-image-' . $a1 . '"/></figure><!-- /wp:image -->';
		$scan = $this->mapper()->scan( 'post', array( 'post_content' => $html ) );
		$tree = $scan['hash_form']['post_content'];

		$this->assertSame( 'blocks', $tree['__sgsp'] );
		$this->assertSame( 'C' . $a1, $tree['tree'][0]['attrs']['id'] );
		$this->assertSame( '<figure><img class="wp-image-C' . $a1 . '"/></figure>', $tree['tree'][0]['content'][0] );

		// Block content without references is hashed as its parsed blocks too (core's kses
		// re-encodes block attributes on live, see ReferenceRewriteTest).
		$plain = $this->mapper()->scan( 'post', array( 'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ) );
		$this->assertSame( 'blocks', $plain['hash_form']['post_content']['__sgsp'] );
		$this->assertSame( array( '<p>Hi</p>' ), $plain['hash_form']['post_content']['tree'][0]['content'] );
		// Classic content without references keeps its text.
		$classic = $this->mapper()->scan( 'post', array( 'post_content' => '<p>Hi</p>' ) );
		$this->assertSame( '<p>Hi</p>', $classic['hash_form']['post_content'] );
	}

	public function test_classic_content_wp_image_class_and_shortcodes() {
		$a1   = self::$img1;
		$html = '<p><img class="alignnone size-full wp-image-' . $a1 . '" src="a.jpg"/></p>[caption id="attachment_' . $a1 . '" align="alignnone"]x[/caption]';
		$scan = $this->mapper()->scan( 'post', array( 'post_content' => $html ) );
		$refs = self::refs( $scan );

		$this->assertSame( 'attachment:' . $a1, $refs['post_content/html/wp-image/0'] );
		$this->assertSame( 'attachment:' . $a1, $refs['post_content/html/shortcode/0/id'] );
		$this->assertSame( '<p><img class="alignnone size-full wp-image-C' . $a1 . '" src="a.jpg"/></p>[caption id="attachment_C' . $a1 . '" align="alignnone"]x[/caption]', $scan['hash_form']['post_content'] );
	}

	public function test_fields_terms_and_meta() {
		$data = array(
			'post_parent'    => self::$page,
			'featured_image' => self::$img1,
			'terms'          => array( 'category' => array( self::$cat ) ),
			'meta'           => array(
				'_builder' => array( '[{"widget":"image","settings":{"image":{"url":"https://x.test/a.jpg","id":424242}}}]' ),
			),
		);
		$scan = $this->mapper()->scan( 'post', $data );
		$refs = self::refs( $scan );

		$this->assertSame( 'post:' . self::$page, $refs['post_parent'] );
		$this->assertSame( 'attachment:' . self::$img1, $refs['featured_image'] );
		$this->assertSame( 'term:' . self::$cat, $refs['terms/category/0'] );
		$this->assertSame( 'C' . self::$page, $scan['hash_form']['post_parent'] );

		// A page-builder {url, id} pair whose URL is not that attachment: reported, not mapped.
		$this->assertSame(
			array(
				array(
					'path'  => 'meta/_builder/0/json/0/settings/image/id',
					'value' => 424242,
				),
			),
			$scan['unmapped']
		);
	}

	public function test_page_builder_pair_with_the_attachment_url_is_a_reference() {
		$url  = wp_get_attachment_url( self::$img1 );
		$data = array( 'meta' => array( '_builder' => array( wp_json_encode( array( 'image' => array( 'url' => $url, 'id' => self::$img1 ) ) ) ) ) );
		update_post_meta( self::$img1, '_wp_attached_file', 'one.jpg' );

		$scan = $this->mapper()->scan( 'post', $data );
		$this->assertSame( 'attachment:' . self::$img1, self::refs( $scan )['meta/_builder/0/json/image/id'] );
		$this->assertSame( 'json', $scan['hash_form']['meta']['_builder'][0]['__sgsp'] );
	}

	public function test_options_theme_mods_and_menu_items() {
		$menu = wp_create_nav_menu( 'Ref menu' );

		$scan = $this->mapper()->scan(
			'option',
			array(
				'name'  => 'page_on_front',
				'value' => (string) self::$page,
			)
		);
		$this->assertSame( 'post:' . self::$page, self::refs( $scan )['value'] );

		$scan = $this->mapper()->scan(
			'option',
			array(
				'name'  => 'theme_mods_demo',
				'value' => array(
					'nav_menu_locations' => array( 'primary' => $menu ),
					'custom_logo'        => self::$img2,
				),
			)
		);
		$refs = self::refs( $scan );
		$this->assertSame( 'menu:' . $menu, $refs['value/nav_menu_locations/primary'] );
		$this->assertSame( 'attachment:' . self::$img2, $refs['value/custom_logo'] );

		$scan = $this->mapper()->scan(
			'menu',
			array(
				'items' => array(
					array(
						'type'      => 'post_type',
						'object_id' => self::$page,
						'parent'    => 0,
					),
					array(
						'type'      => 'taxonomy',
						'object_id' => self::$cat,
						'parent'    => self::$page2,
					),
					array(
						'type'      => 'custom',
						'object_id' => 77,
					),
				),
			)
		);
		$refs = self::refs( $scan );
		$this->assertSame( 'post:' . self::$page, $refs['items/0/object_id'] );
		$this->assertSame( 'term:' . self::$cat, $refs['items/1/object_id'] );
		$this->assertSame( 'menu_item:' . self::$page2, $refs['items/1/parent'] );
		$this->assertArrayNotHasKey( 'items/2/object_id', $refs );
	}

	public function test_the_filter_can_add_rules_but_not_change_built_in_ones() {
		add_filter(
			'softglaze_sp_reference_rules',
			static function ( $rules ) {
				$rules['blocks']['acme/teaser']    = array( 'postId' => 'post' );
				$rules['blocks']['core/image']     = array( 'id' => 'term' );
				$rules['blocks']['Bad Name!']      = array( 'x' => 'post' );
				$rules['post_meta']['_acme_image'] = 'attachment';
				$rules['options']['page_on_front'] = 'term';
				return $rules;
			}
		);
		Reference_Mapper::flush_rules();

		$rules = Reference_Mapper::rules();
		$this->assertSame( array( 'postId' => 'post' ), $rules['blocks']['acme/teaser'] );
		$this->assertSame( 'attachment', $rules['blocks']['core/image']['id'] );
		$this->assertArrayNotHasKey( 'Bad Name!', $rules['blocks'] );
		$this->assertSame( 'post', $rules['options']['page_on_front'] );

		$scan = $this->mapper()->scan(
			'post',
			array(
				'post_content' => '<!-- wp:acme/teaser {"postId":' . self::$page . '} /-->',
				'meta'         => array( '_acme_image' => array( (string) self::$img1 ) ),
			)
		);
		$refs = self::refs( $scan );
		$this->assertSame( 'post:' . self::$page, $refs['post_content/blocks/0/attrs/postId'] );
		$this->assertSame( 'attachment:' . self::$img1, $refs['meta/_acme_image/0'] );
	}
}
