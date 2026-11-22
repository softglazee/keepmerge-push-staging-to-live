<?php
/**
 * WooCommerce products and variations.
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
 * Products and variations, read through the WooCommerce getters in the "edit" context (stored
 * values, no display filters) and grouped into the field groups content, pricing, identity and
 * live-owned. Only used while WooCommerce is active. Meta the getters cover or WooCommerce derives
 * is not repeated as other meta (Woo\Guard::PRODUCT_INTERNAL_META).
 */
final class Products extends Exporter {

	/**
	 * Exports one product or variation.
	 *
	 * @param int|string $id Post ID.
	 * @return array|null
	 */
	public function export( $id ) {
		$post = get_post( absint( $id ) );
		if ( ! Guard::active() || ! Scope::allows_post( $post, 'product' ) ) {
			return null;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$variation = 'product_variation' === $post->post_type;

		$content = array(
			'type'               => (string) $product->get_type(),
			'name'               => (string) $product->get_name( 'edit' ),
			'slug'               => (string) $product->get_slug( 'edit' ),
			'status'             => (string) $product->get_status( 'edit' ),
			'description'        => (string) $product->get_description( 'edit' ),
			'menu_order'         => (int) $product->get_menu_order( 'edit' ),
			'image_id'           => (int) $product->get_image_id( 'edit' ),
			'virtual'            => (bool) $product->get_virtual( 'edit' ),
			'downloadable'       => (bool) $product->get_downloadable( 'edit' ),
			'downloads'          => $this->downloads( $product ),
			'download_limit'     => (int) $product->get_download_limit( 'edit' ),
			'download_expiry'    => (int) $product->get_download_expiry( 'edit' ),
			'weight'             => (string) $product->get_weight( 'edit' ),
			'length'             => (string) $product->get_length( 'edit' ),
			'width'              => (string) $product->get_width( 'edit' ),
			'height'             => (string) $product->get_height( 'edit' ),
			'shipping_class_id'  => (int) $product->get_shipping_class_id( 'edit' ),
			'manage_stock'       => (bool) $product->get_manage_stock( 'edit' ),
			'backorders'         => (string) $product->get_backorders( 'edit' ),
			'low_stock_amount'   => $product->get_low_stock_amount( 'edit' ),
			'purchase_note'      => (string) $product->get_purchase_note( 'edit' ),
			'post_password'      => (string) $product->get_post_password( 'edit' ),
			'meta'               => $this->post_meta( $post->ID, array(), array( Guard::class, 'is_internal_product_meta' ) ),
			'parent'             => (int) $product->get_parent_id( 'edit' ),
			'featured'           => (bool) $product->get_featured( 'edit' ),
			'catalog_visibility' => (string) $product->get_catalog_visibility( 'edit' ),
		);

		if ( $variation ) {
			$content['variation_attributes'] = (array) $product->get_attributes( 'edit' );
			ksort( $content['variation_attributes'], SORT_STRING );
		} else {
			$content['short_description']  = (string) $product->get_short_description( 'edit' );
			$content['gallery_image_ids']  = array_map( 'intval', (array) $product->get_gallery_image_ids( 'edit' ) );
			$content['upsell_ids']         = array_map( 'intval', (array) $product->get_upsell_ids( 'edit' ) );
			$content['cross_sell_ids']     = array_map( 'intval', (array) $product->get_cross_sell_ids( 'edit' ) );
			$content['reviews_allowed']    = (bool) $product->get_reviews_allowed( 'edit' );
			$content['sold_individually']  = (bool) $product->get_sold_individually( 'edit' );
			$content['attributes']         = $this->attributes( $product );
			$content['default_attributes'] = (array) $product->get_default_attributes( 'edit' );
			$content['terms']              = $this->product_terms( $product );
			ksort( $content['default_attributes'], SORT_STRING );
			if ( $product->is_type( 'grouped' ) ) {
				$content['children'] = array_map( 'intval', (array) $product->get_children( 'edit' ) );
			}
			if ( $product->is_type( 'external' ) && is_callable( array( $product, 'get_product_url' ) ) ) {
				$content['product_url'] = (string) $product->get_product_url( 'edit' );
				$content['button_text'] = (string) $product->get_button_text( 'edit' );
			}
		}
		ksort( $content, SORT_STRING );

		$pricing = array(
			'regular_price'     => (string) $product->get_regular_price( 'edit' ),
			'sale_price'        => (string) $product->get_sale_price( 'edit' ),
			'date_on_sale_from' => self::gmt( $product->get_date_on_sale_from( 'edit' ) ),
			'date_on_sale_to'   => self::gmt( $product->get_date_on_sale_to( 'edit' ) ),
			'tax_status'        => (string) $product->get_tax_status( 'edit' ),
			'tax_class'         => (string) $product->get_tax_class( 'edit' ),
		);

		$identity = array(
			'sku'              => (string) $product->get_sku( 'edit' ),
			'global_unique_id' => is_callable( array( $product, 'get_global_unique_id' ) ) ? (string) $product->get_global_unique_id( 'edit' ) : '',
		);

		$live_owned = array(
			'stock_quantity' => $product->get_stock_quantity( 'edit' ),
			'stock_status'   => (string) $product->get_stock_status( 'edit' ),
			'total_sales'    => (int) $product->get_total_sales( 'edit' ),
			'average_rating' => (string) $product->get_average_rating( 'edit' ),
			'rating_counts'  => (array) $product->get_rating_counts( 'edit' ),
			'review_count'   => (int) $product->get_review_count( 'edit' ),
		);

		$raw = array(
			'post_type'     => $post->post_type,
			'post_date_gmt' => $post->post_date_gmt,
			'author'        => $this->author( $post->post_author ),
			'groups'        => array(
				'content'    => $content,
				'pricing'    => $pricing,
				'identity'   => $identity,
				'live_owned' => $live_owned,
			),
		);

		return $this->item(
			'product',
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

	/**
	 * Product attributes as plain data; global attributes list their term IDs.
	 *
	 * @param \WC_Product $product Product.
	 * @return array[]
	 */
	private function attributes( \WC_Product $product ) {
		$out = array();
		foreach ( (array) $product->get_attributes( 'edit' ) as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$taxonomy = (bool) $attribute->is_taxonomy();
			$options  = (array) $attribute->get_options();
			$out[]    = array(
				'name'      => (string) $attribute->get_name(),
				'taxonomy'  => $taxonomy,
				'options'   => $taxonomy ? array_map( 'intval', $options ) : array_map( 'strval', $options ),
				'position'  => (int) $attribute->get_position(),
				'visible'   => (bool) $attribute->get_visible(),
				'variation' => (bool) $attribute->get_variation(),
			);
		}
		return $out;
	}

	/**
	 * Downloadable files as plain data (URLs become tokens like any string).
	 *
	 * @param \WC_Product $product Product.
	 * @return array[]
	 */
	private function downloads( \WC_Product $product ) {
		$out = array();
		foreach ( (array) $product->get_downloads( 'edit' ) as $download ) {
			if ( $download instanceof \WC_Product_Download ) {
				$out[] = array(
					'id'      => (string) $download->get_id(),
					'name'    => (string) $download->get_name(),
					'file'    => (string) $download->get_file(),
					'enabled' => (bool) $download->get_enabled(),
				);
			}
		}
		return $out;
	}

	/**
	 * Category, tag and brand assignments in the included taxonomies.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,int[]>
	 */
	private function product_terms( \WC_Product $product ) {
		$included = Scope::taxonomies();
		$sources  = array(
			'product_cat'   => 'get_category_ids',
			'product_tag'   => 'get_tag_ids',
			'product_brand' => 'get_brand_ids',
		);
		$out      = array();
		foreach ( $sources as $taxonomy => $method ) {
			if ( ! in_array( $taxonomy, $included, true ) || ! is_callable( array( $product, $method ) ) ) {
				continue;
			}
			$ids = array_map( 'intval', (array) $product->{$method}( 'edit' ) );
			if ( empty( $ids ) ) {
				continue;
			}
			sort( $ids );
			$out[ $taxonomy ] = $ids;
		}
		return $out;
	}

	/**
	 * A WooCommerce date as a GMT string, '' when unset.
	 *
	 * @param mixed $date WC_DateTime or null.
	 * @return string
	 */
	private static function gmt( $date ) {
		if ( ! $date instanceof \DateTimeInterface ) {
			return '';
		}
		return gmdate( 'Y-m-d H:i:s', $date->getTimestamp() );
	}
}
