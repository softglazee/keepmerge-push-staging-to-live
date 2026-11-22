<?php
/**
 * PHPUnit bootstrap for Keepmerge.
 *
 * Loads the WordPress test library from vendor/wp-phpunit, then the plugin from
 * keepmerge-push-staging-to-live/ on muplugins_loaded. The plugin's own Installer::maybe_upgrade()
 * creates its tables on the first init, before any test starts, so they are real tables and each
 * test's writes roll back with the core transaction.
 *
 * @package SoftGlaze\SafePush\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

$keepmerge_root = dirname( __DIR__, 2 );

if ( ! file_exists( $keepmerge_root . '/vendor/autoload.php' ) ) {
	fwrite( STDERR, "Run composer install first.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

require_once $keepmerge_root . '/vendor/autoload.php';

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $keepmerge_root . '/vendor/yoast/phpunit-polyfills' );

// Set by vendor/wp-phpunit/wp-phpunit/__loaded.php through the Composer autoloader.
$keepmerge_tests_dir = getenv( 'WP_PHPUNIT__DIR' );

require_once $keepmerge_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $keepmerge_root ) {
		require $keepmerge_root . '/keepmerge-push-staging-to-live/keepmerge-push-staging-to-live.php';
	}
);

require $keepmerge_tests_dir . '/includes/bootstrap.php';
