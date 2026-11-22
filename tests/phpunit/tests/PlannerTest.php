<?php
/**
 * Sync\Planner and Sync\Plan_Store on the source: stages, classification of real rows, the
 * dependency closure, decisions (F7), expiry, the summary, and the row filters (S-74).
 * Live is played by a stub client that answers with the same site's own manifest, then changes
 * the hashes a test needs.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Sync\Baseline;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Manifest;
use SoftGlaze\SafePush\Sync\Plan_Store;
use SoftGlaze\SafePush\Sync\Planner;
use SoftGlaze\SafePush\Sync\Sync_State;
use SoftGlaze\SafePush\Tables;

/**
 * A stand-in for live: answers manifest from this site's own destination export, with
 * per-UUID overrides; answers baseline with "slug taken" for chosen local IDs; refuses code
 * reports like a live site with the switch off.
 */
class Keepmerge_Test_Live {

	public $overrides = array();
	public $taken     = array();
	public $woo       = true;
	public $calls     = array();
	public $live_changes = array();

	public function post( $route, array $body = array(), array $opts = array() ) {
		unset( $opts );
		$this->calls[] = array( $route, $body );
		if ( '/keepmerge/v1/remote/manifest' === $route ) {
			$answer = Manifest::answer( array( 'id' => 424242 ), $body );
			foreach ( $answer['items'] as $i => $item ) {
				if ( isset( $this->overrides[ $item['uuid'] ] ) ) {
					$own = isset( $item['hash'] ) ? $item['hash'] : '';
					foreach ( $this->overrides[ $item['uuid'] ] as $key => $value ) {
						// "OWN" stands for the hash this site computes now (a base equal to it).
						$answer['items'][ $i ][ $key ] = 'OWN' === $value ? $own : $value;
					}
				}
			}
			$answer['woocommerce']           = $this->woo;
			$answer['live_changes']['items'] = $this->live_changes;
			return array(
				'status' => 200,
				'data'   => $answer,
			);
		}
		if ( '/keepmerge/v1/remote/baseline' === $route ) {
			$results = array();
			foreach ( $body['fingerprints'] as $i => $fingerprint ) {
				$results[] = array(
					'i'     => $i,
					'm'     => 'none',
					'id'    => 0,
					'taken' => in_array( (int) $fingerprint['id'], $this->taken, true ) ? array( 7 ) : array(),
				);
			}
			return array(
				'status' => 200,
				'data'   => array( 'results' => $results ),
			);
		}
		if ( '/keepmerge/v1/remote/items/fetch' === $route ) {
			return array(
				'status' => 200,
				'data'   => Manifest::fetch( array( 'id' => 424242 ), $body ),
			);
		}
		return new WP_Error( 'code_report_disabled', 'off', array( 'status' => 403 ) );
	}
}

/**
 * @group planner
 */
class PlannerTest extends WP_UnitTestCase {

	const X = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
	const Y = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

	private $uuid;
	private $pairing_id;
	private $live;

	public function set_up() {
		parent::set_up();
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
		$this->pairing_id = (int) Pairing_Store::find( $this->uuid )['id'];
		update_option(
			Baseline::OPTION,
			array(
				$this->uuid => array(
					'state'         => 'done',
					'clone_date'    => '2026-10-20',
					'clone_gmt'     => strtotime( '2026-10-20 00:00:00 UTC' ),
					'clone_unknown' => false,
				),
			),
			false
		);
		$this->live = new Keepmerge_Test_Live();
	}

	private function page( $title, array $o = array() ) {
		return self::factory()->post->create( array_merge( array( 'post_type' => 'page', 'post_title' => $title, 'post_status' => 'publish' ), $o ) );
	}

	/** Links a local page to "live" as a strong match (as the baseline would). */
	private function link( $id ) {
		$uuid = Identity::assign( 'post', $id );
		Sync_State::upsert( $this->pairing_id, $uuid, 'post', $id, $id, 'strong' );
		return $uuid;
	}

	private function plan( array $groups = array() ) {
		$plan = Planner::start( $this->uuid, $groups, 1 );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_code() : '' );
		for ( $i = 0; $i < 50 && 'planning' === $plan['state']; $i++ ) {
			$plan = Planner::step( $plan['id'], array(), $this->live );
			$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_code() : '' );
		}
		$this->assertSame( 'planned', $plan['state'] );
		return $plan;
	}

	private function row( array $plan, $uuid ) {
		return Plan_Store::item( $plan['id'], $uuid );
	}

	private function content_counts() {
		global $wpdb;
		return array(
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key <> '_softglaze_sp_uuid'" ),
			(int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::table_name( 'sync_state' ) ),
		);
	}

	public function test_a_plan_classifies_each_row_and_writes_only_its_own_rows() {
		$a = $this->page( 'Staging changed' );
		$b = $this->page( 'Live changed' );
		$c = $this->page( 'Conflict' );
		$d = $this->page( 'Unchanged' );
		$e = $this->page( 'New here' );
		$f = $this->page( 'New, slug taken' );
		$ua = $this->link( $a );
		$ub = $this->link( $b );
		$uc = $this->link( $c );
		$ud = $this->link( $d );
		$ue = Identity::assign( 'post', $e );
		$uf = Identity::assign( 'post', $f );

		$this->live->overrides = array(
			$ua => array( 'hash' => self::X, 'base_hash' => self::X ),
			$ub => array( 'base_hash' => 'OWN', 'hash' => self::X ),
			$uc => array( 'hash' => self::X, 'base_hash' => self::Y ),
			$ud => array( 'base_hash' => 'OWN' ),
			$ue => array( 'state' => 'missing' ),
			$uf => array( 'state' => 'missing' ),
		);
		$this->live->taken = array( $f );

		$before = $this->content_counts();
		$plan   = $this->plan();
		$this->assertSame( $before, $this->content_counts(), 'planning writes only the plan rows' );

		$this->assertSame( 'staging_changed', $this->row( $plan, $ua )['status'] );
		$this->assertSame( 'push', $this->row( $plan, $ua )['action'] );
		$this->assertSame( 'conflict', $this->row( $plan, $uc )['status'] );
		$this->assertSame( '', $this->row( $plan, $uc )['action'] );
		$this->assertSame( 'new_staging', $this->row( $plan, $ue )['status'] );
		$this->assertSame( 'create', $this->row( $plan, $ue )['action'] );
		$this->assertSame( 'skip', $this->row( $plan, $uf )['action'] );
		$this->assertContains( 'slug_taken', $this->row( $plan, $uf )['payload']['badges'] );
		$this->assertSame( 'live_changed', $this->row( $plan, $ub )['status'] );
		$this->assertSame( 'keep_live', $this->row( $plan, $ub )['action'] );
		$this->assertSame( 'unchanged', $this->row( $plan, $ud )['status'] );

		$summary = $plan['summary'];
		$this->assertGreaterThanOrEqual( 1, $summary['undecided'] );
		$this->assertFalse( $summary['ready'] );
		$this->assertSame( 'disabled', $summary['code']['state'] );
		$this->assertSame( 64, strlen( $summary['plan_hash'] ) );

		global $wpdb;
		$done = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s AND details LIKE %s', Tables::table_name( 'audit' ), 'plan_requested', '%"stage":"done"%' ) );
		$this->assertSame( 1, $done );
	}

	public function test_live_changes_report_items_deleted_on_live_and_deleted_here() {
		$kept    = $this->page( 'Deleted on live' );
		$changed = $this->page( 'Deleted on live, changed here' );
		$gone    = $this->page( 'Deleted here' );
		$uk      = $this->link( $kept );
		$uc      = $this->link( $changed );
		$ug      = $this->link( $gone );

		$this->live->overrides = array(
			$uk => array( 'state' => 'missing' ),
			$uc => array( 'state' => 'missing' ),
			// Live still has the page this site deleted (here both "sites" share one database).
			$ug => array(
				'state'   => 'found',
				'hash'    => self::Y,
				'live_id' => $gone,
			),
		);
		$plan_first = $this->plan();
		$own        = $this->row( $plan_first, $uk )['payload']['s'];
		$this->live->live_changes = array(
			array( 'uuid' => $uk, 'state' => 'deleted', 'base_hash' => $own ),
			array( 'uuid' => $uc, 'state' => 'deleted', 'base_hash' => self::X ),
		);
		wp_delete_post( $gone, true );

		$plan = $this->plan();
		$this->assertSame( 'deleted_live', $this->row( $plan, $uk )['status'] );
		$this->assertSame( 'leave_deleted', $this->row( $plan, $uk )['action'] );
		$this->assertSame( 'deleted_live_chg', $this->row( $plan, $uc )['status'] );
		$deleted_here = $this->row( $plan, $ug );
		$this->assertSame( 'deleted_staging', $deleted_here['status'] );
		$this->assertSame( 'keep_on_live', $deleted_here['action'] );
		$this->assertSame( array( 'keep_on_live', 'trash_on_live' ), $deleted_here['payload']['choices'] );
		// The manifest was asked without a type for the deleted item.
		$asked = array();
		foreach ( $this->live->calls as $call ) {
			if ( '/keepmerge/v1/remote/manifest' === $call[0] ) {
				foreach ( $call[1]['items'] as $entry ) {
					$asked[ $entry['uuid'] ] = $entry;
				}
			}
		}
		$this->assertSame( '', $asked[ $ug ]['type'] );
		$this->assertSame( $gone, $asked[ $ug ]['live_id'] );
	}

	public function test_a_dependency_in_conflict_blocks_its_dependent_until_decided() {
		$parent = $this->page( 'Parent' );
		$child  = $this->page( 'Child', array( 'post_parent' => $parent ) );
		$up     = $this->link( $parent );
		$uc     = $this->link( $child );

		$this->live->overrides = array(
			$up => array( 'hash' => self::X, 'base_hash' => self::Y ),
			$uc => array( 'hash' => self::X, 'base_hash' => self::X ),
		);
		$plan  = $this->plan();
		$child_row = $this->row( $plan, $uc );
		$this->assertSame( 'push', $child_row['action'] );
		$this->assertSame( array( $up ), $child_row['payload']['blocked_by'] );
		$this->assertSame( 1, $plan['summary']['blocked'] );

		$after = Planner::decide( $plan['id'], array( array( 'uuid' => $up, 'action' => 'keep_live', 'group' => '' ) ), '' );
		$this->assertIsArray( $after );
		$this->assertSame( array(), $this->row( $after, $uc )['payload']['blocked_by'] );
		$this->assertSame( 0, $after['summary']['blocked'] );
	}

	public function test_new_dependencies_outside_the_scope_are_pulled_in() {
		$image = self::factory()->attachment->create_object(
			array(
				'file'           => 'kmfx/pull-in.jpg',
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Pulled-in image',
			)
		);
		$page = $this->page( 'Page with a new image' );
		set_post_thumbnail( $page, $image );
		$up = Identity::assign( 'post', $page );
		$ui = Identity::assign( 'post', $image );
		$this->live->overrides = array(
			$up => array( 'state' => 'missing' ),
			$ui => array( 'state' => 'missing' ),
		);

		$plan = $this->plan( array( 'content' ) );
		$image_row = $this->row( $plan, $ui );
		$this->assertSame( 'out_of_scope', $image_row['action'] );
		$this->assertTrue( $image_row['payload']['pulled_in'] );
		$this->assertSame( array( $up ), $image_row['payload']['pulled_in_by'] );
		$this->assertSame( 'create', Planner::effective( $image_row ) );
		$this->assertSame( array(), $this->row( $plan, $up )['payload']['blocked_by'] );
	}

	public function test_decisions_are_checked_against_the_rows_choices() {
		$c  = $this->page( 'Conflict' );
		$uc = $this->link( $c );
		$this->live->overrides = array( $uc => array( 'hash' => self::X, 'base_hash' => self::Y ) );
		$plan = $this->plan();

		$this->assertWPError( Planner::decide( $plan['id'], array( array( 'uuid' => $uc, 'action' => 'create', 'group' => '' ) ), '' ) );
		$this->assertWPError( Planner::decide( $plan['id'], array( array( 'uuid' => $uc, 'action' => 'keep_live', 'group' => 'pricing' ) ), '' ) );
		$this->assertWPError( Planner::decide( $plan['id'], array( array( 'uuid' => wp_generate_uuid4(), 'action' => 'keep_live', 'group' => '' ) ), '' ) );

		$after = Planner::decide( $plan['id'], array(), 'keep_live_remaining' );
		$this->assertSame( 'keep_live', $this->row( $after, $uc )['decision'] );
		$this->assertSame( 0, $after['summary']['undecided'] );

		$after = Planner::decide( $plan['id'], array( array( 'uuid' => $uc, 'action' => 'use_staging', 'group' => '' ) ), '' );
		$this->assertSame( 'use_staging', Planner::effective( $this->row( $after, $uc ) ) );
		$this->assertSame( 1, $after['summary']['dialog']['update'] );
		$this->assertTrue( $after['summary']['ready'] );
	}

	public function test_plans_expire_after_60_minutes_and_a_new_plan_replaces_the_old() {
		$this->page( 'Anything' );
		$plan = $this->plan();
		global $wpdb;
		$wpdb->update( Tables::table_name( 'pushes' ), array( 'started_gmt' => gmdate( 'Y-m-d H:i:s', time() - 3601 ) ), array( 'id' => $plan['id'] ) );
		$expired = Planner::usable( $plan['id'] );
		$this->assertWPError( $expired );
		$this->assertSame( 'plan_expired', $expired->get_error_code() );
		$this->assertSame( 410, $expired->get_error_data()['status'] );
		$this->assertWPError( Planner::decide( $plan['id'], array(), 'keep_live_remaining' ) );

		$next = $this->plan();
		$this->assertNull( Plan_Store::find( $plan['id'] ), 'the earlier plan of the pairing is deleted' );
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE push_id = %d', Tables::table_name( 'push_items' ), $plan['id'] ) ) );
		$this->assertNotNull( Plan_Store::find( $next['id'] ) );
	}

	public function test_a_plan_needs_an_active_pairing_and_a_baseline() {
		delete_option( Baseline::OPTION );
		$this->assertSame( 'baseline_needed', Planner::start( $this->uuid, array(), 1 )->get_error_code() );
		$this->assertSame( 'pairing_not_found', Planner::start( wp_generate_uuid4(), array(), 1 )->get_error_code() );
		update_option( Baseline::OPTION, array( $this->uuid => array( 'state' => 'running' ) ), false );
		$this->assertSame( 'baseline_needed', Planner::start( $this->uuid, array(), 1 )->get_error_code() );
		update_option( Baseline::OPTION, array( $this->uuid => array( 'state' => 'done', 'clone_unknown' => true ) ), false );
		$this->assertSame( 'invalid_scope', Planner::start( $this->uuid, array( 'orders' ), 1 )->get_error_code() );
		Pairing_Store::update( $this->pairing_id, array( 'status' => 'repair_needed' ) );
		$this->assertSame( 'pairing_not_active', Planner::start( $this->uuid, array(), 1 )->get_error_code() );
	}

	public function test_row_filters_take_allowlisted_values_only_s74() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->link( $this->page( "Row $i" ) );
		}
		$plan = $this->plan();
		global $wpdb;
		$all = Plan_Store::page( $plan['id'], array(), 1, 100 );
		$this->assertGreaterThanOrEqual( 3, $all['total'] );

		$attacks = array(
			array( 'tab' => "content' OR 1=1 --" ),
			array( 'classes' => array( "unchanged' OR '1'='1", 'conflict) OR (1=1' ) ),
			array( 'kind' => "post' UNION SELECT user_pass FROM wp_users --" ),
			array( 'search' => "' OR 1=1 --" ),
			array( 'search' => '%' ),
			array( 'search' => '_' ),
			array( 'orderby' => 'seq; DROP TABLE wp_users' ),
			array( 'orderby' => '(SELECT 1)' ),
			array( 'order' => 'DESC; DELETE FROM wp_posts' ),
		);
		foreach ( $attacks as $filters ) {
			$wpdb->last_error = '';
			$page             = Plan_Store::page( $plan['id'], $filters, 1, 100 );
			$this->assertSame( '', $wpdb->last_error, wp_json_encode( $filters ) );
			$this->assertLessThanOrEqual( $all['total'], $page['total'], wp_json_encode( $filters ) );
		}
		// Unknown values fall back to no filter; injected search text matches nothing.
		$this->assertSame( 0, Plan_Store::page( $plan['id'], array( 'search' => "' OR 1=1 --" ), 1, 100 )['total'] );
		$this->assertSame( 0, Plan_Store::page( $plan['id'], array( 'search' => '%' ), 1, 100 )['total'] );
		$this->assertSame( $all['total'], Plan_Store::page( $plan['id'], array( 'orderby' => 'seq; DROP TABLE wp_users' ), 1, 100 )['total'] );
		$this->assertSame( 1, Plan_Store::page( $plan['id'], array( 'search' => 'Row 1' ), 1, 100 )['total'] );
		$this->assertTrue( (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->users}'" ) );
	}
}
