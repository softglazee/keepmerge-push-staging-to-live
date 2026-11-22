<?php
/**
 * One staged item made ready to write on live.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply;

use SoftGlaze\SafePush\Receiver_User;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Normalizer;
use SoftGlaze\SafePush\Sync\Option_Policy;
use SoftGlaze\SafePush\Sync\Reference_Mapper;
use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Item_Stager;
use SoftGlaze\SafePush\Woo\Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds what an applier writes for one item (blueprint §8.2, §8.3): every reference of the
 * sidecar resolved to live's own ID (the item itself, another item of this push after the allocate
 * pass, or live's existing object, which must still be there and inside live's scope), the IDs
 * written at their paths by Reference_Mapper's rewrite mode, URLs of files live renamed rewritten,
 * then the typed values decoded with live's own address in place of the URL tokens. Nothing is
 * ever unserialized: Normalizer::decode() builds arrays, scalars and stdClass only.
 *
 * A reference that cannot be resolved makes the item skipped with its reason: the target was not
 * created on live (dependency_skipped), live's object is gone or out of scope
 * (reference_missing), or the sidecar names another ID than the value at its path
 * (reference_mismatch).
 */
final class Item_Context {

	/**
	 * Session.
	 *
	 * @var array
	 */
	public $session;

	/**
	 * Item row with payload, refs and pre-image decoded.
	 *
	 * @var array
	 */
	public $row;

	/**
	 * Kind.
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * Post type, taxonomy, option name or "widgets".
	 *
	 * @var string
	 */
	public $type;

	/**
	 * Live object ID the item is written to (0 for options and units).
	 *
	 * @var int
	 */
	public $live_id;

	/**
	 * Typed data as staged (staging IDs).
	 *
	 * @var array
	 */
	public $original = array();

	/**
	 * Typed data with live IDs.
	 *
	 * @var array
	 */
	public $typed = array();

	/**
	 * Decoded data: live IDs, live URLs, PHP values.
	 *
	 * @var array
	 */
	public $data = array();

	/**
	 * Reference map: path => array( kind, id, live ).
	 *
	 * @var array
	 */
	public $map = array();

	/**
	 * Warnings (codes) collected while building and writing.
	 *
	 * @var string[]
	 */
	public $warnings = array();

	/**
	 * Review decision of a held item ('' for an item that was not held).
	 *
	 * @var string
	 */
	public $review = '';

	/**
	 * Builds the context of one row.
	 *
	 * @param array $session Session.
	 * @param array $row     Row (decoded).
	 * @param array $index   uuid => array( id, kind, action, status, local_object_id ) of the push.
	 * @param array $renames    Source path => final path of files live renamed.
	 * @param bool  $allocating The allocate pass: references to items not created yet are left
	 *                          out (a placeholder needs only its name and, for a term or a
	 *                          variation, its parent, which is created first).
	 * @return Item_Context|WP_Error A WP_Error carries the skip reason.
	 */
	public static function build( array $session, array $row, array $index, array $renames, $allocating = false ) {
		$ctx           = new self();
		$ctx->session  = $session;
		$ctx->row      = $row;
		$ctx->kind     = (string) $row['object_type'];
		$ctx->type     = isset( $row['payload']['type'] ) ? (string) $row['payload']['type'] : '';
		$ctx->live_id  = (int) $row['local_object_id'];
		$ctx->review   = (string) $row['review'];
		$ctx->original = isset( $row['payload']['data'] ) && is_array( $row['payload']['data'] ) ? $row['payload']['data'] : array();

		$map = $ctx->resolve_map( is_array( $row['refs'] ) ? $row['refs'] : array(), $index, $allocating );
		if ( is_wp_error( $map ) ) {
			return $map;
		}
		$ctx->map = $map;

		$mapper = new Reference_Mapper( array( Identity::class, 'get' ) );
		$result = $mapper->rewrite( $ctx->kind, $ctx->original, $map );
		if ( ! empty( $result['mismatches'] ) ) {
			return new WP_Error( 'reference_mismatch', 'reference_mismatch' );
		}
		if ( $result['zeroed'] > 0 ) {
			$ctx->warnings[] = 'unmapped_reference';
		}
		$ctx->typed = $result['data'];

		if ( ! empty( $renames ) ) {
			$ctx->typed = self::rename_urls( $ctx->typed, $renames );
		}

		$decoded = Normalizer::decode( $ctx->typed, Normalizer::local_origins() );
		if ( is_wp_error( $decoded ) || ! is_array( $decoded ) ) {
			return new WP_Error( 'invalid_value', 'invalid_value' );
		}
		$ctx->data = $decoded;
		return $ctx;
	}

	/**
	 * Path => live ID of every reference of the sidecar.
	 *
	 * @param array $refs       Sidecar (with res and live_id from staging).
	 * @param array $index      Rows of the push by UUID.
	 * @param bool  $allocating Whether this is the allocate pass.
	 * @return array|WP_Error
	 */
	private function resolve_map( array $refs, array $index, $allocating ) {
		$map = array();
		foreach ( $refs as $ref ) {
			if ( ! is_array( $ref ) || ! isset( $ref['path'], $ref['kind'], $ref['id'], $ref['uuid'] ) ) {
				return new WP_Error( 'reference_mismatch', 'reference_mismatch' );
			}
			$res  = isset( $ref['res'] ) ? (string) $ref['res'] : '';
			$live = 0;
			switch ( $res ) {
				case 'self':
					$live = $this->live_id;
					break;

				case 'push':
					$target = isset( $index[ $ref['uuid'] ] ) ? $index[ $ref['uuid'] ] : null;
					if ( $allocating && null !== $target && (int) $target['local_object_id'] < 1 && ! in_array( $target['status'], array( 'refused', 'failed', 'skipped' ), true ) ) {
						continue 2;
					}
					if ( null === $target || (int) $target['local_object_id'] < 1 || in_array( $target['status'], array( 'refused', 'failed' ), true ) ) {
						return new WP_Error( 'dependency_skipped', 'dependency_skipped' );
					}
					if ( 'skipped' === $target['status'] && in_array( $target['action'], array( 'create', 'recreate', 'created' ), true ) ) {
						// Created and then skipped never happens (skips come before allocation), but a
						// placeholder that was not written is not a real object either.
						return new WP_Error( 'dependency_skipped', 'dependency_skipped' );
					}
					$live = (int) $target['local_object_id'];
					break;

				case 'live':
					$live  = isset( $ref['live_id'] ) ? (int) $ref['live_id'] : 0;
					$space = isset( Reference_Mapper::KIND_SPACE[ $ref['kind'] ] ) ? Reference_Mapper::KIND_SPACE[ $ref['kind'] ] : 'post';
					if ( $live < 1 || ! Item_Stager::object_fits( (string) $ref['kind'], $space, $live ) ) {
						return new WP_Error( 'reference_missing', 'reference_missing' );
					}
					break;

				case 'unit':
					// A menu item's parent inside its menu: the menu applier resolves it.
					continue 2;

				default:
					return new WP_Error( 'reference_missing', 'reference_missing' );
			}
			$map[ (string) $ref['path'] ] = array(
				'kind' => (string) $ref['kind'],
				'id'   => (int) $ref['id'],
				'live' => $live,
			);
		}
		return $map;
	}

	/**
	 * Rewrites the URLs of files that live stored under a new name (blueprint §9 step 3) in every
	 * string of the typed data, where URLs are still tokens: plain, JSON-escaped, URL-encoded and
	 * protocol-relative forms of this site's uploads URL, followed by the old path.
	 *
	 * @param mixed $value   Typed value.
	 * @param array $renames Source path => final path.
	 * @param int   $depth   Depth.
	 * @return mixed
	 */
	public static function rename_urls( $value, array $renames, $depth = 0 ) {
		if ( $depth > Normalizer::MAX_DEPTH ) {
			return $value;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				if ( Normalizer::MARK === $key ) {
					continue;
				}
				$value[ $key ] = self::rename_urls( $item, $renames, $depth + 1 );
			}
			return $value;
		}
		if ( ! is_string( $value ) || false === strpos( $value, Normalizer::TOKEN_LITERAL ) ) {
			return $value;
		}
		foreach ( self::rename_pairs( $renames ) as $pair ) {
			if ( false === strpos( $value, $pair[0] ) ) {
				continue;
			}
			$value = (string) preg_replace( '~' . preg_quote( $pair[0], '~' ) . '(?![A-Za-z0-9._-])~', str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $pair[1] ), $value );
		}
		return $value;
	}

	/**
	 * Search and replacement texts of the renamed files, longest first.
	 *
	 * @param array $renames Source path => final path.
	 * @return array[]
	 */
	private static function rename_pairs( array $renames ) {
		static $cache = array();
		$key          = md5( (string) wp_json_encode( $renames ) );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$uploads = wp_upload_dir( null, false );
		$home    = untrailingslashit( home_url() );
		$baseurl = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
		$rel     = '';
		foreach ( array( $home, set_url_scheme( $home, 'http' ), set_url_scheme( $home, 'https' ) ) as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $baseurl, $prefix ) ) {
				$rel = substr( $baseurl, strlen( $prefix ) );
				break;
			}
		}
		$pairs = array();
		if ( '' === $rel ) {
			// Uploads on another host (a CDN): not rewritten, as blueprint §8.2 says.
			$cache[ $key ] = $pairs;
			return $pairs;
		}
		foreach ( $renames as $from => $to ) {
			foreach ( array( 'home', 'site' ) as $name ) {
				$plain   = $rel . '/' . $from;
				$final   = $rel . '/' . $to;
				$pairs[] = array( '{{sgsp:' . $name . '}}' . $plain, '{{sgsp:' . $name . '}}' . $final );
				$pairs[] = array( '{{sgsp:' . $name . ':p}}' . $plain, '{{sgsp:' . $name . ':p}}' . $final );
				$pairs[] = array( '{{sgsp:' . $name . ':j}}' . str_replace( '/', '\/', $plain ), '{{sgsp:' . $name . ':j}}' . str_replace( '/', '\/', $final ) );
				$pairs[] = array( '{{sgsp:' . $name . ':u}}' . rawurlencode( $plain ), '{{sgsp:' . $name . ':u}}' . rawurlencode( $final ) );
			}
		}
		usort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b[0] ) - strlen( $a[0] );
			}
		);
		$cache[ $key ] = $pairs;
		return $pairs;
	}

	/**
	 * Whether a path of the item's data was rewritten to a live ID.
	 *
	 * @param string $path Path.
	 * @return bool
	 */
	public function mapped( $path ) {
		return isset( $this->map[ (string) $path ] );
	}

	/**
	 * A positive ID list from the decoded data (zeros left by unmapped entries removed).
	 *
	 * @param mixed $ids List.
	 * @return int[]
	 */
	public static function id_list( $ids ) {
		$out = array();
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Taxonomies whose assignments the item sets on live: the ones the source names in its scope
	 * (or, without a scope, the ones the item carries) that live includes for the type.
	 *
	 * @param string $object_type Post type.
	 * @param array  $terms       Terms of the item: taxonomy => IDs.
	 * @return string[]
	 */
	public function taxonomies( $object_type, array $terms ) {
		$named = isset( $this->row['payload']['scope']['taxonomies'] ) && is_array( $this->row['payload']['scope']['taxonomies'] ) ? $this->row['payload']['scope']['taxonomies'] : array_keys( $terms );
		$out   = array();
		foreach ( array_unique( array_merge( $named, array_keys( $terms ) ) ) as $taxonomy ) {
			$taxonomy = (string) $taxonomy;
			if ( in_array( $taxonomy, Scope::taxonomies(), true ) && is_object_in_taxonomy( (string) $object_type, $taxonomy ) ) {
				$out[] = $taxonomy;
			}
		}
		return $out;
	}

	/**
	 * Whether a meta key live has but the item lacks may be deleted: not excluded here, not one the
	 * source excludes itself, not Keepmerge's own.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public function may_delete_meta( $key ) {
		$key = (string) $key;
		if ( Scope::meta_excluded( $key ) || 0 === strpos( $key, '_softglaze_sp_' ) || Guard::is_volatile_term_meta( $key ) || ( 'product' === $this->kind && Guard::is_internal_product_meta( $key ) ) ) {
			// Keys an exporter leaves out (WooCommerce counters and the meta its getters cover)
			// are not the item's to delete.
			return false;
		}
		$skip = isset( $this->row['payload']['scope']['meta_skip'] ) && is_array( $this->row['payload']['scope']['meta_skip'] ) ? $this->row['payload']['scope']['meta_skip'] : null;
		if ( null === $skip ) {
			// Without the source's own exclusions, live keeps keys the item does not carry.
			return false;
		}
		foreach ( $skip as $entry ) {
			if ( Option_Policy::matches( $key, (string) $entry ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The live user an item's author maps to (blueprint §6.8): by login, then by email, then the
	 * pairing's default author, then the first administrator. Never the receiver user.
	 *
	 * @param mixed $author Author entry: login and email.
	 * @return int
	 */
	public function author_id( $author ) {
		$login = is_array( $author ) && isset( $author['login'] ) ? (string) $author['login'] : '';
		$email = is_array( $author ) && isset( $author['email'] ) ? (string) $author['email'] : '';

		$candidates = array();
		if ( '' !== $login ) {
			$candidates[] = get_user_by( 'login', $login );
		}
		if ( '' !== $email && is_email( $email ) ) {
			$candidates[] = get_user_by( 'email', $email );
		}
		$candidates[] = get_userdata( $this->default_author() );
		foreach ( $candidates as $user ) {
			if ( $user instanceof \WP_User && ! Receiver_User::is_receiver( $user ) ) {
				return (int) $user->ID;
			}
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 5,
			)
		);
		foreach ( (array) $admins as $id ) {
			$user = get_userdata( (int) $id );
			if ( $user instanceof \WP_User && ! Receiver_User::is_receiver( $user ) ) {
				return (int) $user->ID;
			}
		}
		return 0;
	}

	/**
	 * The pairing's default author on live.
	 *
	 * @return int
	 */
	private function default_author() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; one non-secret column.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT default_author_id FROM %i WHERE id = %d', Tables::table_name( 'pairings' ), absint( $this->session['pairing_id'] ) ) );
	}
}
