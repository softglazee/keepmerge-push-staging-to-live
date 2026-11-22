<?php
/**
 * Sync\Manifest (destination, read-only): POST /remote/manifest and POST /remote/items/fetch.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Sync\Export_Context;
use SoftGlaze\SafePush\Sync\Items;
use SoftGlaze\SafePush\Sync\Manifest;

/**
 * @group sync
 * @group manifest
 */
class ManifestTest extends WP_UnitTestCase {

	const PAIRING = array( 'id' => 987 );

	private $page;

	public function set_up() {
		parent::set_up();
		$this->page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Manifest page',
				'post_content' => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
			)
		);
	}

	private function entry( array $o = array() ) {
		return array_merge(
			array(
				'uuid'    => wp_generate_uuid4(),
				'kind'    => 'post',
				'type'    => 'page',
				'live_id' => $this->page,
			),
			$o
		);
	}

	public function test_found_items_carry_the_same_hash_as_a_local_export() {
		$result = Manifest::answer( self::PAIRING, array( 'items' => array( $this->entry() ) ) );
		$this->assertIsArray( $result );
		$item = $result['items'][0];
		$this->assertSame( 'found', $item['state'] );
		$this->assertSame( $this->page, $item['live_id'] );
		$this->assertSame( Items::export( 'post', $this->page, Export_Context::destination() )['hash'], $item['hash'] );
		$this->assertSame( '', $item['base_hash'] );
		$this->assertArrayNotHasKey( 'data', $item, 'the manifest carries hashes only' );
		$this->assertSame( array(), $result['live_changes']['items'] );
	}

	public function test_hints_scope_and_options_follow_lives_own_settings() {
		$comment_post = self::factory()->post->create();
		$block        = self::factory()->post->create( array( 'post_type' => 'wp_block' ) );

		$result = Manifest::answer(
			self::PAIRING,
			array(
				'items' => array(
					// A hint that points at an object of another type than claimed.
					$this->entry( array( 'live_id' => $comment_post ) ),
					// No hint, unknown UUID.
					$this->entry( array( 'live_id' => 0 ) ),
					// Types live does not include.
					$this->entry( array( 'type' => 'revision' ) ),
					$this->entry( array( 'type' => 'shop_order' ) ),
					$this->entry( array( 'type' => 'customize_changeset' ) ),
					// A pattern asked for as a page.
					$this->entry( array( 'live_id' => $block ) ),
					// Options: on live's allowlist, denylisted, not allowlisted.
					array(
						'uuid' => 'option:blogname',
						'kind' => 'option',
					),
					array(
						'uuid' => 'option:admin_email',
						'kind' => 'option',
					),
					array(
						'uuid' => 'option:siteurl',
						'kind' => 'option',
					),
					array(
						'uuid' => 'option:blog_public',
						'kind' => 'option',
					),
					array(
						'uuid' => 'option:softglaze_sp_settings',
						'kind' => 'option',
					),
				),
			)
		);

		$states = array_column( $result['items'], 'state' );
		$this->assertSame( array( 'missing', 'missing', 'excluded', 'excluded', 'excluded', 'missing', 'found', 'excluded', 'excluded', 'excluded', 'excluded' ), $states );
		$this->assertSame( array( 'found' => 1, 'missing' => 3, 'excluded' => 7, 'deferred' => 0 ), $result['counts'] );
	}

	public function test_missing_block_types_and_themes_for_the_listed_items() {
		$result = Manifest::answer(
			self::PAIRING,
			array(
				'items'       => array(),
				'block_types' => array( 'core/paragraph', 'acme/not-here' ),
				'stylesheets' => array( 'no-such-theme-x' ),
			)
		);
		$this->assertSame( array( 'acme/not-here' ), $result['missing_block_types'] );
		$this->assertSame( array( 'no-such-theme-x' ), $result['missing_stylesheets'] );
	}

	public function test_limits_and_kinds() {
		$cases = array(
			'501 items'      => array( Manifest::answer( self::PAIRING, array( 'items' => array_fill( 0, 501, $this->entry() ) ) ), 'too_many_items' ),
			'51 to fetch'    => array( Manifest::fetch( self::PAIRING, array( 'items' => array_fill( 0, 51, $this->entry() ) ) ), 'too_many_items' ),
			'users'          => array( Manifest::fetch( self::PAIRING, array( 'items' => array( $this->entry( array( 'kind' => 'user' ) ) ) ) ), 'invalid_body' ),
			'comments'       => array( Manifest::answer( self::PAIRING, array( 'items' => array( $this->entry( array( 'kind' => 'comment' ) ) ) ) ), 'invalid_body' ),
			'orders by kind' => array( Manifest::fetch( self::PAIRING, array( 'items' => array( $this->entry( array( 'kind' => 'shop_order' ) ) ) ) ), 'invalid_body' ),
			'menu items'     => array( Manifest::fetch( self::PAIRING, array( 'items' => array( $this->entry( array( 'kind' => 'menu_item' ) ) ) ) ), 'invalid_body' ),
			'bad option'     => array( Manifest::fetch( self::PAIRING, array( 'items' => array( array( 'uuid' => 'option:a b', 'kind' => 'option' ) ) ) ), 'invalid_body' ),
			'bad uuid'       => array( Manifest::fetch( self::PAIRING, array( 'items' => array( $this->entry( array( 'uuid' => 'x' ) ) ) ) ), 'invalid_body' ),
			'string id'      => array( Manifest::fetch( self::PAIRING, array( 'items' => array( $this->entry( array( 'live_id' => '5' ) ) ) ) ), 'invalid_body' ),
			'no list'        => array( Manifest::answer( self::PAIRING, array( 'items' => 'all' ) ), 'invalid_body' ),
			'bad block'      => array( Manifest::answer( self::PAIRING, array( 'items' => array(), 'block_types' => array( '<script>' ) ) ), 'invalid_body' ),
		);
		foreach ( $cases as $label => $case ) {
			$this->assertWPError( $case[0], $label );
			$this->assertSame( $case[1], $case[0]->get_error_code(), $label );
			$this->assertSame( 400, $case[0]->get_error_data()['status'], $label );
		}
		$this->assertIsArray( Manifest::fetch( self::PAIRING, array( 'items' => array_fill( 0, 50, $this->entry() ) ) ) );
		$this->assertIsArray( Manifest::answer( self::PAIRING, array( 'items' => array_fill( 0, 500, $this->entry() ) ) ) );
	}

	public function test_fetch_returns_the_normal_form_but_no_email_or_comment() {
		$user = self::factory()->user->create(
			array(
				'user_login' => 'live-author',
				'user_email' => 'author-km@example.invalid',
			)
		);
		wp_update_post(
			array(
				'ID'          => $this->page,
				'post_author' => $user,
			)
		);
		self::factory()->comment->create(
			array(
				'comment_post_ID'      => $this->page,
				'comment_content'      => 'km-comment-needle',
				'comment_author_email' => 'commenter-km@example.invalid',
			)
		);

		$result = Manifest::fetch( self::PAIRING, array( 'items' => array( $this->entry() ) ) );
		$item   = $result['items'][0];
		$this->assertSame( 'found', $item['state'] );
		$this->assertSame( 'Manifest page', $item['data']['post_title'] );
		$this->assertSame( array( 'login' => 'live-author' ), $item['data']['author'] );

		$json = wp_json_encode( $result );
		foreach ( array( 'author-km@example.invalid', 'km-comment-needle', 'commenter-km@example.invalid', get_option( 'admin_email' ) ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	public function test_reads_write_nothing() {
		global $wpdb;
		$count = static function () use ( $wpdb ) {
			return array(
				(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
				(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
				(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta}" ),
				(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ),
			);
		};
		$before = $count();
		Manifest::answer( self::PAIRING, array( 'items' => array( $this->entry(), array( 'uuid' => 'option:blogname', 'kind' => 'option' ) ) ) );
		Manifest::fetch( self::PAIRING, array( 'items' => array( $this->entry() ) ) );
		$this->assertSame( $before, $count() );
	}
}
