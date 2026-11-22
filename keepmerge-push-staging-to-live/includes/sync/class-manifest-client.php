<?php
/**
 * Manifest requests from the source.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Source_Pairing;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gathers S, L and B per item for the planner: every item is exported and hashed here, then live
 * is asked for its hash and base hash in batches of at most 500 UUIDs with live-ID hints
 * (POST /remote/manifest, read-only on live).
 */
final class Manifest_Client {

	const ROUTE = '/keepmerge/v1/remote/manifest';

	const BATCH = 500;

	/**
	 * Exports every item and asks live for its side.
	 *
	 * @param string $uuid Pairing UUID.
	 * @return array|WP_Error {
	 *     @type array[]  $items               uuid, kind, type, id, title, confidence, live_id, state, s_hash, l_hash, b_hash, equal, unsupported, problems, modified_gmt, l_modified_gmt.
	 *     @type string[] $missing_block_types Block types live lacks (for the listed items).
	 *     @type string[] $missing_stylesheets Template stylesheets live lacks.
	 *     @type array[]  $live_changes        Live-only items changed since base.
	 * }
	 */
	public static function compare( $uuid ) {
		$active = Source_Pairing::active_client( $uuid );
		if ( is_wp_error( $active ) ) {
			return $active;
		}
		$row    = $active['row'];
		$client = $active['client'];

		$ctx    = Export_Context::source( (int) $row['id'] );
		$states = Sync_State::by_object( (int) $row['id'] );
		$local  = array();
		$blocks = array();
		$themes = array();

		foreach ( Items::enumerate() as $ref ) {
			$item = Items::export( $ref['kind'], $ref['ref'], $ctx );
			if ( null === $item ) {
				continue;
			}
			$state = isset( $states[ $item['kind'] . ':' . $item['id'] ] ) ? $states[ $item['kind'] . ':' . $item['id'] ] : null;

			$local[ $item['uuid'] ] = array(
				'item'  => $item,
				'state' => $state,
			);
			foreach ( $item['block_types'] as $name ) {
				$blocks[ $name ] = true;
			}
			if ( ! empty( $item['stylesheet'] ) ) {
				$themes[ $item['stylesheet'] ] = true;
			}
		}

		$entries = array();
		foreach ( $local as $item_uuid => $entry ) {
			$item  = $entry['item'];
			$state = $entry['state'];
			$hint  = is_array( $state ) && in_array( $state['match_confidence'], array( 'strong', 'medium', 'pushed' ), true ) ? (int) $state['remote_object_id'] : 0;
			$one   = array(
				'uuid' => (string) $item_uuid,
				'kind' => $item['kind'],
			);
			if ( ! in_array( $item['kind'], array( 'option', 'widgets' ), true ) ) {
				$one['type']    = $item['type'];
				$one['live_id'] = $hint;
			}
			$entries[] = $one;
		}

		$answers        = array();
		$missing_blocks = array();
		$missing_themes = array();
		$live_changes   = array();
		$block_names    = array_keys( $blocks );
		$theme_names    = array_keys( $themes );
		$cursor         = 0;
		$batches        = array_chunk( $entries, self::BATCH );
		if ( empty( $batches ) ) {
			$batches = array( array() );
		}

		foreach ( $batches as $n => $batch ) {
			$body = array(
				'items'       => $batch,
				'live_cursor' => $cursor,
			);
			if ( 0 === $n ) {
				$body['block_types'] = array_slice( $block_names, 0, Manifest::MAX_BLOCK_TYPES );
				$body['stylesheets'] = array_slice( $theme_names, 0, Manifest::MAX_STYLESHEETS );
			}
			$response = $client->post( self::ROUTE, $body );
			if ( is_wp_error( $response ) ) {
				Source_Pairing::after_call( $row, $client, $response );
				return $response;
			}
			$data = $response['data'];
			foreach ( isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array() as $answer ) {
				if ( is_array( $answer ) && isset( $answer['uuid'] ) && is_string( $answer['uuid'] ) ) {
					$answers[ $answer['uuid'] ] = $answer;
				}
			}
			foreach ( array( 'missing_block_types', 'missing_stylesheets' ) as $field ) {
				if ( isset( $data[ $field ] ) && is_array( $data[ $field ] ) ) {
					foreach ( $data[ $field ] as $name ) {
						if ( is_string( $name ) ) {
							if ( 'missing_block_types' === $field ) {
								$missing_blocks[] = sanitize_text_field( $name );
							} else {
								$missing_themes[] = sanitize_text_field( $name );
							}
						}
					}
				}
			}
			if ( isset( $data['live_changes']['items'] ) && is_array( $data['live_changes']['items'] ) ) {
				foreach ( $data['live_changes']['items'] as $change ) {
					$live_changes[] = $change;
				}
				$cursor = isset( $data['live_changes']['next_cursor'] ) && is_int( $data['live_changes']['next_cursor'] ) ? $data['live_changes']['next_cursor'] : $cursor;
			}
		}

		Source_Pairing::after_call( $row, $client, null );

		$out    = array();
		$counts = array(
			'items'   => 0,
			'found'   => 0,
			'equal'   => 0,
			'differ'  => 0,
			'missing' => 0,
		);
		foreach ( $local as $item_uuid => $entry ) {
			$item   = $entry['item'];
			$state  = $entry['state'];
			$answer = isset( $answers[ $item_uuid ] ) ? $answers[ $item_uuid ] : array( 'state' => 'missing' );
			$found  = isset( $answer['state'] ) && 'found' === $answer['state'] && isset( $answer['hash'] ) && is_string( $answer['hash'] );
			$equal  = $found && hash_equals( $answer['hash'], $item['hash'] );

			++$counts['items'];
			if ( $found ) {
				++$counts['found'];
				++$counts[ $equal ? 'equal' : 'differ' ];
			} else {
				++$counts['missing'];
			}

			$out[] = array(
				'uuid'           => (string) $item_uuid,
				'kind'           => $item['kind'],
				'type'           => $item['type'],
				'id'             => (int) $item['id'],
				'title'          => (string) $item['title'],
				'confidence'     => is_array( $state ) ? (string) $state['match_confidence'] : '',
				'live_id'        => $found && isset( $answer['live_id'] ) ? (int) $answer['live_id'] : 0,
				'state'          => isset( $answer['state'] ) ? sanitize_key( (string) $answer['state'] ) : 'missing',
				's_hash'         => $item['hash'],
				'l_hash'         => $found ? $answer['hash'] : '',
				'b_hash'         => $found && isset( $answer['base_hash'] ) && is_string( $answer['base_hash'] ) ? $answer['base_hash'] : '',
				's_groups'       => $item['groups'],
				'l_groups'       => $found && isset( $answer['groups'] ) && is_array( $answer['groups'] ) ? $answer['groups'] : array(),
				'equal'          => $equal,
				'unsupported'    => (bool) $item['unsupported'],
				'problems'       => $item['problems'],
				'unmapped'       => count( $item['unmapped'] ),
				'modified_gmt'   => (string) $item['modified_gmt'],
				'l_modified_gmt' => $found && isset( $answer['modified_gmt'] ) && is_string( $answer['modified_gmt'] ) ? $answer['modified_gmt'] : '',
			);
		}

		Audit_Log::write(
			'manifest_requested',
			array(
				'pairing_id'  => (int) $row['id'],
				'object_type' => 'pairing',
				'object_ref'  => (string) $row['pairing_uuid'],
				'details'     => $counts,
			)
		);

		return array(
			'items'               => $out,
			'counts'              => $counts,
			'missing_block_types' => array_values( array_unique( $missing_blocks ) ),
			'missing_stylesheets' => array_values( array_unique( $missing_themes ) ),
			'live_changes'        => $live_changes,
		);
	}
}
