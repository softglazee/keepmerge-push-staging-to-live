<?php
/**
 * WooCommerce products and variations on live.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply\Appliers;

use SoftGlaze\SafePush\Apply\Item_Context;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Woo\Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blueprint §7 rules 3–9: products and variations are written only through the WooCommerce CRUD:
 * wc_get_product() (or the class of the staged type), setters for the props of the field groups
 * the admin decided to push, then save(). The data store writes the changed props only, so stock
 * that a checkout wrote between load and save is not overwritten, and WooCommerce keeps its own
 * lookup tables and caches.
 *
 * - The live-owned group (stock quantity and status) is written only for a new product (the
 *   staging stock, live has none) or when the admin chose "Push stock" and live's live-owned
 *   group still equals its base; otherwise it is left and the result says so.
 * - Products are never trashed or deleted here; product IDs never change.
 * - The author of a new product is the mapped author (WooCommerce would use the current user).
 */
final class Woo_Product extends Base {

	/**
	 * Group actions that write a group.
	 */
	const WRITE_ACTIONS = array( 'push', 'use_staging', 'create' );

	/**
	 * Allocate pass: a draft product (or variation under its parent) through the CRUD.
	 *
	 * @param Item_Context $ctx Context.
	 * @return int|WP_Error
	 */
	public static function allocate( Item_Context $ctx ) {
		if ( ! Guard::active() ) {
			return new WP_Error( 'woocommerce_inactive', 'woocommerce_inactive' );
		}
		$content = self::group( $ctx, 'content' );
		$type    = self::str( $content, 'type' );
		$class   = self::class_for( $type );
		if ( '' === $class ) {
			return new WP_Error( 'type_not_included', 'type_not_included' );
		}

		$author = $ctx->author_id( isset( $ctx->data['author'] ) ? $ctx->data['author'] : array() );
		$filter = static function ( $data ) use ( $author ) {
			if ( is_array( $data ) && $author > 0 ) {
				$data['post_author'] = $author;
			}
			return $data;
		};

		try {
			$product = new $class();
			$product->set_name( self::str( $content, 'name' ) );
			$product->set_status( 'draft' );
			if ( 'variation' === $type ) {
				$parent = self::int( $content, 'parent' );
				if ( $parent < 1 ) {
					return new WP_Error( 'reference_missing', 'reference_missing' );
				}
				$product->set_parent_id( $parent );
			}
			add_filter( 'woocommerce_new_product_data', $filter );
			add_filter( 'woocommerce_new_product_variation_data', $filter );
			$id = (int) $product->save();
		} catch ( \Exception $e ) {
			return new WP_Error( 'write_failed', 'write_failed' );
		} finally {
			remove_filter( 'woocommerce_new_product_data', $filter );
			remove_filter( 'woocommerce_new_product_variation_data', $filter );
		}
		if ( $id < 1 ) {
			return new WP_Error( 'write_failed', 'write_failed' );
		}
		Identity::stamp( 'post', $id, (string) $ctx->row['uuid'] );
		return $id;
	}

	/**
	 * Write pass.
	 *
	 * @param Item_Context $ctx Context.
	 * @return array|WP_Error
	 */
	public static function write( Item_Context $ctx ) {
		if ( ! Guard::active() ) {
			return new WP_Error( 'woocommerce_inactive', 'woocommerce_inactive' );
		}
		$created = 'created' === $ctx->row['action'];
		$content = self::group( $ctx, 'content' );
		$type    = self::str( $content, 'type' );

		$product = wc_get_product( $ctx->live_id );
		if ( ! $product instanceof \WC_Product ) {
			return new WP_Error( 'write_failed', 'write_failed' );
		}
		if ( self::writes( $ctx, 'content' ) && '' !== $type && $product->get_type() !== $type ) {
			// A changed product type needs the class of the new type (core of WooCommerce's own
			// product type switch); the ID stays.
			$class = self::class_for( $type );
			if ( '' === $class ) {
				return new WP_Error( 'type_not_included', 'type_not_included' );
			}
			$product = new $class( $ctx->live_id );
		}

		$warnings = array();
		try {
			if ( self::writes( $ctx, 'content' ) ) {
				$problem = self::content( $product, $content, $ctx );
				if ( is_wp_error( $problem ) ) {
					return $problem;
				}
				$gmt = self::str( $ctx->data, 'post_date_gmt' );
				if ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) {
					$product->set_date_created( (int) strtotime( $gmt . ' UTC' ) );
				}
			}
			if ( self::writes( $ctx, 'pricing' ) ) {
				self::pricing( $product, self::group( $ctx, 'pricing' ) );
			}
			if ( self::writes( $ctx, 'identity' ) ) {
				$identity = self::group( $ctx, 'identity' );
				if ( array_key_exists( 'sku', $identity ) ) {
					$product->set_sku( self::str( $identity, 'sku' ) );
				}
				if ( array_key_exists( 'global_unique_id', $identity ) && is_callable( array( $product, 'set_global_unique_id' ) ) ) {
					$product->set_global_unique_id( self::str( $identity, 'global_unique_id' ) );
				}
			}
			$stock = self::stock( $ctx, $product, $created );
			if ( is_string( $stock ) ) {
				$warnings[] = $stock;
			}
			$product->save();
		} catch ( \Exception $e ) {
			return new WP_Error( 'write_failed', 'write_failed' );
		}

		return array(
			'id'       => (int) $product->get_id(),
			'warnings' => $warnings,
		);
	}

	/**
	 * Base group hashes after the push (blueprint §6.6): a group written here gets live's new hash;
	 * a group live kept gets the staging hash of that group, so the next plan sees "changed on live"
	 * (keep) rather than "changed on staging" (push) for it.
	 *
	 * @param Item_Context $ctx         Context.
	 * @param array        $live_groups Live's group hashes after the write.
	 * @return array<string,string>
	 */
	public static function base_groups( Item_Context $ctx, array $live_groups ) {
		$staging = isset( $ctx->row['payload']['group_hashes'] ) && is_array( $ctx->row['payload']['group_hashes'] ) ? $ctx->row['payload']['group_hashes'] : array();
		$old     = isset( $ctx->row['pre_image']['sync']['base_groups'] ) ? json_decode( (string) $ctx->row['pre_image']['sync']['base_groups'], true ) : array();
		$old     = is_array( $old ) ? $old : array();
		$out     = array();
		foreach ( $live_groups as $name => $hash ) {
			$name = (string) $name;
			if ( 'live_owned' === $name || self::writes( $ctx, $name ) ) {
				$out[ $name ] = (string) $hash;
			} elseif ( isset( $staging[ $name ] ) ) {
				$out[ $name ] = (string) $staging[ $name ];
			} elseif ( isset( $old[ $name ] ) && is_string( $old[ $name ] ) ) {
				$out[ $name ] = $old[ $name ];
			} else {
				$out[ $name ] = (string) $hash;
			}
		}
		return $out;
	}

	/**
	 * Whether a group is written: every group of a new product, else the decided groups.
	 *
	 * @param Item_Context $ctx  Context.
	 * @param string       $name Group.
	 * @return bool
	 */
	private static function writes( Item_Context $ctx, $name ) {
		if ( 'created' === $ctx->row['action'] ) {
			return 'live_owned' !== $name;
		}
		$actions = isset( $ctx->row['payload']['groups'] ) && is_array( $ctx->row['payload']['groups'] ) ? $ctx->row['payload']['groups'] : array();
		return isset( $actions[ $name ] ) && in_array( $actions[ $name ], self::WRITE_ACTIONS, true );
	}

	/**
	 * The live-owned group: the staging stock for a new product; for an existing one only with
	 * "Push stock" and only while live's stock group equals its base (blueprint §7 rule 4).
	 *
	 * @param Item_Context $ctx     Context.
	 * @param \WC_Product  $product Product.
	 * @param bool         $created Whether the product is new.
	 * @return string|null A warning code, or null.
	 */
	private static function stock( Item_Context $ctx, $product, $created ) {
		$owned   = self::group( $ctx, 'live_owned' );
		$actions = isset( $ctx->row['payload']['groups'] ) && is_array( $ctx->row['payload']['groups'] ) ? $ctx->row['payload']['groups'] : array();
		if ( ! $created ) {
			if ( ! isset( $actions['live_owned'] ) || 'push_stock' !== $actions['live_owned'] ) {
				return null;
			}
			$base = isset( $ctx->row['pre_image']['sync']['base_groups'] ) ? json_decode( (string) $ctx->row['pre_image']['sync']['base_groups'], true ) : array();
			$now  = isset( $ctx->row['pre_image']['groups']['live_owned'] ) ? (string) $ctx->row['pre_image']['groups']['live_owned'] : '';
			$item = \SoftGlaze\SafePush\Sync\Items::export( 'product', $ctx->live_id, \SoftGlaze\SafePush\Sync\Export_Context::destination() );
			$live = is_array( $item ) && isset( $item['groups']['live_owned'] ) ? (string) $item['groups']['live_owned'] : '';
			if ( ! is_array( $base ) || ! isset( $base['live_owned'] ) || ! hash_equals( (string) $base['live_owned'], $live ) || ! hash_equals( $now, $live ) ) {
				// Live's stock moved since the base (or since the snapshot): it stays as live has it.
				return 'stock_changed_on_live';
			}
		}
		if ( array_key_exists( 'stock_quantity', $owned ) && $product->get_manage_stock() && ( is_numeric( $owned['stock_quantity'] ) || null === $owned['stock_quantity'] ) ) {
			$product->set_stock_quantity( null === $owned['stock_quantity'] ? null : wc_stock_amount( $owned['stock_quantity'] ) );
		}
		if ( array_key_exists( 'stock_status', $owned ) && '' !== self::str( $owned, 'stock_status' ) && ! $product->is_type( 'external' ) ) {
			$product->set_stock_status( self::str( $owned, 'stock_status' ) );
		}
		return null;
	}

	/**
	 * The content group's props.
	 *
	 * @param \WC_Product  $product Product.
	 * @param array        $content Content group (decoded, live IDs).
	 * @param Item_Context $ctx     Context.
	 * @return true|WP_Error
	 */
	private static function content( $product, array $content, Item_Context $ctx ) {
		$variation = $product->is_type( 'variation' );
		$strings   = array( 'name', 'slug', 'status', 'description', 'weight', 'length', 'width', 'height', 'backorders', 'purchase_note', 'post_password', 'catalog_visibility' );
		if ( ! $variation ) {
			$strings[] = 'short_description';
		}
		foreach ( $strings as $field ) {
			if ( array_key_exists( $field, $content ) && is_callable( array( $product, 'set_' . $field ) ) ) {
				$product->{'set_' . $field}( self::str( $content, $field ) );
			}
		}
		foreach ( array( 'menu_order', 'image_id', 'shipping_class_id', 'download_limit', 'download_expiry' ) as $field ) {
			if ( array_key_exists( $field, $content ) ) {
				$product->{'set_' . $field}( self::int( $content, $field ) );
			}
		}
		foreach ( array( 'virtual', 'downloadable', 'manage_stock', 'featured', 'reviews_allowed', 'sold_individually' ) as $field ) {
			if ( array_key_exists( $field, $content ) && ( ! $variation || ! in_array( $field, array( 'featured', 'reviews_allowed', 'sold_individually' ), true ) ) ) {
				$product->{'set_' . $field}( (bool) $content[ $field ] );
			}
		}
		if ( array_key_exists( 'low_stock_amount', $content ) ) {
			$product->set_low_stock_amount( is_numeric( $content['low_stock_amount'] ) ? (int) $content['low_stock_amount'] : '' );
		}
		foreach ( array( 'gallery_image_ids', 'upsell_ids', 'cross_sell_ids' ) as $field ) {
			if ( array_key_exists( $field, $content ) && ! $variation ) {
				$product->{'set_' . $field}( Item_Context::id_list( $content[ $field ] ) );
			}
		}
		if ( array_key_exists( 'downloads', $content ) ) {
			$downloads = array();
			foreach ( (array) $content['downloads'] as $download ) {
				if ( is_array( $download ) ) {
					$downloads[] = array(
						'download_id' => self::str( $download, 'id' ),
						'name'        => self::str( $download, 'name' ),
						'file'        => self::str( $download, 'file' ),
						'enabled'     => ! isset( $download['enabled'] ) || (bool) $download['enabled'],
					);
				}
			}
			$product->set_downloads( $downloads );
		}
		if ( $variation ) {
			if ( array_key_exists( 'parent', $content ) ) {
				$parent = self::int( $content, 'parent' );
				if ( $parent < 1 ) {
					return new WP_Error( 'reference_missing', 'reference_missing' );
				}
				$product->set_parent_id( $parent );
			}
			if ( isset( $content['variation_attributes'] ) && is_array( $content['variation_attributes'] ) ) {
				$product->set_attributes( array_map( 'strval', $content['variation_attributes'] ) );
			}
		} else {
			if ( array_key_exists( 'default_attributes', $content ) ) {
				$product->set_default_attributes( (array) $content['default_attributes'] );
			}
			if ( array_key_exists( 'attributes', $content ) ) {
				$attributes = self::attributes( (array) $content['attributes'] );
				if ( is_wp_error( $attributes ) ) {
					return $attributes;
				}
				$product->set_attributes( $attributes );
			}
			self::terms( $product, isset( $content['terms'] ) && is_array( $content['terms'] ) ? $content['terms'] : array(), $ctx );
			if ( array_key_exists( 'children', $content ) && $product->is_type( 'grouped' ) ) {
				$product->set_children( Item_Context::id_list( $content['children'] ) );
			}
			if ( $product->is_type( 'external' ) ) {
				if ( array_key_exists( 'product_url', $content ) ) {
					$product->set_product_url( self::str( $content, 'product_url' ) );
				}
				if ( array_key_exists( 'button_text', $content ) ) {
					$product->set_button_text( self::str( $content, 'button_text' ) );
				}
			}
		}

		self::meta( $product, isset( $content['meta'] ) && is_array( $content['meta'] ) ? $content['meta'] : array(), $ctx );
		return true;
	}

	/**
	 * The pricing group's props.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $pricing Pricing group.
	 * @return void
	 */
	private static function pricing( $product, array $pricing ) {
		foreach ( array( 'regular_price', 'sale_price', 'tax_status', 'tax_class' ) as $field ) {
			if ( array_key_exists( $field, $pricing ) ) {
				$product->{'set_' . $field}( self::str( $pricing, $field ) );
			}
		}
		foreach ( array( 'date_on_sale_from', 'date_on_sale_to' ) as $field ) {
			if ( array_key_exists( $field, $pricing ) ) {
				$value = self::str( $pricing, $field );
				$product->{'set_' . $field}( '' === $value ? null : (int) strtotime( $value . ' UTC' ) );
			}
		}
	}

	/**
	 * Category, tag and brand assignments through the CRUD (taxonomies the source names).
	 *
	 * @param \WC_Product  $product Product.
	 * @param array        $terms   Taxonomy => live term IDs.
	 * @param Item_Context $ctx     Context.
	 * @return void
	 */
	private static function terms( $product, array $terms, Item_Context $ctx ) {
		$setters = array(
			'product_cat'   => 'set_category_ids',
			'product_tag'   => 'set_tag_ids',
			'product_brand' => 'set_brand_ids',
		);
		foreach ( $ctx->taxonomies( 'product', $terms ) as $taxonomy ) {
			if ( isset( $setters[ $taxonomy ] ) && is_callable( array( $product, $setters[ $taxonomy ] ) ) ) {
				$product->{$setters[ $taxonomy ]}( Item_Context::id_list( isset( $terms[ $taxonomy ] ) ? $terms[ $taxonomy ] : array() ) );
			}
		}
	}

	/**
	 * Product attributes as WC_Product_Attribute objects; a global attribute must exist on live.
	 *
	 * @param array $entries Attributes (decoded).
	 * @return array|WP_Error
	 */
	private static function attributes( array $entries ) {
		$out = array();
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$taxonomy  = ! empty( $entry['taxonomy'] );
			$name      = self::str( $entry, 'name' );
			$attribute = new \WC_Product_Attribute();
			if ( $taxonomy ) {
				$id = (int) wc_attribute_taxonomy_id_by_name( $name );
				if ( $id < 1 || ! taxonomy_exists( $name ) ) {
					return new WP_Error( 'attribute_missing', 'attribute_missing' );
				}
				$attribute->set_id( $id );
				$attribute->set_options( Item_Context::id_list( isset( $entry['options'] ) ? $entry['options'] : array() ) );
			} else {
				$attribute->set_id( 0 );
				$attribute->set_options( array_map( 'strval', isset( $entry['options'] ) ? (array) $entry['options'] : array() ) );
			}
			$attribute->set_name( $name );
			$attribute->set_position( self::int( $entry, 'position' ) );
			$attribute->set_visible( ! empty( $entry['visible'] ) );
			$attribute->set_variation( ! empty( $entry['variation'] ) );
			$out[] = $attribute;
		}
		return $out;
	}

	/**
	 * Other product meta through the CRUD: each key the item carries gets its values; a key live
	 * has that the item lacks is removed only when Item_Context allows it.
	 *
	 * @param \WC_Product  $product Product.
	 * @param array        $meta    Key => list of values.
	 * @param Item_Context $ctx     Context.
	 * @return void
	 */
	private static function meta( $product, array $meta, Item_Context $ctx ) {
		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( '' === $key || 0 === strpos( $key, '_softglaze_sp_' ) || Guard::is_internal_product_meta( $key ) ) {
				continue;
			}
			$values  = is_array( $values ) ? array_values( $values ) : array( $values );
			$current = array();
			foreach ( (array) $product->get_meta( $key, false, 'edit' ) as $entry ) {
				$current[] = is_object( $entry ) && isset( $entry->value ) ? $entry->value : $entry;
			}
			if ( self::same_values( $current, $values ) ) {
				continue;
			}
			$product->delete_meta_data( $key );
			foreach ( $values as $value ) {
				$product->add_meta_data( $key, $value, false );
			}
		}
		foreach ( (array) $product->get_meta_data() as $entry ) {
			$key = is_object( $entry ) && isset( $entry->key ) ? (string) $entry->key : '';
			if ( '' !== $key && ! array_key_exists( $key, $meta ) && $ctx->may_delete_meta( $key ) ) {
				$product->delete_meta_data( $key );
			}
		}
	}

	/**
	 * A decoded field group of the item.
	 *
	 * @param Item_Context $ctx  Context.
	 * @param string       $name Group.
	 * @return array
	 */
	private static function group( Item_Context $ctx, $name ) {
		return isset( $ctx->data['groups'][ $name ] ) && is_array( $ctx->data['groups'][ $name ] ) ? $ctx->data['groups'][ $name ] : array();
	}

	/**
	 * The WooCommerce class of a product type, or ''.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	private static function class_for( $type ) {
		if ( ! in_array( $type, array( 'simple', 'variable', 'grouped', 'external', 'variation' ), true ) || ! class_exists( 'WC_Product_Factory' ) ) {
			return '';
		}
		$class = \WC_Product_Factory::get_classname_from_product_type( $type );
		return is_string( $class ) && class_exists( $class ) ? $class : '';
	}
}
