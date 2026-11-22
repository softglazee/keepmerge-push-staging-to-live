<?php
/**
 * Activation, upgrade and deactivation.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates and maintains what the plugin needs on each site (blueprint §18): the seven tables,
 * the receiver role, the site ID, default settings and the daily prune. Nothing is redirected
 * and no notice is shown.
 */
final class Installer {

	const DB_VERSION_OPTION = 'softglaze_sp_db_version';

	const SITE_ID_OPTION = 'softglaze_sp_site_id';

	const ROLE = 'softglaze_sp_receiver';

	const CAPABILITY = 'softglaze_sp_receive_push';

	/**
	 * Folder under uploads that holds the plugin's temporary files and snapshots (§14.6).
	 */
	const UPLOADS_DIR = 'keepmerge-push-staging-to-live';

	/**
	 * Activation hook.
	 *
	 * Network activation is refused: the plugin pairs single sites, and a network-wide
	 * receiver would put every subsite's content behind one decision (blueprint §1.3).
	 *
	 * @param bool $network_wide Whether WordPress is activating for the whole network.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			wp_die(
				esc_html__( 'Keepmerge cannot be network activated. Activate it on each site that sends or receives pushes.', 'keepmerge-push-staging-to-live' ),
				esc_html__( 'Network activation is not supported', 'keepmerge-push-staging-to-live' ),
				array( 'back_link' => true )
			);
		}

		self::install();

		// Receivers left without a live pairing (for example after a deactivation, which needs a
		// new key) are deleted, so no account outlives its purpose (threat model §7.1, S-06).
		Receiver_User::delete_orphans();
	}

	/**
	 * Deactivation hook (blueprint §18.2). Every receiver Application Password and secret is
	 * revoked, the receive window closes, pairings need a new key, the push lock and the temp
	 * directory go. Content, logs, snapshots and tables stay.
	 *
	 * Source pairings become repair_needed as well, and their stored credentials are deleted
	 * (Source_Pairing::mark_repair_needed_for_deactivation() explains why).
	 *
	 * @return void
	 */
	public static function deactivate() {
		Cron::unschedule();

		Receive_Window::close( 'deactivated' );
		Pairing_Service::revoke_credentials_for_deactivation();
		Source_Pairing::mark_repair_needed_for_deactivation();
		delete_option( Receive_Window::LOCK_OPTION );
		self::delete_temp_dir();
	}

	/**
	 * Deletes uploads/keepmerge-push-staging-to-live/tmp/ through WP_Filesystem.
	 *
	 * Only with the direct method: a deactivation cannot stop to ask for FTP credentials, and
	 * the daily prune removes leftovers. The path is built from wp_upload_dir() and must resolve
	 * inside the uploads base.
	 *
	 * @return bool Whether the directory is gone.
	 */
	public static function delete_temp_dir() {
		global $wp_filesystem;

		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}

		$base = realpath( $uploads['basedir'] );
		$dir  = untrailingslashit( $uploads['basedir'] ) . '/' . self::UPLOADS_DIR . '/tmp';
		$real = realpath( $dir );
		if ( false === $real ) {
			return true;
		}
		if ( false === $base || 0 !== strpos( $real, trailingslashit( $base ) ) ) {
			return false;
		}

		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method( array(), $uploads['basedir'], true ) || ! WP_Filesystem() || ! $wp_filesystem ) {
			return false;
		}

		return (bool) $wp_filesystem->delete( $real, true, 'd' );
	}

	/**
	 * Runs the installer when the stored schema version differs from the plugin version, so an
	 * update that ships while the plugin stays active still gets its tables.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== SOFTGLAZE_SP_VERSION ) {
			self::install();
		}
	}

	/**
	 * Tables, role, site ID, default settings and cron. Safe to run again.
	 *
	 * @return void
	 */
	public static function install() {
		self::create_tables();
		self::ensure_role();
		self::ensure_site_id();

		if ( false === get_option( Settings::OPTION, false ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', true );
		}

		Cron::schedule();

		update_option( self::DB_VERSION_OPTION, SOFTGLAZE_SP_VERSION, true );
	}

	/**
	 * Creates or updates the seven tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Tables::schema() as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * The receiver role with exactly one capability. A role left over with extra capabilities
	 * (by another plugin or a role editor) is reduced back to that one. The lock-down hooks are
	 * in Receiver_User.
	 *
	 * @return void
	 */
	public static function ensure_role() {
		$role = get_role( self::ROLE );

		if ( null === $role ) {
			add_role( self::ROLE, 'Keepmerge receiver', array( self::CAPABILITY => true ) );
			return;
		}

		foreach ( array_keys( (array) $role->capabilities ) as $cap ) {
			if ( self::CAPABILITY !== $cap ) {
				$role->remove_cap( $cap );
			}
		}
		if ( empty( $role->capabilities[ self::CAPABILITY ] ) ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	/**
	 * Returns this install's site ID, creating it when missing and regenerating it when the
	 * database now serves a different home URL than the one the ID was created for.
	 *
	 * A different home URL means the database was copied (a host re-cloned live into staging,
	 * for example). The record keeps a SHA-256 of the normalised home URL: clone tools
	 * search-replace URLs in every table, serialized options included, so a stored URL would be
	 * rewritten to match the copy, while the hash is not. On a mismatch, before any remote route
	 * is registered (this runs on init, priority 2): new site ID, receive window closed, every
	 * pairing recorded for the other URL loses its receiver Application Passwords and secrets
	 * and needs a new key, clone_detected is audited (blueprint §5.9, threat model T21).
	 *
	 * @return string UUID.
	 */
	public static function ensure_site_id() {
		$home   = self::normalised_home();
		$hash   = self::home_hash();
		$stored = get_option( self::SITE_ID_OPTION );

		$valid = is_array( $stored ) && isset( $stored['uuid'] ) && is_string( $stored['uuid'] ) && wp_is_uuid( $stored['uuid'] );

		if ( $valid && isset( $stored['home_hash'] ) && is_string( $stored['home_hash'] ) && hash_equals( $hash, $stored['home_hash'] ) ) {
			return $stored['uuid'];
		}

		// A record written before the hash existed is upgraded when its URL still matches.
		if ( $valid && ! isset( $stored['home_hash'] ) && isset( $stored['home'] ) && $stored['home'] === $home ) {
			$stored['home_hash'] = $hash;
			update_option( self::SITE_ID_OPTION, $stored, true );
			return $stored['uuid'];
		}

		$record = array(
			'uuid'        => wp_generate_uuid4(),
			'home'        => $home,
			'home_hash'   => $hash,
			'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( self::SITE_ID_OPTION, $record, true );

		if ( $valid ) {
			Receive_Window::close( 'clone_detected', 'system' );
			$changed = Pairing_Service::handle_clone();

			Audit_Log::write(
				'clone_detected',
				array(
					'actor_type' => 'system',
					'details'    => array(
						'previous_site_id' => (string) $stored['uuid'],
						'previous_home'    => isset( $stored['home'] ) ? (string) $stored['home'] : '',
						'site_id'          => $record['uuid'],
						'home'             => $home,
						'pairings_reset'   => $changed,
					),
				)
			);
		}

		return $record['uuid'];
	}

	/**
	 * SHA-256 of the normalised home URL: the value pairings and the site ID are bound to.
	 *
	 * @return string 64 hex.
	 */
	public static function home_hash() {
		return hash( 'sha256', self::normalised_home() );
	}

	/**
	 * The current site ID without changing anything.
	 *
	 * @return string UUID, or '' before the first install.
	 */
	public static function site_id() {
		$stored = get_option( self::SITE_ID_OPTION );
		return is_array( $stored ) && isset( $stored['uuid'] ) && wp_is_uuid( $stored['uuid'] ) ? $stored['uuid'] : '';
	}

	/**
	 * The home URL in a stable form: lowercase scheme and host, no trailing slash.
	 *
	 * Read from the stored home option (WP_HOME included), not home_url(): home_url() switches to
	 * https whenever is_ssl() is true and passes through per-request filters, so a site whose home
	 * is http:// behind a TLS-terminating proxy would get another hash on HTTPS requests than in
	 * cron or WP-CLI, and clone detection would reset its pairings.
	 *
	 * @return string
	 */
	public static function normalised_home() {
		$url   = untrailingslashit( (string) get_option( 'home' ) );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		$out = strtolower( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . strtolower( $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$out .= ':' . (int) $parts['port'];
		}
		if ( ! empty( $parts['path'] ) ) {
			$out .= untrailingslashit( $parts['path'] );
		}

		return $out;
	}
}
