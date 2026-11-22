<?php
/**
 * Classic menus on live, as one unit.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply\Appliers;

use SoftGlaze\SafePush\Apply\Item_Context;
use SoftGlaze\SafePush\Sync\Exporters\Menus;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Scope;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces a menu unit (blueprint §6.6: items are not merged one by one in v1). The menu term is
 * updated; its item posts are reused in order and written with the fields core stores for a menu
 * item; new items are created; items beyond the staged list go to the Trash (not deleted, so a
 * rollback can bring them back). Parent items are resolved inside the unit through the source's
 * item IDs (the envelope's item_ids). Locations travel in the theme mods option.
 */
final class Menu extends Base {

	/**
	 * Allocate pass: the menu term with its name and slug.
	 *
	 * @param Item_Context $ctx Context.
	 * @return int|WP_Error
	 */
	public static function allocate( Item_Context $ctx ) {
		if ( ! Scope::menus_included() ) {
			return new WP_Error( 'type_not_included', 'type_not_included' );
		}
		$result = wp_insert_term(
			wp_slash( self::str( $ctx->data, 'name' ) ),
			'nav_menu',
			array( 'slug' => self::str( $ctx->data, 'slug' ) )
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
		$menu = get_term( $ctx->live_id, 'nav_menu' );
		if ( ! $menu instanceof \WP_Term ) {
			return new WP_Error( 'write_failed', 'write_failed' );
		}
		$menu_id = (int) $menu->term_id;
		$result  = wp_update_term(
			$menu_id,
			'nav_menu',
			array(
				'name'        => wp_slash( self::str( $ctx->data, 'name' ) ),
				'slug'        => self::str( $ctx->data, 'slug' ),
				'description' => wp_slash( self::str( $ctx->data, 'description' ) ),
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items    = isset( $ctx->data['items'] ) && is_array( $ctx->data['items'] ) ? array_values( $ctx->data['items'] ) : array();
		$original = isset( $ctx->original['items'] ) && is_array( $ctx->original['items'] ) ? array_values( $ctx->original['items'] ) : array();
		$ids      = isset( $ctx->row['payload']['item_ids'] ) && is_array( $ctx->row['payload']['item_ids'] ) ? array_values( $ctx->row['payload']['item_ids'] ) : array();
		$existing = Menus::item_posts( $menu_id );
		$new_ids  = array();
		$warnings = array();

		foreach ( $items as $i => $item ) {
			$item    = is_array( $item ) ? $item : array();
			$item_id = isset( $existing[ $i ] ) ? (int) $existing[ $i ]->ID : 0;
			$postarr = array(
				'post_type'    => 'nav_menu_item',
				'post_status'  => 'draft' === self::str( $item, 'status' ) ? 'draft' : 'publish',
				'post_title'   => self::str( $item, 'title' ),
				'post_excerpt' => self::str( $item, 'attr_title' ),
				'post_content' => self::str( $item, 'description' ),
				'menu_order'   => self::int( $item, 'position' ),
				'post_author'  => $ctx->author_id( array() ),
				'ping_status'  => 'closed',
			);
			if ( $item_id > 0 ) {
				$postarr['ID'] = $item_id;
			}
			$saved = self::save_post( $postarr );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			$item_id       = (int) $saved;
			$new_ids[ $i ] = $item_id;

			$terms = wp_set_object_terms( $item_id, array( $menu_id ), 'nav_menu', false );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}

			$type    = self::str( $item, 'type' );
			$classes = isset( $item['classes'] ) && is_array( $item['classes'] ) ? array_values( array_map( 'strval', $item['classes'] ) ) : array();
			update_post_meta( $item_id, '_menu_item_type', wp_slash( $type ) );
			update_post_meta( $item_id, '_menu_item_object', wp_slash( self::str( $item, 'object' ) ) );
			update_post_meta( $item_id, '_menu_item_object_id', (string) self::int( $item, 'object_id' ) );
			update_post_meta( $item_id, '_menu_item_url', wp_slash( self::str( $item, 'url' ) ) );
			update_post_meta( $item_id, '_menu_item_classes', self::slash( $classes ) );
			update_post_meta( $item_id, '_menu_item_target', wp_slash( self::str( $item, 'target' ) ) );
			update_post_meta( $item_id, '_menu_item_xfn', wp_slash( self::str( $item, 'xfn' ) ) );

			self::replace_meta( 'post', $item_id, isset( $item['meta'] ) && is_array( $item['meta'] ) ? $item['meta'] : array(), $ctx, Menus::ITEM_FIELDS );
		}

		// Parents inside the unit: the staging parent ID names an item of this menu by position.
		foreach ( $items as $i => $item ) {
			$parent = isset( $original[ $i ]['parent'] ) && is_int( $original[ $i ]['parent'] ) ? $original[ $i ]['parent'] : 0;
			$live   = 0;
			if ( $parent > 0 ) {
				$at = array_search( $parent, $ids, true );
				if ( false !== $at && isset( $new_ids[ $at ] ) ) {
					$live = (int) $new_ids[ $at ];
				} else {
					$warnings[] = 'unmapped_reference';
				}
			}
			update_post_meta( $new_ids[ $i ], '_menu_item_menu_item_parent', (string) $live );
		}

		// Items live has beyond the staged list leave the menu to the Trash.
		$count = count( $existing );
		for ( $i = count( $items ); $i < $count; $i++ ) {
			wp_trash_post( (int) $existing[ $i ]->ID );
		}

		return array(
			'id'       => $menu_id,
			'warnings' => array_values( array_unique( $warnings ) ),
		);
	}
}
