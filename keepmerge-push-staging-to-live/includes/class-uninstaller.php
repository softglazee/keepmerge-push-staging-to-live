<?php
/**
 * What uninstall.php removes.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

use WP_Application_Passwords;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uninstall. uninstall.php loads only this class and Tables, so nothing here depends on the
 * plugin's hooks.
 *
 * Always removed, so credentials never outlive the plugin: receiver users, the receiver role,
 * every stored secret and Application Password, the nonce and throttle tables, the push lock,
 * the receive window, the daily prune and the temporary folder. With "Remove all Keepmerge data
 * on uninstall" on (default off), every remaining table, the UUID meta, the uploads folder and
 * every softglaze_sp_ option go too; otherwise history and identity stay for a reinstall.
 */
final class Uninstaller {

	const ROLE = 'softglaze_sp_receiver';

	/**
	 * Receiver logins (Receiver_User::LOGIN_PATTERN); a user is removed only when it has the role,
	 * or a pairing records it and its login has this form.
	 */
	const LOGIN_PATTERN = '/\Asoftglaze-sp-[0-9a-f]{8}\z/';

	const SETTINGS_OPTION = 'softglaze_sp_settings';

	const META_KEY = '_softglaze_sp_uuid';

	/**
	 * Attachment meta of the files a push made an attachment's own (Media_Receiver::OWN_FILES_META).
	 */
	const FILES_META_KEY = '_softglaze_sp_files';

	const CRON_HOOK = 'softglaze_sp_daily_prune';

	const UPLOADS_DIR = 'keepmerge-push-staging-to-live';

	/**
	 * Rows per DELETE when the UUID meta goes, so a large site is never locked by one statement.
	 */
	const BATCH = 1000;

	/**
	 * Runs the uninstall on this site, or on every site of a network where it was activated per site.
	 *
	 * @return array Counts per site ID (for tests and the audit row).
	 */
	public static function run() {
		if ( ! is_multisite() ) {
			return array( get_current_blog_id() => self::uninstall_site() );
		}

		$out       = array();
		$receivers = array();
		$offset    = 0;
		do {
			$ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 200,
					'offset' => $offset,
				)
			);
			foreach ( $ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				$out[ (int) $site_id ] = self::uninstall_site();
				$receivers             = array_merge( $receivers, $out[ (int) $site_id ]['receiver_ids'] );
				restore_current_blog();
			}
			$offset += 200;
			$full    = 200 === count( $ids );
		} while ( $full );

		self::delete_network_receivers( array_unique( $receivers ) );

		return $out;
	}

	/**
	 * Uninstall on the current site.
	 *
	 * @return array Counts.
	 */
	public static function uninstall_site() {
		$remove_all = self::remove_all_requested();
		$recorded   = self::recorded_receiver_ids();

		$out = array(
			'remove_all'   => $remove_all,
			'receiver_ids' => array(),
			'pairings'     => self::delete_credentials(),
		);

		$out['receiver_ids'] = self::delete_receivers( $recorded );
		remove_role( self::ROLE );

		self::drop_tables( array( 'nonces', 'throttle' ) );
		delete_option( 'softglaze_sp_push_lock' );
		delete_option( 'softglaze_sp_receive' );
		wp_clear_scheduled_hook( self::CRON_HOOK );
		$out['temp_removed'] = self::delete_folder( 'tmp' );

		if ( ! $remove_all ) {
			self::audit( $out );
			return $out;
		}

		self::drop_tables( array( 'pairings', 'audit', 'sync_state', 'pushes', 'push_items' ) );
		$out['meta']           = self::delete_meta( 'post' ) + self::delete_meta( 'term' );
		$out['folder_removed'] = self::delete_folder( '' );
		$out['options']        = self::delete_options();

		return $out;
	}

	/**
	 * Whether the administrator switched on "Remove all Keepmerge data on uninstall".
	 *
	 * @return bool
	 */
	private static function remove_all_requested() {
		$settings = get_option( self::SETTINGS_OPTION );
		return is_array( $settings ) && ! empty( $settings['remove_data_on_uninstall'] );
	}

	/**
	 * Whether one of the plugin's tables exists on this site.
	 *
	 * @param string $name Short table name.
	 * @return bool
	 */
	private static function table_exists( $name ) {
		global $wpdb;

		$table = Tables::table_name( $name );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall checks the plugin's own table; nothing to cache.
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/**
	 * Receiver user IDs recorded in the pairings table, read before that table can go.
	 *
	 * @return int[]
	 */
	private static function recorded_receiver_ids() {
		global $wpdb;

		if ( ! self::table_exists( 'pairings' ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall reads the plugin's own table once.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT receiver_user_id FROM %i WHERE receiver_user_id > %d', Tables::table_name( 'pairings' ), 0 ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Removes every credential a pairing row holds, on both roles, and marks pairings that were
	 * still usable as needing a new key. The rows stay as history unless the data goes too.
	 *
	 * @return int Rows changed.
	 */
	private static function delete_credentials() {
		global $wpdb;

		if ( ! self::table_exists( 'pairings' ) ) {
			return 0;
		}

		$table = Tables::table_name( 'pairings' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall clears secrets in the plugin's own table with one prepared statement.
		$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET app_password_uuid = '', app_password_enc = NULL, secret_enc = NULL, bootstrap_password_uuid = '', bootstrap_secret_enc = NULL, expires_gmt = NULL WHERE app_password_uuid <> %s OR app_password_enc IS NOT NULL OR secret_enc IS NOT NULL OR bootstrap_password_uuid <> %s OR bootstrap_secret_enc IS NOT NULL", $table, '', '' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall marks the plugin's own rows with one prepared statement.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s WHERE status IN ( %s, %s, %s )', $table, 'repair_needed', 'pending', 'awaiting_approval', 'active' ) );

		return $changed;
	}

	/**
	 * Deletes every receiver user of this site with its Application Passwords; anything attributed
	 * to one is reassigned to an administrator, never deleted.
	 *
	 * @param int[] $recorded Receiver IDs recorded by pairings.
	 * @return int[] IDs removed from this site.
	 */
	private static function delete_receivers( array $recorded ) {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$ids = get_users(
			array(
				'role'   => self::ROLE,
				'fields' => 'ID',
				'number' => -1,
			)
		);
		$ids = array_unique( array_merge( array_map( 'intval', (array) $ids ), $recorded ) );

		$reassign = self::reassign_target( $ids );
		$removed  = array();

		foreach ( $ids as $id ) {
			$user = get_userdata( $id );
			if ( ! $user || ! self::is_receiver( $user, $recorded ) ) {
				continue;
			}
			WP_Application_Passwords::delete_all_application_passwords( $id );
			if ( wp_delete_user( $id, $reassign > 0 ? $reassign : null ) ) {
				$removed[] = $id;
			}
		}

		return $removed;
	}

	/**
	 * On a network, a receiver removed from every site it belonged to is deleted from the network.
	 *
	 * @param int[] $ids Receiver IDs removed from sites.
	 * @return void
	 */
	private static function delete_network_receivers( array $ids ) {
		if ( empty( $ids ) ) {
			return;
		}
		if ( ! function_exists( 'wpmu_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/ms.php';
		}
		foreach ( $ids as $id ) {
			$user = get_userdata( (int) $id );
			if ( $user && 1 === preg_match( self::LOGIN_PATTERN, (string) $user->user_login ) && empty( get_blogs_of_user( (int) $id, true ) ) ) {
				wpmu_delete_user( (int) $id );
			}
		}
	}

	/**
	 * Whether a user is a receiver: the role, or a pairing records it and the login has the form.
	 *
	 * @param \WP_User $user     User.
	 * @param int[]    $recorded Receiver IDs recorded by pairings.
	 * @return bool
	 */
	private static function is_receiver( $user, array $recorded ) {
		if ( in_array( self::ROLE, (array) $user->roles, true ) ) {
			return true;
		}
		return in_array( (int) $user->ID, $recorded, true ) && 1 === preg_match( self::LOGIN_PATTERN, (string) $user->user_login );
	}

	/**
	 * The first administrator of this site that is not about to be deleted.
	 *
	 * @param int[] $exclude Users being deleted.
	 * @return int User ID, 0 when there is none.
	 */
	private static function reassign_target( array $exclude ) {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 20,
			)
		);
		foreach ( (array) $admins as $id ) {
			if ( ! in_array( (int) $id, $exclude, true ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Drops some of the plugin's tables.
	 *
	 * @param string[] $names Short names (Tables::NAMES).
	 * @return void
	 */
	private static function drop_tables( array $names ) {
		global $wpdb;

		foreach ( $names as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Uninstall drops the plugin's own table; the name comes from the Tables allowlist.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Tables::table_name( $name ) ) );
		}
	}

	/**
	 * Deletes the UUID meta of posts or terms (and the own-files meta of attachments) in batches of
	 * BATCH rows.
	 *
	 * @param string $type post or term.
	 * @return int Rows deleted.
	 */
	private static function delete_meta( $type ) {
		global $wpdb;

		$table   = 'term' === $type ? $wpdb->termmeta : $wpdb->postmeta;
		$deleted = 0;
		$rounds  = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall removes the plugin's own meta keys in bounded batches; delete_metadata() would load every row first.
			$n        = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s) LIMIT %d', $table, self::META_KEY, self::FILES_META_KEY, self::BATCH ) );
			$deleted += $n;
			++$rounds;
		} while ( $n >= self::BATCH && $rounds < 100000 );

		$group = 'term' === $type ? 'term_meta' : 'post_meta';
		if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' ) ) {
			wp_cache_flush_group( $group );
		}

		return $deleted;
	}

	/**
	 * Deletes every softglaze_sp_ option through delete_option(), so caches forget them too.
	 *
	 * @return int Options deleted.
	 */
	private static function delete_options() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall lists the plugin's own option names by prefix; core has no API for that.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s LIMIT %d', $wpdb->options, $wpdb->esc_like( 'softglaze_sp_' ) . '%', 1000 ) );

		$deleted = 0;
		foreach ( (array) $names as $name ) {
			if ( delete_option( (string) $name ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Deletes uploads/keepmerge-push-staging-to-live/<sub>/ (the whole folder when $sub is ''),
	 * only inside the uploads base and only with WP_Filesystem's direct method, because uninstall
	 * cannot ask for FTP credentials.
	 *
	 * @param string $sub '' or 'tmp'.
	 * @return bool Whether the folder is gone.
	 */
	private static function delete_folder( $sub ) {
		global $wp_filesystem;

		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return false;
		}

		$base = realpath( $uploads['basedir'] );
		$dir  = untrailingslashit( $uploads['basedir'] ) . '/' . self::UPLOADS_DIR . ( '' === $sub ? '' : '/' . $sub );
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
	 * One data_removed row in the audit log that stays, so a reinstall shows when the credentials
	 * went. Written directly because Audit_Log is not loaded during uninstall.
	 *
	 * @param array $out Counts.
	 * @return void
	 */
	private static function audit( array $out ) {
		global $wpdb;

		if ( ! self::table_exists( 'audit' ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table; $wpdb->insert prepares the statement.
		$wpdb->insert(
			Tables::table_name( 'audit' ),
			array(
				'created_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'pairing_id'  => 0,
				'actor_type'  => 'system',
				'actor_id'    => 0,
				'event'       => 'data_removed',
				'object_type' => 'plugin',
				'object_ref'  => 'uninstall',
				'ip'          => '',
				'result'      => 'ok',
				'details'     => wp_json_encode(
					array(
						'scope'     => 'credentials',
						'receivers' => count( $out['receiver_ids'] ),
						'pairings'  => (int) $out['pairings'],
					)
				),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}
}
