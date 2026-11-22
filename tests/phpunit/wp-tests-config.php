<?php
/**
 * WordPress test-suite configuration for Keepmerge.
 *
 * Everything comes from the environment, so no credentials live in the repository:
 *
 * - WP_CORE_DIR           WordPress core to test against (the folder that holds wp-settings.php).
 * - WP_TESTS_DB_NAME      An empty database. The test library drops and recreates every
 *                         wptests_ table in it on each run: never point it at a real site.
 * - WP_TESTS_DB_USER
 * - WP_TESTS_DB_PASSWORD
 * - WP_TESTS_DB_HOST      For example 127.0.0.1:3306.
 *
 * @package SoftGlaze\SafePush\Tests
 */

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

if ( ! function_exists( 'keepmerge_tests_env' ) ) {
	/**
	 * One environment variable, or the fallback when it is not set.
	 *
	 * @param string $name     Variable name.
	 * @param string $fallback Value when unset.
	 * @return string
	 */
	function keepmerge_tests_env( $name, $fallback = '' ) {
		$value = getenv( $name );
		return false === $value ? $fallback : (string) $value;
	}
}

$keepmerge_core_dir = keepmerge_tests_env( 'WP_CORE_DIR', '/tmp/wordpress' );

if ( ! file_exists( rtrim( $keepmerge_core_dir, '/\\' ) . '/wp-settings.php' ) ) {
	fwrite( STDERR, "WP_CORE_DIR does not point to a WordPress core folder: {$keepmerge_core_dir}\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}

define( 'ABSPATH', rtrim( $keepmerge_core_dir, '/\\' ) . '/' );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', keepmerge_tests_env( 'WP_TESTS_DB_NAME', 'wordpress_test' ) );
define( 'DB_USER', keepmerge_tests_env( 'WP_TESTS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', keepmerge_tests_env( 'WP_TESTS_DB_PASSWORD' ) );
define( 'DB_HOST', keepmerge_tests_env( 'WP_TESTS_DB_HOST', '127.0.0.1' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Keepmerge Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
