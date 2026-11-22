<?php
/**
 * Tables and Installer: allowlisted names, seven tables, role, site ID, upgrades.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Tables;

/**
 * @group tables
 * @group installer
 */
class TablesInstallerTest extends WP_UnitTestCase {

	public function test_table_names_come_from_the_allowlist() {
		global $wpdb;

		$this->assertSame( $wpdb->prefix . 'softglaze_sp_audit', Tables::table_name( 'audit' ) );
		$this->assertCount( 7, Tables::all() );
	}

	/**
	 * @dataProvider unknown_tables
	 *
	 * @param mixed $name Table name.
	 */
	public function test_unknown_table_names_throw( $name ) {
		$this->expectException( InvalidArgumentException::class );
		Tables::table_name( $name );
	}

	public function unknown_tables() {
		return array(
			'core table'  => array( 'users' ),
			'snapshots'   => array( 'snapshots' ),
			'transfers'   => array( 'transfers' ),
			'injection'   => array( 'audit; DROP TABLE wp_users' ),
			'uppercase'   => array( 'AUDIT' ),
			'not string'  => array( array( 'audit' ) ),
			'empty'       => array( '' ),
		);
	}

	public function test_all_seven_tables_exist() {
		global $wpdb;

		$found = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'softglaze_sp_' ) . '%' ) );
		sort( $found );
		$expected = array_values( Tables::all() );
		sort( $expected );

		$this->assertSame( $expected, $found );
	}

	public function test_role_has_exactly_one_capability_and_is_repaired() {
		$role = get_role( Installer::ROLE );
		$this->assertNotNull( $role );
		$this->assertSame( array( 'softglaze_sp_receive_push' => true ), $role->capabilities );

		$role->add_cap( 'read' );
		$role->add_cap( 'edit_posts' );
		Installer::ensure_role();

		$this->assertSame( array( 'softglaze_sp_receive_push' => true ), get_role( Installer::ROLE )->capabilities );
	}

	public function test_site_id_is_stable_for_the_same_home_url() {
		$first = Installer::ensure_site_id();
		$this->assertTrue( wp_is_uuid( $first ) );
		$this->assertSame( $first, Installer::ensure_site_id() );
		$this->assertSame( $first, Installer::site_id() );
	}

	public function test_site_id_is_regenerated_when_home_url_differs() {
		global $wpdb;

		$original = Installer::ensure_site_id();
		$stored   = get_option( Installer::SITE_ID_OPTION );

		// Simulate a database copied from another site: the record is bound to another home URL.
		// Clone tools search-replace the URL itself, so only the hash still names the original.
		$stored['home_hash'] = hash( 'sha256', 'https://live.example.com' );
		update_option( Installer::SITE_ID_OPTION, $stored );

		$new = Installer::ensure_site_id();

		$this->assertNotSame( $original, $new );
		$this->assertTrue( wp_is_uuid( $new ) );
		$this->assertSame( Installer::normalised_home(), get_option( Installer::SITE_ID_OPTION )['home'] );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event = %s ORDER BY id DESC LIMIT 1', Tables::table_name( 'audit' ), 'clone_detected' ), ARRAY_A );
		$this->assertNotNull( $row );
		$this->assertSame( 'system', $row['actor_type'] );
		$details = json_decode( $row['details'], true );
		$this->assertSame( $original, $details['previous_site_id'] );
		$this->assertSame( $new, $details['site_id'] );
	}

	public function test_a_record_without_a_hash_is_upgraded_when_its_url_matches() {
		global $wpdb;

		$id = Installer::ensure_site_id();
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', Tables::table_name( 'audit' ), 'clone_detected' ) );
		$stored = get_option( Installer::SITE_ID_OPTION );
		unset( $stored['home_hash'] );
		update_option( Installer::SITE_ID_OPTION, $stored );

		$this->assertSame( $id, Installer::ensure_site_id() );
		$this->assertSame( Installer::home_hash(), get_option( Installer::SITE_ID_OPTION )['home_hash'] );
		$this->assertSame( $before, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', Tables::table_name( 'audit' ), 'clone_detected' ) ) );

		// Without a hash and with another URL, it is a copy.
		unset( $stored['home_hash'] );
		$stored['home'] = 'https://live.example.com';
		update_option( Installer::SITE_ID_OPTION, $stored );
		$this->assertNotSame( $id, Installer::ensure_site_id() );
	}

	public function test_missing_site_id_is_created_without_a_clone_event() {
		global $wpdb;

		delete_option( Installer::SITE_ID_OPTION );
		$before = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', Tables::table_name( 'audit' ), 'clone_detected' ) );

		$this->assertTrue( wp_is_uuid( Installer::ensure_site_id() ) );
		$this->assertSame( $before, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', Tables::table_name( 'audit' ), 'clone_detected' ) ) );
	}

	public function test_normalised_home_lowercases_host_and_drops_trailing_slash() {
		update_option( 'home', 'HTTPS://Live.Example.COM/Shop/' );
		$this->assertSame( 'https://live.example.com/Shop', Installer::normalised_home() );
	}

	public function test_upgrade_runs_when_the_stored_version_differs() {
		update_option( Installer::DB_VERSION_OPTION, '0.9.0' );
		Installer::maybe_upgrade();
		$this->assertSame( SOFTGLAZE_SP_VERSION, get_option( Installer::DB_VERSION_OPTION ) );
		$this->assertNotFalse( wp_next_scheduled( 'softglaze_sp_daily_prune' ) );
	}
}
