<?php
/**
 * The dry-run plan on the source.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

use SoftGlaze\SafePush\Apply\Markup_Gate;
use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Limits;
use SoftGlaze\SafePush\Source_Pairing;
use SoftGlaze\SafePush\Transfer\Remote_Client;
use SoftGlaze\SafePush\Woo\Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a dry-run plan in time-boxed steps: exports and hashes every item, reads live's hashes,
 * slugs and code inventory, classifies each row three ways, then resolves dependencies, warnings
 * and the summary. Live is only read; the plan is stored on this site in push_items (side
 * "source") and expires after 60 minutes.
 */
final class Planner {

	/**
	 * Scope groups an admin can pick, and the kinds in each.
	 */
	const SCOPES = array(
		'content'     => array( 'post' ),
		'products'    => array( 'product' ),
		'media'       => array( 'attachment' ),
		'terms'       => array( 'term' ),
		'menus'       => array( 'menu' ),
		'site_editor' => array( 'pattern', 'navigation', 'template', 'template_part', 'global_styles' ),
		'options'     => array( 'option', 'widgets' ),
	);

	const STAGES = array( 'export', 'deleted', 'manifest', 'taken', 'titles', 'code', 'classify', 'closure', 'done' );

	const MANIFEST_ROUTE = '/keepmerge/v1/remote/manifest';

	const BASELINE_ROUTE = '/keepmerge/v1/remote/baseline';

	/**
	 * Items exported per inner batch before the time budget is checked again.
	 */
	const EXPORT_BATCH = 200;

	const MANIFEST_BATCH = 500;

	/**
	 * Smallest manifest batch the adaptive size goes down to.
	 */
	const MANIFEST_MIN_BATCH = 25;

	/**
	 * Gate reasons and problems kept per row.
	 */
	const MAX_REASONS = 5;

	/**
	 * Sample items kept per warning in the summary.
	 */
	const WARNING_SAMPLES = 5;

	/**
	 * Starts a plan for an active source pairing whose baseline is done.
	 *
	 * @param string   $pairing_uuid Pairing UUID.
	 * @param string[] $groups       Scope groups (keys of SCOPES); empty for everything included.
	 * @param int      $user_id      User asking (0 for WP-CLI).
	 * @return array|WP_Error The plan as presented.
	 */
	public static function start( $pairing_uuid, array $groups, $user_id ) {
		$row = Source_Pairing::find( $pairing_uuid );
		if ( null === $row ) {
			return new WP_Error( 'pairing_not_found', __( 'This pairing does not exist on this site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
		}
		if ( 'active' !== $row['status'] ) {
			return new WP_Error( 'pairing_not_active', __( 'This pairing is not active. Check its status on the Send tab.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		$baseline = Baseline::record( (string) $row['pairing_uuid'] );
		if ( null === $baseline || 'done' !== $baseline['state'] ) {
			return new WP_Error( 'baseline_needed', __( 'Run the baseline first: the plan needs to know which items already match on live.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		$clean = array();
		foreach ( $groups as $group ) {
			if ( is_string( $group ) && isset( self::SCOPES[ $group ] ) ) {
				$clean[] = $group;
			} else {
				return new WP_Error( 'invalid_scope', __( 'Unknown type in the plan scope.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
			}
		}
		$clean = array_values( array_unique( $clean ) );
		$kinds = array();
		foreach ( empty( $clean ) ? array_keys( self::SCOPES ) : $clean as $group ) {
			foreach ( self::SCOPES[ $group ] as $kind ) {
				$kinds[] = $kind;
			}
		}

		Plan_Store::prune( (int) $row['id'] );

		$list = array();
		foreach ( Items::enumerate() as $ref ) {
			$list[] = array( $ref['kind'], $ref['ref'] );
		}

		$cursor = array(
			'pairing'        => (string) $row['pairing_uuid'],
			'stage'          => 'export',
			'steps'          => 0,
			'scope'          => $clean,
			'kinds'          => $kinds,
			'list'           => $list,
			'pos'            => 0,
			'blocks'         => array(),
			'themes'         => array(),
			'first_sent'     => false,
			'live_cursor'    => 0,
			'live_done'      => false,
			'live_deleted'   => array(),
			'missing_blocks' => array(),
			'missing_themes' => array(),
			'woo_live'       => null,
			'after'          => 0,
			'clone_gmt'      => empty( $baseline['clone_unknown'] ) && isset( $baseline['clone_gmt'] ) ? (int) $baseline['clone_gmt'] : null,
			'clone_date'     => empty( $baseline['clone_unknown'] ) && isset( $baseline['clone_date'] ) ? (string) $baseline['clone_date'] : null,
		);

		$id = Plan_Store::create( (int) $row['id'], (int) $user_id, $cursor, array() );
		if ( $id < 1 ) {
			return new WP_Error( 'plan_not_saved', __( 'The plan could not be saved on this site. Try again.', 'keepmerge-push-staging-to-live' ), array( 'status' => 500 ) );
		}

		Audit_Log::write(
			'plan_requested',
			array(
				'pairing_id'  => (int) $row['id'],
				'object_type' => 'plan',
				'object_ref'  => (string) $id,
				'details'     => array(
					'stage' => 'started',
					'scope' => empty( $clean ) ? 'all' : implode( ',', $clean ),
					'items' => count( $list ),
				),
			)
		);

		return self::present( Plan_Store::find( $id ) );
	}

	/**
	 * Runs one time-boxed step of a plan.
	 *
	 * @param int        $plan_id Plan ID.
	 * @param array      $args    steps (int): the step count the screen last saw (optimistic check).
	 * @param mixed|null $client  Client with post( $route, $body, $opts ) (tests); the pairing's own by default.
	 * @return array|WP_Error The plan as presented.
	 */
	public static function step( $plan_id, array $args = array(), $client = null ) {
		$plan = self::usable( $plan_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$cursor = $plan['cursor_json'];
		if ( 'planning' !== $plan['state'] ) {
			return self::present( $plan );
		}
		if ( isset( $args['steps'] ) && (int) $args['steps'] !== (int) $cursor['steps'] ) {
			return new WP_Error( 'plan_moved', __( 'The plan moved on in another window or tab. The screen now shows where it is.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		$row = null;
		if ( null === $client ) {
			$active = Source_Pairing::active_client( $cursor['pairing'] );
			if ( is_wp_error( $active ) ) {
				return $active;
			}
			$row    = $active['row'];
			$client = $active['client'];
		}

		$deadline = microtime( true ) + max( 3, Limits::time_budget() - 3 );
		++$cursor['steps'];
		$error = null;

		while ( 'done' !== $cursor['stage'] && ! Limits::over( $deadline ) ) {
			$before = $cursor['stage'];
			switch ( $cursor['stage'] ) {
				case 'export':
					self::stage_export( $plan, $cursor, $deadline );
					break;
				case 'deleted':
					self::stage_deleted( $plan, $cursor );
					break;
				case 'manifest':
					$error = self::stage_manifest( $plan, $cursor, $client, $deadline );
					break;
				case 'taken':
					$error = self::stage_taken( $plan, $cursor, $client, $deadline );
					break;
				case 'titles':
					$error = self::stage_titles( $plan, $cursor, $client, $deadline );
					break;
				case 'code':
					Code_Report::inventory( $cursor['pairing'], $client );
					$cursor['stage'] = 'classify';
					$cursor['after'] = 0;
					break;
				case 'classify':
					self::stage_classify( $plan, $cursor, $deadline );
					break;
				case 'closure':
					self::closure( $plan, $cursor );
					$cursor['stage'] = 'done';
					break;
			}
			if ( null !== $error ) {
				break;
			}
			if ( $before === $cursor['stage'] && Limits::over( $deadline ) ) {
				break;
			}
		}

		if ( null !== $row && $client instanceof Remote_Client ) {
			Source_Pairing::after_call( $row, $client, $error );
		}

		if ( null !== $error ) {
			Plan_Store::update(
				(int) $plan['id'],
				array(
					'cursor_json'  => $cursor,
					'error_code'   => $error->get_error_code(),
					'error_detail' => $error->get_error_message(),
				)
			);
			return $error;
		}

		$fields = array(
			'cursor_json' => $cursor,
			'error_code'  => '',
		);
		if ( 'done' === $cursor['stage'] ) {
			$fields['state']        = 'planned';
			$fields['finished_gmt'] = gmdate( 'Y-m-d H:i:s' );
		}
		Plan_Store::update( (int) $plan['id'], $fields );

		$plan = Plan_Store::find( (int) $plan['id'] );
		if ( 'done' === $cursor['stage'] ) {
			$summary = $plan['counts'];
			Audit_Log::write(
				'plan_requested',
				array(
					'pairing_id'  => (int) $plan['pairing_id'],
					'object_type' => 'plan',
					'object_ref'  => (string) $plan['id'],
					'details'     => array(
						'stage'     => 'done',
						'rows'      => isset( $summary['totals']['rows'] ) ? (int) $summary['totals']['rows'] : 0,
						'changes'   => isset( $summary['totals']['changes'] ) ? (int) $summary['totals']['changes'] : 0,
						'conflicts' => isset( $summary['undecided'] ) ? (int) $summary['undecided'] : 0,
					),
				)
			);
		}

		return self::present( $plan );
	}

	/**
	 * Runs steps until the plan is done (WP-CLI and test tools).
	 *
	 * @param string   $pairing_uuid Pairing UUID.
	 * @param string[] $groups       Scope groups.
	 * @param int      $user_id      User.
	 * @return array|WP_Error
	 */
	public static function run( $pairing_uuid, array $groups, $user_id = 0 ) {
		$plan = self::start( $pairing_uuid, $groups, $user_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		for ( $i = 0; $i < 1000 && 'planning' === $plan['state']; $i++ ) {
			$plan = self::step( (int) $plan['id'] );
			if ( is_wp_error( $plan ) ) {
				return $plan;
			}
		}
		return $plan;
	}

	/**
	 * A plan that can be read or stepped: exists, on this side, not older than 60 minutes.
	 *
	 * @param int $plan_id Plan ID.
	 * @return array|WP_Error
	 */
	public static function usable( $plan_id ) {
		$plan = Plan_Store::find( (int) $plan_id );
		if ( null === $plan ) {
			return new WP_Error( 'plan_not_found', __( 'This plan does not exist (a newer plan replaces older ones). Create a new plan.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
		}
		if ( Plan_Store::is_expired( $plan ) ) {
			return new WP_Error( 'plan_expired', __( 'This plan is more than 60 minutes old, so it may no longer match either site. Create a new plan.', 'keepmerge-push-staging-to-live' ), array( 'status' => 410 ) );
		}
		return $plan;
	}

	/**
	 * Stage 1: export every item in list order.
	 *
	 * @param array $plan     Plan.
	 * @param array $cursor   Cursor (changed).
	 * @param float $deadline Deadline.
	 * @return void
	 */
	private static function stage_export( array $plan, array &$cursor, $deadline ) {
		$pairing_id = (int) $plan['pairing_id'];
		$ctx        = Export_Context::source( $pairing_id );
		$states     = Sync_State::by_object( $pairing_id );
		$origins    = Normalizer::local_origins();
		$total      = count( $cursor['list'] );
		$blocks     = array_flip( $cursor['blocks'] );
		$themes     = array_flip( $cursor['themes'] );

		$batch = 0;
		while ( $cursor['pos'] < $total ) {
			if ( ++$batch > self::EXPORT_BATCH ) {
				$batch = 0;
				if ( Limits::over( $deadline ) ) {
					break;
				}
			}
			$position = (int) $cursor['pos'];
			$entry    = $cursor['list'][ $position ];
			++$cursor['pos'];

			$item = Items::export( (string) $entry[0], $entry[1], $ctx );
			if ( null === $item || '' === (string) $item['uuid'] ) {
				continue;
			}

			$state   = isset( $states[ $item['kind'] . ':' . $item['id'] ] ) ? $states[ $item['kind'] . ':' . $item['id'] ] : null;
			$payload = self::payload_from_item( $item, $state, $origins, $cursor['kinds'] );
			$deps    = array();
			foreach ( $item['refs'] as $ref ) {
				if ( isset( $ref['uuid'] ) && is_string( $ref['uuid'] ) && '' !== $ref['uuid'] && $ref['uuid'] !== $item['uuid'] ) {
					$deps[ $ref['uuid'] ] = true;
				}
			}

			Plan_Store::add_item( (int) $plan['id'], Plan_Store::seq( $payload['tab'], $position ), (string) $item['uuid'], (string) $item['kind'], (int) $item['id'], $payload, array_keys( $deps ) );

			foreach ( $item['block_types'] as $name ) {
				$blocks[ $name ] = true;
			}
			if ( ! empty( $item['stylesheet'] ) ) {
				$themes[ (string) $item['stylesheet'] ] = true;
			}
		}

		$cursor['blocks'] = array_slice( array_keys( $blocks ), 0, Manifest::MAX_BLOCK_TYPES );
		$cursor['themes'] = array_slice( array_keys( $themes ), 0, Manifest::MAX_STYLESHEETS );

		if ( $cursor['pos'] >= $total ) {
			$cursor['stage'] = 'deleted';
			// The list is not needed any more; keep its length for the progress bar.
			$cursor['total'] = $total;
			$cursor['list']  = array();
		}
	}

	/**
	 * Stage 2: items this site and live matched (strong) at the baseline, gone from this site
	 * since (deleted for good, or in the trash).
	 *
	 * @param array $plan   Plan.
	 * @param array $cursor Cursor (changed).
	 * @return void
	 */
	private static function stage_deleted( array $plan, array &$cursor ) {
		$position = isset( $cursor['total'] ) ? (int) $cursor['total'] : 0;

		foreach ( Sync_State::all( (int) $plan['pairing_id'] ) as $state ) {
			$kind = (string) $state['object_type'];
			if ( ! in_array( $state['match_confidence'], Sync_State::LINKED, true ) || ! in_array( $kind, Manifest::KINDS, true ) || in_array( $kind, array( 'option', 'widgets' ), true ) ) {
				continue;
			}
			if ( Plan_Store::has_item( (int) $plan['id'], (string) $state['uuid'] ) ) {
				continue;
			}

			$local = (int) $state['object_id'];
			$type  = '';
			$title = '';
			if ( 'term' === Scope::space( $kind ) ) {
				$term = get_term( $local );
				if ( $term instanceof \WP_Term ) {
					// The term still exists but is no longer an item (its taxonomy is skipped now).
					continue;
				}
			} else {
				$post = get_post( $local );
				if ( $post instanceof \WP_Post ) {
					if ( 'trash' !== $post->post_status ) {
						continue;
					}
					$type  = $post->post_type;
					$title = $post->post_title;
				}
			}

			$payload = array(
				'title'       => $title,
				'type'        => $type,
				'type_label'  => self::type_label( $kind, $type ),
				'local_id'    => $local,
				'remote_id'   => (int) $state['remote_object_id'],
				'confidence'  => (string) $state['match_confidence'],
				'exists_here' => false,
				'tab'         => Plan_Store::tab_for( $kind, $type ),
				'in_scope'    => in_array( $kind, $cursor['kinds'], true ),
			);
			Plan_Store::add_item( (int) $plan['id'], Plan_Store::seq( $payload['tab'], $position++ ), (string) $state['uuid'], $kind, $local, $payload, array() );
		}

		$cursor['stage'] = 'manifest';
		$cursor['after'] = 0;
	}

	/**
	 * Stage 3: live's side of every row.
	 *
	 * @param array $plan     Plan.
	 * @param array $cursor   Cursor (changed).
	 * @param mixed $client   Client.
	 * @param float $deadline Deadline.
	 * @return WP_Error|null
	 */
	private static function stage_manifest( array $plan, array &$cursor, $client, $deadline ) {
		// Adaptive batch: halved when live deferred entries for its own time budget or
		// memory guard, doubled again (up to the maximum) after an answer without any.
		$batch = isset( $cursor['mbatch'] ) ? max( self::MANIFEST_MIN_BATCH, min( self::MANIFEST_BATCH, (int) $cursor['mbatch'] ) ) : self::MANIFEST_BATCH;
		while ( ! Limits::over( $deadline ) ) {
			$rows = Plan_Store::items_with_status( (int) $plan['id'], 'pending', (int) $cursor['after'], $batch );
			if ( empty( $rows ) && $cursor['after'] > 0 ) {
				// Rows live deferred (its own time budget) are asked for again from the start.
				$cursor['after'] = 0;
				$rows            = Plan_Store::items_with_status( (int) $plan['id'], 'pending', 0, $batch );
			}
			if ( empty( $rows ) && $cursor['live_done'] ) {
				$cursor['stage'] = 'taken';
				$cursor['after'] = 0;
				return null;
			}

			$entries = array();
			foreach ( $rows as $row ) {
				$entries[] = self::manifest_entry( $row );
			}
			$body = array(
				'items'       => $entries,
				'live_cursor' => (int) $cursor['live_cursor'],
			);
			if ( ! $cursor['first_sent'] ) {
				$body['block_types'] = $cursor['blocks'];
				$body['stylesheets'] = $cursor['themes'];
			}

			$response = $client->post( self::MANIFEST_ROUTE, $body, array( 'budget' => max( 5, (int) floor( $deadline - microtime( true ) ) + 3 ) ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();

			if ( ! $cursor['first_sent'] ) {
				$cursor['first_sent']     = true;
				$cursor['missing_blocks'] = self::names( $data, 'missing_block_types' );
				$cursor['missing_themes'] = self::names( $data, 'missing_stylesheets' );
				$cursor['woo_live']       = isset( $data['woocommerce'] ) ? (bool) $data['woocommerce'] : null;
			}

			$answers = array();
			foreach ( isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array() as $answer ) {
				if ( is_array( $answer ) && isset( $answer['uuid'] ) && is_string( $answer['uuid'] ) ) {
					$answers[ $answer['uuid'] ] = $answer;
				}
			}

			$progress = false;
			$deferred = 0;
			foreach ( $rows as $row ) {
				$cursor['after'] = max( (int) $cursor['after'], (int) $row['id'] );
				$answer          = isset( $answers[ $row['uuid'] ] ) ? $answers[ $row['uuid'] ] : array( 'state' => 'missing' );
				$state           = isset( $answer['state'] ) && in_array( $answer['state'], array( 'found', 'missing', 'excluded', 'deferred' ), true ) ? $answer['state'] : 'missing';
				if ( 'deferred' === $state ) {
					++$deferred;
					continue;
				}
				$progress = true;
				$payload  = $row['payload'];

				$payload['live_state'] = $state;
				if ( 'found' === $state ) {
					$payload['l']             = self::hash_value( $answer, 'hash' );
					$payload['b']             = self::hash_value( $answer, 'base_hash' );
					$payload['l_groups']      = self::hash_map( $answer, 'groups' );
					$payload['b_groups']      = self::hash_map( $answer, 'base_groups' );
					$payload['live_id']       = isset( $answer['live_id'] ) && is_int( $answer['live_id'] ) ? $answer['live_id'] : 0;
					$payload['l_modified']    = isset( $answer['modified_gmt'] ) && is_string( $answer['modified_gmt'] ) && 1 === preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $answer['modified_gmt'] ) ? $answer['modified_gmt'] : '';
					$payload['l_unsupported'] = ! empty( $answer['unsupported'] );
				}
				Plan_Store::update_item(
					(int) $row['id'],
					array(
						'status'  => 'manifested',
						'payload' => $payload,
					)
				);
			}

			$batch            = $deferred > 0 ? max( self::MANIFEST_MIN_BATCH, (int) floor( $batch / 2 ) ) : min( self::MANIFEST_BATCH, $batch * 2 );
			$cursor['mbatch'] = $batch;

			$changes = isset( $data['live_changes'] ) && is_array( $data['live_changes'] ) ? $data['live_changes'] : array();
			foreach ( isset( $changes['items'] ) && is_array( $changes['items'] ) ? $changes['items'] : array() as $change ) {
				if ( is_array( $change ) && isset( $change['uuid'], $change['state'], $change['base_hash'] ) && 'deleted' === $change['state'] && is_string( $change['uuid'] ) && is_string( $change['base_hash'] ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $change['base_hash'] ) && count( $cursor['live_deleted'] ) < 20000 ) {
					$cursor['live_deleted'][ $change['uuid'] ] = $change['base_hash'];
				}
			}
			if ( array_key_exists( 'next_cursor', $changes ) && is_int( $changes['next_cursor'] ) && $changes['next_cursor'] > (int) $cursor['live_cursor'] ) {
				$cursor['live_cursor'] = $changes['next_cursor'];
			} else {
				$cursor['live_done'] = true;
			}

			if ( ! $progress && ! empty( $rows ) && $cursor['live_done'] ) {
				// Live deferred every row of this batch: hand back and let the next step ask again.
				return null;
			}
		}
		return null;
	}

	/**
	 * Stage 4: whether live already has another item with the slug of an item that is new here.
	 *
	 * @param array $plan     Plan.
	 * @param array $cursor   Cursor (changed).
	 * @param mixed $client   Client.
	 * @param float $deadline Deadline.
	 * @return WP_Error|null
	 */
	private static function stage_taken( array $plan, array &$cursor, $client, $deadline ) {
		$fingerprints = null;

		while ( ! Limits::over( $deadline ) ) {
			$rows = Plan_Store::items_with_status( (int) $plan['id'], 'manifested', (int) $cursor['after'], self::MANIFEST_BATCH );
			if ( empty( $rows ) ) {
				$cursor['stage'] = 'titles';
				$cursor['after'] = 0;
				return null;
			}

			$need = array();
			foreach ( $rows as $row ) {
				$cursor['after'] = max( (int) $cursor['after'], (int) $row['id'] );
				$p               = $row['payload'];
				if ( empty( $p['exists_here'] ) || 'missing' !== ( isset( $p['live_state'] ) ? $p['live_state'] : '' ) || in_array( isset( $p['confidence'] ) ? $p['confidence'] : '', array( 'strong', 'pushed', 'medium', 'duplicate' ), true ) || isset( $cursor['live_deleted'][ $row['uuid'] ] ) ) {
					continue;
				}
				if ( null === $fingerprints ) {
					$fingerprints = array();
					foreach ( Items::fingerprints( Normalizer::for_source() ) as $fingerprint ) {
						$fingerprints[ $fingerprint['k'] . ':' . $fingerprint['id'] ] = $fingerprint;
					}
				}
				$key = $row['object_type'] . ':' . (int) $row['local_object_id'];
				if ( isset( $fingerprints[ $key ] ) ) {
					$need[] = array(
						'row'         => $row,
						'fingerprint' => $fingerprints[ $key ],
					);
				}
			}
			if ( empty( $need ) ) {
				continue;
			}

			$response = $client->post(
				self::BASELINE_ROUTE,
				array( 'fingerprints' => array_column( $need, 'fingerprint' ) ),
				array( 'budget' => max( 5, (int) floor( $deadline - microtime( true ) ) + 3 ) )
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$results = isset( $response['data']['results'] ) && is_array( $response['data']['results'] ) ? $response['data']['results'] : array();
			foreach ( $results as $result ) {
				if ( ! is_array( $result ) || ! isset( $result['i'] ) || ! is_int( $result['i'] ) || ! isset( $need[ $result['i'] ] ) ) {
					continue;
				}
				$taken = ( isset( $result['m'] ) && in_array( $result['m'], array( 'strong', 'medium' ), true ) ) || ( isset( $result['taken'] ) && is_array( $result['taken'] ) && ! empty( $result['taken'] ) );
				if ( $taken ) {
					$row                   = $need[ $result['i'] ]['row'];
					$payload               = $row['payload'];
					$payload['slug_taken'] = true;
					Plan_Store::update_item( (int) $row['id'], array( 'payload' => $payload ) );
				}
			}
		}
		return null;
	}

	/**
	 * Stage 5: names of items deleted on this site, from live's copy (read with
	 * POST /remote/items/fetch, at most 50 per call), so the plan can say what it would keep.
	 *
	 * @param array $plan     Plan.
	 * @param array $cursor   Cursor (changed).
	 * @param mixed $client   Client.
	 * @param float $deadline Deadline.
	 * @return WP_Error|null
	 */
	private static function stage_titles( array $plan, array &$cursor, $client, $deadline ) {
		while ( ! Limits::over( $deadline ) ) {
			$rows = Plan_Store::items_with_status( (int) $plan['id'], 'manifested', (int) $cursor['after'], self::MANIFEST_BATCH );
			if ( empty( $rows ) ) {
				$cursor['stage'] = 'code';
				$cursor['after'] = 0;
				return null;
			}
			$need = array();
			foreach ( $rows as $row ) {
				if ( count( $need ) >= Manifest::MAX_FETCH_ITEMS ) {
					break;
				}
				$cursor['after'] = max( (int) $cursor['after'], (int) $row['id'] );
				$p               = $row['payload'];
				if ( empty( $p['exists_here'] ) && isset( $p['live_state'] ) && 'found' === $p['live_state'] && '' === (string) $p['title'] ) {
					$need[ $row['uuid'] ] = $row;
				}
			}
			if ( empty( $need ) ) {
				continue;
			}

			$entries = array();
			foreach ( $need as $row ) {
				$entries[] = self::manifest_entry( $row );
			}
			$response = $client->post( Plan_Diff::FETCH_ROUTE, array( 'items' => $entries ), array( 'depth' => 64 ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			foreach ( isset( $response['data']['items'] ) && is_array( $response['data']['items'] ) ? $response['data']['items'] : array() as $answer ) {
				if ( ! is_array( $answer ) || ! isset( $answer['uuid'], $need[ $answer['uuid'] ] ) || 'found' !== ( isset( $answer['state'] ) ? $answer['state'] : '' ) ) {
					continue;
				}
				$row  = $need[ $answer['uuid'] ];
				$data = isset( $answer['data'] ) && is_array( $answer['data'] ) ? $answer['data'] : array();
				$name = '';
				foreach ( array( 'post_title', 'name' ) as $field ) {
					if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) && '' !== $data[ $field ] ) {
						$name = $data[ $field ];
						break;
					}
				}
				if ( '' === $name && isset( $data['groups']['content']['name'] ) && is_string( $data['groups']['content']['name'] ) ) {
					$name = $data['groups']['content']['name'];
				}
				$payload          = $row['payload'];
				$payload['title'] = sanitize_text_field( $name );
				if ( '' === (string) $payload['type'] && isset( $answer['type'] ) && is_string( $answer['type'] ) && 1 === preg_match( '/\A[a-z0-9_-]{1,32}\z/', $answer['type'] ) ) {
					$payload['type']       = $answer['type'];
					$payload['type_label'] = self::type_label( (string) $row['object_type'], $answer['type'] );
				}
				Plan_Store::update_item( (int) $row['id'], array( 'payload' => $payload ) );
			}
		}
		return null;
	}

	/**
	 * Stage 6: classification of every row.
	 *
	 * @param array $plan     Plan.
	 * @param array $cursor   Cursor (changed).
	 * @param float $deadline Deadline.
	 * @return void
	 */
	private static function stage_classify( array $plan, array &$cursor, $deadline ) {
		$source_woo = Guard::active();

		while ( ! Limits::over( $deadline ) ) {
			$rows = Plan_Store::items_with_status( (int) $plan['id'], 'manifested', 0, self::EXPORT_BATCH );
			if ( empty( $rows ) ) {
				$cursor['stage'] = 'closure';
				return;
			}
			foreach ( $rows as $row ) {
				$p    = $row['payload'];
				$kind = (string) $row['object_type'];
				$base = isset( $p['b'] ) ? (string) $p['b'] : '';
				if ( '' === $base && isset( $cursor['live_deleted'][ $row['uuid'] ] ) ) {
					$base = (string) $cursor['live_deleted'][ $row['uuid'] ];
				}

				$result = Classifier::classify(
					array(
						'kind'        => $kind,
						'exists_here' => ! empty( $p['exists_here'] ),
						'confidence'  => isset( $p['confidence'] ) ? (string) $p['confidence'] : '',
						'live_state'  => isset( $p['live_state'] ) ? (string) $p['live_state'] : 'missing',
						's'           => isset( $p['s'] ) ? (string) $p['s'] : '',
						'l'           => isset( $p['l'] ) ? (string) $p['l'] : '',
						'b'           => $base,
						's_groups'    => isset( $p['s_groups'] ) ? $p['s_groups'] : array(),
						'l_groups'    => isset( $p['l_groups'] ) ? $p['l_groups'] : array(),
						'b_groups'    => isset( $p['b_groups'] ) ? $p['b_groups'] : array(),
						'unsupported' => ! empty( $p['unsupported'] ),
						's_modified'  => isset( $p['s_modified'] ) ? (string) $p['s_modified'] : '',
						'l_modified'  => isset( $p['l_modified'] ) ? (string) $p['l_modified'] : '',
						'clone_gmt'   => $cursor['clone_gmt'],
						'slug_taken'  => ! empty( $p['slug_taken'] ),
					)
				);

				// Templates, parts and global styles of a theme live lacks are skipped, whatever their class.
				$stylesheet = isset( $p['stylesheet'] ) ? (string) $p['stylesheet'] : '';
				if ( '' !== $stylesheet && in_array( $stylesheet, $cursor['missing_themes'], true ) && ( in_array( $result['action'], Classifier::SENT, true ) || '' === $result['action'] ) ) {
					$result['class']    = Classifier::EXCLUDED;
					$result['action']   = 'skip';
					$result['choices']  = array();
					$result['badges'][] = 'theme_missing';
				}
				if ( 'product' === $kind && Classifier::EXCLUDED === $result['class'] && $source_woo && false === $cursor['woo_live'] ) {
					$result['badges'][] = 'woo_not_on_live';
				}

				$p['b']       = $base;
				$p['choices'] = $result['choices'];
				$p['badges']  = array_values( array_unique( $result['badges'] ) );
				$p['groups']  = $result['groups'];
				$in_scope     = ! empty( $p['in_scope'] );

				Plan_Store::update_item(
					(int) $row['id'],
					array(
						'status'  => $result['class'],
						'action'  => $in_scope ? $result['action'] : 'out_of_scope',
						'payload' => $p,
					)
				);
			}
		}
	}

	/**
	 * Stage 7 (and after every decision): dependencies, warnings and the summary.
	 *
	 * @param array      $plan   Plan.
	 * @param array|null $cursor Cursor (null: read from the plan).
	 * @return array The summary.
	 */
	public static function closure( array $plan, $cursor = null ) {
		$cursor = is_array( $cursor ) ? $cursor : $plan['cursor_json'];
		$rows   = Plan_Store::all_items( (int) $plan['id'] );
		$index  = array();
		$before = array();
		foreach ( $rows as $i => $row ) {
			$index[ $row['uuid'] ] = $i;
			$before[ $i ]          = $row['payload'];
		}

		$known = array();
		foreach ( Sync_State::all( (int) $plan['pairing_id'] ) as $state ) {
			if ( in_array( $state['match_confidence'], Sync_State::LINKED, true ) ) {
				$known[ $state['uuid'] ] = true;
			}
		}

		// Closure flags are recomputed from scratch.
		foreach ( $rows as $i => $row ) {
			$rows[ $i ]['payload']['pulled_in_by'] = array();
			$rows[ $i ]['payload']['blocked_by']   = array();
			$rows[ $i ]['payload']['dep_missing']  = 0;
			$rows[ $i ]['pull']                    = false;
		}

		// Pull in new dependencies of sent items, until nothing changes (a pulled-in item can
		// have new dependencies of its own).
		$changed = true;
		$rounds  = 0;
		while ( $changed && $rounds++ < 20 ) {
			$changed = false;
			foreach ( $rows as $i => $row ) {
				if ( ! self::sends( $rows[ $i ] ) ) {
					continue;
				}
				foreach ( is_array( $row['refs'] ) ? $row['refs'] : array() as $dep ) {
					if ( ! isset( $index[ $dep ] ) ) {
						continue;
					}
					$j = $index[ $dep ];
					if ( 'out_of_scope' === $rows[ $j ]['action'] && Classifier::NEW_STAGING === $rows[ $j ]['status'] && '' === $rows[ $j ]['decision'] && ! in_array( 'slug_taken', (array) $rows[ $j ]['payload']['badges'], true ) && ! $rows[ $j ]['pull'] ) {
						$rows[ $j ]['pull'] = true;
						$changed            = true;
					}
				}
			}
		}

		// Blocked dependents and pulled-in markers.
		foreach ( $rows as $i => $row ) {
			if ( ! self::sends( $row ) ) {
				continue;
			}
			foreach ( is_array( $row['refs'] ) ? $row['refs'] : array() as $dep ) {
				if ( ! isset( $index[ $dep ] ) ) {
					if ( ! isset( $known[ $dep ] ) ) {
						++$rows[ $i ]['payload']['dep_missing'];
					}
					continue;
				}
				$j     = $index[ $dep ];
				$other = $rows[ $j ];
				if ( $other['pull'] ) {
					$rows[ $j ]['payload']['pulled_in_by'][] = $row['uuid'];
					continue;
				}
				if ( self::undecided( $other ) || self::absent_on_live( $other ) ) {
					$rows[ $i ]['payload']['blocked_by'][] = $other['uuid'];
				}
			}
		}

		$summary = self::summarise( $plan, $cursor, $rows );

		foreach ( $rows as $i => $row ) {
			$payload             = $row['payload'];
			$payload['warnings'] = self::warnings( $row, $cursor );
			if ( $row['pull'] ) {
				$payload['pulled_in'] = true;
			} else {
				unset( $payload['pulled_in'] );
			}
			$payload['has_warnings'] = ! empty( $payload['warnings'] );
			$payload['pulled_in_by'] = array_slice( array_values( array_unique( $payload['pulled_in_by'] ) ), 0, 20 );
			$payload['blocked_by']   = array_slice( array_values( array_unique( $payload['blocked_by'] ) ), 0, 20 );
			if ( $before[ $i ] !== $payload ) {
				Plan_Store::update_item( (int) $row['id'], array( 'payload' => $payload ) );
			}
		}

		Plan_Store::update(
			(int) $plan['id'],
			array(
				'counts'    => $summary,
				'plan_hash' => $summary['plan_hash'],
			)
		);

		return $summary;
	}

	/**
	 * Applies the admin's decisions and the bulk "Keep live for all remaining".
	 *
	 * @param int    $plan_id   Plan ID.
	 * @param array  $decisions [ { uuid, action, group? } ].
	 * @param string $bulk      '' or 'keep_live_remaining'.
	 * @return array|WP_Error The plan as presented.
	 */
	public static function decide( $plan_id, array $decisions, $bulk ) {
		$plan = self::usable( $plan_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		if ( 'planned' !== $plan['state'] ) {
			return new WP_Error( 'plan_not_ready', __( 'The plan is not finished yet.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		$pending = array();
		foreach ( $decisions as $decision ) {
			$row = Plan_Store::item( (int) $plan['id'], (string) $decision['uuid'] );
			if ( null === $row ) {
				return new WP_Error( 'item_not_found', __( 'An item of this decision is not in the plan.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
			}
			$error = self::apply_decision( $row, (string) $decision['action'], isset( $decision['group'] ) ? (string) $decision['group'] : '' );
			if ( is_wp_error( $error ) ) {
				return $error;
			}
			$pending[ $row['uuid'] ] = $error;
		}

		foreach ( $pending as $row ) {
			Plan_Store::update_item(
				(int) $row['id'],
				array(
					'decision' => $row['decision'],
					'payload'  => $row['payload'],
				)
			);
		}

		if ( 'keep_live_remaining' === $bulk ) {
			foreach ( Plan_Store::all_items( (int) $plan['id'] ) as $row ) {
				if ( ! self::undecided( $row ) ) {
					continue;
				}
				if ( 'product' === $row['object_type'] && ! empty( $row['payload']['groups'] ) ) {
					foreach ( $row['payload']['groups'] as $name => $group ) {
						if ( Classifier::CONFLICT === $group['class'] && empty( $group['decision'] ) ) {
							$row = self::apply_decision( $row, 'keep_live', $name );
						}
					}
				} else {
					$row = self::apply_decision( $row, Classifier::DELETED_LIVE_CHANGED === $row['status'] ? 'leave_deleted' : 'keep_live', '' );
				}
				if ( ! is_wp_error( $row ) ) {
					Plan_Store::update_item(
						(int) $row['id'],
						array(
							'decision' => $row['decision'],
							'payload'  => $row['payload'],
						)
					);
				}
			}
		}

		self::closure( Plan_Store::find( (int) $plan['id'] ) );

		return self::present( Plan_Store::find( (int) $plan['id'] ) );
	}

	/**
	 * Sets one decision on a row (not saved).
	 *
	 * @param array  $row    Row.
	 * @param string $action Action.
	 * @param string $group  Product group ('' for the item).
	 * @return array|WP_Error The row.
	 */
	private static function apply_decision( array $row, $action, $group ) {
		$refused = new WP_Error( 'decision_refused', __( 'That choice is not available for this item.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		if ( 'out_of_scope' === $row['action'] && empty( $row['payload']['pulled_in'] ) ) {
			return $refused;
		}

		if ( '' !== $group ) {
			$groups = isset( $row['payload']['groups'] ) && is_array( $row['payload']['groups'] ) ? $row['payload']['groups'] : array();
			if ( 'product' !== $row['object_type'] || ! isset( $groups[ $group ] ) || ! in_array( $action, (array) $groups[ $group ]['choices'], true ) ) {
				return $refused;
			}
			$groups[ $group ]['decision'] = $action === $groups[ $group ]['action'] ? '' : $action;
			$row['payload']['groups']     = $groups;

			$open = false;
			foreach ( Classifier::GROUPS as $name ) {
				if ( isset( $groups[ $name ] ) && Classifier::CONFLICT === $groups[ $name ]['class'] && empty( $groups[ $name ]['decision'] ) ) {
					$open = true;
				}
			}
			$row['decision'] = Classifier::CONFLICT === $row['status'] && ! $open ? 'groups' : ( Classifier::CONFLICT === $row['status'] ? '' : $row['decision'] );
			return $row;
		}

		$choices = isset( $row['payload']['choices'] ) && is_array( $row['payload']['choices'] ) ? $row['payload']['choices'] : array();
		if ( ! in_array( $action, $choices, true ) ) {
			return $refused;
		}
		$row['decision'] = $action === $row['action'] ? '' : $action;
		return $row;
	}

	/**
	 * The effective action of a row: the decision, else the default.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public static function effective( array $row ) {
		if ( ! empty( $row['pull'] ) || ! empty( $row['payload']['pulled_in'] ) ) {
			return 'create';
		}
		if ( 'groups' === $row['decision'] ) {
			return self::group_summary_action( $row );
		}
		if ( '' !== $row['decision'] ) {
			return (string) $row['decision'];
		}
		if ( 'product' === $row['object_type'] && ! empty( $row['payload']['groups'] ) && 'out_of_scope' !== $row['action'] ) {
			return self::group_summary_action( $row );
		}
		return (string) $row['action'];
	}

	/**
	 * Whether a row sends content to live with its current decisions.
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	public static function sends( array $row ) {
		return in_array( self::effective( $row ), Classifier::SENT, true );
	}

	/**
	 * The plan action with which a row is part of a push, or '' when the push leaves it alone:
	 * content sent (create, push, use_staging, recreate), live's copy trashed (trash_on_live), or
	 * the item recorded as in sync (record_base). These rows, and only these, travel to live.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public static function push_action( array $row ) {
		$effective = self::effective( $row );
		if ( in_array( $effective, Classifier::SENT, true ) || in_array( $effective, array( 'trash_on_live', 'record_base' ), true ) ) {
			return $effective;
		}
		return '';
	}

	/**
	 * The plan hash: SHA-256 of the sorted lines "uuid:plan action:staging hash" of every row a
	 * push carries. Live computes the same over what it received (Transfer\Push_Session).
	 *
	 * @param string[] $lines Lines.
	 * @return string
	 */
	public static function plan_hash( array $lines ) {
		sort( $lines, SORT_STRING );
		return hash( 'sha256', implode( "\n", $lines ) );
	}

	/**
	 * Whether a row still needs a decision (in scope, or pulled in).
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	public static function undecided( array $row ) {
		if ( 'out_of_scope' === $row['action'] && empty( $row['pull'] ) && empty( $row['payload']['pulled_in'] ) ) {
			return false;
		}
		if ( Classifier::CONFLICT !== $row['status'] && Classifier::DELETED_LIVE_CHANGED !== $row['status'] ) {
			return false;
		}
		return '' === $row['decision'];
	}

	/**
	 * Whether a dependency will not exist on live after this push.
	 *
	 * @param array $row Dependency row.
	 * @return bool
	 */
	private static function absent_on_live( array $row ) {
		$live = isset( $row['payload']['live_state'] ) ? $row['payload']['live_state'] : '';
		if ( 'found' === $live || ! empty( $row['pull'] ) ) {
			return false;
		}
		return ! self::sends( $row );
	}

	/**
	 * The item-level action of a product from its groups and their decisions.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private static function group_summary_action( array $row ) {
		$actions = array();
		foreach ( Classifier::GROUPS as $name ) {
			if ( ! isset( $row['payload']['groups'][ $name ] ) ) {
				continue;
			}
			$group     = $row['payload']['groups'][ $name ];
			$actions[] = ! empty( $group['decision'] ) ? $group['decision'] : (string) $group['action'];
		}
		if ( in_array( '', $actions, true ) ) {
			return '';
		}
		if ( in_array( 'push', $actions, true ) || in_array( 'use_staging', $actions, true ) ) {
			return 'push';
		}
		if ( in_array( 'keep_live', $actions, true ) ) {
			return 'keep_live';
		}
		if ( in_array( 'record_base', $actions, true ) ) {
			return 'record_base';
		}
		return 'none';
	}

	/**
	 * Warnings of a row (codes the screen explains).
	 *
	 * @param array $row    Row.
	 * @param array $cursor Cursor.
	 * @return array[] [ { code, detail } ].
	 */
	private static function warnings( array $row, array $cursor ) {
		$p      = $row['payload'];
		$badges = isset( $p['badges'] ) ? (array) $p['badges'] : array();
		$sends  = self::sends( $row );
		$out    = array();

		if ( Classifier::UNSUPPORTED === $row['status'] ) {
			$out[] = array(
				'code'   => 'unsupported',
				'detail' => implode( ', ', array_slice( isset( $p['problems'] ) ? (array) $p['problems'] : array(), 0, 3 ) ),
			);
		}
		if ( in_array( 'theme_missing', $badges, true ) ) {
			$out[] = array(
				'code'   => 'theme_missing',
				'detail' => isset( $p['stylesheet'] ) ? (string) $p['stylesheet'] : '',
			);
		} elseif ( in_array( 'woo_not_on_live', $badges, true ) ) {
			$out[] = array(
				'code'   => 'woo_not_on_live',
				'detail' => '',
			);
		} elseif ( Classifier::EXCLUDED === $row['status'] ) {
			$out[] = array(
				'code'   => 'excluded_on_live',
				'detail' => '',
			);
		}
		if ( 'out_of_scope' === $row['action'] && empty( $row['pull'] ) ) {
			return $out;
		}

		$missing = array_values( array_intersect( isset( $p['block_types'] ) ? (array) $p['block_types'] : array(), $cursor['missing_blocks'] ) );
		if ( ! empty( $missing ) && ( $sends || Classifier::CONFLICT === $row['status'] ) ) {
			$out[] = array(
				'code'   => 'missing_blocks',
				'detail' => implode( ', ', array_slice( $missing, 0, 5 ) ),
			);
		}
		if ( ! empty( $p['gate'] ) && ( $sends || Classifier::CONFLICT === $row['status'] ) ) {
			$first = $p['gate'][0];
			$out[] = array(
				'code'   => 'held_for_review',
				'detail' => $first['field'] . ': ' . $first['rule'],
			);
		}
		if ( ! empty( $p['unmapped'] ) && ( $sends || Classifier::CONFLICT === $row['status'] ) ) {
			$out[] = array(
				'code'   => 'unmapped_refs',
				'detail' => (string) (int) $p['unmapped'],
			);
		}
		if ( in_array( 'slug_taken', $badges, true ) && Classifier::NEW_STAGING === $row['status'] ) {
			$out[] = array(
				'code'   => 'slug_taken',
				'detail' => '',
			);
		}
		if ( ! empty( $p['blocked_by'] ) && $sends ) {
			$out[] = array(
				'code'   => 'blocked',
				'detail' => (string) count( array_unique( $p['blocked_by'] ) ),
			);
		}
		if ( ! empty( $p['dep_missing'] ) && $sends ) {
			$out[] = array(
				'code'   => 'dependency_missing',
				'detail' => (string) (int) $p['dep_missing'],
			);
		}
		if ( 'product' === $row['object_type'] && in_array( 'stock_changed_on_live', $badges, true ) ) {
			$out[] = array(
				'code'   => 'stock_changed_on_live',
				'detail' => '',
			);
		}

		return $out;
	}

	/**
	 * The plan summary: counts per class, tab and kind, what a push would do, warnings, authors.
	 *
	 * @param array   $plan   Plan.
	 * @param array   $cursor Cursor.
	 * @param array[] $rows   Rows (with closure flags).
	 * @return array
	 */
	private static function summarise( array $plan, array $cursor, array $rows ) {
		$classes  = array_fill_keys( Classifier::CLASSES, 0 );
		$by_tab   = array();
		$dialog   = array_fill_keys( array( 'create', 'update', 'trash', 'recreate', 'record_base', 'keep_live', 'skip', 'held', 'by_dates', 'products_split', 'stock' ), 0 );
		$warnings = array();
		$authors  = array();
		$undecid  = 0;
		$blocked  = 0;
		$sent     = 0;
		$in_scope = 0;
		$hash     = array();

		foreach ( Plan_Store::TABS as $tab => $band ) {
			$by_tab[ $tab ] = array(
				'total'     => 0,
				'changes'   => 0,
				'conflicts' => 0,
			);
		}

		foreach ( $rows as $row ) {
			$p     = $row['payload'];
			$scope = 'out_of_scope' !== $row['action'] || ! empty( $row['pull'] );
			if ( ! $scope ) {
				continue;
			}
			++$in_scope;
			$class = (string) $row['status'];
			if ( isset( $classes[ $class ] ) ) {
				++$classes[ $class ];
			}
			$tab = isset( $p['tab'], $by_tab[ $p['tab'] ] ) ? $p['tab'] : 'content';
			++$by_tab[ $tab ]['total'];
			if ( Classifier::UNCHANGED !== $class ) {
				++$by_tab[ $tab ]['changes'];
			}

			$effective = self::effective( $row );
			if ( self::undecided( $row ) || ( 'product' === $row['object_type'] && Classifier::CONFLICT === $class && '' === $row['decision'] ) ) {
				++$undecid;
				++$by_tab[ $tab ]['conflicts'];
			}
			if ( ! empty( $p['blocked_by'] ) && in_array( $effective, Classifier::SENT, true ) ) {
				++$blocked;
			}

			switch ( $effective ) {
				case 'create':
					++$dialog['create'];
					break;
				case 'push':
				case 'use_staging':
					++$dialog['update'];
					break;
				case 'recreate':
					++$dialog['recreate'];
					break;
				case 'trash_on_live':
					++$dialog['trash'];
					break;
				case 'record_base':
					++$dialog['record_base'];
					break;
				case 'keep_live':
				case 'keep_on_live':
				case 'leave_deleted':
					++$dialog['keep_live'];
					break;
				case 'skip':
					++$dialog['skip'];
					break;
			}

			$sends = in_array( $effective, Classifier::SENT, true );
			if ( $sends || 'trash_on_live' === $effective ) {
				++$sent;
			}
			$push_action = self::push_action( $row );
			if ( '' !== $push_action ) {
				$hash[] = $row['uuid'] . ':' . $push_action . ':' . ( isset( $p['s'] ) ? $p['s'] : '' );
			}
			if ( $sends && ! empty( $p['gate'] ) ) {
				++$dialog['held'];
			}
			if ( in_array( 'by_dates', isset( $p['badges'] ) ? (array) $p['badges'] : array(), true ) && '' === $row['decision'] ) {
				++$dialog['by_dates'];
			}
			if ( 'product' === $row['object_type'] && ! empty( $p['groups'] ) ) {
				$kinds = array();
				foreach ( Classifier::GROUPS as $name ) {
					if ( isset( $p['groups'][ $name ] ) ) {
						$g       = $p['groups'][ $name ];
						$kinds[] = ! empty( $g['decision'] ) ? $g['decision'] : (string) $g['action'];
					}
				}
				if ( ( in_array( 'push', $kinds, true ) || in_array( 'use_staging', $kinds, true ) ) && in_array( 'keep_live', $kinds, true ) ) {
					++$dialog['products_split'];
				}
				if ( isset( $p['groups']['live_owned']['decision'] ) && 'push_stock' === $p['groups']['live_owned']['decision'] ) {
					++$dialog['stock'];
				}
			}

			if ( $sends && isset( $p['author']['login'] ) && '' !== $p['author']['login'] ) {
				$login = (string) $p['author']['login'];
				if ( ! isset( $authors[ $login ] ) ) {
					$authors[ $login ] = array(
						'login' => $login,
						'email' => isset( $p['author']['email'] ) ? (string) $p['author']['email'] : '',
						'name'  => isset( $p['author']['name'] ) ? (string) $p['author']['name'] : '',
						'items' => 0,
					);
				}
				++$authors[ $login ]['items'];
			}

			foreach ( self::warnings( $row, $cursor ) as $warning ) {
				$code = $warning['code'];
				if ( ! isset( $warnings[ $code ] ) ) {
					$warnings[ $code ] = array(
						'count'   => 0,
						'samples' => array(),
					);
				}
				++$warnings[ $code ]['count'];
				if ( count( $warnings[ $code ]['samples'] ) < self::WARNING_SAMPLES ) {
					$warnings[ $code ]['samples'][] = array(
						'uuid'   => $row['uuid'],
						'title'  => isset( $p['title'] ) ? (string) $p['title'] : '',
						'detail' => $warning['detail'],
					);
				}
			}
		}

		$missing_blocks = array();
		foreach ( $cursor['missing_blocks'] as $name ) {
			$missing_blocks[] = array(
				'name'  => $name,
				'items' => 0,
			);
		}
		foreach ( $rows as $row ) {
			foreach ( $missing_blocks as $i => $block ) {
				if ( in_array( $block['name'], isset( $row['payload']['block_types'] ) ? (array) $row['payload']['block_types'] : array(), true ) ) {
					++$missing_blocks[ $i ]['items'];
				}
			}
		}

		$code = Code_Report::summary( (string) $cursor['pairing'] );

		return array(
			'totals'         => array(
				'rows'     => count( $rows ),
				'in_scope' => $in_scope,
				'changes'  => $in_scope - $classes[ Classifier::UNCHANGED ],
			),
			'by_class'       => $classes,
			'by_tab'         => $by_tab,
			'undecided'      => $undecid,
			'blocked'        => $blocked,
			'sent'           => $sent,
			'push_total'     => count( $hash ),
			'ready'          => 0 === $undecid && 0 === $blocked && $sent > 0,
			'dialog'         => $dialog,
			'warnings'       => $warnings,
			'missing_themes' => array_values( $cursor['missing_themes'] ),
			'missing_blocks' => $missing_blocks,
			'woocommerce'    => array(
				'staging' => Guard::active(),
				'live'    => null === $cursor['woo_live'] ? null : (bool) $cursor['woo_live'],
			),
			'authors'        => array_values( $authors ),
			'clone'          => array(
				'date'    => $cursor['clone_date'],
				'unknown' => null === $cursor['clone_gmt'],
			),
			'scope'          => $cursor['scope'],
			'code'           => $code,
			'plan_hash'      => self::plan_hash( $hash ),
		);
	}

	/**
	 * The payload of a freshly exported item.
	 *
	 * @param array      $item    Exported item.
	 * @param array|null $state   This site's sync_state row for it.
	 * @param array      $origins This site's origins.
	 * @param string[]   $kinds   Kinds in the plan's scope.
	 * @return array
	 */
	private static function payload_from_item( array $item, $state, array $origins, array $kinds ) {
		$kind = (string) $item['kind'];
		$type = (string) $item['type'];

		$gate    = array();
		$decoded = Normalizer::decode( $item['data'], $origins );
		if ( ! is_wp_error( $decoded ) && is_array( $decoded ) ) {
			$gate = array_slice( Markup_Gate::check( $kind, $decoded ), 0, self::MAX_REASONS );
		}

		$author = array();
		if ( isset( $item['data']['author'] ) && is_array( $item['data']['author'] ) && isset( $item['data']['author']['login'] ) ) {
			$author = array(
				'login' => (string) $item['data']['author']['login'],
				'email' => isset( $item['data']['author']['email'] ) ? (string) $item['data']['author']['email'] : '',
			);
			$user   = get_user_by( 'login', $author['login'] );
			if ( $user ) {
				$author['name'] = (string) $user->display_name;
			}
		}

		return array(
			'title'       => (string) $item['title'],
			'type'        => $type,
			'type_label'  => self::type_label( $kind, $type ),
			'local_id'    => (int) $item['id'],
			'remote_id'   => is_array( $state ) ? (int) $state['remote_object_id'] : 0,
			'confidence'  => is_array( $state ) ? (string) $state['match_confidence'] : '',
			'exists_here' => true,
			's'           => (string) $item['hash'],
			's_groups'    => $item['groups'],
			's_modified'  => (string) $item['modified_gmt'],
			'unsupported' => (bool) $item['unsupported'],
			'problems'    => array_slice( (array) $item['problems'], 0, self::MAX_REASONS ),
			'unmapped'    => count( $item['unmapped'] ),
			'block_types' => array_slice( (array) $item['block_types'], 0, 50 ),
			'stylesheet'  => isset( $item['stylesheet'] ) ? (string) $item['stylesheet'] : '',
			'author'      => $author,
			'gate'        => $gate,
			'tab'         => Plan_Store::tab_for( $kind, $type ),
			'in_scope'    => in_array( $kind, $kinds, true ),
		);
	}

	/**
	 * The manifest entry of a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function manifest_entry( array $row ) {
		$kind = (string) $row['object_type'];
		$p    = $row['payload'];
		if ( 'option' === $kind || 'widgets' === $kind ) {
			return array(
				'uuid' => (string) $row['uuid'],
				'kind' => $kind,
			);
		}
		$hint = isset( $p['confidence'] ) && in_array( $p['confidence'], array( 'strong', 'medium', 'pushed' ), true ) && isset( $p['remote_id'] ) ? (int) $p['remote_id'] : 0;
		return array(
			'uuid'    => (string) $row['uuid'],
			'kind'    => $kind,
			'type'    => isset( $p['type'] ) ? (string) $p['type'] : '',
			'live_id' => $hint,
		);
	}

	/**
	 * A plan as the screen and the CLI show it.
	 *
	 * @param array|null $plan Plan row.
	 * @return array
	 */
	public static function present( $plan ) {
		if ( ! is_array( $plan ) ) {
			return array();
		}
		$cursor  = $plan['cursor_json'];
		$stage   = isset( $cursor['stage'] ) ? (string) $cursor['stage'] : 'done';
		$total   = isset( $cursor['total'] ) ? (int) $cursor['total'] : count( isset( $cursor['list'] ) ? $cursor['list'] : array() );
		$started = strtotime( (string) $plan['started_gmt'] . ' UTC' );

		return array(
			'id'         => (int) $plan['id'],
			'pairing'    => isset( $cursor['pairing'] ) ? (string) $cursor['pairing'] : '',
			'state'      => (string) $plan['state'],
			'stage'      => $stage,
			'steps'      => isset( $cursor['steps'] ) ? (int) $cursor['steps'] : 0,
			'progress'   => array(
				'stage_index' => (int) array_search( $stage, self::STAGES, true ),
				'stages'      => count( self::STAGES ),
				'done'        => 'export' === $stage ? (int) $cursor['pos'] : $total,
				'total'       => $total,
			),
			'started_at' => false === $started ? null : $started,
			'expires_at' => false === $started ? null : $started + Plan_Store::TTL,
			'expired'    => Plan_Store::is_expired( $plan ),
			'scope'      => isset( $cursor['scope'] ) ? $cursor['scope'] : array(),
			'summary'    => 'planned' === $plan['state'] ? $plan['counts'] : null,
			'error'      => '' === (string) $plan['error_code'] ? null : array(
				'code'    => (string) $plan['error_code'],
				'message' => (string) $plan['error_detail'],
			),
		);
	}

	/**
	 * One row as the screen shows it.
	 *
	 * @param array $row        Row.
	 * @param array $titles     UUID => title of the plan's rows (for dependency names).
	 * @param array $pairing    Pairing row (for live links).
	 * @return array
	 */
	public static function present_row( array $row, array $titles, array $pairing ) {
		$p       = $row['payload'];
		$kind    = (string) $row['object_type'];
		$local   = (int) $row['local_object_id'];
		$live_id = isset( $p['live_id'] ) ? (int) $p['live_id'] : 0;
		$names   = function ( $uuids ) use ( $titles ) {
			$out = array();
			foreach ( (array) $uuids as $uuid ) {
				$out[] = array(
					'uuid'  => (string) $uuid,
					'title' => isset( $titles[ $uuid ] ) ? (string) $titles[ $uuid ] : '',
				);
			}
			return $out;
		};

		$groups = array();
		foreach ( isset( $p['groups'] ) && is_array( $p['groups'] ) ? $p['groups'] : array() as $name => $group ) {
			$groups[] = array(
				'name'     => (string) $name,
				'class'    => (string) $group['class'],
				'action'   => (string) $group['action'],
				'decision' => isset( $group['decision'] ) ? (string) $group['decision'] : '',
				'choices'  => array_values( (array) $group['choices'] ),
				'badges'   => array_values( (array) $group['badges'] ),
			);
		}

		return array(
			'uuid'         => (string) $row['uuid'],
			'kind'         => $kind,
			'type'         => isset( $p['type'] ) ? (string) $p['type'] : '',
			'type_label'   => isset( $p['type_label'] ) ? (string) $p['type_label'] : '',
			// The title as stored: the screen shows it as text (never as HTML), markup included.
			'title'        => isset( $p['title'] ) ? (string) $p['title'] : '',
			'class'        => (string) $row['status'],
			'action'       => (string) $row['action'],
			'decision'     => (string) $row['decision'],
			'effective'    => self::effective( $row ),
			'choices'      => isset( $p['choices'] ) ? array_values( (array) $p['choices'] ) : array(),
			'badges'       => isset( $p['badges'] ) ? array_values( (array) $p['badges'] ) : array(),
			'groups'       => $groups,
			'warnings'     => isset( $p['warnings'] ) ? array_values( (array) $p['warnings'] ) : array(),
			'gate'         => isset( $p['gate'] ) ? array_values( (array) $p['gate'] ) : array(),
			'blocked_by'   => $names( isset( $p['blocked_by'] ) ? $p['blocked_by'] : array() ),
			'pulled_in_by' => $names( isset( $p['pulled_in_by'] ) ? $p['pulled_in_by'] : array() ),
			'pulled_in'    => ! empty( $p['pulled_in'] ),
			'in_scope'     => 'out_of_scope' !== $row['action'] || ! empty( $p['pulled_in'] ),
			'author'       => isset( $p['author'] ) ? $p['author'] : array(),
			'local_id'     => $local,
			'live_id'      => $live_id,
			'exists_here'  => ! empty( $p['exists_here'] ),
			'on_live'      => isset( $p['live_state'] ) && 'found' === $p['live_state'],
			'local_url'    => ! empty( $p['exists_here'] ) ? self::edit_url( admin_url( '/' ), $kind, $local, isset( $p['type'] ) ? (string) $p['type'] : '' ) : '',
			'live_url'     => $live_id > 0 ? self::edit_url( self::live_admin( $pairing ), $kind, $live_id, isset( $p['type'] ) ? (string) $p['type'] : '' ) : '',
		);
	}

	/**
	 * Live's wp-admin URL from the pairing's home URL (links only; never requested).
	 *
	 * @param array $pairing Pairing row.
	 * @return string
	 */
	public static function live_admin( array $pairing ) {
		$home = isset( $pairing['remote_url'] ) ? (string) $pairing['remote_url'] : '';
		return '' === $home ? '' : esc_url_raw( trailingslashit( $home ) . 'wp-admin/', array( 'https' ) );
	}

	/**
	 * An edit link for an item on a site.
	 *
	 * @param string $admin Admin URL with a trailing slash.
	 * @param string $kind  Kind.
	 * @param int    $id    ID.
	 * @param string $type  Post type or taxonomy.
	 * @return string
	 */
	private static function edit_url( $admin, $kind, $id, $type ) {
		if ( '' === $admin || $id < 1 ) {
			return '';
		}
		switch ( $kind ) {
			case 'term':
				$path = 'term.php?taxonomy=' . rawurlencode( $type ) . '&tag_ID=' . $id;
				break;
			case 'menu':
				$path = 'nav-menus.php?action=edit&menu=' . $id;
				break;
			case 'option':
			case 'widgets':
				return '';
			case 'template':
			case 'template_part':
			case 'global_styles':
				$path = 'site-editor.php';
				break;
			case 'navigation':
				$path = 'site-editor.php?postId=' . $id . '&postType=wp_navigation&canvas=edit';
				break;
			default:
				$path = 'post.php?post=' . $id . '&action=edit';
		}
		return esc_url_raw( trailingslashit( $admin ) . $path, array( 'https', 'http' ) );
	}

	/**
	 * A readable label for a kind and type.
	 *
	 * @param string $kind Kind.
	 * @param string $type Post type, taxonomy or option name.
	 * @return string
	 */
	public static function type_label( $kind, $type ) {
		$fixed = array(
			'attachment'    => __( 'Media', 'keepmerge-push-staging-to-live' ),
			'menu'          => __( 'Menu', 'keepmerge-push-staging-to-live' ),
			'pattern'       => __( 'Pattern', 'keepmerge-push-staging-to-live' ),
			'navigation'    => __( 'Navigation', 'keepmerge-push-staging-to-live' ),
			'template'      => __( 'Template', 'keepmerge-push-staging-to-live' ),
			'template_part' => __( 'Template part', 'keepmerge-push-staging-to-live' ),
			'global_styles' => __( 'Global styles', 'keepmerge-push-staging-to-live' ),
			'option'        => __( 'Option', 'keepmerge-push-staging-to-live' ),
			'widgets'       => __( 'Widgets', 'keepmerge-push-staging-to-live' ),
		);
		if ( isset( $fixed[ $kind ] ) ) {
			return $fixed[ $kind ];
		}
		$object = 'term' === $kind ? get_taxonomy( $type ) : get_post_type_object( $type );
		if ( is_object( $object ) && isset( $object->labels->singular_name ) ) {
			return wp_strip_all_tags( (string) $object->labels->singular_name );
		}
		return '' === $type ? $kind : $type;
	}

	/**
	 * A list of names from an answer.
	 *
	 * @param array  $data  Answer.
	 * @param string $field Field.
	 * @return string[]
	 */
	private static function names( array $data, $field ) {
		$out = array();
		foreach ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ? $data[ $field ] : array() as $name ) {
			if ( is_string( $name ) && 1 === preg_match( '#\A[A-Za-z0-9._/-]{1,200}\z#', $name ) ) {
				$out[] = $name;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A 64-hex hash from an answer, '' otherwise.
	 *
	 * @param array  $answer Answer.
	 * @param string $field  Field.
	 * @return string
	 */
	private static function hash_value( array $answer, $field ) {
		return isset( $answer[ $field ] ) && is_string( $answer[ $field ] ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $answer[ $field ] ) ? $answer[ $field ] : '';
	}

	/**
	 * A map of group => 64-hex hash from an answer.
	 *
	 * @param array  $answer Answer.
	 * @param string $field  Field.
	 * @return array<string,string>
	 */
	private static function hash_map( array $answer, $field ) {
		$out = array();
		foreach ( isset( $answer[ $field ] ) && is_array( $answer[ $field ] ) ? $answer[ $field ] : array() as $name => $hash ) {
			if ( is_string( $name ) && 1 === preg_match( '/\A[a-z_]{1,32}\z/', $name ) && is_string( $hash ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $hash ) ) {
				$out[ $name ] = $hash;
			}
		}
		return $out;
	}
}
