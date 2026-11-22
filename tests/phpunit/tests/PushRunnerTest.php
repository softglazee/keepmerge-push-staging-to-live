<?php
/**
 * Transfer\Push_Runner on the source: the state machine, batches, resume of a batch whose
 * answer was lost (I-01), 413 halving, 429, stubs, the step claim and abort. Live is played by
 * a stub client that runs this site's own Push_Session (JSON round trip included) under a
 * different push UUID, because both halves share one database here.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Apply\Applier;
use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Pairing_Store;
use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Sync\Baseline;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Manifest;
use SoftGlaze\SafePush\Sync\Plan_Store;
use SoftGlaze\SafePush\Sync\Planner;
use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Sync\Sync_State;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Runner;
use SoftGlaze\SafePush\Transfer\Push_Session;

/**
 * Live for the runner: manifest from this site's own export (with hash overrides), the push
 * routes from Push_Session, and hooks that fail a call the way live or the network would.
 */
class Keepmerge_Test_Push_Live {

	public $pairing   = array( 'id' => 5555 );
	public $overrides = array();
	public $hook      = null;
	public $apply_hook = null;
	public $calls     = array();
	public $items     = 0;
	public $dropped   = false;
	public $down      = false;
	public $last_push = '';
	public $applies   = 0;

	public function live_uuid( $uuid ) {
		$uuid = (string) $uuid;
		return ( 'f' === $uuid[0] ? 'e' : 'f' ) . substr( $uuid, 1 );
	}

	public function post( $route, array $body = array(), array $opts = array() ) {
		unset( $opts );
		$this->calls[] = $route;
		$body          = json_decode( wp_json_encode( empty( $body ) ? new stdClass() : $body ), true );

		if ( '/keepmerge/v1/remote/manifest' === $route ) {
			$answer = Manifest::answer( array( 'id' => 5556 ), $body );
			foreach ( $answer['items'] as $i => $item ) {
				if ( isset( $this->overrides[ $item['uuid'] ] ) ) {
					$answer['items'][ $i ] = array_merge( $item, $this->overrides[ $item['uuid'] ] );
				}
			}
			$answer['woocommerce'] = false;
			return $this->wrap( $answer );
		}
		if ( '/keepmerge/v1/remote/baseline' === $route ) {
			return $this->wrap( array( 'results' => array() ) );
		}
		if ( '/keepmerge/v1/remote/push' === $route ) {
			$body['push'] = $this->live_uuid( $body['push'] );
			return $this->wrap( Push_Session::open( $this->pairing, $body ) );
		}
		if ( 1 === preg_match( '#^/keepmerge/v1/remote/push/([0-9a-f-]{36})/apply$#', $route, $m ) ) {
			++$this->applies;
			if ( is_callable( $this->apply_hook ) ) {
				$out = call_user_func( $this->apply_hook, $body, $this );
				if ( null !== $out ) {
					return $out;
				}
			}
			$session = Push_Session::for_pairing( $this->live_uuid( $m[1] ), $this->pairing );
			return $this->wrap( is_wp_error( $session ) ? $session : Applier::remote( $session, $body ) );
		}
		if ( 1 === preg_match( '#^/keepmerge/v1/remote/push/([0-9a-f-]{36})/(items|abort)$#', $route, $m ) ) {
			if ( 'items' === $m[2] ) {
				++$this->items;
				if ( is_callable( $this->hook ) ) {
					$out = call_user_func( $this->hook, $body, $this );
					if ( null !== $out ) {
						return $out;
					}
				}
			}
			return $this->wrap( $this->session_call( $m[1], $m[2], $body ) );
		}
		return new WP_Error( 'code_report_disabled', 'off', array( 'status' => 409, 'remote_status' => 403 ) );
	}

	public function get( $route, array $query = array(), array $opts = array() ) {
		unset( $query, $opts );
		$this->calls[] = $route;
		if ( 1 === preg_match( '#^/keepmerge/v1/remote/push/([0-9a-f-]{36})$#', $route, $m ) ) {
			$session = Push_Session::for_pairing( $this->live_uuid( $m[1] ), $this->pairing );
			return $this->wrap( is_wp_error( $session ) ? $session : Push_Session::status( $session ) );
		}
		return new WP_Error( 'unexpected_status', 'x', array( 'status' => 409, 'remote_status' => 404 ) );
	}

	public function session_call( $uuid, $what, array $body ) {
		$session = Push_Session::for_pairing( $this->live_uuid( $uuid ), $this->pairing );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		return 'items' === $what ? Push_Session::receive( $session, $this->pairing, $body ) : Push_Session::abort( $session, $this->pairing );
	}

	public function wrap( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			return self::error( $result->get_error_code(), isset( $data['status'] ) ? (int) $data['status'] : 500 );
		}
		return array(
			'status' => 200,
			'data'   => json_decode( wp_json_encode( $result ), true ),
		);
	}

	public static function error( $code, $status, array $extra = array() ) {
		return new WP_Error(
			$code,
			'message for ' . $code,
			array_merge(
				array(
					'status'        => 409,
					'remote_status' => $status,
					'remote_code'   => $code,
					'signed'        => true,
					'retryable'     => false,
				),
				$extra
			)
		);
	}
}

/**
 * @group staging
 * @group runner
 */
class PushRunnerTest extends WP_UnitTestCase {

	const X = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

	private $uuid;
	private $pairing_id;
	private $live;
	private $pages = array();

	public function set_up() {
		parent::set_up();
		delete_option( Push_Lock::OPTION );
		Settings::save( array( 'item_batch' => 10 ) );
		Scope::flush();

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
		$this->live = new Keepmerge_Test_Push_Live();
	}

	/**
	 * Pages linked to "live" whose staging copy changed since the base (default: push).
	 *
	 * @param int $count Pages.
	 * @return string[] UUIDs.
	 */
	private function changed_pages( $count ) {
		$uuids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$id   = self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_title'   => 'Runner page ' . $i,
					'post_content' => '<!-- wp:paragraph --><p>Text ' . $i . '</p><!-- /wp:paragraph -->',
					'post_status'  => 'publish',
				)
			);
			$uuid = Identity::assign( 'post', $id );
			Sync_State::upsert( $this->pairing_id, $uuid, 'post', $id, $id, 'strong' );
			$this->live->overrides[ $uuid ] = array(
				'hash'      => self::X,
				'base_hash' => self::X,
			);
			$this->pages[ $uuid ]           = $id;
			$uuids[]                        = $uuid;
		}
		return $uuids;
	}

	private function plan() {
		$plan = Planner::start( $this->uuid, array( 'content' ), 1 );
		$this->assertIsArray( $plan, is_wp_error( $plan ) ? $plan->get_error_code() : '' );
		for ( $i = 0; $i < 50 && 'planning' === $plan['state']; $i++ ) {
			$plan = Planner::step( $plan['id'], array(), $this->live );
		}
		$this->assertSame( 'planned', $plan['state'] );
		return $plan;
	}

	private function start( array $plan, $confirm = 'live.example.com' ) {
		return Push_Runner::start( $plan['id'], $confirm, 1, $this->live );
	}

	private function steps( $push, $max = 20 ) {
		for ( $i = 0; $i < $max && 'sending_items' === $push['state']; $i++ ) {
			$push = Push_Runner::step( $push['id'], array(), $this->live );
			$this->assertIsArray( $push, is_wp_error( $push ) ? $push->get_error_code() . ' ' . $push->get_error_message() : '' );
		}
		return $push;
	}

	private function live_rows() {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT COUNT(*) AS n, COUNT(DISTINCT uuid) AS d FROM %i WHERE side = %s', Tables::table_name( 'push_items' ), 'destination' ), ARRAY_A );
	}

	private function events( array $push ) {
		return array_column( $push['log'], 'event' );
	}

	public function test_a_push_stages_every_item_in_batches_and_live_confirms_the_plan() {
		$this->changed_pages( 25 );
		$plan = $this->plan();
		$this->assertSame( 25, $plan['summary']['push_total'] );

		$push = $this->start( $plan );
		$this->assertSame( 'sending_items', $push['state'] );
		$this->assertSame( 25, $push['total'] );
		$this->assertSame( 10, $push['batch_size'] );

		$push = $this->steps( $push );
		$this->assertSame( 'staged', $push['state'] );
		$this->assertSame( 25, $push['sent'] );
		$this->assertSame( 3, $push['seq'] );
		$this->assertSame( 25, $push['counts']['staged'] );
		$this->assertSame( array( 'open', 'batch', 'batch', 'batch', 'staged' ), $this->events( $push ) );

		$session = Push_Session::find( $this->live->live_uuid( $push['push'] ) );
		$this->assertSame( 'staged', $session['state'] );
		$this->assertTrue( $session['cursor_json']['plan_hash_ok'] );
		$this->assertSame( array( 'n' => '25', 'd' => '25' ), $this->live_rows() );

		// The source rows remember live's answer per item.
		$row = Plan_Store::item( $push['id'], array_keys( $this->pages )[0] );
		$this->assertSame( 'staged', $row['payload']['live']['status'] );

		// The next steps call apply on live; live skips every page here because its hash moved
		// since the plan (the manifest of this test overrides live's hash), and the push ends done.
		$push = $this->steps_through( $push );
		$this->assertSame( 'done', $push['state'] );
		$this->assertSame( array( 'skipped' => 25 ), $push['applied'] );
		$this->assertSame( 'changed_during_push', $push['problems'][0]['reason'] );
		$this->assertSame( 'done', Push_Runner::step( $push['id'], array(), $this->live )['state'], 'a done push takes no more steps' );
	}

	private function steps_through( $push, $max = 20 ) {
		for ( $i = 0; $i < $max && in_array( $push['state'], array( 'sending_items', 'staged', 'applying' ), true ); $i++ ) {
			$push = Push_Runner::step( $push['id'], array(), $this->live );
			$this->assertIsArray( $push, is_wp_error( $push ) ? $push->get_error_code() : '' );
		}
		return $push;
	}

	public function test_the_apply_phase_records_lives_ids_as_links_and_ends_done() {
		// Equal on both sides but with a stale base: the plan records the base (adopt on live).
		for ( $i = 0; $i < 4; $i++ ) {
			$id   = self::factory()->post->create(
				array(
					'post_type'  => 'page',
					'post_title' => 'Adopt ' . $i,
				)
			);
			$uuid = Identity::assign( 'post', $id );
			Sync_State::upsert( $this->pairing_id, $uuid, 'post', $id, $id, 'strong' );
			$this->live->overrides[ $uuid ] = array( 'base_hash' => self::X );
			$this->pages[ $uuid ]           = $id;
		}
		$push = $this->steps_through( $this->start( $this->plan() ) );

		$this->assertSame( 'done', $push['state'], wp_json_encode( $push['error'] ) );
		$this->assertSame( array( 'applied' => 4 ), $push['applied'] );
		$this->assertContains( 'done', $this->events( $push ) );
		$this->assertGreaterThan( 0, $this->live->applies );
		foreach ( $this->pages as $uuid => $id ) {
			$link = Sync_State::find( $this->pairing_id, $uuid );
			$this->assertSame( 'pushed', $link['match_confidence'] );
			$this->assertSame( $id, (int) $link['remote_object_id'] );
			$this->assertSame( 'applied', Plan_Store::item( $push['id'], $uuid )['payload']['applied']['status'] );
		}
		$this->assertSame( 'applied', Push_Session::find( $this->live->live_uuid( $push['push'] ) )['state'] );
	}

	public function test_a_push_with_held_items_stops_at_awaiting_review_until_live_finishes_it() {
		wp_set_current_user( 1 );
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Held page',
				'post_content' => '<p>x</p><script>track()</script>',
			)
		);
		wp_set_current_user( 0 );
		$uuid = Identity::assign( 'post', $id );
		Sync_State::upsert( $this->pairing_id, $uuid, 'post', $id, $id, 'strong' );
		$this->live->overrides[ $uuid ] = array( 'hash' => self::X, 'base_hash' => self::X );

		$push = $this->steps_through( $this->start( $this->plan() ) );
		$this->assertSame( 'awaiting_review', $push['state'] );
		$this->assertSame( 1, $push['apply']['held'] );
		$this->assertContains( 'awaiting_review', $this->events( $push ) );

		// Further steps only ask live's state; apply is not called again.
		$applies = $this->live->applies;
		$push    = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 'awaiting_review', $push['state'] );
		$this->assertSame( $applies, $this->live->applies );
		// WP-CLI asks live once (the CLI's "Check status") and stops there too, without an apply call.
		$checked = Push_Runner::run( $push['id'], $this->live );
		$this->assertSame( 'awaiting_review', $checked['state'], 'WP-CLI stops here too' );
		$this->assertSame( $applies, $this->live->applies );

		// A live administrator decides and continues on live.
		$session = Push_Session::find( $this->live->live_uuid( $push['push'] ) );
		wp_set_current_user( 1 );
		\SoftGlaze\SafePush\Apply\Review::decide( $session, array( $uuid => 'skip' ), 1 );
		$live = Applier::admin_continue( Push_Session::find( $session['push_uuid'] ), 1 );
		wp_set_current_user( 0 );
		$this->assertSame( 'applied', $live['state'] );

		// `wp keepmerge push --plan=<id>`, which the CLI names for this moment, collects the
		// results and ends the push (it used to return the old review state).
		$push = Push_Runner::run( $push['id'], $this->live );
		$this->assertSame( 'done', $push['state'] );
		$this->assertSame( array( 'skipped' => 1 ), $push['applied'] );
		$this->assertSame( 'review_skipped', $push['problems'][0]['reason'] );
	}

	/**
	 * @group recovery
	 */
	public function test_i01_a_lost_answer_sends_the_same_batch_again_and_live_writes_nothing_twice() {
		$this->changed_pages( 25 );
		$push = $this->start( $this->plan() );

		// Batch 2 reaches live, but the answer never comes back (the tab or the network died).
		$this->live->hook = function ( $body, $live ) {
			if ( 2 === $body['seq'] && empty( $live->dropped ) ) {
				$live->dropped = true;
				$live->session_call( $live->last_push, 'items', $body );
				return Keepmerge_Test_Push_Live::error( 'network_error', 0 );
			}
			return null;
		};
		$this->live->last_push = $push['push'];

		$push = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 1, $push['seq'] );
		$push = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 'paused', $push['state'] );
		$this->assertSame( 'network_error', $push['error']['code'] );
		$this->assertSame( 10, $push['sent'], 'the batch is not acknowledged' );
		$this->assertSame( array( 'n' => '20', 'd' => '20' ), $this->live_rows(), 'live stored it' );

		// Resume: the same batch again, answered from live's records.
		$push = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 'sending_items', $push['state'] );
		$this->assertSame( 2, $push['seq'] );
		$last = end( $push['log'] );
		$this->assertTrue( $last['replay'] );
		$this->assertSame( array( 'n' => '20', 'd' => '20' ), $this->live_rows() );

		$push = $this->steps( $push );
		$this->assertSame( 'staged', $push['state'] );
		$this->assertSame( array( 'n' => '25', 'd' => '25' ), $this->live_rows() );
		$this->assertSame( 25, $push['counts']['staged'], 'replayed items are not counted twice' );
	}

	/**
	 * Found in a two-site end-to-end run: after a pause, WP-CLI tells the user to run
	 * `wp keepmerge push --plan=<id>`, and its help says a paused push continues, but
	 * Push_Runner::run() stepped only pushes in a sending state, so the command returned the same
	 * pause again without sending anything. It now resumes a paused push once per call; a push that
	 * pauses again stops the call (no loop while live is still unreachable).
	 *
	 * @group recovery
	 */
	public function test_wp_cli_run_resumes_a_paused_push_once_per_call() {
		$this->changed_pages( 25 );
		$push             = $this->start( $this->plan() );
		$this->live->down = true;
		$this->live->hook = function ( $body, $live ) {
			return ! empty( $live->down ) && 2 === $body['seq'] ? Keepmerge_Test_Push_Live::error( 'network_error', 0 ) : null;
		};

		$push = Push_Runner::run( $push['id'], $this->live );
		$this->assertSame( 'paused', $push['state'], 'the command stops at the pause' );
		$this->assertSame( 'network_error', $push['error']['code'] );
		$this->assertSame( 10, $push['sent'] );

		// Still unreachable: the call resumes once, pauses again and returns.
		$items = $this->live->items;
		$push  = Push_Runner::run( $push['id'], $this->live );
		$this->assertSame( 'paused', $push['state'] );
		$this->assertSame( $items + 1, $this->live->items, 'one attempt per call, no loop' );
		$this->assertContains( 'resume', $this->events( $push ) );

		// Reachable again: the same command resumes and finishes.
		$this->live->down = false;
		$push             = Push_Runner::run( $push['id'], $this->live );
		$this->assertSame( 'done', $push['state'], wp_json_encode( $push['error'] ) );
		$this->assertSame( 25, $push['sent'] );
		$this->assertSame( array( 'n' => '25', 'd' => '25' ), $this->live_rows() );
	}

	/**
	 * Found in a two-site end-to-end run: live staged a slow batch until its full time budget
	 * (~16 s with WordPress's start-up) while the source waited only for what was left of its own
	 * step (~16 s; both budgets are equal by design), so the source gave up on a batch live had
	 * staged, its retries had no time left, and the push paused with network_error. A batch live
	 * stages for the source now stops at half its time budget.
	 *
	 * @group recovery
	 */
	public function test_a_remote_batch_answers_within_half_the_time_budget() {
		$this->changed_pages( 10 );
		$push    = $this->start( $this->plan() );
		$elapsed = null;
		$uuid    = $push['push'];
		$this->live->hook = function ( $body, $live ) use ( &$elapsed, $uuid ) {
			if ( 1 !== $body['seq'] || null !== $elapsed ) {
				return null;
			}
			// A 12 s time budget; each stored item takes 1 s.
			$budget = static function () {
				return 12;
			};
			$slow   = static function ( $query ) {
				if ( false !== strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, 'push_items' ) ) {
					usleep( 1000000 );
				}
				return $query;
			};
			add_filter( 'softglaze_sp_time_budget', $budget );
			add_filter( 'query', $slow );
			$started = microtime( true );
			$result  = $live->session_call( $uuid, 'items', $body );
			$elapsed = microtime( true ) - $started;
			remove_filter( 'query', $slow );
			remove_filter( 'softglaze_sp_time_budget', $budget );
			$data = (array) ( is_wp_error( $result ) ? $result->get_error_data() : array() );
			return is_wp_error( $result ) ? Keepmerge_Test_Push_Live::error( $result->get_error_code(), (int) $data['status'] ) : $live->wrap( $result );
		};

		$push = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertNotNull( $elapsed );
		$this->assertLessThan( 8, $elapsed, 'live answered within half of its 12 s budget, plus the item in hand' );

		$push = $this->steps( $push, 40 );
		$this->assertSame( 'staged', $push['state'] );
		$this->assertSame( array( 'n' => '10', 'd' => '10' ), $this->live_rows(), 'every item staged once' );
	}

	/**
	 * Found in a two-site end-to-end run (F-01, after the half-budget fix on
	 * live): the source started another apply call late in its step, with a few seconds left to
	 * wait, gave up on it after 3 s while live was still applying, and paused. A step now starts a
	 * further apply call only when it can wait for live's whole remote step; the first call of a
	 * step always goes out.
	 *
	 * @group recovery
	 */
	public function test_a_step_does_not_start_an_apply_call_it_could_not_wait_for() {
		$this->changed_pages( 14 );
		$push = $this->steps( $this->start( $this->plan() ) );
		$this->assertSame( 'staged', $push['state'] );

		// A 16 s budget on both sides: the source's step lasts 13 s, live's remote step 8 s; each
		// snapshot live stores takes 0.8 s, so live is still at work after the first call.
		$budget = static function () {
			return 16;
		};
		$slow   = static function ( $query ) {
			if ( false !== strpos( $query, 'pre_image' ) && 0 === strpos( ltrim( $query ), 'UPDATE' ) ) {
				usleep( 800000 );
			}
			return $query;
		};
		add_filter( 'softglaze_sp_time_budget', $budget );
		add_filter( 'query', $slow );
		$applies = $this->live->applies;
		$push    = Push_Runner::step( $push['id'], array(), $this->live );
		$calls   = $this->live->applies - $applies;
		remove_filter( 'query', $slow );
		remove_filter( 'softglaze_sp_time_budget', $budget );

		$this->assertSame( 1, $calls, 'no second apply call with less time left than live may need' );
		$this->assertSame( 'applying', $push['state'] );
		$push = $this->steps_through( $push, 40 );
		$this->assertSame( 'done', $push['state'] );
	}

	/**
	 * Found in a two-site end-to-end run (I-04): after live's PHP restarted, a
	 * step that died there still marked the push busy, and WP-CLI asked again at once on every
	 * "push_busy" answer: 275 requests in half a minute, until the pairing's rate limit paused the
	 * push. The source now waits a few seconds before it asks again.
	 *
	 * @group recovery
	 */
	public function test_a_busy_answer_makes_the_source_wait_before_it_asks_again() {
		$this->changed_pages( 3 );
		$push = $this->steps( $this->start( $this->plan() ) );
		$this->assertSame( 'staged', $push['state'] );

		$busy                   = 1;
		$this->live->apply_hook = function () use ( &$busy ) {
			if ( $busy > 0 ) {
				--$busy;
				return Keepmerge_Test_Push_Live::error( 'push_busy', 409 );
			}
			return null;
		};

		$push = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 'applying', $push['state'] );
		$this->assertGreaterThan( 0, $push['wait'], 'the source waits before it asks live again' );

		$applies = $this->live->applies;
		$push    = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( $applies, $this->live->applies, 'no request while it waits' );

		// WP-CLI sleeps through the wait, then finishes.
		$push = Push_Runner::run( $push['id'], $this->live );
		$this->assertSame( 'done', $push['state'] );
		$this->assertSame( 0, $busy );
	}

	public function test_a_batch_range_recorded_before_a_crash_is_sent_as_it_was() {
		$this->changed_pages( 12 );
		$push = $this->start( $this->plan() );

		// The step recorded its range and died before sending (no request reached live).
		$row                             = Push_Runner::find( $push['id'] );
		$cursor                          = $row['cursor_json'];
		$cursor['push']['pending']       = array(
			'seq'  => 1,
			'from' => 0,
			'to'   => 7,
		);
		Plan_Store::update( $push['id'], array( 'cursor_json' => $cursor ) );

		$push = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 7, $push['sent'] );
		$push = $this->steps( $push );
		$this->assertSame( 'staged', $push['state'] );
		$this->assertSame( array( 'n' => '12', 'd' => '12' ), $this->live_rows() );
	}

	public function test_413_halves_the_batch_and_a_single_item_over_the_limit_goes_as_a_stub() {
		$uuids = $this->changed_pages( 12 );
		$push  = $this->start( $this->plan() );
		$big   = $uuids[3];

		$this->live->hook = function ( $body ) use ( $big ) {
			if ( count( $body['items'] ) > 3 ) {
				return Keepmerge_Test_Push_Live::error( 'payload_too_large', 413 );
			}
			foreach ( $body['items'] as $item ) {
				if ( $item['uuid'] === $big && empty( $item['skip'] ) ) {
					return Keepmerge_Test_Push_Live::error( 'unexpected_status', 413 );
				}
			}
			return null;
		};

		$push = $this->steps( $push, 40 );
		$this->assertSame( 'staged', $push['state'] );
		$this->assertContains( 'halved', $this->events( $push ) );
		$this->assertContains( 'too_large', $this->events( $push ) );
		$this->assertSame( 1, $push['counts']['refused'] );
		$this->assertSame( 11, $push['counts']['staged'] );
		$this->assertSame( 'too_large', $push['refused'][0]['reason'] );
		$this->assertLessThanOrEqual( 3, $push['batch_size'] );
	}

	public function test_429_hands_the_wait_to_the_browser() {
		$this->changed_pages( 3 );
		$push             = $this->start( $this->plan() );
		$this->live->hook = function () {
			return Keepmerge_Test_Push_Live::error( 'rate_limited', 429, array( 'retry_after' => 300 ) );
		};
		$push             = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 'sending_items', $push['state'] );
		$this->assertGreaterThan( 290, $push['wait'] );
		$this->assertContains( 'wait', $this->events( $push ) );

		// Before the wait is over a step sends nothing.
		$calls = $this->live->items;
		$this->assertSame( 'sending_items', Push_Runner::step( $push['id'], array(), $this->live )['state'] );
		$this->assertSame( $calls, $this->live->items );
	}

	public function test_an_item_changed_after_the_plan_is_not_sent() {
		$uuids = $this->changed_pages( 3 );
		$push  = $this->start( $this->plan() );
		wp_update_post(
			array(
				'ID'           => $this->pages[ $uuids[1] ],
				'post_content' => 'Changed after the plan',
			)
		);
		$push = $this->steps( $push );
		$this->assertSame( 'staged', $push['state'] );
		$this->assertSame( 2, $push['counts']['staged'] );
		$this->assertSame( 'changed_since_plan', $push['refused'][0]['reason'] );
		$this->assertSame( $uuids[1], $push['refused'][0]['uuid'] );
	}

	public function test_one_step_at_a_time() {
		$this->changed_pages( 3 );
		$push = $this->start( $this->plan() );

		$moved = Push_Runner::step( $push['id'], array( 'steps' => 7 ), $this->live );
		$this->assertSame( 'push_moved', $moved->get_error_code() );

		$row                          = Push_Runner::find( $push['id'] );
		$cursor                       = $row['cursor_json'];
		$cursor['push']['busy_until'] = time() + 60;
		Plan_Store::update( $push['id'], array( 'cursor_json' => $cursor ) );
		$busy = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 'push_busy', $busy->get_error_code() );
		$this->assertSame( 0, $this->live->items, 'nothing was sent' );
	}

	public function test_start_checks_the_typed_domain_the_plan_and_the_lock() {
		$this->changed_pages( 2 );
		$plan = $this->plan();

		$error = $this->start( $plan, 'live.example.co' );
		$this->assertSame( 'confirm_mismatch', $error->get_error_code() );
		$this->assertSame( 400, $error->get_error_data()['status'] );
		$this->assertSame( array(), preg_grep( '#/remote/push#', $this->live->calls ), 'nothing sent' );

		// Upper case and spaces around the domain are fine.
		$push = $this->start( $plan, '  LIVE.example.com ' );
		$this->assertSame( 'sending_items', $push['state'] );

		$plan2 = $this->plan();
		$this->assertSame( 'push_in_progress', $this->start( $plan2 )->get_error_code() );
	}

	public function test_a_lock_held_on_live_leaves_the_plan_ready() {
		$this->changed_pages( 2 );
		$plan = $this->plan();
		update_option(
			Push_Lock::OPTION,
			array(
				'push_uuid' => 'f' . substr( wp_generate_uuid4(), 1 ),
				'heartbeat' => time(),
			),
			false
		);
		// A lock naming no session is cleared; one naming a running session is not.
		$other = Push_Session::open(
			array( 'id' => 9999 ),
			array(
				'push'      => wp_generate_uuid4(),
				'plan_hash' => self::X,
				'counts'    => array( 'create' => 1, 'update' => 0, 'recreate' => 0, 'trash' => 0, 'adopt' => 0 ),
				'items'     => 1,
			)
		);
		$this->assertSame( 'open', $other['state'] );

		$error = $this->start( $plan );
		$this->assertSame( 'push_locked', $error->get_error_code() );
		$this->assertSame( 'planned', Plan_Store::find( $plan['id'] )['state'] );
	}

	public function test_abort_purges_live_and_ends_the_push() {
		$this->changed_pages( 15 );
		$push = $this->start( $this->plan() );
		$push = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( array( 'n' => '10', 'd' => '10' ), $this->live_rows() );

		$aborted = Push_Runner::abort( $push['id'], 1, $this->live );
		$this->assertSame( 'aborted', $aborted['state'] );
		$this->assertSame( array( 'n' => '0', 'd' => '0' ), $this->live_rows() );
		$this->assertNull( Push_Lock::get() );
		$this->assertSame( 'aborted', Push_Session::find( $this->live->live_uuid( $push['push'] ) )['state'] );
	}

	public function test_signed_refusals_pause_or_fail_with_their_fix() {
		$this->changed_pages( 3 );
		$push             = $this->start( $this->plan() );
		$this->live->hook = function () {
			return Keepmerge_Test_Push_Live::error( 'window_closed', 404 );
		};
		$push             = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 'paused', $push['state'] );
		$this->assertSame( 'window_closed', $push['error']['code'] );

		$this->live->hook = function () {
			return Keepmerge_Test_Push_Live::error( 'push_not_found', 404 );
		};
		$push             = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 'failed', $push['state'] );
		$this->assertSame( 'push_not_found', $push['error']['code'] );
		$this->assertSame( 'failed', Push_Runner::step( $push['id'], array(), $this->live )['state'], 'a failed push takes no more steps' );
	}

	/**
	 * I-01: live stops a batch part-way at its time budget (503 server_busy, the items
	 * staged so far kept); the source halves its batch and sends again; live stores nothing twice.
	 *
	 * @group recovery
	 */
	public function test_i01_a_batch_live_stops_at_its_time_budget_is_sent_again_smaller_without_duplicates() {
		$this->changed_pages( 25 );
		$push                  = $this->start( $this->plan() );
		$this->live->last_push = $push['push'];
		$this->live->hook      = function ( $body, $live ) {
			if ( 2 !== $body['seq'] || ! empty( $live->dropped ) ) {
				return null;
			}
			$live->dropped = true;
			// Live's own time budget of 3 s leaves 1 s for the batch; each stored item takes 0.6 s.
			$budget = static function () {
				return 3;
			};
			$slow   = static function ( $query ) {
				if ( false !== strpos( $query, 'INSERT IGNORE INTO' ) && false !== strpos( $query, 'push_items' ) ) {
					usleep( 600000 );
				}
				return $query;
			};
			add_filter( 'softglaze_sp_time_budget', $budget );
			add_filter( 'query', $slow );
			$result = $live->session_call( $live->last_push, 'items', $body );
			remove_filter( 'query', $slow );
			remove_filter( 'softglaze_sp_time_budget', $budget );
			$data = (array) ( is_wp_error( $result ) ? $result->get_error_data() : array() );
			return is_wp_error( $result ) ? Keepmerge_Test_Push_Live::error( $result->get_error_code(), (int) $data['status'] ) : $live->wrap( $result );
		};

		$push = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 1, $push['seq'] );
		$stored = (int) $this->live_rows()['n'];
		$push   = Push_Runner::step( $push['id'], array(), $this->live );
		$staged = (int) $this->live_rows()['n'];
		$this->assertGreaterThan( 10, $staged, 'live kept the items it staged before it stopped' );
		$this->assertLessThan( 20, $staged, 'live stopped part-way through the batch' );
		$this->assertSame( 10, $stored );
		$this->assertContains( 'halved', $this->events( $push ), 'the source halves its batch' );
		$this->assertLessThan( 10, $push['batch_size'] );

		$push = $this->steps( $push, 40 );
		$this->assertSame( 'staged', $push['state'] );
		$this->assertSame( array( 'n' => '25', 'd' => '25' ), $this->live_rows(), 'no duplicate rows' );
		$this->assertSame( 25, $push['counts']['staged'] );
	}

	/**
	 * I-06: the network to live goes away mid-push: the client's retries with backoff are spent
	 * (RemoteClientTest::test_server_errors_are_retried_with_a_new_nonce_and_then_reported), the push
	 * pauses with Resume, and Resume finishes it without duplicates once live answers again.
	 *
	 * @group recovery
	 */
	public function test_i06_a_network_outage_pauses_the_push_and_resume_finishes_it() {
		$this->changed_pages( 25 );
		$push                  = $this->start( $this->plan() );
		$this->live->last_push = $push['push'];
		$push                  = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 1, $push['seq'] );

		$this->live->hook = function () {
			return Keepmerge_Test_Push_Live::error( 'network_error', 0, array( 'retryable' => true ) );
		};
		$push             = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertSame( 'paused', $push['state'] );
		$this->assertSame( 'network_error', $push['error']['code'] );
		$this->assertSame( array( 'n' => '10', 'd' => '10' ), $this->live_rows() );

		$this->live->hook = null;
		$push             = Push_Runner::step( $push['id'], array(), $this->live );
		$this->assertContains( 'resume', $this->events( $push ) );
		$push = $this->steps( $push, 40 );
		$this->assertSame( 'staged', $push['state'] );
		$this->assertSame( array( 'n' => '25', 'd' => '25' ), $this->live_rows() );
	}
}
