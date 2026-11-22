<?php
/**
 * Templates, template parts, global styles, patterns and navigation on live.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply\Appliers;

use SoftGlaze\SafePush\Apply\Item_Context;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes site editor items as core stores them (blueprint §6.2, storage checked in core 7.1.5):
 * the post, then the non-public taxonomies set with wp_set_object_terms() (wp_theme for templates,
 * parts and global styles; wp_template_part_area for parts), the origin meta, a pattern's
 * wp_pattern_sync_status meta and wp_pattern_category terms. A template, part or global styles of a
 * theme live does not have is skipped (theme_missing). Global styles written by the receiver go
 * through core's wp_filter_global_styles_post(), which removes custom CSS and unsafe properties;
 * those were held for review on live first.
 */
final class Site_Editor extends Base {

	const THEMED = array( 'template', 'template_part', 'global_styles' );

	/**
	 * Allocate pass: a draft without any theme term (a draft template with a wp_theme term would
	 * already take over the theme's template).
	 *
	 * @param Item_Context $ctx Context.
	 * @return int|WP_Error
	 */
	public static function allocate( Item_Context $ctx ) {
		$title = self::str( $ctx->data, 'post_title' );
		return self::placeholder_post( $ctx, $ctx->type, '' === $title ? self::str( $ctx->data, 'post_name' ) : $title );
	}

	/**
	 * Write pass.
	 *
	 * @param Item_Context $ctx Context.
	 * @return array|WP_Error
	 */
	public static function write( Item_Context $ctx ) {
		$data  = $ctx->data;
		$kind  = $ctx->kind;
		$theme = self::str( $data, 'theme' );

		if ( in_array( $kind, self::THEMED, true ) ) {
			if ( '' === $theme || ! wp_get_theme( $theme )->exists() ) {
				return new WP_Error( 'theme_missing', 'theme_missing' );
			}
			// The theme term first: template slugs are made unique per theme from this term.
			$set = wp_set_object_terms( $ctx->live_id, array( $theme ), 'wp_theme', false );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}

		$postarr = array(
			'ID'          => $ctx->live_id,
			'post_type'   => $ctx->type,
			'post_name'   => self::str( $data, 'post_name' ),
			'post_title'  => self::str( $data, 'post_title' ),
			'post_status' => self::str( $data, 'post_status', 'publish' ),
			'post_author' => $ctx->author_id( array() ),
		);
		if ( array_key_exists( 'post_content', $data ) ) {
			$postarr['post_content'] = self::str( $data, 'post_content' );
		}
		if ( array_key_exists( 'post_excerpt', $data ) ) {
			$postarr['post_excerpt'] = self::str( $data, 'post_excerpt' );
		}
		$id = self::save_post( $postarr );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		if ( 'template_part' === $kind ) {
			$area = self::str( $data, 'area' );
			$set  = wp_set_object_terms( $id, '' === $area ? array() : array( $area ), 'wp_template_part_area', false );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}
		if ( in_array( $kind, array( 'template', 'template_part' ), true ) ) {
			$origin = self::str( $data, 'origin' );
			if ( '' === $origin ) {
				delete_post_meta( $id, 'origin' );
			} else {
				update_post_meta( $id, 'origin', wp_slash( $origin ) );
			}
		}
		if ( 'pattern' === $kind ) {
			$sync = self::str( $data, 'sync_status' );
			if ( '' === $sync ) {
				delete_post_meta( $id, 'wp_pattern_sync_status' );
			} else {
				update_post_meta( $id, 'wp_pattern_sync_status', wp_slash( $sync ) );
			}
			$terms = self::set_terms( $id, $ctx->type, isset( $data['terms'] ) && is_array( $data['terms'] ) ? $data['terms'] : array(), $ctx );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}
		}

		return array( 'id' => $id );
	}
}
