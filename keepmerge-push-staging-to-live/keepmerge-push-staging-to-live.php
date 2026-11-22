<?php
/**
 * Plugin Name:       Keepmerge – Push Staging to Live
 * Description:       Pushes content and media from staging to live with a dry-run diff, conflict checks and rollback. Code is reported, never pushed.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            SoftGlaze
 * Author URI:        https://softglaze.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       keepmerge-push-staging-to-live
 * Domain Path:       /languages
 *
 * @package SoftGlaze\SafePush
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SOFTGLAZE_SP_VERSION', '1.0.0' );
define( 'SOFTGLAZE_SP_FILE', __FILE__ );
define( 'SOFTGLAZE_SP_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'SoftGlaze\\SafePush\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$class = array_pop( $parts );
		$dir   = '';
		foreach ( $parts as $part ) {
			$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
		}
		$file = SOFTGLAZE_SP_DIR . 'includes/' . $dir . 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'SoftGlaze\SafePush\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SoftGlaze\SafePush\Installer', 'deactivate' ) );

SoftGlaze\SafePush\Plugin::instance();
