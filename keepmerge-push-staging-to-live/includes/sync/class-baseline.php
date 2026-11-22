<?php
/**
 * The baseline run on the source.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Limits;
use SoftGlaze\SafePush\Source_Pairing;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The first sync of a pairing, run from the Send tab in time-boxed steps: this site's
 * fingerprints go to live in batches of at most 500, live answers read-only, and this site
 * records the matches in its own sync_state and gives its objects UUIDs. Only strong matches
 * link objects; medium matches become conflicts in the plan. Nothing is written on live.
 */
final class Baseline {

	/**
	 * Option holding one record per pairing UUID (not autoloaded).
	 */
	const OPTION = 'softglaze_sp_baseline';

	const BATCH = 500;

	const ROUTE = '/keepmerge/v1/remote/baseline';

	/**
	 * Sample rows per page on the Send tab.
	 */
	const PAGE_SIZE = 10;

	/**
	 * Confidences the sample table can filter by.
	 */
	const FILTERS = array( 'strong', 'medium', 'none', 'duplicate', 'excluded' );

	/**
	 * Runs one step: starts (or restarts) the baseline, then sends batches until the time budget
	 * is used up or every fingerprint was matched.
	 *
	 * @param string $uuid Pairing UUID.
	 * @param array  $args Arguments: restart (bool, start again and clear this pairing's
	 *                      unpushed rows first), clone_date (Y-m-d, when staging was copied from
	 *                      live), clone_unknown (bool), cursor (int, the position the screen last
	 *                      saw; an optimistic check).
	 * @return array|WP_Error The record as the screen shows it.
	 */
	public static function step( $uuid, array $args ) {
		$active = Source_Pairing::active_client( $uuid );
		if ( is_wp_error( $active ) ) {
			return $active;
		}
		$row    = $active['row'];
		$client = $active['client'];
		$key    = (string) $row['pairing_uuid'];
		$record = self::record( $key );

		if ( ! empty( $args['restart'] ) || null === $record ) {
			$clone = self::clone_input( $args );
			if ( is_wp_error( $clone ) ) {
				return $clone;
			}
			$renewed = Identity::dedupe( 'post' ) + Identity::dedupe( 'term' );
			Sync_State::clear_unbased( (int) $row['id'] );
			$record = array(
				'state'          => 'running',
				'clone_date'     => $clone['date'],
				'clone_gmt'      => $clone['gmt'],
				'clone_unknown'  => $clone['unknown'],
				'started_at'     => time(),
				'finished_at'    => null,
				'started_by'     => get_current_user_id(),
				'cursor'         => 0,
				'total'          => 0,
				'counts'         => array_fill_keys( self::FILTERS, 0 ),
				'by_kind'        => array(),
				'adopted'        => 0,
				'renewed'        => $renewed,
				'live_admin_url' => '',
				'error'          => null,
			);
			Audit_Log::write(
				'baseline_requested',
				array(
					'pairing_id'  => (int) $row['id'],
					'object_type' => 'pairing',
					'object_ref'  => $key,
					'details'     => array(
						'stage'      => 'started',
						'clone_date' => null === $clone['date'] ? 'unknown' : $clone['date'],
					),
				)
			);
		} elseif ( 'running' !== $record['state'] ) {
			return self::present( $record );
		} elseif ( isset( $args['cursor'] ) && (int) $args['cursor'] !== (int) $record['cursor'] ) {
			return new WP_Error( 'baseline_moved', __( 'The baseline moved on in another window or tab. The screen now shows where it is.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		$ctx          = Export_Context::source( (int) $row['id'] );
		$fingerprints = Items::fingerprints( $ctx->normalizer );
		$total        = count( $fingerprints );
		$deadline     = microtime( true ) + max( 5, Limits::time_budget() - 3 );

		$record['total'] = $total;
		$record['error'] = null;

		while ( $record['cursor'] < $total && microtime( true ) < $deadline ) {
			$batch    = array_slice( $fingerprints, (int) $record['cursor'], self::BATCH );
			$response = $client->post(
				self::ROUTE,
				array( 'fingerprints' => $batch ),
				array( 'budget' => max( 5, (int) floor( $deadline - microtime( true ) ) + 3 ) )
			);

			if ( is_wp_error( $response ) ) {
				Source_Pairing::after_call( $row, $client, $response );
				$record['state'] = 'failed';
				$record['error'] = array(
					'code'    => $response->get_error_code(),
					'message' => $response->get_error_message(),
				);
				self::save( $key, $record );
				self::audit_end( $row, $record, 'error' );
				return $response;
			}

			$results = self::results( $response['data'], count( $batch ) );
			if ( null === $results ) {
				$error = new WP_Error( 'response_invalid', __( 'The live site\'s answer could not be read. Try again.', 'keepmerge-push-staging-to-live' ), array( 'status' => 502 ) );
				Source_Pairing::after_call( $row, $client, $error );
				$record['state'] = 'failed';
				$record['error'] = array(
					'code'    => 'response_invalid',
					'message' => $error->get_error_message(),
				);
				self::save( $key, $record );
				self::audit_end( $row, $record, 'error' );
				return $error;
			}

			self::apply( (int) $row['id'], $batch, $results, $record );
			$record['cursor'] += count( $batch );
			if ( '' === $record['live_admin_url'] ) {
				$record['live_admin_url'] = self::live_admin_url( $row, isset( $response['data']['admin_url'] ) ? $response['data']['admin_url'] : '' );
			}
		}

		Source_Pairing::after_call( $row, $client, null );

		if ( $record['cursor'] >= $total ) {
			$record['state']       = 'done';
			$record['finished_at'] = time();
			self::audit_end( $row, $record, 'ok' );
		}

		self::save( $key, $record );
		return self::present( $record );
	}

	/**
	 * Runs steps until the baseline is done (WP-CLI and test tools).
	 *
	 * @param string $uuid Pairing UUID.
	 * @param array  $args See step().
	 * @return array|WP_Error
	 */
	public static function run( $uuid, array $args ) {
		$args['restart'] = true;
		for ( $i = 0; $i < 1000; $i++ ) {
			$result = self::step( $uuid, $args );
			if ( is_wp_error( $result ) || 'running' !== $result['state'] ) {
				return $result;
			}
			$args = array();
		}
		return new WP_Error( 'baseline_incomplete', 'baseline_incomplete' );
	}

	/**
	 * The stored record of a pairing, or null.
	 *
	 * @param string $uuid Pairing UUID.
	 * @return array|null
	 */
	public static function record( $uuid ) {
		$records = get_option( self::OPTION, array() );
		$uuid    = strtolower( (string) $uuid );
		return is_array( $records ) && isset( $records[ $uuid ] ) && is_array( $records[ $uuid ] ) ? $records[ $uuid ] : null;
	}

	/**
	 * The record of a pairing as the screen shows it, or null when no baseline ran.
	 *
	 * @param string $uuid Pairing UUID.
	 * @return array|null
	 */
	public static function summary( $uuid ) {
		$record = self::record( $uuid );
		return null === $record ? null : self::present( $record );
	}

	/**
	 * A page of matched or unmatched items with links to each site.
	 *
	 * @param string $uuid   Pairing UUID.
	 * @param string $filter One of FILTERS.
	 * @param int    $page   1-based page.
	 * @return array|WP_Error
	 */
	public static function rows( $uuid, $filter, $page ) {
		$row = Source_Pairing::find( $uuid );
		if ( null === $row ) {
			return new WP_Error( 'pairing_not_found', __( 'This pairing does not exist on this site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $filter, self::FILTERS, true ) ) {
			return new WP_Error( 'invalid_filter', __( 'Unknown filter.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}

		$record = self::record( (string) $row['pairing_uuid'] );
		$live   = null === $record || '' === (string) $record['live_admin_url'] ? self::live_admin_url( $row, '' ) : (string) $record['live_admin_url'];
		$result = Sync_State::page( (int) $row['id'], $filter, max( 1, (int) $page ), self::PAGE_SIZE );

		$out = array();
		foreach ( $result['rows'] as $state ) {
			$out[] = self::describe( $state, $live );
		}

		return array(
			'filter'   => $filter,
			'page'     => max( 1, (int) $page ),
			'per_page' => self::PAGE_SIZE,
			'total'    => (int) $result['total'],
			'rows'     => $out,
		);
	}

	/**
	 * Forgets a pairing's record (test tools).
	 *
	 * @param string $uuid Pairing UUID.
	 * @return void
	 */
	public static function forget( $uuid ) {
		$records = get_option( self::OPTION, array() );
		if ( is_array( $records ) && isset( $records[ strtolower( (string) $uuid ) ] ) ) {
			unset( $records[ strtolower( (string) $uuid ) ] );
			update_option( self::OPTION, $records, false );
		}
	}

	/**
	 * Applies live's answers for one batch: UUIDs (adopted from live for strong matches when no
	 * other object uses them) and sync_state rows.
	 *
	 * @param int   $pairing_id Pairing row ID.
	 * @param array $batch      Fingerprints sent.
	 * @param array $results    Index in batch => answer.
	 * @param array $record     Record (counts updated).
	 * @return void
	 */
	private static function apply( $pairing_id, array $batch, array $results, array &$record ) {
		foreach ( $batch as $i => $fingerprint ) {
			$kind   = $fingerprint['k'];
			$space  = Scope::space( $kind );
			$local  = (int) $fingerprint['id'];
			$answer = isset( $results[ $i ] ) ? $results[ $i ] : array(
				'm'     => 'none',
				'id'    => 0,
				'uuid'  => '',
				'taken' => array(),
			);

			$uuid = Identity::get( $space, $local );
			if ( 'strong' === $answer['m'] && '' !== $answer['uuid'] && $answer['uuid'] !== $uuid && self::can_adopt( $space, $local, $uuid, $answer['uuid'], $pairing_id ) ) {
				Identity::adopt( $space, $local, $answer['uuid'] );
				$uuid = $answer['uuid'];
				++$record['adopted'];
			}
			if ( '' === $uuid ) {
				$uuid = Identity::assign( $space, $local );
			}
			if ( '' === $uuid ) {
				continue;
			}

			switch ( $answer['m'] ) {
				case 'strong':
				case 'medium':
					$confidence = $answer['m'];
					$remote     = (int) $answer['id'];
					break;
				case 'excluded':
					$confidence = 'excluded';
					$remote     = 0;
					break;
				default:
					$confidence = empty( $answer['taken'] ) ? 'none' : 'duplicate';
					$remote     = empty( $answer['taken'] ) ? 0 : (int) $answer['taken'][0];
			}

			Sync_State::upsert( $pairing_id, $uuid, $kind, $local, $remote, $confidence );

			++$record['counts'][ $confidence ];
			if ( ! isset( $record['by_kind'][ $kind ] ) ) {
				$record['by_kind'][ $kind ] = array_fill_keys( self::FILTERS, 0 );
			}
			++$record['by_kind'][ $kind ][ $confidence ];
		}
	}

	/**
	 * Whether this site may take over live's UUID for a matched object: no other object here
	 * carries it, and the object's current UUID is not used by another pairing.
	 *
	 * @param string $space      Space.
	 * @param int    $local      Local ID.
	 * @param string $own        Current UUID ('' when none).
	 * @param string $live_uuid  Live's UUID.
	 * @param int    $pairing_id Pairing row ID.
	 * @return bool
	 */
	private static function can_adopt( $space, $local, $own, $live_uuid, $pairing_id ) {
		if ( ! wp_is_uuid( $live_uuid, 4 ) ) {
			return false;
		}
		foreach ( Identity::find( $space, $live_uuid ) as $id ) {
			if ( $id !== $local ) {
				return false;
			}
		}
		return '' === $own || ! Sync_State::used_elsewhere( $own, $pairing_id );
	}

	/**
	 * Live's answers indexed by batch position, checked field by field; null when malformed.
	 *
	 * @param array $data  Decoded answer.
	 * @param int   $count Batch size.
	 * @return array|null
	 */
	private static function results( array $data, $count ) {
		if ( ! isset( $data['results'] ) || ! is_array( $data['results'] ) ) {
			return null;
		}
		$out = array();
		foreach ( $data['results'] as $answer ) {
			if ( ! is_array( $answer ) || ! isset( $answer['i'], $answer['m'], $answer['id'] ) || ! is_int( $answer['i'] ) || $answer['i'] < 0 || $answer['i'] >= $count || ! in_array( $answer['m'], array( 'strong', 'medium', 'none', 'excluded' ), true ) || ! is_int( $answer['id'] ) || $answer['id'] < 0 ) {
				return null;
			}
			$taken = array();
			if ( isset( $answer['taken'] ) && is_array( $answer['taken'] ) ) {
				foreach ( $answer['taken'] as $id ) {
					if ( is_int( $id ) && $id > 0 ) {
						$taken[] = $id;
					}
				}
			}
			$out[ $answer['i'] ] = array(
				'm'     => $answer['m'],
				'id'    => $answer['id'],
				'uuid'  => isset( $answer['uuid'] ) && is_string( $answer['uuid'] ) && wp_is_uuid( $answer['uuid'], 4 ) ? strtolower( $answer['uuid'] ) : '',
				'taken' => array_slice( $taken, 0, Baseline_Matcher::MAX_TAKEN ),
			);
		}
		return $out;
	}

	/**
	 * Validates the clone date input.
	 *
	 * @param array $args Arguments.
	 * @return array{date:string|null,gmt:int|null,unknown:bool}|WP_Error
	 */
	private static function clone_input( array $args ) {
		if ( ! empty( $args['clone_unknown'] ) ) {
			return array(
				'date'    => null,
				'gmt'     => null,
				'unknown' => true,
			);
		}
		$date = isset( $args['clone_date'] ) && is_string( $args['clone_date'] ) ? trim( $args['clone_date'] ) : '';
		if ( 1 !== preg_match( '/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return new WP_Error( 'clone_date_invalid', __( 'Enter the date this site was copied from live, or choose "I don\'t know".', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}
		$start = date_create_immutable( $date . ' 00:00:00', wp_timezone() );
		if ( false === $start || $start->getTimestamp() > time() + DAY_IN_SECONDS ) {
			return new WP_Error( 'clone_date_future', __( 'The clone date cannot be in the future.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}
		return array(
			'date'    => $date,
			'gmt'     => $start->getTimestamp(),
			'unknown' => false,
		);
	}

	/**
	 * Live's admin URL from the answer when it is https on the paired host; otherwise built from
	 * the pairing's home URL. Only used for links the admin clicks.
	 *
	 * @param array  $row      Pairing row.
	 * @param string $reported Admin URL live reported.
	 * @return string
	 */
	private static function live_admin_url( array $row, $reported ) {
		$host = strtolower( (string) wp_parse_url( (string) $row['rest_root'], PHP_URL_HOST ) );
		if ( is_string( $reported ) && '' !== $reported ) {
			$parts = wp_parse_url( $reported );
			if ( is_array( $parts ) && isset( $parts['scheme'], $parts['host'] ) && 'https' === strtolower( $parts['scheme'] ) && strtolower( $parts['host'] ) === $host && ! isset( $parts['user'] ) && ! isset( $parts['query'] ) && ! isset( $parts['fragment'] ) ) {
				return esc_url_raw( trailingslashit( $reported ), array( 'https' ) );
			}
		}
		return esc_url_raw( trailingslashit( (string) $row['remote_url'] ) . 'wp-admin/', array( 'https' ) );
	}

	/**
	 * One sync_state row as a table row: title, type, and links on both sites.
	 *
	 * @param array  $state      Row.
	 * @param string $live_admin Live's admin URL.
	 * @return array
	 */
	private static function describe( array $state, $live_admin ) {
		$kind   = (string) $state['object_type'];
		$local  = (int) $state['object_id'];
		$remote = (int) $state['remote_object_id'];
		$title  = '';
		$type   = $kind;
		$path   = '';
		$rpath  = '';

		if ( 'term' === $kind || 'menu' === $kind ) {
			$term = get_term( $local );
			if ( $term instanceof \WP_Term ) {
				$title = $term->name;
				$type  = $term->taxonomy;
				if ( 'menu' === $kind ) {
					$path  = 'nav-menus.php?action=edit&menu=' . $local;
					$rpath = 'nav-menus.php?action=edit&menu=' . $remote;
				} else {
					$path  = 'term.php?taxonomy=' . rawurlencode( $term->taxonomy ) . '&tag_ID=' . $local;
					$rpath = 'term.php?taxonomy=' . rawurlencode( $term->taxonomy ) . '&tag_ID=' . $remote;
				}
			}
		} else {
			$post = get_post( $local );
			if ( $post instanceof \WP_Post ) {
				$title = '' === $post->post_title ? $post->post_name : $post->post_title;
				$type  = $post->post_type;
				switch ( $kind ) {
					case 'template':
					case 'template_part':
						$id    = rawurlencode( Items::theme_of( $local ) . '//' . $post->post_name );
						$path  = 'site-editor.php?postType=' . $post->post_type . '&postId=' . $id . '&canvas=edit';
						$rpath = $path;
						break;
					case 'global_styles':
						$path  = 'site-editor.php?path=%2Fwp_global_styles';
						$rpath = $path;
						break;
					case 'navigation':
						$path  = 'site-editor.php?postId=' . $local . '&postType=wp_navigation&canvas=edit';
						$rpath = 'site-editor.php?postId=' . $remote . '&postType=wp_navigation&canvas=edit';
						break;
					case 'menu_item':
						$menus = wp_get_object_terms( $local, 'nav_menu', array( 'fields' => 'ids' ) );
						$menu  = is_array( $menus ) && ! empty( $menus ) ? (int) reset( $menus ) : 0;
						$title = '' === $post->post_title ? __( 'Menu item', 'keepmerge-push-staging-to-live' ) . ' ' . $local : $post->post_title;
						$path  = 'nav-menus.php?action=edit&menu=' . $menu;
						$rpath = '';
						break;
					default:
						$path  = 'post.php?post=' . $local . '&action=edit';
						$rpath = 'post.php?post=' . $remote . '&action=edit';
				}
			}
		}

		$object = 'term' === $kind || 'menu' === $kind ? get_taxonomy( $type ) : get_post_type_object( $type );
		$label  = is_object( $object ) && isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : $type;

		return array(
			'uuid'       => (string) $state['uuid'],
			'kind'       => $kind,
			'type'       => $type,
			'type_label' => wp_strip_all_tags( $label ),
			'title'      => wp_strip_all_tags( (string) $title ),
			'local_id'   => $local,
			'live_id'    => $remote,
			'confidence' => (string) $state['match_confidence'],
			'local_url'  => '' === $path ? '' : esc_url_raw( admin_url( $path ) ),
			'live_url'   => '' === $rpath || $remote < 1 || '' === $live_admin ? '' : esc_url_raw( $live_admin . $rpath, array( 'https' ) ),
		);
	}

	/**
	 * The record as the screen shows it.
	 *
	 * @param array $record Record.
	 * @return array
	 */
	private static function present( array $record ) {
		$counts   = isset( $record['counts'] ) && is_array( $record['counts'] ) ? array_merge( array_fill_keys( self::FILTERS, 0 ), $record['counts'] ) : array_fill_keys( self::FILTERS, 0 );
		$compared = array_sum( $counts ) - $counts['excluded'];

		return array(
			'state'         => (string) $record['state'],
			'clone_date'    => $record['clone_date'],
			'clone_unknown' => (bool) $record['clone_unknown'],
			'started_at'    => (int) $record['started_at'],
			'finished_at'   => null === $record['finished_at'] ? null : (int) $record['finished_at'],
			'cursor'        => (int) $record['cursor'],
			'total'         => (int) $record['total'],
			'counts'        => $counts,
			'compared'      => $compared,
			'strong_rate'   => $compared > 0 ? round( 100 * $counts['strong'] / $compared, 1 ) : null,
			'by_kind'       => isset( $record['by_kind'] ) && is_array( $record['by_kind'] ) ? $record['by_kind'] : array(),
			'adopted'       => (int) $record['adopted'],
			'renewed'       => (int) $record['renewed'],
			'error'         => $record['error'],
		);
	}

	/**
	 * Audits the end of a run (counts only).
	 *
	 * @param array  $row    Pairing row.
	 * @param array  $record Record.
	 * @param string $result ok or error.
	 * @return void
	 */
	private static function audit_end( array $row, array $record, $result ) {
		$details = array_merge(
			array(
				'stage'        => 'ok' === $result ? 'done' : 'failed',
				'fingerprints' => (int) $record['cursor'],
			),
			$record['counts']
		);
		if ( is_array( $record['error'] ) ) {
			$details['reason'] = (string) $record['error']['code'];
		}

		Audit_Log::write(
			'baseline_requested',
			array(
				'result'      => $result,
				'pairing_id'  => (int) $row['id'],
				'object_type' => 'pairing',
				'object_ref'  => (string) $row['pairing_uuid'],
				'details'     => $details,
			)
		);
	}

	/**
	 * Stores a record.
	 *
	 * @param string $uuid   Pairing UUID.
	 * @param array  $record Record.
	 * @return void
	 */
	private static function save( $uuid, array $record ) {
		$records = get_option( self::OPTION, array() );
		$records = is_array( $records ) ? $records : array();

		$records[ strtolower( (string) $uuid ) ] = $record;
		update_option( self::OPTION, $records, false );
	}
}
