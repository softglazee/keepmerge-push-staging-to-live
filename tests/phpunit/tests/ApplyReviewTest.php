<?php
/**
 * Review of held items on live: the review gate stops every
 * write while a push has held items (S-07, S-37, S-81), decisions are checked per item on the
 * server (S-37), approved markup is written only by a live administrator's own session, filtered
 * items get WordPress's HTML filter, skipped items leave live unchanged (F-14, S-82). The plugin
 * never removes core's filters (S-37 grep).
 *
 * @package SoftGlaze\SafePush\Tests
 */

require_once __DIR__ . '/class-keepmerge-apply-harness.php';

use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Push_Session;

/**
 * @group apply
 * @group security
 */
class ApplyReviewTest extends Keepmerge_Apply_Harness {

	private function session_id( $push ) {
		return (int) Push_Session::find( $push )['id'];
	}

	private function decide( $push, array $decisions, $user = null ) {
		return $this->admin( 'POST', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) . '/held', array( 'decisions' => $decisions ), $user );
	}

	private function continue_apply( $push, $user = null, $confirm = 'example.org' ) {
		$last = null;
		for ( $i = 0; $i < 30; $i++ ) {
			$last = $this->admin( 'POST', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) . '/continue', array( 'confirm' => $confirm ), $user );
			if ( 200 !== $last->get_status() || ! in_array( $last->get_data()['state'], array( 'snapshotting', 'applying' ), true ) ) {
				break;
			}
		}
		return $last;
	}

	/** Content state the review must not change: hashes of the given items plus table counts. */
	private function state( array $items ) {
		$out = array( 'counts' => $this->content_counts() );
		foreach ( $items as $item ) {
			$out[ $item[0] . ':' . $item[1] ] = $this->live_hash( $item[0], $item[1] );
		}
		return $out;
	}

	private function page_with( $title, $content ) {
		return self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => $title,
				'post_content' => $content,
			)
		);
	}

	private function content_change( $id, $content, $kind = 'post' ) {
		return $this->envelope(
			$kind,
			$id,
			'push',
			static function ( $data ) use ( $content ) {
				$data['post_content'] = $content;
				return $data;
			}
		);
	}

	// --- S-81 ---------------------------------------------------------------------------------------

	public function test_s81_markup_in_meta_term_meta_options_and_widgets_is_held_and_nothing_is_written() {
		Settings::save(
			array_merge(
				Settings::get(),
				array(
					'custom_options' => array( 'km_builder_*' ),
					'option_groups'  => array_merge( Settings::get()['option_groups'], array( 'widgets' => true ) ),
				)
			)
		);
		Scope::flush();
		update_option( 'km_builder_footer', 'plain' );
		update_option( 'widget_custom_html', array( 2 => array( 'content' => 'plain' ), '_multiwidget' => 1 ) );

		$page  = $this->page_with( 'S81 page', 'clean' );
		$clean = $this->page_with( 'S81 clean', 'clean' );
		$term  = self::factory()->category->create( array( 'name' => 'S81 term' ) );

		$envs   = array();
		$envs[] = $this->envelope(
			'post',
			$page,
			'push',
			static function ( $data ) {
				$data['meta']['_builder'] = array( '[{"widget":"html","html":"<script>alert(1)<\/script>"}]' );
				return $data;
			}
		);
		$envs[] = $this->envelope(
			'term',
			$term,
			'push',
			static function ( $data ) {
				$data['meta']['km_banner'] = array( '<img src=x onload=alert(1)>' );
				return $data;
			}
		);
		$envs[] = $this->envelope(
			'option',
			'km_builder_footer',
			'push',
			static function ( $data ) {
				$data['value'] = 'go=' . rawurlencode( 'javascript:alert(1)' );
				return $data;
			}
		);
		$envs[] = $this->envelope(
			'widgets',
			'widgets',
			'push',
			static function ( $data ) {
				$data['widgets']['widget_custom_html'][2]['content'] = '{"inner":"&lt;script&gt;x()&lt;/script&gt;"}';
				return $data;
			}
		);
		$envs[] = $this->content_change( $clean, 'still clean, changed' );

		$push   = $this->stage( $envs );
		$before = $this->state( array( array( 'post', $page ), array( 'post', $clean ), array( 'term', $term ), array( 'option', 'km_builder_footer' ), array( 'widgets', 'widgets' ) ) );
		$rows   = $this->rows( $push );
		$held   = array();
		foreach ( $rows as $row ) {
			if ( 'held' === $row['status'] ) {
				$held[ $row['object_type'] ] = array_column( $row['payload']['gate'], 'rule' );
			}
		}
		$this->assertSame( array( 'post', 'term', 'option', 'widgets' ), array_keys( $held ) );
		$this->assertContains( 'pattern:tag', $held['post'] );
		$this->assertContains( 'pattern:event_attribute', $held['term'] );
		$this->assertContains( 'pattern:javascript_url', $held['option'], 'URL-encoded once' );
		$this->assertContains( 'pattern:escaped_tag', $held['widgets'], 'entity-encoded, inside nested JSON' );

		// The source calls apply again and again: nothing is written, the push waits for review.
		for ( $i = 0; $i < 3; $i++ ) {
			$answers = $this->apply( $push );
			$this->assertSame( 'awaiting_review', end( $answers )['state'] );
			$this->assertSame( 4, end( $answers )['undecided'] );
		}
		$this->assertSame( $before, $this->state( array( array( 'post', $page ), array( 'post', $clean ), array( 'term', $term ), array( 'option', 'km_builder_footer' ), array( 'widgets', 'widgets' ) ) ) );
		$this->assertSame( 'clean', get_post( $clean )->post_content, 'even items without markup wait' );
		$this->assertSame( 'plain', get_option( 'km_builder_footer' ) );
		global $wpdb;
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE push_id = %d AND snapshot_gmt IS NOT NULL", Tables::table_name( 'push_items' ), $this->session_id( $push ) ) ), 'not even a snapshot pass' );
	}

	// --- S-07 ---------------------------------------------------------------------------------------

	public function test_s07_a_compromised_source_with_valid_credentials_gets_nothing_written() {
		global $wpdb;
		$roles   = $wpdb->prefix . 'user_roles';
		$before  = array(
			'active_plugins'     => get_option( 'active_plugins' ),
			'users_can_register' => get_option( 'users_can_register' ),
			$roles               => get_option( $roles ),
		);
		$order   = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$page    = $this->page_with( 'S07 page', 'clean' );
		$hash    = str_repeat( 'b', 64 );
		$attacks = array();
		foreach ( array( 'active_plugins', 'users_can_register', $roles ) as $name ) {
			$attacks[] = array(
				'uuid'   => 'option:' . $name,
				'kind'   => 'option',
				'type'   => $name,
				'action' => 'push',
				'hash'   => $hash,
				'data'   => array(
					'name'  => $name,
					'value' => 'active_plugins' === $name ? array( 'evil/evil.php' ) : 1,
				),
			);
		}
		foreach ( array( 'shop_order', 'shop_order_placehold' ) as $type ) {
			$attacks[] = array(
				'uuid'   => wp_generate_uuid4(),
				'kind'   => 'post',
				'type'   => $type,
				'action' => 'create',
				'hash'   => $hash,
				'data'   => array(
					'post_type'    => $type,
					'post_title'   => 'Order',
					'post_content' => '',
					'post_status'  => 'publish',
				),
			);
		}
		$attacks[] = $this->content_change( $page, '<p>Hi</p><script>document.cookie</script>' );

		// A user item is not a kind live knows: the whole batch is refused.
		$user_item = array(
			'uuid'   => wp_generate_uuid4(),
			'kind'   => 'user',
			'type'   => 'user',
			'action' => 'create',
			'hash'   => $hash,
			'data'   => array( 'user_login' => 'evil' ),
		);
		$push      = wp_generate_uuid4();
		$this->call(
			'POST',
			'/keepmerge/v1/remote/push',
			(string) wp_json_encode(
				array(
					'push'      => $push,
					'plan_hash' => str_repeat( 'c', 64 ),
					'counts'    => array(
						'create'   => 3,
						'update'   => 4,
						'recreate' => 0,
						'trash'    => 0,
						'adopt'    => 0,
					),
					'items'     => 7,
				)
			)
		);
		$refused = $this->call(
			'POST',
			'/keepmerge/v1/remote/push/' . $push . '/items',
			(string) wp_json_encode(
				array(
					'seq'   => 1,
					'items' => array( $user_item ),
				)
			)
		);
		$this->assertSame( 400, $refused->get_status() );
		$this->call( 'POST', '/keepmerge/v1/remote/push/' . $push . '/abort', '{}' );

		$push    = $this->stage( $attacks );
		$results = array();
		foreach ( $this->rows( $push ) as $row ) {
			$results[ (string) $row['payload']['type'] ] = $row['status'] . ':' . explode( ':', (string) $row['error'] )[0];
		}
		$this->assertSame( 'refused:option_denied', $results['active_plugins'] );
		$this->assertSame( 'refused:option_denied', $results['users_can_register'] );
		$this->assertSame( 'refused:option_denied', $results[ $roles ] );
		$this->assertSame( 'refused:type_denied', $results['shop_order'] );
		$this->assertSame( 'refused:type_denied', $results['shop_order_placehold'] );
		$this->assertSame( 'held:', $results['page'] );

		$answers = $this->apply( $push );
		$this->assertSame( 'awaiting_review', end( $answers )['state'] );
		$this->assertSame( 'clean', get_post( $page )->post_content );
		foreach ( $before as $name => $value ) {
			$this->assertSame( $value, get_option( $name ), $name );
		}
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_placehold')" ) ); // phpcs:ignore WordPress.DB
		$this->assertFalse( get_user_by( 'login', 'evil' ) );
		unset( $order );
	}

	// --- S-37 ---------------------------------------------------------------------------------------

	public function test_s37_approve_as_is_needs_unfiltered_html_and_edit_css_on_the_server() {
		// The block theme of the core under test (6.5 ships no twentytwentyfive).
		$theme = wp_get_theme( 'twentytwentyfive' )->exists() ? 'twentytwentyfive' : 'twentytwentyfour';
		switch_theme( $theme );
		$styles = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		// Core creates the post with tax_input, which needs a user who may assign terms.
		wp_set_object_terms( $styles, $theme, 'wp_theme' );
		$this->assertGreaterThan( 0, $styles );
		$page = $this->page_with( 'S37 page', 'clean' );
		$css  = $this->envelope(
			'global_styles',
			$styles,
			'push',
			static function ( $data ) {
				$json                  = json_decode( $data['post_content'], true );
				$json['styles']['css'] = 'body{color:red}';
				$data['post_content']  = (string) wp_json_encode( $json );
				return $data;
			}
		);
		$push = $this->stage( array( $this->content_change( $page, '<p onclick="steal()">x</p>' ), $css ) );
		$this->apply( $push );
		$this->assertSame( 'awaiting_review', Push_Session::find( $push )['state'] );
		$rows        = $this->rows( $push );
		$page_uuid   = $this->rows_uuid( $rows, 'post' );
		$styles_uuid = $this->rows_uuid( $rows, 'global_styles' );

		// An administrator without unfiltered_html (DISALLOW_UNFILTERED_HTML cannot change at runtime,
		// so the capability is removed through map_meta_cap, which is what the constant does).
		$deny = static function ( $caps, $cap ) {
			return in_array( $cap, array( 'unfiltered_html', 'edit_css' ), true ) ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		$response = $this->decide( $push, array( $page_uuid => 'approve' ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'approve_not_allowed', $response->get_data()['code'] );
		$this->assertSame( '', $this->rows( $push )[ $page_uuid ]['review'], 'nothing recorded' );
		// The detail screen tells the client the control is unavailable.
		$detail = $this->admin( 'GET', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) )->get_data();
		$this->assertFalse( $detail['can']['unfiltered_html'] );
		remove_filter( 'map_meta_cap', $deny, 10 );

		// CSS needs edit_css as well.
		$no_css = static function ( $caps, $cap ) {
			return 'edit_css' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $no_css, 10, 2 );
		$response = $this->decide( $push, array( $styles_uuid => 'approve' ) );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '', $this->rows( $push )[ $styles_uuid ]['review'] );
		remove_filter( 'map_meta_cap', $no_css, 10 );

		// Decided by a full administrator, then continued by one who lost the right: refused.
		$this->assertSame( 200, $this->decide( $push, array( $page_uuid => 'approve', $styles_uuid => 'skip' ) )->get_status() );
		add_filter( 'map_meta_cap', $deny, 10, 2 );
		$response = $this->continue_apply( $push );
		$this->assertSame( 403, $response->get_status() );
		remove_filter( 'map_meta_cap', $deny, 10 );
		$this->assertSame( 'clean', get_post( $page )->post_content, 'nothing written by the refused continue' );

		// Editors cannot reach the review at all.
		$this->assertSame( 403, $this->decide( $push, array( $page_uuid => 'skip' ), self::$editor )->get_status() );

		// The source keeps calling apply: awaiting_review, nothing written.
		$again = $this->apply( $push );
		$this->assertSame( 'awaiting_review', end( $again )['state'] );
		$this->assertSame( 'clean', get_post( $page )->post_content );
	}

	public function test_s37_the_plugin_never_removes_cores_filters() {
		$hits = array();
		$dir  = dirname( __DIR__, 3 ) . '/keepmerge-push-staging-to-live';
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$path = wp_normalize_path( $file->getPathname() );
			if ( false !== strpos( $path, '/node_modules/' ) || false !== strpos( $path, '/.git/' ) || ! preg_match( '/\.(php|js|jsx)\z/', $path ) ) {
				continue;
			}
			if ( false !== strpos( (string) file_get_contents( $path ), 'kses_remove_filters' ) ) {
				$hits[] = $path;
			}
		}
		$this->assertSame( array(), $hits );
	}

	private function rows_uuid( array $rows, $kind ) {
		foreach ( $rows as $uuid => $row ) {
			if ( $kind === $row['object_type'] ) {
				return $uuid;
			}
		}
		return '';
	}

	// --- S-82 ---------------------------------------------------------------------------------------

	public function test_s82_global_styles_css_reaches_live_only_after_approval_and_skip_leaves_it_unchanged() {
		// The block theme of the core under test (6.5 ships no twentytwentyfive).
		$theme = wp_get_theme( 'twentytwentyfive' )->exists() ? 'twentytwentyfive' : 'twentytwentyfour';
		switch_theme( $theme );
		$styles = WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		// Core creates the post with tax_input, which needs a user who may assign terms.
		wp_set_object_terms( $styles, $theme, 'wp_theme' );
		$before = get_post( $styles )->post_content;
		$this->assertStringNotContainsString( 'outline:3px', $before );
		$make = function () use ( $styles ) {
			return $this->envelope(
				'global_styles',
				$styles,
				'push',
				static function ( $data ) {
					$json                  = json_decode( $data['post_content'], true );
					$json['styles']['css'] = 'a{outline:3px solid gold}';
					$data['post_content']  = (string) wp_json_encode( $json );
					return $data;
				}
			);
		};

		// Skipped: unchanged.
		$push = $this->stage( array( $make() ) );
		$this->apply( $push );
		$this->assertSame( 'awaiting_review', Push_Session::find( $push )['state'] );
		$uuid = array_keys( $this->rows( $push ) )[0];
		$this->assertSame( 200, $this->decide( $push, array( $uuid => 'skip' ) )->get_status() );
		$this->assertSame( 'applied', $this->continue_apply( $push )->get_data()['state'] );
		$this->assertSame( $before, get_post( $styles )->post_content );
		$this->assertSame( 'skipped', $this->rows( $push )[ $uuid ]['status'] );

		// Approved by an administrator with edit_css: written as staged, CSS included.
		$push = $this->stage( array( $make() ) );
		$this->apply( $push );
		$this->assertStringNotContainsString( 'outline:3px', get_post( $styles )->post_content, 'nothing before the approval' );
		$uuid = array_keys( $this->rows( $push ) )[0];
		$this->assertTrue( user_can( self::$admin, 'edit_css' ) );
		$this->assertSame( 200, $this->decide( $push, array( $uuid => 'approve' ) )->get_status() );
		$done = $this->continue_apply( $push );
		$this->assertSame( 200, $done->get_status(), wp_json_encode( $done->get_data() ) );
		$this->assertSame( 'applied', $done->get_data()['state'] );
		$this->assertStringContainsString( 'outline:3px solid gold', get_post( $styles )->post_content );
		$this->assertSame( 'approve', $this->rows( $push )[ $uuid ]['review'] );
		$this->assertSame( self::$admin, (int) $this->rows( $push )[ $uuid ]['review_by'] );
	}

	// --- F-14 ---------------------------------------------------------------------------------------

	public function test_f14_approve_filter_and_skip_each_do_what_they_say() {
		$approve = $this->page_with( 'F14 approve', 'old' );
		$filter  = $this->page_with( 'F14 filter', 'old' );
		$skip    = $this->page_with( 'F14 skip', 'old' );
		$plain   = $this->page_with( 'F14 plain', 'old' );
		$builder = $this->page_with( 'F14 builder', 'old' );
		$script  = '<p>Hi</p><script>track()</script><img src="x" onerror="e()"><a href="javascript:void(0)">x</a>';
		$iframe  = '<!-- wp:html --><iframe src="https://example.net/embed"></iframe><!-- /wp:html -->';

		$envs   = array(
			$this->content_change( $approve, $script ),
			$this->content_change( $filter, $script ),
			$this->content_change( $skip, $iframe ),
			$this->content_change( $plain, '<p>No markup at all</p>' ),
			$this->envelope(
				'post',
				$builder,
				'push',
				static function ( $data ) {
					$data['meta']['_elementor_data'] = array( '[{"widgetType":"html","settings":{"html":"<script>x()<\/script>"}}]' );
					return $data;
				}
			),
		);
		$push   = $this->stage( $envs );
		$before = $this->state( array( array( 'post', $approve ), array( 'post', $filter ), array( 'post', $skip ), array( 'post', $plain ), array( 'post', $builder ) ) );

		$answers = $this->apply( $push );
		$this->assertSame( 'awaiting_review', end( $answers )['state'] );
		$this->assertSame( 4, end( $answers )['undecided'] );
		$this->assertSame( $before, $this->state( array( array( 'post', $approve ), array( 'post', $filter ), array( 'post', $skip ), array( 'post', $plain ), array( 'post', $builder ) ) ) );

		$uuids = array();
		foreach ( $envs as $env ) {
			$uuids[ $env['live_id'] ] = $env['uuid'];
		}
		$detail = $this->admin( 'GET', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) )->get_data();
		$this->assertSame( 'destination', $detail['side'] );
		$this->assertCount( 4, $detail['held_items'] );
		foreach ( $detail['held_items'] as $item ) {
			$this->assertSame( '', $item['review'], 'nothing pre-chosen' );
			$this->assertNotEmpty( $item['reasons'][0]['excerpt'] );
		}
		$by_uuid = array_column( $detail['held_items'], null, 'uuid' );
		$this->assertTrue( $by_uuid[ $uuids[ $filter ] ]['can_filter'] );
		$this->assertFalse( $by_uuid[ $uuids[ $builder ] ]['can_filter'], 'builder meta is not reached by the HTML filter' );

		// The filter is refused for markup in meta.
		$this->assertSame( 400, $this->decide( $push, array( $uuids[ $builder ] => 'filtered' ) )->get_status() );
		// Continue is refused until every held item is decided.
		$this->assertSame( 200, $this->decide( $push, array( $uuids[ $approve ] => 'approve' ) )->get_status() );
		$this->assertSame( 'review_incomplete', $this->continue_apply( $push )->get_data()['code'] );
		$this->assertSame( 'confirm_mismatch', $this->continue_apply( $push, null, 'staging.example.com' )->get_data()['code'] );

		$this->assertSame(
			200,
			$this->decide(
				$push,
				array(
					$uuids[ $filter ]  => 'filtered',
					$uuids[ $skip ]    => 'skip',
					$uuids[ $builder ] => 'approve',
				)
			)->get_status()
		);
		$done = $this->continue_apply( $push );
		$this->assertSame( 'applied', $done->get_data()['state'], wp_json_encode( $done->get_data() ) );

		$this->assertSame( $script, get_post( $approve )->post_content, 'approved: written unchanged' );
		$filtered = get_post( $filter )->post_content;
		$this->assertSame( wp_kses( $script, 'post' ), $filtered, 'filtered: what kses gives' );
		$this->assertStringNotContainsString( '<script', $filtered );
		$this->assertStringNotContainsString( 'onerror', $filtered );
		$this->assertSame( 'old', get_post( $skip )->post_content, 'skipped: unchanged' );
		$this->assertSame( '<p>No markup at all</p>', get_post( $plain )->post_content, 'items without markup applied normally' );
		$this->assertSame( '[{"widgetType":"html","settings":{"html":"<script>x()<\/script>"}}]', get_post_meta( $builder, '_elementor_data', true ) );

		$rows = $this->rows( $push );
		$this->assertSame( 'skipped', $rows[ $uuids[ $skip ] ]['status'] );
		$this->assertSame( 'review_skipped', $rows[ $uuids[ $skip ] ]['error'] );
		$this->assertSame( 'filtered', $rows[ $uuids[ $filter ] ]['review'] );
		$this->assertSame( 0, $this->applied_without_snapshot() );

		global $wpdb;
		$decided = $wpdb->get_col( $wpdb->prepare( 'SELECT details FROM %i WHERE event = %s AND actor_id = %d', Tables::table_name( 'audit' ), 'held_items_decided', self::$admin ) );
		$this->assertNotEmpty( $decided );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s AND actor_type = %s AND actor_id = %d', Tables::table_name( 'audit' ), 'push_applied', 'user', self::$admin ) ), 'the apply ran from the live admin\'s session' );
	}
}
