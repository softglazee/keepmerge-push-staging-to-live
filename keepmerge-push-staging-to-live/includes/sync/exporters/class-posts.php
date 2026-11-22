<?php
/**
 * Posts, pages and custom post types.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync\Exporters;

use SoftGlaze\SafePush\Sync\Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post-like items of kind "post": the post fields, parent, author, page template, term
 * assignments, featured image and post meta minus the excluded keys. Volatile fields
 * (modification dates, GUID, comment count) are not part of the item.
 */
final class Posts extends Exporter {

	/**
	 * Exports one post.
	 *
	 * @param int|string $id Post ID.
	 * @return array|null
	 */
	public function export( $id ) {
		$post = get_post( absint( $id ) );
		if ( ! Scope::allows_post( $post, 'post' ) ) {
			return null;
		}

		$raw = array(
			'post_type'      => $post->post_type,
			'post_title'     => $post->post_title,
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_status'    => $post->post_status,
			'post_name'      => $post->post_name,
			'post_password'  => $post->post_password,
			'menu_order'     => (int) $post->menu_order,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_date_gmt'  => $post->post_date_gmt,
			'post_parent'    => (int) $post->post_parent,
			'author'         => $this->author( $post->post_author ),
			'page_template'  => (string) get_post_meta( $post->ID, '_wp_page_template', true ),
			'featured_image' => (int) get_post_meta( $post->ID, '_thumbnail_id', true ),
			'terms'          => $this->terms_for( $post ),
			'meta'           => $this->post_meta( $post->ID, array( '_wp_page_template' ) ),
		);

		// A draft without a fixed date has no GMT date; its local date is all there is.
		if ( '0000-00-00 00:00:00' === $post->post_date_gmt ) {
			$raw['post_date'] = $post->post_date;
		}
		if ( post_type_supports( $post->post_type, 'post-formats' ) ) {
			$format        = get_post_format( $post );
			$raw['format'] = false === $format ? 'standard' : (string) $format;
		}

		return $this->item(
			'post',
			$post->post_type,
			$post->ID,
			$this->ctx->uuid( 'post', $post->ID ),
			$raw,
			array(
				'title'        => (string) $post->post_title,
				'modified_gmt' => (string) $post->post_modified_gmt,
			)
		);
	}
}
