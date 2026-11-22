<?php
/**
 * Sync\Classifier: the three-way table, the first-sync date rules and the product field groups
 * (F-03, F-09 classification part).
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Sync\Classifier;

/**
 * @group planner
 */
class ClassifierTest extends WP_UnitTestCase {

	const S = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	const L = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
	const B = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

	private function run_case( array $in ) {
		return Classifier::classify(
			array_merge(
				array(
					'kind'        => 'post',
					'exists_here' => true,
					'confidence'  => 'strong',
					'live_state'  => 'found',
				),
				$in
			)
		);
	}

	private function assertRow( $class, $action, array $result, array $badges = array() ) {
		$this->assertSame( $class, $result['class'] );
		$this->assertSame( $action, $result['action'] );
		foreach ( $badges as $badge ) {
			$this->assertContains( $badge, $result['badges'] );
		}
	}

	// --- three-way, with a base ----------------------------------------------------------------

	public function test_unchanged() {
		$this->assertRow( 'unchanged', 'none', $this->run_case( array( 's' => self::B, 'l' => self::B, 'b' => self::B ) ) );
	}

	public function test_changed_on_staging_pushes() {
		$r = $this->run_case( array( 's' => self::S, 'l' => self::B, 'b' => self::B ) );
		$this->assertRow( 'staging_changed', 'push', $r );
		$this->assertSame( array( 'push', 'skip' ), $r['choices'] );
	}

	public function test_changed_on_live_keeps_live() {
		$r = $this->run_case( array( 's' => self::B, 'l' => self::L, 'b' => self::B ) );
		$this->assertRow( 'live_changed', 'keep_live', $r );
		$this->assertSame( array( 'keep_live', 'use_staging' ), $r['choices'] );
	}

	public function test_changed_identically_records_the_base() {
		$this->assertRow( 'both_same', 'record_base', $this->run_case( array( 's' => self::S, 'l' => self::S, 'b' => self::B ) ) );
	}

	public function test_conflict_is_undecided() {
		$r = $this->run_case( array( 's' => self::S, 'l' => self::L, 'b' => self::B ) );
		$this->assertRow( 'conflict', '', $r );
		$this->assertSame( array( 'keep_live', 'use_staging' ), $r['choices'] );
	}

	public function test_new_on_staging_creates_or_skips_when_the_slug_is_taken() {
		$this->assertRow( 'new_staging', 'create', $this->run_case( array( 'confidence' => '', 'live_state' => 'missing', 's' => self::S ) ) );
		$this->assertRow( 'new_staging', 'create', $this->run_case( array( 'confidence' => 'none', 'live_state' => 'missing', 's' => self::S ) ) );
		$this->assertRow( 'new_staging', 'skip', $this->run_case( array( 'confidence' => '', 'live_state' => 'missing', 's' => self::S, 'slug_taken' => true ) ), array( 'slug_taken' ) );
		$this->assertRow( 'new_staging', 'skip', $this->run_case( array( 'confidence' => 'duplicate', 'live_state' => 'missing', 's' => self::S ) ), array( 'slug_taken' ) );
	}

	public function test_deleted_on_staging_keeps_live_and_offers_trash_for_posts_only() {
		$r = $this->run_case( array( 'exists_here' => false, 's' => '', 'l' => self::B, 'b' => self::B ) );
		$this->assertRow( 'deleted_staging', 'keep_on_live', $r );
		$this->assertSame( array( 'keep_on_live', 'trash_on_live' ), $r['choices'] );

		$product = $this->run_case( array( 'kind' => 'product', 'exists_here' => false, 's' => '', 'l' => self::B, 'b' => self::B ) );
		$this->assertSame( array( 'keep_on_live' ), $product['choices'], 'products are never trashed by a push' );
		$term = $this->run_case( array( 'kind' => 'term', 'exists_here' => false, 's' => '', 'l' => self::B, 'b' => self::B ) );
		$this->assertSame( array( 'keep_on_live' ), $term['choices'] );
	}

	public function test_deleted_on_live_changed_on_staging_is_a_conflict() {
		$r = $this->run_case( array( 'live_state' => 'missing', 's' => self::S, 'l' => '', 'b' => self::B ) );
		$this->assertRow( 'deleted_live_chg', '', $r );
		$this->assertSame( array( 'recreate', 'leave_deleted' ), $r['choices'] );
	}

	public function test_deleted_on_live_stays_deleted() {
		$this->assertRow( 'deleted_live', 'leave_deleted', $this->run_case( array( 'live_state' => 'missing', 's' => self::B, 'l' => '', 'b' => self::B ) ) );
	}

	public function test_gone_from_both_sites_needs_nothing() {
		$this->assertRow( 'unchanged', 'none', $this->run_case( array( 'exists_here' => false, 'live_state' => 'missing', 's' => '', 'l' => '', 'b' => self::B ) ), array( 'gone_both' ) );
	}

	public function test_unsupported_and_excluded_are_skipped() {
		$this->assertRow( 'unsupported', 'skip', $this->run_case( array( 's' => self::S, 'l' => self::L, 'b' => self::B, 'unsupported' => true ) ) );
		$this->assertRow( 'excluded', 'skip', $this->run_case( array( 'live_state' => 'excluded', 's' => self::S ) ) );
	}

	// --- first sync: no base yet ---------------------------------------------------------------

	private function first_sync( array $in ) {
		return $this->run_case(
			array_merge(
				array(
					's'          => self::S,
					'l'          => self::L,
					'b'          => '',
					'clone_gmt'  => strtotime( '2026-10-20 00:00:00 UTC' ),
					's_modified' => '2026-10-01 09:00:00',
					'l_modified' => '2026-10-01 09:00:00',
				),
				$in
			)
		);
	}

	public function test_first_sync_equal_items_are_unchanged() {
		$this->assertRow( 'unchanged', 'none', $this->first_sync( array( 'l' => self::S ) ) );
	}

	public function test_first_sync_staging_changed_after_the_clone_pushes_by_dates() {
		$this->assertRow( 'staging_changed', 'push', $this->first_sync( array( 's_modified' => '2026-10-25 10:00:00' ) ), array( 'by_dates' ) );
	}

	public function test_first_sync_live_changed_after_the_clone_keeps_live_by_dates() {
		$this->assertRow( 'live_changed', 'keep_live', $this->first_sync( array( 'l_modified' => '2026-10-25 10:00:00' ) ), array( 'by_dates' ) );
	}

	public function test_first_sync_both_changed_is_a_conflict() {
		$this->assertRow( 'conflict', '', $this->first_sync( array( 's_modified' => '2026-10-21 10:00:00', 'l_modified' => '2026-10-22 10:00:00' ) ), array( 'changed_both_since_clone' ) );
	}

	public function test_first_sync_differs_without_date_changes_is_a_conflict() {
		$this->assertRow( 'conflict', '', $this->first_sync( array() ), array( 'no_history' ) );
	}

	public function test_first_sync_the_clone_day_itself_counts_as_after_the_clone() {
		// The clone date is a day; anything on that day may be after the copy, so it counts as
		// changed (the cautious side: two such changes make a conflict, never a push over live).
		$this->assertRow( 'conflict', '', $this->first_sync( array( 's_modified' => '2026-10-20 08:00:00', 'l_modified' => '2026-10-20 07:00:00' ) ), array( 'changed_both_since_clone' ) );
	}

	public function test_first_sync_unknown_clone_date_is_a_conflict() {
		$this->assertRow( 'conflict', '', $this->first_sync( array( 'clone_gmt' => null, 's_modified' => '2026-10-25 10:00:00' ) ), array( 'no_history' ) );
	}

	public function test_first_sync_items_without_timestamps_are_conflicts() {
		foreach ( array( 'term', 'menu', 'option', 'widgets' ) as $kind ) {
			$this->assertRow( 'conflict', '', $this->first_sync( array( 'kind' => $kind, 's_modified' => '2026-10-25 10:00:00' ) ), array( 'no_history' ) );
		}
		$this->assertRow( 'conflict', '', $this->first_sync( array( 's_modified' => '' ) ), array( 'no_history' ) );
	}

	public function test_first_sync_medium_matches_that_differ_are_conflicts_whatever_the_dates() {
		$r = $this->first_sync( array( 'confidence' => 'medium', 's_modified' => '2026-10-25 10:00:00' ) );
		$this->assertRow( 'conflict', '', $r, array( 'matched_slug_date' ) );
		$this->assertRow( 'unchanged', 'none', $this->first_sync( array( 'confidence' => 'medium', 'l' => self::S ) ), array( 'matched_slug_date' ) );
	}

	public function test_first_sync_unlinked_items_found_on_live_are_conflicts() {
		$this->assertRow( 'conflict', '', $this->first_sync( array( 'confidence' => '', 's_modified' => '2026-10-25 10:00:00' ) ), array( 'no_history' ) );
	}

	public function test_first_sync_deleted_on_live_uses_staging_dates() {
		$this->assertRow( 'deleted_live_chg', '', $this->first_sync( array( 'live_state' => 'missing', 'l' => '', 's_modified' => '2026-10-25 10:00:00' ) ), array( 'by_dates' ) );
		$this->assertRow( 'deleted_live', 'leave_deleted', $this->first_sync( array( 'live_state' => 'missing', 'l' => '' ) ), array( 'by_dates' ) );
		$this->assertRow( 'deleted_live_chg', '', $this->first_sync( array( 'live_state' => 'missing', 'l' => '', 'clone_gmt' => null ) ), array( 'no_history' ) );
	}

	// --- products --------------------------------------------------------------------------------

	private function groups( $content, $pricing, $identity, $owned ) {
		return array(
			'content'    => $content,
			'pricing'    => $pricing,
			'identity'   => $identity,
			'live_owned' => $owned,
		);
	}

	private function product( array $s, array $l, array $b, $item_s = self::S, $item_l = self::L, $item_b = self::B ) {
		return $this->run_case(
			array(
				'kind'     => 'product',
				's'        => $item_s,
				'l'        => $item_l,
				'b'        => $item_b,
				's_groups' => $s,
				'l_groups' => $l,
				'b_groups' => $b,
			)
		);
	}

	public function test_price_on_live_and_description_on_staging_split_the_product() {
		$base = $this->groups( 'c0', 'p0', 'i0', 'o0' );
		$r    = $this->product( $this->groups( 'c1', 'p0', 'i0', 'o0' ), $this->groups( 'c0', 'p1', 'i0', 'o0' ), $base );
		$this->assertSame( 'staging_changed', $r['class'] );
		$this->assertSame( 'push', $r['action'] );
		$this->assertSame( 'staging_changed', $r['groups']['content']['class'] );
		$this->assertSame( 'push', $r['groups']['content']['action'] );
		$this->assertSame( 'live_changed', $r['groups']['pricing']['class'] );
		$this->assertSame( 'keep_live', $r['groups']['pricing']['action'] );
		$this->assertSame( 'unchanged', $r['groups']['identity']['class'] );
	}

	public function test_price_changed_on_both_is_a_pricing_conflict() {
		$base = $this->groups( 'c0', 'p0', 'i0', 'o0' );
		$r    = $this->product( $this->groups( 'c0', 'p2', 'i0', 'o0' ), $this->groups( 'c0', 'p1', 'i0', 'o0' ), $base );
		$this->assertSame( 'conflict', $r['class'] );
		$this->assertSame( '', $r['action'] );
		$this->assertSame( 'conflict', $r['groups']['pricing']['class'] );
		$this->assertSame( array( 'keep_live', 'use_staging' ), $r['groups']['pricing']['choices'] );
		$this->assertSame( 'unchanged', $r['groups']['content']['class'] );
	}

	public function test_stock_is_live_owned_and_push_stock_needs_unchanged_live_stock() {
		$base = $this->groups( 'c0', 'p0', 'i0', 'o0' );
		// Staging's stock differs, live's is unchanged since the base: "Push stock" offered, not default.
		$r = $this->product( $this->groups( 'c1', 'p0', 'i0', 'o1' ), $this->groups( 'c0', 'p0', 'i0', 'o0' ), $base );
		$this->assertSame( 'keep_live', $r['groups']['live_owned']['action'] );
		$this->assertSame( array( 'keep_live', 'push_stock' ), $r['groups']['live_owned']['choices'] );
		// Live sold something since the base: refused.
		$r = $this->product( $this->groups( 'c1', 'p0', 'i0', 'o1' ), $this->groups( 'c0', 'p0', 'i0', 'o2' ), $base );
		$this->assertSame( array( 'keep_live' ), $r['groups']['live_owned']['choices'] );
		$this->assertContains( 'stock_changed_on_live', $r['groups']['live_owned']['badges'] );
		// Without a base it cannot be shown that live's stock is unchanged: refused.
		$r = $this->product( $this->groups( 'c1', 'p0', 'i0', 'o1' ), $this->groups( 'c0', 'p0', 'i0', 'o0' ), array(), self::S, self::L, '' );
		$this->assertSame( array( 'keep_live' ), $r['groups']['live_owned']['choices'] );
	}

	public function test_a_sale_on_live_alone_leaves_the_product_unchanged() {
		$base = $this->groups( 'c0', 'p0', 'i0', 'o0' );
		$r    = $this->product( $this->groups( 'c0', 'p0', 'i0', 'o0' ), $this->groups( 'c0', 'p0', 'i0', 'o9' ), $base, self::B, self::B, self::B );
		$this->assertSame( 'unchanged', $r['class'] );
		$this->assertSame( 'none', $r['action'] );
	}

	public function test_product_groups_without_base_use_the_item_dates() {
		$r = $this->run_case(
			array(
				'kind'       => 'product',
				's'          => self::S,
				'l'          => self::L,
				'b'          => '',
				's_groups'   => $this->groups( 'c1', 'p0', 'i0', 'o0' ),
				'l_groups'   => $this->groups( 'c0', 'p0', 'i0', 'o0' ),
				'clone_gmt'  => strtotime( '2026-10-20 00:00:00 UTC' ),
				's_modified' => '2026-10-25 10:00:00',
				'l_modified' => '2026-10-01 09:00:00',
			)
		);
		$this->assertSame( 'push', $r['groups']['content']['action'] );
		$this->assertContains( 'by_dates', $r['groups']['content']['badges'] );
		$this->assertSame( 'unchanged', $r['groups']['pricing']['class'] );
	}

	public function test_a_base_without_group_hashes_still_tells_which_side_moved() {
		$r = $this->product( $this->groups( 'c1', 'p0', 'i0', 'o0' ), $this->groups( 'c0', 'p0', 'i0', 'o0' ), array(), self::S, self::B, self::B );
		$this->assertSame( 'push', $r['groups']['content']['action'] );
		$this->assertSame( 'unchanged', $r['groups']['pricing']['class'] );
	}

	public function test_differences_outside_the_groups_count_as_content() {
		$same = $this->groups( 'c0', 'p0', 'i0', 'o0' );
		$r    = $this->product( $same, $same, $same, self::S, self::B, self::B );
		$this->assertSame( 'staging_changed', $r['groups']['content']['class'] );
	}
}
