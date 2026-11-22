<?php
/**
 * Settings: defaults, names-only fields, ranges, sanitize callback, audit of changes.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Tables;

/**
 * @group settings
 */
class SettingsTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Settings::OPTION );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_defaults_keep_code_report_and_data_removal_off() {
		$settings = Settings::get();
		$this->assertFalse( $settings['allow_code_report'] );
		$this->assertFalse( $settings['remove_data_on_uninstall'] );
		$this->assertFalse( $settings['option_groups']['woocommerce'] );
		$this->assertTrue( $settings['option_groups']['site_identity'] );
		$this->assertSame( 180, $settings['audit_days'] );
		$this->assertSame( 30, $settings['snapshot_days'] );
		$this->assertSame( array(), $settings['custom_options'] );
	}

	/**
	 * @dataProvider code_like_entries
	 *
	 * @param string $entry Entry that is not a name.
	 */
	public function test_name_fields_refuse_code( $entry ) {
		foreach ( array( 'custom_options', 'excluded_meta', 'skip_post_types', 'skip_taxonomies' ) as $field ) {
			$result = Settings::validate( array( $field => array( 'blogname', $entry ) ) );
			$this->assertArrayHasKey( $field, $result['errors'], "$field refuses $entry" );
			$this->assertNotContains( strtolower( trim( $entry ) ), $result['settings'][ $field ] );
			$this->assertNotEmpty( $result['errors'][ $field ][0]['reason'] );
		}
	}

	public function code_like_entries() {
		return array(
			'css'          => array( 'body{color:red}' ),
			'css2'         => array( '.site-header { display:none; }' ),
			'script tag'   => array( '<script>alert(1)</script>' ),
			'script bare'  => array( '<script' ),
			'php open tag' => array( '<?php echo 1; ?>' ),
			'php short'    => array( '<?= $x ?>' ),
			'html'         => array( '<b>bold</b>' ),
			'js url'       => array( 'javascript:alert(1)' ),
			'sql'          => array( "x' OR 1=1 --" ),
			'space'        => array( 'two words' ),
		);
	}

	public function test_custom_options_refuse_denylisted_names_with_a_reason() {
		$result = Settings::validate( array( 'custom_options' => array( 'siteurl', 'my_api_key', 'elementor_*' ) ) );

		$this->assertCount( 2, $result['errors']['custom_options'] );
		$entries = wp_list_pluck( $result['errors']['custom_options'], 'entry' );
		$this->assertSame( array( 'siteurl', 'my_api_key' ), $entries );
		$this->assertStringContainsString( 'never pushes', $result['errors']['custom_options'][0]['reason'] );
		$this->assertStringContainsString( 'api_key', $result['errors']['custom_options'][1]['reason'] );
		$this->assertSame( array( 'elementor_*' ), $result['settings']['custom_options'] );
	}

	public function test_names_are_lowercased_trimmed_and_deduplicated() {
		$result = Settings::validate( array( 'excluded_meta' => array( ' _My_Cache ', '_my_cache', '' ) ) );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( array( '_my_cache' ), $result['settings']['excluded_meta'] );
	}

	public function test_numbers_must_be_in_range() {
		$result = Settings::validate( array( 'item_batch' => 5 ) );
		$this->assertArrayHasKey( 'item_batch', $result['errors'] );
		$this->assertSame( 50, $result['settings']['item_batch'] );

		$result = Settings::validate( array( 'item_batch' => 100, 'audit_days' => '365' ) );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 100, $result['settings']['item_batch'] );
		$this->assertSame( 365, $result['settings']['audit_days'] );

		$result = Settings::validate( array( 'chunk_kb' => '1e3' ) );
		$this->assertArrayHasKey( 'chunk_kb', $result['errors'] );
	}

	public function test_booleans_are_strict() {
		$this->assertTrue( Settings::validate( array( 'allow_code_report' => 'yes' ) )['settings']['allow_code_report'] );
		$this->assertFalse( Settings::validate( array( 'allow_code_report' => 'please' ) )['settings']['allow_code_report'] );
		$this->assertFalse( Settings::validate( array( 'allow_code_report' => array( true ) ) )['settings']['allow_code_report'] );
	}

	public function test_unknown_keys_are_dropped() {
		$result = Settings::validate( array( 'custom_css' => 'body{}', 'header_scripts' => '<script>' ) );
		$this->assertArrayNotHasKey( 'custom_css', $result['settings'] );
		$this->assertArrayNotHasKey( 'header_scripts', $result['settings'] );
	}

	public function test_sanitize_callback_drops_invalid_entries_on_direct_update() {
		update_option(
			Settings::OPTION,
			array(
				'custom_options'    => array( 'siteurl', 'elementor_*', '<script>' ),
				'allow_code_report' => 'on',
				'item_batch'        => 9999,
				'evil'              => 'x',
			)
		);
		$stored = get_option( Settings::OPTION );

		$this->assertSame( array( 'elementor_*' ), $stored['custom_options'] );
		$this->assertTrue( $stored['allow_code_report'] );
		$this->assertSame( 50, $stored['item_batch'] );
		$this->assertArrayNotHasKey( 'evil', $stored );
	}

	public function test_save_refuses_everything_when_one_entry_is_bad() {
		$before = Settings::get();
		$result = Settings::save(
			array(
				'audit_days'     => 90,
				'custom_options' => array( 'blogname', 'siteurl' ),
			)
		);

		$this->assertFalse( $result['saved'] );
		$this->assertSame( $before, Settings::get(), 'nothing saved' );
	}

	public function test_save_audits_changed_keys_only() {
		global $wpdb;

		$result = Settings::save(
			array(
				'audit_days'     => 90,
				'custom_options' => array( 'elementor_*' ),
			)
		);
		$this->assertTrue( $result['saved'] );
		$this->assertSame( array( 'custom_options', 'audit_days' ), $result['changed'] );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE event = %s ORDER BY id DESC LIMIT 1', Tables::table_name( 'audit' ), 'settings_changed' ), ARRAY_A );
		$this->assertNotNull( $row );
		$details = json_decode( $row['details'], true );
		$this->assertSame( array( 'keys' => array( 'custom_options', 'audit_days' ) ), $details );
		$this->assertStringNotContainsString( 'elementor', $row['details'], 'values are never logged' );
		$this->assertSame( 'user', $row['actor_type'] );
		$this->assertSame( (string) get_current_user_id(), $row['actor_id'] );
	}

	public function test_code_report_switch_is_audited() {
		global $wpdb;
		$table = Tables::table_name( 'audit' );

		Settings::save( array( 'allow_code_report' => true ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', $table, 'code_report_enabled' ) ) );

		Settings::save( array( 'allow_code_report' => false ) );
		$this->assertSame( '1', $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', $table, 'code_report_disabled' ) ) );
	}

	public function test_saving_the_same_values_writes_no_audit_row() {
		global $wpdb;
		$table = Tables::table_name( 'audit' );

		Settings::save( array( 'audit_days' => 120 ) );
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', $table, 'settings_changed' ) );

		$result = Settings::save( array( 'audit_days' => 120 ) );
		$this->assertSame( array(), $result['changed'] );
		$this->assertSame( $count, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', $table, 'settings_changed' ) ) );
	}
}
