<?php
/**
 * Audit_Log: fixed events, secret-key guard, collapse rule, CSV neutralisation, export paging,
 * prune and filters.
 *
 * @package SoftGlaze\SafePush\Tests
 */

use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Tables;

/**
 * @group audit
 */
class AuditLogTest extends WP_UnitTestCase {

	/**
	 * Previous REMOTE_ADDR.
	 *
	 * @var mixed
	 */
	private $remote_addr;

	public function set_up() {
		parent::set_up();
		$this->remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
	}

	public function tear_down() {
		if ( null === $this->remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->remote_addr;
		}
		parent::tear_down();
	}

	private function count_rows( $event = null ) {
		global $wpdb;
		if ( null === $event ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Tables::table_name( 'audit' ) ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE event = %s', Tables::table_name( 'audit' ), $event ) );
	}

	private function row( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Tables::table_name( 'audit' ), $id ), ARRAY_A );
	}

	public function test_unknown_events_are_refused() {
		$this->setExpectedIncorrectUsage( 'SoftGlaze\SafePush\Audit_Log::write' );
		$before = $this->count_rows();

		$this->assertFalse( Audit_Log::write( 'made_up_event' ) );
		$this->assertSame( $before, $this->count_rows() );
	}

	public function test_every_event_has_a_label() {
		$labels = Audit_Log::event_labels();
		foreach ( Audit_Log::EVENTS as $event ) {
			$this->assertArrayHasKey( $event, $labels );
		}
		$this->assertCount( 42, Audit_Log::EVENTS );
	}

	public function test_details_are_json_and_secret_keys_are_dropped() {
		$this->setExpectedIncorrectUsage( 'SoftGlaze\SafePush\Audit_Log::clean_details' );

		$id = Audit_Log::write(
			'pairing_confirmed',
			array(
				'details' => array(
					'source'       => 'https://staging.example',
					'secret'       => 'hmac-secret-value',
					'app_password' => 'abcd efgh',
					'pairing_key'  => 'key-value',
					'signature'    => str_repeat( 'f', 64 ),
					'body'         => '{"content":"x"}',
					'nested'       => array(
						'api_key' => 'k',
						'count'   => 3,
					),
				),
			)
		);

		$row = $this->row( $id );
		$this->assertJson( $row['details'] );
		$details = json_decode( $row['details'], true );
		$this->assertSame(
			array(
				'source' => 'https://staging.example',
				'nested' => array( 'count' => 3 ),
			),
			$details
		);
		foreach ( array( 'hmac-secret-value', 'abcd efgh', 'key-value', 'ffffffff', 'content' ) as $leak ) {
			$this->assertStringNotContainsString( $leak, $row['details'] );
		}
	}

	public function test_identical_failures_from_one_address_collapse_into_one_row() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$before                 = $this->count_rows( 'auth_failed' );

		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = Audit_Log::write(
				'auth_failed',
				array(
					'actor_type' => 'remote',
					'result'     => 'denied',
					'details'    => array( 'reason' => 'signature_invalid' ),
				)
			);
		}

		$this->assertSame( $before + 1, $this->count_rows( 'auth_failed' ) );
		$this->assertCount( 1, array_unique( $ids ) );
		$details = json_decode( $this->row( $ids[0] )['details'], true );
		$this->assertSame( 5, $details['count'] );
		$this->assertSame( '203.0.113.7', $this->row( $ids[0] )['ip'] );

		// Another reason, another address: separate rows.
		Audit_Log::write( 'auth_failed', array( 'actor_type' => 'remote', 'result' => 'denied', 'details' => array( 'reason' => 'clock_skew' ) ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		Audit_Log::write( 'auth_failed', array( 'actor_type' => 'remote', 'result' => 'denied', 'details' => array( 'reason' => 'signature_invalid' ) ) );
		$this->assertSame( $before + 3, $this->count_rows( 'auth_failed' ) );
	}

	public function test_a_new_row_starts_after_the_collapse_window() {
		global $wpdb;

		$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
		$first                  = Audit_Log::write( 'ip_blocked', array( 'actor_type' => 'remote', 'result' => 'denied', 'details' => array( 'reason' => 'too_many_failures' ) ) );

		$wpdb->update( Tables::table_name( 'audit' ), array( 'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - 11 * MINUTE_IN_SECONDS ) ), array( 'id' => $first ) );

		$second = Audit_Log::write( 'ip_blocked', array( 'actor_type' => 'remote', 'result' => 'denied', 'details' => array( 'reason' => 'too_many_failures' ) ) );
		$this->assertNotSame( $first, $second );
	}

	public function test_successes_never_collapse() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$a                      = Audit_Log::write( 'status_checked', array( 'actor_type' => 'remote' ) );
		$b                      = Audit_Log::write( 'status_checked', array( 'actor_type' => 'remote' ) );
		$this->assertNotSame( $a, $b );
	}

	public function test_forwarded_headers_are_ignored_and_bad_addresses_dropped() {
		$_SERVER['REMOTE_ADDR']          = 'not-an-ip';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.0.2.1';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$id = Audit_Log::write( 'settings_changed', array( 'details' => array( 'keys' => array( 'audit_days' ) ) ) );
		$this->assertSame( '', $this->row( $id )['ip'] );
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	/**
	 * @dataProvider csv_cells
	 *
	 * @param string $in  Value.
	 * @param string $out Expected cell.
	 */
	public function test_csv_cells_neutralise_formulas( $in, $out ) {
		$this->assertSame( $out, Audit_Log::csv_cell( $in ) );
	}

	public function csv_cells() {
		return array(
			'hyperlink' => array( '=HYPERLINK("http://x","y")', '"\'=HYPERLINK(""http://x"",""y"")"' ),
			'plus'      => array( '+1+1', '"\'+1+1"' ),
			'minus'     => array( '-2+3', '"\'-2+3"' ),
			'at'        => array( '@SUM(A1)', '"\'@SUM(A1)"' ),
			'tab'       => array( "\t=1", "\"'\t=1\"" ),
			'cr'        => array( "\r=1", "\"'\r=1\"" ),
			'plain'     => array( 'settings_changed', '"settings_changed"' ),
			'quotes'    => array( 'say "hi"', '"say ""hi"""' ),
			'number'    => array( 42, '"42"' ),
			'null byte' => array( "a\0b", '"ab"' ),
		);
	}

	public function test_export_neutralises_values_from_the_table() {
		$id  = Audit_Log::write( 'items_fetched', array( 'actor_type' => 'system', 'object_ref' => '=HYPERLINK("http://evil","x")' ) );
		$csv = Audit_Log::export_csv( array( 'event' => 'items_fetched' ) );

		$this->assertStringStartsWith( '"id","created_gmt","event"', $csv['csv'] );
		$this->assertStringContainsString( '"\'=HYPERLINK(', $csv['csv'] );
		$this->assertStringNotContainsString( ',"=HYPERLINK', $csv['csv'] );
		$this->assertGreaterThanOrEqual( 1, $csv['rows'] );
		$this->assertNotFalse( $id );
	}

	public function test_export_pages_by_id_without_repeats() {
		global $wpdb;

		$table  = Tables::table_name( 'audit' );
		$values = array();
		$now    = gmdate( 'Y-m-d H:i:s' );
		for ( $i = 0; $i < Audit_Log::EXPORT_CHUNK + 50; $i++ ) {
			$values[] = $wpdb->prepare( '(%s, %s, %s, %s, %s)', $now, 'system', 'lock_released', 'ok', 'row-' . $i );
		}
		$wpdb->query( "INSERT INTO $table (created_gmt, actor_type, event, result, object_ref) VALUES " . implode( ',', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$first = Audit_Log::export_csv( array( 'event' => 'lock_released' ) );
		$this->assertTrue( $first['has_more'] );
		$this->assertSame( Audit_Log::EXPORT_CHUNK, $first['rows'] );

		$second = Audit_Log::export_csv( array( 'event' => 'lock_released' ), $first['next_before'] );
		$this->assertFalse( $second['has_more'] );
		$this->assertSame( 50, $second['rows'] );
		$this->assertStringNotContainsString( '"id","created_gmt"', $second['csv'], 'header only in the first chunk' );

		$all = $first['csv'] . $second['csv'];
		$this->assertSame( Audit_Log::EXPORT_CHUNK + 50 + 1, substr_count( $all, "\r\n" ) );
	}

	public function test_prune_deletes_only_rows_past_retention() {
		global $wpdb;

		$old   = Audit_Log::write( 'push_applied', array( 'actor_type' => 'system' ) );
		$fresh = Audit_Log::write( 'push_applied', array( 'actor_type' => 'system' ) );
		$wpdb->update( Tables::table_name( 'audit' ), array( 'created_gmt' => gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ) ), array( 'id' => $old ) );

		$this->assertGreaterThanOrEqual( 1, Audit_Log::prune( 180 ) );
		$this->assertNull( $this->row( $old ) );
		$this->assertNotNull( $this->row( $fresh ) );
	}

	public function test_query_filters_and_injection_attempts() {
		Audit_Log::write( 'push_failed', array( 'actor_type' => 'system', 'result' => 'error' ) );
		Audit_Log::write( 'push_resumed', array( 'actor_type' => 'system' ) );

		$only_failed = Audit_Log::query( array( 'event' => 'push_failed' ) );
		$this->assertGreaterThanOrEqual( 1, $only_failed['total'] );
		foreach ( $only_failed['rows'] as $row ) {
			$this->assertSame( 'push_failed', $row['event'] );
		}

		$errors = Audit_Log::query( array( 'result' => 'error' ) );
		foreach ( $errors['rows'] as $row ) {
			$this->assertSame( 'error', $row['result'] );
		}

		$injected = Audit_Log::normalise_filters(
			array(
				'event'  => "' OR 1=1 --",
				'result' => 'ok; DROP TABLE x',
				'from'   => '2026-02-30',
				'to'     => '1 OR 1',
			)
		);
		$this->assertSame( '', $injected['event'] );
		$this->assertSame( '', $injected['result'] );
		$this->assertSame( '1970-01-01 00:00:00', $injected['from'] );
		$this->assertSame( '9999-12-31 23:59:59', $injected['to'] );

		$future = Audit_Log::query( array( 'from' => '2999-01-01' ) );
		$this->assertSame( 0, $future['total'] );
	}
}
