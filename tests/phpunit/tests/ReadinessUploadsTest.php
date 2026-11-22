<?php
/**
 * Readiness "Uploads folder": a site
 * that never stored an upload has no uploads folder yet. Media_Receiver creates it with the first
 * received file (it checks WordPress's filesystem method on wp-content in that case), so the
 * Overview must not report "media cannot be received" there; a broken uploads configuration and a
 * non-direct filesystem method are still errors.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Readiness;

/**
 * @group readiness
 */
class ReadinessUploadsTest extends WP_UnitTestCase {

	private $missing = '';

	public function tear_down() {
		remove_all_filters( 'upload_dir' );
		remove_all_filters( 'filesystem_method' );
		parent::tear_down();
	}

	private static function check() {
		$found = wp_list_filter( Readiness::checks(), array( 'id' => 'filesystem' ) );
		return array_values( $found )[0];
	}

	private function point_uploads_at( $basedir, $error = false ) {
		add_filter(
			'upload_dir',
			static function ( $dirs ) use ( $basedir, $error ) {
				$dirs['basedir'] = $basedir;
				$dirs['path']    = $basedir . $dirs['subdir'];
				$dirs['error']   = $error;
				return $dirs;
			}
		);
	}

	public function test_an_uploads_folder_that_does_not_exist_yet_is_ready_when_wordpress_can_create_it() {
		$missing = WP_CONTENT_DIR . '/km-uploads-not-created-' . wp_generate_password( 8, false );
		$this->assertDirectoryDoesNotExist( $missing );
		$this->point_uploads_at( $missing );

		$check = self::check();
		$this->assertSame( 'ok', $check['status'], $check['detail'] );
		$this->assertStringContainsString( 'first', $check['detail'] );
		$this->assertDirectoryDoesNotExist( $missing, 'the readiness check creates nothing' );
	}

	public function test_an_existing_writable_uploads_folder_is_ready() {
		$check = self::check();
		$this->assertSame( 'ok', $check['status'], $check['detail'] );
	}

	public function test_a_broken_uploads_configuration_and_a_non_direct_method_are_errors() {
		$this->point_uploads_at( WP_CONTENT_DIR . '/uploads', 'Unable to create directory.' );
		$this->assertSame( 'error', self::check()['status'] );
		remove_all_filters( 'upload_dir' );

		$this->point_uploads_at( WP_CONTENT_DIR . '/km-uploads-not-created-x' );
		add_filter(
			'filesystem_method',
			static function () {
				return 'ftpext';
			}
		);
		$check = self::check();
		$this->assertSame( 'error', $check['status'] );
		$this->assertStringContainsString( 'ftpext', $check['detail'] );
	}
}
