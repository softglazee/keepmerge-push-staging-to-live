<?php
/**
 * Manifest and item reads on the destination.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

use SoftGlaze\SafePush\Limits;
use SoftGlaze\SafePush\Sync\Exporters\Options;
use SoftGlaze\SafePush\Woo\Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers POST /remote/manifest and POST /remote/items/fetch, read-only: exports run in the
 * destination context, which writes nothing. Only kinds and types live itself includes are
 * answered, options only from live's own allowlist; a request naming an unknown kind is refused
 * as a whole, an excluded item is answered "excluded" with nothing about it, and a live-ID hint
 * counts only when that object has the kind and type the request names.
 */
final class Manifest {

	const MAX_MANIFEST_ITEMS = 500;

	const MAX_FETCH_ITEMS = 50;

	const MAX_BLOCK_TYPES = 500;

	const MAX_STYLESHEETS = 50;

	/**
	 * Live-only changes returned per manifest call.
	 */
	const LIVE_CHANGES_PAGE = 100;

	/**
	 * Kinds these routes answer (menu items travel inside their menu).
	 */
	const KINDS = array( 'post', 'product', 'attachment', 'pattern', 'navigation', 'template', 'template_part', 'global_styles', 'term', 'menu', 'option', 'widgets' );

	/**
	 * POST /remote/manifest.
	 *
	 * @param array $pairing Pairing row.
	 * @param array $body    Decoded body.
	 * @return array|WP_Error
	 */
	public static function answer( array $pairing, array $body ) {
		$items = self::parse_items( $body, self::MAX_MANIFEST_ITEMS );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$block_types = self::parse_names( $body, 'block_types', self::MAX_BLOCK_TYPES, '#\A[a-z][a-z0-9-]*/[a-z][a-z0-9-]*\z#' );
		$stylesheets = self::parse_names( $body, 'stylesheets', self::MAX_STYLESHEETS, '/\A[A-Za-z0-9._-]{1,100}\z/' );
		if ( is_wp_error( $block_types ) || is_wp_error( $stylesheets ) ) {
			return self::invalid();
		}
		$cursor = 0;
		if ( isset( $body['live_cursor'] ) ) {
			if ( ! is_int( $body['live_cursor'] ) || $body['live_cursor'] < 0 ) {
				return self::invalid();
			}
			$cursor = $body['live_cursor'];
		}

		$deadline = microtime( true ) + max( 1, Limits::time_budget() - 2 );
		$ctx      = Export_Context::destination();
		$bases    = Sync_State::rows_for( (int) $pairing['id'], array_column( $items, 'uuid' ) );
		$out      = array();
		$counts   = array_fill_keys( array( 'found', 'missing', 'excluded', 'deferred' ), 0 );

		foreach ( $items as $entry ) {
			if ( Limits::over( $deadline ) ) {
				$out[] = array(
					'uuid'  => $entry['uuid'],
					'state' => 'deferred',
				);
				++$counts['deferred'];
				continue;
			}

			$resolved = self::resolve( $pairing, $entry );
			if ( 'found' !== $resolved['state'] ) {
				$out[] = array(
					'uuid'  => $entry['uuid'],
					'state' => $resolved['state'],
				);
				++$counts[ $resolved['state'] ];
				continue;
			}

			$item = Items::export( $resolved['kind'], $resolved['ref'], $ctx );
			if ( null === $item ) {
				$out[] = array(
					'uuid'  => $entry['uuid'],
					'state' => 'missing',
				);
				++$counts['missing'];
				continue;
			}

			$out[] = array(
				'uuid'         => $entry['uuid'],
				'state'        => 'found',
				'live_id'      => (int) $item['id'],
				'hash'         => $item['hash'],
				'groups'       => $item['groups'],
				'base_hash'    => isset( $bases[ $entry['uuid'] ] ) ? (string) $bases[ $entry['uuid'] ]['base_hash'] : '',
				'base_groups'  => isset( $bases[ $entry['uuid'] ] ) ? self::base_groups( $bases[ $entry['uuid'] ] ) : array(),
				'modified_gmt' => $item['modified_gmt'],
				'unsupported'  => (bool) $item['unsupported'],
			);
			++$counts['found'];
		}

		$missing_blocks = array();
		if ( ! empty( $block_types ) ) {
			$registry = \WP_Block_Type_Registry::get_instance();
			foreach ( $block_types as $name ) {
				if ( ! $registry->is_registered( $name ) ) {
					$missing_blocks[] = $name;
				}
			}
		}
		$missing_themes = array();
		foreach ( $stylesheets as $stylesheet ) {
			if ( ! wp_get_theme( $stylesheet )->exists() ) {
				$missing_themes[] = $stylesheet;
			}
		}

		$changes = self::live_changes( $pairing, $cursor, $ctx, $deadline );

		return array(
			'items'               => $out,
			'counts'              => $counts,
			'missing_block_types' => $missing_blocks,
			'missing_stylesheets' => $missing_themes,
			'live_changes'        => $changes,
			'woocommerce'         => Guard::active(),
		);
	}

	/**
	 * POST /remote/items/fetch: live's normal forms for a diff (at most 50 items).
	 *
	 * @param array $pairing Pairing row.
	 * @param array $body    Decoded body.
	 * @return array|WP_Error
	 */
	public static function fetch( array $pairing, array $body ) {
		$items = self::parse_items( $body, self::MAX_FETCH_ITEMS );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$deadline = microtime( true ) + max( 1, Limits::time_budget() - 2 );
		$ctx      = Export_Context::destination();
		$out      = array();
		$counts   = array_fill_keys( array( 'found', 'missing', 'excluded', 'deferred' ), 0 );

		foreach ( $items as $entry ) {
			if ( Limits::over( $deadline ) ) {
				$out[] = array(
					'uuid'  => $entry['uuid'],
					'state' => 'deferred',
				);
				++$counts['deferred'];
				continue;
			}
			$resolved = self::resolve( $pairing, $entry );
			$item     = 'found' === $resolved['state'] ? Items::export( $resolved['kind'], $resolved['ref'], $ctx ) : null;
			if ( null === $item ) {
				$state = 'found' === $resolved['state'] ? 'missing' : $resolved['state'];
				$out[] = array(
					'uuid'  => $entry['uuid'],
					'state' => $state,
				);
				++$counts[ $state ];
				continue;
			}

			$out[] = array(
				'uuid'        => $entry['uuid'],
				'state'       => 'found',
				'live_id'     => (int) $item['id'],
				'kind'        => $item['kind'],
				'type'        => $item['type'],
				'data'        => $item['data'],
				'refs'        => self::live_refs( $item['refs'] ),
				'unmapped'    => $item['unmapped'],
				'hash'        => $item['hash'],
				'groups'      => $item['groups'],
				'fields'      => $item['fields'],
				'problems'    => $item['problems'],
				'unsupported' => (bool) $item['unsupported'],
			);
			++$counts['found'];
		}

		return array(
			'items'  => $out,
			'counts' => $counts,
		);
	}

	/**
	 * Validates the item list of both routes after the permission chain, each field read by name.
	 *
	 * @param array $body Body.
	 * @param int   $max  Most items.
	 * @return array|WP_Error
	 */
	public static function parse_items( array $body, $max ) {
		if ( ! isset( $body['items'] ) || ! is_array( $body['items'] ) || ! wp_is_numeric_array( $body['items'] ) ) {
			return self::invalid();
		}
		if ( count( $body['items'] ) > $max ) {
			return new WP_Error( 'too_many_items', 'too_many_items', array( 'status' => 400 ) );
		}

		$out = array();
		foreach ( array_values( $body['items'] ) as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['kind'], $entry['uuid'] ) || ! is_string( $entry['kind'] ) || ! in_array( $entry['kind'], self::KINDS, true ) || ! is_string( $entry['uuid'] ) ) {
				return self::invalid();
			}
			$kind  = $entry['kind'];
			$uuid  = $entry['uuid'];
			$clean = array(
				'kind'    => $kind,
				'uuid'    => $uuid,
				'type'    => '',
				'live_id' => 0,
			);

			if ( 'option' === $kind ) {
				if ( 1 !== preg_match( '/\Aoption:([A-Za-z0-9_.:-]{1,191})\z/', $uuid, $m ) ) {
					return self::invalid();
				}
				$clean['type'] = $m[1];
			} elseif ( 'widgets' === $kind ) {
				if ( Options::WIDGETS_UUID !== $uuid ) {
					return self::invalid();
				}
			} else {
				// The type may be left out (an empty string) when the source no longer knows it: an
				// object deleted there, looked up by its UUID and live ID. Live then accepts any type
				// of the kind that is inside its own scope.
				$type = isset( $entry['type'] ) ? $entry['type'] : '';
				if ( ! wp_is_uuid( $uuid, 4 ) || ! is_string( $type ) || ( '' !== $type && 1 !== preg_match( '/\A[a-z0-9_-]{1,32}\z/', $type ) ) ) {
					return self::invalid();
				}
				$clean['uuid'] = strtolower( $uuid );
				$clean['type'] = $type;
				if ( isset( $entry['live_id'] ) ) {
					if ( ! is_int( $entry['live_id'] ) || $entry['live_id'] < 0 ) {
						return self::invalid();
					}
					$clean['live_id'] = $entry['live_id'];
				}
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * Finds the live object a request entry names, inside live's own scope.
	 *
	 * @param array $pairing Pairing row.
	 * @param array $entry   Parsed entry.
	 * @return array{state:string,kind:string,ref:int|string}
	 */
	public static function resolve( array $pairing, array $entry ) {
		$kind = $entry['kind'];

		if ( 'option' === $kind ) {
			if ( ! Scope::option_allowed( $entry['type'] ) ) {
				return self::state( 'excluded' );
			}
			return false === get_option( $entry['type'], false ) ? self::state( 'missing' ) : self::state( 'found', 'option', $entry['type'] );
		}
		if ( 'widgets' === $kind ) {
			return Scope::widgets_included() ? self::state( 'found', 'widgets', 'widgets' ) : self::state( 'excluded' );
		}

		$any_type = '' === $entry['type'];

		if ( 'term' === $kind || 'menu' === $kind ) {
			$taxonomy = 'menu' === $kind ? 'nav_menu' : $entry['type'];
			if ( 'menu' === $kind ) {
				$allowed = Scope::menus_included() && ( $any_type || 'nav_menu' === $entry['type'] );
			} else {
				$allowed = $any_type ? ! empty( Scope::taxonomies() ) : in_array( $taxonomy, Scope::taxonomies(), true );
			}
			if ( ! $allowed ) {
				return self::state( 'excluded' );
			}
			foreach ( self::candidate_ids( $pairing, $entry, 'term' ) as $id ) {
				$term = get_term( $id );
				if ( $term instanceof \WP_Term && ( ( $any_type && 'term' === $kind ) || $term->taxonomy === $taxonomy ) && Scope::allows_term( $term, $kind ) ) {
					return self::state( 'found', $kind, (int) $term->term_id );
				}
			}
			return self::state( 'missing' );
		}

		$types = Scope::post_types_for_kind( $kind );
		if ( $any_type ? empty( $types ) : ! in_array( $entry['type'], $types, true ) ) {
			return self::state( 'excluded' );
		}
		foreach ( self::candidate_ids( $pairing, $entry, 'post' ) as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post && ( $any_type ? in_array( $post->post_type, $types, true ) : $post->post_type === $entry['type'] ) && Scope::allows_post( $post, $kind ) ) {
				return self::state( 'found', $kind, (int) $post->ID );
			}
		}
		return self::state( 'missing' );
	}

	/**
	 * Live objects an entry may name: the ID hint, objects carrying the UUID, the pairing's row.
	 *
	 * @param array  $pairing Pairing row.
	 * @param array  $entry   Entry.
	 * @param string $space   'post' or 'term'.
	 * @return int[]
	 */
	private static function candidate_ids( array $pairing, array $entry, $space ) {
		$ids = array();
		if ( $entry['live_id'] > 0 ) {
			$ids[] = (int) $entry['live_id'];
		}
		foreach ( Identity::find( $space, $entry['uuid'] ) as $id ) {
			$ids[] = $id;
		}
		$row = Sync_State::find( (int) $pairing['id'], $entry['uuid'] );
		if ( is_array( $row ) && (int) $row['object_id'] > 0 && Scope::space( (string) $row['object_type'] ) === $space ) {
			$ids[] = (int) $row['object_id'];
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Items whose live hash moved away from their base hash since the last push (paged by
	 * sync_state row).
	 *
	 * @param array          $pairing  Pairing row.
	 * @param int            $cursor   Row ID cursor.
	 * @param Export_Context $ctx      Context.
	 * @param float          $deadline Deadline.
	 * @return array{items:array[],next_cursor:int|null}
	 */
	private static function live_changes( array $pairing, $cursor, Export_Context $ctx, $deadline ) {
		$rows  = Sync_State::based_after( (int) $pairing['id'], $cursor, self::LIVE_CHANGES_PAGE );
		$items = array();
		$last  = $cursor;
		foreach ( $rows as $row ) {
			if ( Limits::over( $deadline ) ) {
				return array(
					'items'       => $items,
					'next_cursor' => $last,
				);
			}
			$last = (int) $row['id'];
			$kind = (string) $row['object_type'];
			$ref  = 'option' === $kind ? substr( (string) $row['uuid'], 7 ) : (int) $row['object_id'];
			$item = in_array( $kind, self::KINDS, true ) ? Items::export( $kind, $ref, $ctx ) : null;
			if ( null === $item ) {
				$items[] = array(
					'uuid'      => (string) $row['uuid'],
					'state'     => 'deleted',
					'base_hash' => (string) $row['base_hash'],
				);
				continue;
			}
			if ( $item['hash'] !== $row['base_hash'] ) {
				$items[] = array(
					'uuid'      => (string) $row['uuid'],
					'state'     => 'changed',
					'kind'      => $kind,
					'type'      => $item['type'],
					'live_id'   => (int) $item['id'],
					'hash'      => $item['hash'],
					'base_hash' => (string) $row['base_hash'],
				);
			}
		}
		return array(
			'items'       => $items,
			'next_cursor' => count( $rows ) < self::LIVE_CHANGES_PAGE ? null : $last,
		);
	}

	/**
	 * The base hashes of a product's field groups from a sync_state row (group => hash).
	 *
	 * @param array $row Row.
	 * @return array<string,string>
	 */
	private static function base_groups( array $row ) {
		if ( ! isset( $row['base_groups'] ) || ! is_string( $row['base_groups'] ) || '' === $row['base_groups'] ) {
			return array();
		}
		$groups = json_decode( $row['base_groups'], true, 4 );
		$out    = array();
		foreach ( is_array( $groups ) ? $groups : array() as $group => $hash ) {
			if ( is_string( $group ) && is_string( $hash ) && 1 === preg_match( '/\A[0-9a-f]{64}\z/', $hash ) ) {
				$out[ sanitize_key( $group ) ] = $hash;
			}
		}
		return $out;
	}

	/**
	 * The sidecar of a live item with the IDs named as live's.
	 *
	 * @param array $refs Refs.
	 * @return array
	 */
	private static function live_refs( array $refs ) {
		$out = array();
		foreach ( $refs as $ref ) {
			$out[] = array(
				'path'    => $ref['path'],
				'kind'    => $ref['kind'],
				'live_id' => (int) $ref['id'],
				'uuid'    => (string) $ref['uuid'],
			);
		}
		return $out;
	}

	/**
	 * A list of names from the body.
	 *
	 * @param array  $body    Body.
	 * @param string $key     Field.
	 * @param int    $max     Most entries.
	 * @param string $pattern Pattern per entry.
	 * @return string[]|WP_Error
	 */
	private static function parse_names( array $body, $key, $max, $pattern ) {
		if ( ! isset( $body[ $key ] ) ) {
			return array();
		}
		if ( ! is_array( $body[ $key ] ) || ! wp_is_numeric_array( $body[ $key ] ) || count( $body[ $key ] ) > $max ) {
			return self::invalid();
		}
		$out = array();
		foreach ( $body[ $key ] as $name ) {
			if ( ! is_string( $name ) || 1 !== preg_match( $pattern, $name ) ) {
				return self::invalid();
			}
			$out[] = $name;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * A resolution result.
	 *
	 * @param string     $state found, missing or excluded.
	 * @param string     $kind  Kind.
	 * @param int|string $ref   Local ID or name.
	 * @return array
	 */
	private static function state( $state, $kind = '', $ref = 0 ) {
		return array(
			'state' => $state,
			'kind'  => $kind,
			'ref'   => $ref,
		);
	}

	/**
	 * The refusal of a malformed request body.
	 *
	 * @return WP_Error
	 */
	private static function invalid() {
		return new WP_Error( 'invalid_body', 'invalid_body', array( 'status' => 400 ) );
	}
}
