<?php
/**
 * Terms.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync\Exporters;

use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Woo\Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Terms of the included taxonomies (blueprint §6.2): taxonomy, name, slug, description, parent
 * and term meta minus the excluded keys. The term count is volatile and left out, as are
 * WooCommerce's counter meta keys.
 */
final class Terms extends Exporter {

	/**
	 * Exports one term.
	 *
	 * @param int|string $id Term ID.
	 * @return array|null
	 */
	public function export( $id ) {
		$term = get_term( absint( $id ) );
		if ( ! Scope::allows_term( $term, 'term' ) ) {
			return null;
		}

		$raw = array(
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'meta'        => $this->term_meta( $term->term_id, array( Guard::class, 'is_volatile_term_meta' ) ),
		);

		return $this->item(
			'term',
			$term->taxonomy,
			$term->term_id,
			$this->ctx->uuid( 'term', $term->term_id ),
			$raw,
			array( 'title' => (string) $term->name )
		);
	}
}
