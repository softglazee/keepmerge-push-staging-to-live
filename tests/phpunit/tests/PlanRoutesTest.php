<?php
/**
 * Admin routes of the plan and the code report: manage_options and the
 * wp_rest nonce, every input read by name against an allowlist (S-74 at the route).
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Sync\Baseline;
use SoftGlaze\SafePush\Sync\Planner;

/**
 * @group rest
 * @group planner
 */
class PlanRoutesTest extends WP_UnitTestCase {

	private static $admin;
	private static $editor;
	private $uuid;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
	}

	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		do_action( 'rest_api_init', $wp_rest_server );

		$this->uuid = wp_generate_uuid4();
		Pairing_Store::insert(
			array(
				'pairing_uuid'    => $this->uuid,
				'role'            => 'source',
				'status'          => 'active',
				'remote_url'      => 'https://live.example.com/',
				'rest_root'       => 'https://live.example.com/wp-json/',
				'local_home_url'  => Installer::normalised_home(),
				'local_home_hash' => Installer::home_hash(),
				'created_gmt'     => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		update_option( Baseline::OPTION, array( $this->uuid => array( 'state' => 'done', 'clone_unknown' => true ) ), false );
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		parent::tear_down();
	}

	private function request( $method, $route, array $params = array(), $nonce = true ) {
		$request = new WP_REST_Request( $method, $route );
		if ( $nonce ) {
			$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		}
		foreach ( $params as $key => $value ) {
			if ( 'GET' === $method ) {
				$request->set_query_params( array_merge( $request->get_query_params(), array( $key => $value ) ) );
			} else {
				$request->set_body_params( array_merge( $request->get_body_params(), array( $key => $value ) ) );
			}
		}
		return rest_get_server()->dispatch( $request );
	}

	/** A finished plan made with a stub live (PlannerTest's). */
	private function plan() {
		if ( ! class_exists( 'Keepmerge_Test_Live' ) ) {
			require_once __DIR__ . '/PlannerTest.php';
		}
		self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Route row' ) );
		$plan = Planner::start( $this->uuid, array(), self::$admin );
		$live = new Keepmerge_Test_Live();
		for ( $i = 0; $i < 50 && 'planning' === $plan['state']; $i++ ) {
			$plan = Planner::step( $plan['id'], array(), $live );
		}
		return $plan;
	}

	public function test_routes_exist_with_real_permission_callbacks() {
		$routes = rest_get_server()->get_routes( 'keepmerge/v1' );
		foreach ( array( '/keepmerge/v1/admin/plans', '/keepmerge/v1/admin/plans/(?P<id>[0-9]{1,18})', '/keepmerge/v1/admin/plans/(?P<id>[0-9]{1,18})/step', '/keepmerge/v1/admin/plans/(?P<id>[0-9]{1,18})/decisions', '/keepmerge/v1/admin/code-report' ) as $route ) {
			$this->assertArrayHasKey( $route, $routes );
			foreach ( $routes[ $route ] as $handler ) {
				$this->assertIsArray( $handler['permission_callback'] );
			}
		}
	}

	public function test_permission_needs_manage_options_and_the_nonce() {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->request( 'POST', '/keepmerge/v1/admin/plans', array( 'pairing' => $this->uuid ) )->get_status() );
		wp_set_current_user( self::$editor );
		$this->assertSame( 403, $this->request( 'POST', '/keepmerge/v1/admin/plans', array( 'pairing' => $this->uuid ) )->get_status() );
		wp_set_current_user( self::$admin );
		$response = $this->request( 'GET', '/keepmerge/v1/admin/code-report', array( 'pairing' => $this->uuid ), false );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_cookie_invalid_nonce', $response->get_data()['code'] );
	}

	public function test_scope_and_decisions_are_validated() {
		wp_set_current_user( self::$admin );
		$this->assertSame( 'invalid_scope', $this->request( 'POST', '/keepmerge/v1/admin/plans', array( 'pairing' => $this->uuid, 'types' => array( 'orders' ) ) )->get_data()['code'] );
		$plan = $this->plan();
		$this->assertSame( 'planned', $plan['state'] );
		$route = '/keepmerge/v1/admin/plans/' . $plan['id'] . '/decisions';
		foreach (
			array(
				array( 'decisions' => array( array( 'uuid' => "x' OR 1=1", 'action' => 'keep_live' ) ) ),
				array( 'decisions' => array( array( 'uuid' => wp_generate_uuid4(), 'action' => 'drop_table' ) ) ),
				array( 'decisions' => array( array( 'uuid' => wp_generate_uuid4(), 'action' => 'keep_live', 'group' => 'stock_hack' ) ) ),
				array( 'bulk' => 'push_everything' ),
				array(),
			) as $body
		) {
			$response = $this->request( 'POST', $route, $body );
			$this->assertSame( 400, $response->get_status(), wp_json_encode( $body ) );
		}
		$this->assertSame( 200, $this->request( 'POST', $route, array( 'bulk' => 'keep_live_remaining' ) )->get_status() );
	}

	public function test_row_filters_at_the_route_s74() {
		wp_set_current_user( self::$admin );
		global $wpdb;
		$plan  = $this->plan();
		$route = '/keepmerge/v1/admin/plans/' . $plan['id'];
		$all   = $this->request( 'GET', $route, array( 'class' => 'all', 'all' => true ) )->get_data()['rows']['total'];
		$this->assertGreaterThan( 0, $all );

		foreach (
			array(
				array( 'class' => "conflict' OR 1=1 --" ),
				array( 'tab' => "content') OR ('1'='1" ),
				array( 'kind' => 'post UNION SELECT 1' ),
				array( 'search' => "' OR 1=1 --" ),
				array( 'orderby' => 'seq,(SELECT SLEEP(1))' ),
				array( 'orderby' => 'seq;DROP TABLE x' ),
				array( 'order' => 'desc;DROP TABLE wp_options' ),
			) as $params
		) {
			$wpdb->last_error = '';
			$response         = $this->request( 'GET', $route, array_merge( array( 'all' => true ), $params ) );
			$this->assertSame( '', $wpdb->last_error );
			if ( 400 === $response->get_status() ) {
				// Longer than the argument schema allows: refused before any query.
				$this->assertSame( 'rest_invalid_param', $response->get_data()['code'], wp_json_encode( $params ) );
				continue;
			}
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $params ) );
			$this->assertLessThanOrEqual( $all, $response->get_data()['rows']['total'] );
		}
		$data = $this->request( 'GET', $route, array( 'orderby' => 'seq;DROP TABLE x', 'order' => 'desc' ) )->get_data();
		$this->assertSame( 'seq', $data['rows']['filters']['orderby'] );
		$this->assertSame( 'desc', $data['rows']['filters']['order'] );
		$data = $this->request( 'GET', $route, array( 'order' => 'or1' ) )->get_data();
		$this->assertSame( 'asc', $data['rows']['filters']['order'] );
		$this->assertSame( 0, $this->request( 'GET', $route, array( 'all' => true, 'search' => "' OR 1=1 --" ) )->get_data()['rows']['total'] );
	}

	public function test_the_diff_route_takes_plan_items_only() {
		wp_set_current_user( self::$admin );
		$plan = $this->plan();
		$this->assertSame( 404, $this->request( 'GET', '/keepmerge/v1/admin/plans/' . $plan['id'] . '/items/' . wp_generate_uuid4() . '/diff' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/keepmerge/v1/admin/plans/999999/items/' . wp_generate_uuid4() . '/diff' )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/keepmerge/v1/admin/plans/' . $plan['id'] . '/items/..%2F..%2Fwp-config/diff' )->get_status() );
	}
}
