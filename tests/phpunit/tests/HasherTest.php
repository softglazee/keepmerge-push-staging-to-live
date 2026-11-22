<?php
/**
 * Sync\Hasher over the hash form, and exporter hash stability.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Sync\Export_Context;
use SoftGlaze\SafePush\Sync\Hasher;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Items;
use SoftGlaze\SafePush\Sync\Normalizer;
use SoftGlaze\SafePush\Sync\Reference_Mapper;

/**
 * @group sync
 * @group hasher
 */
class HasherTest extends WP_UnitTestCase {

	public function test_object_key_order_does_not_matter_but_list_order_does() {
		$this->assertSame(
			Hasher::hash(
				array(
					'b' => 1,
					'a' => array(
						'y' => 2,
						'x' => 3,
					),
				)
			),
			Hasher::hash(
				array(
					'a' => array(
						'x' => 3,
						'y' => 2,
					),
					'b' => 1,
				)
			)
		);
		$this->assertNotSame( Hasher::hash( array( 1, 2 ) ), Hasher::hash( array( 2, 1 ) ) );
		$this->assertSame( 64, strlen( Hasher::hash( array() ) ) );
	}

	public function test_term_assignments_are_compared_as_sets() {
		$this->assertSame(
			Hasher::hash( array( 'terms' => array( 'category' => array( 't:3', 't:1' ) ) ) ),
			Hasher::hash( array( 'terms' => array( 'category' => array( 't:1', 't:3' ) ) ) )
		);
	}

	public function test_same_content_with_different_urls_and_ids_hashes_alike() {
		$a = self::factory()->post->create();
		$b = self::factory()->post->create();

		$staging = new Normalizer(
			array(
				array(
					'name' => 'home',
					'url'  => 'https://staging.example.com',
				),
			),
			true
		);
		$live    = new Normalizer(
			array(
				array(
					'name' => 'home',
					'url'  => 'https://live.example.org',
				),
			),
			false
		);

		// Staging's item points at post $a, which the baseline linked to live's post $b.
		$staging_data = $staging->encode(
			array(
				'post_content' => '<p><a href="https://staging.example.com/about/">About</a></p>',
				'post_parent'  => $a,
			)
		);
		$live_data    = $live->encode(
			array(
				'post_content' => '<p><a href="https://live.example.org/about/">About</a></p>',
				'post_parent'  => $b,
			)
		);
		$uuid         = static function () {
			return '';
		};
		$source_canon = static function ( $space, $id ) use ( $a, $b ) {
			return $id === $a ? 'p:' . $b : 'u:' . $id;
		};
		$live_canon   = static function ( $space, $id ) {
			return 'p:' . $id;
		};

		$s = ( new Reference_Mapper( $uuid, $source_canon ) )->scan( 'post', $staging_data );
		$l = ( new Reference_Mapper( $uuid, $live_canon ) )->scan( 'post', $live_data );

		$this->assertNotSame( $staging_data, $live_data );
		$this->assertSame( Hasher::hash( $l['hash_form'] ), Hasher::hash( $s['hash_form'] ) );

		// A real change still shows.
		$changed = $live->encode(
			array(
				'post_content' => '<p><a href="https://live.example.org/about/">About us</a></p>',
				'post_parent'  => $b,
			)
		);
		$c       = ( new Reference_Mapper( $uuid, $live_canon ) )->scan( 'post', $changed );
		$this->assertNotSame( Hasher::hash( $c['hash_form'] ), Hasher::hash( $s['hash_form'] ) );
	}

	public function test_volatile_data_never_enters_an_items_hash() {
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>Stable</p><!-- /wp:paragraph -->',
			)
		);
		$hash = Items::export( 'post', $page, Export_Context::destination() )['hash'];

		// Modification date, edit lock, edit last, Keepmerge's own UUID, an oEmbed cache.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2001-01-01 00:00:00', 'post_modified' => '2001-01-01 00:00:00' ), array( 'ID' => $page ) );
		clean_post_cache( $page );
		update_post_meta( $page, '_edit_lock', time() . ':1' );
		update_post_meta( $page, '_edit_last', 1 );
		update_post_meta( $page, '_softglaze_sp_uuid', wp_generate_uuid4() );
		update_post_meta( $page, '_oembed_abc', '<iframe></iframe>' );
		$this->assertSame( $hash, Items::export( 'post', $page, Export_Context::destination() )['hash'] );

		// Content and meta that matter do change it.
		update_post_meta( $page, 'seo_title', 'New' );
		$with_meta = Items::export( 'post', $page, Export_Context::destination() )['hash'];
		$this->assertNotSame( $hash, $with_meta );

		wp_update_post(
			array(
				'ID'         => $page,
				'post_title' => 'Renamed',
			)
		);
		$this->assertNotSame( $with_meta, Items::export( 'post', $page, Export_Context::destination() )['hash'] );
	}

	public function test_the_author_is_compared_by_login_and_live_never_returns_an_email() {
		$user = self::factory()->user->create(
			array(
				'user_login' => 'writer',
				'user_email' => 'writer@example.invalid',
			)
		);
		$page = self::factory()->post->create( array( 'post_author' => $user ) );

		$source = Items::export( 'post', $page, Export_Context::source() );
		$dest   = Items::export( 'post', $page, Export_Context::destination() );
		$this->assertSame( 'writer@example.invalid', $source['data']['author']['email'] );
		$this->assertArrayNotHasKey( 'email', $dest['data']['author'] );

		// The emails differ (staging copies are often anonymised): the hash does not.
		wp_update_user(
			array(
				'ID'         => $user,
				'user_email' => 'other@example.invalid',
			)
		);
		$this->assertSame( $source['hash'], Items::export( 'post', $page, Export_Context::source() )['hash'] );
	}

	public function test_product_live_owned_group_is_kept_out_of_the_item_hash() {
		$form   = array(
			'post_type' => 'product',
			'groups'    => array(
				'content'    => array( 'name' => 'Hoodie' ),
				'pricing'    => array( 'regular_price' => '45' ),
				'identity'   => array( 'sku' => 'H-1' ),
				'live_owned' => array( 'stock_quantity' => 10 ),
			),
		);
		$before = Hasher::item_hashes( 'product', $form );

		$form['groups']['live_owned']['stock_quantity'] = 7;
		$after = Hasher::item_hashes( 'product', $form );
		$this->assertSame( $before['hash'], $after['hash'] );
		$this->assertNotSame( $before['groups']['live_owned'], $after['groups']['live_owned'] );
		$this->assertSame( $before['groups']['pricing'], $after['groups']['pricing'] );

		$form['groups']['pricing']['regular_price'] = '40';
		$priced = Hasher::item_hashes( 'product', $form );
		$this->assertNotSame( $after['hash'], $priced['hash'] );
		$this->assertNotSame( $after['groups']['pricing'], $priced['groups']['pricing'] );
		$this->assertSame( $after['groups']['content'], $priced['groups']['content'] );
	}

	public function test_destination_exports_write_nothing() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$term = self::factory()->category->create();
		delete_post_meta( $page, Identity::META_KEY );

		global $wpdb;
		$meta_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" );
		$term_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta}" );

		$ctx = Export_Context::destination();
		Items::export( 'post', $page, $ctx );
		Items::export( 'term', $term, $ctx );

		$this->assertSame( $meta_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ) );
		$this->assertSame( $term_before, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta}" ) );
		$this->assertSame( '', Identity::get( 'post', $page ) );

		// The source context gives the object a UUID (its own site only).
		Items::export( 'post', $page, Export_Context::source() );
		$this->assertTrue( wp_is_uuid( Identity::get( 'post', $page ), 4 ) );
	}
}
