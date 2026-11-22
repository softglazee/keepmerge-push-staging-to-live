<?php
/**
 * Sync\Reference_Mapper rewrite mode: live writes its own IDs exactly at
 * the paths the source's scan recorded, serializes blocks again only when a block reference
 * changed, keeps content IDs it has no entry for, writes 0 into ID fields without an entry, and
 * reports a sidecar entry that names another ID than the value at its path.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Sync\Export_Context;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Items;
use SoftGlaze\SafePush\Sync\Reference_Mapper;

/**
 * @group apply
 */
class ReferenceRewriteTest extends WP_UnitTestCase {

	private function mapper() {
		return new Reference_Mapper( array( Identity::class, 'get' ) );
	}

	/** The map that sends every reference of an export to "live ID = staging ID + $shift". */
	private function shifted( array $refs, $shift ) {
		$map = array();
		foreach ( $refs as $ref ) {
			$map[ $ref['path'] ] = array(
				'kind' => $ref['kind'],
				'id'   => $ref['id'],
				'live' => $ref['id'] + $shift,
			);
		}
		return $map;
	}

	public function test_every_scanned_location_gets_the_live_id_and_nothing_else_changes() {
		$image   = self::factory()->attachment->create_object( '2026/11/a.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$image2  = self::factory()->attachment->create_object( '2026/11/b.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$pattern = self::factory()->post->create( array( 'post_type' => 'wp_block' ) );
		$parent  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$cat     = self::factory()->category->create();
		$content = '<!-- wp:image {"id":' . $image . '} --><figure class="wp-block-image"><img src="x.jpg" class="wp-image-' . $image . '"/></figure><!-- /wp:image -->'
			. '<!-- wp:gallery {"ids":[' . $image . ',' . $image2 . ']} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->'
			. '<!-- wp:block {"ref":' . $pattern . '} /-->'
			. '<!-- wp:shortcode -->[gallery ids="' . $image . ',' . $image2 . '" columns="2"]<!-- /wp:shortcode -->';
		$page    = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_parent'   => $parent,
				'post_content'  => $content,
				'post_category' => array( $cat ),
			)
		);
		set_post_thumbnail( $page, $image );

		$item = Items::export( 'post', $page, Export_Context::source() );
		$map  = $this->shifted( $item['refs'], 1000 );
		$out  = $this->mapper()->rewrite( 'post', $item['data'], $map );

		$this->assertSame( array(), $out['mismatches'] );
		$this->assertSame( 0, $out['zeroed'] );
		$this->assertSame( count( $item['refs'] ), $out['changed'] );
		$this->assertSame( $parent + 1000, $out['data']['post_parent'] );
		$this->assertSame( $image + 1000, $out['data']['featured_image'] );

		$html = $out['data']['post_content'];
		$this->assertStringContainsString( '"id":' . ( $image + 1000 ), $html );
		$this->assertStringContainsString( 'wp-image-' . ( $image + 1000 ), $html );
		$this->assertStringContainsString( '"ids":[' . ( $image + 1000 ) . ',' . ( $image2 + 1000 ) . ']', $html );
		$this->assertStringContainsString( '"ref":' . ( $pattern + 1000 ), $html );
		// The shortcode keeps its other attributes.
		$this->assertStringContainsString( '[gallery ids="' . ( $image + 1000 ) . ',' . ( $image2 + 1000 ) . '" columns="2"]', $html );

		// Scanning the rewritten data finds the same paths: the rewrite moved IDs only.
		$paths = array_column( $item['refs'], 'path' );
		sort( $paths );
		$this->assertSame( $item['data']['post_title'], $out['data']['post_title'] );
	}

	public function test_equal_ids_leave_the_content_byte_identical() {
		$image = self::factory()->attachment->create_object( '2026/11/c.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$page  = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:image {"id":' . $image . ',"sizeSlug":"large"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="x.jpg" alt="" class="wp-image-' . $image . '"/></figure>' . "\n" . '<!-- /wp:image -->',
			)
		);
		$item = Items::export( 'post', $page, Export_Context::source() );
		$out  = $this->mapper()->rewrite( 'post', $item['data'], $this->shifted( $item['refs'], 0 ) );

		$this->assertSame( 0, $out['changed'] );
		$this->assertSame( $item['data']['post_content'], $out['data']['post_content'], 'serialize_blocks() only when a block reference changed' );
	}

	/**
	 * Found in a two-site end-to-end run: live's receiver writes content through core's kses,
	 * which re-encodes every block's attributes (wp_pre_kses_block_attributes()) and so writes an
	 * empty object "{}" as "[]" (WooCommerce's cart page: "taxQuery":{}). Block content without
	 * references was hashed as its raw text, so after a push the page's hash on live differed from
	 * staging's and every later plan offered the page again. Block content now hashes as its
	 * parsed blocks whether or not it holds references.
	 *
	 * The same run found kses's other harmless rewrites of what the block editor writes:
	 * "<img …/>" becomes "<img … />", a bare "&" in a link becomes "&amp;", and "; " in an inline
	 * style loses its space. HTML is now hashed with those three written one way, as the Markup
	 * Gate already compares it.
	 */
	public function test_block_content_that_cores_kses_re_encodes_hashes_alike() {
		// Stored as an administrator with unfiltered_html saves it (no kses): "{}" kept.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$content = '<!-- wp:query {"queryId":0,"query":{"perPage":3,"taxQuery":{},"exclude":[],"relatedBy":{"categories":true}}} --><div class="wp-block-query"><!-- wp:paragraph {"style":{}} --><p>Inner</p><!-- /wp:paragraph --></div><!-- /wp:query -->'
			. '<!-- wp:image {"sizeSlug":"full"} --><figure class="wp-block-image size-full"><img src="https://example.org/a.jpg" alt=""/></figure><!-- /wp:image -->'
			. '<!-- wp:paragraph --><p style="color: red; font-size: 12px"><a href="https://example.net/?a=1&b=2">Link</a></p><!-- /wp:paragraph -->'
			. '<!-- wp:spacer {"height":"10px","style":{}} /-->';
		$page    = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => $content,
			)
		);
		$this->assertStringContainsString( '"taxQuery":{}', get_post_field( 'post_content', $page, 'raw' ) );
		$original = Items::export( 'post', $page, Export_Context::source() );

		// The same content as core's kses writes it for a user without unfiltered_html (the receiver).
		$reencoded = wp_kses_post( $content );
		$this->assertStringContainsString( '"taxQuery":[]', $reencoded );
		$this->assertStringContainsString( 'alt="" />', $reencoded );
		$this->assertStringContainsString( '?a=1&amp;b=2', $reencoded );
		$this->assertStringContainsString( 'color: red;font-size: 12px', $reencoded );
		wp_update_post(
			array(
				'ID'           => $page,
				'post_content' => wp_slash( $reencoded ),
			)
		);
		$this->assertSame( $reencoded, get_post_field( 'post_content', $page, 'raw' ) );
		$this->assertSame( $original['hash'], Items::export( 'post', $page, Export_Context::source() )['hash'], 'the same blocks written two ways hash alike' );

		// A real change still changes the hash.
		wp_update_post(
			array(
				'ID'           => $page,
				'post_content' => wp_slash( str_replace( '"perPage":3', '"perPage":4', $content ) ),
			)
		);
		$this->assertNotSame( $original['hash'], Items::export( 'post', $page, Export_Context::source() )['hash'] );
	}

	public function test_an_id_field_without_an_entry_becomes_zero_and_content_ids_stay() {
		$data = array(
			'post_type'      => 'page',
			'post_parent'    => 777777,
			'featured_image' => 888888,
			'post_content'   => '<p><img class="wp-image-999999" src="x.jpg"/></p>',
			'terms'          => array( 'category' => array( 555555 ) ),
		);
		$out  = $this->mapper()->rewrite( 'post', $data, array() );

		$this->assertSame( 0, $out['data']['post_parent'] );
		$this->assertSame( 0, $out['data']['featured_image'] );
		$this->assertSame( array( 0 ), $out['data']['terms']['category'] );
		$this->assertSame( $data['post_content'], $out['data']['post_content'] );
		$this->assertSame( 3, $out['zeroed'], 'parent, featured image and the category; the class in content stays' );
	}

	public function test_a_sidecar_entry_that_names_another_id_is_a_mismatch() {
		$data = array(
			'post_type'   => 'page',
			'post_parent' => 12,
		);
		$out  = $this->mapper()->rewrite(
			'post',
			$data,
			array(
				'post_parent' => array(
					'kind' => 'post',
					'id'   => 13,
					'live' => 99,
				),
			)
		);
		$this->assertSame( array( 'post_parent' ), $out['mismatches'] );

		$kind = $this->mapper()->rewrite(
			'post',
			$data,
			array(
				'post_parent' => array(
					'kind' => 'attachment',
					'id'   => 12,
					'live' => 99,
				),
			)
		);
		$this->assertSame( array( 'post_parent' ), $kind['mismatches'], 'another kind at the path' );
	}

	public function test_builder_json_ids_are_rewritten_and_empty_objects_stay_objects() {
		$json = '{"elements":[{"settings":{},"image":{"url":"{{sgsp:home}}/wp-content/uploads/a.jpg","id":12}}]}';
		$data = array(
			'post_type'    => 'page',
			'post_content' => '',
			'meta'         => array( '_builder_data' => array( $json ) ),
		);
		$path = 'meta/_builder_data/0/json/elements/0/image/id';
		$out  = $this->mapper()->rewrite(
			'post',
			$data,
			array(
				$path => array(
					'kind' => 'attachment',
					'id'   => 12,
					'live' => 34,
				),
			)
		);
		$this->assertSame( 1, $out['changed'] );
		$decoded = json_decode( $out['data']['meta']['_builder_data'][0], false );
		$this->assertSame( 34, $decoded->elements[0]->image->id );
		$this->assertInstanceOf( stdClass::class, $decoded->elements[0]->settings );
		$this->assertStringContainsString( '"settings":{}', $out['data']['meta']['_builder_data'][0] );

		// Without an entry the builder's own ID is left as it is.
		$same = $this->mapper()->rewrite( 'post', $data, array() );
		$this->assertSame( $json, $same['data']['meta']['_builder_data'][0] );
	}
}
