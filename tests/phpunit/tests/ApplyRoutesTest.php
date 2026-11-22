<?php
/**
 * The apply on live through the signed route POST /remote/push/{id}/apply, as the receiver user: snapshot
 * before any write, allocation, the write pass, adopt and finish; optimistic concurrency (F-02),
 * ID remapping (F-06), URL tokens (F-07), typed values (F-08), menus (F-10), site editor (F-11),
 * options (F-12), authors (F-13), deletions (F-15), attachments with files.
 *
 * @package SoftGlaze\SafePush\Tests
 */

require_once __DIR__ . '/class-keepmerge-apply-harness.php';

use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Sync\Sync_State;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Session;

/**
 * @group apply
 */
class ApplyRoutesTest extends Keepmerge_Apply_Harness {

	public function test_an_update_is_written_after_its_snapshot_and_its_base_is_recorded() {
		$page   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Old title',
				'post_content' => '<!-- wp:paragraph --><p>Old</p><!-- /wp:paragraph -->',
			)
		);
		$before = $this->live_hash( 'post', $page );
		$env    = $this->envelope(
			'post',
			$page,
			'push',
			static function ( $data ) {
				$data['post_title']           = 'New title';
				$data['post_content']         = '<!-- wp:paragraph --><p>New</p><!-- /wp:paragraph -->';
				$data['meta']['km_note']      = array( 'from staging' );
				return $data;
			}
		);
		$push    = $this->stage( array( $env ) );
		$snaps   = $this->audit_count( 'snapshot_done' );
		$applied = $this->audit_count( 'push_applied' );

		$answers = $this->apply( $push );
		$last    = end( $answers );

		$this->assertSame( 'applied', $last['state'] );
		$this->assertSame( 'New title', get_post( $page )->post_title );
		$this->assertStringContainsString( '<p>New</p>', get_post( $page )->post_content );
		$this->assertSame( 'from staging', get_post_meta( $page, 'km_note', true ) );

		$row = $this->rows( $push )[ $env['uuid'] ];
		$this->assertSame( 'applied', $row['status'] );
		$this->assertSame( 'updated', $row['action'] );
		$this->assertSame( $before, $row['pre_hash'], 'the snapshot holds live\'s version before the write' );
		$this->assertSame( 'Old title', $row['pre_image']['form']['post_title'] );
		$this->assertSame( 'Old title', $row['pre_image']['raw']['post']['post_title'] );
		$this->assertSame( $this->live_hash( 'post', $page ), $row['post_hash'] );
		$this->assertSame( $env['hash'], $row['transfer']['staging_hash'] );
		$this->assertTrue( $row['payload']['cleared'], 'staged content cleared at finish' );
		$this->assertArrayNotHasKey( 'data', $row['payload'] );
		$this->assertSame( 0, $this->applied_without_snapshot() );

		$base = Sync_State::find( (int) Push_Session::find( $push )['pairing_id'], $env['uuid'] );
		$this->assertSame( $row['post_hash'], $base['base_hash'] );
		$this->assertSame( 'pushed', $base['match_confidence'] );
		$this->assertSame( $env['uuid'], Identity::get( 'post', $page ) );

		$this->assertSame( $snaps + 1, $this->audit_count( 'snapshot_done' ) );
		$this->assertSame( $applied + 1, $this->audit_count( 'push_applied' ) );
		$this->assertNull( Push_Lock::get(), 'lock released at finish' );

		// A further call writes nothing and returns the final state.
		$again = $this->call( 'POST', '/keepmerge/v1/remote/push/' . $push . '/apply', '{"after":0}' );
		$this->assertSame( 'applied', $again->get_data()['state'] );
		$this->assertSame( $env['uuid'], $again->get_data()['results'][0]['uuid'] );
		$this->assertSame( $page, $again->get_data()['results'][0]['live_id'] );
	}

	public function test_f02_a_page_edited_on_live_after_the_plan_is_skipped_and_keeps_the_live_edit() {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Planned',
			)
		);
		$env  = $this->envelope(
			'post',
			$page,
			'push',
			static function ( $data ) {
				$data['post_title'] = 'From staging';
				return $data;
			}
		);
		$push = $this->stage( array( $env ) );

		// Edited on live between the plan and the apply.
		wp_update_post(
			array(
				'ID'         => $page,
				'post_title' => 'Edited on live',
			)
		);

		$last = $this->apply( $push );
		$last = end( $last );
		$this->assertSame( 'applied', $last['state'] );
		$this->assertSame( 'Edited on live', get_post( $page )->post_title );
		$row = $this->rows( $push )[ $env['uuid'] ];
		$this->assertSame( 'skipped', $row['status'] );
		$this->assertSame( 'changed_during_push', $row['error'] );
		$this->assertSame( array( 'skipped' ), array_column( $last['results'], 'status' ) );
		$this->assertSame( 'changed_during_push', $last['results'][0]['reason'] );
	}

	public function test_time_boxed_steps_repeat_until_done_and_never_write_twice() {
		$pages = array();
		$envs  = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$pages[ $i ] = self::factory()->post->create(
				array(
					'post_type'  => 'page',
					'post_title' => 'Step ' . $i,
				)
			);
			$envs[]      = $this->envelope(
				'post',
				$pages[ $i ],
				'push',
				static function ( $data ) use ( $i ) {
					$data['post_title'] = 'Stepped ' . $i;
					return $data;
				}
			);
		}
		$push = $this->stage( $envs );
		add_filter(
			'softglaze_sp_apply_step_items',
			static function () {
				return 2;
			}
		);
		$answers = $this->apply( $push );
		$this->assertGreaterThan( 5, count( $answers ), 'several calls' );
		$this->assertSame( 'applied', end( $answers )['state'] );
		foreach ( $pages as $i => $id ) {
			$this->assertSame( 'Stepped ' . $i, get_post( $id )->post_title );
		}
		$this->assertSame( array( 'applied' ), array_values( array_unique( array_column( $this->rows( $push ), 'status' ) ) ) );
		// Results arrive once each across the calls (cursor "after").
		$uuids = array();
		foreach ( $answers as $answer ) {
			foreach ( $answer['results'] as $result ) {
				$uuids[] = $result['uuid'];
			}
		}
		$this->assertCount( 7, $uuids );
		$this->assertCount( 7, array_unique( $uuids ) );
		$this->assertSame( 0, $this->applied_without_snapshot() );
	}

	public function test_a_create_allocates_then_writes_and_records_live_ids() {
		$scratch = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Brand new page',
				'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		$envs    = $this->new_items( array( 'post' => array( $scratch ) ) );
		$env     = $envs[ $scratch ];
		$posts   = $this->content_counts()['posts'];
		$push    = $this->stage( array( $env ) );

		$last = $this->apply( $push );
		$last = end( $last );
		$this->assertSame( 'applied', $last['state'] );

		$row = $this->rows( $push )[ $env['uuid'] ];
		$this->assertSame( 'created', $row['action'] );
		$this->assertTrue( $row['pre_image']['absent'] );
		$new = (int) $row['local_object_id'];
		$this->assertNotSame( $scratch, $new );
		$this->assertSame( 'Brand new page', get_post( $new )->post_title );
		$this->assertSame( 'publish', get_post( $new )->post_status );
		$this->assertSame( $env['uuid'], Identity::get( 'post', $new ) );
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'page' AND post_title = %s", 'Brand new page' ) ), 'one page, no duplicate placeholder' );
		$this->assertGreaterThanOrEqual( $posts + 1, $this->content_counts()['posts'] );
		$this->assertSame( $new, $last['results'][0]['live_id'] );
	}
}
