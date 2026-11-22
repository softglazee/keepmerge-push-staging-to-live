<?php
/**
 * Shared writing code of the appliers.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply\Appliers;

use SoftGlaze\SafePush\Apply\Item_Context;
use SoftGlaze\SafePush\Sync\Identity;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helpers for writing through WordPress APIs on live (blueprint §10.3). Values passed to core's
 * write functions are slashed first, because those functions unslash what they receive. Nothing
 * here removes a filter or changes the current user: whoever runs the apply (the receiver for a
 * push from the source, a live administrator for a reviewed push) writes with their own filters.
 */
abstract class Base {

	/**
	 * Meta replaced key by key: every key the item carries gets exactly its values; a key live has
	 * that the item lacks is deleted only when Item_Context allows it (not excluded here, not
	 * excluded by the source itself, not Keepmerge's own).
	 *
	 * @param string       $type Meta type: 'post' or 'term'.
	 * @param int          $id   Object ID.
	 * @param array        $meta Key => list of decoded values.
	 * @param Item_Context $ctx  Context.
	 * @param string[]     $keep Keys this applier writes itself (never touched here).
	 * @return void
	 */
	protected static function replace_meta( $type, $id, array $meta, Item_Context $ctx, array $keep = array() ) {
		$id      = (int) $id;
		$current = 'term' === $type ? get_term_meta( $id ) : get_post_meta( $id );
		$current = is_array( $current ) ? $current : array();

		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( '' === $key || in_array( $key, $keep, true ) || 0 === strpos( $key, '_softglaze_sp_' ) ) {
				continue;
			}
			$values = is_array( $values ) ? array_values( $values ) : array( $values );
			$now    = 'term' === $type ? get_term_meta( $id, $key, false ) : get_post_meta( $id, $key, false );
			if ( is_array( $now ) && self::same_values( $now, $values ) ) {
				continue;
			}
			if ( 'term' === $type ) {
				delete_term_meta( $id, $key );
				foreach ( $values as $value ) {
					add_term_meta( $id, wp_slash( $key ), self::slash( $value ) );
				}
			} else {
				delete_post_meta( $id, $key );
				foreach ( $values as $value ) {
					add_post_meta( $id, wp_slash( $key ), self::slash( $value ) );
				}
			}
		}

		foreach ( array_keys( $current ) as $key ) {
			$key = (string) $key;
			if ( array_key_exists( $key, $meta ) || in_array( $key, $keep, true ) || ! $ctx->may_delete_meta( $key ) ) {
				continue;
			}
			if ( 'term' === $type ) {
				delete_term_meta( $id, $key );
			} else {
				delete_post_meta( $id, $key );
			}
		}
	}

	/**
	 * Whether two meta value lists hold the same values in the same order.
	 *
	 * @param array $a First.
	 * @param array $b Second.
	 * @return bool
	 */
	protected static function same_values( array $a, array $b ) {
		if ( count( $a ) !== count( $b ) ) {
			return false;
		}
		foreach ( array_values( $a ) as $i => $value ) {
			if ( maybe_serialize( $value ) !== maybe_serialize( $b[ $i ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Term assignments of a post set with wp_set_object_terms() (never tax_input, which needs
	 * assign_terms): each taxonomy the item manages gets exactly its terms.
	 *
	 * @param int          $post_id   Post ID.
	 * @param string       $post_type Post type.
	 * @param array        $terms     Taxonomy => live term IDs.
	 * @param Item_Context $ctx       Context.
	 * @return true|WP_Error
	 */
	protected static function set_terms( $post_id, $post_type, array $terms, Item_Context $ctx ) {
		foreach ( $ctx->taxonomies( $post_type, $terms ) as $taxonomy ) {
			$ids    = Item_Context::id_list( isset( $terms[ $taxonomy ] ) ? $terms[ $taxonomy ] : array() );
			$result = wp_set_object_terms( (int) $post_id, $ids, $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * A post written with wp_insert_post() / wp_update_post(), errors returned.
	 *
	 * @param array $postarr Post fields (unslashed).
	 * @return int|WP_Error
	 */
	protected static function save_post( array $postarr ) {
		$postarr = wp_slash( $postarr );
		$result  = empty( $postarr['ID'] ) ? wp_insert_post( $postarr, true ) : wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_int( $result ) || $result < 1 ) {
			return new WP_Error( 'write_failed', 'write_failed' );
		}
		return $result;
	}

	/**
	 * A draft placeholder of a post-like item (allocate pass): no terms, no meta but the UUID.
	 *
	 * @param Item_Context $ctx       Context.
	 * @param string       $post_type Post type.
	 * @param string       $title     Title.
	 * @return int|WP_Error
	 */
	protected static function placeholder_post( Item_Context $ctx, $post_type, $title ) {
		$id = self::save_post(
			array(
				'post_type'   => (string) $post_type,
				'post_status' => 'attachment' === $post_type ? 'inherit' : 'draft',
				'post_title'  => (string) $title,
				'post_author' => $ctx->author_id( isset( $ctx->data['author'] ) ? $ctx->data['author'] : array() ),
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Identity::stamp( 'post', $id, (string) $ctx->row['uuid'] );
		return $id;
	}

	/**
	 * A value slashed the way core's meta functions unslash it (wp_unslash() also walks into
	 * stdClass, which wp_slash() leaves alone, so objects are copied and slashed here).
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	public static function slash( $value, $depth = 0 ) {
		if ( $depth > 64 ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return addslashes( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = self::slash( $item, $depth + 1 );
			}
			return $value;
		}
		if ( $value instanceof \stdClass ) {
			$copy = new \stdClass();
			foreach ( get_object_vars( $value ) as $key => $item ) {
				$copy->$key = self::slash( $item, $depth + 1 );
			}
			return $copy;
		}
		return $value;
	}

	/**
	 * A GMT date in the site's time as post_date expects it.
	 *
	 * @param string $gmt GMT date.
	 * @return string
	 */
	protected static function local_date( $gmt ) {
		return get_date_from_gmt( (string) $gmt );
	}

	/**
	 * A string field of the decoded data.
	 *
	 * @param array  $data    Data.
	 * @param string $name    Field.
	 * @param string $fallback Value when the field is missing.
	 * @return string
	 */
	protected static function str( array $data, $name, $fallback = '' ) {
		return isset( $data[ $name ] ) && is_scalar( $data[ $name ] ) ? (string) $data[ $name ] : (string) $fallback;
	}

	/**
	 * An integer field of the decoded data.
	 *
	 * @param array  $data Data.
	 * @param string $name Field.
	 * @return int
	 */
	protected static function int( array $data, $name ) {
		return isset( $data[ $name ] ) && is_numeric( $data[ $name ] ) ? (int) $data[ $name ] : 0;
	}
}
