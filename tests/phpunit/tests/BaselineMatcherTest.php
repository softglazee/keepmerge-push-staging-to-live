<?php
/**
 * Sync\Baseline_Matcher (destination, read-only): matching existing items at the first sync.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Sync\Baseline_Matcher;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Normalizer;

/**
 * @group sync
 * @group baseline
 */
class BaselineMatcherTest extends WP_UnitTestCase {

	private $page;

	public function set_up() {
		parent::set_up();
		$this->page = get_post(
			self::factory()->post->create(
				array(
					'post_type'     => 'page',
					'post_name'     => 'about-us',
					'post_date_gmt' => '2026-01-02 03:04:05',
					'post_date'     => '2026-01-02 03:04:05',
				)
			)
		);
	}

	private function post_fp( array $o = array() ) {
		return array_merge(
			array(
				'k'    => 'post',
				'id'   => $this->page->ID,
				'type' => 'page',
				'guid' => Normalizer::for_destination()->tokenize( $this->page->guid, false ),
				'slug' => 'about-us',
				'date' => '2026-01-02 03:04:05',
			),
			$o
		);
	}

	private function answer( array $fingerprints ) {
		$result = Baseline_Matcher::answer( array( 'fingerprints' => $fingerprints ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		return $result['results'];
	}

	public function test_post_matches_strong_medium_none_and_excluded() {
		$staging_guid = str_replace( home_url(), 'https://staging.example.com', $this->page->guid );
		$this->assertNotSame( $staging_guid, $this->page->guid );

		$results = $this->answer(
			array(
				// Same GUID after tokens (the clone search-replaced it).
				$this->post_fp(),
				// Same ID, slug and date, but the GUID was changed.
				$this->post_fp( array( 'guid' => '{{sgsp:home}}/?page_id=123456' ) ),
				// Same GUID, slug and date on another ID (a page created again gets the same
				// permalink-shaped GUID): medium, never linked.
				$this->post_fp( array( 'id' => 999999 ) ),
				// Recreated: other ID, other GUID, same slug and date.
				$this->post_fp(
					array(
						'id'   => 999998,
						'guid' => '{{sgsp:home}}/?page_id=999998',
					)
				),
				// Same slug, other date: none, slug taken.
				$this->post_fp(
					array(
						'id'   => 999997,
						'guid' => '{{sgsp:home}}/?page_id=999997',
						'date' => '2020-01-01 00:00:00',
					)
				),
				// Same GUID on another ID but another date: none, slug taken.
				$this->post_fp(
					array(
						'id'   => 999996,
						'date' => '2020-01-01 00:00:00',
					)
				),
				// A type live does not include.
				$this->post_fp( array( 'type' => 'revision' ) ),
				$this->post_fp( array( 'type' => 'shop_order' ) ),
			)
		);

		$this->assertSame( array( 'strong', $this->page->ID, 'guid' ), array( $results[0]['m'], $results[0]['id'], $results[0]['by'] ) );
		$this->assertSame( array( 'strong', $this->page->ID, 'id_slug_date' ), array( $results[1]['m'], $results[1]['id'], $results[1]['by'] ) );
		$this->assertSame( array( 'medium', $this->page->ID, 'slug_date' ), array( $results[2]['m'], $results[2]['id'], $results[2]['by'] ) );
		$this->assertSame( array( 'medium', $this->page->ID, 'slug_date' ), array( $results[3]['m'], $results[3]['id'], $results[3]['by'] ) );
		$this->assertSame( array( 'none', 0 ), array( $results[4]['m'], $results[4]['id'] ) );
		$this->assertSame( array( $this->page->ID ), $results[4]['taken'] );
		$this->assertSame( array( 'none', array( $this->page->ID ) ), array( $results[5]['m'], $results[5]['taken'] ) );
		$this->assertSame( 'excluded', $results[6]['m'] );
		$this->assertSame( 'excluded', $results[7]['m'] );
		$this->assertSame( range( 0, 7 ), array_column( $results, 'i' ) );
	}

	public function test_existing_uuid_is_returned_for_the_source_to_adopt() {
		$uuid = wp_generate_uuid4();
		update_post_meta( $this->page->ID, Identity::META_KEY, $uuid );
		$results = $this->answer( array( $this->post_fp() ) );
		$this->assertSame( $uuid, $results[0]['uuid'] );
	}

	public function test_terms_menus_templates_global_styles_and_attachments() {
		$cat  = get_term( self::factory()->category->create( array( 'slug' => 'news-x' ) ) );
		$menu = get_term( wp_create_nav_menu( 'Main menu x' ) );

		$template = self::factory()->post->create(
			array(
				'post_type'   => 'wp_template',
				'post_name'   => 'page-landing',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $template, 'twentytwentyfive', 'wp_theme' );
		$styles = self::factory()->post->create(
			array(
				'post_type'   => 'wp_global_styles',
				'post_name'   => 'wp-global-styles-twentytwentyfive',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $styles, 'twentytwentyfive', 'wp_theme' );

		$upload = wp_upload_dir( null, false );
		wp_mkdir_p( $upload['basedir'] . '/2026/10' );
		file_put_contents( $upload['basedir'] . '/2026/10/km-test.txt', 'twelve bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$attachment = self::factory()->attachment->create_object( '2026/10/km-test.txt', 0, array( 'post_mime_type' => 'text/plain' ) );

		$results = $this->answer(
			array(
				array(
					'k'    => 'term',
					'id'   => $cat->term_id,
					'tax'  => 'category',
					'slug' => 'news-x',
				),
				array(
					'k'    => 'term',
					'id'   => 999999,
					'tax'  => 'category',
					'slug' => 'news-x',
				),
				array(
					'k'    => 'term',
					'id'   => 999999,
					'tax'  => 'category',
					'slug' => 'nowhere',
				),
				array(
					'k'    => 'menu',
					'id'   => $menu->term_id,
					'slug' => $menu->slug,
				),
				array(
					'k'     => 'template',
					'id'    => 999999,
					'slug'  => 'page-landing',
					'theme' => 'twentytwentyfive',
				),
				array(
					'k'     => 'template',
					'id'    => 999999,
					'slug'  => 'page-landing',
					'theme' => 'another-theme',
				),
				array(
					'k'     => 'global_styles',
					'id'    => 999999,
					'theme' => 'twentytwentyfive',
				),
				array(
					'k'    => 'attachment',
					'id'   => $attachment,
					'file' => '2026/10/km-test.txt',
					'size' => 12,
				),
				array(
					'k'    => 'attachment',
					'id'   => $attachment,
					'file' => '2026/10/km-test.txt',
					'size' => 13,
				),
				array(
					'k'    => 'attachment',
					'id'   => 5,
					'file' => 'woocommerce_uploads/secret.zip',
					'size' => 1,
				),
			)
		);

		$this->assertSame( array( 'strong', $cat->term_id ), array( $results[0]['m'], $results[0]['id'] ) );
		$this->assertSame( array( 'medium', $cat->term_id ), array( $results[1]['m'], $results[1]['id'] ) );
		$this->assertSame( 'none', $results[2]['m'] );
		$this->assertSame( array( 'strong', $menu->term_id ), array( $results[3]['m'], $results[3]['id'] ) );
		$this->assertSame( array( 'strong', $template, 'slug_theme' ), array( $results[4]['m'], $results[4]['id'], $results[4]['by'] ) );
		$this->assertSame( 'none', $results[5]['m'] );
		$this->assertSame( array( 'strong', $styles, 'stylesheet' ), array( $results[6]['m'], $results[6]['id'], $results[6]['by'] ) );
		$this->assertSame( array( 'strong', $attachment, 'path_size' ), array( $results[7]['m'], $results[7]['id'], $results[7]['by'] ) );
		$this->assertSame( array( 'none', array( $attachment ) ), array( $results[8]['m'], $results[8]['taken'] ) );
		$this->assertSame( 'excluded', $results[9]['m'] );
	}

	public function test_body_limits_and_validation() {
		$too_many = array_fill( 0, 501, $this->post_fp() );
		$result   = Baseline_Matcher::answer( array( 'fingerprints' => $too_many ) );
		$this->assertWPError( $result );
		$this->assertSame( 'too_many_items', $result->get_error_code() );
		$this->assertIsArray( Baseline_Matcher::answer( array( 'fingerprints' => array_fill( 0, 500, $this->post_fp() ) ) ) );

		$bad = array(
			array( 'fingerprints' => 'x' ),
			array( 'fingerprints' => array( array( 'k' => 'user', 'id' => 1 ) ) ),
			array( 'fingerprints' => array( array( 'k' => 'comment', 'id' => 1 ) ) ),
			array( 'fingerprints' => array( $this->post_fp( array( 'id' => '5' ) ) ) ),
			array( 'fingerprints' => array( $this->post_fp( array( 'type' => 'Page Type' ) ) ) ),
			array( 'fingerprints' => array( $this->post_fp( array( 'date' => 'yesterday' ) ) ) ),
			array( 'fingerprints' => array( array( 'k' => 'attachment', 'id' => 1, 'file' => '../wp-config.php', 'size' => 1 ) ) ),
			array( 'fingerprints' => array( array( 'k' => 'attachment', 'id' => 1, 'file' => '/etc/passwd', 'size' => 1 ) ) ),
			array( 'fingerprints' => array( array( 'k' => 'template', 'id' => 1, 'slug' => 'x', 'theme' => '../x' ) ) ),
		);
		foreach ( $bad as $body ) {
			$result = Baseline_Matcher::answer( $body );
			$this->assertWPError( $result, wp_json_encode( $body ) );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
	}

	public function test_matching_writes_nothing() {
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
		$this->answer( array( $this->post_fp(), $this->post_fp( array( 'id' => 999998, 'guid' => '{{sgsp:home}}/x' ) ) ) );
		$this->assertSame( $before, $count() );
	}
}
