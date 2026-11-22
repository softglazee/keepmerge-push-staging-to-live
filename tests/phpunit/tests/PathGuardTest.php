<?php
/**
 * Transfer\Path_Guard: the path checks every received file passes (S-36).
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Transfer\Path_Guard;

/**
 * @group staging
 * @group guard
 */
class PathGuardTest extends WP_UnitTestCase {

	/**
	 * @dataProvider accepted
	 *
	 * @param string $path Path.
	 */
	public function test_ordinary_upload_paths_are_accepted( $path ) {
		$this->assertSame( '', Path_Guard::reason( $path ), $path );
	}

	public function accepted() {
		return array(
			'year/month'      => array( '2026/10/photo.jpg' ),
			'root'            => array( 'photo.png' ),
			'pdf'             => array( '2026/10/menu-card.pdf' ),
			'four segments'   => array( 'a/b/c/d.jpg' ),
			'scaled'          => array( '2026/10/big-image-scaled.jpg' ),
			'size suffix'     => array( '2026/10/photo-150x150.webp' ),
			'upper case ext'  => array( '2026/10/Photo.JPG' ),
		);
	}

	/**
	 * @dataProvider refused
	 *
	 * @param string $path   Path.
	 * @param string $reason Expected reason.
	 */
	public function test_traversal_hidden_protected_and_script_paths_are_refused( $path, $reason ) {
		$this->assertSame( $reason, Path_Guard::reason( $path ), $path );
	}

	public function refused() {
		return array(
			'S-36 wp-config'       => array( '../../wp-config.php', 'validate_file' ),
			'traversal inside'     => array( '2026/../../x.jpg', 'validate_file' ),
			'encoded dots'         => array( '%2e%2e/%2e%2e/wp-config.php', 'encoded' ),
			'absolute'             => array( '/etc/passwd.jpg', 'bad_segment' ),
			'backslash'            => array( '2026\\10\\x.jpg', 'bad_character' ),
			'drive'                => array( 'C:/x.jpg', 'bad_character' ),
			'nul'                  => array( "x.jpg\0.php", 'bad_character' ),
			'dot segment'          => array( '2026/./x.jpg', 'bad_segment' ),
			'empty segment'        => array( '2026//x.jpg', 'bad_segment' ),
			'htaccess'             => array( '.htaccess', 'bad_segment' ),
			'hidden folder'        => array( '2026/.git/x.jpg', 'bad_segment' ),
			'too deep'             => array( 'a/b/c/d/e.jpg', 'too_deep' ),
			'own folder'           => array( 'keepmerge-push-staging-to-live/tmp/x.jpg', 'protected_dir' ),
			'downloads'            => array( 'woocommerce_uploads/x.jpg', 'protected_dir' ),
			'logs'                 => array( 'wc-logs/x.jpg', 'protected_dir' ),
			'fonts'                => array( 'fonts/x.jpg', 'protected_dir' ),
			'double extension'     => array( '2026/10/x.php.jpg', 'not_sanitized' ),
			'phtml'                => array( 'shell.phtml', 'extension_refused' ),
			'php upper'            => array( 'shell.PhP', 'extension_refused' ),
			'phar'                 => array( 'x.phar', 'extension_refused' ),
			'svg'                  => array( 'logo.svg', 'extension_refused' ),
			'html'                 => array( 'page.html', 'extension_refused' ),
			'js'                   => array( 'app.js', 'extension_refused' ),
			'exe'                  => array( 'setup.exe', 'extension_refused' ),
			'no extension'         => array( '2026/10/README', 'no_extension' ),
			'space'                => array( '2026/10/my photo.jpg', 'not_sanitized' ),
			'too long'             => array( str_repeat( 'a', 252 ) . '.jpg', 'too_long' ),
			'empty'                => array( '', 'empty' ),
		);
	}

	public function test_every_part_of_a_basename_is_checked() {
		// sanitize_file_name() would rewrite these; the parts check refuses them on its own too.
		$this->assertSame( 'extension_refused', Path_Guard::basename_reason( 'x.jpg.php' ) );
		$this->assertSame( 'extension_refused', Path_Guard::basename_reason( 'x.asp' ) );
		$this->assertSame( '', Path_Guard::basename_reason( 'x.tar.gz' ), 'two allowed extensions' );
	}

	public function test_metadata_siblings_stay_in_the_attachment_folder() {
		$this->assertSame( '', Path_Guard::sibling_reason( '2026/10/a.jpg', 'a-150x150.jpg' ) );
		$this->assertSame( 'bad_segment', Path_Guard::sibling_reason( '2026/10/a.jpg', '../x.jpg' ) );
		$this->assertSame( 'extension_refused', Path_Guard::sibling_reason( '2026/10/a.jpg', 'a.phtml' ) );
		$this->assertSame( '', Path_Guard::sibling_reason( 'a.jpg', 'a-300x200.jpg' ) );
	}

	public function test_protected_folders_include_other_sites_and_plugin_downloads_and_a_filter_only_adds() {
		foreach ( array( 'sites/2/10/x.jpg', 'edd/2026/x.pdf', 'wpforms/x.jpg', 'gravity_forms/x.jpg', 'SITES/2/x.jpg' ) as $path ) {
			$this->assertSame( 'protected_dir', Path_Guard::reason( $path ), $path );
		}
		$this->assertSame( '', Path_Guard::reason( 'sites.jpg' ), 'a file, not a folder' );

		$filter = static function () {
			return array( 'private-docs', '../x', 7 );
		};
		add_filter( 'softglaze_sp_protected_upload_dirs', $filter );
		$this->assertSame( 'protected_dir', Path_Guard::reason( 'private-docs/x.pdf' ) );
		$this->assertContains( 'keepmerge-push-staging-to-live', Path_Guard::protected_dirs(), 'built-ins stay' );
		$this->assertNotContains( '../x', Path_Guard::protected_dirs() );
		remove_filter( 'softglaze_sp_protected_upload_dirs', $filter );

		$remove = static function () {
			return null;
		};
		add_filter( 'softglaze_sp_protected_upload_dirs', $remove );
		$this->assertSame( 'protected_dir', Path_Guard::reason( 'fonts/x.woff2' ), 'a filter cannot remove a built-in' );
		remove_filter( 'softglaze_sp_protected_upload_dirs', $remove );
	}

	public function test_metadata_file_fields_are_checked_like_paths() {
		$ok = array(
			'file'           => '2026/10/a-scaled.jpg',
			'sizes'          => array(
				'thumbnail' => array( 'file' => 'a-150x150.jpg' ),
				'medium'    => array( 'file' => 'a-300x200.jpg' ),
			),
			'original_image' => 'a.jpg',
		);
		$this->assertSame( '', Path_Guard::metadata_reason( '2026/10/a-scaled.jpg', $ok ) );
		$this->assertSame( '', Path_Guard::metadata_reason( '2026/10/a.jpg', 'not an array' ) );

		$bad = array(
			'metadata.file'           => array( 'file' => '../../wp-config.php' ),
			'metadata.sizes'          => array( 'sizes' => array( 'thumbnail' => array( 'file' => '../../x.php' ) ) ),
			'metadata.original_image' => array( 'original_image' => '2026/10/a.jpg' ),
		);
		foreach ( $bad as $field => $meta ) {
			$this->assertSame( $field, Path_Guard::metadata_reason( '2026/10/a.jpg', $meta ), $field );
		}
		$this->assertSame( 'metadata.sizes', Path_Guard::metadata_reason( '2026/10/a.jpg', array( 'sizes' => 'x' ) ) );
		$this->assertSame( 'metadata.sizes', Path_Guard::metadata_reason( '2026/10/a.jpg', array( 'sizes' => array( 'x' ) ) ) );

		$this->assertSame(
			array( '2026/10/a-scaled.jpg', '2026/10/a-150x150.jpg', '2026/10/a-300x200.jpg', '2026/10/a.jpg' ),
			Path_Guard::attachment_paths( '2026/10/a-scaled.jpg', $ok )
		);
		$this->assertSame( array( 'a.jpg' ), Path_Guard::attachment_paths( 'a.jpg', array( 'sizes' => array( 'x' => array( 'file' => '../b.jpg' ) ) ) ), 'refused names are left out' );
	}

	public function test_mime_types_are_those_a_user_without_unfiltered_upload_may_upload() {
		$this->assertTrue( Path_Guard::mime_allowed( 'image/jpeg' ) );
		$this->assertTrue( Path_Guard::mime_allowed( 'application/pdf' ) );
		$this->assertFalse( Path_Guard::mime_allowed( 'text/html' ) );
		$this->assertFalse( Path_Guard::mime_allowed( 'image/svg+xml' ) );
		$this->assertFalse( Path_Guard::mime_allowed( 'application/x-httpd-php' ) );

		// Even for an administrator (unfiltered_html) the list stays the restricted one.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( Path_Guard::mime_allowed( 'text/html' ) );
		$this->assertSame( 'extension_refused', Path_Guard::reason( 'page.htm' ) );
	}
}
