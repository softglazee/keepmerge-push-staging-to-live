<?php
/**
 * The text diff of one plan item.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

use SoftGlaze\SafePush\Source_Pairing;
use SoftGlaze\SafePush\Transfer\Remote_Client;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the field-by-field difference of one item for the diff drawer (blueprint F6: "each row
 * opens a text diff of the changed fields, content rendered as escaped text").
 *
 * This site's side is exported now; live's side comes from POST /remote/items/fetch (read-only).
 * Which fields differ is decided by the per-field hashes of both normal forms, so IDs and URLs
 * that are written differently on the two sites but name the same thing are not shown as
 * changes. Values travel as text with URL tokens in place; the screen shows each side with its
 * own address and never renders any of it as HTML.
 */
final class Plan_Diff {

	const FETCH_ROUTE = '/keepmerge/v1/remote/items/fetch';

	/**
	 * Longest text sent per side and field.
	 */
	const MAX_TEXT = 200000;

	/**
	 * The diff of one row of a plan.
	 *
	 * @param array      $plan   Plan.
	 * @param array      $row    Plan row.
	 * @param mixed|null $client Client with post() (tests); the pairing's own by default.
	 * @return array|WP_Error
	 */
	public static function build( array $plan, array $row, $client = null ) {
		$cursor  = $plan['cursor_json'];
		$pairing = Source_Pairing::find( isset( $cursor['pairing'] ) ? $cursor['pairing'] : '' );
		$p       = $row['payload'];
		$kind    = (string) $row['object_type'];

		$staging = null;
		if ( ! empty( $p['exists_here'] ) ) {
			$ref     = in_array( $kind, array( 'option', 'widgets' ), true ) ? ( 'option' === $kind ? substr( (string) $row['uuid'], 7 ) : 'widgets' ) : (int) $row['local_object_id'];
			$staging = Items::export( $kind, $ref, Export_Context::source( (int) $plan['pairing_id'] ) );
		}

		$live    = null;
		$on_live = isset( $p['live_state'] ) && 'found' === $p['live_state'];
		$api_row = null;
		$error   = null;
		if ( $on_live ) {
			if ( null === $client ) {
				$active = Source_Pairing::active_client( isset( $cursor['pairing'] ) ? $cursor['pairing'] : '' );
				if ( is_wp_error( $active ) ) {
					return $active;
				}
				$api_row = $active['row'];
				$client  = $active['client'];
			}
			$entry = array(
				'uuid' => (string) $row['uuid'],
				'kind' => $kind,
			);
			if ( ! in_array( $kind, array( 'option', 'widgets' ), true ) ) {
				$entry['type']    = isset( $p['type'] ) ? (string) $p['type'] : '';
				$entry['live_id'] = isset( $p['live_id'] ) ? (int) $p['live_id'] : 0;
			}
			$response = $client->post( self::FETCH_ROUTE, array( 'items' => array( $entry ) ), array( 'depth' => 64 ) );
			if ( is_wp_error( $response ) ) {
				$error = $response;
			} elseif ( isset( $response['data']['items'][0] ) && is_array( $response['data']['items'][0] ) && 'found' === ( isset( $response['data']['items'][0]['state'] ) ? $response['data']['items'][0]['state'] : '' ) ) {
				$live = $response['data']['items'][0];
			}
			if ( null !== $api_row && $client instanceof Remote_Client ) {
				Source_Pairing::after_call( $api_row, $client, $error );
			}
			if ( null !== $error ) {
				return $error;
			}
		}

		$s_fields = is_array( $staging ) && isset( $staging['fields'] ) ? (array) $staging['fields'] : array();
		$l_fields = is_array( $live ) && isset( $live['fields'] ) && is_array( $live['fields'] ) ? $live['fields'] : array();
		$s_data   = is_array( $staging ) ? (array) $staging['data'] : array();
		$l_data   = is_array( $live ) && isset( $live['data'] ) && is_array( $live['data'] ) ? $live['data'] : array();

		$paths = array_unique( array_merge( array_keys( $s_fields ), array_keys( $l_fields ) ) );
		sort( $paths, SORT_STRING );

		$fields = array();
		$equal  = 0;
		foreach ( $paths as $path ) {
			$path = (string) $path;
			if ( 1 !== preg_match( '/\A[A-Za-z0-9_.:\/-]{1,300}\z/', $path ) ) {
				continue;
			}
			$s_hash = isset( $s_fields[ $path ] ) ? (string) $s_fields[ $path ] : '';
			$l_hash = isset( $l_fields[ $path ] ) && is_string( $l_fields[ $path ] ) ? $l_fields[ $path ] : '';
			if ( '' !== $s_hash && '' !== $l_hash && hash_equals( $s_hash, $l_hash ) ) {
				++$equal;
				continue;
			}
			$s_value  = null === $staging ? null : self::at( $s_data, $path );
			$l_value  = null === $live ? null : self::at( $l_data, $path );
			$fields[] = array(
				'path'    => $path,
				'group'   => 0 === strpos( $path, 'groups.' ) ? (string) explode( '.', $path )[1] : '',
				'staging' => '' === $s_hash ? null : self::text( $s_value ),
				'live'    => '' === $l_hash ? null : self::text( $l_value ),
			);
		}

		return array(
			'uuid'         => (string) $row['uuid'],
			'title'        => isset( $p['title'] ) ? (string) $p['title'] : '',
			'kind'         => $kind,
			'type_label'   => isset( $p['type_label'] ) ? (string) $p['type_label'] : '',
			'class'        => (string) $row['status'],
			'staging'      => array(
				'exists'   => null !== $staging,
				'id'       => null === $staging ? 0 : (int) $staging['id'],
				'modified' => null === $staging ? '' : (string) $staging['modified_gmt'],
				'author'   => isset( $s_data['author']['login'] ) ? (string) $s_data['author']['login'] : '',
				'origin'   => untrailingslashit( home_url() ),
			),
			'live'         => array(
				'exists'   => null !== $live,
				'id'       => null === $live ? 0 : (int) $live['live_id'],
				'modified' => isset( $p['l_modified'] ) ? (string) $p['l_modified'] : '',
				'author'   => isset( $l_data['author']['login'] ) && is_string( $l_data['author']['login'] ) ? sanitize_user( $l_data['author']['login'], true ) : '',
				'origin'   => null === $pairing ? '' : untrailingslashit( (string) $pairing['remote_url'] ),
			),
			'fields'       => $fields,
			'equal_fields' => $equal,
			'gate'         => isset( $p['gate'] ) ? array_values( (array) $p['gate'] ) : array(),
		);
	}

	/**
	 * The value at a field path of a normal form (see Hasher::field_hashes()).
	 *
	 * @param array  $data Normal form.
	 * @param string $path Path.
	 * @return mixed
	 */
	private static function at( array $data, $path ) {
		if ( 0 === strpos( $path, 'meta.' ) ) {
			$key = substr( $path, 5 );
			return isset( $data['meta'][ $key ] ) ? $data['meta'][ $key ] : null;
		}
		if ( 0 === strpos( $path, 'groups.' ) ) {
			$parts = explode( '.', $path, 3 );
			if ( 3 !== count( $parts ) ) {
				return isset( $data['groups'][ $parts[1] ] ) ? $data['groups'][ $parts[1] ] : null;
			}
			$group = isset( $data['groups'][ $parts[1] ] ) && is_array( $data['groups'][ $parts[1] ] ) ? $data['groups'][ $parts[1] ] : array();
			if ( 0 === strpos( $parts[2], 'meta.' ) ) {
				$key = substr( $parts[2], 5 );
				return isset( $group['meta'][ $key ] ) ? $group['meta'][ $key ] : null;
			}
			return isset( $group[ $parts[2] ] ) ? $group[ $parts[2] ] : null;
		}
		return isset( $data[ $path ] ) ? $data[ $path ] : null;
	}

	/**
	 * A value as text: strings as they are, anything else as indented JSON (typed markers
	 * decoded where possible, URL tokens kept).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ) {
		if ( is_string( $value ) ) {
			$text = $value;
		} else {
			$plain = Normalizer::decode( $value );
			if ( is_wp_error( $plain ) ) {
				$plain = $value;
			}
			if ( is_object( $plain ) ) {
				$plain = get_object_vars( $plain );
			}
			$text = (string) wp_json_encode( $plain, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}
		$text = wp_check_invalid_utf8( $text, true );
		if ( strlen( $text ) > self::MAX_TEXT ) {
			$text = substr( $text, 0, self::MAX_TEXT ) . "\n…";
		}
		return $text;
	}
}
