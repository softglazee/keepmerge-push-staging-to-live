<?php
/**
 * ID references inside items.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds every numeric reference to another object inside an item (blueprint §8.3) and builds
 * the reference sidecar: one entry { path, kind, id, uuid } per location. IDs are never
 * rewritten in the item on the sending side; live resolves each UUID to its own ID and rewrites
 * only those locations (Phase 7).
 *
 * Block content is read with parse_blocks() and never serialized back here. The same walk can
 * also produce the hash form of an item: every reference location replaced by a canonical
 * token that both sites compute alike (the Hasher's job), so an item has the same hash on both
 * sites even when its IDs differ.
 *
 * The registry is extensible with the softglaze_sp_reference_rules filter; built-in rules always
 * win over filtered ones, so a filter can add rules but not remove or change one.
 */
final class Reference_Mapper {

	/**
	 * ID space of each reference kind.
	 */
	const KIND_SPACE = array(
		'post'       => 'post',
		'attachment' => 'post',
		'pattern'    => 'post',
		'navigation' => 'post',
		'menu_item'  => 'post',
		'term'       => 'term',
		'menu'       => 'term',
	);

	/**
	 * Rule specs: a kind (one ID), "kind[]" (a list or comma-separated IDs), "kind{}[]" (a list
	 * of objects with an "id"), and "nav" (navigation links: a post or a term by their kind).
	 */
	const SPEC_PATTERN = '/\A(?:(?:post|attachment|pattern|navigation|menu_item|term|menu)(?:\[\]|\{\}\[\])?|nav)\z/';

	/**
	 * Block attributes holding IDs (blueprint §8.3).
	 */
	const BLOCK_RULES = array(
		'core/image'              => array( 'id' => 'attachment' ),
		'core/gallery'            => array( 'ids' => 'attachment[]' ),
		'core/cover'              => array( 'id' => 'attachment' ),
		'core/media-text'         => array( 'mediaId' => 'attachment' ),
		'core/file'               => array( 'id' => 'attachment' ),
		'core/video'              => array( 'id' => 'attachment' ),
		'core/audio'              => array( 'id' => 'attachment' ),
		'core/block'              => array( 'ref' => 'pattern' ),
		'core/navigation'         => array( 'ref' => 'navigation' ),
		'core/navigation-link'    => array( 'id' => 'nav' ),
		'core/navigation-submenu' => array( 'id' => 'nav' ),
		'core/page-list'          => array( 'parentPageID' => 'post' ),
		'core/latest-posts'       => array( 'categories' => 'term{}[]' ),
	);

	/**
	 * Attributes of WooCommerce product blocks (any block in the woocommerce/ namespace).
	 */
	const WOO_BLOCK_ATTRS = array(
		'productId'  => 'post',
		'products'   => 'post[]',
		'categories' => 'term[]',
		'categoryId' => 'term',
		'attributes' => 'term{}[]',
	);

	/**
	 * Shortcode attributes holding IDs. "attachment_" is the caption's id prefix.
	 */
	const SHORTCODES = array(
		'gallery'      => array(
			'ids'     => 'attachment[]',
			'include' => 'attachment[]',
		),
		'caption'      => array( 'id' => 'attachment_prefixed' ),
		'product'      => array( 'id' => 'post' ),
		'products'     => array( 'ids' => 'post[]' ),
		'add_to_cart'  => array( 'id' => 'post' ),
		'product_page' => array( 'id' => 'post' ),
	);

	/**
	 * ID-valued options (blueprint §6.9, the names marked *).
	 */
	const OPTION_RULES = array(
		'site_icon'                     => 'attachment',
		'page_on_front'                 => 'post',
		'page_for_posts'                => 'post',
		'woocommerce_shop_page_id'      => 'post',
		'woocommerce_cart_page_id'      => 'post',
		'woocommerce_checkout_page_id'  => 'post',
		'woocommerce_myaccount_page_id' => 'post',
		'woocommerce_terms_page_id'     => 'post',
	);

	/**
	 * Term meta holding IDs (WooCommerce category images).
	 */
	const TERM_META_RULES = array( 'thumbnail_id' => 'attachment' );

	/**
	 * Returns the UUID of an object: function( string $space, int $id ): string.
	 *
	 * @var callable
	 */
	private $uuid_fn;

	/**
	 * Returns the canonical token of a reference for the hash form, or null for a scan only:
	 * function( string $space, int $id, string $uuid, bool $exists ): string.
	 *
	 * @var callable|null
	 */
	private $canon_fn;

	/**
	 * Turns a tokenized URL into this site's URL (for attachment lookups).
	 *
	 * @var callable
	 */
	private $url_fn;

	/**
	 * Sidecar entries.
	 *
	 * @var array[]
	 */
	private $refs = array();

	/**
	 * Numeric references that point to nothing known.
	 *
	 * @var array[]
	 */
	private $unmapped = array();

	/**
	 * Block types used.
	 *
	 * @var array<string,bool>
	 */
	private $block_types = array();

	/**
	 * Locations met in the current subtree (to know whether a subtree changed).
	 *
	 * @var int
	 */
	private $hits = 0;

	/**
	 * Merged rules.
	 *
	 * @var array|null
	 */
	private static $rules = null;

	/**
	 * Creates a mapper.
	 *
	 * @param callable      $uuid_fn  UUID lookup (or assignment on the source).
	 * @param callable|null $canon_fn Canonical token for the hash form; null to scan only.
	 * @param callable|null $url_fn   Tokenized URL to local URL; defaults to this site's origins.
	 */
	public function __construct( $uuid_fn, $canon_fn = null, $url_fn = null ) {
		$this->uuid_fn  = $uuid_fn;
		$this->canon_fn = $canon_fn;
		$this->url_fn   = null !== $url_fn ? $url_fn : array( __CLASS__, 'local_url' );
	}

	/**
	 * Forgets the merged rules (the filter changed; tests).
	 *
	 * @return void
	 */
	public static function flush_rules() {
		self::$rules = null;
	}

	/**
	 * Scans an item's normal form.
	 *
	 * @param string $kind Item kind.
	 * @param array  $data Normal form (typed values).
	 * @return array{refs:array[],unmapped:array[],block_types:string[],hash_form:array}
	 */
	public function scan( $kind, array $data ) {
		$this->refs        = array();
		$this->unmapped    = array();
		$this->block_types = array();

		$form = $this->walk_kind( (string) $kind, $data );

		$types = array_keys( $this->block_types );
		sort( $types );

		return array(
			'refs'        => $this->refs,
			'unmapped'    => $this->unmapped,
			'block_types' => $types,
			'hash_form'   => $form,
		);
	}

	/**
	 * The registry: built-in rules plus valid additions from the softglaze_sp_reference_rules
	 * filter (blocks: name => attr => spec; post_meta and term_meta: key => spec; options:
	 * name => spec).
	 *
	 * @return array{blocks:array,post_meta:array,term_meta:array,options:array}
	 */
	public static function rules() {
		if ( null !== self::$rules ) {
			return self::$rules;
		}

		$built_in = array(
			'blocks'    => self::BLOCK_RULES,
			'post_meta' => array(),
			'term_meta' => self::TERM_META_RULES,
			'options'   => self::OPTION_RULES,
		);

		/**
		 * Adds ID-reference rules (blueprint §8.3). Entries that already exist keep their built-in
		 * spec; entries with an unknown name or spec are ignored.
		 *
		 * @param array $rules {
		 *     @type array $blocks    Block name => array( attribute => spec ).
		 *     @type array $post_meta Post meta key => spec.
		 *     @type array $term_meta Term meta key => spec.
		 *     @type array $options   Option name => spec.
		 * }
		 */
		$filtered = apply_filters( 'softglaze_sp_reference_rules', $built_in );
		$merged   = $built_in;

		if ( is_array( $filtered ) ) {
			if ( isset( $filtered['blocks'] ) && is_array( $filtered['blocks'] ) ) {
				foreach ( $filtered['blocks'] as $name => $attrs ) {
					if ( ! is_string( $name ) || 1 !== preg_match( '#\A[a-z0-9-]+/[a-z0-9-]+\z#', $name ) || ! is_array( $attrs ) ) {
						continue;
					}
					foreach ( $attrs as $attr => $spec ) {
						if ( is_string( $attr ) && 1 === preg_match( '/\A[A-Za-z0-9_]{1,64}\z/', $attr ) && is_string( $spec ) && 1 === preg_match( self::SPEC_PATTERN, $spec ) && ! isset( $built_in['blocks'][ $name ][ $attr ] ) ) {
							$merged['blocks'][ $name ][ $attr ] = $spec;
						}
					}
				}
			}
			foreach ( array( 'post_meta', 'term_meta', 'options' ) as $section ) {
				if ( ! isset( $filtered[ $section ] ) || ! is_array( $filtered[ $section ] ) ) {
					continue;
				}
				foreach ( $filtered[ $section ] as $key => $spec ) {
					if ( is_string( $key ) && 1 === preg_match( '/\A[A-Za-z0-9_.:-]{1,191}\z/', $key ) && is_string( $spec ) && 1 === preg_match( self::SPEC_PATTERN, $spec ) && 'nav' !== $spec && ! isset( $built_in[ $section ][ $key ] ) ) {
						$merged[ $section ][ $key ] = $spec;
					}
				}
			}
		}

		self::$rules = $merged;
		return self::$rules;
	}

	/**
	 * A tokenized URL as this site's URL, for attachment_url_to_postid(). Inside decoded JSON a
	 * JSON-escaped token stands for a plain URL.
	 *
	 * @param string $url Tokenized URL.
	 * @return string
	 */
	public static function local_url( $url ) {
		$url = str_replace( array( '{{sgsp:home:j}}', '{{sgsp:site:j}}' ), array( '{{sgsp:home}}', '{{sgsp:site}}' ), (string) $url );
		return Normalizer::detokenize( $url, Normalizer::local_origins() );
	}

	/**
	 * The walk for one kind.
	 *
	 * @param string $kind Kind.
	 * @param array  $data Normal form.
	 * @return array Hash form.
	 */
	private function walk_kind( $kind, array $data ) {
		$rules = self::rules();
		$form  = $data;

		switch ( $kind ) {
			case 'post':
			case 'pattern':
			case 'navigation':
			case 'template':
			case 'template_part':
			case 'attachment':
				foreach ( array(
					'post_parent'    => 'post',
					'featured_image' => 'attachment',
				) as $field => $ref_kind ) {
					if ( array_key_exists( $field, $data ) ) {
						$form[ $field ] = $this->id_at( $field, $ref_kind, $data[ $field ] );
					}
				}
				if ( isset( $data['terms'] ) && is_array( $data['terms'] ) ) {
					$form['terms'] = $this->terms( 'terms', $data['terms'] );
				}
				foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
					if ( isset( $data[ $field ] ) && 'attachment' !== $kind ) {
						$form[ $field ] = $this->content( $field, $data[ $field ] );
					}
				}
				if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
					$form['meta'] = $this->meta( 'meta', $data['meta'], $rules['post_meta'] );
				}
				break;

			case 'product':
				$form = $this->product( $data, $rules );
				break;

			case 'term':
			case 'menu':
				if ( array_key_exists( 'parent', $data ) ) {
					$form['parent'] = $this->id_at( 'parent', 'term', $data['parent'] );
				}
				if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
					$form['meta'] = $this->meta( 'meta', $data['meta'], $rules['term_meta'] );
				}
				if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
					$form['items'] = $this->menu_items( $data['items'], $rules );
				}
				break;

			case 'option':
				$form = $this->option( $data, $rules );
				break;

			case 'widgets':
				if ( isset( $data['widgets'] ) && is_array( $data['widgets'] ) ) {
					$form['widgets'] = $this->widgets( $data['widgets'] );
				}
				break;
		}

		return $form;
	}

	/**
	 * Product fields (blueprint §6.6 groups).
	 *
	 * @param array $data  Normal form.
	 * @param array $rules Rules.
	 * @return array
	 */
	private function product( array $data, array $rules ) {
		$form = $data;
		if ( ! isset( $data['groups']['content'] ) || ! is_array( $data['groups']['content'] ) ) {
			return $form;
		}
		$content = $data['groups']['content'];
		$out     = $content;
		$base    = 'groups/content/';

		foreach ( array(
			'parent'            => 'post',
			'image_id'          => 'attachment',
			'shipping_class_id' => 'term',
		) as $field => $kind ) {
			if ( array_key_exists( $field, $content ) ) {
				$out[ $field ] = $this->id_at( $base . $field, $kind, $content[ $field ] );
			}
		}
		foreach ( array(
			'gallery_image_ids' => 'attachment',
			'upsell_ids'        => 'post',
			'cross_sell_ids'    => 'post',
			'children'          => 'post',
		) as $field => $kind ) {
			if ( isset( $content[ $field ] ) ) {
				$out[ $field ] = $this->id_list( $base . $field, $kind, $content[ $field ] );
			}
		}
		if ( isset( $content['terms'] ) && is_array( $content['terms'] ) ) {
			$out['terms'] = $this->terms( $base . 'terms', $content['terms'] );
		}
		if ( isset( $content['attributes'] ) && is_array( $content['attributes'] ) ) {
			foreach ( $content['attributes'] as $i => $attribute ) {
				if ( is_array( $attribute ) && ! empty( $attribute['taxonomy'] ) && isset( $attribute['options'] ) ) {
					$out['attributes'][ $i ]['options'] = $this->id_list( $base . 'attributes/' . $i . '/options', 'term', $attribute['options'] );
				}
			}
		}
		foreach ( array( 'description', 'short_description' ) as $field ) {
			if ( isset( $content[ $field ] ) ) {
				$out[ $field ] = $this->content( $base . $field, $content[ $field ] );
			}
		}
		if ( isset( $content['meta'] ) && is_array( $content['meta'] ) ) {
			$out['meta'] = $this->meta( $base . 'meta', $content['meta'], $rules['post_meta'] );
		}

		$form['groups']['content'] = $out;
		return $form;
	}

	/**
	 * Menu items: object (post or term) and parent item.
	 *
	 * @param array $items Items.
	 * @param array $rules Rules.
	 * @return array
	 */
	private function menu_items( array $items, array $rules ) {
		$out = $items;
		foreach ( $items as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$type = isset( $item['type'] ) ? (string) $item['type'] : '';
			if ( isset( $item['object_id'] ) && ( 'post_type' === $type || 'taxonomy' === $type ) ) {
				$out[ $i ]['object_id'] = $this->id_at( 'items/' . $i . '/object_id', 'post_type' === $type ? 'post' : 'term', $item['object_id'] );
			}
			if ( isset( $item['parent'] ) ) {
				$out[ $i ]['parent'] = $this->id_at( 'items/' . $i . '/parent', 'menu_item', $item['parent'] );
			}
			if ( isset( $item['meta'] ) && is_array( $item['meta'] ) ) {
				$out[ $i ]['meta'] = $this->meta( 'items/' . $i . '/meta', $item['meta'], $rules['post_meta'] );
			}
		}
		return $out;
	}

	/**
	 * Options: the registry, theme mods, then a generic look for { url, id } pairs.
	 *
	 * @param array $data  Normal form (name, value).
	 * @param array $rules Rules.
	 * @return array
	 */
	private function option( array $data, array $rules ) {
		$form = $data;
		$name = isset( $data['name'] ) ? (string) $data['name'] : '';
		if ( ! array_key_exists( 'value', $data ) ) {
			return $form;
		}
		$value = $data['value'];

		if ( isset( $rules['options'][ $name ] ) ) {
			$form['value'] = $this->apply_spec( 'value', $rules['options'][ $name ], $value );
			return $form;
		}

		if ( 0 === strpos( $name, 'theme_mods_' ) && is_array( $value ) ) {
			$out = $value;
			if ( isset( $value['nav_menu_locations'] ) && is_array( $value['nav_menu_locations'] ) ) {
				foreach ( $value['nav_menu_locations'] as $location => $menu ) {
					$out['nav_menu_locations'][ $location ] = $this->id_at( 'value/nav_menu_locations/' . $location, 'menu', $menu );
				}
			}
			if ( array_key_exists( 'custom_logo', $value ) ) {
				$out['custom_logo'] = $this->id_at( 'value/custom_logo', 'attachment', $value['custom_logo'] );
			}
			if ( isset( $value['header_image_data'] ) ) {
				$header = $value['header_image_data'];
				if ( is_array( $header ) && isset( $header[ Normalizer::MARK ], $header['props']['attachment_id'] ) ) {
					$out['header_image_data']['props']['attachment_id'] = $this->id_at( 'value/header_image_data/props/attachment_id', 'attachment', $header['props']['attachment_id'] );
				} elseif ( is_array( $header ) && isset( $header['attachment_id'] ) ) {
					$out['header_image_data']['attachment_id'] = $this->id_at( 'value/header_image_data/attachment_id', 'attachment', $header['attachment_id'] );
				}
			}
			$form['value'] = $out;
			return $form;
		}

		$form['value'] = $this->generic( 'value', $value );
		return $form;
	}

	/**
	 * The widgets unit: media and menu widgets, block and text widgets' content.
	 *
	 * @param array $widgets Option name => value.
	 * @return array
	 */
	private function widgets( array $widgets ) {
		$fields  = array(
			'widget_media_image'   => array( 'attachment_id' => 'attachment' ),
			'widget_media_audio'   => array( 'attachment_id' => 'attachment' ),
			'widget_media_video'   => array( 'attachment_id' => 'attachment' ),
			'widget_media_gallery' => array( 'ids' => 'attachment[]' ),
			'widget_nav_menu'      => array( 'nav_menu' => 'menu' ),
		);
		$content = array(
			'widget_block'       => 'content',
			'widget_text'        => 'text',
			'widget_custom_html' => 'content',
		);

		$out = $widgets;
		foreach ( $widgets as $name => $instances ) {
			if ( ! is_array( $instances ) ) {
				continue;
			}
			foreach ( $instances as $number => $instance ) {
				if ( ! is_array( $instance ) ) {
					continue;
				}
				$path = 'widgets/' . $name . '/' . $number;
				if ( isset( $fields[ $name ] ) ) {
					foreach ( $fields[ $name ] as $field => $spec ) {
						if ( array_key_exists( $field, $instance ) ) {
							$out[ $name ][ $number ][ $field ] = $this->apply_spec( $path . '/' . $field, $spec, $instance[ $field ] );
						}
					}
				}
				if ( isset( $content[ $name ], $instance[ $content[ $name ] ] ) ) {
					$out[ $name ][ $number ][ $content[ $name ] ] = $this->content( $path . '/' . $content[ $name ], $instance[ $content[ $name ] ] );
				}
			}
		}
		return $out;
	}

	/**
	 * Term assignments: taxonomy => list of term IDs.
	 *
	 * @param string $path  Path.
	 * @param array  $terms Terms.
	 * @return array
	 */
	private function terms( $path, array $terms ) {
		$out = $terms;
		foreach ( $terms as $taxonomy => $ids ) {
			$out[ $taxonomy ] = $this->id_list( $path . '/' . $taxonomy, 'term', $ids );
		}
		return $out;
	}

	/**
	 * Meta: registry rules by key, else the generic look.
	 *
	 * @param string $path  Path.
	 * @param array  $meta  Key => list of values.
	 * @param array  $rules Key => spec.
	 * @return array
	 */
	private function meta( $path, array $meta, array $rules ) {
		$out = $meta;
		foreach ( $meta as $key => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}
			foreach ( $values as $i => $value ) {
				$at = $path . '/' . $key . '/' . $i;
				if ( isset( $rules[ $key ] ) ) {
					$out[ $key ][ $i ] = $this->apply_spec( $at, $rules[ $key ], $value );
				} else {
					$out[ $key ][ $i ] = $this->generic( $at, $value );
				}
			}
		}
		return $out;
	}

	/**
	 * Generic look for page-builder references: objects with a "url" and an "id". The ID is a
	 * reference when the URL is that attachment on this site; otherwise it is reported as an
	 * unmapped numeric reference. Strings holding JSON are looked into as well.
	 *
	 * @param string $path  Path.
	 * @param mixed  $value Typed value.
	 * @param int    $depth Depth.
	 * @return mixed Hash form.
	 */
	private function generic( $path, $value, $depth = 0 ) {
		if ( $depth > Normalizer::MAX_DEPTH ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$first = substr( ltrim( $value ), 0, 1 );
			if ( ( '{' !== $first && '[' !== $first ) || strlen( $value ) > 4194304 || ( false === strpos( $value, '"id"' ) && false === strpos( $value, '"url"' ) ) ) {
				return $value;
			}
			$decoded = json_decode( $value, true, Normalizer::MAX_DEPTH );
			if ( ! is_array( $decoded ) ) {
				return $value;
			}
			$before = $this->hits;
			$form   = $this->generic( $path . '/json', $decoded, $depth + 1 );
			if ( $this->hits === $before || null === $this->canon_fn ) {
				return $value;
			}
			return array(
				Normalizer::MARK => 'json',
				'v'              => $form,
			);
		}

		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_key_exists( Normalizer::MARK, $value ) ) {
			$mark = $value[ Normalizer::MARK ];
			if ( 'object' === $mark && isset( $value['props'] ) && is_array( $value['props'] ) ) {
				$value['props'] = $this->generic( $path . '/props', $value['props'], $depth + 1 );
			} elseif ( 'ser' === $mark && array_key_exists( 'v', $value ) ) {
				$value['v'] = $this->generic( $path . '/v', $value['v'], $depth + 1 );
			}
			return $value;
		}

		$out = $value;
		if ( isset( $value['url'], $value['id'] ) && is_string( $value['url'] ) && self::positive_id( $value['id'] ) ) {
			$id        = (int) $value['id'];
			$found     = (int) attachment_url_to_postid( (string) call_user_func( $this->url_fn, $value['url'] ) );
			$out['id'] = $found === $id ? $this->id_at( $path . '/id', 'attachment', $value['id'] ) : $this->unmapped_at( $path . '/id', $value['id'] );
		}
		foreach ( $value as $key => $item ) {
			if ( 'id' === $key && isset( $value['url'] ) ) {
				continue;
			}
			if ( is_array( $item ) || is_string( $item ) ) {
				$out[ $key ] = $this->generic( $path . '/' . $key, $item, $depth + 1 );
			}
		}
		return $out;
	}

	/**
	 * Block or classic content.
	 *
	 * @param string $path  Path of the field.
	 * @param mixed  $value Typed value (a string, or a marker that is left alone).
	 * @return mixed Hash form.
	 */
	private function content( $path, $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		$before = $this->hits;

		if ( false !== strpos( $value, '<!-- wp:' ) ) {
			$tree = $this->blocks( $path . '/blocks', parse_blocks( $value ) );
			if ( $this->hits === $before || null === $this->canon_fn ) {
				return $value;
			}
			return array(
				Normalizer::MARK => 'blocks',
				'tree'           => $tree,
			);
		}

		$text = $this->chunk( $path . '/html', $value );
		return null === $this->canon_fn ? $value : $text;
	}

	/**
	 * Parsed blocks: attributes by rule, then each piece of inner HTML.
	 *
	 * @param string $path   Path.
	 * @param array  $blocks Blocks from parse_blocks().
	 * @param string $prefix Block path prefix.
	 * @return array Tree for the hash form.
	 */
	private function blocks( $path, array $blocks, $prefix = '' ) {
		$tree  = array();
		$rules = self::rules();

		foreach ( $blocks as $index => $block ) {
			$at    = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			$name  = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : null;
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

			if ( null !== $name ) {
				$this->block_types[ $name ] = true;
				$block_rules                = isset( $rules['blocks'][ $name ] ) ? $rules['blocks'][ $name ] : array();
				if ( 0 === strpos( $name, 'woocommerce/' ) ) {
					$block_rules = array_merge( self::WOO_BLOCK_ATTRS, $block_rules );
				}
				foreach ( $block_rules as $attr => $spec ) {
					if ( ! array_key_exists( $attr, $attrs ) ) {
						continue;
					}
					if ( 'nav' === $spec ) {
						$kind = isset( $attrs['kind'] ) ? (string) $attrs['kind'] : 'post-type';
						if ( 'custom' === $kind ) {
							continue;
						}
						$spec = 'taxonomy' === $kind ? 'term' : 'post';
					}
					$attrs[ $attr ] = $this->apply_spec( $path . '/' . $at . '/attrs/' . $attr, $spec, $attrs[ $attr ] );
				}
			}

			$content = array();
			$pieces  = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : array();
			foreach ( $pieces as $i => $piece ) {
				$content[] = is_string( $piece ) ? $this->chunk( $path . '/' . $at . '/html/' . $i, $piece ) : null;
			}

			$tree[] = array(
				'name'    => $name,
				'attrs'   => $attrs,
				'content' => $content,
				'inner'   => isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $this->blocks( $path, $block['innerBlocks'], $at ) : array(),
			);
		}

		return $tree;
	}

	/**
	 * One piece of HTML: wp-image-<id> classes and ID shortcodes.
	 *
	 * @param string $path Path of the piece.
	 * @param string $html HTML.
	 * @return string Hash form of the piece.
	 */
	private function chunk( $path, $html ) {
		$out = $html;

		if ( false !== strpos( $out, 'wp-image-' ) ) {
			$n      = 0;
			$mapper = $this;
			$out    = (string) preg_replace_callback(
				'/\bwp-image-([0-9]+)\b/',
				static function ( $m ) use ( $mapper, $path, &$n ) {
					$token = $mapper->location( $path . '/wp-image/' . $n, 'attachment', (int) $m[1] );
					++$n;
					return 'wp-image-' . $token;
				},
				$out
			);
		}

		if ( false !== strpos( $out, '[' ) ) {
			$tags   = implode( '|', array_keys( self::SHORTCODES ) );
			$n      = 0;
			$mapper = $this;
			$out    = (string) preg_replace_callback(
				'/\[(' . $tags . ')\b([^\]]*)\]/',
				static function ( $m ) use ( $mapper, $path, &$n ) {
					$result = '[' . $m[1] . $mapper->shortcode_attrs( $path . '/shortcode/' . $n, $m[1], $m[2] ) . ']';
					++$n;
					return $result;
				},
				$out
			);
		}

		return $out;
	}

	/**
	 * The attribute text of one shortcode with its ID values replaced in the hash form.
	 *
	 * @param string $path  Path of the shortcode.
	 * @param string $tag   Tag.
	 * @param string $attrs Attribute text.
	 * @return string
	 */
	public function shortcode_attrs( $path, $tag, $attrs ) {
		if ( ! isset( self::SHORTCODES[ $tag ] ) ) {
			return $attrs;
		}
		foreach ( self::SHORTCODES[ $tag ] as $attr => $spec ) {
			$mapper = $this;
			$attrs  = (string) preg_replace_callback(
				'/(\b' . preg_quote( $attr, '/' ) . '\s*=\s*)(["\']?)([^"\'\s\]]*)\2/',
				static function ( $m ) use ( $mapper, $path, $attr, $spec ) {
					return $m[1] . $m[2] . $mapper->shortcode_value( $path . '/' . $attr, $spec, $m[3] ) . $m[2];
				},
				$attrs
			);
		}
		return $attrs;
	}

	/**
	 * One shortcode attribute value.
	 *
	 * @param string $path  Path.
	 * @param string $spec  Spec.
	 * @param string $value Value.
	 * @return string
	 */
	public function shortcode_value( $path, $spec, $value ) {
		if ( 'attachment_prefixed' === $spec ) {
			if ( 1 !== preg_match( '/\Aattachment_([0-9]+)\z/', $value, $m ) ) {
				return $value;
			}
			return 'attachment_' . $this->location( $path, 'attachment', (int) $m[1] );
		}
		$kind  = rtrim( $spec, '[]' );
		$parts = explode( ',', $value );
		foreach ( $parts as $i => $part ) {
			$part = trim( $part );
			if ( self::positive_id( $part ) ) {
				$parts[ $i ] = $this->location( $path . '/' . $i, $kind, (int) $part );
			}
		}
		return implode( ',', $parts );
	}

	/**
	 * Applies a rule spec to a value.
	 *
	 * @param string $path  Path.
	 * @param string $spec  Spec.
	 * @param mixed  $value Typed value.
	 * @return mixed
	 */
	private function apply_spec( $path, $spec, $value ) {
		if ( '{}[]' === substr( $spec, -4 ) ) {
			$kind = substr( $spec, 0, -4 );
			if ( ! is_array( $value ) ) {
				return $this->id_at( $path, $kind, $value );
			}
			$out = $value;
			foreach ( $value as $i => $item ) {
				if ( is_array( $item ) && array_key_exists( 'id', $item ) ) {
					$out[ $i ]['id'] = $this->id_at( $path . '/' . $i . '/id', $kind, $item['id'] );
				} elseif ( ! is_array( $item ) ) {
					$out[ $i ] = $this->id_at( $path . '/' . $i, $kind, $item );
				}
			}
			return $out;
		}
		if ( '[]' === substr( $spec, -2 ) ) {
			return $this->id_list( $path, substr( $spec, 0, -2 ), $value );
		}
		return $this->id_at( $path, $spec, $value );
	}

	/**
	 * A list of IDs (array, or comma-separated string).
	 *
	 * @param string $path  Path.
	 * @param string $kind  Kind.
	 * @param mixed  $value List.
	 * @return mixed
	 */
	private function id_list( $path, $kind, $value ) {
		if ( is_string( $value ) ) {
			$parts = explode( ',', $value );
			foreach ( $parts as $i => $part ) {
				$part = trim( $part );
				if ( self::positive_id( $part ) ) {
					$parts[ $i ] = $this->location( $path . '/' . $i, $kind, (int) $part );
				}
			}
			return null === $this->canon_fn ? $value : implode( ',', $parts );
		}
		if ( ! is_array( $value ) ) {
			return $this->id_at( $path, $kind, $value );
		}
		$out = $value;
		foreach ( $value as $i => $item ) {
			$out[ $i ] = $this->id_at( $path . '/' . $i, $kind, $item );
		}
		return $out;
	}

	/**
	 * One ID location (an int or a digit string greater than zero; anything else is left).
	 *
	 * @param string $path  Path.
	 * @param string $kind  Kind.
	 * @param mixed  $value Value.
	 * @return mixed Hash-form value.
	 */
	private function id_at( $path, $kind, $value ) {
		if ( ! self::positive_id( $value ) ) {
			return $value;
		}
		$token = $this->location( $path, $kind, (int) $value );
		return null === $this->canon_fn ? $value : $token;
	}

	/**
	 * An unmapped numeric reference (reported; canonical form "x:<id>").
	 *
	 * @param string $path  Path.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	private function unmapped_at( $path, $value ) {
		++$this->hits;
		$this->unmapped[] = array(
			'path'  => $path,
			'value' => (int) $value,
		);
		return null === $this->canon_fn ? $value : 'x:' . (int) $value;
	}

	/**
	 * Records a location and returns its hash-form token (or the ID as text for a scan).
	 *
	 * @param string $path Path.
	 * @param string $kind Kind.
	 * @param int    $id   ID.
	 * @return string
	 */
	public function location( $path, $kind, $id ) {
		++$this->hits;
		$space  = isset( self::KIND_SPACE[ $kind ] ) ? self::KIND_SPACE[ $kind ] : 'post';
		$exists = Identity::exists( $space, $id );

		if ( ! $exists ) {
			$this->unmapped[] = array(
				'path'  => $path,
				'value' => (int) $id,
			);
			return null === $this->canon_fn ? (string) $id : 'x:' . (int) $id;
		}

		$uuid         = (string) call_user_func( $this->uuid_fn, $space, $id );
		$this->refs[] = array(
			'path' => $path,
			'kind' => $kind,
			'id'   => (int) $id,
			'uuid' => $uuid,
		);

		return null === $this->canon_fn ? (string) $id : (string) call_user_func( $this->canon_fn, $space, (int) $id, $uuid, true );
	}

	/**
	 * Whether a value is a positive integer or a string of digits for one.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function positive_id( $value ) {
		if ( is_int( $value ) ) {
			return $value > 0;
		}
		return is_string( $value ) && 1 === preg_match( '/\A[1-9][0-9]{0,18}\z/', $value );
	}
}
