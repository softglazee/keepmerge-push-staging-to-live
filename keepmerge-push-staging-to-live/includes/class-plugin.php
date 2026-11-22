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
		// before any later code (and the remote routes) relies on them.
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ), 1 );
		add_action( 'init', array( Installer::class, 'ensure_site_id' ), 2 );
		add_action( 'init', array( Settings::class, 'register' ) );
		add_action( 'update_option_' . Settings::OPTION, array( Sync\Scope::class, 'flush' ) );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		add_action( 'admin_menu', array( Admin\Menu::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( Admin\Assets::class, 'enqueue' ) );
		add_action( 'admin_init', array( Admin\Privacy::class, 'register' ) );

		add_action( Cron::HOOK, array( Cron::class, 'daily_prune' ) );

		// WooCommerce is optional: the hook only fires when it is active.
		add_action( 'before_woocommerce_init', array( Woo\Guard::class, 'declare_hpos_compatibility' ) );

		// The receiver lock-down applies whether or not the window is open.
		Receiver_User::register_hooks();
		Rest\Remote_Controller::register_hooks();

		// Pins the plugin's own outbound requests to the checked address; other requests are untouched.
		add_action( 'http_api_curl', array( Transfer\Outbound_Guard::class, 'pin_handle' ), 10, 3 );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'keepmerge', Cli\Command::class );
		}
	}

	/**
	 * Registers the admin routes, and the remote routes only while the receive window is open, so
	 * a site with the window closed exposes no remote route at all.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		// Clone detection must precede the window check, even if the REST server started before init.
		Installer::ensure_site_id();

		( new Rest\Admin_Controller() )->register_routes();
		( new Rest\Plan_Controller() )->register_routes();
		( new Rest\Push_Controller() )->register_routes();

		if ( Receive_Window::is_open() ) {
			( new Rest\Remote_Controller() )->register_routes();
		}
	}
}
