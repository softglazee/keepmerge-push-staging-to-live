<?php
/**
 * Writes a snapshot's pre-image back on live, and removes what a push created.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply;

use SoftGlaze\SafePush\Apply\Appliers\Woo_Product;
use SoftGlaze\SafePush\Sync\Exporters\Menus;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Normalizer;
use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Woo\Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts an item back exactly as its snapshot recorded it, through WordPress's own write functions
 * in the live administrator's session, and removes objects the push created (posts and products to
 * the Trash). Serialized meta and option text goes back with one prepared write, because
 * add_metadata() would serialize it again and rebuilding it would need unserialize(). Stock, sales
 * and ratings are never written back, so orders placed since the push keep their effect.
 */
final class Raw_Restore {

	/**
	 * Meta keys an applier writes as fields although the exporters exclude them from "meta".
	 */
	const FIELD_META = array( '_thumbnail_id', Identity::META_KEY );

	/**
	 * Restores one item from its pre-image.
	 *
	 * @param array $session Session.
	 * @param array $row     Item row with pre_image decoded.
	 * @return true|WP_Error
	 */
	public static function restore( array $session, array $row ) {
		$kind  = (string) $row['object_type'];
		$image = is_array( $row['pre_image'] ) ? $row['pre_image'] : array();
		$raw   = isset( $image['raw'] ) && is_array( $image['raw'] ) ? $image['raw'] : array();
		if ( empty( $raw ) ) {
			return new WP_Error( 'snapshot_missing', 'snapshot_missing' );
		}

		switch ( $kind ) {
			case 'option':
			case 'widgets':
				return self::options( $raw, isset( $image['form'] ) && is_array( $image['form'] ) ? $image['form'] : array(), $kind );
			case 'term':
				return self::term( $raw );
			case 'menu':
				return self::menu( $raw );
			case 'product':
				return self::product( $session, $row, $image );
			default:
				return self::post( $raw );
		}
	}

	/**
	 * Removes an object the push created.
	 *
	 * @param array $row Item row.
	 * @return true|WP_Error
	 */
	public static function remove( array $row ) {
		$kind = (string) $row['object_type'];
		$id   = (int) $row['local_object_id'];

		if ( in_array( $kind, array( 'option', 'widgets' ), true ) ) {
			// An option the push added: its pre-image says it did not exist.
			$image = is_array( $row['pre_image'] ) ? $row['pre_image'] : array();
			$raw   = isset( $image['raw'] ) && is_array( $image['raw'] ) ? $image['raw'] : array();
			return empty( $raw ) ? new WP_Error( 'snapshot_missing', 'snapshot_missing' ) : self::options( $raw, array(), $kind );
		}
		if ( $id < 1 ) {
			return true;
		}
		$found = Identity::get( Scope::space( $kind ), $id );
		if ( '' === $found && self::removed_already( $kind, $id ) ) {
			// A step that died after it removed the object, or moved it to the Trash and took its
			// UUID, and before it recorded that: the removal is done.
			return true;
		}
		if ( $found !== (string) $row['uuid'] ) {
			// The object no longer carries this item's UUID: it is not the one the push created.
			return new WP_Error( 'created_object_changed', 'created_object_changed' );
		}

		switch ( $kind ) {
			case 'term':
				$term = get_term( $id );
				if ( ! $term instanceof \WP_Term ) {
					return true;
				}
				$deleted = wp_delete_term( $id, $term->taxonomy );
				return is_wp_error( $deleted ) ? $deleted : true;

			case 'menu':
				if ( ! get_term( $id, 'nav_menu' ) instanceof \WP_Term ) {
					return true;
				}
				$deleted = wp_delete_nav_menu( $id );
				return is_wp_error( $deleted ) ? $deleted : true;

			case 'attachment':
				return self::delete_attachment_keep_files( $id );

			case 'product':
				return self::trash_product( $id );

			default:
				return self::trash_post( $id );
		}
	}

	// --- posts ------------------------------------------------------------------------------------

	/**
	 * A post-like pre-image: row, term assignments, meta.
	 *
	 * @param array $raw Raw pre-image (post, meta, terms).
	 * @return true|WP_Error
	 */
	public static function post( array $raw ) {
		$columns = isset( $raw['post'] ) && is_array( $raw['post'] ) ? self::plain( $raw['post'] ) : array();
		$id      = isset( $columns['ID'] ) ? (int) $columns['ID'] : 0;
		$post    = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return new WP_Error( 'restore_target_missing', 'restore_target_missing' );
		}

		if ( 'trash' === $post->post_status && isset( $columns['post_status'] ) && 'trash' !== $columns['post_status'] ) {
			wp_untrash_post( $id );
		}

		$postarr = array(
			'ID'            => $id,
			// Keeps the recorded dates for drafts too (wp_update_post() clears a draft's date otherwise).
			'edit_date'     => true,
			// Empty: core leaves _wp_page_template alone instead of re-checking the stored value, which
			// it refuses when the theme's classic list lacks it (WooCommerce's "archive-product").
			// The recorded meta, template included, is put back below.
			'page_template' => '',
		);
		foreach ( array( 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged', 'post_content_filtered', 'post_parent', 'menu_order', 'post_mime_type' ) as $field ) {
			if ( array_key_exists( $field, $columns ) && is_scalar( $columns[ $field ] ) ) {
				$postarr[ $field ] = $columns[ $field ];
			}
		}
		$result = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$terms = self::terms( $id, $post->post_type, isset( $raw['terms'] ) && is_array( $raw['terms'] ) ? $raw['terms'] : array() );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		self::meta( 'post', $id, isset( $raw['meta'] ) && is_array( $raw['meta'] ) ? $raw['meta'] : array() );
		clean_post_cache( $id );
		return true;
	}

	/**
	 * Term assignments of a post put back to exactly the recorded IDs, per taxonomy of its type.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @param array  $terms     Taxonomy => term IDs as recorded.
	 * @return true|WP_Error
	 */
	private static function terms( $post_id, $post_type, array $terms ) {
		foreach ( get_object_taxonomies( (string) $post_type ) as $taxonomy ) {
			$want = array();
			foreach ( isset( $terms[ $taxonomy ] ) ? (array) $terms[ $taxonomy ] : array() as $term_id ) {
				$want[] = (int) $term_id;
			}
			sort( $want );
			$now = wp_get_object_terms( (int) $post_id, $taxonomy, array( 'fields' => 'ids' ) );
			$now = is_wp_error( $now ) ? array() : array_map( 'intval', $now );
			sort( $now );
			if ( $now === $want ) {
				continue;
			}
			$set = wp_set_object_terms( (int) $post_id, $want, $taxonomy, false );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
		}
		return true;
	}

	/**
	 * Moves a post the push created to the Trash without its UUID (a draft on a site without a
	 * Trash, instead of being deleted).
	 *
	 * @param int $id Post ID.
	 * @return true|WP_Error
	 */
	private static function trash_post( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post instanceof \WP_Post ) {
			return true;
		}
		// The UUID goes last: a step that dies before that leaves a post the next step still
		// recognises as the one this push created.
		if ( 'trash' !== $post->post_status ) {
			if ( ! EMPTY_TRASH_DAYS ) {
				// This site has no Trash: wp_trash_post() would delete the post for good.
				$draft = wp_update_post(
					array(
						'ID'            => (int) $id,
						'post_status'   => 'draft',
						// See post(): core would re-check the stored template and refuse.
						'page_template' => '',
					),
					true
				);
				if ( is_wp_error( $draft ) ) {
					return $draft;
				}
			} elseif ( ! wp_trash_post( (int) $id ) ) {
				return new WP_Error( 'remove_failed', 'remove_failed' );
			}
		}
		delete_post_meta( (int) $id, Identity::META_KEY );
		return true;
	}

	/**
	 * Moves a product or variation the push created to the Trash through the CRUD.
	 *
	 * @param int $id Product ID.
	 * @return true|WP_Error
	 */
	private static function trash_product( $id ) {
		if ( ! Guard::active() ) {
			return new WP_Error( 'woocommerce_inactive', 'woocommerce_inactive' );
		}
		$product = wc_get_product( (int) $id );
		if ( ! $product instanceof \WC_Product ) {
			return true;
		}
		// The UUID goes last, as in trash_post().
		if ( 'trash' !== $product->get_status() ) {
			if ( ! EMPTY_TRASH_DAYS ) {
				$product->set_status( 'draft' );
				$product->save();
			} else {
				$product->delete( false );
			}
		}
		delete_post_meta( (int) $id, Identity::META_KEY );
		return true;
	}

	/**
	 * Whether an object without its UUID is in the state a finished removal leaves: gone, or a post
	 * or product in the Trash.
	 *
	 * @param string $kind Object type.
	 * @param int    $id   Object ID.
	 * @return bool
	 */
	private static function removed_already( $kind, $id ) {
		if ( 'term' === $kind || 'menu' === $kind ) {
			return ! get_term( (int) $id ) instanceof \WP_Term;
		}
		$post = get_post( (int) $id );
		return ! $post instanceof \WP_Post || ( 'attachment' !== $kind && 'trash' === $post->post_status );
	}

	/**
	 * Deletes an attachment the push created; its files stay for the files pass to decide.
	 *
	 * @param int $id Attachment ID.
	 * @return true|WP_Error
	 */
	private static function delete_attachment_keep_files( $id ) {
		if ( 'attachment' !== get_post_type( (int) $id ) ) {
			return true;
		}
		$keep = static function () {
			return '';
		};
		add_filter( 'wp_delete_file', $keep, PHP_INT_MAX );
		try {
			$deleted = wp_delete_attachment( (int) $id, true );
		} finally {
			remove_filter( 'wp_delete_file', $keep, PHP_INT_MAX );
		}
		return $deleted ? true : new WP_Error( 'remove_failed', 'remove_failed' );
	}

	// --- terms and menus --------------------------------------------------------------------------

	/**
	 * A term pre-image: name, slug, description, parent and meta.
	 *
	 * @param array $raw Raw pre-image (term, meta).
	 * @return true|WP_Error
	 */
	public static function term( array $raw ) {
		$columns  = isset( $raw['term'] ) && is_array( $raw['term'] ) ? self::plain( $raw['term'] ) : array();
		$id       = isset( $columns['term_id'] ) ? (int) $columns['term_id'] : 0;
		$taxonomy = isset( $columns['taxonomy'] ) ? (string) $columns['taxonomy'] : '';
		$term     = $id > 0 ? get_term( $id, $taxonomy ) : null;
		if ( ! $term instanceof \WP_Term ) {
			return new WP_Error( 'restore_target_missing', 'restore_target_missing' );
		}
		$result = wp_update_term(
			$id,
			$taxonomy,
			array(
				'name'        => wp_slash( isset( $columns['name'] ) ? (string) $columns['name'] : $term->name ),
				'slug'        => isset( $columns['slug'] ) ? (string) $columns['slug'] : $term->slug,
				'description' => wp_slash( isset( $columns['description'] ) ? (string) $columns['description'] : '' ),
				'parent'      => isset( $columns['parent'] ) ? (int) $columns['parent'] : 0,
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		self::meta( 'term', $id, isset( $raw['meta'] ) && is_array( $raw['meta'] ) ? $raw['meta'] : array() );
		clean_term_cache( $id, $taxonomy );
		return true;
	}

	/**
	 * A menu unit pre-image: the menu term, then every item it had (untrashed if the push trashed
	 * it); items the push added to the menu are deleted.
	 *
	 * @param array $raw Raw pre-image (term, meta, items).
	 * @return true|WP_Error
	 */
	private static function menu( array $raw ) {
		$done = self::term( $raw );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		$columns = self::plain( $raw['term'] );
		$menu_id = (int) $columns['term_id'];

		$keep = array();
		foreach ( isset( $raw['items'] ) && is_array( $raw['items'] ) ? $raw['items'] : array() as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['post'] ) ) {
				continue;
			}
			$item_columns = self::plain( $item['post'] );
			$item_id      = isset( $item_columns['ID'] ) ? (int) $item_columns['ID'] : 0;
			if ( $item_id < 1 || ! get_post( $item_id ) instanceof \WP_Post ) {
				continue;
			}
			$restored = self::post( $item );
			if ( is_wp_error( $restored ) ) {
				return $restored;
			}
			$keep[ $item_id ] = true;
		}
		foreach ( Menus::item_posts( $menu_id ) as $post ) {
			if ( ! isset( $keep[ (int) $post->ID ] ) ) {
				wp_delete_post( (int) $post->ID, true );
			}
		}
		return true;
	}

	// --- products ---------------------------------------------------------------------------------

	/**
	 * A product pre-image through the CRUD, without the live-owned group.
	 *
	 * @param array $session Session.
	 * @param array $row     Row.
	 * @param array $image   Pre-image.
	 * @return true|WP_Error
	 */
	private static function product( array $session, array $row, array $image ) {
		if ( ! Guard::active() ) {
			return new WP_Error( 'woocommerce_inactive', 'woocommerce_inactive' );
		}
		$form = isset( $image['form'] ) && is_array( $image['form'] ) ? $image['form'] : array();
		$data = Normalizer::decode( $form, Normalizer::local_origins() );
		if ( is_wp_error( $data ) || ! is_array( $data ) || empty( $data['groups']['content'] ) ) {
			return new WP_Error( 'restore_unsupported', 'restore_unsupported' );
		}

		$ctx           = new Item_Context();
		$ctx->session  = $session;
		$ctx->kind     = 'product';
		$ctx->type     = isset( $data['post_type'] ) ? (string) $data['post_type'] : 'product';
		$ctx->live_id  = (int) $row['local_object_id'];
		$ctx->original = $form;
		$ctx->typed    = $form;
		$ctx->data     = $data;
		$ctx->row      = array(
			'id'              => (int) $row['id'],
			'uuid'            => (string) $row['uuid'],
			'object_type'     => 'product',
			'action'          => 'update',
			'local_object_id' => (int) $row['local_object_id'],
			'review'          => '',
			'refs'            => array(),
			'pre_image'       => $image,
			'payload'         => array(
				'type'   => $ctx->type,
				'groups' => array(
					'content'  => 'push',
					'pricing'  => 'push',
					'identity' => 'push',
				),
				// Every product taxonomy is set to what the snapshot had; meta keys the push added go.
				'scope'  => array(
					'taxonomies' => array( 'product_cat', 'product_tag', 'product_brand' ),
					'meta_skip'  => array(),
				),
			),
		);

		$result = Woo_Product::write( $ctx );
		return is_wp_error( $result ) ? $result : true;
	}

	// --- options ----------------------------------------------------------------------------------

	/**
	 * Options as recorded: an option that did not exist is deleted, one that did gets its stored
	 * text back.
	 *
	 * @param array  $raw  Raw pre-image: options => name => {exists, value, autoload}.
	 * @param array  $form Typed form of the item (value, or widgets and sidebars_widgets).
	 * @param string $kind option or widgets.
	 * @return true|WP_Error
	 */
	private static function options( array $raw, array $form, $kind ) {
		$options = isset( $raw['options'] ) && is_array( $raw['options'] ) ? $raw['options'] : array();
		if ( empty( $options ) ) {
			return new WP_Error( 'snapshot_missing', 'snapshot_missing' );
		}
		foreach ( $options as $name => $before ) {
			$name  = (string) $name;
			$typed = null;
			if ( 'option' === $kind && array_key_exists( 'value', $form ) ) {
				$typed = $form['value'];
			} elseif ( 'widgets' === $kind && 'sidebars_widgets' === $name && array_key_exists( 'sidebars_widgets', $form ) ) {
				$typed = $form['sidebars_widgets'];
			} elseif ( 'widgets' === $kind && isset( $form['widgets'] ) && is_array( $form['widgets'] ) && array_key_exists( $name, $form['widgets'] ) ) {
				$typed = $form['widgets'][ $name ];
			}
			$done = self::option( $name, is_array( $before ) ? $before : array(), $typed );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		}
		return true;
	}

	/**
	 * One option as recorded.
	 *
	 * @param string     $name   Option name.
	 * @param array      $before {exists, value (stored text), autoload}.
	 * @param mixed|null $typed  Typed form of the value, when the pre-image has one.
	 * @return true|WP_Error
	 */
	public static function option( $name, array $before, $typed = null ) {
		global $wpdb;

		$now = Snapshot_Store::raw_option( $name );
		if ( empty( $before['exists'] ) ) {
			if ( $now['exists'] ) {
				delete_option( $name );
			}
			return true;
		}

		$text = isset( $before['value'] ) ? self::plain( $before['value'] ) : '';
		$text = is_string( $text ) ? $text : '';
		if ( $now['exists'] && is_string( $now['value'] ) && $now['value'] === $text ) {
			return true;
		}
		$autoload = isset( $before['autoload'] ) ? (string) $before['autoload'] : '';
		$auto     = in_array( $autoload, array( 'on', 'yes', 'auto-on' ), true ) ? true : ( in_array( $autoload, array( 'off', 'no', 'auto-off' ), true ) ? false : null );

		$value = $text;
		$api   = ! is_serialized( $text );
		if ( ! $api && null !== $typed ) {
			// The typed form is used when it rebuilds exactly the recorded text, so update hooks run.
			$decoded = Normalizer::decode( $typed, Normalizer::local_origins() );
			if ( ! is_wp_error( $decoded ) && maybe_serialize( $decoded ) === $text ) {
				$value = $decoded;
				$api   = true;
			}
		}

		if ( $api ) {
			if ( $now['exists'] ) {
				update_option( $name, $value );
			} else {
				add_option( $name, $value, '', $auto );
			}
		} else {
			// Otherwise the recorded text goes back as stored, never unserialized.
			if ( $now['exists'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact restore of a serialized option text recorded by the snapshot; the caches are cleared below.
				$wpdb->update( $wpdb->options, array( 'option_value' => $text ), array( 'option_name' => $name ), array( '%s' ), array( '%s' ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact restore of a serialized option text recorded by the snapshot; the caches are cleared below.
				$wpdb->insert(
					$wpdb->options,
					array(
						'option_name'  => $name,
						'option_value' => $text,
						'autoload'     => '' === $autoload ? 'auto' : $autoload,
					),
					array( '%s', '%s', '%s' )
				);
			}
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		$after = Snapshot_Store::raw_option( $name );
		return $after['exists'] && $after['value'] === $text ? true : new WP_Error( 'restore_inexact', 'restore_inexact' );
	}

	// --- meta -------------------------------------------------------------------------------------

	/**
	 * Meta of a post or term put back key by key (keys live manages only).
	 *
	 * @param string $type 'post' or 'term'.
	 * @param int    $id   Object ID.
	 * @param array  $raw  Key => list of stored texts, as recorded.
	 * @return void
	 */
	public static function meta( $type, $id, array $raw ) {
		$want = array();
		foreach ( $raw as $key => $values ) {
			$list = array();
			foreach ( (array) $values as $value ) {
				$plain  = self::plain( $value );
				$list[] = is_string( $plain ) ? $plain : '';
			}
			$want[ (string) $key ] = $list;
		}
		$now = 'term' === $type ? get_term_meta( (int) $id ) : get_post_meta( (int) $id );
		$now = is_array( $now ) ? $now : array();

		foreach ( array_unique( array_merge( array_keys( $want ), array_map( 'strval', array_keys( $now ) ) ) ) as $key ) {
			$key = (string) $key;
			if ( ! self::managed( $key ) ) {
				continue;
			}
			$before  = isset( $want[ $key ] ) ? $want[ $key ] : array();
			$current = isset( $now[ $key ] ) ? array_map( 'strval', array_values( (array) $now[ $key ] ) ) : array();
			if ( $before === $current ) {
				continue;
			}
			delete_metadata( $type, (int) $id, wp_slash( $key ) );
			foreach ( $before as $text ) {
				self::add_meta( $type, (int) $id, $key, $text );
			}
		}
	}

	/**
	 * Whether live manages a meta key (the keys a push can change).
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	private static function managed( $key ) {
		return in_array( $key, self::FIELD_META, true ) || ! Scope::meta_excluded( $key );
	}

	/**
	 * Adds one stored meta text as it was.
	 *
	 * @param string $type 'post' or 'term'.
	 * @param int    $id   Object ID.
	 * @param string $key  Key.
	 * @param string $text Stored text.
	 * @return void
	 */
	private static function add_meta( $type, $id, $key, $text ) {
		global $wpdb;

		if ( ! is_serialized( $text ) ) {
			add_metadata( $type, $id, wp_slash( $key ), wp_slash( $text ) );
			return;
		}
		$table  = 'term' === $type ? $wpdb->termmeta : $wpdb->postmeta;
		$column = 'term' === $type ? 'term_id' : 'post_id';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Exact restore of a serialized meta text recorded by the snapshot (add_metadata() would serialize it again); the meta cache is cleared below.
		$wpdb->insert(
			$table,
			array(
				$column      => (int) $id,
				'meta_key'   => (string) $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- An insert, not a query by key.
				'meta_value' => (string) $text, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- An insert, not a query by value.
			),
			array( '%d', '%s', '%s' )
		);
		wp_cache_delete( (int) $id, $type . '_meta' );
	}

	/**
	 * A recorded value with the {"__sgsp":"bin","hex":…} marks of non-UTF-8 strings turned back
	 * into their bytes (Snapshot_Store::encode()).
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	public static function plain( $value, $depth = 0 ) {
		if ( $depth > 16 || ! is_array( $value ) ) {
			return $value;
		}
		if ( isset( $value['__sgsp'], $value['hex'] ) && 'bin' === $value['__sgsp'] && is_string( $value['hex'] ) && 1 === preg_match( '/\A(?:[0-9a-f]{2})*\z/', $value['hex'] ) ) {
			return (string) hex2bin( $value['hex'] );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::plain( $item, $depth + 1 );
		}
		return $value;
	}
}
