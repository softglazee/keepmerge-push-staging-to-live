<?php
/**
 * Terms on live.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply\Appliers;

use SoftGlaze\SafePush\Apply\Item_Context;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Scope;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a term with wp_insert_term() / wp_update_term(), so core's term filters apply, then its
 * meta key by key.
 */
final class Term extends Base {

	/**
	 * Allocate pass: the term with its parent, so two new terms with one name under different
	 * parents do not collide.
	 *
	 * @param Item_Context $ctx Context.
	 * @return int|WP_Error
	 */
	public static function allocate( Item_Context $ctx ) {
		$taxonomy = $ctx->type;
		if ( ! in_array( $taxonomy, Scope::taxonomies(), true ) ) {
			return new WP_Error( 'type_not_included', 'type_not_included' );
		}
		$result = wp_insert_term(
			wp_slash( self::str( $ctx->data, 'name' ) ),
			$taxonomy,
			array(
				'slug'   => self::str( $ctx->data, 'slug' ),
				'parent' => is_taxonomy_hierarchical( $taxonomy ) ? self::int( $ctx->data, 'parent' ) : 0,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$id = (int) $result['term_id'];
		Identity::stamp( 'term', $id, (string) $ctx->row['uuid'] );
		return $id;
	}

	/**
	 * Write pass.
	 *
	 * @param Item_Context $ctx Context.
	 * @return array|WP_Error
	 */
	public static function write( Item_Context $ctx ) {
		$taxonomy = $ctx->type;
		$term     = get_term( $ctx->live_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return new WP_Error( 'write_failed', 'write_failed' );
		}
		$result = wp_update_term(
			(int) $term->term_id,
			$taxonomy,
			array(
				'name'        => wp_slash( self::str( $ctx->data, 'name' ) ),
				'slug'        => self::str( $ctx->data, 'slug' ),
				'description' => wp_slash( self::str( $ctx->data, 'description' ) ),
				'parent'      => is_taxonomy_hierarchical( $taxonomy ) ? self::int( $ctx->data, 'parent' ) : 0,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::replace_meta( 'term', (int) $term->term_id, isset( $ctx->data['meta'] ) && is_array( $ctx->data['meta'] ) ? $ctx->data['meta'] : array(), $ctx );
		return array( 'id' => (int) $term->term_id );
	}
}
