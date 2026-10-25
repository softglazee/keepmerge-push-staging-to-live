<?php
/**
 * Plugin bootstrap.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin's hooks. Classes are loaded on first use by the autoloader.
 */
final class Plugin {

	/**
	 * REST namespace for every route the plugin registers.
	 */
	const REST_NAMESPACE = 'keepmerge/v1';

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers hooks.
	 */
	private function __construct() {
		// Before anything else on init: tables exist and the site ID matches this home URL
		// before any later code (and, from Phase 2A, the remote routes) relies on them.
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 1 );
		add_action( 'init', array( Installer::class, 'ensure_site_id' ), 2 );
		add_action( 'init', array( Settings::class, 'register' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		add_action( 'admin_menu', array( Admin\Menu::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( Admin\Assets::class, 'enqueue' ) );

		add_action( Cron::HOOK, array( Cron::class, 'daily_prune' ) );

		// TODO(Phase 2A): receive window, Rest\Remote_Controller (registered only while the window
		// is open), receiver lock-down hooks (blueprint §5.8), Cli\Command when WP_CLI is defined.
	}

	/**
	 * Registers the admin routes. No remote route exists in this version of the code.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		( new Rest\Admin_Controller() )->register_routes();
	}
}
