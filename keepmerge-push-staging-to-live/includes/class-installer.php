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
	}

	/**
	 * Deactivation hook. Content, logs, snapshots and tables stay.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Cron::unschedule();

		// TODO(Phase 2A): revoke every receiver Application Password and HMAC secret, close the
		// receive window, mark pairings repair_needed, release the push lock (blueprint §5.9).
		// TODO(Phase 5): delete uploads/keepmerge-push-staging-to-live/tmp/.
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
	 * (by another plugin or a role editor) is reduced back to that one.
	 *
	 * TODO(Phase 2A): hide the role from role dropdowns (editable_roles) and the rest of the
	 * receiver lock-down in blueprint §5.8.
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
	 * for example): two sites must never share an ID, so the copy gets a new one and the event
	 * is audited as clone_detected (blueprint §5.9).
	 *
	 * @return string UUID.
	 */
	public static function ensure_site_id() {
		$home   = self::normalised_home();
		$stored = get_option( self::SITE_ID_OPTION );

		if ( is_array( $stored ) && isset( $stored['uuid'], $stored['home'] ) && wp_is_uuid( $stored['uuid'] ) && $stored['home'] === $home ) {
			return $stored['uuid'];
		}

		$record = array(
			'uuid'        => wp_generate_uuid4(),
			'home'        => $home,
			'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( self::SITE_ID_OPTION, $record, true );

		if ( is_array( $stored ) && ! empty( $stored['uuid'] ) && isset( $stored['home'] ) && $stored['home'] !== $home ) {
			Audit_Log::write(
				'clone_detected',
				array(
					'actor_type' => 'system',
					'details'    => array(
						'previous_site_id' => (string) $stored['uuid'],
						'previous_home'    => (string) $stored['home'],
						'site_id'          => $record['uuid'],
						'home'             => $home,
					),
				)
			);

			// TODO(Phase 2A): close the receive window, delete receiver Application Passwords and
			// secrets of pairings recorded for the other URL and mark them repair_needed.
		}

		return $record['uuid'];
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
	 * @return string
	 */
	public static function normalised_home() {
		$url   = untrailingslashit( home_url() );
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
