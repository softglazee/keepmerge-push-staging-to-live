<?php
/**
 * Admin script and style.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Admin;

use SoftGlaze\SafePush\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the admin bundle on Tools → Staging Push and nowhere else. React and the @wordpress
 * packages come from core's script handles (listed in build/admin.asset.php); nothing is
 * loaded from a CDN.
 */
final class Assets {

	const HANDLE = 'softglaze-sp-admin';

	/**
	 * Enqueues the bundle on our page only.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		if ( Menu::HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}

		$asset_file = SOFTGLAZE_SP_DIR . 'build/admin.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;
		$deps  = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$ver   = isset( $asset['version'] ) ? (string) $asset['version'] : SOFTGLAZE_SP_VERSION;

		wp_enqueue_script(
			self::HANDLE,
			plugins_url( 'build/admin.js', SOFTGLAZE_SP_FILE ),
			$deps,
			$ver,
			array( 'in_footer' => true )
		);
		wp_set_script_translations( self::HANDLE, 'keepmerge-push-staging-to-live' );

		if ( is_readable( SOFTGLAZE_SP_DIR . 'build/admin.css' ) ) {
			wp_enqueue_style( self::HANDLE, plugins_url( 'build/admin.css', SOFTGLAZE_SP_FILE ), array(), $ver );
			wp_style_add_data( self::HANDLE, 'rtl', 'replace' );
		}

		// Data the app boots with, as JSON so numbers and booleans keep their types.
		wp_add_inline_script(
			self::HANDLE,
			'window.softglazeSpAdmin = ' . wp_json_encode( self::boot_data() ) . ';',
			'before'
		);
	}

	/**
	 * The data island. Only facts the screen needs before its first request; no secrets.
	 *
	 * @return array
	 */
	private static function boot_data() {
		return array(
			'namespace' => Plugin::REST_NAMESPACE,
			'version'   => SOFTGLAZE_SP_VERSION,
			'siteName'  => get_bloginfo( 'name' ),
			'homeUrl'   => home_url( '/' ),
		);
	}
}
