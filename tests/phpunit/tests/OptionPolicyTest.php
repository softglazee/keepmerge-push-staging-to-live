<?php
/**
 * Option_Policy: hard denylist, patterns, filters that can only add, meta exclusions.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Sync\Option_Policy;

/**
 * @group policy
 */
class OptionPolicyTest extends WP_UnitTestCase {

	/**
	 * @dataProvider denied_names
	 *
	 * @param string $name Option name.
	 */
	public function test_denied_options( $name ) {
		$this->assertNotSame( '', Option_Policy::option_denial_reason( $name ), $name );
	}

	public function denied_names() {
		$names = array(
			'siteurl',
			'home',
			'admin_email',
			'new_admin_email',
			'users_can_register',
			'default_role',
			'wp_user_roles',
			'wptests_user_roles',
			'active_plugins',
			'template',
			'stylesheet',
			'cron',
			'rewrite_rules',
			'permalink_structure',
			'WPLANG',
			'wplang',
			'secure_auth_key',
			'mailserver_url',
			'auto_update_plugins',
			'auto_core_update_notified',
			'_transient_doing_cron',
			'_site_transient_update_core',
			'softglaze_sp_settings',
			'woocommerce_version',
			'woocommerce_stripe_settings',
			'woocommerce_api_enabled',
			'woocommerce_store_address',
			'woocommerce_onboarding_profile',
			'action_scheduler_lock_async',
			'my_api_key',
			'mailchimp_apikey',
			'smtp_password',
			'stripe_secret',
			'github_token',
			'plugin_license_key',
			'theme_licence',
			'sso_private_key',
			'oauth_client_id',
			'aws_credentials',
		);
		$out   = array();
		foreach ( $names as $name ) {
			$out[ $name ] = array( $name );
		}
		return $out;
	}

	/**
	 * @dataProvider allowed_names
	 *
	 * @param string $name Option name.
	 */
	public function test_allowed_options( $name ) {
		$this->assertSame( '', Option_Policy::option_denial_reason( $name ), $name );
	}

	public function allowed_names() {
		return array(
			'blogname'                 => array( 'blogname' ),
			'page_on_front'            => array( 'page_on_front' ),
			'widget_text'              => array( 'widget_text' ),
			'theme_mods'               => array( 'theme_mods_twentytwentyfive' ),
			'woocommerce_shop_page_id' => array( 'woocommerce_shop_page_id' ),
			'elementor_cpt_support'    => array( 'elementor_cpt_support' ),
		);
	}

	public function test_a_filter_returning_the_list_minus_entries_removes_nothing() {
		add_filter(
			'softglaze_sp_option_denylist',
			static function ( $extra, $hard ) {
				return array_values( array_diff( $hard, array( 'siteurl', '*secret*' ) ) );
			},
			10,
			2
		);

		$this->assertNotSame( '', Option_Policy::option_denial_reason( 'siteurl' ) );
		$this->assertNotSame( '', Option_Policy::option_denial_reason( 'stripe_secret' ) );
		foreach ( Option_Policy::HARD_DENYLIST as $entry ) {
			$this->assertContains( $entry, Option_Policy::option_denylist() );
		}
	}

	public function test_an_empty_or_broken_filter_result_removes_nothing() {
		add_filter( 'softglaze_sp_option_denylist', '__return_empty_array' );
		$this->assertNotSame( '', Option_Policy::option_denial_reason( 'active_plugins' ) );

		add_filter(
			'softglaze_sp_option_denylist',
			static function () {
				return 'not an array';
			},
			30
		);
		$this->assertNotSame( '', Option_Policy::option_denial_reason( 'active_plugins' ) );
	}

	public function test_filters_can_add_entries() {
		add_filter(
			'softglaze_sp_option_denylist',
			static function ( $extra ) {
				$extra[] = 'my_plugin_state';
				$extra[] = 'cache_plugin_*';
				$extra[] = '<script>';
				return $extra;
			}
		);

		$this->assertNotSame( '', Option_Policy::option_denial_reason( 'my_plugin_state' ) );
		$this->assertNotSame( '', Option_Policy::option_denial_reason( 'cache_plugin_paths' ) );
		$this->assertNotContains( '<script>', Option_Policy::option_denylist(), 'malformed filter entries are ignored' );
	}

	/**
	 * @dataProvider custom_entries
	 *
	 * @param string $entry    Custom allowlist entry.
	 * @param bool   $accepted Whether it is accepted.
	 */
	public function test_custom_entries( $entry, $accepted ) {
		$reason = Option_Policy::custom_entry_reason( $entry );
		if ( $accepted ) {
			$this->assertSame( '', $reason, $entry );
		} else {
			$this->assertNotSame( '', $reason, $entry );
		}
	}

	public function custom_entries() {
		return array(
			'siteurl'            => array( 'siteurl', false ),
			'my_api_key'         => array( 'my_api_key', false ),
			'prefix covers home' => array( 'hom*', false ),
			'prefix site*'       => array( 'site*', false ),
			'woocommerce_*'      => array( 'woocommerce_*', false ),
			'too short'          => array( 'ab*', false ),
			'transient prefix'   => array( '_transient_*', false ),
			'secret prefix'      => array( 'my_secret_*', false ),
			'own prefix'         => array( 'softglaze_sp_*', false ),
			'elementor_*'        => array( 'elementor_*', true ),
			'widget_custom'      => array( 'widget_custom', true ),
			'blogname'           => array( 'blogname', true ),
		);
	}

	public function test_meta_exclusions() {
		foreach ( array( '_edit_lock', '_wp_trash_meta_status', '_oembed_123', '_softglaze_sp_uuid', '_stock', '_stock_status', 'total_sales', '_order_total', '_thumbnail_id', 'my_api_key', 'download_token' ) as $key ) {
			$this->assertNotSame( '', Option_Policy::meta_exclusion( $key ), $key );
		}
		foreach ( array( '_elementor_data', '_price', '_wp_page_template', 'subtitle' ) as $key ) {
			$this->assertSame( '', Option_Policy::meta_exclusion( $key ), $key );
		}
		$this->assertNotSame( '', Option_Policy::meta_exclusion( '_my_cache_x', array( '_my_cache_*' ) ) );
	}

	public function test_meta_filters_cannot_remove_hard_entries() {
		add_filter( 'softglaze_sp_excluded_meta_keys', '__return_empty_array' );
		$this->assertNotSame( '', Option_Policy::meta_exclusion( '_stock' ) );
	}

	public function test_pattern_matching() {
		$this->assertTrue( Option_Policy::matches( 'woocommerce_paypal_settings', 'woocommerce_*_settings' ) );
		$this->assertFalse( Option_Policy::matches( 'woocommerce_settings_x', 'woocommerce_*_settings' ) );
		$this->assertTrue( Option_Policy::matches( 'X_SECRET_Y', '*secret*' ) );
		$this->assertTrue( Option_Policy::matches( 'abc_user_roles', '*user_roles' ) );
		$this->assertFalse( Option_Policy::matches( 'user_roles_backup', '*user_roles' ) );
	}
}
