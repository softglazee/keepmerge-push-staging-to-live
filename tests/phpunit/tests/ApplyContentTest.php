<?php
/**
 * What the appliers write on live, each through the signed apply route as the receiver user:
 * ID remapping (F-06), URL tokens (F-07), typed values (F-08), menus (F-10), site editor (F-11),
 * options and the widgets unit (F-12, live part), authors (F-13), deletions (F-15) and
 * attachments whose files move into the uploads tree.
 *
 * @package SoftGlaze\SafePush\Tests
 */

require_once __DIR__ . '/class-keepmerge-apply-harness.php';

use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Sync\Exporters\Menus;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Push_Session;

/**
 * @group apply
 */
class ApplyContentTest extends Keepmerge_Apply_Harness {

	const YEAR = '2031/07';

	public function tear_down() {
		$this->remove_tree( $this->base() . '/2031' );
		parent::tear_down();
	}

	private function live_id( $push, $uuid ) {
		return (int) $this->rows( $push )[ $uuid ]['local_object_id'];
	}

	private function done( $push ) {
		$answers = $this->apply( $push );
		$last    = end( $answers );
		$this->assertSame( 'applied', $last['state'], wp_json_encode( $this->rows( $push ) ) );
		return $last;
	}

	// --- F-06 ---------------------------------------------------------------------------------------

	public function test_f06_every_reference_points_to_the_right_live_object() {
		$image   = self::factory()->attachment->create_object( self::YEAR . '/kept.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$image2  = self::factory()->attachment->create_object( self::YEAR . '/kept2.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$parent  = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'F06 parent',
			)
		);
		$pattern = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'F06 pattern',
				'post_content' => '<!-- wp:paragraph --><p>Synced</p><!-- /wp:paragraph -->',
			)
		);
		$nav     = self::factory()->post->create(
			array(
				'post_type'    => 'wp_navigation',
				'post_title'   => 'F06 nav',
				'post_content' => '<!-- wp:navigation-link {"label":"Parent","type":"page","id":' . $parent . ',"kind":"post-type"} /-->',
			)
		);
		$cat     = self::factory()->category->create( array( 'name' => 'F06 category' ) );
		$child   = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_title'   => 'F06 child',
				'post_content' => '<!-- wp:image {"id":' . $image . '} --><figure class="wp-block-image"><img src="x.jpg" class="wp-image-' . $image . '"/></figure><!-- /wp:image -->'
					. '<!-- wp:gallery {"ids":[' . $image . ',' . $image2 . ']} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->'
					. '<!-- wp:block {"ref":' . $pattern . '} /--><!-- wp:navigation {"ref":' . $nav . '} /-->',
				'post_category' => array( $cat ),
			)
		);
		set_post_thumbnail( $child, $image );
		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'F06 front',
				'post_parent' => $parent,
			)
		);

		// Pages, the pattern, the navigation and the category are new; the images exist on live.
		$envs  = $this->new_items(
			array(
				'term'       => array( $cat ),
				'post'       => array( $parent, $child, $page ),
				'pattern'    => array( $pattern ),
				'navigation' => array( $nav ),
			)
		);
		$front = $this->envelope(
			'option',
			'page_on_front',
			'push',
			static function ( $data ) use ( $page ) {
				$data['value'] = $page;
				return $data;
			}
		);
		$front['refs'] = array(
			array(
				'path'    => 'value',
				'kind'    => 'post',
				'id'      => $page,
				'uuid'    => $envs[ $page ]['uuid'],
				'live_id' => 0,
			),
		);
		$push          = $this->stage( array_merge( array_values( $envs ), array( $front ) ) );
		$this->done( $push );

		$l_parent  = $this->live_id( $push, $envs[ $parent ]['uuid'] );
		$l_child   = $this->live_id( $push, $envs[ $child ]['uuid'] );
		$l_page    = $this->live_id( $push, $envs[ $page ]['uuid'] );
		$l_pattern = $this->live_id( $push, $envs[ $pattern ]['uuid'] );
		$l_nav     = $this->live_id( $push, $envs[ $nav ]['uuid'] );
		$l_cat     = $this->live_id( $push, $envs[ $cat ]['uuid'] );
		foreach ( array( array( $parent, $l_parent ), array( $child, $l_child ), array( $pattern, $l_pattern ), array( $nav, $l_nav ), array( $cat, $l_cat ) ) as $pair ) {
			$this->assertNotSame( $pair[0], $pair[1], 'live IDs differ from the source IDs' );
		}

		$this->assertSame( $l_parent, (int) get_post( $l_page )->post_parent );
		$this->assertSame( $image, (int) get_post_thumbnail_id( $l_child ) );
		$content = get_post( $l_child )->post_content;
		$this->assertStringContainsString( '"ref":' . $l_pattern, $content );
		$this->assertStringContainsString( '"ref":' . $l_nav, $content );
		$this->assertStringContainsString( 'wp-image-' . $image, $content );
		$this->assertStringContainsString( '"ids":[' . $image . ',' . $image2 . ']', $content );
		$this->assertStringContainsString( '"id":' . $l_parent, get_post( $l_nav )->post_content, 'navigation link id' );
		$this->assertSame( array( $l_cat ), wp_get_object_terms( $l_child, 'category', array( 'fields' => 'ids' ) ) );
		$this->assertSame( $l_page, (int) get_option( 'page_on_front' ) );
		$this->assertSame( 'F06 category', get_term( $l_cat )->name );
		$this->assertSame( 0, $this->applied_without_snapshot() );
	}

	// --- F-07 ---------------------------------------------------------------------------------------

	public function test_f07_url_tokens_become_lives_own_address_in_every_form() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$env  = $this->envelope(
			'post',
			$page,
			'push',
			static function ( $data ) {
				$data['post_content']           = '<p><a href="{{sgsp:home}}/about/">About</a> <a href="{{sgsp:home:p}}/x/">x</a></p>';
				$data['meta']['km_json']        = array( '{"link":"{{sgsp:home:j}}\/contact\/"}' );
				$data['meta']['km_encoded']     = array( 'u={{sgsp:home:u}}%2Fshop%2F' );
				$data['meta']['km_http_variant'] = array( '{{sgsp:site}}/wp-login.php' );
				return $data;
			}
		);
		$this->done( $this->stage( array( $env ) ) );

		$content = get_post( $page )->post_content;
		$this->assertStringContainsString( 'href="https://example.org/about/"', $content );
		$this->assertStringContainsString( 'href="//example.org/x/"', $content );
		$this->assertSame( '{"link":"https:\/\/example.org\/contact\/"}', get_post_meta( $page, 'km_json', true ) );
		$this->assertSame( 'u=https%3A%2F%2Fexample.org%2Fshop%2F', get_post_meta( $page, 'km_encoded', true ) );
		$this->assertSame( 'https://example.org/wp-login.php', get_post_meta( $page, 'km_http_variant', true ) );
		foreach ( array( $content, get_post_meta( $page, 'km_json', true ) ) as $text ) {
			$this->assertStringNotContainsString( '{{sgsp:', $text );
			$this->assertStringNotContainsString( 'staging.example.com', $text );
		}
	}

	// --- F-08 ---------------------------------------------------------------------------------------

	public function test_f08_typed_values_arrive_with_their_types_and_every_meta_row_unserializes() {
		$page   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$object = new stdClass();
		$object->a = 1;
		$object->b = array( 'x' => 1.5 );
		$values = array(
			'km_nested' => array( 'a' => array( 'b' => array( 1, 2, true, null ) ) ),
			'km_object' => $object,
			'km_float'  => 0.1 + 0.2,
			'km_bool'   => false,
			'km_null'   => null,
			'km_double' => serialize( array( 'inner' => 'v' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test value: a double-serialized meta value.
		);
		$expected = array();
		foreach ( $values as $key => $value ) {
			add_post_meta( $page, $key, wp_slash( $value ) );
			// What the source reads back is what was stored (a float is stored as text, for example).
			$expected[ $key ] = get_post_meta( $page, $key, true );
		}
		// Export with these values (the source decodes them), then remove them from live.
		$env = $this->envelope( 'post', $page );
		foreach ( array_keys( $values ) as $key ) {
			delete_post_meta( $page, $key );
		}
		$env['live_hash'] = $this->live_hash( 'post', $page );
		$this->done( $this->stage( array( $env ) ) );

		foreach ( $expected as $key => $value ) {
			$this->assertSame( var_export( $value, true ), var_export( get_post_meta( $page, $key, true ), true ), $key ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Exact comparison of typed values.
		}
		$this->assertInstanceOf( stdClass::class, get_post_meta( $page, 'km_object', true ) );
		global $wpdb;
		$stored = array();
		foreach ( array_keys( $values ) as $key ) {
			$this->assertTrue( metadata_exists( 'post', $page, $key ), $key );
			$stored[] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $page, $key ) );
		}
		foreach ( $stored as $raw ) {
			$this->assertTrue( ! is_serialized( $raw ) || false !== @unserialize( $raw, array( 'allowed_classes' => false ) ) || 'b:0;' === $raw, $raw ); // phpcs:ignore
		}
	}

	// --- F-10 ---------------------------------------------------------------------------------------

	public function test_f10_a_menu_unit_is_replaced_its_locations_mapped_and_extra_items_trashed() {
		$page  = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Menu target',
			)
		);
		$menu  = wp_create_nav_menu( 'F10 menu' );
		$top   = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'     => 'Top',
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page,
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			)
		);
		$sub   = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'     => 'Sub',
				'menu-item-url'       => 'https://example.net/sub',
				'menu-item-type'      => 'custom',
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => $top,
			)
		);
		$extra = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Live only',
				'menu-item-url'    => 'https://example.net/old',
				'menu-item-type'   => 'custom',
				'menu-item-status' => 'publish',
			)
		);
		$ids   = array_map(
			static function ( $post ) {
				return (int) $post->ID;
			},
			Menus::item_posts( $menu )
		);
		// Staging's menu: the first two items, the custom item renamed.
		$env = $this->envelope(
			'menu',
			$menu,
			'push',
			static function ( $data ) {
				$data['items']                = array_slice( $data['items'], 0, 2 );
				$data['items'][1]['title']    = 'Sub renamed';
				$data['name']                 = 'F10 menu renamed';
				return $data;
			},
			array( 'item_ids' => array_slice( $ids, 0, 2 ) )
		);
		$refs = array();
		foreach ( $env['refs'] as $ref ) {
			if ( 0 !== strpos( $ref['path'], 'items/2/' ) ) {
				$refs[] = $ref;
			}
		}
		$env['refs'] = $refs;

		$mods = 'theme_mods_' . get_stylesheet();
		update_option(
			$mods,
			array(
				'nav_menu_locations' => array( 'primary' => 0 ),
				'custom_css_post_id' => 4242,
			)
		);
		$loc                 = $this->envelope(
			'option',
			$mods,
			'push',
			static function ( $data ) use ( $menu ) {
				$data['value']['nav_menu_locations']['primary'] = $menu;
				unset( $data['value']['custom_css_post_id'] );
				return $data;
			}
		);
		$loc['refs']         = array(
			array(
				'path'    => 'value/nav_menu_locations/primary',
				'kind'    => 'menu',
				'id'      => $menu,
				'uuid'    => $env['uuid'],
				'live_id' => 0,
			),
		);
		$push = $this->stage( array( $env, $loc ) );
		$this->done( $push );

		$this->assertSame( 'F10 menu renamed', get_term( $menu )->name );
		$items = Menus::item_posts( $menu );
		$this->assertCount( 2, $items );
		$this->assertSame( array( 'Top', 'Sub renamed' ), array( $items[0]->post_title, $items[1]->post_title ) );
		$this->assertSame( (string) $page, get_post_meta( $items[0]->ID, '_menu_item_object_id', true ) );
		$this->assertSame( (string) $items[0]->ID, get_post_meta( $items[1]->ID, '_menu_item_menu_item_parent', true ), 'parent inside the unit' );
		$this->assertSame( 'trash', get_post_status( $extra ), 'the live-only item went to the Trash, not deleted' );

		$value = get_option( $mods );
		$this->assertSame( $menu, (int) $value['nav_menu_locations']['primary'] );
		$this->assertSame( 4242, (int) $value['custom_css_post_id'], 'live keeps its own Additional CSS link' );
	}

	// --- F-11 ---------------------------------------------------------------------------------------

	public function test_f11_site_editor_items_resolve_with_their_theme_and_area_terms() {
		// The block theme of the core under test (6.5 ships no twentytwentyfive).
		$theme = wp_get_theme( 'twentytwentyfive' )->exists() ? 'twentytwentyfive' : 'twentytwentyfour';
		switch_theme( $theme );
		$template = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template',
				'post_name'    => 'page-km-landing',
				'post_title'   => 'KM landing',
				'post_content' => '<!-- wp:paragraph --><p>Landing</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		wp_set_object_terms( $template, $theme, 'wp_theme' );
		update_post_meta( $template, 'origin', 'theme' );
		$part = self::factory()->post->create(
			array(
				'post_type'    => 'wp_template_part',
				'post_name'    => 'km-header',
				'post_title'   => 'KM header',
				'post_content' => '<!-- wp:site-title /-->',
				'post_status'  => 'publish',
			)
		);
		wp_set_object_terms( $part, $theme, 'wp_theme' );
		wp_set_object_terms( $part, 'header', 'wp_template_part_area' );
		$pattern_cat = wp_insert_term( 'KM patterns', 'wp_pattern_category' );
		$pattern     = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'KM unsynced',
				'post_content' => '<!-- wp:paragraph --><p>Unsynced</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $pattern, 'wp_pattern_sync_status', 'unsynced' );
		wp_set_object_terms( $pattern, array( (int) $pattern_cat['term_id'] ), 'wp_pattern_category' );

		$tag  = self::factory()->tag->create( array( 'name' => 'km-tag' ) );
		$post = self::factory()->post->create( array( 'post_title' => 'Tagged' ) );
		wp_set_object_terms( $post, array( $tag ), 'post_tag' );

		$envs = $this->new_items(
			array(
				'template'      => array( $template ),
				'template_part' => array( $part ),
				'pattern'       => array( $pattern ),
				'post'          => array( $post ),
			)
		);
		$push = $this->stage( array_values( $envs ) );
		$this->done( $push );

		$l_template = $this->live_id( $push, $envs[ $template ]['uuid'] );
		$l_part     = $this->live_id( $push, $envs[ $part ]['uuid'] );
		$l_pattern  = $this->live_id( $push, $envs[ $pattern ]['uuid'] );
		$l_post     = $this->live_id( $push, $envs[ $post ]['uuid'] );

		$this->assertSame( array( $theme ), wp_get_object_terms( $l_template, 'wp_theme', array( 'fields' => 'names' ) ) );
		$this->assertSame( array( 'header' ), wp_get_object_terms( $l_part, 'wp_template_part_area', array( 'fields' => 'names' ) ) );
		$this->assertSame( 'page-km-landing', get_post( $l_template )->post_name );
		$this->assertSame( 'theme', get_post_meta( $l_template, 'origin', true ) );
		$found = get_block_template( $theme . '//page-km-landing' );
		$this->assertInstanceOf( WP_Block_Template::class, $found, 'the template resolves in the Site Editor' );
		$this->assertSame( $l_template, (int) $found->wp_id );
		$parts = get_block_templates( array( 'area' => 'header' ), 'wp_template_part' );
		$this->assertContains( 'km-header', wp_list_pluck( $parts, 'slug' ) );

		$this->assertSame( 'unsynced', get_post_meta( $l_pattern, 'wp_pattern_sync_status', true ) );
		$this->assertSame( array( (int) $pattern_cat['term_id'] ), wp_get_object_terms( $l_pattern, 'wp_pattern_category', array( 'fields' => 'ids' ) ) );
		$this->assertSame( array( $tag ), wp_get_object_terms( $l_post, 'post_tag', array( 'fields' => 'ids' ) ), 'set by the receiver with wp_set_object_terms()' );
		$this->assertFalse( user_can( $this->receiver, 'assign_terms' ) );

		// A template of a theme live does not have is refused when staged.
		$missing                  = $envs[ $template ];
		$missing['uuid']          = wp_generate_uuid4();
		$missing['data']['theme'] = 'no-such-theme';
		$this->assertSame( 'theme_missing', $this->stage_refusal( $missing ) );
	}

	/** Stages one envelope in its own push and returns live's refusal reason. */
	private function stage_refusal( array $env ) {
		$push = wp_generate_uuid4();
		$this->call(
			'POST',
			'/keepmerge/v1/remote/push',
			(string) wp_json_encode(
				array(
					'push'      => $push,
					'plan_hash' => str_repeat( 'a', 64 ),
					'counts'    => array(
						'create'   => 1,
						'update'   => 0,
						'recreate' => 0,
						'trash'    => 0,
						'adopt'    => 0,
					),
					'items'     => 1,
				)
			)
		);
		$answer = $this->call(
			'POST',
			'/keepmerge/v1/remote/push/' . $push . '/items',
			(string) wp_json_encode(
				array(
					'seq'   => 1,
					'items' => array( $env ),
				)
			)
		)->get_data();
		$this->call( 'POST', '/keepmerge/v1/remote/push/' . $push . '/abort', '{}' );
		return isset( $answer['results'][0]['reason'] ) ? $answer['results'][0]['reason'] : '';
	}

	// --- F-12 (live part) --------------------------------------------------------------------------

	public function test_f12_options_follow_lives_own_allowlist_at_write_time_and_widgets_go_as_one_unit() {
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
		update_option( 'blogdescription', 'Live tagline' );
		update_option( 'km_custom_a', 'live a' );
		update_option( 'widget_text', array( 2 => array( 'title' => 'Old' ), '_multiwidget' => 1 ) );

		$tagline = $this->envelope(
			'option',
			'blogdescription',
			'push',
			static function ( $data ) {
				$data['value'] = 'Staging tagline';
				return $data;
			}
		);
		$custom  = $this->envelope(
			'option',
			'km_custom_a',
			'push',
			static function ( $data ) {
				$data['value'] = 'staging a';
				return $data;
			}
		);
		$widgets = $this->envelope(
			'widgets',
			'widgets',
			'push',
			static function ( $data ) {
				$data['widgets']['widget_text'][2]['title'] = 'New';
				return $data;
			}
		);
		$push    = $this->stage( array( $tagline, $custom, $widgets ) );

		// Live's admin narrows the allowlist after staging: live decides again at write time.
		Settings::save( array_merge( Settings::get(), array( 'custom_options' => array() ) ) );
		Scope::flush();

		$this->done( $push );
		$this->assertSame( 'Staging tagline', get_option( 'blogdescription' ) );
		$this->assertSame( 'live a', get_option( 'km_custom_a' ), 'no longer on live\'s allowlist: refused at write' );
		$rows = $this->rows( $push );
		$this->assertSame( 'option_set', $rows['option:blogdescription']['action'] );
		$this->assertSame( 'skipped', $rows['option:km_custom_a']['status'] );
		$this->assertSame( 'option_not_allowed', $rows['option:km_custom_a']['error'] );
		$this->assertSame( 'New', get_option( 'widget_text' )[2]['title'] );
		$this->assertTrue( $rows['option:blogdescription']['pre_image']['raw']['options']['blogdescription']['exists'] );
		$this->assertSame( 'Live tagline', $rows['option:blogdescription']['pre_image']['raw']['options']['blogdescription']['value'] );
	}

	// --- F-13 ---------------------------------------------------------------------------------------

	public function test_f13_authors_map_by_login_then_email_then_the_pairing_default() {
		$by_login = self::factory()->user->create(
			array(
				'user_login' => 'km-writer',
				'user_email' => 'writer@example.org',
				'role'       => 'author',
			)
		);
		$by_email = self::factory()->user->create(
			array(
				'user_login' => 'km-live-login',
				'user_email' => 'only-email@example.org',
				'role'       => 'author',
			)
		);
		$pages = array();
		$envs  = array();
		foreach ( array(
			'login' => array( 'km-writer', 'other@example.org' ),
			'email' => array( 'km-staging-login', 'only-email@example.org' ),
			'none'  => array( 'km-nobody', 'nobody@example.org' ),
		) as $case => $author ) {
			$pages[ $case ] = self::factory()->post->create( array( 'post_type' => 'page' ) );
			$envs[]         = $this->envelope(
				'post',
				$pages[ $case ],
				'push',
				static function ( $data ) use ( $author ) {
					$data['author'] = array(
						'login' => $author[0],
						'email' => $author[1],
					);
					return $data;
				}
			);
		}
		$this->done( $this->stage( $envs ) );

		$this->assertSame( $by_login, (int) get_post( $pages['login'] )->post_author );
		$this->assertSame( $by_email, (int) get_post( $pages['email'] )->post_author );
		$this->assertSame( self::$admin, (int) get_post( $pages['none'] )->post_author, 'the pairing default author' );
		foreach ( $pages as $id ) {
			$this->assertNotSame( $this->receiver, (int) get_post( $id )->post_author );
		}
	}

	// --- F-15 ---------------------------------------------------------------------------------------

	public function test_f15_trash_on_live_moves_the_page_to_the_trash_after_its_snapshot() {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Deleted on staging',
			)
		);
		$keep = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Kept on live',
			)
		);
		$env  = $this->envelope( 'post', $page, 'trash_on_live' );
		unset( $env['data'], $env['refs'] );
		$env['hash'] = '';
		$push        = $this->stage( array( $env ) );
		$this->done( $push );

		$this->assertSame( 'trash', get_post_status( $page ), 'Trash, never deleted' );
		$this->assertSame( 'publish', get_post_status( $keep ), 'a page without a decision is kept' );
		$row = $this->rows( $push )[ $env['uuid'] ];
		$this->assertSame( 'trashed', $row['action'] );
		$this->assertSame( 'Deleted on staging', $row['pre_image']['form']['post_title'] );
		$this->assertSame( 0, $this->applied_without_snapshot() );
	}

	// --- attachments --------------------------------------------------------------------------------

	/** A scratch attachment with a real file, exported as the source would, then removed. */
	private function new_attachment( $name, $bytes ) {
		$path = self::YEAR . '/' . $name;
		$full = $this->put_file( $path, $bytes );
		$id   = self::factory()->attachment->create_object(
			$path,
			0,
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'KM ' . $name,
			)
		);
		wp_update_attachment_metadata(
			$id,
			array(
				'width'  => 32,
				'height' => 24,
				'file'   => $path,
				'sizes'  => array(),
			)
		);
		update_post_meta( $id, '_wp_attachment_image_alt', 'Alt ' . $name );
		$env = $this->new_items( array( 'attachment' => array( $id ) ) )[ $id ];
		if ( is_file( $full ) ) {
			unlink( $full );
		}
		return array( $id, $env, $path );
	}

	public function test_a_new_attachment_moves_its_committed_file_into_uploads_and_its_page_points_to_it() {
		$bytes                      = $this->jpeg();
		list( $old_id, $env, $path ) = $this->new_attachment( 'km-photo.jpg', $bytes );
		$env['media']               = array(
			array(
				'path' => $path,
				'ref'  => 1,
			),
		);
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$use  = $this->envelope(
			'post',
			$page,
			'push',
			static function ( $data ) use ( $old_id ) {
				$data['featured_image'] = $old_id;
				return $data;
			}
		);
		$use['refs'][] = array(
			'path'    => 'featured_image',
			'kind'    => 'attachment',
			'id'      => $old_id,
			'uuid'    => $env['uuid'],
			'live_id' => 0,
		);
		$push          = $this->stage(
			array( $env, $use ),
			array(
				1 => array(
					'path'       => $path,
					'bytes'      => $bytes,
					'attachment' => $env['uuid'],
				),
			)
		);
		$this->assertFileDoesNotExist( $this->base() . '/' . $path );
		$this->done( $push );

		$live = $this->live_id( $push, $env['uuid'] );
		$this->assertSame( 'attachment', get_post_type( $live ) );
		$this->assertSame( $path, get_post_meta( $live, '_wp_attached_file', true ) );
		$this->assertFileExists( $this->base() . '/' . $path );
		$this->assertSame( hash( 'sha256', $bytes ), hash_file( 'sha256', $this->base() . '/' . $path ) );
		$this->assertSame( 'Alt km-photo.jpg', get_post_meta( $live, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 32, wp_get_attachment_metadata( $live )['width'] );
		$this->assertSame( trailingslashit( wp_upload_dir( null, false )["baseurl"] ) . $path, get_post( $live )->guid );
		$this->assertSame( $live, (int) get_post_thumbnail_id( $page ) );
		$this->assertSame( array(), glob( $this->plugin_dir() . '/tmp/*', GLOB_ONLYDIR ) ?: array(), 'the push\'s temp folder removed at finish' );
	}

	public function test_a_foreign_file_at_the_same_path_gives_a_new_name_and_urls_follow_it() {
		$bytes                      = $this->jpeg( 32, 24, 90 );
		list( $old_id, $env, $path ) = $this->new_attachment( 'km-taken.jpg', $bytes );
		$foreign                    = $this->put_file( $path, 'someone else\'s file' );
		$env['media']               = array(
			array(
				'path' => $path,
				'ref'  => 1,
			),
		);
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$use  = $this->envelope(
			'post',
			$page,
			'push',
			static function ( $data ) use ( $path ) {
				$data['post_content'] = '<p><img src="{{sgsp:home}}/wp-content/uploads/' . $path . '" /></p>';
				return $data;
			}
		);
		$push = $this->stage(
			array( $env, $use ),
			array(
				1 => array(
					'path'       => $path,
					'bytes'      => $bytes,
					'attachment' => $env['uuid'],
				),
			)
		);
		$this->done( $push );

		$live  = $this->live_id( $push, $env['uuid'] );
		$final = get_post_meta( $live, '_wp_attached_file', true );
		$this->assertNotSame( $path, $final );
		$this->assertSame( 'someone else\'s file', file_get_contents( $foreign ), 'the foreign file is untouched' );
		$this->assertSame( hash( 'sha256', $bytes ), hash_file( 'sha256', $this->base() . '/' . $final ) );
		$this->assertStringContainsString( 'https://example.org/wp-content/uploads/' . $final, get_post( $page )->post_content );
		$this->assertContains( 'file_renamed', $this->rows( $push )[ $env['uuid'] ]['transfer']['warnings'] );
	}

	public function test_an_attachment_update_snapshots_its_own_file_before_replacing_it() {
		$path = self::YEAR . '/km-own.jpg';
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
		$env                     = $this->envelope(
			'attachment',
			$id,
			'push',
			static function ( $data ) use ( $new ) {
				$data['files'][0]['size']   = strlen( $new );
				$data['files'][0]['sha256'] = hash( 'sha256', $new );
				return $data;
			}
		);
		$env['media']            = array(
			array(
				'path' => $path,
				'ref'  => 1,
			),
		);
		$push                    = $this->stage(
			array( $env ),
			array(
				1 => array(
					'path'       => $path,
					'bytes'      => $new,
					'attachment' => $env['uuid'],
				),
			)
		);
		$this->done( $push );

		global $wpdb;
		$session = Push_Session::find( $push );
		$file    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE push_id = %d AND object_type = 'file'", Tables::table_name( 'push_items' ), (int) $session['id'] ), ARRAY_A );
		$this->assertSame( 'file_replaced', $file['action'] );
		$this->assertMatchesRegularExpression( '#\Asnapshots/[0-9a-f]{32}/1\.bin\z#', $file['snapshot_file_rel'] );
		$this->assertSame( hash( 'sha256', $old ), $file['pre_hash'] );
		$this->assertSame( hash( 'sha256', $old ), hash_file( 'sha256', $this->plugin_dir() . '/' . $file['snapshot_file_rel'] ), 'byte-identical copy of the replaced file' );
		$this->assertSame( hash( 'sha256', $new ), hash_file( 'sha256', $this->base() . '/' . $path ) );
		$this->assertFileExists( $this->plugin_dir() . '/snapshots/index.php' );
		$this->assertLessThanOrEqual( $file['applied_gmt'], $file['snapshot_gmt'] );
	}
}
