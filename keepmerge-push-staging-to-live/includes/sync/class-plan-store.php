<?php
/**
 * Plans on the source: rows in pushes and push_items.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

use SoftGlaze\SafePush\Tables;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every query on a source-side plan. The plan is a pushes row with side "source"; its items are
 * push_items rows (status = class, action = default, decision = the admin's choice, seq = tab
 * band × 1,000,000 + position, payload and refs as JSON). Filters reach SQL only as allowlisted
 * values or prepared placeholders.
 */
final class Plan_Store {

	const SIDE = 'source';

	/**
	 * Minutes a plan can be used.
	 */
	const TTL = 3600;

	/**
	 * Width of a tab band in seq.
	 */
	const BAND = 1000000;

	/**
	 * Sub-tabs that list rows, with their seq band.
	 */
	const TABS = array(
		'content'     => 1,
		'woocommerce' => 2,
		'media'       => 3,
		'menus'       => 4,
		'site_editor' => 5,
		'options'     => 6,
	);

	/**
	 * Columns rows can be sorted by.
	 */
	const ORDERBY = array(
		'seq'    => 'seq',
		'class'  => 'status',
		'kind'   => 'object_type',
		'action' => 'action',
	);

	const MAX_PER_PAGE = 100;

	/**
	 * Creates a plan row.
	 *
	 * @param int   $pairing_id Pairing row ID.
	 * @param int   $user_id    User who asked.
	 * @param array $cursor     Initial step state.
	 * @param array $counts     Initial summary.
	 * @return int Plan ID (0 on failure).
	 */
	public static function create( $pairing_id, $user_id, array $cursor, array $counts ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table; one insert.
		$ok = $wpdb->insert(
			Tables::table_name( 'pushes' ),
			array(
				'push_uuid'    => wp_generate_uuid4(),
				'pairing_id'   => absint( $pairing_id ),
				'side'         => self::SIDE,
				'state'        => 'planning',
				'plan_hash'    => '',
				'counts'       => wp_json_encode( $counts ),
				'cursor_json'  => wp_json_encode( $cursor ),
				'chunk_size'   => 0,
				'started_gmt'  => gmdate( 'Y-m-d H:i:s' ),
				'initiated_by' => absint( $user_id ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * A plan row with counts and cursor decoded, or null.
	 *
	 * @param int $plan_id Plan ID.
	 * @return array|null
	 */
	public static function find( $plan_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the plan changes between requests.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND side = %s', Tables::table_name( 'pushes' ), absint( $plan_id ), self::SIDE ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['counts']      = self::decode( $row['counts'] );
		$row['cursor_json'] = self::decode( $row['cursor_json'] );
		return $row;
	}

	/**
	 * The newest plan of a pairing, or null.
	 *
	 * @param int $pairing_id Pairing row ID.
	 * @return array|null
	 */
	public static function latest( $pairing_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE pairing_id = %d AND side = %s ORDER BY id DESC LIMIT 1', Tables::table_name( 'pushes' ), absint( $pairing_id ), self::SIDE ) );
		return $id > 0 ? self::find( $id ) : null;
	}

	/**
	 * Whether a plan is older than TTL.
	 *
	 * @param array $plan Plan row.
	 * @return bool
	 */
	public static function is_expired( array $plan ) {
		$started = strtotime( (string) $plan['started_gmt'] . ' UTC' );
		return false === $started || time() - $started > self::TTL;
	}

	/**
	 * Updates plan columns (counts and cursor_json are encoded here).
	 *
	 * @param int   $plan_id Plan ID.
	 * @param array $fields  Columns: state, plan_hash, counts, cursor_json, finished_gmt, error_code, error_detail.
	 * @return void
	 */
	public static function update( $plan_id, array $fields ) {
		global $wpdb;

		$allowed = array( 'state', 'plan_hash', 'counts', 'cursor_json', 'finished_gmt', 'error_code', 'error_detail' );
		$data    = array();
		foreach ( $fields as $name => $value ) {
			if ( ! in_array( $name, $allowed, true ) ) {
				continue;
			}
			$data[ $name ] = in_array( $name, array( 'counts', 'cursor_json' ), true ) ? wp_json_encode( $value ) : $value;
		}
		if ( empty( $data ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Tables::table_name( 'pushes' ), $data, array( 'id' => absint( $plan_id ) ) );
	}

	/**
	 * Deletes a pairing's earlier source plans (a new plan replaces them) and plans older than
	 * the TTL of any pairing. Pushes that left the planning states are kept.
	 *
	 * @param int $pairing_id Pairing whose plans go (0: only expired ones).
	 * @param int $keep_id    Plan to keep.
	 * @return int Plans deleted.
	 */
	public static function prune( $pairing_id, $keep_id = 0 ) {
		global $wpdb;

		$pushes = Tables::table_name( 'pushes' );
		$items  = Tables::table_name( 'push_items' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::TTL );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE side = %s AND state IN ('planning','planned') AND id <> %d AND ( pairing_id = %d OR started_gmt < %s )",
				$pushes,
				self::SIDE,
				absint( $keep_id ),
				absint( $pairing_id ),
				$cutoff
			)
		);

		$deleted = 0;
		foreach ( is_array( $ids ) ? $ids : array() as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE push_id = %d AND side = %s', $items, absint( $id ), self::SIDE ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
			$deleted += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d AND side = %s', $pushes, absint( $id ), self::SIDE ) );
		}
		return $deleted;
	}

	/**
	 * Inserts a plan row for an item; an item already in the plan is left as it is.
	 *
	 * @param int    $plan_id  Plan ID.
	 * @param int    $seq      Sort key (tab band and position).
	 * @param string $uuid     UUID (or option:<name>, unit:widgets).
	 * @param string $kind     Kind.
	 * @param int    $local_id Local ID.
	 * @param array  $payload  Payload.
	 * @param array  $refs     Dependency UUIDs.
	 * @return bool Whether a row was added.
	 */
	public static function add_item( $plan_id, $seq, $uuid, $kind, $local_id, array $payload, array $refs ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; INSERT IGNORE keeps the first row of a UUID.
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (push_id, side, seq, uuid, object_type, action, decision, status, payload, refs, local_object_id) VALUES (%d, %s, %d, %s, %s, %s, %s, %s, %s, %s, %d)',
				Tables::table_name( 'push_items' ),
				absint( $plan_id ),
				self::SIDE,
				absint( $seq ),
				substr( (string) $uuid, 0, 191 ),
				substr( (string) $kind, 0, 32 ),
				'',
				'',
				'pending',
				(string) wp_json_encode( $payload ),
				(string) wp_json_encode( array_values( $refs ) ),
				absint( $local_id )
			)
		);
		return 1 === (int) $result;
	}

	/**
	 * Whether a plan has a row for a UUID.
	 *
	 * @param int    $plan_id Plan ID.
	 * @param string $uuid    UUID.
	 * @return bool
	 */
	public static function has_item( $plan_id, $uuid ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; unique key lookup.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE push_id = %d AND side = %s AND uuid = %s', Tables::table_name( 'push_items' ), absint( $plan_id ), self::SIDE, (string) $uuid ) );
	}

	/**
	 * One row by UUID (payload and refs decoded), or null.
	 *
	 * @param int    $plan_id Plan ID.
	 * @param string $uuid    UUID.
	 * @return array|null
	 */
	public static function item( $plan_id, $uuid ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE push_id = %d AND side = %s AND uuid = %s', Tables::table_name( 'push_items' ), absint( $plan_id ), self::SIDE, (string) $uuid ), ARRAY_A );
		return is_array( $row ) ? self::decode_row( $row ) : null;
	}

	/**
	 * Rows with a status after a row ID, by ID.
	 *
	 * @param int    $plan_id  Plan ID.
	 * @param string $status   Status.
	 * @param int    $after_id Row ID cursor.
	 * @param int    $limit    Most rows.
	 * @return array[]
	 */
	public static function items_with_status( $plan_id, $status, $after_id, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE push_id = %d AND side = %s AND status = %s AND id > %d ORDER BY id ASC LIMIT %d', Tables::table_name( 'push_items' ), absint( $plan_id ), self::SIDE, (string) $status, absint( $after_id ), max( 1, absint( $limit ) ) ), ARRAY_A );

		return array_map( array( __CLASS__, 'decode_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Every row of a plan (payload and refs decoded), by seq.
	 *
	 * @param int $plan_id Plan ID.
	 * @return array[]
	 */
	public static function all_items( $plan_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the whole plan for the dependency closure.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE push_id = %d AND side = %s ORDER BY seq ASC, id ASC', Tables::table_name( 'push_items' ), absint( $plan_id ), self::SIDE ), ARRAY_A );

		return array_map( array( __CLASS__, 'decode_row' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Updates one row.
	 *
	 * @param int   $id     Row ID.
	 * @param array $fields status, action, decision, payload (array), refs (array).
	 * @return void
	 */
	public static function update_item( $id, array $fields ) {
		global $wpdb;

		$data = array();
		foreach ( array( 'status', 'action', 'decision' ) as $name ) {
			if ( isset( $fields[ $name ] ) ) {
				$data[ $name ] = substr( (string) $fields[ $name ], 0, 20 );
			}
		}
		foreach ( array( 'payload', 'refs' ) as $name ) {
			if ( isset( $fields[ $name ] ) && is_array( $fields[ $name ] ) ) {
				$data[ $name ] = (string) wp_json_encode( $fields[ $name ] );
			}
		}
		if ( empty( $data ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Tables::table_name( 'push_items' ), $data, array( 'id' => absint( $id ) ) );
	}

	/**
	 * A page of rows for the screen.
	 *
	 * @param int   $plan_id Plan ID.
	 * @param array $filters {
	 *     Filters.
	 *
	 *     @type string   $tab      One of TABS, or '' for every tab.
	 *     @type string[] $classes  Classes (Classifier::CLASSES).
	 *     @type string   $kind     One kind or ''.
	 *     @type bool     $warnings Only rows with warnings.
	 *     @type bool     $in_scope Leave out rows outside the plan's scope.
	 *     @type string   $search   Text looked for in the row's title and type.
	 *     @type string   $orderby  Key of ORDERBY.
	 *     @type string   $order    asc or desc.
	 * }
	 * @param int   $page     1-based page.
	 * @param int   $per_page Rows per page.
	 * @return array{rows:array[],total:int,page:int,per_page:int}
	 */
	public static function page( $plan_id, array $filters, $page, $per_page ) {
		global $wpdb;

		$per_page = max( 1, min( self::MAX_PER_PAGE, absint( $per_page ) ) );
		$page     = max( 1, absint( $page ) );
		$table    = Tables::table_name( 'push_items' );

		// Every filter is always in the query, switched off by its own value, so the SQL is one
		// literal string: a tab is a seq range (0 to the largest seq when none), classes fill
		// eleven fixed slots (one per class) behind a "no class filter" switch.
		$tab  = isset( $filters['tab'] ) ? (string) $filters['tab'] : '';
		$low  = isset( self::TABS[ $tab ] ) ? self::TABS[ $tab ] * self::BAND : 0;
		$high = isset( self::TABS[ $tab ] ) ? ( self::TABS[ $tab ] + 1 ) * self::BAND : 4294967295;

		$classes = isset( $filters['classes'] ) && is_array( $filters['classes'] ) ? array_values( array_intersect( Classifier::CLASSES, $filters['classes'] ) ) : array();
		$slots   = array_slice( array_pad( $classes, 11, '' ), 0, 11 );

		$kind = isset( $filters['kind'] ) && in_array( $filters['kind'], Scope::KINDS, true ) ? (string) $filters['kind'] : '';

		$search = isset( $filters['search'] ) ? trim( (string) $filters['search'] ) : '';
		$like   = '';
		if ( '' !== $search ) {
			// The payload stores the title JSON-encoded; encode the text the same way.
			$like = '%' . $wpdb->esc_like( substr( (string) wp_json_encode( $search, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), 1, -1 ) ) . '%';
		}

		$args = array_merge(
			array( $table, absint( $plan_id ), self::SIDE, $low, $high, empty( $classes ) ? 1 : 0 ),
			$slots,
			array(
				$kind,
				$kind,
				empty( $filters['in_scope'] ) ? 0 : 1,
				'out_of_scope',
				empty( $filters['warnings'] ) ? 0 : 1,
				'%' . $wpdb->esc_like( '"has_warnings":true' ) . '%',
				$like,
				$like,
			)
		);

		$orderby = isset( $filters['orderby'], self::ORDERBY[ $filters['orderby'] ] ) ? self::ORDERBY[ $filters['orderby'] ] : 'seq';
		$offset  = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Own table; the replacements are passed as one array (25 values for 25 placeholders).
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE push_id = %d AND side = %s AND seq >= %d AND seq < %d AND ( 1 = %d OR status IN ( %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s ) ) AND ( '' = %s OR object_type = %s ) AND ( 0 = %d OR action <> %s ) AND ( 0 = %d OR payload LIKE %s ) AND ( '' = %s OR payload LIKE %s )", $args ) );

		if ( isset( $filters['order'] ) && 'desc' === $filters['order'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Own table; the replacements are passed as one array (28 values for 28 placeholders).
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE push_id = %d AND side = %s AND seq >= %d AND seq < %d AND ( 1 = %d OR status IN ( %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s ) ) AND ( '' = %s OR object_type = %s ) AND ( 0 = %d OR action <> %s ) AND ( 0 = %d OR payload LIKE %s ) AND ( '' = %s OR payload LIKE %s ) ORDER BY %i DESC, id ASC LIMIT %d OFFSET %d", array_merge( $args, array( $orderby, $per_page, $offset ) ) ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Own table; the replacements are passed as one array (28 values for 28 placeholders).
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE push_id = %d AND side = %s AND seq >= %d AND seq < %d AND ( 1 = %d OR status IN ( %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s ) ) AND ( '' = %s OR object_type = %s ) AND ( 0 = %d OR action <> %s ) AND ( 0 = %d OR payload LIKE %s ) AND ( '' = %s OR payload LIKE %s ) ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d", array_merge( $args, array( $orderby, $per_page, $offset ) ) ), ARRAY_A );
		}

		return array(
			'rows'     => array_map( array( __CLASS__, 'decode_row' ), is_array( $rows ) ? $rows : array() ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * The tab of an item.
	 *
	 * @param string $kind Kind.
	 * @param string $type Post type or taxonomy.
	 * @return string
	 */
	public static function tab_for( $kind, $type ) {
		switch ( $kind ) {
			case 'product':
				return 'woocommerce';
			case 'attachment':
				return 'media';
			case 'menu':
				return 'menus';
			case 'pattern':
			case 'navigation':
			case 'template':
			case 'template_part':
			case 'global_styles':
				return 'site_editor';
			case 'option':
			case 'widgets':
				return 'options';
			case 'term':
				return self::is_product_taxonomy( (string) $type ) ? 'woocommerce' : 'content';
			default:
				return 'content';
		}
	}

	/**
	 * Whether a taxonomy belongs to WooCommerce products.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public static function is_product_taxonomy( $taxonomy ) {
		return in_array( $taxonomy, array( 'product_cat', 'product_tag', 'product_brand', 'product_shipping_class' ), true ) || 0 === strpos( $taxonomy, 'pa_' );
	}

	/**
	 * The seq of an item at a position.
	 *
	 * @param string $tab      Tab.
	 * @param int    $position Position (below BAND).
	 * @return int
	 */
	public static function seq( $tab, $position ) {
		$band = isset( self::TABS[ $tab ] ) ? self::TABS[ $tab ] : 1;
		return $band * self::BAND + min( self::BAND - 1, absint( $position ) );
	}

	/**
	 * A row with payload and refs decoded.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function decode_row( array $row ) {
		$row['payload'] = self::decode( $row['payload'] );
		$row['refs']    = self::decode( $row['refs'] );
		return $row;
	}

	/**
	 * JSON text to an array ('' and invalid JSON give an empty array).
	 *
	 * @param mixed $json Text.
	 * @return array
	 */
	private static function decode( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : array();
	}
}
