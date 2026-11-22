<?php
/**
 * Posts, pages and custom post types on live.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply\Appliers;

use SoftGlaze\SafePush\Apply\Item_Context;
use SoftGlaze\SafePush\Transfer\Item_Stager;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes an item of kind "post" (blueprint §6.2, §10.3): wp_insert_post() / wp_update_post()
 * without tax_input, term assignments with wp_set_object_terms(), the featured image as
 * _thumbnail_id, the page template only when live's theme has it, the post format, and the meta
 * key by key. The author maps by login, then email, then the pairing's default author.
 */
final class Post extends Base {

	/**
	 * Allocate pass: a draft with the item's UUID.
	 *
	 * @param Item_Context $ctx Context.
	 * @return int|WP_Error
	 */
	public static function allocate( Item_Context $ctx ) {
		return self::placeholder_post( $ctx, $ctx->type, self::str( $ctx->data, 'post_title' ) );
	}

	/**
	 * Write pass.
	 *
	 * @param Item_Context $ctx Context.
	 * @return array|WP_Error
	 */
	public static function write( Item_Context $ctx ) {
		$data     = $ctx->data;
		$type     = $ctx->type;
		$warnings = array();

		$postarr = self::post_fields( $ctx, $data, $type );
		$id      = self::save_post( $postarr );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( array_key_exists( 'page_template', $data ) ) {
			$template = self::str( $data, 'page_template' );
			if ( '' === $template ) {
				delete_post_meta( $id, '_wp_page_template' );
			} elseif ( Item_Stager::template_exists( $template, $type ) ) {
				update_post_meta( $id, '_wp_page_template', wp_slash( $template ) );
			} else {
				// The template left live's theme since the item was staged: the field is not written.
				$warnings[] = 'page_template_dropped';
			}
		}

		if ( array_key_exists( 'featured_image', $data ) ) {
			$thumb = self::int( $data, 'featured_image' );
			if ( $thumb > 0 ) {
				update_post_meta( $id, '_thumbnail_id', $thumb );
			} else {
				delete_post_meta( $id, '_thumbnail_id' );
			}
		}

		if ( isset( $data['format'] ) && post_type_supports( $type, 'post-formats' ) ) {
			$format = self::str( $data, 'format' );
			set_post_format( $id, 'standard' === $format ? '' : $format );
		}

		$terms = self::set_terms( $id, $type, isset( $data['terms'] ) && is_array( $data['terms'] ) ? $data['terms'] : array(), $ctx );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		self::replace_meta( 'post', $id, isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array(), $ctx, array( '_wp_page_template', '_thumbnail_id' ) );

		return array(
			'id'       => $id,
			'warnings' => $warnings,
		);
	}

	/**
	 * The post fields of a post-like item for wp_insert_post() / wp_update_post().
	 *
	 * @param Item_Context $ctx  Context.
	 * @param array        $data Decoded data.
	 * @param string       $type Post type.
	 * @return array
	 */
	public static function post_fields( Item_Context $ctx, array $data, $type ) {
		$postarr = array(
			'post_type'   => (string) $type,
			'post_author' => $ctx->author_id( isset( $data['author'] ) ? $data['author'] : array() ),
			// Keeps the dates given here for drafts too (wp_update_post() clears a draft's date
			// otherwise).
			'edit_date'   => true,
		);
		if ( $ctx->live_id > 0 ) {
			$postarr['ID'] = $ctx->live_id;
		}
		foreach ( array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_password', 'comment_status', 'ping_status' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$postarr[ $field ] = self::str( $data, $field );
			}
		}
		foreach ( array( 'menu_order', 'post_parent' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$postarr[ $field ] = self::int( $data, $field );
			}
		}

		$gmt = self::str( $data, 'post_date_gmt' );
		if ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) {
			$postarr['post_date_gmt'] = $gmt;
			$postarr['post_date']     = self::local_date( $gmt );
		} elseif ( '' !== self::str( $data, 'post_date' ) ) {
			// A draft without a fixed date: only its local date exists.
			$postarr['post_date']     = self::str( $data, 'post_date' );
			$postarr['post_date_gmt'] = '0000-00-00 00:00:00';
		}
		return $postarr;
	}
}
