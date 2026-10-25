<?php
/**
 * Templates, template parts, global styles, patterns and navigation.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync\Exporters;

use SoftGlaze\SafePush\Sync\Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site editor items exactly as blueprint §6.2 (storage checked in core 7.1.3):
 * - wp_template / wp_template_part: slug, content, title, excerpt, status, the theme (the
 *   wp_theme term, a stylesheet), the origin meta, and for parts the wp_template_part_area term;
 * - wp_global_styles: the post of one stylesheet (wp_theme term), content = theme.json-shaped
 *   JSON;
 * - wp_block (patterns, synced and unsynced): content, title, slug, status, the
 *   wp_pattern_sync_status meta and the wp_pattern_category terms;
 * - wp_navigation: content (block markup), title, slug, status.
 * wp_theme and wp_template_part_area are not public taxonomies, so they are fields here; the
 * term exporter never touches them.
 */
final class Site_Editor extends Exporter {

	/**
	 * Exports one site editor item.
	 *
	 * @param int|string $id Post ID.
	 * @return array|null
	 */
	public function export( $id ) {
		$post = get_post( absint( $id ) );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$kind = Scope::kind_for_post_type( $post->post_type );
		if ( ! in_array( $kind, array( 'template', 'template_part', 'global_styles', 'pattern', 'navigation' ), true ) || ! Scope::allows_post( $post, $kind ) ) {
			return null;
		}

		$raw   = array(
			'post_type'   => $post->post_type,
			'post_name'   => $post->post_name,
			'post_title'  => $post->post_title,
			'post_status' => $post->post_status,
		);
		$extra = array(
			'title'        => '' === $post->post_title ? $post->post_name : (string) $post->post_title,
			'modified_gmt' => (string) $post->post_modified_gmt,
		);

		switch ( $kind ) {
			case 'template':
			case 'template_part':
				$theme               = $this->first_term_name( $post->ID, 'wp_theme' );
				$raw['post_content'] = $post->post_content;
				$raw['post_excerpt'] = $post->post_excerpt;
				$raw['theme']        = $theme;
				$raw['origin']       = (string) get_post_meta( $post->ID, 'origin', true );
				$extra['stylesheet'] = $theme;
				if ( 'template_part' === $kind ) {
					$raw['area'] = $this->first_term_name( $post->ID, 'wp_template_part_area' );
				}
				break;

			case 'global_styles':
				$theme               = $this->first_term_name( $post->ID, 'wp_theme' );
				$raw['post_content'] = $post->post_content;
				$raw['theme']        = $theme;
				$extra['stylesheet'] = $theme;
				break;

			case 'pattern':
				$raw['post_content'] = $post->post_content;
				$raw['sync_status']  = (string) get_post_meta( $post->ID, 'wp_pattern_sync_status', true );
				$raw['terms']        = $this->terms_for( $post, array( 'wp_pattern_category' ) );
				break;

			case 'navigation':
				$raw['post_content'] = $post->post_content;
				break;
		}

		return $this->item( $kind, $post->post_type, $post->ID, $this->ctx->uuid( 'post', $post->ID ), $raw, $extra );
	}
}
