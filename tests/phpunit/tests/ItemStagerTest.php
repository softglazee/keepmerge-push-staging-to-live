<?php
/**
 * Transfer\Item_Stager: received item batches are checked and staged, never applied (T10).
 * Every kind's fields one by one, live's own scope, references, the markup gate.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Normalizer;
use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Transfer\Item_Stager;

/**
 * @group staging
 * @group stager
 */
class ItemStagerTest extends WP_UnitTestCase {

	const PAIRING = array( 'id' => 987 );

	const H = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	public function set_up() {
		parent::set_up();
		Scope::flush();
	}

	public function tear_down() {
		Scope::flush();
		parent::tear_down();
	}

	private function post_data( array $over = array(), array $drop = array() ) {
		$data = array_merge(
			array(
				'post_type'      => 'page',
				'post_title'     => 'A page',
				'post_content'   => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
				'post_excerpt'   => '',
				'post_status'    => 'publish',
				'post_name'      => 'a-page',
				'post_password'  => '',
				'menu_order'     => 0,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'post_date_gmt'  => '2026-10-20 10:00:00',
				'post_parent'    => 0,
				'author'         => array(
					'login' => 'admin',
					'email' => 'admin@example.org',
				),
				'page_template'  => '',
				'featured_image' => 0,
				'terms'          => array(),
				'meta'           => array(),
			),
			$over
		);
		foreach ( $drop as $key ) {
			unset( $data[ $key ] );
		}
		return $data;
	}

	private function envelope( array $data, array $over = array() ) {
		return array_merge(
			array(
				'uuid'    => wp_generate_uuid4(),
				'kind'    => 'post',
				'type'    => isset( $data['post_type'] ) ? $data['post_type'] : 'page',
				'action'  => 'create',
				'hash'    => self::H,
				'live_id' => 0,
				'data'    => $data,
				'refs'    => array(),
			),
			$over
		);
	}

	private function stage( array $envelope, array $known = array() ) {
		$identity = Item_Stager::identity( $envelope );
		$this->assertNotNull( $identity, 'identity' );
		return ( new Item_Stager( self::PAIRING, $known ) )->stage( $identity, $envelope );
	}

	private function codes( array $result ) {
		return array_column( $result['warnings'], 'code' );
	}

	// --- identity --------------------------------------------------------------------------

	public function test_identity_is_read_by_name_and_refused_as_a_whole_when_malformed() {
		$good = $this->envelope( $this->post_data() );
		$this->assertSame( 'update', Item_Stager::identity( array_merge( $good, array( 'action' => 'use_staging' ) ) )['action'] );
		$this->assertSame( 'adopt', Item_Stager::identity( array_merge( $good, array( 'action' => 'record_base' ) ) )['action'] );

		$bad = array(
			'not an array'      => 'x',
			'upper-case uuid'   => array_merge( $good, array( 'uuid' => strtoupper( $good['uuid'] ) ) ),
			'uuid v1'           => array_merge( $good, array( 'uuid' => 'a8098c1a-f86e-11da-bd1a-00112444be1e' ) ),
			'menu item kind'    => array_merge( $good, array( 'kind' => 'menu_item' ) ),
			'unknown action'    => array_merge( $good, array( 'action' => 'delete' ) ),
			'short hash'        => array_merge( $good, array( 'hash' => 'abc' ) ),
			'empty hash create' => array_merge( $good, array( 'hash' => '' ) ),
			'negative live id'  => array_merge( $good, array( 'live_id' => -1 ) ),
			'string live id'    => array_merge( $good, array( 'live_id' => '5' ) ),
			'type pattern'      => array_merge( $good, array( 'type' => 'Page Type' ) ),
			'unknown skip'      => array_merge( $good, array( 'skip' => 'because' ) ),
			'option mismatch'   => array(
				'uuid'   => 'option:blogname',
				'kind'   => 'option',
				'type'   => 'admin_email',
				'action' => 'push',
				'hash'   => self::H,
			),
			'widgets uuid'      => array(
				'uuid'   => 'unit:sidebar',
				'kind'   => 'widgets',
				'type'   => 'widgets',
				'action' => 'push',
				'hash'   => self::H,
			),
			'missing type'      => array_diff_key( $good, array( 'type' => 1 ) ),
		);
		foreach ( $bad as $label => $envelope ) {
			$this->assertNull( Item_Stager::identity( $envelope ), $label );
		}

		// A trashed item may come without its type and hash.
		$trash = Item_Stager::identity( array_merge( $good, array( 'action' => 'trash_on_live', 'hash' => '', 'type' => '' ) ) );
		$this->assertSame( 'trash', $trash['action'] );
	}

	// --- posts: fields one by one ----------------------------------------------------------

	public function test_a_valid_page_is_staged_with_its_fields_and_unknown_keys_dropped() {
		$data            = $this->post_data();
		$data['evil']    = 'x';
		$data['post_id'] = 5;
		$result          = $this->stage( $this->envelope( $data ) );

		$this->assertSame( 'staged', $result['status'] );
		$this->assertArrayNotHasKey( 'evil', $result['data'] );
		$this->assertArrayNotHasKey( 'post_id', $result['data'] );
		$this->assertSame( 'A page', $result['data']['post_title'] );
		$this->assertContains( 'unknown_fields', $this->codes( $result ) );
		$this->assertSame( '2', $result['warnings'][ array_search( 'unknown_fields', $this->codes( $result ), true ) ]['detail'] );
		$this->assertSame( 0, $result['target'] );
	}

	/**
	 * @dataProvider bad_post_fields
	 *
	 * @param string $field Field.
	 * @param mixed  $value Value.
	 */
	public function test_each_post_field_is_validated_on_its_own( $field, $value ) {
		$result = $this->stage( $this->envelope( $this->post_data( array( $field => $value ) ), array( 'type' => 'page' ) ) );
		$this->assertSame( 'refused', $result['status'], $field );
		$this->assertSame( 'invalid_field', $result['reason'] );
		$this->assertSame( $field, $result['field'] );
		$this->assertNull( $result['data'], 'a refused row keeps no content' );
	}

	public function bad_post_fields() {
		return array(
			array( 'post_title', array( '__sgsp' => 'bin', 'hex' => '00' ) ),
			array( 'post_title', 5 ),
			array( 'post_content', array( 'x' ) ),
			array( 'post_status', 'trash' ),
			array( 'post_status', 'auto-draft' ),
			array( 'post_name', '../evil' ),
			array( 'post_name', 'a b' ),
			array( 'post_password', str_repeat( 'p', 256 ) ),
			array( 'menu_order', '1' ),
			array( 'comment_status', 'maybe' ),
			array( 'post_date_gmt', '20 Oct 2026' ),
			array( 'post_parent', -1 ),
			array( 'post_parent', '7' ),
			array( 'author', array( 'login' => '<b>admin</b>' ) ),
			array( 'author', array( 'login' => 'admin', 'email' => 'not-an-email' ) ),
			array( 'author', array( 'login' => 'admin', 'role' => 'administrator' ) ),
			array( 'featured_image', 1.5 ),
			array( 'terms', array( 'Category Name' => array( 1 ) ) ),
			array( 'terms', array( 'category' => array( 'one' ) ) ),
			array( 'meta', array( 'key' => 'not a list' ) ),
			array( 'meta', array( "bad\nkey" => array( 'v' ) ) ),
			array( 'post_type', 'post' ),
		);
	}

	public function test_required_post_fields() {
		foreach ( array( 'post_type', 'post_title', 'post_content', 'post_status' ) as $field ) {
			$result = $this->stage( $this->envelope( $this->post_data( array(), array( $field ) ), array( 'type' => 'page' ) ) );
			$this->assertSame( 'missing_field', $result['reason'], $field );
			$this->assertSame( $field, $result['field'] );
		}
		$this->assertSame( 'invalid_item', $this->stage( $this->envelope( array( 'post_type', 'page' ) ) )['reason'] );
	}

	public function test_post_formats_must_exist() {
		$post = $this->post_data( array( 'post_type' => 'post', 'format' => 'aside' ) );
		$this->assertSame( 'staged', $this->stage( $this->envelope( $post ) )['status'] );
		$post['format'] = 'evil';
		$this->assertSame( 'invalid_field', $this->stage( $this->envelope( $post ) )['reason'] );
	}

	// --- S-35: post types and taxonomies ------------------------------------------------------

	/**
	 * @dataProvider denied_types
	 *
	 * @param string $type Post type.
	 */
	public function test_s35_internal_order_coupon_and_font_types_are_refused( $type ) {
		$result = $this->stage( $this->envelope( $this->post_data( array( 'post_type' => $type ) ) ) );
		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'type_denied', $result['reason'], $type );
	}

	public function denied_types() {
		return array(
			array( 'customize_changeset' ),
			array( 'user_request' ),
			array( 'oembed_cache' ),
			array( 'revision' ),
			array( 'custom_css' ),
			array( 'wp_font_family' ),
			array( 'wp_font_face' ),
			array( 'wp_font_anything' ),
			array( 'shop_order' ),
			array( 'shop_order_placehold' ),
			array( 'shop_order_refund' ),
			array( 'shop_order_custom' ),
			array( 'shop_subscription' ),
			array( 'shop_coupon' ),
		);
	}

	public function test_types_live_does_not_include_are_refused() {
		$this->assertSame( 'type_not_included', $this->stage( $this->envelope( $this->post_data( array( 'post_type' => 'nav_menu_item' ) ) ) )['reason'] );
		$this->assertSame( 'type_not_included', $this->stage( $this->envelope( $this->post_data( array( 'post_type' => 'no_such_type' ) ) ) )['reason'] );
		// A pattern sent as kind post.
		$this->assertSame( 'type_not_included', $this->stage( $this->envelope( $this->post_data( array( 'post_type' => 'wp_block' ) ) ) )['reason'] );

		// A type live's admin skipped in Settings.
		Settings::save( array( 'skip_post_types' => array( 'page' ) ) );
		Scope::flush();
		$this->assertSame( 'type_not_included', $this->stage( $this->envelope( $this->post_data() ) )['reason'] );
	}

	public function test_taxonomies_must_be_included_on_live() {
		$term = array(
			'taxonomy'    => 'category',
			'name'        => 'News',
			'slug'        => 'news',
			'description' => '',
			'parent'      => 0,
			'meta'        => array(),
		);
		$env  = array(
			'uuid'   => wp_generate_uuid4(),
			'kind'   => 'term',
			'type'   => 'category',
			'action' => 'create',
			'hash'   => self::H,
			'data'   => $term,
		);
		$this->assertSame( 'staged', $this->stage( $env )['status'] );

		foreach ( array( 'nav_menu', 'wp_theme', 'wp_template_part_area', 'link_category', 'no_such_taxonomy' ) as $taxonomy ) {
			$env['type']             = $taxonomy;
			$env['data']['taxonomy'] = $taxonomy;
			$this->assertSame( 'taxonomy_not_included', $this->stage( $env )['reason'], $taxonomy );
		}

		// The taxonomy inside the data must be the one checked.
		$env['type']             = 'category';
		$env['data']['taxonomy'] = 'post_tag';
		$this->assertSame( 'invalid_field', $this->stage( $env )['reason'] );
	}

	public function test_term_assignments_in_taxonomies_live_does_not_include_are_dropped() {
		$post   = $this->post_data(
			array(
				'post_type' => 'post',
				'terms'     => array(
					'category' => array( 1 ),
					'wp_theme' => array( 9 ),
				),
			)
		);
		$result = $this->stage( $this->envelope( $post ) );
		$this->assertSame( 'staged', $result['status'] );
		$this->assertSame( array( 'category' => array( 1 ) ), $result['data']['terms'] );
		$this->assertContains( 'taxonomy_not_included', $this->codes( $result ) );
	}

	// --- S-33 and F-12: options ---------------------------------------------------------------

	private function option( $name, $value = 'x', $action = 'push' ) {
		return array(
			'uuid'   => 'option:' . $name,
			'kind'   => 'option',
			'type'   => $name,
			'action' => $action,
			'hash'   => self::H,
			'data'   => array(
				'name'  => $name,
				'value' => $value,
			),
		);
	}

	public function test_s33_denylisted_options_are_refused_and_a_filter_cannot_lift_the_denylist() {
		global $wpdb;
		$denied = array( 'admin_email', 'users_can_register', 'default_role', $wpdb->prefix . 'user_roles', 'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'cron', 'auth_key', 'softglaze_sp_settings', 'woocommerce_stripe_settings', 'my_api_key', 'some_license' );

		$remove = static function () {
			return array();
		};
		add_filter( 'softglaze_sp_option_denylist', $remove, 999 );
		Scope::flush();
		foreach ( $denied as $name ) {
			$result = $this->stage( $this->option( $name ) );
			$this->assertSame( 'refused', $result['status'], $name );
			$this->assertSame( 'option_denied', $result['reason'], $name );
		}
		remove_filter( 'softglaze_sp_option_denylist', $remove, 999 );

		$this->assertSame( 'staged', $this->stage( $this->option( 'blogname', 'New name' ) )['status'] );
	}

	public function test_f12_live_allowlist_is_authoritative() {
		// On the source's allowlist perhaps, not on live's: refused here.
		update_option( 'kmfx_only_staging', 'x' );
		$result = $this->stage( $this->option( 'kmfx_only_staging' ) );
		$this->assertSame( 'option_not_allowed', $result['reason'] );

		// Once live's own admin adds it, live takes it.
		Settings::save( array( 'custom_options' => array( 'kmfx_*' ) ) );
		Scope::flush();
		$this->assertSame( 'staged', $this->stage( $this->option( 'kmfx_only_staging' ) )['status'] );

		// A group live switched off.
		Settings::save( array( 'option_groups' => array( 'site_identity' => false ) ) );
		Scope::flush();
		$this->assertSame( 'option_not_allowed', $this->stage( $this->option( 'blogname' ) )['reason'] );
	}

	public function test_option_create_and_update_follow_lives_state() {
		$this->assertSame( 'target_exists', $this->stage( $this->option( 'blogname', 'x', 'create' ) )['reason'] );
		Settings::save( array( 'custom_options' => array( 'kmfx_new' ) ) );
		Scope::flush();
		$this->assertSame( 'target_missing', $this->stage( $this->option( 'kmfx_new', 'x', 'push' ) )['reason'] );
		$this->assertSame( 'staged', $this->stage( $this->option( 'kmfx_new', 'x', 'create' ) )['status'] );
	}

	// --- S-36: page template and attachment paths -----------------------------------------

	public function test_s36_page_template_outside_lives_theme_is_dropped_with_a_warning() {
		foreach ( array( '../../../../wp-config.php', 'templates/missing.php', 'page-not-in-theme' ) as $template ) {
			$result = $this->stage( $this->envelope( $this->post_data( array( 'page_template' => $template ) ) ) );
			$this->assertSame( 'staged', $result['status'], $template );
			$this->assertArrayNotHasKey( 'page_template', $result['data'] );
			$this->assertContains( 'page_template_dropped', $this->codes( $result ) );
		}
		foreach ( array( '', 'default' ) as $template ) {
			$result = $this->stage( $this->envelope( $this->post_data( array( 'page_template' => $template ) ) ) );
			$this->assertSame( $template, $result['data']['page_template'] );
		}

		// A block template slug live has is kept (the block theme of the core under test: 6.5 ships
		// no twentytwentyfive; both themes have page-no-title).
		switch_theme( wp_get_theme( 'twentytwentyfive' )->exists() ? 'twentytwentyfive' : 'twentytwentyfour' );
		$result = $this->stage( $this->envelope( $this->post_data( array( 'page_template' => 'page-no-title' ) ) ) );
		$this->assertSame( 'page-no-title', $result['data']['page_template'] );

		// _wp_page_template in meta never travels: dropped.
		$result = $this->stage( $this->envelope( $this->post_data( array( 'meta' => array( '_wp_page_template' => array( '../../../../wp-config.php' ) ) ) ) ) );
		$this->assertSame( 'staged', $result['status'] );
		$this->assertSame( array(), $result['data']['meta'] );
		$this->assertContains( 'page_template_dropped', $this->codes( $result ) );
	}

	private function attachment( array $over = array() ) {
		return array(
			'uuid'   => wp_generate_uuid4(),
			'kind'   => 'attachment',
			'type'   => 'attachment',
			'action' => 'create',
			'hash'   => self::H,
			'data'   => array_merge(
				array(
					'post_type'     => 'attachment',
					'post_title'    => 'Photo',
					'post_excerpt'  => '',
					'post_content'  => '',
					'post_name'     => 'photo',
					'post_status'   => 'inherit',
					'post_date_gmt' => '2026-10-20 10:00:00',
					'post_parent'   => 0,
					'author'        => array( 'login' => 'admin' ),
					'mime_type'     => 'image/jpeg',
					'file'          => '2026/10/photo.jpg',
					'metadata'      => array(
						'width'  => 800,
						'height' => 600,
						'file'   => '2026/10/photo.jpg',
						'sizes'  => array(
							'thumbnail' => array(
								'file'      => 'photo-150x150.jpg',
								'width'     => 150,
								'height'    => 150,
								'mime-type' => 'image/jpeg',
							),
						),
					),
					'alt'           => '',
					'files'         => array(
						array(
							'path'   => '2026/10/photo.jpg',
							'size'   => 1000,
							'sha256' => str_repeat( 'b', 64 ),
						),
					),
					'meta'          => array(),
				),
				$over
			),
		);
	}

	/**
	 * A stager whose push has these file rows (ref => status, path, size, sha256, final).
	 *
	 * @param array $envelope Envelope.
	 * @param array $files    ref => array( status, path, size, sha256, final ).
	 * @return array
	 */
	private function stage_with_files( array $envelope, array $files ) {
		$rows     = array();
		foreach ( $files as $ref => $file ) {
			$rows[ $ref ] = array(
				'id'       => $ref,
				'ref'      => $ref,
				'status'   => $file[0],
				'decision' => $file[1] === $file[4] ? 'need' : 'renamed',
				'error'    => '',
				't'        => array(
					'path'   => $file[1],
					'size'   => $file[2],
					'sha256' => $file[3],
					'final'  => $file[4],
				),
			);
		}
		$identity = Item_Stager::identity( $envelope );
		$this->assertNotNull( $identity );
		$stager = new Item_Stager(
			self::PAIRING,
			array(),
			static function ( array $refs ) use ( $rows ) {
				return array_intersect_key( $rows, array_flip( $refs ) );
			}
		);
		return $stager->stage( $identity, $envelope );
	}

	public function test_attachments_are_staged_against_the_files_of_the_push() {
		$sha  = str_repeat( 'b', 64 );
		$env  = $this->attachment();
		$env['media'] = array(
			array(
				'path' => '2026/10/photo.jpg',
				'ref'  => 7,
			),
		);

		// Committed under a new name on live: staged, with the final path for the apply.
		$result = $this->stage_with_files( $env, array( 7 => array( 'committed', '2026/10/photo.jpg', 1000, $sha, '2026/10/photo-1.jpg' ) ) );
		$this->assertSame( 'staged', $result['status'] );
		$this->assertSame( 'ready', $result['media']['state'] );
		$this->assertSame(
			array(
				array(
					'path'   => '2026/10/photo.jpg',
					'final'  => '2026/10/photo-1.jpg',
					'ref'    => 7,
					'status' => 'committed',
				),
			),
			$result['media']['files']
		);

		// Live had the same file already.
		$this->assertSame( 'staged', $this->stage_with_files( $env, array( 7 => array( 'have', '2026/10/photo.jpg', 1000, $sha, '2026/10/photo.jpg' ) ) )['status'] );

		// Not committed, refused at commit, another file, or no map at all: refused.
		$cases = array(
			'need'          => array( array( 7 => array( 'need', '2026/10/photo.jpg', 1000, $sha, '2026/10/photo.jpg' ) ), 'media_missing' ),
			'refused'       => array( array( 7 => array( 'refused', '2026/10/photo.jpg', 1000, $sha, '2026/10/photo.jpg' ) ), 'file_refused' ),
			'other hash'    => array( array( 7 => array( 'committed', '2026/10/photo.jpg', 1000, str_repeat( 'c', 64 ), '2026/10/photo.jpg' ) ), 'media_missing' ),
			'other size'    => array( array( 7 => array( 'committed', '2026/10/photo.jpg', 999, $sha, '2026/10/photo.jpg' ) ), 'media_missing' ),
			'other path'    => array( array( 7 => array( 'committed', '2026/10/other.jpg', 1000, $sha, '2026/10/other.jpg' ) ), 'media_missing' ),
			'no file row'   => array( array(), 'media_missing' ),
		);
		foreach ( $cases as $label => $case ) {
			$result = $this->stage_with_files( $env, $case[0] );
			$this->assertSame( 'refused', $result['status'], $label );
			$this->assertSame( $case[1], $result['reason'], $label );
			$this->assertNull( $result['data'], $label );
		}
		unset( $env['media'] );
		$this->assertSame( 'media_missing', $this->stage_with_files( $env, array( 7 => array( 'committed', '2026/10/photo.jpg', 1000, $sha, '2026/10/photo.jpg' ) ) )['reason'], 'no map' );

		// Without the files of the push an attachment is never staged.
		$this->assertSame( 'media_missing', $this->stage( $this->attachment() )['reason'] );
	}

	public function test_an_attachment_lists_only_its_own_files_and_needs_its_main_file() {
		$sha = str_repeat( 'b', 64 );

		// A file its metadata does not name.
		$env = $this->attachment(
			array(
				'files' => array(
					array( 'path' => '2026/10/photo.jpg', 'size' => 1000, 'sha256' => $sha ),
					array( 'path' => '2026/10/elsewhere.jpg', 'size' => 10, 'sha256' => $sha ),
				),
			)
		);
		$this->assertSame( 'invalid_field', $this->stage_with_files( $env, array() )['reason'] );

		// The main file missing on the source.
		$env = $this->attachment( array( 'files' => array( array( 'path' => '2026/10/photo.jpg', 'size' => -1, 'sha256' => '' ) ) ) );
		$result = $this->stage_with_files( $env, array() );
		$this->assertSame( 'file_missing', $result['reason'] );
		$this->assertSame( 'file', $result['field'] );

		// A size missing on the source: staged with a warning.
		$env          = $this->attachment(
			array(
				'files' => array(
					array( 'path' => '2026/10/photo.jpg', 'size' => 1000, 'sha256' => $sha ),
					array( 'path' => '2026/10/photo-150x150.jpg', 'size' => -1, 'sha256' => '' ),
				),
			)
		);
		$env['media'] = array( array( 'path' => '2026/10/photo.jpg', 'ref' => 1 ) );
		$result       = $this->stage_with_files( $env, array( 1 => array( 'committed', '2026/10/photo.jpg', 1000, $sha, '2026/10/photo.jpg' ) ) );
		$this->assertSame( 'staged', $result['status'] );
		$this->assertContains( 'size_file_missing', $this->codes( $result ) );

		// A malformed map.
		foreach ( array( 'x', array( array( 'path' => '2026/10/photo.jpg', 'ref' => '1' ) ), array( array( 'path' => 1, 'ref' => 1 ) ), array( 'a' => array( 'path' => 'x', 'ref' => 1 ) ) ) as $map ) {
			$env['media'] = $map;
			$this->assertSame( 'invalid_field', $this->stage_with_files( $env, array() )['reason'] );
		}
	}

	public function test_s36_attachment_paths_are_refused() {
		$cases = array(
			'file'                    => array( 'file' => '../../wp-config.php' ),
			'metadata.file'           => array(
				'metadata' => array( 'file' => '../../wp-config.php' ),
			),
			'metadata.sizes'          => array(
				'metadata' => array(
					'sizes' => array( 'thumbnail' => array( 'file' => '../../../shell.php' ) ),
				),
			),
			'metadata.original_image' => array(
				'metadata' => array( 'original_image' => 'x.phtml' ),
			),
			'files'                   => array(
				'files' => array(
					array(
						'path'   => '.htaccess',
						'size'   => 1,
						'sha256' => '',
					),
				),
			),
		);
		foreach ( $cases as $field => $over ) {
			$result = $this->stage( $this->attachment( $over ) );
			$this->assertSame( 'refused', $result['status'], $field );
			$this->assertSame( 'path_refused', $result['reason'], $field );
			$this->assertSame( $field, $result['field'] );
		}

		$this->assertSame( 'mime_refused', $this->stage( $this->attachment( array( 'mime_type' => 'text/html' ) ) )['reason'] );
		$this->assertSame( 'invalid_field', $this->stage( $this->attachment( array( 'post_status' => 'publish' ) ) )['reason'] );
	}

	public function test_attachment_paths_as_meta_refuse_the_item() {
		foreach ( array( '_wp_attached_file', '_wp_attachment_metadata' ) as $key ) {
			$result = $this->stage( $this->envelope( $this->post_data( array( 'meta' => array( $key => array( '../../wp-config.php' ) ) ) ) ) );
			$this->assertSame( 'reserved_meta', $result['reason'], $key );
		}
	}

	// --- meta keys ------------------------------------------------------------------------------

	public function test_meta_keys_excluded_on_live_are_dropped() {
		Settings::save( array( 'excluded_meta' => array( 'kmfx_live_only_*' ) ) );
		Scope::flush();
		$meta   = array(
			'_edit_lock'        => array( '1:1' ),
			'_thumbnail_id'     => array( 5 ),
			'_softglaze_sp_uuid' => array( wp_generate_uuid4() ),
			'_customer_user'    => array( 3 ),
			'my_api_key'        => array( 'k' ),
			'kmfx_live_only_a'  => array( 'x' ),
			'_wp_attachment_image_alt' => array( 'alt' ),
			'subtitle'          => array( 'Kept' ),
		);
		$result = $this->stage( $this->envelope( $this->post_data( array( 'meta' => $meta ) ) ) );
		$this->assertSame( 'staged', $result['status'] );
		$this->assertSame( array( 'subtitle' => array( 'Kept' ) ), $result['data']['meta'] );
		$this->assertSame( '7', $result['warnings'][ array_search( 'meta_excluded', $this->codes( $result ), true ) ]['detail'] );
	}

	// --- S-73: received values are typed JSON only --------------------------------------------

	public function test_s73_serialized_text_is_refused_and_object_markers_are_refused() {
		// Serialized PHP text as a plain string is never staged, so it is never stored for other code
		// to unserialize (KM-02; it used to be kept as a string).
		$text   = 'O:8:"stdClass":0:{}';
		$result = $this->stage( $this->envelope( $this->post_data( array( 'meta' => array( 'kmfx_ser' => array( $text, 'a:1:{i:0;O:8:"WP_Theme":0:{}}' ) ) ) ) ) );
		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'invalid_value', $result['reason'] );
		$this->assertSame( 'serialized_string', $result['field'] );
		$this->assertNull( $result['data'] );
		$this->assertIsString( Normalizer::decode( $text, Normalizer::local_origins() ), 'decode itself never unserializes' );

		$object = array(
			'__sgsp' => 'object',
			'class'  => 'WP_Theme',
		);
		$result = $this->stage( $this->envelope( $this->post_data( array( 'meta' => array( 'kmfx_obj' => array( $object ) ) ) ) ) );
		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'invalid_value', $result['reason'] );
		$this->assertSame( 'object', $result['field'] );

		$result = $this->stage( $this->option( 'blogname', $object ) );
		$this->assertSame( 'invalid_value', $result['reason'] );

		// Unknown markers, raw floats and markers with extra keys are refused too.
		foreach ( array( array( '__sgsp' => 'php' ), array( '__sgsp' => 'float', 'v' => '1.5', 'x' => 1 ), 1.5 ) as $value ) {
			$this->assertSame( 'invalid_value', $this->stage( $this->option( 'blogname', array( 'a' => $value ) ) )['reason'] );
		}

		// A stdClass marker with plain properties is data, not code.
		$plain = array(
			'__sgsp' => 'object',
			'props'  => array( 'a' => 1 ),
		);
		$this->assertSame( 'staged', $this->stage( $this->option( 'blogname', $plain ) )['status'] );
	}

	// --- S-81: the markup gate holds active markup -------------------------------------------

	public function test_s81_active_markup_in_meta_options_terms_and_content_is_held() {
		$cases = array(
			'script'        => array( 'kmfx' => array( '<script>alert(1)</script>' ) ),
			'onload'        => array( 'kmfx' => array( '<img src=x onload=alert(1)>' ) ),
			'javascript'    => array( 'kmfx' => array( 'javascript:alert(1)' ) ),
			'url-encoded'   => array( 'kmfx' => array( '%3Cscript%3Ealert(1)%3C/script%3E' ) ),
			'entity'        => array( 'kmfx' => array( '&lt;script&gt;alert(1)&lt;/script&gt;' ) ),
			'nested JSON'   => array( '_elementor_data' => array( '[{"elements":[{"settings":{"html":"<iframe src=\"https://x.test\"></iframe>"}}]}]' ) ),
			'double JSON'   => array( 'kmfx' => array( wp_json_encode( array( 'inner' => wp_json_encode( array( 'html' => '<script>x</script>' ) ) ) ) ) ),
		);
		foreach ( $cases as $label => $meta ) {
			$result = $this->stage( $this->envelope( $this->post_data( array( 'meta' => $meta ) ) ) );
			$this->assertSame( 'held', $result['status'], $label );
			$this->assertNotEmpty( $result['gate'], $label );
			$this->assertSame( 'meta', substr( $result['gate'][0]['field'], 0, 4 ), $label );
			$this->assertNotNull( $result['data'], 'held items keep their content for the review' );
		}

		$content = $this->stage( $this->envelope( $this->post_data( array( 'post_content' => '<p>Hi</p><script>alert(1)</script>' ) ) ) );
		$this->assertSame( 'held', $content['status'] );
		$this->assertSame( 'post_content', $content['gate'][0]['field'] );

		$option = $this->stage( $this->option( 'blogname', 'Shop <script>x</script>' ) );
		$this->assertSame( 'held', $option['status'] );

		$term = $this->stage(
			array(
				'uuid'   => wp_generate_uuid4(),
				'kind'   => 'term',
				'type'   => 'category',
				'action' => 'create',
				'hash'   => self::H,
				'data'   => array(
					'taxonomy' => 'category',
					'name'     => 'News',
					'slug'     => 'news',
					'meta'     => array( 'kmfx' => array( '<svg onload=alert(1)>' ) ),
				),
			)
		);
		$this->assertSame( 'held', $term['status'] );

		// Ordinary content is not held.
		$this->assertSame( 'staged', $this->stage( $this->envelope( $this->post_data( array( 'meta' => array( 'kmfx' => array( 'Prices from 5 & up', '{"title":"Plain"}' ) ) ) ) ) )['status'] );
	}

	// --- targets on live ---------------------------------------------------------------------

	public function test_targets_resolve_inside_lives_scope() {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$update = $this->stage( $this->envelope( $this->post_data(), array( 'action' => 'push', 'live_id' => $page ) ) );
		$this->assertSame( 'staged', $update['status'] );
		$this->assertSame( $page, $update['target'] );

		$this->assertSame( 'target_missing', $this->stage( $this->envelope( $this->post_data(), array( 'action' => 'push', 'live_id' => 999999 ) ) )['reason'] );

		// A hint at an object of another type is not followed.
		$post = self::factory()->post->create();
		$this->assertSame( 'target_missing', $this->stage( $this->envelope( $this->post_data(), array( 'action' => 'push', 'live_id' => $post ) ) )['reason'] );

		// Creating an item live already has (by UUID).
		$uuid = wp_generate_uuid4();
		update_post_meta( $page, Identity::META_KEY, $uuid );
		$this->assertSame( 'target_exists', $this->stage( $this->envelope( $this->post_data(), array( 'uuid' => $uuid ) ) )['reason'] );

		// Trash and adopt carry no content.
		$trash = $this->stage( $this->envelope( array(), array( 'action' => 'trash_on_live', 'hash' => '', 'type' => '', 'live_id' => $post ) ) );
		$this->assertSame( 'staged', $trash['status'] );
		$this->assertNull( $trash['data'] );
		$adopt = $this->stage( $this->envelope( array(), array( 'action' => 'record_base', 'live_id' => $page ) ) );
		$this->assertSame( 'staged', $adopt['status'] );

		// Only posts are trashed.
		$this->assertSame(
			'action_not_allowed',
			$this->stage(
				array(
					'uuid'    => wp_generate_uuid4(),
					'kind'    => 'term',
					'type'    => 'category',
					'action'  => 'trash_on_live',
					'hash'    => '',
					'live_id' => 1,
				)
			)['reason']
		);

		// A source's stub is recorded as refused with its reason.
		$this->assertSame( 'changed_since_plan', $this->stage( $this->envelope( $this->post_data(), array( 'skip' => 'changed_since_plan' ) ) )['reason'] );
	}

	// --- references ----------------------------------------------------------------------------

	public function test_references_resolve_to_this_push_to_live_or_wait_for_the_final_pass() {
		$self    = wp_generate_uuid4();
		$sibling = wp_generate_uuid4();
		$later   = wp_generate_uuid4();
		$uuid_on = wp_generate_uuid4();
		$page    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $page, Identity::META_KEY, $uuid_on );
		$changeset = self::factory()->post->create( array( 'post_type' => 'customize_changeset' ) );

		$refs   = array(
			array( 'path' => 'post_parent', 'kind' => 'post', 'id' => 4, 'uuid' => $uuid_on ),
			array( 'path' => 'terms/category/0', 'kind' => 'term', 'id' => 1, 'uuid' => wp_generate_uuid4(), 'live_id' => 1 ),
			array( 'path' => 'post_content/blocks/0/ref', 'kind' => 'pattern', 'id' => 8, 'uuid' => $sibling ),
			array( 'path' => 'meta/x/0', 'kind' => 'post', 'id' => 9, 'uuid' => $later ),
			array( 'path' => 'meta/y/0', 'kind' => 'post', 'id' => 10, 'uuid' => $self ),
			array( 'path' => 'meta/z/0', 'kind' => 'post', 'id' => 11, 'uuid' => wp_generate_uuid4(), 'live_id' => $changeset ),
		);
		$result = $this->stage(
			$this->envelope( $this->post_data( array( 'post_parent' => 4 ) ), array( 'uuid' => $self, 'refs' => $refs ) ),
			array( $sibling => array( 'pattern', 'staged' ) )
		);
		$this->assertSame( 'staged', $result['status'] );
		$this->assertSame( array( 'live', 'live', 'push', 'pending', 'self', 'pending' ), array_column( $result['refs'], 'res' ) );
		$this->assertSame( $page, $result['refs'][0]['live_id'] );
		$this->assertSame( 1, $result['refs'][1]['live_id'] );
		$this->assertNotContains( 'unmapped_reference', $this->codes( $result ) );

		// A structural ID without a reference is reported.
		$this->assertContains( 'unmapped_reference', $this->codes( $this->stage( $this->envelope( $this->post_data( array( 'post_parent' => 77 ) ) ) ) ) );

		// Malformed references refuse the item.
		foreach ( array( array( 'path' => 'x', 'kind' => 'user', 'id' => 1, 'uuid' => $later ), array( 'path' => 'x', 'kind' => 'post', 'id' => 0, 'uuid' => $later ), array( 'path' => '', 'kind' => 'post', 'id' => 1, 'uuid' => $later ), 'x' ) as $bad ) {
			$this->assertSame( 'invalid_refs', $this->stage( $this->envelope( $this->post_data(), array( 'refs' => array( $bad ) ) ) )['reason'] );
		}
	}

	public function test_final_pass_resolves_pending_references_and_cascades_refusals() {
		$a = wp_generate_uuid4();
		$b = wp_generate_uuid4();
		$c = wp_generate_uuid4();
		$d = wp_generate_uuid4();
		$x = wp_generate_uuid4();

		$ref  = function ( $uuid, $res, $kind = 'post' ) {
			return array(
				'path' => 'meta/k/0',
				'kind' => $kind,
				'id'   => 3,
				'uuid' => $uuid,
				'res'  => $res,
			);
		};
		$rows = array(
			$a => array( 'kind' => 'post', 'status' => 'staged', 'refs' => array( $ref( $b, 'pending' ) ) ),
			$b => array( 'kind' => 'post', 'status' => 'held', 'refs' => array() ),
			$c => array( 'kind' => 'post', 'status' => 'staged', 'refs' => array( $ref( $x, 'pending' ) ) ),
			$d => array( 'kind' => 'post', 'status' => 'staged', 'refs' => array( $ref( $c, 'push' ) ) ),
		);
		$changed = Item_Stager::final_pass( $rows );

		$this->assertFalse( $changed[ $a ]['refused'] );
		$this->assertSame( 'push', $changed[ $a ]['refs'][0]['res'] );
		$this->assertTrue( $changed[ $c ]['refused'], 'names nothing in the push' );
		$this->assertTrue( $changed[ $d ]['refused'], 'names a refused row' );
		$this->assertArrayNotHasKey( $b, $changed );

		// A reference of the wrong kind does not resolve.
		$rows = array(
			$a => array( 'kind' => 'post', 'status' => 'staged', 'refs' => array( $ref( $b, 'pending', 'attachment' ) ) ),
			$b => array( 'kind' => 'post', 'status' => 'staged', 'refs' => array() ),
		);
		$this->assertTrue( Item_Stager::final_pass( $rows )[ $a ]['refused'] );
	}

	// --- site editor, menus, widgets, products ------------------------------------------------

	private function site_editor( $kind, $type, array $data ) {
		return array(
			'uuid'   => wp_generate_uuid4(),
			'kind'   => $kind,
			'type'   => $type,
			'action' => 'create',
			'hash'   => self::H,
			'data'   => array_merge(
				array(
					'post_type'    => $type,
					'post_name'    => 'header-two',
					'post_title'   => 'Header two',
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
				),
				$data
			),
		);
	}

	public function test_site_editor_items_need_lives_theme() {
		// The block theme of the core under test (6.5 ships no twentytwentyfive).
		$theme = wp_get_theme( 'twentytwentyfive' )->exists() ? 'twentytwentyfive' : 'twentytwentyfour';
		$part = $this->site_editor( 'template_part', 'wp_template_part', array( 'theme' => $theme, 'area' => 'header', 'origin' => '' ) );
		$this->assertSame( 'staged', $this->stage( $part )['status'] );

		$part['data']['theme'] = 'no-such-theme';
		$this->assertSame( 'theme_missing', $this->stage( $part )['reason'] );
		$part['data']['theme'] = '../' . $theme;
		$this->assertSame( 'invalid_field', $this->stage( $part )['reason'] );
		$part['data']['theme'] = $theme;
		$part['data']['area']  = 'evil-area';
		$this->assertSame( 'invalid_field', $this->stage( $part )['reason'] );

		$styles = $this->site_editor(
			'global_styles',
			'wp_global_styles',
			array(
				'post_name'    => 'wp-global-styles-' . $theme,
				'post_content' => '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"color":{"text":"#111"}}}',
				'theme'        => $theme,
			)
		);
		$this->assertSame( 'staged', $this->stage( $styles )['status'] );
		$styles['data']['post_content'] = '{"version":3,"isGlobalStylesUserThemeJSON":true,"styles":{"css":"body{color:red}"}}';
		$this->assertSame( 'held', $this->stage( $styles )['status'] );
		$styles['data']['post_content'] = 'not json';
		$this->assertSame( 'invalid_field', $this->stage( $styles )['reason'] );
		$styles['data']['post_content'] = '{}';
		$styles['data']['post_name']    = 'wp-global-styles-other';
		$this->assertSame( 'invalid_field', $this->stage( $styles )['reason'] );

		$pattern = $this->site_editor( 'pattern', 'wp_block', array( 'sync_status' => 'unsynced', 'terms' => array() ) );
		$this->assertSame( 'staged', $this->stage( $pattern )['status'] );
		$pattern['data']['sync_status'] = 'evil';
		$this->assertSame( 'invalid_field', $this->stage( $pattern )['reason'] );
	}

	public function test_menus_validate_each_item_and_drop_item_field_meta() {
		$parent = wp_generate_uuid4();
		$menu   = array(
			'uuid'   => wp_generate_uuid4(),
			'kind'   => 'menu',
			'type'   => 'nav_menu',
			'action' => 'create',
			'hash'   => self::H,
			'data'   => array(
				'name'        => 'Main',
				'slug'        => 'main',
				'description' => '',
				'items'       => array(
					array(
						'type'      => 'custom',
						'object'    => 'custom',
						'object_id' => 12,
						'url'       => '{{sgsp:home}}/about/',
						'title'     => 'About',
						'classes'   => array( '' ),
						'target'    => '',
						'parent'    => 0,
						'position'  => 1,
						'status'    => 'publish',
						'meta'      => array( '_menu_item_type' => array( 'custom' ) ),
					),
					array(
						'type'     => 'custom',
						'url'      => 'https://example.org/',
						'title'    => 'Child',
						'parent'   => 13,
						'position' => 2,
					),
				),
			),
			'refs'   => array(
				array(
					'path' => 'items/1/parent',
					'kind' => 'menu_item',
					'id'   => 13,
					'uuid' => $parent,
				),
			),
		);
		$result = $this->stage( $menu );
		$this->assertSame( 'staged', $result['status'] );
		$this->assertSame( array(), $result['data']['items'][0]['meta'] );
		$this->assertSame( 'unit', $result['refs'][0]['res'] );

		$menu['data']['items'][0]['target'] = '_top';
		$this->assertSame( 'invalid_field', $this->stage( $menu )['reason'] );
		$this->assertSame( 'items.target', $this->stage( $menu )['field'] );
		$menu['data']['items'][0]['target'] = '';
		$menu['data']['items'][0]['type']   = 'evil';
		$this->assertSame( 'items.type', $this->stage( $menu )['field'] );
	}

	public function test_widgets_follow_lives_widgets_group_and_policy() {
		$unit = array(
			'uuid'   => 'unit:widgets',
			'kind'   => 'widgets',
			'type'   => 'widgets',
			'action' => 'push',
			'hash'   => self::H,
			'data'   => array(
				'sidebars_widgets' => array( 'sidebar-1' => array( 'text-2' ) ),
				'widgets'          => array( 'widget_text' => array( 2 => array( 'text' => 'Hi' ) ) ),
			),
		);
		Settings::save( array( 'option_groups' => array( 'widgets' => false ) ) );
		Scope::flush();
		$this->assertSame( 'widgets_not_included', $this->stage( $unit )['reason'] );

		Settings::save( array( 'option_groups' => array( 'widgets' => true ) ) );
		Scope::flush();
		$this->assertSame( 'staged', $this->stage( $unit )['status'] );

		$unit['data']['widgets']['widget_secret_token'] = array();
		$this->assertSame( 'option_denied', $this->stage( $unit )['reason'] );
		unset( $unit['data']['widgets']['widget_secret_token'] );
		$unit['data']['widgets']['widget_custom_html'] = array( 3 => array( 'content' => '<script>x</script>' ) );
		$this->assertSame( 'held', $this->stage( $unit )['status'] );
	}

	public function test_products_need_woocommerce_on_live() {
		$product = array(
			'uuid'   => wp_generate_uuid4(),
			'kind'   => 'product',
			'type'   => 'product',
			'action' => 'create',
			'hash'   => self::H,
			'data'   => array(
				'post_type' => 'product',
				'groups'    => array(
					'content' => array(
						'type'   => 'simple',
						'name'   => 'Pot',
						'status' => 'publish',
					),
				),
			),
		);
		// WooCommerce is not active in the test suite: products are outside live's scope.
		$this->assertSame( 'type_not_included', $this->stage( $product )['reason'] );
	}

	/**
	 * Product schema checks that do not need WooCommerce (the rules themselves).
	 */
	public function test_product_rules() {
		$this->assertTrue( Item_Stager::rule_ok( '12.50', 'num' ) );
		$this->assertTrue( Item_Stager::rule_ok( '', 'num' ) );
		$this->assertFalse( Item_Stager::rule_ok( '12,50', 'num' ) );
		$this->assertFalse( Item_Stager::rule_ok( 12.5, 'num' ) );
		$this->assertTrue( Item_Stager::rule_ok( array( 3, 4 ), 'ids' ) );
		$this->assertFalse( Item_Stager::rule_ok( array( 'a' => 3 ), 'ids' ) );
		$this->assertTrue( Item_Stager::rule_ok( '0123-456', 'gtin' ) );
		$this->assertFalse( Item_Stager::rule_ok( 'abc', 'gtin' ) );
		$this->assertTrue( Item_Stager::rule_ok( array( array( 'name' => 'pa_color', 'taxonomy' => true, 'options' => array( 5 ), 'visible' => true ) ), 'attributes' ) );
		$this->assertFalse( Item_Stager::rule_ok( array( array( 'name' => 'pa_color', 'taxonomy' => true, 'options' => array( 'red' ) ) ), 'attributes' ) );
		$this->assertTrue( Item_Stager::rule_ok( array( array( 'id' => 'd1', 'name' => 'Manual', 'file' => '{{sgsp:home}}/m.pdf', 'enabled' => true ) ), 'downloads' ) );
		$this->assertFalse( Item_Stager::rule_ok( array( array( 'id' => 'd1', 'name' => 'Manual' ) ), 'downloads' ) );
		$this->assertTrue( Item_Stager::rule_ok( null, 'int_or_empty' ) );
		$this->assertFalse( Item_Stager::rule_ok( '5', 'int_or_empty' ) );
	}

	// --- security review findings (KM-xx) --------------------------------------------------------

	/**
	 * @group security
	 * @group review
	 */
	public function test_km01_a_metadata_thumb_must_name_one_of_the_attachments_own_files() {
		foreach ( array( 'woocommerce_uploads/.htaccess', '../x.jpg', 'other-photo.jpg' ) as $thumb ) {
			$env                              = $this->attachment();
			$env['data']['metadata']['thumb'] = $thumb;
			$result                           = $this->stage( $env );
			$this->assertSame( 'refused', $result['status'], $thumb );
			$this->assertSame( 'path_refused', $result['reason'], $thumb );
			$this->assertSame( 'metadata.thumb', $result['field'], $thumb );
		}

		// Its own size file is a thumb core may delete with it.
		$sha                              = str_repeat( 'b', 64 );
		$env                              = $this->attachment();
		$env['data']['metadata']['thumb'] = 'photo-150x150.jpg';
		$env['media']                     = array( array( 'path' => '2026/10/photo.jpg', 'ref' => 1 ) );
		$this->assertSame( 'staged', $this->stage_with_files( $env, array( 1 => array( 'committed', '2026/10/photo.jpg', 1000, $sha, '2026/10/photo.jpg' ) ) )['status'] );
	}

	/**
	 * @group security
	 * @group review
	 */
	public function test_km01_backup_sizes_meta_never_reaches_live() {
		$sha                 = str_repeat( 'b', 64 );
		$env                 = $this->attachment();
		$env['data']['meta'] = array(
			'_wp_attachment_backup_sizes' => array(
				array(
					'full-orig' => array( 'file' => 'woocommerce_uploads/.htaccess' ),
					'a'         => array( 'file' => 'keepmerge-push-staging-to-live/snapshots/x.bin' ),
				),
			),
			'kmfx_kept'                   => array( 'kept' ),
		);
		$env['media']        = array( array( 'path' => '2026/10/photo.jpg', 'ref' => 1 ) );
		$result              = $this->stage_with_files( $env, array( 1 => array( 'committed', '2026/10/photo.jpg', 1000, $sha, '2026/10/photo.jpg' ) ) );
		$this->assertSame( 'staged', $result['status'] );
		$this->assertSame( array( 'kmfx_kept' => array( 'kept' ) ), $result['data']['meta'] );
		$this->assertContains( 'meta_excluded', $this->codes( $result ) );
		$this->assertTrue( Scope::meta_excluded( '_wp_attachment_backup_sizes' ), 'excluded on both sites' );
	}

	/**
	 * @group security
	 * @group review
	 */
	public function test_km01_a_file_live_already_has_for_another_attachment_is_not_shared() {
		$sha          = str_repeat( 'b', 64 );
		$env          = $this->attachment(
			array(
				'files' => array(
					array(
						'path'   => '2026/10/photo.jpg',
						'size'   => 1000,
						'sha256' => $sha,
					),
					array(
						'path'   => '2026/10/photo-150x150.jpg',
						'size'   => 50,
						'sha256' => $sha,
					),
				),
			)
		);
		$env['media'] = array(
			array(
				'path' => '2026/10/photo.jpg',
				'ref'  => 1,
			),
			array(
				'path' => '2026/10/photo-150x150.jpg',
				'ref'  => 2,
			),
		);
		$files        = array(
			1 => array( 'have', '2026/10/photo.jpg', 1000, $sha, '2026/10/photo.jpg' ),
			2 => array( 'have', '2026/10/photo-150x150.jpg', 50, $sha, '2026/10/photo-150x150.jpg' ),
		);

		// Nobody on live uses the files: the new attachment may take them as they are.
		$this->assertSame( 'staged', $this->stage_with_files( $env, $files )['status'] );

		// Another attachment's main file.
		$other  = self::factory()->attachment->create_object( '2026/10/photo.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		$result = $this->stage_with_files( $env, $files );
		$this->assertSame( 'refused', $result['status'] );
		$this->assertSame( 'file_shared', $result['reason'] );
		$this->assertSame( 'files', $result['field'] );
		wp_delete_post( $other, true );

		// Another attachment's size file in the same folder.
		$other = self::factory()->attachment->create_object( '2026/10/elsewhere.jpg', 0, array( 'post_mime_type' => 'image/jpeg' ) );
		wp_update_attachment_metadata(
			$other,
			array(
				'file'  => '2026/10/elsewhere.jpg',
				'sizes' => array( 'thumbnail' => array( 'file' => 'photo-150x150.jpg' ) ),
			)
		);
		$this->assertSame( 'file_shared', $this->stage_with_files( $env, $files )['reason'] );

		// The same name in another folder is another file.
		update_post_meta( $other, '_wp_attached_file', '2025/01/elsewhere.jpg' );
		wp_update_attachment_metadata(
			$other,
			array(
				'file'  => '2025/01/elsewhere.jpg',
				'sizes' => array( 'thumbnail' => array( 'file' => 'photo-150x150.jpg' ) ),
			)
		);
		$this->assertSame( 'staged', $this->stage_with_files( $env, $files )['status'] );
	}

	/**
	 * @group security
	 * @group review
	 */
	public function test_km02_serialized_php_text_in_any_received_string_refuses_the_item() {
		$object = 'O:13:"Vendor_Gadget":1:{s:4:"file";s:9:"/tmp/x.ph";}';
		$cases  = array(
			'meta string'       => $this->envelope( $this->post_data( array( 'meta' => array( 'kmfx_vendor' => array( $object ) ) ) ) ),
			'meta nested'       => $this->envelope( $this->post_data( array( 'meta' => array( 'kmfx_vendor' => array( array( 'deep' => array( 'a:1:{i:0;' . $object . '}' ) ) ) ) ) ) ),
			'post content'      => $this->envelope( $this->post_data( array( 'post_content' => 's:5:"hello";' ) ) ),
			'option value'      => $this->option( 'blogname', array( 'x' => $object ) ),
			'binary string'     => $this->option(
				'blogname',
				array(
					'__sgsp' => 'bin',
					'hex'    => bin2hex( $object ),
				)
			),
			'inside a ser node' => $this->option(
				'blogname',
				array(
					'__sgsp' => 'ser',
					'v'      => array( 'inner' => $object ),
				)
			),
			'serialized null'   => $this->option( 'blogname', 'N;' ),
		);
		foreach ( $cases as $label => $envelope ) {
			$result = $this->stage( $envelope );
			$this->assertSame( 'refused', $result['status'], $label );
			$this->assertSame( 'invalid_value', $result['reason'], $label );
			$this->assertSame( 'serialized_string', $result['field'], $label );
		}

		// What a source's encoder sends for serialized data (a ser node of plain data) still works,
		// and text that only looks a little like it is text.
		$ser = array(
			'__sgsp' => 'ser',
			'v'      => array(
				'a' => 1,
				'b' => array( 'c' ),
			),
		);
		$this->assertSame( 'staged', $this->stage( $this->option( 'blogname', $ser ) )['status'] );
		$this->assertSame( 'staged', $this->stage( $this->option( 'blogname', 'O: is a letter; a:b is a ratio' ) )['status'] );
	}

	/**
	 * @group security
	 * @group review
	 */
	public function test_km05_code_like_settings_in_options_and_widgets_are_held_for_live() {
		$mods = 'theme_mods_' . get_stylesheet();
		set_theme_mod( 'header_textcolor', '000000' );
		foreach ( array( 'custom_js', 'header_scripts', 'footer-code', 'tracking_code', 'custom_css', 'head_html' ) as $key ) {
			$result = $this->stage( $this->option( $mods, array( $key => 'document.write(document.cookie)' ) ) );
			$this->assertSame( 'held', $result['status'], $key );
			$this->assertSame( 'code_setting', $result['gate'][0]['rule'], $key );
			$this->assertSame( 'value.' . $key, $result['gate'][0]['field'], $key );
		}
		$this->assertSame( 'held', $this->stage( $this->option( $mods, array( 'opts' => array( 'body_script' => 'x()' ) ) ) )['status'] );

		// Ordinary theme mods are not held.
		$plain = $this->stage(
			$this->option(
				$mods,
				array(
					'header_textcolor' => 'ffffff',
					'custom_logo'      => 0,
					'js_enabled'       => '',
					'code_count'       => '3',
				)
			)
		);
		$this->assertSame( 'staged', $plain['status'], wp_json_encode( $plain ) );
	}

	/**
	 * @group security
	 * @group review
	 */
	public function test_km05_the_widgets_unit_keeps_only_options_of_widgets_registered_on_live() {
		Settings::save( array( 'option_groups' => array( 'widgets' => true ) ) );
		Scope::flush();
		$unit   = array(
			'uuid'   => 'unit:widgets',
			'kind'   => 'widgets',
			'type'   => 'widgets',
			'action' => 'push',
			'hash'   => self::H,
			'data'   => array(
				'sidebars_widgets' => array( 'sidebar-1' => array( 'text-2' ) ),
				'widgets'          => array(
					'widget_text'        => array( 2 => array( 'text' => 'Hi' ) ),
					'widget_logic'       => array( 2 => 'is_user_logged_in()' ),
					'widget_vendor_eval' => array( 'expr' => 'x' ),
				),
			),
		);
		$result = $this->stage( $unit );
		$this->assertSame( 'staged', $result['status'] );
		$this->assertSame( array( 'widget_text' ), array_keys( $result['data']['widgets'] ) );
		$this->assertContains( 'widget_not_registered', $this->codes( $result ) );
	}
}
