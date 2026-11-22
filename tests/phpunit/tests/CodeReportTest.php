<?php
/**
 * Sync\Code_Report: live's answer to POST /remote/code-manifest (names, versions, paths, sizes and SHA-1 only, paged,
 * components from live's own list) and the source's comparison (F8, F-05, S-52).
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Sync\Code_Report;

/**
 * Plays live for the source half: answers with this site's own Code_Report::answer(), then
 * changes what a test needs (a file hash, a missing file, a version).
 */
class Keepmerge_Test_Code_Live {

	public $calls   = array();
	public $tamper  = null;
	public $refuse  = false;
	public $plugins = array();

	public function post( $route, array $body = array(), array $opts = array() ) {
		unset( $opts );
		$this->calls[] = array( $route, $body );
		if ( $this->refuse ) {
			return new WP_Error( 'code_report_disabled', 'off', array( 'status' => 403 ) );
		}
		$answer = Code_Report::answer( $body );
		if ( is_wp_error( $answer ) ) {
			return $answer;
		}
		if ( isset( $answer['inventory'] ) && $this->plugins ) {
			foreach ( $this->plugins as $plugin ) {
				$answer['inventory']['plugins'][] = $plugin;
			}
		}
		if ( null !== $this->tamper ) {
			$answer = call_user_func( $this->tamper, $answer );
		}
		return array(
			'status' => 200,
			'data'   => $answer,
		);
	}
}

/**
 * @group planner
 * @group code
 */
class CodeReportTest extends WP_UnitTestCase {

	private $tmp = '';

	public function tear_down() {
		if ( '' !== $this->tmp && is_dir( $this->tmp ) ) {
			$this->remove( $this->tmp );
		}
		parent::tear_down();
	}

	private function remove( $dir ) {
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_link( $path ) || is_file( $path ) ) {
				unlink( $path );
			} else {
				$this->remove( $path );
			}
		}
		rmdir( $dir );
	}

	private function theme_id() {
		return 'theme:' . get_stylesheet();
	}

	public function test_inventory_has_names_and_versions_only() {
		$answer = Code_Report::answer( array() );
		$this->assertSame( array( 'inventory' ), array_keys( $answer ) );
		$inventory = $answer['inventory'];
		$this->assertSame( array( 'wordpress', 'php', 'theme', 'themes', 'plugins', 'mu_plugins', 'dropins', 'components' ), array_keys( $inventory ) );
		$this->assertSame( get_bloginfo( 'version' ), $inventory['wordpress'] );
		foreach ( $inventory['plugins'] as $plugin ) {
			$this->assertSame( array( 'slug', 'name', 'version', 'active' ), array_keys( $plugin ) );
		}
		$this->assertContains( $this->theme_id(), array_column( $inventory['components'], 'id' ) );
	}

	public function test_a_page_carries_paths_sizes_and_sha1_and_never_content() {
		$answer = Code_Report::answer( array( 'component' => $this->theme_id(), 'cursor' => 0 ) );
		$this->assertIsArray( $answer );
		$this->assertSame( array( 'component', 'version', 'total', 'truncated', 'cursor', 'next_cursor', 'files' ), array_keys( $answer ) );
		$this->assertNotEmpty( $answer['files'] );
		$this->assertLessThanOrEqual( Code_Report::PAGE_SIZE, count( $answer['files'] ) );
		$root = get_stylesheet_directory();
		foreach ( $answer['files'] as $file ) {
			$this->assertSame( array( 'path', 'size', 'sha1' ), array_keys( $file ) );
			$this->assertSame( filesize( $root . '/' . $file['path'] ), $file['size'] );
			$this->assertSame( sha1_file( $root . '/' . $file['path'] ), $file['sha1'] );
		}
		$paths = array_column( $answer['files'], 'path' );
		$sorted = $paths;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $paths, 'sorted by path' );
		// No byte of any file is in the answer: style.css's header line is not there.
		$this->assertStringNotContainsString( 'Theme Name', wp_json_encode( $answer ) );
	}

	public function test_component_and_cursor_are_checked_s52() {
		$this->assertSame( 'invalid_component', Code_Report::answer( array( 'component' => 'plugin:not-installed/x.php' ) )->get_error_code() );
		$this->assertSame( 'invalid_component', Code_Report::answer( array( 'component' => 'theme:../../../wp-config.php' ) )->get_error_code() );
		$this->assertSame( 'invalid_component', Code_Report::answer( array( 'component' => 'theme:..' ) )->get_error_code() );
		$this->assertSame( 'invalid_component', Code_Report::answer( array( 'component' => '/etc/passwd' ) )->get_error_code() );
		$this->assertSame( 'invalid_component', Code_Report::answer( array( 'component' => array( 'x' ) ) )->get_error_code() );
		$this->assertSame( 'invalid_component', Code_Report::answer( array( 'component' => 'theme:twentytwentyone' ) )->get_error_code(), 'installed but not active: not in the list' );
		$this->assertSame( 'invalid_cursor', Code_Report::answer( array( 'component' => $this->theme_id(), 'cursor' => -1 ) )->get_error_code() );
		$this->assertSame( 'invalid_cursor', Code_Report::answer( array( 'component' => $this->theme_id(), 'cursor' => '5' ) )->get_error_code() );
		$this->assertSame( 'cursor_out_of_range', Code_Report::answer( array( 'component' => $this->theme_id(), 'cursor' => 999999 ) )->get_error_code() );
		$this->assertSame( 'invalid_body', Code_Report::answer( array( 'path' => 'wp-config.php' ) )->get_error_code() );
		$this->assertSame( 'invalid_body', Code_Report::answer( array( 'cursor' => 0 ) )->get_error_code() );
		$this->assertSame( 400, Code_Report::answer( array( 'component' => 'theme:..' ) )->get_error_data()['status'] );
	}

	public function test_files_over_5_mb_are_listed_by_size_only_and_links_outside_are_left_out() {
		$this->tmp = get_temp_dir() . 'kmfx-code-' . wp_generate_password( 8, false );
		mkdir( $this->tmp . '/sub', 0700, true );
		file_put_contents( $this->tmp . '/b.txt', 'bee' );
		file_put_contents( $this->tmp . '/sub/a.txt', 'a' );
		$big = fopen( $this->tmp . '/big.bin', 'wb' );
		ftruncate( $big, Code_Report::MAX_HASH_BYTES + 1 );
		fclose( $big );
		$outside = get_temp_dir() . 'kmfx-outside-' . wp_generate_password( 8, false );
		file_put_contents( $outside, 'secret' );
		if ( function_exists( 'symlink' ) ) {
			@symlink( $outside, $this->tmp . '/link.txt' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Test setup.
		}

		$files = Code_Report::files(
			array(
				'root'   => $this->tmp,
				'single' => '',
			)
		);
		unlink( $outside );
		$this->assertSame( array( 'b.txt', 'big.bin', 'sub/a.txt' ), $files['paths'] );
		$this->assertFalse( $files['truncated'] );

		$big_entry = Code_Report::file_entry( $files['root'], 'big.bin' );
		$this->assertSame( Code_Report::MAX_HASH_BYTES + 1, $big_entry['size'] );
		$this->assertNull( $big_entry['sha1'] );
		$this->assertSame( sha1( 'bee' ), Code_Report::file_entry( $files['root'], 'b.txt' )['sha1'] );

		// A listing that runs past its deadline is truncated, never partial.
		$late = Code_Report::files(
			array(
				'root'   => $this->tmp,
				'single' => '',
			),
			microtime( true ) - 1
		);
		$this->assertTrue( $late['truncated'] );
	}

	public function test_inventory_comparison() {
		$here = array(
			'wordpress'  => '7.1.3',
			'php'        => '8.3.1',
			'theme'      => array( 'slug' => 't', 'version' => '1.1', 'parent' => '' ),
			'themes'     => array( array( 'slug' => 't', 'name' => 'T', 'version' => '1.1' ) ),
			'plugins'    => array(
				array( 'slug' => 'query-monitor/query-monitor.php', 'name' => 'Query Monitor', 'version' => '3.20', 'active' => true ),
				array( 'slug' => 'a/a.php', 'name' => 'A', 'version' => '2.0', 'active' => true ),
			),
			'mu_plugins' => array( 'mine.php' ),
			'dropins'    => array(),
		);
		$live = array(
			'wordpress'  => '7.1.3',
			'php'        => '8.3.1',
			'theme'      => array( 'slug' => 't', 'version' => '1.0', 'parent' => '' ),
			'themes'     => array( array( 'slug' => 't', 'name' => 'T', 'version' => '1.0' ) ),
			'plugins'    => array(
				array( 'slug' => 'a/a.php', 'name' => 'A', 'version' => '2.0', 'active' => false ),
				array( 'slug' => 'b/b.php', 'name' => 'B', 'version' => '1.0', 'active' => true ),
			),
			'mu_plugins' => array(),
			'dropins'    => array( 'object-cache.php' ),
		);
		$diff = Code_Report::compare_inventories( $here, $live );
		$this->assertSame( array( 'Query Monitor' ), array_column( $diff['missing_on_live'], 'name' ) );
		$this->assertSame( array( 'B' ), array_column( $diff['extra_on_live'], 'name' ) );
		$this->assertSame( array( 't' ), array_column( $diff['version_differs'], 'slug' ) );
		$this->assertSame( '1.1', $diff['version_differs'][0]['here'] );
		$this->assertSame( '1.0', $diff['version_differs'][0]['live'] );
		$this->assertSame( array( 'A' ), array_column( $diff['active_differs'], 'name' ) );
		$this->assertSame( array( 'mine.php' ), $diff['mu_plugins']['missing_on_live'] );
		$this->assertSame( array( 'object-cache.php' ), $diff['dropins']['extra_on_live'] );
	}

	public function test_the_source_compares_files_page_by_page() {
		$uuid = wp_generate_uuid4();
		$live = new Keepmerge_Test_Code_Live();
		$live->plugins = array( array( 'slug' => 'only-live/only-live.php', 'name' => 'Only live', 'version' => '1.0', 'active' => false ) );
		$theme         = $this->theme_id();
		$live->tamper  = static function ( $answer ) use ( $theme ) {
			if ( isset( $answer['component'] ) && $theme === $answer['component'] && ! empty( $answer['files'] ) ) {
				// Live's copy of the first file differs; its second file does not exist on live;
				// live has one file this site lacks.
				$answer['files'][0]['sha1'] = str_repeat( '0', 40 );
				array_splice( $answer['files'], 1, 1 );
				$answer['files'][] = array( 'path' => 'zzz-only-on-live.php', 'size' => 10, 'sha1' => str_repeat( '1', 40 ) );
			}
			return $answer;
		};

		$report = Code_Report::step( $uuid, true, $live );
		for ( $i = 0; $i < 20 && in_array( $report['state'], array( 'inventory', 'running' ), true ); $i++ ) {
			$report = Code_Report::step( $uuid, false, $live );
		}
		$this->assertSame( 'done', $report['state'] );
		$this->assertSame( array( 'Only live' ), array_column( $report['diff']['extra_on_live'], 'name' ) );

		$result = null;
		foreach ( $report['files'] as $file ) {
			if ( $theme === $file['id'] ) {
				$result = $file;
			}
		}
		$this->assertNotNull( $result );
		$this->assertTrue( $result['done'] );
		$this->assertSame( 1, $result['differ'] );
		$this->assertSame( 1, $result['only_here'] );
		$this->assertSame( 1, $result['only_live'] );
		$this->assertContains( 'zzz-only-on-live.php', array_column( $result['paths'], 'path' ) );

		// The requests carry names and cursors only.
		foreach ( $live->calls as $call ) {
			$this->assertSame( '/keepmerge/v1/remote/code-manifest', $call[0] );
			$this->assertSame( array(), array_diff( array_keys( $call[1] ), array( 'component', 'cursor' ) ) );
		}
		Code_Report::forget( $uuid );
	}

	public function test_a_refusing_live_is_recorded_as_disabled() {
		$uuid         = wp_generate_uuid4();
		$live         = new Keepmerge_Test_Code_Live();
		$live->refuse = true;
		$report       = Code_Report::step( $uuid, true, $live );
		$this->assertSame( 'disabled', $report['state'] );
		$this->assertSame( 'disabled', Code_Report::summary( $uuid )['state'] );
		Code_Report::forget( $uuid );
		$this->assertSame( 'not_run', Code_Report::summary( $uuid )['state'] );
	}
}
