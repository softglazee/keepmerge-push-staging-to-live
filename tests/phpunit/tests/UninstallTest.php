<?php
/**
 * Lifecycle tests U-01 to U-04: uninstall.php with the "Remove all
 * Keepmerge data" setting off and on, deactivate and reactivate on both sides, orphan receivers at
 * activation.
 *
 * The site holds what a real pair leaves behind: an active destination pairing (receiver user,
 * Application Password, secret), a source pairing with encrypted credentials, nonce and throttle
 * rows, an applied push with snapshot rows and a snapshot file, a temp folder, sync state, UUID
 * meta on posts and terms, the push lock, an open receive window, the daily prune, the source's
 * baseline and code report options.
 *
 * WordPress's test case turns DROP TABLE into DROP TEMPORARY TABLE; the uninstall runs with that
 * rewrite removed so the drops are real, and the plugin's tables are created again afterwards
 * (MySQL commits the drops).
 *
 * @package SoftGlaze\SafePush\Tests
 */

require_once __DIR__ . '/class-keepmerge-recovery-harness.php';

use SoftGlaze\SafePush\Cron;
use SoftGlaze\SafePush\Crypto;
use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Receiver_User;
use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Throttle;
use SoftGlaze\SafePush\Transfer\Push_Lock;

/**
 * @group lifecycle
 */
class UninstallTest extends Keepmerge_Recovery_Harness {

	const SOURCE_PAIRING = '7a7a7a7a-1111-4222-8333-444455556666';

	private $snapshot_file;
	private $temp_file;
	private $page_id;
	private $term_id;

	public function tear_down() {
		global $wpdb;
		parent::tear_down();
		// A real DROP survives the rollback on MySQL: put the plugin's tables and role back.
		Installer::create_tables();
		Installer::ensure_role();
		Cron::schedule();
		// That DROP also committed the test's transaction, so the rows the test wrote before the
		// uninstall outlive the rollback (the next seed would hit a duplicate pairing UUID). The
		// plugin's tables hold no class-level fixture rows: empty them.
		foreach ( Tables::NAMES as $name ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Tables::table_name( $name ) ) );
		}
		$wpdb->query( 'COMMIT' );
	}

	// --- fixture ------------------------------------------------------------------------------------

	private function seed() {
		global $wpdb;

		// An applied push: snapshot rows on live, sync state, UUID meta on the page.
		$this->page_id = $this->page( 'U page' );
		$push          = $this->stage( array( $this->changed_page( $this->page_id ) ) );
		$answers       = $this->apply( $push );
		$this->assertSame( 'applied', end( $answers )['state'] );

		// A snapshot file and a temp folder as Snapshot_Store and Media_Receiver write them.
		$this->snapshot_file = $this->put_file( 'keepmerge-push-staging-to-live/snapshots/' . str_repeat( 'ab', 16 ) . '/1.bin', 'snapshot bytes' );
		$this->temp_file     = $this->put_file( 'keepmerge-push-staging-to-live/tmp/' . str_repeat( 'cd', 16 ) . '/1.part0', 'part bytes' );

		// A term with a UUID.
		$this->term_id = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		add_term_meta( $this->term_id, Identity::META_KEY, wp_generate_uuid4() );

		// The source side of another pair: encrypted credentials stored.
		Pairing_Store::insert(
			array(
				'pairing_uuid'     => self::SOURCE_PAIRING,
				'role'             => 'source',
				'remote_url'       => 'https://live.example.net',
				'rest_root'        => 'https://live.example.net/wp-json/',
				'local_home_url'   => Installer::normalised_home(),
				'local_home_hash'  => Installer::home_hash(),
				'status'           => 'active',
				'app_password_enc' => Crypto::encrypt( 'abcd efgh ijkl mnop qrst uvwx' ),
				'secret_enc'       => Crypto::encrypt( str_repeat( 'f', 64 ) ),
				'created_gmt'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		Throttle::record_failure( '192.0.2.99' );
		update_option( Push_Lock::OPTION, array( 'push' => wp_generate_uuid4(), 'heartbeat' => time() ), false );
		update_option( 'softglaze_sp_baseline', array( self::SOURCE_PAIRING => array( 'state' => 'done' ) ), false );
		update_option( 'softglaze_sp_code_report', array( self::SOURCE_PAIRING => array( 'state' => 'done' ) ), false );
		update_option( 'softglaze_sp_last_unpinned', time(), false );
		Installer::install();

		$this->assertGreaterThan( 0, $this->rows_in( 'nonces' ), 'fixture: nonces' );
		$this->assertGreaterThan( 0, $this->rows_in( 'throttle' ), 'fixture: throttle' );
		$this->assertGreaterThan( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE side = %s AND pre_image IS NOT NULL', Tables::table_name( 'push_items' ), 'destination' ) ), 'fixture: snapshot rows' );
		$this->assertGreaterThan( 0, $this->rows_in( 'sync_state' ), 'fixture: sync state' );
		$this->assertGreaterThan( 0, $this->uuid_meta( 'post' ), 'fixture: post UUIDs' );
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( $this->receiver ) );
		$this->assertTrue( Receive_Window::state()['open'] );
		$this->assertNotFalse( wp_next_scheduled( 'softglaze_sp_daily_prune' ) );
	}

	private function rows_in( $table ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Tables::table_name( $table ) ) );
	}

	private function table_exists( $table ) {
		global $wpdb;
		$name = Tables::table_name( $table );
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) );
	}

	private function uuid_meta( $type ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key = %s', 'term' === $type ? $wpdb->termmeta : $wpdb->postmeta, Identity::META_KEY ) );
	}

	private function our_options() {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s ORDER BY option_name', $wpdb->options, $wpdb->esc_like( 'softglaze_sp_' ) . '%' ) );
	}

	private function our_tables() {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'softglaze_sp_' ) . '%' ) );
	}

	private function credential_columns_left() {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE app_password_uuid <> '' OR app_password_enc IS NOT NULL OR secret_enc IS NOT NULL OR bootstrap_password_uuid <> '' OR bootstrap_secret_enc IS NOT NULL OR 0 = %d", Tables::table_name( 'pairings' ), 1 ) );
	}

	private function cron_hooks() {
		$hooks = array();
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( 0 === strpos( $hook, 'softglaze_sp_' ) ) {
					$hooks[] = $hook;
				}
			}
		}
		return $hooks;
	}

	/**
	 * Runs uninstall.php as WordPress does (plugin inactive is not needed here: it touches no hook).
	 */
	private function uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'keepmerge-push-staging-to-live/keepmerge-push-staging-to-live.php' );
		}
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		Receiver_User::flush_cache();
		include SOFTGLAZE_SP_DIR . 'uninstall.php';
		wp_cache_flush();
	}

	// --- U-01 ---------------------------------------------------------------------------------------

	public function test_u01_uninstall_with_the_setting_off_removes_credentials_and_keeps_data() {
		global $wpdb;
		$this->seed();

		$site_id   = Installer::site_id();
		$settings  = get_option( Settings::OPTION );
		$pairings  = $this->rows_in( 'pairings' );
		$audit     = $this->rows_in( 'audit' );
		$items     = $this->rows_in( 'push_items' );
		$pushes    = $this->rows_in( 'pushes' );
		$sync      = $this->rows_in( 'sync_state' );
		$post_uuid = $this->uuid_meta( 'post' );
		$term_uuid = $this->uuid_meta( 'term' );
		$receiver  = $this->receiver;
		$this->assertGreaterThanOrEqual( 2, $pairings - $this->count_status( 'revoked' ) );

		$this->uninstall();

		// Gone, always.
		$this->assertFalse( get_userdata( $receiver ), 'receiver user deleted' );
		$this->assertSame( array(), get_users( array( 'role' => Installer::ROLE, 'fields' => 'ID' ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_value LIKE %s', $wpdb->usermeta, '%' . $wpdb->esc_like( 'softglaze_sp_' ) . '%' ) ), 'no receiver capabilities left in usermeta' );
		$this->assertNull( get_role( Installer::ROLE ), 'role removed' );
		$this->assertSame( 0, $this->credential_columns_left(), 'no secret, Application Password or bootstrap value in any pairing row' );
		$this->assertFalse( $this->table_exists( 'nonces' ), 'nonces table dropped' );
		$this->assertFalse( $this->table_exists( 'throttle' ), 'throttle table dropped' );
		$this->assertFileDoesNotExist( $this->temp_file );
		$this->assertDirectoryDoesNotExist( $this->plugin_dir() . '/tmp' );
		$this->assertFalse( get_option( Push_Lock::OPTION ) );
		$this->assertFalse( get_option( Receive_Window::OPTION ) );
		$this->assertSame( array(), $this->cron_hooks(), 'no softglaze_sp_ cron event' );

		// Kept.
		$this->assertSame( $pairings, $this->rows_in( 'pairings' ), 'pairing rows kept as history' );
		$this->assertSame( 'repair_needed', Pairing_Store::find( $this->pairing_uuid )['status'] );
		$this->assertSame( 'repair_needed', Pairing_Store::find( self::SOURCE_PAIRING )['status'] );
		$this->assertSame( $audit + 1, $this->rows_in( 'audit' ), 'audit kept, one data_removed row added' );
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 1', Tables::table_name( 'audit' ) ), ARRAY_A );
		$this->assertSame( 'data_removed', $row['event'] );
		$this->assertSame( array( 'scope' => 'credentials', 'receivers' => 1, 'pairings' => 2 ), json_decode( $row['details'], true ) );
		$this->assertSame( $items, $this->rows_in( 'push_items' ), 'push_items (snapshots) kept' );
		$this->assertSame( $pushes, $this->rows_in( 'pushes' ) );
		$this->assertSame( $sync, $this->rows_in( 'sync_state' ), 'sync state kept' );
		$this->assertFileExists( $this->snapshot_file );
		$this->assertSame( 'snapshot bytes', file_get_contents( $this->snapshot_file ) );
		$this->assertSame( $post_uuid, $this->uuid_meta( 'post' ), 'post UUIDs kept' );
		$this->assertSame( $term_uuid, $this->uuid_meta( 'term' ), 'term UUIDs kept' );
		$this->assertSame( $settings, get_option( Settings::OPTION ), 'settings kept' );
		$this->assertSame( $site_id, Installer::site_id(), 'site ID kept' );
		$this->assertIsArray( get_option( 'softglaze_sp_baseline' ) );

		// Reinstall: the tables and role come back, history is there, pairings need a new key.
		Installer::activate();
		$this->assertTrue( $this->table_exists( 'nonces' ) );
		$this->assertTrue( $this->table_exists( 'throttle' ) );
		$this->assertSame( array( Installer::CAPABILITY => true ), get_role( Installer::ROLE )->capabilities );
		$this->assertNotFalse( wp_next_scheduled( 'softglaze_sp_daily_prune' ) );
		$this->assertSame( $site_id, Installer::ensure_site_id() );
		$this->assertSame( $audit + 1, $this->rows_in( 'audit' ) );
		$this->assertSame( $items, $this->rows_in( 'push_items' ) );
		$this->assertSame( 'repair_needed', Pairing_Store::find( $this->pairing_uuid )['status'] );
		wp_set_current_user( self::$admin );
		$history = $this->admin( 'GET', '/keepmerge/v1/admin/pushes' );
		$this->assertSame( 200, $history->get_status() );
		$this->assertNotEmpty( wp_json_encode( $history->get_data() ) );
		$audit_screen = $this->admin( 'GET', '/keepmerge/v1/admin/audit' );
		$this->assertSame( 200, $audit_screen->get_status() );
		$this->assertStringContainsString( 'data_removed', wp_json_encode( $audit_screen->get_data() ) );
	}

	private function count_status( $status ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', Tables::table_name( 'pairings' ), $status ) );
	}

	// --- U-02 ---------------------------------------------------------------------------------------

	public function test_u02_uninstall_with_the_setting_on_leaves_nothing_of_the_plugin() {
		global $wpdb;
		$this->seed();
		$settings                             = Settings::get();
		$settings['remove_data_on_uninstall'] = true;
		Settings::save( $settings );
		$this->assertTrue( Settings::get()['remove_data_on_uninstall'] );

		// More UUID meta than one batch, so the bounded batches loop.
		$values = array();
		for ( $i = 0; $i < 2500; $i++ ) {
			$values[] = $wpdb->prepare( '(%d, %s, %s)', $this->page_id, Identity::META_KEY, 'u-' . $i );
		}
		$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $values ) ); // phpcs:ignore
		$this->assertGreaterThan( 2500, $this->uuid_meta( 'post' ) );
		$this->assertGreaterThan( 5, count( $this->our_options() ) );
		$this->assertCount( 7, $this->our_tables() );

		$receiver = $this->receiver;
		$this->uninstall();

		$this->assertSame( array(), $this->our_options(), 'zero softglaze_sp_ options' );
		$this->assertSame( array(), $this->our_tables(), 'zero softglaze_sp_ tables' );
		$this->assertSame( 0, $this->uuid_meta( 'post' ), 'zero post UUID meta' );
		$this->assertSame( 0, $this->uuid_meta( 'term' ), 'zero term UUID meta' );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key LIKE %s', $wpdb->postmeta, '%' . $wpdb->esc_like( 'softglaze_sp' ) . '%' ) ) );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE meta_key LIKE %s OR meta_value LIKE %s', $wpdb->usermeta, '%' . $wpdb->esc_like( 'softglaze_sp' ) . '%', '%' . $wpdb->esc_like( 'softglaze_sp_' ) . '%' ) ) );
		$this->assertSame( array(), $this->cron_hooks(), 'zero cron events' );
		$this->assertDirectoryDoesNotExist( $this->plugin_dir(), 'uploads/keepmerge-push-staging-to-live/ gone' );
		$this->assertFalse( get_userdata( $receiver ) );
		$this->assertNull( get_role( Installer::ROLE ) );
		$this->assertSame( 'U page (staging)', get_post( $this->page_id )->post_title, 'content itself untouched' );
	}

	// --- U-03 ---------------------------------------------------------------------------------------

	public function test_u03_deactivate_and_reactivate_on_both_sides_revokes_credentials_and_keeps_data() {
		$this->seed();
		$items    = $this->rows_in( 'push_items' );
		$sync     = $this->rows_in( 'sync_state' );
		$audit    = $this->rows_in( 'audit' );
		$receiver = $this->receiver;

		Installer::deactivate();

		$this->assertSame( 0, $this->credential_columns_left(), 'destination and source credentials revoked' );
		$this->assertCount( 0, WP_Application_Passwords::get_user_application_passwords( $receiver ) );
		$this->assertSame( 'repair_needed', Pairing_Store::find( $this->pairing_uuid )['status'] );
		$this->assertSame( 'repair_needed', Pairing_Store::find( self::SOURCE_PAIRING )['status'] );
		$this->assertFalse( Receive_Window::state()['open'] );
		$this->assertFalse( get_option( Push_Lock::OPTION ) );
		$this->assertSame( array(), $this->cron_hooks(), 'cron cleared' );
		$this->assertFileDoesNotExist( $this->temp_file );
		$this->assertDirectoryDoesNotExist( $this->plugin_dir() . '/tmp' );
		$this->assertFileExists( $this->snapshot_file, 'snapshots intact' );
		$this->assertSame( $items, $this->rows_in( 'push_items' ) );
		$this->assertSame( $sync, $this->rows_in( 'sync_state' ) );
		$this->assertGreaterThanOrEqual( $audit, $this->rows_in( 'audit' ) );

		Installer::activate();

		$this->assertNotFalse( wp_next_scheduled( 'softglaze_sp_daily_prune' ), 'cron rescheduled' );
		$this->assertSame( array( 'softglaze_sp_daily_prune' ), $this->cron_hooks() );
		$this->assertFalse( get_userdata( $receiver ), 'the receiver of a pairing that needs a new key is an orphan and goes' );
		$this->assertSame( 'repair_needed', Pairing_Store::find( $this->pairing_uuid )['status'], 'nothing runs again without a new key' );
		$this->assertSame( 'repair_needed', Pairing_Store::find( self::SOURCE_PAIRING )['status'] );
		$this->assertFileExists( $this->snapshot_file );
		$this->assertSame( $items, $this->rows_in( 'push_items' ) );
		$this->assertSame( $sync, $this->rows_in( 'sync_state' ) );
		$this->assertSame( 401, $this->call( 'GET', '/keepmerge/v1/remote/status' )->get_status(), 'old credentials refused' );
	}

	// --- U-04 ---------------------------------------------------------------------------------------

	public function test_u04_receivers_without_a_live_pairing_are_deleted_at_activation() {
		$orphan = Receiver_User::create();
		$this->assertInstanceOf( WP_User::class, $orphan );
		$active = $this->receiver;

		Installer::activate();

		$this->assertFalse( get_userdata( $orphan->ID ), 'orphan receiver deleted' );
		$this->assertInstanceOf( WP_User::class, get_userdata( $active ), 'the active pairing keeps its receiver' );
		$this->assertSame( 200, $this->call( 'GET', '/keepmerge/v1/remote/status' )->get_status() );
	}
}

