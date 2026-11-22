<?php
/**
 * Transfer\Push_Session and Push_Lock on the destination: the push session routes (R8 open,
 * R9 state, R13 item batches, R15 abort), their limits (S-63, S-66), and the receive window's
 * lock part (S-79).
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Sync\Planner;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Session;

/**
 * @group staging
 * @group session
 */
class PushSessionTest extends WP_UnitTestCase {

	const PAIRING = array( 'id' => 4321 );

	const OTHER = array( 'id' => 4322 );

	const H = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

	public function set_up() {
		parent::set_up();
		delete_option( Push_Lock::OPTION );
		delete_option( Receive_Window::OPTION );
	}

	/**
	 * Envelopes of new pages and the open body that declares exactly them.
	 *
	 * @param int $count Pages.
	 * @return array{0:array,1:array}
	 */
	private function push( $count ) {
		$items = array();
		$lines = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$uuid    = wp_generate_uuid4();
			$items[] = array(
				'uuid'    => $uuid,
				'kind'    => 'post',
				'type'    => 'page',
				'action'  => 'create',
				'hash'    => self::H,
				'live_id' => 0,
				'data'    => array(
					'post_type'    => 'page',
					'post_title'   => 'Page ' . $i,
					'post_content' => 'Body ' . $i,
					'post_status'  => 'publish',
				),
				'refs'    => array(),
			);
			$lines[] = $uuid . ':create:' . self::H;
		}
		$body = array(
			'push'      => wp_generate_uuid4(),
			'plan_hash' => Planner::plan_hash( $lines ),
			'counts'    => array(
				'create'   => $count,
				'update'   => 0,
				'recreate' => 0,
				'trash'    => 0,
				'adopt'    => 0,
			),
			'items'     => $count,
			'files'     => 0,
		);
		return array( $body, $items );
	}

	private function open( array $body, $pairing = self::PAIRING ) {
		return Push_Session::open( $pairing, $body );
	}

	private function send( array $body, $seq, array $items, $pairing = self::PAIRING ) {
		$session = Push_Session::for_pairing( $body['push'], $pairing );
		if ( is_wp_error( $session ) ) {
			return $session;
		}
		return Push_Session::receive( $session, $pairing, json_decode( wp_json_encode( array( 'seq' => $seq, 'items' => $items ) ), true ) );
	}

	private function rows( $push_uuid = '' ) {
		global $wpdb;
		if ( '' === $push_uuid ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE side = %s', Tables::table_name( 'push_items' ), 'destination' ) );
		}
		$session = Push_Session::find( $push_uuid );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE push_id = %d', Tables::table_name( 'push_items' ), (int) $session['id'] ) );
	}

	private function assertCode( $code, $status, $result ) {
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	private function age_session( $push_uuid, $seconds ) {
		global $wpdb;
		$wpdb->update( Tables::table_name( 'pushes' ), array( 'started_gmt' => gmdate( 'Y-m-d H:i:s', time() - $seconds ) ), array( 'push_uuid' => $push_uuid ) );
	}

	// --- R8 and the lock ----------------------------------------------------------------------

	public function test_open_body_is_read_field_by_field() {
		list( $body ) = $this->push( 2 );
		$bad          = array(
			'push not a uuid'   => array_merge( $body, array( 'push' => 'x' ) ),
			'push upper case'   => array_merge( $body, array( 'push' => strtoupper( $body['push'] ) ) ),
			'plan hash'         => array_merge( $body, array( 'plan_hash' => 'abc' ) ),
			'counts missing'    => array_diff_key( $body, array( 'counts' => 1 ) ),
			'count string'      => array_merge( $body, array( 'counts' => array_merge( $body['counts'], array( 'update' => '0' ) ) ) ),
			'items not the sum' => array_merge( $body, array( 'items' => 3 ) ),
			'no items'          => array_merge( $body, array( 'items' => 0, 'counts' => array_fill_keys( Push_Session::ACTIONS, 0 ) ) ),
			'files negative'    => array_merge( $body, array( 'files' => -1 ) ),
		);
		foreach ( $bad as $label => $candidate ) {
			$this->assertCode( 'invalid_body', 400, $this->open( $candidate ) );
		}
		$this->assertCode( 'too_many_items', 400, $this->open( array_merge( $body, array( 'items' => 5001, 'counts' => array_merge( $body['counts'], array( 'create' => 5001 ) ) ) ) ) );
		$this->assertNull( Push_Lock::get(), 'nothing was locked' );
	}

	public function test_s66_one_session_holds_the_lock_and_a_second_push_gets_409() {
		list( $a ) = $this->push( 2 );
		list( $b ) = $this->push( 1 );

		$opened = $this->open( $a );
		$this->assertSame( 'open', $opened['state'] );
		$this->assertSame( 'fresh', $opened['lock'] );
		$this->assertSame( $a['push'], Push_Lock::get()['push_uuid'] );
		$this->assertSame( 'no', $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT IF(autoload IN (%s, %s), %s, %s) FROM %i WHERE option_name = %s', 'yes', 'on', 'yes', 'no', $GLOBALS['wpdb']->options, Push_Lock::OPTION ) ), 'the lock is not autoloaded' );

		$this->assertCode( 'push_locked', 409, $this->open( $b ) );
		$this->assertCode( 'push_locked', 409, $this->open( $b, self::OTHER ) );

		// The same open sent again (its answer was lost) returns the same session.
		$again = $this->open( $a );
		$this->assertSame( $a['push'], $again['push'] );
		// The same ID with another plan is not the same push.
		$this->assertCode( 'push_exists', 409, $this->open( array_merge( $a, array( 'plan_hash' => str_repeat( 'd', 64 ) ) ) ) );
		$this->assertCode( 'push_exists', 409, $this->open( $a, self::OTHER ) );
	}

	public function test_s66_a_stale_lock_shows_the_session_interrupted_and_is_not_taken_over() {
		list( $a, $items ) = $this->push( 2 );
		list( $b )         = $this->push( 1 );
		$this->open( $a );

		$lock              = Push_Lock::get();
		$lock['heartbeat'] = time() - 301;
		update_option( Push_Lock::OPTION, $lock, false );

		$view = Push_Session::present( Push_Session::find( $a['push'] ) );
		$this->assertTrue( $view['interrupted'] );
		$this->assertSame( 'stale', $view['lock'] );
		$this->assertCode( 'push_locked', 409, $this->open( $b ) );

		// Its own source resumes: the heartbeat is fresh again.
		$this->send( $a, 1, array( $items[0] ) );
		$this->assertSame( 'fresh', Push_Session::present( Push_Session::find( $a['push'] ) )['lock'] );
	}

	public function test_abort_purges_the_staged_rows_releases_the_lock_and_lets_the_next_push_open() {
		list( $a, $items ) = $this->push( 3 );
		list( $b )         = $this->push( 1 );
		$this->open( $a );
		$this->send( $a, 1, array_slice( $items, 0, 2 ) );
		$this->assertSame( 2, $this->rows( $a['push'] ) );

		$session = Push_Session::for_pairing( $a['push'], self::PAIRING );
		$aborted = Push_Session::abort( $session, self::PAIRING );
		$this->assertSame( 'aborted', $aborted['state'] );
		$this->assertSame( 0, $this->rows( $a['push'] ) );
		$this->assertNull( Push_Lock::get() );

		// Aborting again is harmless; items for an aborted session are refused.
		$this->assertSame( 'aborted', Push_Session::abort( Push_Session::find( $a['push'] ), self::PAIRING )['state'] );
		$this->assertCode( 'push_aborted', 409, $this->send( $a, 2, array( $items[2] ) ) );

		$this->assertSame( 'open', $this->open( $b )['state'] );
	}

	public function test_a_lock_whose_session_is_gone_or_expired_does_not_block() {
		update_option(
			Push_Lock::OPTION,
			array(
				'push_uuid' => wp_generate_uuid4(),
				'heartbeat' => time(),
			),
			false
		);
		list( $a, $items ) = $this->push( 2 );
		$this->assertSame( 'open', $this->open( $a )['state'] );
		$this->send( $a, 1, array( $items[0] ) );

		// 24 hours unapplied: the next open expires it (rows purged) and takes the lock.
		$this->age_session( $a['push'], DAY_IN_SECONDS + 5 );
		list( $b ) = $this->push( 1 );
		$this->assertSame( 'open', $this->open( $b )['state'] );
		$this->assertSame( 'expired', Push_Session::find( $a['push'] )['state'] );
		$this->assertSame( 0, $this->rows( $a['push'] ) );
		$this->assertSame( $b['push'], Push_Lock::get()['push_uuid'] );
	}

	public function test_expire_old_purges_sessions_after_24_hours_and_audits_them() {
		global $wpdb;
		list( $a, $items ) = $this->push( 2 );
		$this->open( $a );
		$this->send( $a, 1, $items );
		$this->assertSame( 'staged', Push_Session::find( $a['push'] )['state'] );

		$this->assertSame( 0, Push_Session::expire_old() );
		$this->age_session( $a['push'], DAY_IN_SECONDS + 5 );
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE event = 'push_aborted' AND actor_type = 'system'", Tables::table_name( 'audit' ) ) );
		$this->assertSame( 1, Push_Session::expire_old() );
		$this->assertSame( 'expired', Push_Session::find( $a['push'] )['state'] );
		$this->assertSame( 0, $this->rows( $a['push'] ) );
		$this->assertNull( Push_Lock::get() );
		$this->assertSame( $before + 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE event = 'push_aborted' AND actor_type = 'system'", Tables::table_name( 'audit' ) ) ) );
		$this->assertCode( 'push_expired', 410, $this->send( $a, 2, $items ) );
	}

	// --- R13: batches -------------------------------------------------------------------------

	public function test_batches_are_idempotent_by_push_and_seq_and_items_by_uuid() {
		list( $a, $items ) = $this->push( 4 );
		$this->open( $a );

		$this->assertCode( 'batch_out_of_order', 409, $this->send( $a, 2, array( $items[0] ) ) );

		$first = $this->send( $a, 1, array( $items[0], $items[1] ) );
		$this->assertFalse( $first['replay'] );
		$this->assertSame( array( 'staged', 'staged' ), array_column( $first['results'], 'status' ) );
		$this->assertSame( 2, $this->rows( $a['push'] ) );
		$this->assertSame( 'receiving', $first['session']['state'] );

		// The same batch again (a lost answer, a reopened tab): stored results, nothing written.
		$again = $this->send( $a, 1, array( $items[0], $items[1] ) );
		$this->assertTrue( $again['replay'] );
		$this->assertSame( array( true, true ), array_column( $again['results'], 'duplicate' ) );
		$this->assertSame( 2, $this->rows( $a['push'] ) );

		// The same number with other items is not the same batch.
		$this->assertCode( 'batch_mismatch', 409, $this->send( $a, 1, array( $items[2] ) ) );

		// An item already received, inside a new batch, is reported as received before.
		$mixed = $this->send( $a, 2, array( $items[1], $items[2] ) );
		$this->assertSame( array( true, false ), array( $mixed['results'][0]['duplicate'], isset( $mixed['results'][1]['duplicate'] ) ) );
		$this->assertSame( 3, $this->rows( $a['push'] ) );

		// More new items than declared.
		list( , $extra ) = $this->push( 2 );
		$this->assertCode( 'item_count_exceeded', 409, $this->send( $a, 3, array( $items[3], $extra[0] ) ) );
		$this->assertSame( 3, $this->rows( $a['push'] ) );

		$last = $this->send( $a, 3, array( $items[3] ) );
		$this->assertSame( 'staged', $last['session']['state'] );
		$this->assertTrue( $last['session']['plan_hash_ok'] );
		$this->assertSame( 4, $last['session']['received']['staged'] );
		$this->assertCode( 'item_count_exceeded', 409, $this->send( $a, 4, array( $extra[0] ) ) );
	}

	public function test_s63_batch_size_limits_and_malformed_batches() {
		list( $a, $items ) = $this->push( 1 );
		$this->open( $a );
		$many = array();
		for ( $i = 0; $i < 51; $i++ ) {
			$many[] = array_merge( $items[0], array( 'uuid' => wp_generate_uuid4() ) );
		}
		$this->assertCode( 'too_many_items', 400, $this->send( $a, 1, $many ) );
		$this->assertCode( 'invalid_body', 400, $this->send( $a, 1, array( $items[0], $items[0] ) ) );
		$this->assertCode( 'invalid_body', 400, $this->send( $a, 1, array( array( 'uuid' => 'x' ) ) ) );
		$this->assertCode( 'invalid_body', 400, $this->send( $a, 0, $items ) );
		$this->assertCode( 'invalid_body', 400, Push_Session::receive( Push_Session::find( $a['push'] ), self::PAIRING, array( 'seq' => 1 ) ) );
		$this->assertSame( 0, $this->rows( $a['push'] ) );
	}

	public function test_the_final_pass_refuses_unresolved_references_and_checks_the_plan_hash() {
		list( $a, $items ) = $this->push( 3 );
		$missing           = wp_generate_uuid4();
		$items[0]['refs']  = array(
			array(
				'path' => 'post_parent',
				'kind' => 'post',
				'id'   => 5,
				'uuid' => $items[2]['uuid'],
			),
		);
		$items[1]['refs']  = array(
			array(
				'path' => 'post_parent',
				'kind' => 'post',
				'id'   => 6,
				'uuid' => $missing,
			),
		);
		$this->open( $a );
		$batch = $this->send( $a, 1, array( $items[0], $items[1] ) );
		$this->assertSame( array( 'staged', 'staged' ), array_column( $batch['results'], 'status' ), 'pending until the push is complete' );

		$last = $this->send( $a, 2, array( $items[2] ) );
		$this->assertSame( 'staged', $last['session']['state'] );
		$this->assertSame( 1, $last['session']['received']['refused'] );
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status, error, refs FROM %i WHERE uuid = %s', Tables::table_name( 'push_items' ), $items[1]['uuid'] ), ARRAY_A );
		$this->assertSame( array( 'refused', 'unresolved_reference', '[]' ), array( $row['status'], $row['error'], $row['refs'] ) );
		$refs = json_decode( $wpdb->get_var( $wpdb->prepare( 'SELECT refs FROM %i WHERE uuid = %s', Tables::table_name( 'push_items' ), $items[0]['uuid'] ) ), true );
		$this->assertSame( 'push', $refs[0]['res'] );
	}

	public function test_a_plan_hash_that_does_not_match_fails_the_session_and_releases_the_lock() {
		list( $a, $items ) = $this->push( 2 );
		$a['plan_hash']    = str_repeat( 'e', 64 );
		$this->open( $a );
		$last = $this->send( $a, 1, $items );
		$this->assertSame( 'failed', $last['session']['state'] );
		$this->assertSame( 'plan_hash_mismatch', $last['session']['error'] );
		$this->assertFalse( $last['session']['plan_hash_ok'] );
		$this->assertNull( Push_Lock::get() );
	}

	public function test_sessions_belong_to_their_pairing_and_need_the_lock() {
		list( $a, $items ) = $this->push( 2 );
		$this->open( $a );
		$this->assertCode( 'push_not_found', 404, Push_Session::for_pairing( $a['push'], self::OTHER ) );
		$this->assertCode( 'push_not_found', 404, Push_Session::for_pairing( wp_generate_uuid4(), self::PAIRING ) );

		delete_option( Push_Lock::OPTION );
		$this->assertCode( 'lock_lost', 409, $this->send( $a, 1, $items ) );
		$this->assertSame( 0, $this->rows( $a['push'] ) );
	}

	public function test_staging_writes_only_keepmerges_own_tables() {
		global $wpdb;
		$counts = function () use ( $wpdb ) {
			return array(
				(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ),
				(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" ),
				(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->terms}" ),
				(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->term_relationships}" ),
				(int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Tables::table_name( 'sync_state' ) ),
			);
		};
		list( $a, $items ) = $this->push( 3 );
		$before            = $counts();
		$this->open( $a );
		$this->send( $a, 1, $items );
		$this->assertSame( $before, $counts() );
		$this->assertSame( 3, $this->rows( $a['push'] ) );
	}

	// --- S-79: the receive window and a real session's lock ---------------------------------------

	public function test_s79_the_window_waits_for_a_fresh_lock_never_past_24_hours() {
		Receive_Window::open( 1 );
		list( $a ) = $this->push( 1 );
		$this->open( $a );

		$state              = Receive_Window::state();
		$state['closes_at'] = time() - 10;
		update_option( Receive_Window::OPTION, $state, true );
		$this->assertTrue( Receive_Window::is_open(), 'a session with a fresh heartbeat keeps it open' );

		$lock              = Push_Lock::get();
		$lock['heartbeat'] = time() - 301;
		update_option( Push_Lock::OPTION, $lock, false );
		$this->assertFalse( Receive_Window::is_open(), 'a stale lock does not' );

		Receive_Window::open( 1 );
		Push_Lock::heartbeat( $a['push'] );
		$state              = Receive_Window::state();
		$state['closes_at'] = time() - 10;
		$state['opened_at'] = time() - DAY_IN_SECONDS - 60;
		update_option( Receive_Window::OPTION, $state, true );
		$this->assertFalse( Receive_Window::is_open(), 'never past 24 hours after opening' );
	}
}
