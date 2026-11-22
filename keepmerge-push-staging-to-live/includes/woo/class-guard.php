<?php
/**
 * WooCommerce data that Keepmerge never reads or writes, and the fields live owns.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Woo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one place in the plugin that names WooCommerce's order, coupon, customer and session
 * storage. Every list is a denylist that exporters and remote handlers consult. It also declares
 * HPOS compatibility: orders are never touched, so the order storage makes no difference.
 */
final class Guard {

	/**
	 * Post types that hold orders, refunds, subscriptions and coupons. HPOS keeps placeholder
	 * posts of type shop_order_placehold in the posts table.
	 */
	const DENIED_POST_TYPES = array(
		'shop_order',
		'shop_order_refund',
		'shop_order_placehold',
		'shop_subscription',
		'shop_coupon',
	);

	/**
	 * Prefix of order post types (orders, refunds, HPOS placeholders and any variant an
	 * extension registers under the same name).
	 */
	const DENIED_POST_TYPE_PREFIX = 'shop_order';

	/**
	 * Tables (without the WordPress prefix) whose rows are never read or written. Listed so that
	 * reviews can see the boundary in one place; no query in the plugin names them.
	 */
	const DENIED_TABLES = array(
		'wc_orders',
		'wc_order_addresses',
		'wc_order_operational_data',
		'wc_orders_meta',
		'woocommerce_order_items',
		'woocommerce_order_itemmeta',
		'wc_order_stats',
		'wc_order_product_lookup',
		'wc_order_tax_lookup',
		'wc_order_coupon_lookup',
		'wc_customer_lookup',
		'wc_reserved_stock',
		'wc_rate_limits',
		'woocommerce_sessions',
		'woocommerce_payment_tokens',
		'woocommerce_payment_tokenmeta',
		'woocommerce_downloadable_product_permissions',
		'wc_download_log',
		'wc_webhooks',
		'woocommerce_api_keys',
		'actionscheduler_actions',
		'actionscheduler_claims',
		'actionscheduler_groups',
		'actionscheduler_logs',
	);

	/**
	 * Product fields that belong to live: never pushed by default and kept out of a product's item
	 * hash, so a sale on live does not make the product look changed.
	 */
	const LIVE_OWNED_FIELDS = array( 'stock_quantity', 'stock_status', 'total_sales', 'average_rating', 'rating_counts', 'review_count' );

	/**
	 * Product meta keys read through WooCommerce getters or derived by WooCommerce, so they are not
	 * repeated as "other meta".
	 */
	const PRODUCT_INTERNAL_META = array(
		'_sku',
		'_global_unique_id',
		'_price',
		'_regular_price',
		'_sale_price',
		'_sale_price_dates_from',
		'_sale_price_dates_to',
		'_tax_status',
		'_tax_class',
		'_manage_stock',
		'_stock',
		'_stock_status',
		'_backorders',
		'_low_stock_amount',
		'_sold_individually',
		'_weight',
		'_length',
		'_width',
		'_height',
		'_upsell_ids',
		'_crosssell_ids',
		'_purchase_note',
		'_default_attributes',
		'_product_attributes',
		'_virtual',
		'_downloadable',
		'_downloadable_files',
		'_download_limit',
		'_download_expiry',
		'_product_image_gallery',
		'_product_version',
		'_thumbnail_id',
		'_variation_description',
		'_product_url',
		'_button_text',
		'_children',
		'_cogs_*',
		'total_sales',
		'_wc_average_rating',
		'_wc_rating_count',
		'_wc_review_count',
		'attribute_*',
	);

	/**
	 * Term meta WooCommerce keeps as counters (recomputed by WooCommerce, different per site).
	 */
	const VOLATILE_TERM_META = array( 'product_count_*' );

	/**
	 * Upload folders whose files are never part of a push (downloadable product files).
	 */
	const PROTECTED_UPLOAD_DIRS = array( 'woocommerce_uploads' );

	/**
	 * Hook: before_woocommerce_init. Declares HPOS (custom_order_tables) compatibility through
	 * WooCommerce's own API, only when WooCommerce provides it.
	 *
	 * @return void
	 */
	public static function declare_hpos_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SOFTGLAZE_SP_FILE, true );
		}
	}

	/**
	 * Whether WooCommerce is active on this site.
	 *
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Whether a post type is one Keepmerge never reads or writes.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_denied_post_type( $post_type ) {
		$post_type = (string) $post_type;
		return in_array( $post_type, self::DENIED_POST_TYPES, true ) || 0 === strpos( $post_type, self::DENIED_POST_TYPE_PREFIX );
	}

	/**
	 * Whether a product meta key is handled by the getters (or derived) rather than as other meta.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_internal_product_meta( $key ) {
		return self::matches_any( (string) $key, self::PRODUCT_INTERNAL_META );
	}

	/**
	 * Whether a term meta key is a WooCommerce counter.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_volatile_term_meta( $key ) {
		return self::matches_any( (string) $key, self::VOLATILE_TERM_META );
	}

	/**
	 * Whether a relative uploads path lies in a protected folder.
	 *
	 * @param string $relative Path relative to the uploads base.
	 * @return bool
	 */
	public static function is_protected_upload( $relative ) {
		$first = strtolower( (string) strtok( ltrim( (string) $relative, '/' ), '/' ) );
		return in_array( $first, self::PROTECTED_UPLOAD_DIRS, true );
	}

	/**
	 * Exact names, or a prefix ending in "*".
	 *
	 * @param string   $name    Name.
	 * @param string[] $entries Entries.
	 * @return bool
	 */
	private static function matches_any( $name, array $entries ) {
		foreach ( $entries as $entry ) {
			if ( '*' === substr( $entry, -1 ) ) {
				if ( 0 === strpos( $name, substr( $entry, 0, -1 ) ) ) {
					return true;
				}
			} elseif ( $name === $entry ) {
				return true;
			}
		}
		return false;
	}
}
