<?php
/**
 * Validation of received items before they are staged on live.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Transfer;

use SoftGlaze\SafePush\Apply\Markup_Gate;
use SoftGlaze\SafePush\Sync\Exporters\Menus;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Manifest;
use SoftGlaze\SafePush\Sync\Normalizer;
use SoftGlaze\SafePush\Sync\Option_Policy;
use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Sync\Sync_State;
use SoftGlaze\SafePush\Woo\Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Step 12 of the remote chain for item batches (blueprint §10.2, threat model §7.4): one
 * received item envelope in, one row to stage out. Nothing here writes; Push_Session stores the
 * result.
 *
 * Every field is read by name and checked on its own against the schema of the item's kind;
 * unknown keys are dropped. Live decides with its own settings: the post type or taxonomy must be
 * included here and never denied, an option must be on this site's own allowlist and pass
 * Option_Policy (the source's allowlist cannot widen it), meta keys excluded here are dropped, a
 * page template must exist in this site's theme, attachment paths must pass Path_Guard and an
 * attachment's files must be among this push's committed files (or files live already has), and
 * every reference must name an item of this push or an object this site includes. Values are decoded
 * with Normalizer::decode(), which builds arrays, scalars and stdClass only: nothing received is
 * ever unserialized. The markup gate (Phase 4, unchanged) then holds items for a live
 * administrator.
 */
final class Item_Stager {

	/**
	 * Plan actions the source sends and the row action each one becomes on live.
	 */
	const PLAN_ACTIONS = array(
		'create'        => 'create',
		'push'          => 'update',
		'use_staging'   => 'update',
		'recreate'      => 'recreate',
		'trash_on_live' => 'trash',
		'record_base'   => 'adopt',
	);

	/**
	 * Reasons the source may give for an item it could not send as planned (the row is refused
	 * with that reason, so the counts of the session still add up).
	 */
	const SKIPS = array( 'changed_since_plan', 'too_large', 'not_exportable' );

	/**
	 * Group actions a product item may carry (Classifier::ACTIONS that apply to groups).
	 */
	const GROUP_ACTIONS = array( 'push', 'use_staging', 'keep_live', 'none', 'record_base', 'push_stock', 'create', 'skip' );

	const GROUP_NAMES = array( 'content', 'pricing', 'identity', 'live_owned' );

	const MAX_REFS = 5000;

	const MAX_MENU_ITEMS = 2000;

	const MAX_FILES = 200;

	const MAX_GATE_REASONS = 10;

	/**
	 * Kinds a received reference may name, and the item kinds that satisfy each.
	 */
	const REF_KINDS = array(
		'post'       => array( 'post', 'product', 'attachment', 'pattern', 'navigation', 'template', 'template_part' ),
		'attachment' => array( 'attachment' ),
		'pattern'    => array( 'pattern' ),
		'navigation' => array( 'navigation' ),
		'menu_item'  => array( 'menu_item' ),
		'term'       => array( 'term' ),
		'menu'       => array( 'menu' ),
	);

	/**
	 * Fields of post-like items (kind post).
	 */
	const POST_FIELDS = array(
		'post_type'      => array( 'key', true ),
		'post_title'     => array( 'str', true ),
		'post_content'   => array( 'str', true ),
		'post_excerpt'   => array( 'str', false ),
		'post_status'    => array( 'enum:publish|future|draft|pending|private', true ),
		'post_name'      => array( 'slug', false ),
		'post_password'  => array( 'str:255', false ),
		'menu_order'     => array( 'int', false ),
		'comment_status' => array( 'enum:open|closed', false ),
		'ping_status'    => array( 'enum:open|closed', false ),
		'post_date_gmt'  => array( 'date', false ),
		'post_date'      => array( 'date', false ),
		'post_parent'    => array( 'uint', false ),
		'author'         => array( 'author', false ),
		'page_template'  => array( 'str:255', false ),
		'featured_image' => array( 'uint', false ),
		'format'         => array( 'key', false ),
		'terms'          => array( 'terms', false ),
		'meta'           => array( 'meta', false ),
	);

	/**
	 * Fields of attachments.
	 */
	const ATTACHMENT_FIELDS = array(
		'post_type'     => array( 'key', true ),
		'post_title'    => array( 'str', false ),
		'post_excerpt'  => array( 'str', false ),
		'post_content'  => array( 'str', false ),
		'post_name'     => array( 'slug', false ),
		'post_status'   => array( 'enum:inherit', true ),
		'post_date_gmt' => array( 'date', false ),
		'post_parent'   => array( 'uint', false ),
		'author'        => array( 'author', false ),
		'mime_type'     => array( 'mime', true ),
		'file'          => array( 'str:255', true ),
		'metadata'      => array( 'map', false ),
		'alt'           => array( 'str', false ),
		'files'         => array( 'files', false ),
		'meta'          => array( 'meta', false ),
	);

	/**
	 * Fields common to the site editor kinds; each kind adds its own below.
	 */
	const SITE_EDITOR_FIELDS = array(
		'post_type'    => array( 'key', true ),
		'post_name'    => array( 'slug', true ),
		'post_title'   => array( 'str', false ),
		'post_status'  => array( 'enum:publish|future|draft|pending|private', false ),
		'post_content' => array( 'str', true ),
	);

	const SITE_EDITOR_EXTRA = array(
		'template'      => array(
			'post_excerpt' => array( 'str', false ),
			'theme'        => array( 'stylesheet', true ),
			'origin'       => array( 'key_or_empty', false ),
		),
		'template_part' => array(
			'post_excerpt' => array( 'str', false ),
			'theme'        => array( 'stylesheet', true ),
			'origin'       => array( 'key_or_empty', false ),
			'area'         => array( 'key_or_empty', false ),
		),
		'global_styles' => array(
			'theme' => array( 'stylesheet', true ),
		),
		'pattern'       => array(
			'sync_status' => array( 'enum:|unsynced', false ),
			'terms'       => array( 'terms', false ),
		),
		'navigation'    => array(),
	);

	const TERM_FIELDS = array(
		'taxonomy'    => array( 'key', true ),
		'name'        => array( 'str:200', true ),
		'slug'        => array( 'slug', true ),
		'description' => array( 'str', false ),
		'parent'      => array( 'uint', false ),
		'meta'        => array( 'meta', false ),
	);

	const MENU_FIELDS = array(
		'name'        => array( 'str:200', true ),
		'slug'        => array( 'slug', true ),
		'description' => array( 'str', false ),
		'items'       => array( 'list', true ),
	);

	const MENU_ITEM_FIELDS = array(
		'type'        => array( 'enum:post_type|taxonomy|custom|post_type_archive', true ),
		'object'      => array( 'key_or_empty', false ),
		'object_id'   => array( 'uint', false ),
		'url'         => array( 'str:2048', false ),
		'title'       => array( 'str', false ),
		'attr_title'  => array( 'str', false ),
		'description' => array( 'str', false ),
		'classes'     => array( 'strs:200', false ),
		'target'      => array( 'enum:|_blank', false ),
		'xfn'         => array( 'str:200', false ),
		'parent'      => array( 'uint', false ),
		'position'    => array( 'int', false ),
		'status'      => array( 'enum:publish|draft', false ),
		'meta'        => array( 'meta', false ),
	);

	const OPTION_FIELDS = array(
		'name'  => array( 'option_name', true ),
		'value' => array( 'any', true ),
	);

	const WIDGETS_FIELDS = array(
		'sidebars_widgets' => array( 'map', true ),
		'widgets'          => array( 'map', true ),
	);

	const PRODUCT_FIELDS = array(
		'post_type'     => array( 'key', true ),
		'post_date_gmt' => array( 'date', false ),
		'author'        => array( 'author', false ),
		'groups'        => array( 'map', true ),
	);

	const PRODUCT_CONTENT = array(
		'type'                 => array( 'enum:simple|variable|grouped|external|variation', true ),
		'name'                 => array( 'str', true ),
		'slug'                 => array( 'slug', false ),
		'status'               => array( 'enum:publish|future|draft|pending|private', true ),
		'description'          => array( 'str', false ),
		'short_description'    => array( 'str', false ),
		'menu_order'           => array( 'int', false ),
		'image_id'             => array( 'uint', false ),
		'gallery_image_ids'    => array( 'ids', false ),
		'virtual'              => array( 'bool', false ),
		'downloadable'         => array( 'bool', false ),
		'downloads'            => array( 'downloads', false ),
		'download_limit'       => array( 'int', false ),
		'download_expiry'      => array( 'int', false ),
		'weight'               => array( 'num', false ),
		'length'               => array( 'num', false ),
		'width'                => array( 'num', false ),
		'height'               => array( 'num', false ),
		'shipping_class_id'    => array( 'uint', false ),
		'manage_stock'         => array( 'bool', false ),
		'backorders'           => array( 'enum:no|notify|yes', false ),
		'low_stock_amount'     => array( 'int_or_empty', false ),
		'purchase_note'        => array( 'str', false ),
		'post_password'        => array( 'str:255', false ),
		'meta'                 => array( 'meta', false ),
		'parent'               => array( 'uint', false ),
		'featured'             => array( 'bool', false ),
		'catalog_visibility'   => array( 'enum:visible|catalog|search|hidden', false ),
		'variation_attributes' => array( 'strmap', false ),
		'upsell_ids'           => array( 'ids', false ),
		'cross_sell_ids'       => array( 'ids', false ),
		'reviews_allowed'      => array( 'bool', false ),
		'sold_individually'    => array( 'bool', false ),
		'attributes'           => array( 'attributes', false ),
		'default_attributes'   => array( 'strmap', false ),
		'terms'                => array( 'terms', false ),
		'children'             => array( 'ids', false ),
		'product_url'          => array( 'str:2048', false ),
		'button_text'          => array( 'str:255', false ),
	);

	const PRODUCT_PRICING = array(
		'regular_price'     => array( 'num', false ),
		'sale_price'        => array( 'num', false ),
		'date_on_sale_from' => array( 'date_or_empty', false ),
		'date_on_sale_to'   => array( 'date_or_empty', false ),
		'tax_status'        => array( 'enum:taxable|shipping|none', false ),
		'tax_class'         => array( 'key_or_empty', false ),
	);

	const PRODUCT_IDENTITY = array(
		'sku'              => array( 'str:100', false ),
		'global_unique_id' => array( 'gtin', false ),
	);

	const PRODUCT_LIVE_OWNED = array(
		'stock_quantity' => array( 'any', false ),
		'stock_status'   => array( 'key_or_empty', false ),
		'total_sales'    => array( 'int', false ),
		'average_rating' => array( 'num', false ),
		'rating_counts'  => array( 'map', false ),
		'review_count'   => array( 'int', false ),
	);

	/**
	 * ID fields whose value must come with a reference (otherwise reported as unmapped).
	 */
	const STRUCTURAL = array(
		'post'          => array( 'post_parent', 'featured_image' ),
		'attachment'    => array( 'post_parent' ),
		'pattern'       => array(),
		'navigation'    => array(),
		'template'      => array(),
		'template_part' => array(),
		'global_styles' => array(),
		'term'          => array( 'parent' ),
		'product'       => array( 'groups/content/parent', 'groups/content/image_id' ),
	);

	/**
	 * Pairing row the push belongs to.
	 *
	 * @var array
	 */
	private $pairing;

	/**
	 * Rows of the push so far: uuid => array( kind, status ).
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private $known;

	/**
	 * This site's origins (URL tokens become this site's address before the gate looks).
	 *
	 * @var array
	 */
	private $origins;

	/**
	 * Warnings of the item being staged.
	 *
	 * @var array[]
	 */
	private $warnings = array();

	/**
	 * Reads the push's file rows by ref (Media_Receiver::rows()), or null when the push has none.
	 *
	 * @var callable|null
	 */
	private $files;

	/**
	 * Creates a stager for one batch of a push.
	 *
	 * @param array         $pairing Pairing row.
	 * @param array         $known   uuid => array( kind, status ) of the rows the push already has.
	 * @param callable|null $files   Returns the push's file rows for a list of refs.
	 */
	public function __construct( array $pairing, array $known, $files = null ) {
		$this->pairing = $pairing;
		$this->known   = $known;
		$this->origins = Normalizer::local_origins();
		$this->files   = is_callable( $files ) ? $files : null;
	}

	/**
	 * The envelope's identity, read by name: what a row needs before anything else is looked at.
	 * A malformed identity makes the whole batch invalid (nothing can be recorded for it).
	 *
	 * @param mixed $raw Envelope as decoded from JSON.
	 * @return array|null uuid, kind, type, plan_action, action, hash, live_id, skip.
	 */
	public static function identity( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		foreach ( array( 'uuid', 'kind', 'type', 'action', 'hash' ) as $name ) {
			if ( ! isset( $raw[ $name ] ) || ! is_string( $raw[ $name ] ) ) {
				return null;
			}
		}
		$kind   = $raw['kind'];
		$uuid   = $raw['uuid'];
		$type   = $raw['type'];
		$action = $raw['action'];
		if ( ! in_array( $kind, Manifest::KINDS, true ) || ! isset( self::PLAN_ACTIONS[ $action ] ) ) {
			return null;
		}

		if ( 'option' === $kind ) {
			if ( 1 !== preg_match( '/\Aoption:([A-Za-z0-9_.:-]{1,191})\z/', $uuid, $m ) || $m[1] !== $type ) {
				return null;
			}
		} elseif ( 'widgets' === $kind ) {
			if ( 'unit:widgets' !== $uuid || 'widgets' !== $type ) {
				return null;
			}
		} else {
			if ( ! wp_is_uuid( $uuid, 4 ) || strtolower( $uuid ) !== $uuid ) {
				return null;
			}
			// An item deleted on the source may no longer know its type (trash only).
			$pattern = 'trash_on_live' === $action ? '/\A[a-z0-9_-]{0,32}\z/' : '/\A[a-z0-9_-]{1,32}\z/';
			if ( 1 !== preg_match( $pattern, $type ) ) {
				return null;
			}
		}

		$hash = $raw['hash'];
		if ( 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $hash ) && ! ( 'trash_on_live' === $action && '' === $hash ) ) {
			return null;
		}

		$live_id = 0;
		if ( isset( $raw['live_id'] ) ) {
			if ( ! is_int( $raw['live_id'] ) || $raw['live_id'] < 0 ) {
				return null;
			}
			$live_id = $raw['live_id'];
		}

		$skip = '';
		if ( isset( $raw['skip'] ) ) {
			if ( ! is_string( $raw['skip'] ) || ! in_array( $raw['skip'], self::SKIPS, true ) ) {
				return null;
			}
			$skip = $raw['skip'];
		}

		return array(
			'uuid'        => $uuid,
			'kind'        => $kind,
			'type'        => $type,
			'plan_action' => $action,
			'action'      => self::PLAN_ACTIONS[ $action ],
			'hash'        => $hash,
			'live_id'     => $live_id,
			'skip'        => $skip,
		);
	}

	/**
	 * Validates one item and returns the row to store.
	 *
	 * @param array $id  Identity from identity().
	 * @param array $raw The envelope.
	 * @return array{status:string,reason:string,field:string,warnings:array,gate:array,target:int,data:array|null,groups:array,refs:array,media:array|null}
	 */
	public function stage( array $id, array $raw ) {
		$this->warnings = array();
		$out            = $this->result( $id );

		if ( '' !== $id['skip'] ) {
			return $this->refuse( $out, $id['skip'] );
		}

		$scope = $this->scope_reason( $id );
		if ( '' !== $scope ) {
			return $this->refuse( $out, $scope );
		}

		// The object on live the action is about.
		$target = $this->target( $id );
		if ( is_string( $target ) ) {
			return $this->refuse( $out, $target );
		}
		$out['target'] = $target;

		if ( in_array( $id['action'], array( 'trash', 'adopt' ), true ) ) {
			// No content travels: live's own object is trashed or adopted (Phase 7).
			$out['status'] = 'staged';
			$this->remember( $id );
			return $this->finish( $out );
		}

		if ( ! isset( $raw['data'] ) || ! is_array( $raw['data'] ) || ( array() !== $raw['data'] && wp_is_numeric_array( $raw['data'] ) ) ) {
			return $this->refuse( $out, 'invalid_item', 'data' );
		}

		$problem = array();
		$data    = $this->clean_kind( $id, $raw['data'], $problem );
		if ( null === $data ) {
			return $this->refuse( $out, $problem[0], $problem[1] );
		}

		$refs = $this->clean_refs( isset( $raw['refs'] ) ? $raw['refs'] : array() );
		if ( null === $refs ) {
			return $this->refuse( $out, 'invalid_refs', 'refs' );
		}

		// Serialized PHP text travels only as "ser" nodes, which live rebuilds from plain data; a
		// string that is serialized text would be stored as it is and could name any class to code
		// on live that unserializes it (security review 2026-11-22, KM-02).
		if ( Normalizer::holds_serialized_text( $data ) ) {
			return $this->refuse( $out, 'invalid_value', 'serialized_string' );
		}

		$decoded = Normalizer::decode( $data, $this->origins );
		if ( is_wp_error( $decoded ) || ! is_array( $decoded ) ) {
			$reason = is_wp_error( $decoded ) ? (array) $decoded->get_error_data() : array();
			return $this->refuse( $out, 'invalid_value', isset( $reason['reason'] ) ? sanitize_key( (string) $reason['reason'] ) : '' );
		}

		$groups = array();
		if ( 'product' === $id['kind'] ) {
			$groups = $this->clean_group_actions( isset( $raw['groups'] ) ? $raw['groups'] : array() );
			if ( null === $groups ) {
				return $this->refuse( $out, 'invalid_field', 'groups' );
			}
		}

		// The files of an attachment arrived first (blueprint §12.1) and were checked at commit:
		// the item names them, live finds them among this push's file rows.
		$media = null;
		if ( 'attachment' === $id['kind'] ) {
			$problem = array();
			$media   = $this->media( $data, isset( $raw['media'] ) ? $raw['media'] : null, $problem, (int) $out['target'] );
			if ( null === $media ) {
				return $this->refuse( $out, $problem[0], $problem[1] );
			}
		}

		// What the source manages for this item (outside the data and its hash), so live never
		// clears something the source simply does not include (blueprint §6.2, Phase 7).
		$problem = array();
		$extra   = self::envelope_extras( $id['kind'], $raw, $data, $problem );
		if ( null === $extra ) {
			return $this->refuse( $out, $problem[0], $problem[1] );
		}

		$out['refs'] = $this->resolve_refs( $id, $refs );
		$this->structural( $id['kind'], $data, $out['refs'] );

		$gate = Markup_Gate::check( $id['kind'], $decoded );

		$out['data']   = $data;
		$out['groups'] = $groups;
		$out['gate']   = array_slice( $gate, 0, self::MAX_GATE_REASONS );
		$out['media']  = $media;
		$out['extra']  = $extra;
		$out['status'] = empty( $gate ) ? 'staged' : 'held';

		$this->remember( $id, $out['status'] );
		return $this->finish( $out );
	}

	/**
	 * Final pass of a push (every declared item received): references left pending must name a
	 * row of the push that was not refused, of a kind that fits; a refused row breaks the
	 * references to it in turn. Rows are given as uuid => array( kind, status, refs ).
	 *
	 * @param array $rows uuid => array( 'kind' => string, 'status' => string, 'refs' => array ).
	 * @return array uuid => array( 'refused' => bool, 'refs' => array ) for the rows that changed.
	 */
	public static function final_pass( array $rows ) {
		$changed = array();
		for ( $round = 0; $round < 100; $round++ ) {
			$again = false;
			foreach ( $rows as $uuid => $row ) {
				if ( 'refused' === $row['status'] ) {
					continue;
				}
				$refs  = is_array( $row['refs'] ) ? $row['refs'] : array();
				$moved = false;
				foreach ( $refs as $i => $ref ) {
					if ( ! isset( $ref['res'] ) || ! in_array( $ref['res'], array( 'push', 'pending' ), true ) ) {
						continue;
					}
					$target = isset( $rows[ $ref['uuid'] ] ) ? $rows[ $ref['uuid'] ] : null;
					$fits   = null !== $target && 'refused' !== $target['status'] && isset( self::REF_KINDS[ $ref['kind'] ] ) && in_array( $target['kind'], self::REF_KINDS[ $ref['kind'] ], true );
					if ( ! $fits ) {
						$rows[ $uuid ]['status'] = 'refused';
						$changed[ $uuid ]        = array(
							'refused' => true,
							'refs'    => $refs,
						);
						$again                   = true;
						continue 2;
					}
					if ( 'pending' === $ref['res'] ) {
						$refs[ $i ]['res'] = 'push';
						$moved             = true;
					}
				}
				if ( $moved ) {
					$rows[ $uuid ]['refs'] = $refs;
					$changed[ $uuid ]      = array(
						'refused' => false,
						'refs'    => $refs,
					);
				}
			}
			if ( ! $again ) {
				break;
			}
		}
		return $changed;
	}

	/**
	 * The empty result for an item.
	 *
	 * @param array $id Identity.
	 * @return array
	 */
	private function result( array $id ) {
		return array(
			'status'   => 'refused',
			'reason'   => '',
			'field'    => '',
			'warnings' => array(),
			'gate'     => array(),
			'target'   => 0,
			'data'     => null,
			'groups'   => array(),
			'refs'     => array(),
			'media'    => null,
			'extra'    => array(),
			'identity' => $id,
		);
	}

	/**
	 * A refusal (the row keeps the identity only, never the content).
	 *
	 * @param array  $out    Result.
	 * @param string $reason Reason code.
	 * @param string $field  Schema field name, when one is to blame.
	 * @return array
	 */
	private function refuse( array $out, $reason, $field = '' ) {
		$out['status'] = 'refused';
		$out['reason'] = (string) $reason;
		$out['field']  = substr( (string) $field, 0, 64 );
		$out['data']   = null;
		$out['refs']   = array();
		$out['gate']   = array();
		$out['media']  = null;
		$out['extra']  = array();
		$this->remember( $out['identity'], 'refused' );
		return $this->finish( $out );
	}

	/**
	 * Adds the collected warnings.
	 *
	 * @param array $out Result.
	 * @return array
	 */
	private function finish( array $out ) {
		$out['warnings'] = array_slice( $this->warnings, 0, 20 );
		return $out;
	}

	/**
	 * Records the item for references from later items of the same batch.
	 *
	 * @param array  $id     Identity.
	 * @param string $status Status.
	 * @return void
	 */
	private function remember( array $id, $status = 'staged' ) {
		$this->known[ $id['uuid'] ] = array( $id['kind'], $status );
	}

	/**
	 * A warning (code plus a short detail that never echoes received text).
	 *
	 * @param string $code   Code.
	 * @param string $detail Detail: a schema field name or a number.
	 * @return void
	 */
	private function warn( $code, $detail = '' ) {
		$this->warnings[] = array(
			'code'   => $code,
			'detail' => (string) $detail,
		);
	}

	// --- live's scope ------------------------------------------------------------------------

	/**
	 * Why live does not take an item of this kind and type, or ''.
	 *
	 * @param array $id Identity.
	 * @return string
	 */
	private function scope_reason( array $id ) {
		$kind = $id['kind'];
		$type = $id['type'];

		switch ( $kind ) {
			case 'option':
				if ( '' !== Option_Policy::option_denial_reason( $type ) ) {
					return 'option_denied';
				}
				return Scope::option_allowed( $type ) ? '' : 'option_not_allowed';

			case 'widgets':
				return Scope::widgets_included() ? '' : 'widgets_not_included';

			case 'menu':
				return Scope::menus_included() && 'nav_menu' === $type ? '' : 'taxonomy_not_included';

			case 'term':
				return in_array( $type, Scope::taxonomies(), true ) ? '' : 'taxonomy_not_included';

			default:
				if ( '' === $type ) {
					return '';
				}
				if ( Scope::never_post_type( $type ) ) {
					return 'type_denied';
				}
				return Scope::kind_for_post_type( $type ) === $kind ? '' : 'type_not_included';
		}
	}

	/**
	 * The live object the action is about: its ID (0 for creations and options), or the refusal.
	 *
	 * @param array $id Identity.
	 * @return int|string
	 */
	private function target( array $id ) {
		if ( 'trash' === $id['action'] && 'post' !== $id['kind'] ) {
			return 'product' === $id['kind'] ? 'product_never_trashed' : 'action_not_allowed';
		}

		$resolved = Manifest::resolve(
			$this->pairing,
			array(
				'kind'    => $id['kind'],
				'uuid'    => $id['uuid'],
				'type'    => $id['type'],
				'live_id' => in_array( $id['action'], array( 'create', 'recreate' ), true ) ? 0 : $id['live_id'],
			)
		);

		if ( 'excluded' === $resolved['state'] ) {
			return 'type_not_included';
		}
		if ( in_array( $id['action'], array( 'create', 'recreate' ), true ) ) {
			// The widgets unit always exists when it is included; creating it means writing it.
			if ( 'found' === $resolved['state'] && 'widgets' !== $id['kind'] ) {
				return 'target_exists';
			}
			return 0;
		}
		if ( 'found' !== $resolved['state'] ) {
			return 'target_missing';
		}
		return is_int( $resolved['ref'] ) ? $resolved['ref'] : 0;
	}

	// --- schema ------------------------------------------------------------------------------

	/**
	 * The item's data cleaned by its kind's schema, or null with the problem.
	 *
	 * @param array $id      Identity.
	 * @param array $data    Received data.
	 * @param array $problem Set to array( reason, field ) on failure.
	 * @return array|null
	 */
	private function clean_kind( array $id, array $data, array &$problem ) {
		$kind = $id['kind'];

		switch ( $kind ) {
			case 'post':
				$clean = $this->fields( $data, self::POST_FIELDS, $problem );
				break;
			case 'attachment':
				$clean = $this->fields( $data, self::ATTACHMENT_FIELDS, $problem );
				break;
			case 'pattern':
			case 'navigation':
			case 'template':
			case 'template_part':
			case 'global_styles':
				$clean = $this->fields( $data, array_merge( self::SITE_EDITOR_FIELDS, self::SITE_EDITOR_EXTRA[ $kind ] ), $problem );
				break;
			case 'product':
				$clean = $this->product( $data, $problem );
				break;
			case 'term':
				$clean = $this->fields( $data, self::TERM_FIELDS, $problem );
				break;
			case 'menu':
				$clean = $this->menu( $data, $problem );
				break;
			case 'option':
				$clean = $this->fields( $data, self::OPTION_FIELDS, $problem );
				break;
			case 'widgets':
				$clean = $this->widgets( $data, $problem );
				break;
			default:
				$problem = array( 'invalid_item', 'kind' );
				return null;
		}
		if ( null === $clean ) {
			return null;
		}

		return $this->kind_checks( $id, $clean, $problem );
	}

	/**
	 * Checks that need live's own state, per kind.
	 *
	 * @param array $id      Identity.
	 * @param array $clean   Cleaned data.
	 * @param array $problem Problem (out).
	 * @return array|null
	 */
	private function kind_checks( array $id, array $clean, array &$problem ) {
		$kind = $id['kind'];

		// The type inside the data must be the type the envelope was checked for.
		$own = array(
			'term'   => 'taxonomy',
			'option' => 'name',
		);
		if ( isset( $own[ $kind ] ) ) {
			if ( $clean[ $own[ $kind ] ] !== $id['type'] ) {
				$problem = array( 'invalid_field', $own[ $kind ] );
				return null;
			}
		} elseif ( ! in_array( $kind, array( 'menu', 'widgets' ), true ) && $clean['post_type'] !== $id['type'] ) {
			$problem = array( 'invalid_field', 'post_type' );
			return null;
		}

		if ( isset( $clean['meta'] ) ) {
			$meta = $this->meta( $kind, $clean['meta'], $problem );
			if ( null === $meta ) {
				return null;
			}
			$clean['meta'] = $meta;
		}
		if ( isset( $clean['terms'] ) ) {
			$clean['terms'] = $this->terms( $clean['terms'], $id['type'] );
		}

		switch ( $kind ) {
			case 'post':
				if ( isset( $clean['format'] ) && ! array_key_exists( $clean['format'], get_post_format_strings() ) ) {
					$problem = array( 'invalid_field', 'format' );
					return null;
				}
				if ( isset( $clean['page_template'] ) && ! self::template_exists( $clean['page_template'], $id['type'] ) ) {
					unset( $clean['page_template'] );
					$this->warn( 'page_template_dropped', 'page_template' );
				}
				break;

			case 'attachment':
				$reason = self::attachment_paths( $clean );
				if ( '' !== $reason ) {
					$problem = array( 'path_refused', $reason );
					return null;
				}
				if ( ! Path_Guard::mime_allowed( $clean['mime_type'] ) ) {
					$problem = array( 'mime_refused', 'mime_type' );
					return null;
				}
				break;

			case 'template':
			case 'template_part':
			case 'global_styles':
				if ( ! wp_get_theme( $clean['theme'] )->exists() ) {
					$problem = array( 'theme_missing', 'theme' );
					return null;
				}
				if ( 'template_part' === $kind && isset( $clean['area'] ) && '' !== $clean['area'] && ! in_array( $clean['area'], self::template_part_areas(), true ) ) {
					$problem = array( 'invalid_field', 'area' );
					return null;
				}
				if ( 'global_styles' === $kind ) {
					$json = json_decode( $clean['post_content'], true, 64 );
					$name = 'wp-global-styles-' . rawurlencode( $clean['theme'] );
					if ( ! is_array( $json ) || $name !== $clean['post_name'] ) {
						$problem = array( 'invalid_field', 'post_content' );
						return null;
					}
				}
				break;

			case 'option':
				// The option name was checked against live's own allowlist with the identity.
				break;
		}

		return $clean;
	}

	/**
	 * Reads the fields of a schema by name; unknown keys are dropped (and counted).
	 *
	 * @param array $data    Received fields.
	 * @param array $schema  name => array( rule, required ).
	 * @param array $problem Problem (out).
	 * @param bool  $count   Whether dropped keys are reported.
	 * @return array|null
	 */
	private function fields( array $data, array $schema, array &$problem, $count = true ) {
		$out = array();
		foreach ( $schema as $name => $spec ) {
			if ( ! array_key_exists( $name, $data ) ) {
				if ( $spec[1] ) {
					$problem = array( 'missing_field', $name );
					return null;
				}
				continue;
			}
			if ( ! self::rule_ok( $data[ $name ], $spec[0] ) ) {
				$problem = array( 'invalid_field', $name );
				return null;
			}
			$out[ $name ] = $data[ $name ];
		}
		$unknown = count( array_diff_key( $data, $schema ) );
		if ( $count && $unknown > 0 ) {
			$this->warn( 'unknown_fields', (string) $unknown );
		}
		return $out;
	}

	/**
	 * Whether a typed value satisfies a rule.
	 *
	 * @param mixed  $value Value (typed JSON form).
	 * @param string $rule  Rule, with an argument after ":".
	 * @return bool
	 */
	public static function rule_ok( $value, $rule ) {
		$parts = explode( ':', $rule, 2 );
		$type  = $parts[0];
		$arg   = isset( $parts[1] ) ? $parts[1] : '';

		switch ( $type ) {
			case 'str':
				return is_string( $value ) && ( '' === $arg || strlen( $value ) <= (int) $arg );
			case 'int':
				return is_int( $value );
			case 'uint':
				return is_int( $value ) && $value >= 0;
			case 'bool':
				return is_bool( $value );
			case 'any':
				return true;
			case 'enum':
				return is_string( $value ) && in_array( $value, explode( '|', $arg ), true );
			case 'date':
				return is_string( $value ) && 1 === preg_match( '/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value );
			case 'date_or_empty':
				return '' === $value || self::rule_ok( $value, 'date' );
			case 'slug':
				return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9%_.~-]{0,200}\z/', $value );
			case 'key':
				return is_string( $value ) && 1 === preg_match( '/\A[a-z0-9_-]{1,64}\z/', $value );
			case 'key_or_empty':
				return '' === $value || self::rule_ok( $value, 'key' );
			case 'stylesheet':
				return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9._-]{1,100}\z/', $value ) && false === strpos( $value, '..' );
			case 'option_name':
				return is_string( $value ) && 1 === preg_match( '/\A[A-Za-z0-9_.:-]{1,191}\z/', $value );
			case 'mime':
				return is_string( $value ) && 1 === preg_match( '#\A[a-z0-9.+-]{1,50}/[a-z0-9.+-]{1,100}\z#i', $value );
			case 'num':
				return is_string( $value ) && strlen( $value ) <= 32 && ( '' === $value || is_numeric( $value ) );
			case 'int_or_empty':
				return null === $value || '' === $value || is_int( $value );
			case 'gtin':
				return is_string( $value ) && 1 === preg_match( '/\A[0-9-]{0,20}\z/', $value );
			case 'map':
				return is_array( $value );
			case 'list':
				return is_array( $value ) && ( array() === $value || wp_is_numeric_array( $value ) );
			case 'ids':
				if ( ! self::rule_ok( $value, 'list' ) ) {
					return false;
				}
				foreach ( $value as $item ) {
					if ( ! is_int( $item ) || $item < 0 ) {
						return false;
					}
				}
				return true;
			case 'strs':
				if ( ! self::rule_ok( $value, 'list' ) ) {
					return false;
				}
				foreach ( $value as $item ) {
					if ( ! is_string( $item ) || strlen( $item ) > (int) $arg ) {
						return false;
					}
				}
				return true;
			case 'strmap':
				if ( ! is_array( $value ) ) {
					return false;
				}
				foreach ( $value as $key => $item ) {
					if ( ! is_string( $item ) || strlen( (string) $key ) > 200 ) {
						return false;
					}
				}
				return true;
			case 'author':
				return self::author_ok( $value );
			case 'terms':
				if ( ! is_array( $value ) ) {
					return false;
				}
				foreach ( $value as $taxonomy => $ids ) {
					if ( ! is_string( $taxonomy ) || 1 !== preg_match( '/\A[a-z0-9_-]{1,32}\z/', $taxonomy ) || ! self::rule_ok( $ids, 'ids' ) ) {
						return false;
					}
				}
				return true;
			case 'meta':
				if ( ! is_array( $value ) ) {
					return false;
				}
				foreach ( $value as $key => $values ) {
					if ( ! is_string( $key ) || '' === $key || strlen( $key ) > 255 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $key ) || ! self::rule_ok( $values, 'list' ) ) {
						return false;
					}
				}
				return true;
			case 'files':
				if ( ! self::rule_ok( $value, 'list' ) || count( $value ) > self::MAX_FILES ) {
					return false;
				}
				foreach ( $value as $file ) {
					if ( ! is_array( $file ) || ! isset( $file['path'], $file['size'], $file['sha256'] ) || ! is_string( $file['path'] ) || ! is_int( $file['size'] ) || $file['size'] < -1 || ! is_string( $file['sha256'] ) || 1 !== preg_match( '/\A(?:[0-9a-f]{64})?\z/', $file['sha256'] ) ) {
						return false;
					}
				}
				return true;
			case 'downloads':
				if ( ! self::rule_ok( $value, 'list' ) ) {
					return false;
				}
				foreach ( $value as $download ) {
					if ( ! is_array( $download ) || ! isset( $download['id'], $download['name'], $download['file'] ) || ! is_string( $download['id'] ) || strlen( $download['id'] ) > 100 || ! is_string( $download['name'] ) || ! is_string( $download['file'] ) || strlen( $download['file'] ) > 2048 || ( isset( $download['enabled'] ) && ! is_bool( $download['enabled'] ) ) ) {
						return false;
					}
				}
				return true;
			case 'attributes':
				if ( ! self::rule_ok( $value, 'list' ) ) {
					return false;
				}
				foreach ( $value as $attribute ) {
					if ( ! is_array( $attribute ) || ! isset( $attribute['name'], $attribute['taxonomy'], $attribute['options'] ) || ! is_string( $attribute['name'] ) || strlen( $attribute['name'] ) > 200 || ! is_bool( $attribute['taxonomy'] ) || ! self::rule_ok( $attribute['options'], $attribute['taxonomy'] ? 'ids' : 'strs:1000' ) ) {
						return false;
					}
					foreach ( array( 'visible', 'variation' ) as $flag ) {
						if ( isset( $attribute[ $flag ] ) && ! is_bool( $attribute[ $flag ] ) ) {
							return false;
						}
					}
					if ( isset( $attribute['position'] ) && ! is_int( $attribute['position'] ) ) {
						return false;
					}
				}
				return true;
		}
		return false;
	}

	/**
	 * An author entry: login (as core sanitizes logins) and email ('' or an address).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function author_ok( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['login'] ) || ! is_string( $value['login'] ) || strlen( $value['login'] ) > 60 || sanitize_user( $value['login'], true ) !== $value['login'] ) {
			return false;
		}
		if ( isset( $value['email'] ) && ( ! is_string( $value['email'] ) || strlen( $value['email'] ) > 100 || ( '' !== $value['email'] && ! is_email( $value['email'] ) ) ) ) {
			return false;
		}
		return count( array_diff_key( $value, array_flip( array( 'login', 'email' ) ) ) ) === 0;
	}

	/**
	 * Product fields by group.
	 *
	 * @param array $data    Data.
	 * @param array $problem Problem (out).
	 * @return array|null
	 */
	private function product( array $data, array &$problem ) {
		$clean = $this->fields( $data, self::PRODUCT_FIELDS, $problem );
		if ( null === $clean ) {
			return null;
		}
		$schemas = array(
			'content'    => self::PRODUCT_CONTENT,
			'pricing'    => self::PRODUCT_PRICING,
			'identity'   => self::PRODUCT_IDENTITY,
			'live_owned' => self::PRODUCT_LIVE_OWNED,
		);
		if ( ! isset( $clean['groups']['content'] ) ) {
			$problem = array( 'missing_field', 'groups.content' );
			return null;
		}
		$groups = array();
		foreach ( $schemas as $name => $schema ) {
			if ( ! isset( $clean['groups'][ $name ] ) ) {
				continue;
			}
			if ( ! is_array( $clean['groups'][ $name ] ) ) {
				$problem = array( 'invalid_field', 'groups.' . $name );
				return null;
			}
			$group = $this->fields( $clean['groups'][ $name ], $schema, $problem );
			if ( null === $group ) {
				$problem[1] = 'groups.' . $name . '.' . $problem[1];
				return null;
			}
			$groups[ $name ] = $group;
		}
		if ( count( array_diff_key( $clean['groups'], $schemas ) ) > 0 ) {
			$this->warn( 'unknown_fields', (string) count( array_diff_key( $clean['groups'], $schemas ) ) );
		}
		$clean['groups'] = $groups;

		if ( isset( $groups['content']['meta'] ) ) {
			$meta = $this->meta( 'product', $groups['content']['meta'], $problem );
			if ( null === $meta ) {
				return null;
			}
			$clean['groups']['content']['meta'] = $meta;
		}
		if ( isset( $groups['content']['terms'] ) ) {
			$clean['groups']['content']['terms'] = $this->terms( $groups['content']['terms'], 'product' );
		}
		return $clean;
	}

	/**
	 * Menu fields and its items.
	 *
	 * @param array $data    Data.
	 * @param array $problem Problem (out).
	 * @return array|null
	 */
	private function menu( array $data, array &$problem ) {
		$clean = $this->fields( $data, self::MENU_FIELDS, $problem );
		if ( null === $clean ) {
			return null;
		}
		if ( count( $clean['items'] ) > self::MAX_MENU_ITEMS ) {
			$problem = array( 'invalid_field', 'items' );
			return null;
		}
		$items = array();
		foreach ( $clean['items'] as $i => $item ) {
			if ( ! is_array( $item ) ) {
				$problem = array( 'invalid_field', 'items' );
				return null;
			}
			$one = $this->fields( $item, self::MENU_ITEM_FIELDS, $problem );
			if ( null === $one ) {
				$problem[1] = 'items.' . $problem[1];
				return null;
			}
			if ( isset( $one['meta'] ) ) {
				$meta = $this->meta( 'menu_item', $one['meta'], $problem );
				if ( null === $meta ) {
					return null;
				}
				$one['meta'] = $meta;
			}
			$items[ $i ] = $one;
		}
		$clean['items'] = $items;
		return $clean;
	}

	/**
	 * The widgets unit: every widget option must be one live's policy allows.
	 *
	 * @param array $data    Data.
	 * @param array $problem Problem (out).
	 * @return array|null
	 */
	private function widgets( array $data, array &$problem ) {
		$clean = $this->fields( $data, self::WIDGETS_FIELDS, $problem );
		if ( null === $clean ) {
			return null;
		}
		$dropped = 0;
		foreach ( array_keys( $clean['widgets'] ) as $name ) {
			if ( ! is_string( $name ) || 1 !== preg_match( '/\Awidget_[a-z0-9_-]{1,100}\z/', $name ) || '' !== Option_Policy::option_denial_reason( $name ) ) {
				$problem = array( 'option_denied', 'widgets' );
				return null;
			}
			if ( ! Scope::widget_registered( $name ) ) {
				unset( $clean['widgets'][ $name ] );
				++$dropped;
			}
		}
		if ( $dropped > 0 ) {
			$this->warn( 'widget_not_registered', (string) $dropped );
		}
		return $clean;
	}

	/**
	 * Meta of an item: keys excluded on live are dropped; keys carried as fields elsewhere are
	 * dropped or, for attachment paths, refuse the item.
	 *
	 * @param string $kind    Kind ('menu_item' for the items of a menu).
	 * @param array  $meta    key => list of values.
	 * @param array  $problem Problem (out).
	 * @return array|null
	 */
	private function meta( $kind, array $meta, array &$problem ) {
		$out     = array();
		$dropped = 0;
		foreach ( $meta as $key => $values ) {
			$key = (string) $key;
			if ( '_wp_attached_file' === $key || '_wp_attachment_metadata' === $key ) {
				// Attachment paths travel as checked fields only, never as meta.
				$problem = array( 'reserved_meta', 'meta' );
				return null;
			}
			if ( '_wp_page_template' === $key ) {
				$this->warn( 'page_template_dropped', 'meta' );
				continue;
			}
			if ( '_wp_attachment_image_alt' === $key || Scope::meta_excluded( $key ) ) {
				++$dropped;
				continue;
			}
			if ( ( 'product' === $kind && Guard::is_internal_product_meta( $key ) ) || ( 'term' === $kind && Guard::is_volatile_term_meta( $key ) ) || ( 'menu_item' === $kind && in_array( $key, Menus::ITEM_FIELDS, true ) ) ) {
				++$dropped;
				continue;
			}
			$out[ $key ] = $values;
		}
		if ( $dropped > 0 ) {
			$this->warn( 'meta_excluded', (string) $dropped );
		}
		return $out;
	}

	/**
	 * Term assignments: taxonomies live does not include (or that the type does not use here) are
	 * dropped with a warning.
	 *
	 * @param array  $terms Taxonomy => IDs.
	 * @param string $type  Post type.
	 * @return array
	 */
	private function terms( array $terms, $type ) {
		$out     = array();
		$dropped = 0;
		$allowed = Scope::taxonomies();
		foreach ( $terms as $taxonomy => $ids ) {
			if ( in_array( $taxonomy, $allowed, true ) && is_object_in_taxonomy( $type, $taxonomy ) ) {
				$out[ $taxonomy ] = $ids;
			} else {
				++$dropped;
			}
		}
		if ( $dropped > 0 ) {
			$this->warn( 'taxonomy_not_included', (string) $dropped );
		}
		return $out;
	}

	/**
	 * Group actions of a product (which groups the source decided to push).
	 *
	 * @param mixed $groups Received map.
	 * @return array|null
	 */
	private function clean_group_actions( $groups ) {
		if ( ! is_array( $groups ) ) {
			return null;
		}
		$out = array();
		foreach ( self::GROUP_NAMES as $name ) {
			if ( ! isset( $groups[ $name ] ) ) {
				continue;
			}
			if ( ! is_string( $groups[ $name ] ) || ! in_array( $groups[ $name ], self::GROUP_ACTIONS, true ) ) {
				return null;
			}
			$out[ $name ] = $groups[ $name ];
		}
		return $out;
	}

	/**
	 * Whether a page template is one live's theme has: '' and "default", a classic template file
	 * of the active theme for the type, or a block template slug that live knows.
	 *
	 * @param string $template  Value.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function template_exists( $template, $post_type ) {
		$template = (string) $template;
		if ( '' === $template || 'default' === $template ) {
			return true;
		}
		if ( 1 === preg_match( '/[\x00-\x1f\x7f\\\\]|\.\./', $template ) ) {
			return false;
		}
		$classic = wp_get_theme()->get_page_templates( null, (string) $post_type );
		if ( is_array( $classic ) && isset( $classic[ $template ] ) ) {
			return true;
		}
		if ( 1 !== preg_match( '/\A[a-z0-9_-]{1,100}\z/', $template ) || ! function_exists( 'get_block_templates' ) ) {
			return false;
		}
		$found = get_block_templates( array( 'slug__in' => array( $template ) ), 'wp_template' );
		return ! empty( $found );
	}

	/**
	 * Template part areas registered here.
	 *
	 * @return string[]
	 */
	private static function template_part_areas() {
		$out = array();
		foreach ( (array) get_allowed_block_template_part_areas() as $area ) {
			if ( is_array( $area ) && isset( $area['area'] ) ) {
				$out[] = (string) $area['area'];
			}
		}
		return $out;
	}

	/**
	 * Every path of an attachment through Path_Guard: the file, the metadata's file, sizes and
	 * original image, the file list.
	 *
	 * @param array $clean Attachment data.
	 * @return string '' or the field that was refused.
	 */
	private static function attachment_paths( array $clean ) {
		$file = $clean['file'];
		if ( '' !== Path_Guard::reason( $file ) ) {
			return 'file';
		}
		if ( isset( $clean['metadata'] ) ) {
			$field = Path_Guard::metadata_reason( $file, $clean['metadata'] );
			if ( '' !== $field ) {
				return $field;
			}
		}
		foreach ( isset( $clean['files'] ) ? $clean['files'] : array() as $entry ) {
			if ( '' !== Path_Guard::reason( $entry['path'] ) ) {
				return 'files';
			}
		}
		return '';
	}

	/**
	 * Checks an attachment against the files of this push (blueprint §9, Phase 6): its file list
	 * may name only the files its own file and metadata imply; the main file must be there; every
	 * listed file needs a file row of this push, named in the envelope's "media" map (path → ref),
	 * with the same path, size and SHA-256, that live already had or that was committed.
	 *
	 * A file live already had (status have) must not belong to another attachment on live: the new
	 * owner would share it, and deleting either attachment deletes the other's file.
	 *
	 * @param array $data    Cleaned attachment data.
	 * @param mixed $map     The envelope's media map as received.
	 * @param array $problem Problem (out).
	 * @param int   $target  The live attachment the item updates, 0 for a new one.
	 * @return array|null The payload's media record, or null with the problem.
	 */
	private function media( array $data, $map, array &$problem, $target = 0 ) {
		$listed = array();
		foreach ( isset( $data['files'] ) ? $data['files'] : array() as $entry ) {
			$listed[ $entry['path'] ] = $entry;
		}
		$expected = Path_Guard::attachment_paths( $data['file'], isset( $data['metadata'] ) ? $data['metadata'] : array() );
		foreach ( array_keys( $listed ) as $path ) {
			if ( ! in_array( $path, $expected, true ) ) {
				$problem = array( 'invalid_field', 'files' );
				return null;
			}
		}
		if ( ! isset( $listed[ $data['file'] ] ) || $listed[ $data['file'] ]['size'] < 0 ) {
			$problem = array( 'file_missing', 'file' );
			return null;
		}

		// The map, read by name: a list of {path, ref}.
		$refs = array();
		if ( null !== $map ) {
			if ( ! is_array( $map ) || ( array() !== $map && ! wp_is_numeric_array( $map ) ) || count( $map ) > self::MAX_FILES ) {
				$problem = array( 'invalid_field', 'media' );
				return null;
			}
			foreach ( $map as $pair ) {
				if ( ! is_array( $pair ) || ! isset( $pair['path'], $pair['ref'] ) || ! is_string( $pair['path'] ) || strlen( $pair['path'] ) > Path_Guard::MAX_BYTES || ! is_int( $pair['ref'] ) || $pair['ref'] < 1 ) {
					$problem = array( 'invalid_field', 'media' );
					return null;
				}
				$refs[ $pair['path'] ] = $pair['ref'];
			}
		}

		$rows    = null === $this->files || empty( $refs ) ? array() : (array) call_user_func( $this->files, array_values( $refs ) );
		$files   = array();
		$missing = 0;
		foreach ( $listed as $path => $entry ) {
			if ( $entry['size'] < 0 ) {
				// A size the source no longer has on disk: live gets the attachment without it.
				++$missing;
				continue;
			}
			$row = isset( $refs[ $path ], $rows[ $refs[ $path ] ] ) ? $rows[ $refs[ $path ] ] : null;
			if ( null === $row || $row['t']['path'] !== $path || (int) $row['t']['size'] !== $entry['size'] || ! hash_equals( (string) $row['t']['sha256'], (string) $entry['sha256'] ) ) {
				$problem = array( 'media_missing', 'files' );
				return null;
			}
			if ( 'refused' === $row['status'] ) {
				$problem = array( 'file_refused', 'files' );
				return null;
			}
			if ( ! in_array( $row['status'], array( 'have', 'committed' ), true ) ) {
				$problem = array( 'media_missing', 'files' );
				return null;
			}
			if ( 'have' === $row['status'] && self::file_used_by_other( (string) $row['t']['final'], (int) $target ) ) {
				$problem = array( 'file_shared', 'files' );
				return null;
			}
			$files[] = array(
				'path'   => $path,
				'final'  => (string) $row['t']['final'],
				'ref'    => (int) $row['ref'],
				'status' => (string) $row['status'],
			);
		}
		if ( $missing > 0 ) {
			$this->warn( 'size_file_missing', (string) $missing );
		}

		return array(
			'state' => 'ready',
			'files' => $files,
		);
	}

	/**
	 * Whether a file in the uploads tree belongs to an attachment on live other than $target: as its
	 * attached file, or as a size or original image in the same folder (core keeps those as plain
	 * names in the metadata, so the same name in another folder is another file). Fails closed
	 * when there are too many candidates to check.
	 *
	 * @param string $path   Relative path.
	 * @param int    $target Live attachment allowed to own it (0 for none).
	 * @return bool
	 */
	private static function file_used_by_other( $path, $target ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One exact lookup of core's attached-file key while staging an attachment.
		$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value = %s AND post_id <> %d LIMIT 1', $wpdb->postmeta, '_wp_attached_file', $path, $target ) );
		if ( null !== $owner ) {
			return true;
		}

		$dir   = dirname( $path );
		$dir   = '.' === $dir ? '' : $dir;
		$limit = 200;
		// The same LIKE core runs before it deletes a thumb (wp_delete_attachment_files()).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded lookup of a file name in attachment metadata while staging an attachment.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s AND meta_value LIKE %s AND post_id <> %d LIMIT %d', $wpdb->postmeta, '_wp_attachment_metadata', '%' . $wpdb->esc_like( '"' . wp_basename( $path ) . '"' ) . '%', $target, $limit ) );
		if ( count( $ids ) >= $limit ) {
			return true;
		}
		foreach ( $ids as $id ) {
			$file  = (string) get_post_meta( (int) $id, '_wp_attached_file', true );
			$other = dirname( $file );
			if ( ( '.' === $other ? '' : $other ) === $dir ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The envelope's extra fields, each read by name: scope (taxonomies the source includes for
	 * the item, its own excluded meta entries), item_ids (a menu's item IDs on the source, in item
	 * order, so parents resolve inside the unit), group_hashes (a product's group hashes on the
	 * source, the base of a group live keeps) and live_hash (live's hash of the item when the plan
	 * was made, so the apply skips an item live changed since: blueprint §5 data safety). Absent
	 * fields stay absent.
	 *
	 * @param string $kind    Kind.
	 * @param array  $raw     Envelope.
	 * @param array  $data    Cleaned data.
	 * @param array  $problem Problem (out).
	 * @return array|null
	 */
	private static function envelope_extras( $kind, array $raw, array $data, array &$problem ) {
		$out = array();

		if ( isset( $raw['scope'] ) ) {
			$scope = $raw['scope'];
			if ( ! is_array( $scope ) ) {
				$problem = array( 'invalid_field', 'scope' );
				return null;
			}
			$clean = array();
			if ( isset( $scope['taxonomies'] ) ) {
				if ( ! self::rule_ok( $scope['taxonomies'], 'list' ) || count( $scope['taxonomies'] ) > 50 ) {
					$problem = array( 'invalid_field', 'scope' );
					return null;
				}
				foreach ( $scope['taxonomies'] as $taxonomy ) {
					if ( ! is_string( $taxonomy ) || 1 !== preg_match( '/\A[a-z0-9_-]{1,32}\z/', $taxonomy ) ) {
						$problem = array( 'invalid_field', 'scope' );
						return null;
					}
				}
				$clean['taxonomies'] = array_values( array_unique( $scope['taxonomies'] ) );
			}
			if ( isset( $scope['meta_skip'] ) ) {
				if ( ! self::rule_ok( $scope['meta_skip'], 'list' ) || count( $scope['meta_skip'] ) > 200 ) {
					$problem = array( 'invalid_field', 'scope' );
					return null;
				}
				foreach ( $scope['meta_skip'] as $entry ) {
					if ( ! is_string( $entry ) || 1 !== preg_match( '/\A[A-Za-z0-9_.:-]{1,191}\*?\z/', $entry ) ) {
						$problem = array( 'invalid_field', 'scope' );
						return null;
					}
				}
				$clean['meta_skip'] = array_values( array_unique( $scope['meta_skip'] ) );
			}
			$out['scope'] = $clean;
		}

		if ( isset( $raw['live_hash'] ) ) {
			if ( ! is_string( $raw['live_hash'] ) || 1 !== preg_match( '/\A(?:[0-9a-f]{64})?\z/', $raw['live_hash'] ) ) {
				$problem = array( 'invalid_field', 'live_hash' );
				return null;
			}
			if ( '' !== $raw['live_hash'] ) {
				$out['live_hash'] = $raw['live_hash'];
			}
		}

		if ( 'menu' === $kind && isset( $raw['item_ids'] ) ) {
			$ids   = $raw['item_ids'];
			$items = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
			if ( ! self::rule_ok( $ids, 'ids' ) || count( $ids ) !== count( $items ) || in_array( 0, $ids, true ) || count( array_unique( $ids ) ) !== count( $ids ) ) {
				$problem = array( 'invalid_field', 'item_ids' );
				return null;
			}
			$out['item_ids'] = array_values( $ids );
		}

		if ( 'product' === $kind && isset( $raw['group_hashes'] ) ) {
			$hashes = $raw['group_hashes'];
			if ( ! is_array( $hashes ) ) {
				$problem = array( 'invalid_field', 'group_hashes' );
				return null;
			}
			$clean = array();
			foreach ( self::GROUP_NAMES as $name ) {
				if ( ! isset( $hashes[ $name ] ) ) {
					continue;
				}
				if ( ! is_string( $hashes[ $name ] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $hashes[ $name ] ) ) {
					$problem = array( 'invalid_field', 'group_hashes' );
					return null;
				}
				$clean[ $name ] = $hashes[ $name ];
			}
			$out['group_hashes'] = $clean;
		}

		return $out;
	}

	// --- references --------------------------------------------------------------------------

	/**
	 * The reference sidecar, each entry read by name, or null when malformed.
	 *
	 * @param mixed $refs Received list.
	 * @return array|null
	 */
	private function clean_refs( $refs ) {
		if ( ! is_array( $refs ) || ( array() !== $refs && ! wp_is_numeric_array( $refs ) ) || count( $refs ) > self::MAX_REFS ) {
			return null;
		}
		$out = array();
		foreach ( $refs as $ref ) {
			if ( ! is_array( $ref ) || ! isset( $ref['path'], $ref['kind'], $ref['id'], $ref['uuid'] ) ) {
				return null;
			}
			if ( ! is_string( $ref['path'] ) || '' === $ref['path'] || strlen( $ref['path'] ) > 1024 || 1 === preg_match( '/[\x00-\x1f\x7f]/', $ref['path'] ) ) {
				return null;
			}
			if ( ! is_string( $ref['kind'] ) || ! isset( self::REF_KINDS[ $ref['kind'] ] ) || ! is_int( $ref['id'] ) || $ref['id'] < 1 || ! is_string( $ref['uuid'] ) || ! wp_is_uuid( $ref['uuid'], 4 ) ) {
				return null;
			}
			$hint = 0;
			if ( isset( $ref['live_id'] ) ) {
				if ( ! is_int( $ref['live_id'] ) || $ref['live_id'] < 0 ) {
					return null;
				}
				$hint = $ref['live_id'];
			}
			$out[] = array(
				'path'    => $ref['path'],
				'kind'    => $ref['kind'],
				'id'      => $ref['id'],
				'uuid'    => strtolower( $ref['uuid'] ),
				'live_id' => $hint,
			);
		}
		return $out;
	}

	/**
	 * Resolves each reference: the item itself, a row of this push, live's own object, the menu's
	 * own items, or pending until the push is complete.
	 *
	 * @param array $id   Identity.
	 * @param array $refs Cleaned references.
	 * @return array References with "res" (self, push, live, unit, pending) and the live ID found.
	 */
	private function resolve_refs( array $id, array $refs ) {
		$cache = array();
		foreach ( $refs as $i => $ref ) {
			$kind = $ref['kind'];
			if ( $ref['uuid'] === $id['uuid'] && in_array( $id['kind'], self::REF_KINDS[ $kind ], true ) ) {
				$refs[ $i ]['res'] = 'self';
				continue;
			}
			if ( 'menu_item' === $kind && 'menu' === $id['kind'] && 0 === strpos( $ref['path'], 'items/' ) ) {
				// A menu item's parent travels inside the same menu unit.
				$refs[ $i ]['res'] = 'unit';
				continue;
			}
			if ( isset( $this->known[ $ref['uuid'] ] ) && 'refused' !== $this->known[ $ref['uuid'] ][1] && in_array( $this->known[ $ref['uuid'] ][0], self::REF_KINDS[ $kind ], true ) ) {
				$refs[ $i ]['res'] = 'push';
				continue;
			}
			$key = $kind . '|' . $ref['uuid'] . '|' . $ref['live_id'];
			if ( ! isset( $cache[ $key ] ) ) {
				$cache[ $key ] = $this->live_object( $kind, $ref['uuid'], $ref['live_id'] );
			}
			if ( $cache[ $key ] > 0 ) {
				$refs[ $i ]['res']     = 'live';
				$refs[ $i ]['live_id'] = $cache[ $key ];
				continue;
			}
			$refs[ $i ]['res'] = 'pending';
		}
		return $refs;
	}

	/**
	 * Live's own object a reference names, inside live's scope: by UUID meta, by this pairing's
	 * linked sync state, or by the source's live ID hint. 0 when none fits.
	 *
	 * @param string $kind Reference kind.
	 * @param string $uuid UUID.
	 * @param int    $hint Live ID hint.
	 * @return int
	 */
	private function live_object( $kind, $uuid, $hint ) {
		$space = in_array( $kind, array( 'term', 'menu' ), true ) ? 'term' : 'post';
		$ids   = Identity::find( $space, $uuid );
		$row   = Sync_State::find( (int) $this->pairing['id'], $uuid );
		if ( is_array( $row ) && in_array( $row['match_confidence'], Sync_State::LINKED, true ) && (int) $row['object_id'] > 0 && Scope::space( (string) $row['object_type'] ) === $space ) {
			$ids[] = (int) $row['object_id'];
		}
		if ( $hint > 0 ) {
			$ids[] = (int) $hint;
		}
		foreach ( array_unique( $ids ) as $object_id ) {
			if ( self::object_fits( $kind, $space, (int) $object_id ) ) {
				return (int) $object_id;
			}
		}
		return 0;
	}

	/**
	 * Whether a live object is of a kind a reference may name and inside live's scope.
	 *
	 * @param string $kind      Reference kind.
	 * @param string $space     'post' or 'term'.
	 * @param int    $object_id ID.
	 * @return bool
	 */
	public static function object_fits( $kind, $space, $object_id ) {
		if ( 'term' === $space ) {
			$term = get_term( $object_id );
			if ( ! $term instanceof \WP_Term ) {
				return false;
			}
			return Scope::allows_term( $term, 'menu' === $kind ? 'menu' : 'term' );
		}
		$post = get_post( $object_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		$own = Scope::kind_for_post_type( $post->post_type );
		return '' !== $own && in_array( $own, self::REF_KINDS[ $kind ], true ) && Scope::allows_post( $post, $own );
	}

	/**
	 * Reports ID fields that carry a value without a reference: live will not guess what they
	 * point to (the applier of Phase 7 decides how to write them).
	 *
	 * @param string $kind Kind.
	 * @param array  $data Cleaned data.
	 * @param array  $refs Resolved references.
	 * @return void
	 */
	private function structural( $kind, array $data, array $refs ) {
		if ( ! isset( self::STRUCTURAL[ $kind ] ) ) {
			return;
		}
		$paths = array_column( $refs, 'path' );
		foreach ( self::STRUCTURAL[ $kind ] as $path ) {
			$value = $data;
			foreach ( explode( '/', $path ) as $part ) {
				$value = is_array( $value ) && isset( $value[ $part ] ) ? $value[ $part ] : 0;
			}
			if ( is_int( $value ) && $value > 0 && ! in_array( $path, $paths, true ) ) {
				// Reported only: the apply writes an unmapped ID field as 0 (Reference_Mapper::rewrite()), never as this number.
				$this->warn( 'unmapped_reference', str_replace( '/', '.', $path ) );
			}
		}
	}
}
