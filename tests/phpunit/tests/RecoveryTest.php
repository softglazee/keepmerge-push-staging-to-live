<?php
/**
 * Failure handling and resumable pushes on live: I-04,
 * I-05, I-07 to I-10, stale lock release, the time budget and the memory guard, the audit collapse
 * under load, and the fix text of every signed error code. A real process kill cannot happen in one
 * PHP process: a crash is fabricated as exactly what it leaves (the cursor as the last finished step
 * stored it, the rows as the dead step left them, the lock's heartbeat in the past).
 *
 * @package SoftGlaze\SafePush\Tests
 */

require_once __DIR__ . '/class-keepmerge-recovery-harness.php';

use SoftGlaze\SafePush\Apply\Recovery;
use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Cron;
use SoftGlaze\SafePush\Limits;
use SoftGlaze\SafePush\Pairing_Service;
use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Media_Receiver;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Session;
use SoftGlaze\SafePush\Transfer\Remote_Client;

/**
 * @group recovery
 */
class RecoveryTest extends Keepmerge_Recovery_Harness {

	private $memory = '';

	private $ballast = '';

	public function set_up() {
		parent::set_up();
		$this->memory = (string) ini_get( 'memory_limit' );
	}

	public function tear_down() {
		$this->ballast = '';
		ini_set( 'memory_limit', $this->memory ); // phpcs:ignore WordPress.PHP.IniSet -- Test: restores the limit a test lowered.
		remove_all_filters( 'admin_memory_limit' );
		parent::tear_down();
	}

	/**
	 * Stages N changed pages; returns array( push, page IDs, envelopes ).
	 */
	private function pages_push( $count, $prefix ) {
		$ids  = array();
		$envs = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$ids[ $i ]  = $this->page( $prefix . ' ' . $i );
			$envs[ $i ] = $this->changed_page( $ids[ $i ] );
		}
		return array( $this->stage( $envs ), $ids, $envs );
	}

	private function one_item_per_step() {
		add_filter(
			'softglaze_sp_apply_step_items',
			static function () {
				return 1;
			}
		);
	}

	private function call_apply( $push ) {
		$response = $this->call( 'POST', '/keepmerge/v1/remote/push/' . $push . '/apply', '{"after":0}' );
		return $response;
	}

	/**
	 * Runs remote apply calls until the write pass has written $written items.
	 */
	private function apply_until_written( $push, $written ) {
		for ( $i = 0; $i < 100; $i++ ) {
			$applied = 0;
			foreach ( $this->rows( $push ) as $row ) {
				$applied += 'applied' === $row['status'] ? 1 : 0;
			}
			if ( $applied >= $written ) {
				return;
			}
			$this->assertSame( 200, $this->call_apply( $push )->get_status() );
		}
		$this->fail( 'the write pass did not reach ' . $written . ' items' );
	}

	// --- I-04 ---------------------------------------------------------------------------------------

	public function test_i04_a_step_that_dies_during_apply_is_interrupted_and_resume_applies_nothing_twice() {
		list( $push, $ids, $envs ) = $this->pages_push( 4, 'I04 page' );
		$this->one_item_per_step();
		$this->apply_until_written( $push, 1 );
		$saved = $this->raw_cursor( $push );

		// The next step writes page 1 completely, and dies before recording it (row left
		// "applying"); page 2 was marked "applying" just before the process died (nothing written).
		// The row keeps as intended hash what live has after a complete write: in the real
		// protocol that is the staging hash (both sites hash the same content alike); this harness
		// sends a stand-in staging hash, so live's own post-write hash plays that part.
		$this->assertSame( 200, $this->call_apply( $push )->get_status() );
		$this->set_row(
			$push,
			$envs[1]['uuid'],
			array(
				'status'      => 'applying',
				'action'      => 'update',
				'applied_gmt' => null,
			)
		);
		$this->set_row( $push, $envs[2]['uuid'], array( 'status' => 'applying' ) );
		$this->crash( $push, $saved, 'applying' );

		// Live sees no heartbeat: interrupted (on any read, here the admin's).
		$detail = $this->admin( 'GET', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) )->get_data();
		$this->assertSame( 'interrupted', $detail['state'] );
		$this->assertTrue( $detail['recovery']['resume'] );
		$this->assertSame( 1, $this->audit_count( 'push_interrupted' ) );

		$writes   = array();
		$callback = $this->count_writes( $writes );
		$answer   = $this->recover( $push, 'resume' );
		$this->assertSame( 200, $answer->get_status(), wp_json_encode( $answer->get_data() ) );
		$this->continue_all( $push );
		remove_action( 'post_updated', $callback );

		$this->assertSame( 'applied', Push_Session::find( $push )['state'] );
		$this->assertArrayNotHasKey( $ids[1], $writes, 'page 1 was complete: recorded applied, not written again' );
		$this->assertSame( 1, $writes[ $ids[2] ], 'page 2 still had its snapshot: written once' );
		$this->assertArrayNotHasKey( $ids[0], $writes, 'page 0 was applied before the crash' );
		$rows = $this->rows( $push );
		$this->assertContains( 'recovered', $rows[ $envs[1]['uuid'] ]['transfer']['warnings'] );
		foreach ( $ids as $i => $id ) {
			$this->assertSame( 'I04 page ' . $i . ' (staging)', get_post( $id )->post_title );
		}
		$this->assertSame( 0, $this->applied_without_snapshot() );
		$this->assertNull( Push_Lock::get(), 'lock released at the end' );
		$this->assertSame( 1, $this->audit_count( 'push_resumed' ) );
	}

	/**
	 * Found in a two-site end-to-end run (I-04): live's PHP restarted in a step
	 * that had begun in the snapshot or allocate pass and had gone on into the write pass. The cursor
	 * that step claimed still named the earlier pass, so the next step built the write order again,
	 * from the rows still to process; the row the dead step had marked "applying" was not among them.
	 * The source's next call (the lock still fresh, so no "interrupted" and no resume on live) finished
	 * the push as applied with that row never written. A rebuilt write order now includes such rows,
	 * and the write pass recovers them as it does when it meets one in its own order.
	 */
	public function test_i04_a_row_left_applying_is_written_when_the_next_step_rebuilds_the_write_order() {
		list( $push, $ids, $envs ) = $this->pages_push( 3, 'I04c page' );
		$this->one_item_per_step();
		// begin + the three snapshots: the stored cursor is still in the snapshot pass.
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame( 200, $this->call_apply( $push )->get_status() );
		}
		$this->assertSame( 3, count( array_filter( $this->rows( $push ), static function ( $row ) { return null !== $row['snapshot_gmt']; } ) ), 'every row has its snapshot' );
		$saved = $this->raw_cursor( $push );
		$state = Push_Session::find( $push )['state'];

		// The dead step: it went on into the write pass, wrote page 0, marked page 1 "applying" and
		// died before writing it. What it leaves: page 0 applied, page 1 applying, the cursor it
		// claimed at its start (before the write pass), the lock's heartbeat still recent.
		$this->assertSame( 200, $this->call_apply( $push )->get_status() );
		$this->assertSame( 'applied', $this->rows( $push )[ $envs[0]['uuid'] ]['status'] );
		$this->set_row( $push, $envs[1]['uuid'], array( 'status' => 'applying' ) );
		global $wpdb;
		$cursor                       = json_decode( $saved, true );
		$cursor['apply']['busy_until'] = time() - 1;
		$wpdb->update(
			Tables::table_name( 'pushes' ),
			array(
				'cursor_json' => wp_json_encode( $cursor ),
				'state'       => $state,
			),
			array( 'id' => $this->session_id( $push ) )
		);

		$writes   = array();
		$callback = $this->count_writes( $writes );
		$answers  = $this->apply( $push );
		remove_action( 'post_updated', $callback );

		$this->assertSame( 'applied', end( $answers )['state'] );
		$statuses = array_column( $this->rows( $push ), 'status' );
		$this->assertNotContains( 'applying', $statuses, 'no row is left applying in an applied push' );
		$this->assertSame( 'I04c page 1 (staging)', get_post( $ids[1] )->post_title, 'the interrupted row was written' );
		$this->assertSame( 1, $writes[ $ids[1] ] ?? 0, 'written once' );
		$this->assertArrayNotHasKey( $ids[0], $writes, 'page 0 was applied before the crash' );
		$this->assertSame( 'I04c page 2 (staging)', get_post( $ids[2] )->post_title );
		$this->assertSame( 0, $this->applied_without_snapshot() );
	}

	public function test_i04_an_item_left_half_written_stops_the_resume_as_failed_partial_for_a_decision() {
		list( $push, $ids, $envs ) = $this->pages_push( 2, 'I04b page' );
		$this->one_item_per_step();
		$this->apply_until_written( $push, 1 );
		$saved = $this->raw_cursor( $push );
		// The dead step had written half of page 1: neither its snapshot nor the staging version.
		$this->set_row( $push, $envs[1]['uuid'], array( 'status' => 'applying' ) );
		wp_update_post(
			array(
				'ID'         => $ids[1],
				'post_title' => 'Half written',
			)
		);
		$this->crash( $push, $saved, 'applying' );

		$answer = $this->recover( $push, 'resume' );
		$this->assertSame( 200, $answer->get_status() );
		$detail = $answer->get_data()['detail'];
		$this->assertSame( 'failed_partial', $detail['state'] );
		$this->assertSame( $envs[1]['uuid'], $detail['failed_item']['uuid'] );
		$this->assertSame( 'interrupted_item', $detail['failed_item']['reason'] );
		$this->assertSame( 'Half written', get_post( $ids[1] )->post_title, 'nothing decided for the admin' );

		// Retry: the item goes back to its snapshot, then it is written.
		$this->assertSame( 200, $this->recover( $push, 'resume' )->get_status() );
		$this->continue_all( $push );
		$this->assertSame( 'applied', Push_Session::find( $push )['state'] );
		$this->assertSame( 'I04b page 1 (staging)', get_post( $ids[1] )->post_title );
	}

	// --- I-05 ---------------------------------------------------------------------------------------

	public function test_i05_a_step_that_dies_during_the_snapshot_pass_resumes_and_nothing_is_written_before_every_snapshot() {
		list( $push, $ids, $envs ) = $this->pages_push( 4, 'I05 page' );
		$this->one_item_per_step();
		// begin + 2 snapshots.
		$this->call_apply( $push );
		$this->call_apply( $push );
		$this->assertSame( 'snapshotting', Push_Session::find( $push )['state'] );
		$saved = $this->raw_cursor( $push );
		$this->call_apply( $push );
		$this->crash( $push, $saved, 'snapshotting' );

		foreach ( $ids as $i => $id ) {
			$this->assertSame( 'I05 page ' . $i, get_post( $id )->post_title, 'nothing written during the snapshot pass' );
		}
		$this->assertSame( 'interrupted', Push_Session::find_by_id( $this->session_id( $push ) )['state'] );

		// The source drove it: its next apply call resumes it.
		$answers = $this->apply( $push );
		$this->assertSame( 'applied', end( $answers )['state'] );
		global $wpdb;
		$first_write = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(applied_gmt) FROM %i WHERE push_id = %d AND status = 'applied'", Tables::table_name( 'push_items' ), $this->session_id( $push ) ) );
		$last_snap   = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(snapshot_gmt) FROM %i WHERE push_id = %d', Tables::table_name( 'push_items' ), $this->session_id( $push ) ) );
		$this->assertLessThanOrEqual( $first_write, $last_snap, 'every snapshot before the first write' );
		$this->assertSame( 0, $this->applied_without_snapshot() );
		foreach ( $ids as $i => $id ) {
			$this->assertSame( 'I05 page ' . $i . ' (staging)', get_post( $id )->post_title );
		}
	}

	// --- I-07 ---------------------------------------------------------------------------------------

	public function test_i07_closing_the_window_by_hand_interrupts_the_push_and_the_admin_resumes_on_live() {
		list( $push, $ids ) = $this->pages_push( 3, 'I07 page' );
		$this->one_item_per_step();
		$this->apply_until_written( $push, 1 );

		Receive_Window::close( 'manual' );
		$session = Push_Session::find( $push );
		$this->assertSame( 'interrupted', $session['state'] );
		$this->assertSame( 'window_closed', $session['error_code'] );
		$this->assertSame( 'window_closed', Remote_Client::translate_code( 'window_closed', 'pairing' ) );

		// The source cannot reach live any more (routes are gone); live's admin resumes it.
		$answer = $this->recover( $push, 'resume' );
		$this->assertSame( 200, $answer->get_status(), wp_json_encode( $answer->get_data() ) );
		$this->continue_all( $push );
		$this->assertSame( 'applied', Push_Session::find( $push )['state'] );
		foreach ( $ids as $i => $id ) {
			$this->assertSame( 'I07 page ' . $i . ' (staging)', get_post( $id )->post_title );
		}
	}

	// --- I-08 ---------------------------------------------------------------------------------------

	public function test_i08_a_revoked_pairing_stops_the_push_and_the_admin_rolls_back_what_was_applied() {
		list( $push, $ids ) = $this->pages_push( 3, 'I08 page' );
		$this->one_item_per_step();
		$this->apply_until_written( $push, 2 );

		Pairing_Service::revoke( $this->pairing_uuid );
		$this->assertSame( 'interrupted', Push_Session::find( $push )['state'] );
		$this->assertSame( 'pairing_revoked', Push_Session::find( $push )['error_code'] );

		$data = $this->preview( $push )->get_data();
		$this->assertTrue( $data['can'] );
		$this->assertSame( 2, $data['restore'] );
		$this->rollback( $push );
		foreach ( $ids as $i => $id ) {
			$this->assertSame( 'I08 page ' . $i, get_post( $id )->post_title, 'back to before the push' );
		}
		$this->assertNull( Push_Lock::get() );
	}

	// --- I-09 ---------------------------------------------------------------------------------------

	/**
	 * A test hook that throws when an item with this title is saved (the "fatal" of I-09, as an
	 * exception the apply catches per item).
	 */
	private function throw_on( $title ) {
		$callback = static function ( $data ) use ( $title ) {
			if ( isset( $data['post_title'] ) && $title === $data['post_title'] ) {
				throw new RuntimeException( 'injected failure' );
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $callback );
		return $callback;
	}

	public function test_i09_an_item_that_throws_stops_the_push_failed_partial_and_retry_works() {
		list( $push, $ids, $envs ) = $this->pages_push( 3, 'I09r page' );
		$hook    = $this->throw_on( 'I09r page 1 (staging)' );
		$answers = $this->apply( $push );
		$this->assertSame( 'failed_partial', end( $answers )['state'] );
		$rows = $this->rows( $push );
		$this->assertSame( 'failed', $rows[ $envs[1]['uuid'] ]['status'] );
		$this->assertSame( 'item_exception', $rows[ $envs[1]['uuid'] ]['error'] );
		$this->assertNotNull( Push_Lock::get(), 'the lock stays for the decision' );
		$this->assertSame( 'I09r page 0 (staging)', get_post( $ids[0] )->post_title, 'applied before it stays' );
		// The source's further calls write nothing.
		$this->assertSame( 'failed_partial', $this->call_apply( $push )->get_data()['state'] );

		remove_filter( 'wp_insert_post_data', $hook );
		$answer = $this->recover( $push, 'resume' );
		$this->assertSame( 200, $answer->get_status(), wp_json_encode( $answer->get_data() ) );
		$this->continue_all( $push );
		$this->assertSame( 'applied', Push_Session::find( $push )['state'] );
		foreach ( $ids as $i => $id ) {
			$this->assertSame( 'I09r page ' . $i . ' (staging)', get_post( $id )->post_title );
		}
	}

	public function test_i09_skip_this_item_puts_it_back_and_the_push_goes_on() {
		list( $push, $ids, $envs ) = $this->pages_push( 3, 'I09s page' );
		$hook = $this->throw_on( 'I09s page 1 (staging)' );
		$this->apply( $push );
		remove_filter( 'wp_insert_post_data', $hook );

		$wrong = $this->recover( $push, 'skip-item', array( 'uuid' => $envs[2]['uuid'] ) );
		$this->assertSame( 400, $wrong->get_status(), 'only the failed item can be skipped' );
		$answer = $this->recover( $push, 'skip-item', array( 'uuid' => $envs[1]['uuid'] ) );
		$this->assertSame( 200, $answer->get_status(), wp_json_encode( $answer->get_data() ) );
		$this->continue_all( $push );

		$this->assertSame( 'applied', Push_Session::find( $push )['state'] );
		$this->assertSame( 'I09s page 1', get_post( $ids[1] )->post_title, 'skipped: live keeps its version' );
		$this->assertSame( 'I09s page 2 (staging)', get_post( $ids[2] )->post_title, 'the push went on' );
		$rows = $this->rows( $push );
		$this->assertSame( 'skipped', $rows[ $envs[1]['uuid'] ]['status'] );
		$this->assertSame( 'admin_skipped', $rows[ $envs[1]['uuid'] ]['error'] );
	}

	public function test_i09_roll_back_after_a_failed_item_restores_what_was_applied() {
		list( $push, $ids ) = $this->pages_push( 3, 'I09b page' );
		$hook = $this->throw_on( 'I09b page 1 (staging)' );
		$this->apply( $push );
		remove_filter( 'wp_insert_post_data', $hook );

		$this->rollback( $push );
		foreach ( $ids as $i => $id ) {
			$this->assertSame( 'I09b page ' . $i, get_post( $id )->post_title );
		}
		$this->assertSame( 1, $this->assert_restored_to_pre_hash( $push ), 'page 0 restored; page 1 unchanged; page 2 never written' );
		$this->assertNull( Push_Lock::get() );
	}

	// --- I-10 ---------------------------------------------------------------------------------------

	public function test_i10_a_staged_session_older_than_24_hours_is_purged_with_its_temp_folder_and_lock() {
		global $wpdb;
		list( $push ) = $this->pages_push( 2, 'I10 page' );
		$session      = Push_Session::find( $push );
		// A temporary folder the session uses.
		$name = str_repeat( 'b', 32 );
		Push_Session::cursor_set( (int) $session['id'], array( 'tmp' => $name ) );
		wp_mkdir_p( $this->plugin_dir() . '/tmp/' . $name );
		$this->assertNotNull( Push_Lock::get() );

		$wpdb->update( Tables::table_name( 'pushes' ), array( 'started_gmt' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS - 60 ) ), array( 'id' => (int) $session['id'] ) );
		$out = Cron::daily_prune();
		$this->assertSame( 1, $out['expired'] );
		$this->assertSame( 'expired', Push_Session::find( $push )['state'] );
		$this->assertSame( array(), $this->rows( $push ), 'staged rows purged' );
		$this->assertDirectoryDoesNotExist( $this->plugin_dir() . '/tmp/' . $name );
		$this->assertNull( Push_Lock::get(), 'lock released' );
	}

	// --- stale lock (S-66 continued) ------------------------------------------------------------------

	public function test_a_stale_lock_marks_the_apply_interrupted_and_is_releasable_only_when_stale() {
		list( $push ) = $this->pages_push( 2, 'Lock page' );
		$this->one_item_per_step();
		$this->call_apply( $push );

		$fresh = $this->recover( $push, '../lock/release' );
		$fresh = $this->admin( 'POST', '/keepmerge/v1/admin/pushes/lock/release', array( 'confirm' => self::HOST ) );
		$this->assertSame( 409, $fresh->get_status() );
		$this->assertSame( 'lock_in_use', $fresh->get_data()['code'] );

		$this->age_lock();
		$released = $this->admin( 'POST', '/keepmerge/v1/admin/pushes/lock/release', array( 'confirm' => self::HOST ) );
		$this->assertSame( 200, $released->get_status(), wp_json_encode( $released->get_data() ) );
		$this->assertSame( 'interrupted', $released->get_data()['state'] );
		$this->assertNull( Push_Lock::get() );
		$this->assertSame( 1, $this->audit_count( 'lock_released' ) );

		// A new push may open now; the interrupted one can still be rolled back once it is free.
		$this->assertSame( 409, $this->admin( 'POST', '/keepmerge/v1/admin/pushes/lock/release', array( 'confirm' => self::HOST ) )->get_status(), 'no lock: nothing to release' );
	}

	// --- time budget and memory guard ------------------------------------------------------------------

	/**
	 * Found in a two-site end-to-end run: a remote apply step ran until its full time budget
	 * while the source waited only for what was left of its own step, so the source gave up and
	 * paused with network_error. A step live runs for the source now stops at half its budget; an
	 * administrator's own continue keeps the full budget.
	 */
	public function test_a_remote_apply_step_answers_within_half_the_time_budget() {
		list( $push, $ids ) = $this->pages_push( 10, 'Budget page' );
		$budget = static function () {
			return 12;
		};
		// Every page write takes 1 s (snapshots are not slowed).
		$slow = static function ( $data ) {
			usleep( 1000000 );
			return $data;
		};
		add_filter( 'softglaze_sp_time_budget', $budget );
		add_filter( 'wp_insert_post_data', $slow );

		$started  = microtime( true );
		$response = $this->call_apply( $push );
		$elapsed  = microtime( true ) - $started;
		$this->assertSame( 200, $response->get_status() );
		$this->assertLessThan( 8, $elapsed, 'live answered within half of its 12 s budget, plus the item in hand' );
		$this->assertSame( 'applying', Push_Session::find( $push )['state'], 'the rest is left for the next call' );

		for ( $i = 0; $i < 10 && 'applied' !== Push_Session::find( $push )['state']; $i++ ) {
			$this->assertSame( 200, $this->call_apply( $push )->get_status() );
		}
		remove_filter( 'wp_insert_post_data', $slow );
		remove_filter( 'softglaze_sp_time_budget', $budget );
		$this->assertSame( 'applied', Push_Session::find( $push )['state'] );
		foreach ( $ids as $i => $id ) {
			$this->assertSame( 'Budget page ' . $i . ' (staging)', get_post( $id )->post_title );
		}
		$this->assertSame( 0, $this->applied_without_snapshot() );
	}

	/**
	 * Found in a two-site end-to-end run (I-07): the live administrator closed
	 * the receive window during the apply; the push was marked interrupted (window_closed), but an
	 * apply request of the source that had passed routing before the close then resumed it, as a
	 * source may resume an apply it drove. The source's next calls met the closed window and paused,
	 * so live showed "applying" with nobody driving it until the stale-lock check minutes later. A
	 * push interrupted because the window was closed now waits for a live administrator.
	 */
	public function test_i07_a_request_in_flight_when_the_window_closes_does_not_resume_the_push() {
		list( $push, $ids ) = $this->pages_push( 3, 'I07b page' );
		$this->one_item_per_step();
		$this->apply_until_written( $push, 1 );

		Receive_Window::close( 'manual' );
		$this->assertSame( 'interrupted', Push_Session::find( $push )['state'] );

		// A request that passed routing before the close (the routes of this test's server stay).
		$applied = count( array_filter( array_column( $this->rows( $push ), 'status' ), static function ( $s ) { return 'applied' === $s; } ) );
		$this->assertSame( 200, $this->call_apply( $push )->get_status() );
		$session = Push_Session::find( $push );
		$this->assertSame( 'interrupted', $session['state'], 'not resumed by the source' );
		$this->assertSame( 'window_closed', $session['error_code'] );
		$this->assertSame( $applied, count( array_filter( array_column( $this->rows( $push ), 'status' ), static function ( $s ) { return 'applied' === $s; } ) ), 'nothing written after the close' );

		// The administrator resumes on live; nothing is written twice.
		$this->assertSame( 200, $this->recover( $push, 'resume' )->get_status() );
		$this->continue_all( $push );
		$this->assertSame( 'applied', Push_Session::find( $push )['state'] );
		foreach ( $ids as $i => $id ) {
			$this->assertSame( 'I07b page ' . $i . ' (staging)', get_post( $id )->post_title );
		}
		$this->assertSame( 0, $this->applied_without_snapshot() );
	}

	/**
	 * Found in a two-site end-to-end run (I-04 again, live's PHP restarted during the allocate
	 * pass): a step died after core had created a placeholder draft but before the draft carried the
	 * item's UUID (stamped after wp_insert_post() and its save hooks), so the next step could not
	 * recognise it and made a second placeholder; the first stayed behind as an empty draft. The
	 * UUID is now written by the same wp_insert_post() call, before any save hook runs.
	 */
	public function test_a_placeholder_carries_its_uuid_before_cores_save_hooks_run() {
		$scratch = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Alloc page',
				'post_status' => 'publish',
			)
		);
		$envs    = $this->new_items( array( 'post' => array( $scratch ) ) );
		$uuid    = $envs[ $scratch ]['uuid'];
		$push    = $this->stage( array_values( $envs ) );
		// When core fires the placeholder's save hooks, the row exists; a process killed from there
		// on leaves a placeholder that the next step finds by its UUID only if it already has it.
		// (A PHP exception cannot stand in for the kill: the applier catches what an item throws.)
		$seen = array();
		$spy  = static function ( $post_id, $post ) use ( &$seen ) {
			if ( 'draft' === $post->post_status && 'Alloc page' === $post->post_title ) {
				$seen[] = Identity::get( 'post', (int) $post_id );
			}
		};
		add_action( 'save_post_page', $spy, 10, 2 );
		$answers = $this->apply( $push );
		remove_action( 'save_post_page', $spy, 10 );
		$this->assertSame( 'applied', end( $answers )['state'] );
		$this->assertNotEmpty( $seen, 'the placeholder was created' );
		$this->assertSame( $uuid, $seen[0], 'the placeholder carries the UUID when its save hooks run' );

		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'any',
				'title'       => 'Alloc page',
				'numberposts' => 10,
				'fields'      => 'ids',
			)
		);
		$this->assertCount( 1, $pages, 'one page, no stray placeholder' );
		$this->assertSame( $uuid, Identity::get( 'post', (int) $pages[0] ) );
	}

	/**
	 * Found in a two-site end-to-end run (F-01): live pages item results by row ID, but rows
	 * finish in apply order (dependencies first), so a row that finished after a higher row had been
	 * reported was never reported; staging counted 297 of 300 applied items, and a skipped or failed
	 * item could have gone unreported the same way. Every result now reaches the source once.
	 */
	public function test_apply_results_reach_the_source_once_each_when_rows_finish_out_of_order() {
		list( $push, , $envs ) = $this->pages_push( 3, 'Order page' );

		// The last row finishes first.
		$this->set_row( $push, $envs[2]['uuid'], array( 'status' => 'applied' ) );
		$answer = \SoftGlaze\SafePush\Apply\Applier::answer( Push_Session::find( $push ), 0 );
		$seen   = array_column( $answer['results'], 'uuid' );

		// Then the others.
		$this->set_row( $push, $envs[0]['uuid'], array( 'status' => 'applied' ) );
		$this->set_row( $push, $envs[1]['uuid'], array( 'status' => 'skipped' ) );
		$answer = \SoftGlaze\SafePush\Apply\Applier::answer( Push_Session::find( $push ), (int) $answer['next'] );
		$seen   = array_merge( $seen, array_column( $answer['results'], 'uuid' ) );

		$want = array( $envs[0]['uuid'], $envs[1]['uuid'], $envs[2]['uuid'] );
		sort( $want );
		sort( $seen );
		$this->assertSame( $want, $seen, 'each result once, the skipped one included' );
	}

	public function test_the_time_budget_follows_max_execution_time_and_its_filter_may_only_lower_it() {
		$this->assertSame( 20, Limits::time_budget() );
		add_filter(
			'softglaze_sp_time_budget',
			static function () {
				return 300;
			}
		);
		$this->assertSame( 20, Limits::time_budget(), 'never raised' );
		remove_all_filters( 'softglaze_sp_time_budget' );
		add_filter(
			'softglaze_sp_time_budget',
			static function () {
				return 4;
			}
		);
		$this->assertSame( 4, Limits::time_budget() );
		// The deadline is taken first: on a clock with microsecond resolution, a bound computed
		// before it is always a few microseconds too early.
		$deadline = Limits::deadline( 1 );
		$this->assertLessThanOrEqual( microtime( true ) + 3, $deadline );
	}

	/**
	 * Sets memory_limit just above what this request uses, so 70 % of it is already reached.
	 */
	private function low_memory() {
		// Above what PHP has allocated (it refuses a lower limit), below used / 0.7, so 70 % of the
		// new limit is already passed while the request still has room to answer. When this process
		// holds much more than it uses (after earlier tests), a ballast string raises the use first.
		$need = (int) ceil( ( 0.7 * ( memory_get_usage( true ) + 6 * MB_IN_BYTES ) + 6 * MB_IN_BYTES - memory_get_usage() ) / 0.3 );
		if ( $need > 0 ) {
			$this->ballast = str_repeat( 'x', $need + MB_IN_BYTES );
		}
		$real  = memory_get_usage( true );
		$limit = $real + 6 * MB_IN_BYTES;
		if ( 0.7 * $limit > memory_get_usage() - 4 * MB_IN_BYTES ) {
			$this->markTestSkipped( 'no room for a low memory_limit in this process' );
		}
		ini_set( 'memory_limit', (string) $limit ); // phpcs:ignore WordPress.PHP.IniSet -- Test: a low limit set inside the test.
		add_filter(
			'admin_memory_limit',
			static function () use ( $limit ) {
				// A host whose administration limit is its PHP limit (wp_raise_memory_limit() would
				// otherwise lift it to WP_MAX_MEMORY_LIMIT, unlimited in this test process).
				return (string) ( $limit + 1024 );
			}
		);
		$this->assertFalse( Limits::memory_ok() );
	}

	public function test_every_working_remote_handler_answers_503_server_busy_above_70_percent_of_memory() {
		list( $push ) = $this->pages_push( 1, 'Busy page' );
		$routes       = array(
			array( 'POST', '/keepmerge/v1/remote/manifest', '{"items":[]}' ),
			array( 'POST', '/keepmerge/v1/remote/items/fetch', '{"items":[]}' ),
			array( 'POST', '/keepmerge/v1/remote/baseline', '{"fingerprints":[]}' ),
			array( 'POST', '/keepmerge/v1/remote/push/' . $push . '/apply', '{"after":0}' ),
			array( 'POST', '/keepmerge/v1/remote/push/' . $push . '/items', '{"seq":2,"items":[]}' ),
			array( 'POST', '/keepmerge/v1/remote/push/' . $push . '/files', '{"files":[]}' ),
		);
		$before = $this->content_counts();
		$this->low_memory();
		foreach ( $routes as $route ) {
			$response = $this->call( $route[0], $route[1], $route[2] );
			$this->assertSame( 503, $response->get_status(), $route[1] );
			$this->assertSame( 'server_busy', $response->get_data()['code'] );
			$this->assertSame( '30', (string) $response->get_headers()['Retry-After'] );
		}
		ini_set( 'memory_limit', $this->memory ); // phpcs:ignore WordPress.PHP.IniSet -- Test: restores the limit.
		$this->assertSame( $before, $this->content_counts(), 'nothing written' );
		$this->assertSame( 'staged', Push_Session::find( $push )['state'] );
	}

	public function test_the_apply_and_rollback_steps_stop_at_the_memory_guard_and_go_on_afterwards() {
		list( $push, $ids ) = $this->pages_push( 3, 'Guard page' );
		$this->apply( $push );
		$this->assertSame( 'applied', Push_Session::find( $push )['state'] );

		// A rollback step under the guard does nothing but start.
		$this->low_memory();
		$step = $this->rollback_step( $push );
		ini_set( 'memory_limit', $this->memory ); // phpcs:ignore WordPress.PHP.IniSet -- Test: restores the limit.
		remove_all_filters( 'admin_memory_limit' );
		$this->assertSame( 200, $step->get_status(), wp_json_encode( $step->get_data() ) );
		$this->assertSame( 'rolling_back', $step->get_data()['state'] );
		$this->assertSame( 0, (int) $step->get_data()['rollback']['done'], 'no row handled above 70 % of memory' );
		foreach ( $ids as $i => $id ) {
			$this->assertSame( 'Guard page ' . $i . ' (staging)', get_post( $id )->post_title );
		}
		$this->rollback( $push );
		$this->assertSame( 'Guard page 0', get_post( $ids[0] )->post_title );

		// An apply step under the guard (called past the route's entry guard) takes no item.
		list( $push2, $ids2 ) = $this->pages_push( 2, 'Guard two' );
		$this->low_memory();
		$answer = \SoftGlaze\SafePush\Apply\Applier::remote( Push_Session::find( $push2 ), array( 'after' => 0 ) );
		ini_set( 'memory_limit', $this->memory ); // phpcs:ignore WordPress.PHP.IniSet -- Test: restores the limit.
		remove_all_filters( 'admin_memory_limit' );
		$this->assertSame( 'snapshotting', $answer['state'] );
		$this->assertSame( 0, $answer['apply']['done'] );
		foreach ( $this->rows( $push2 ) as $row ) {
			$this->assertNull( $row['snapshot_gmt'], 'no row handled above 70 % of memory' );
		}
		$answers = $this->apply( $push2 );
		$this->assertSame( 'applied', end( $answers )['state'] );
		$this->assertSame( 'Guard two 1 (staging)', get_post( $ids2[1] )->post_title );
	}

	public function test_a_manifest_answer_defers_entries_above_the_memory_guard() {
		$id     = $this->page( 'Manifest page' );
		$uuid   = Identity::assign( 'post', $id );
		$answer = SoftGlaze\SafePush\Sync\Manifest::answer(
			Pairing_Service::list_destination()[0] + array( 'id' => (int) SoftGlaze\SafePush\Pairing_Store::find( $this->pairing_uuid )['id'] ),
			array(
				'items' => array(
					array(
						'uuid' => $uuid,
						'kind' => 'post',
						'type' => 'page',
					),
				),
			)
		);
		$this->assertNotSame( 'deferred', $answer['items'][0]['state'] );
		$this->low_memory();
		$answer = SoftGlaze\SafePush\Sync\Manifest::answer(
			array( 'id' => (int) SoftGlaze\SafePush\Pairing_Store::find( $this->pairing_uuid )['id'] ),
			array(
				'items' => array(
					array(
						'uuid' => $uuid,
						'kind' => 'post',
						'type' => 'page',
					),
				),
			)
		);
		ini_set( 'memory_limit', $this->memory ); // phpcs:ignore WordPress.PHP.IniSet -- Test: restores the limit.
		$this->assertSame( 'deferred', $answer['items'][0]['state'], 'asked again by the source' );
	}

	// --- audit collapse under load ----------------------------------------------------------------------

	public function test_the_audit_log_collapses_500_failures_from_one_address() {
		global $wpdb;
		$table = Tables::table_name( 'audit' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		for ( $i = 0; $i < 500; $i++ ) {
			Audit_Log::write(
				'auth_failed',
				array(
					'result'     => 'denied',
					'actor_type' => 'remote',
					'ip'         => '198.51.100.7',
					'details'    => array( 'reason' => 0 === $i % 2 ? 'signature_invalid' : 'clock_skew' ),
				)
			);
		}
		Audit_Log::write(
			'auth_failed',
			array(
				'result'     => 'denied',
				'actor_type' => 'remote',
				'ip'         => '198.51.100.8',
				'details'    => array( 'reason' => 'signature_invalid' ),
			)
		);
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT ip, details FROM %i WHERE event = %s', $table, 'auth_failed' ), ARRAY_A );
		$this->assertCount( 3, $rows, 'one row per address and reason' );
		$total = 0;
		foreach ( $rows as $row ) {
			$details = json_decode( $row['details'], true );
			$total  += (int) $details['count'];
		}
		$this->assertSame( 501, $total, 'every failure counted' );
	}

	// --- error codes with fix text --------------------------------------------------------------------

	public function test_every_signed_error_code_has_its_fix_text_on_the_source() {
		$codes = array( 'window_closed', 'pairing_revoked', 'pairing_not_approved', 'clock_skew', 'signature_invalid', 'https_required', 'authorization_header_missing', 'rate_limited', 'push_locked', 'storage_full', 'storage_unwritable', 'path_refused', 'code_report_disabled', 'address_refused', 'transport_unpinned', 'server_busy', 'push_failed_partial', 'push_interrupted', 'push_rolled_back' );
		$generic = Remote_Client::message( 'no_such_code_here', 409 );
		foreach ( $codes as $code ) {
			$text = Remote_Client::message( $code, 409 );
			$this->assertNotSame( $generic, $text, $code . ' has its own text' );
			$this->assertGreaterThan( 40, strlen( $text ), $code );
		}
	}
}
