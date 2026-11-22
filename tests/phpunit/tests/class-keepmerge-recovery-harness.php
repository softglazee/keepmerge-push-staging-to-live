<?php
/**
 * Shared helpers of the rollback and recovery tests: admin calls for the preview, the
 * rollback, resume, skip and lock release; a fabricated crash (a step that died after doing part
 * of its work: the cursor as the last finished step saved it, rows as the dead step left them,
 * the lock's heartbeat in the past); and the hash checks of RB-01.
 *
 * @package SoftGlaze\SafePush\Tests
 */

require_once __DIR__ . '/class-keepmerge-apply-harness.php';

use SoftGlaze\SafePush\Apply\Rollback_Runner;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Push_Lock;
use SoftGlaze\SafePush\Transfer\Push_Session;

/**
 * Base class of the rollback and recovery tests.
 */
abstract class Keepmerge_Recovery_Harness extends Keepmerge_Apply_Harness {

	const HOST = 'example.org';

	public function tear_down() {
		remove_all_filters( 'softglaze_sp_rollback_step_items' );
		remove_all_filters( 'softglaze_sp_time_budget' );
		parent::tear_down();
	}

	protected function session_id( $push ) {
		return (int) Push_Session::find( $push )['id'];
	}

	protected function preview( $push, $user = null ) {
		return $this->admin( 'GET', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) . '/rollback-preview', array(), $user );
	}

	/**
	 * One rollback call (one step) as the admin.
	 */
	protected function rollback_step( $push, array $include = array(), $confirm = self::HOST ) {
		return $this->admin(
			'POST',
			'/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) . '/rollback',
			array(
				'confirm' => $confirm,
				'include' => $include,
			)
		);
	}

	/**
	 * Rollback calls until the push is rolled back; returns the last answer.
	 */
	protected function rollback( $push, array $include = array(), $max = 80 ) {
		$data = array();
		for ( $i = 0; $i < $max; $i++ ) {
			$response = $this->rollback_step( $push, $include );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$data = $response->get_data();
			if ( 'rolled_back' === $data['state'] ) {
				break;
			}
		}
		$this->assertSame( 'rolled_back', $data['state'] );
		return $data;
	}

	protected function recover( $push, $what, array $params = array() ) {
		return $this->admin( 'POST', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) . '/' . $what, array_merge( array( 'confirm' => self::HOST ), $params ) );
	}

	/**
	 * Continue calls (live admin) until the push leaves the running states.
	 */
	protected function continue_all( $push, $max = 60 ) {
		$data = array();
		for ( $i = 0; $i < $max; $i++ ) {
			$response = $this->admin( 'POST', '/keepmerge/v1/admin/pushes/' . $this->session_id( $push ) . '/continue', array( 'confirm' => self::HOST ) );
			$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
			$data = $response->get_data();
			if ( ! in_array( $data['state'], array( 'snapshotting', 'applying' ), true ) ) {
				break;
			}
		}
		return $data;
	}

	/**
	 * Every row the rollback restored is back to its pre_hash; returns how many were checked.
	 */
	protected function assert_restored_to_pre_hash( $push ) {
		$checked = 0;
		foreach ( $this->rows( $push ) as $uuid => $row ) {
			$state = isset( $row['transfer']['rb']['s'] ) ? $row['transfer']['rb']['s'] : '';
			if ( 'restored' !== $state ) {
				continue;
			}
			$this->assertNotNull( $row['restored_gmt'], $uuid );
			$kind = $row['object_type'];
			$ref  = 'option' === $kind ? substr( $uuid, 7 ) : ( 'widgets' === $kind ? 'widgets' : (int) $row['local_object_id'] );
			$this->assertSame( $row['pre_hash'], $this->live_hash( $kind, $ref ), 'restored to its snapshot: ' . $kind . ' ' . $uuid );
			++$checked;
		}
		return $checked;
	}

	/**
	 * The stored cursor of a session (exact text).
	 */
	protected function raw_cursor( $push ) {
		return Push_Session::raw_cursor( $this->session_id( $push ) );
	}

	/**
	 * What a step that died leaves behind: the cursor as the last finished step stored it (with
	 * the dead step's claim expired), the given state, and the lock's last heartbeat 6 minutes ago.
	 */
	protected function crash( $push, $raw_cursor, $state ) {
		global $wpdb;
		$cursor = json_decode( $raw_cursor, true );
		foreach ( array( 'apply', 'rb' ) as $part ) {
			if ( isset( $cursor[ $part ] ) ) {
				$cursor[ $part ]['busy_until'] = time() - 1;
			}
		}
		$wpdb->update(
			Tables::table_name( 'pushes' ),
			array(
				'cursor_json' => wp_json_encode( $cursor ),
				'state'       => $state,
			),
			array( 'id' => $this->session_id( $push ) )
		);
		$this->age_lock();
	}

	protected function age_lock( $seconds = 360 ) {
		$lock = Push_Lock::get();
		if ( null !== $lock ) {
			$lock['heartbeat'] = time() - $seconds;
			update_option( Push_Lock::OPTION, $lock, false );
		}
	}

	protected function set_row( $push, $uuid, array $fields ) {
		global $wpdb;
		$wpdb->update(
			Tables::table_name( 'push_items' ),
			$fields,
			array(
				'push_id' => $this->session_id( $push ),
				'side'    => 'destination',
				'uuid'    => $uuid,
			)
		);
	}

	/**
	 * Counts writes per post ID (wp_update_post / wp_insert_post on an existing post) while active.
	 */
	protected function count_writes( array &$counter ) {
		$callback = static function ( $post_id ) use ( &$counter ) {
			$counter[ (int) $post_id ] = ( isset( $counter[ (int) $post_id ] ) ? $counter[ (int) $post_id ] : 0 ) + 1;
		};
		add_action( 'post_updated', $callback );
		return $callback;
	}

	protected function page( $title, $content = '', array $meta = array() ) {
		$id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_title'   => $title,
				'post_content' => '' === $content ? '<!-- wp:paragraph --><p>' . $title . '</p><!-- /wp:paragraph -->' : $content,
				'post_status'  => 'publish',
			)
		);
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, $key, wp_slash( $value ) );
		}
		return $id;
	}

	/**
	 * The envelope of a page with a new title and content.
	 */
	protected function changed_page( $id, $suffix = ' (staging)' ) {
		return $this->envelope(
			'post',
			$id,
			'push',
			static function ( $data ) use ( $suffix ) {
				$data['post_title']  .= $suffix;
				$data['post_content'] = '<!-- wp:paragraph --><p>Staging text' . $suffix . '</p><!-- /wp:paragraph -->';
				return $data;
			}
		);
	}

	protected function rb_state( $push, $uuid ) {
		$row = $this->rows( $push )[ $uuid ];
		return isset( $row['transfer']['rb']['s'] ) ? $row['transfer']['rb']['s'] : '';
	}

	protected function host() {
		return Rollback_Runner::host();
	}
}
