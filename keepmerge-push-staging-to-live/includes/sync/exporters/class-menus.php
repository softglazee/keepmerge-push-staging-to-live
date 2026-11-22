<?php
/**
 * Classic menus as one unit.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync\Exporters;

use SoftGlaze\SafePush\Sync\Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A menu unit: the nav_menu term and its ordered items (type, object, object ID or URL, title,
 * classes, target, parent item, position, item meta). Menu locations travel in the theme mods
 * option.
 */
final class Menus extends Exporter {

	/**
	 * Item meta carried as fields.
	 */
	const ITEM_FIELDS = array( '_menu_item_type', '_menu_item_object', '_menu_item_object_id', '_menu_item_url', '_menu_item_classes', '_menu_item_target', '_menu_item_xfn', '_menu_item_menu_item_parent' );

	/**
	 * Exports one menu.
	 *
	 * @param int|string $id nav_menu term ID.
	 * @return array|null
	 */
	public function export( $id ) {
		$menu = get_term( absint( $id ), 'nav_menu' );
		if ( ! Scope::allows_term( $menu, 'menu' ) ) {
			return null;
		}

		$items = array();
		foreach ( self::item_posts( $menu->term_id ) as $post ) {
			$classes = get_post_meta( $post->ID, '_menu_item_classes', true );
			$items[] = array(
				'type'        => (string) get_post_meta( $post->ID, '_menu_item_type', true ),
				'object'      => (string) get_post_meta( $post->ID, '_menu_item_object', true ),
				'object_id'   => (int) get_post_meta( $post->ID, '_menu_item_object_id', true ),
				'url'         => (string) get_post_meta( $post->ID, '_menu_item_url', true ),
				'title'       => $post->post_title,
				'attr_title'  => $post->post_excerpt,
				'description' => $post->post_content,
				'classes'     => is_array( $classes ) ? array_values( array_map( 'strval', $classes ) ) : array(),
				'target'      => (string) get_post_meta( $post->ID, '_menu_item_target', true ),
				'xfn'         => (string) get_post_meta( $post->ID, '_menu_item_xfn', true ),
				'parent'      => (int) get_post_meta( $post->ID, '_menu_item_menu_item_parent', true ),
				'position'    => (int) $post->menu_order,
				'status'      => $post->post_status,
				'meta'        => $this->post_meta( $post->ID, self::ITEM_FIELDS ),
			);
		}

		$raw = array(
			'name'        => $menu->name,
			'slug'        => $menu->slug,
			'description' => $menu->description,
			'items'       => $items,
		);

		return $this->item(
			'menu',
			'nav_menu',
			$menu->term_id,
			$this->ctx->uuid( 'term', $menu->term_id ),
			$raw,
			array( 'title' => (string) $menu->name )
		);
	}

	/**
	 * A menu's items in order (position, then ID).
	 *
	 * @param int $menu_id nav_menu term ID.
	 * @return \WP_Post[]
	 */
	public static function item_posts( $menu_id ) {
		$ids = get_objects_in_term( (int) $menu_id, 'nav_menu' );
		if ( is_wp_error( $ids ) || empty( $ids ) ) {
			return array();
		}
		$posts = array();
		foreach ( $ids as $item_id ) {
			$post = get_post( (int) $item_id );
			if ( $post instanceof \WP_Post && 'nav_menu_item' === $post->post_type && in_array( $post->post_status, array( 'publish', 'draft' ), true ) ) {
				$posts[] = $post;
			}
		}
		usort(
			$posts,
			static function ( $a, $b ) {
				if ( (int) $a->menu_order === (int) $b->menu_order ) {
					return (int) $a->ID - (int) $b->ID;
				}
				return (int) $a->menu_order - (int) $b->menu_order;
			}
		);
		return $posts;
	}
}
