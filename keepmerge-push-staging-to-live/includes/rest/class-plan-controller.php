<?php
/**
 * Admin REST routes of the plan and the code report (source).
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Rest;

use SoftGlaze\SafePush\Plugin;
use SoftGlaze\SafePush\Source_Pairing;
use SoftGlaze\SafePush\Sync\Classifier;
use SoftGlaze\SafePush\Sync\Code_Report;
use SoftGlaze\SafePush\Sync\Plan_Diff;
use SoftGlaze\SafePush\Sync\Plan_Store;
use SoftGlaze\SafePush\Sync\Planner;
use SoftGlaze\SafePush\Sync\Scope;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin routes for plans and the code report: the dry run, its rows, one item's diff, the
 * conflict decisions and the code report.
 *
 * Every route needs manage_options and a valid wp_rest nonce. Route args are cheap type checks
 * only, because core runs them before the permission callback; handlers validate each value.
 */
final class Plan_Controller {

	const BASE = '/admin';

	/**
	 * Row filters by class.
	 */
	const CLASS_FILTERS = array(
		'changes'   => array( 'staging_changed', 'live_changed', 'both_same', 'conflict', 'new_staging', 'deleted_staging', 'deleted_live_chg', 'deleted_live', 'unsupported', 'excluded' ),
		'conflicts' => array( 'conflict', 'deleted_live_chg' ),
		'push'      => array( 'staging_changed', 'new_staging' ),
		'live'      => array( 'live_changed', 'deleted_staging', 'deleted_live' ),
		'skipped'   => array( 'unsupported', 'excluded' ),
		'unchanged' => array( 'unchanged', 'both_same' ),
	);

	const ITEM_KEY = '(?P<item>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|option:[A-Za-z0-9_.:-]{1,191}|unit:widgets)';

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$ns         = Plugin::REST_NAMESPACE;
		$permission = array( $this, 'permission' );

		register_rest_route(
			$ns,
			self::BASE . '/plans',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_plan' ),
				'permission_callback' => $permission,
				'args'                => array(
					'pairing' => array(
						'type'      => 'string',
						'maxLength' => 36,
					),
					'types'   => array(
						'type'     => 'array',
						'items'    => array(
							'type'      => 'string',
							'maxLength' => 20,
						),
						'maxItems' => 10,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/plans/(?P<id>[0-9]{1,18})/step',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'step_plan' ),
				'permission_callback' => $permission,
				'args'                => array(
					'steps' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/plans/(?P<id>[0-9]{1,18})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_plan' ),
				'permission_callback' => $permission,
				'args'                => array(
					'tab'      => array(
						'type'      => 'string',
						'maxLength' => 20,
					),
					'class'    => array(
						'type'      => 'string',
						'maxLength' => 20,
					),
					'kind'     => array(
						'type'      => 'string',
						'maxLength' => 20,
					),
					'warnings' => array( 'type' => 'boolean' ),
					'all'      => array( 'type' => 'boolean' ),
					'search'   => array(
						'type'      => 'string',
						'maxLength' => 100,
					),
					'orderby'  => array(
						'type'      => 'string',
						'maxLength' => 20,
					),
					'order'    => array(
						'type'      => 'string',
						'maxLength' => 4,
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100000,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => Plan_Store::MAX_PER_PAGE,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/plans/(?P<id>[0-9]{1,18})/items/' . self::ITEM_KEY . '/diff',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_diff' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/plans/(?P<id>[0-9]{1,18})/decisions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'decide' ),
				'permission_callback' => $permission,
				'args'                => array(
					'decisions' => array(
						'type'     => 'array',
						'maxItems' => 500,
						'items'    => array( 'type' => 'object' ),
					),
					'bulk'      => array(
						'type'      => 'string',
						'maxLength' => 30,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/code-report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_code_report' ),
				'permission_callback' => $permission,
				'args'                => array(
					'pairing' => array(
						'type'      => 'string',
						'maxLength' => 36,
					),
					'run'     => array( 'type' => 'boolean' ),
					'restart' => array( 'type' => 'boolean' ),
				),
			)
		);
	}

	/**
	 * Requires manage_options plus a valid wp_rest nonce, as on every admin route.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission( WP_REST_Request $request ) {
		return ( new Admin_Controller() )->permission( $request );
	}

	/**
	 * POST /admin/plans: starts a plan for a pairing (scope: every included type, or the named
	 * groups).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_plan( WP_REST_Request $request ) {
		$pairing = self::pairing_uuid( $request->get_param( 'pairing' ) );
		$types   = $request->get_param( 'types' );
		$groups  = array();
		foreach ( is_array( $types ) ? $types : array() as $type ) {
			$type = is_string( $type ) ? sanitize_key( $type ) : '';
			if ( ! isset( Planner::SCOPES[ $type ] ) ) {
				return new WP_Error( 'invalid_scope', __( 'Unknown type in the plan scope.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
			}
			$groups[] = $type;
		}

		$result = Planner::start( $pairing, $groups, get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /admin/plans/{id}/step: one time-boxed step.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function step_plan( WP_REST_Request $request ) {
		$steps = $request->get_param( 'steps' );
		$args  = is_int( $steps ) ? array( 'steps' => $steps ) : array();

		$result = Planner::step( absint( $request->get_param( 'id' ) ), $args );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /admin/plans/{id}: the plan and one page of its rows.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_plan( WP_REST_Request $request ) {
		$plan = Planner::usable( absint( $request->get_param( 'id' ) ) );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}

		$out = array(
			'plan' => Planner::present( $plan ),
			'rows' => null,
		);
		if ( 'planned' !== $plan['state'] ) {
			return rest_ensure_response( $out );
		}

		$filters = self::filters( $request );
		$page    = $request->get_param( 'page' );
		$size    = $request->get_param( 'per_page' );
		$result  = Plan_Store::page( (int) $plan['id'], $filters, is_int( $page ) ? $page : 1, is_int( $size ) ? $size : 25 );

		$pairing = Source_Pairing::find( isset( $plan['cursor_json']['pairing'] ) ? $plan['cursor_json']['pairing'] : '' );
		$titles  = self::titles( (int) $plan['id'], $result['rows'] );
		$rows    = array();
		foreach ( $result['rows'] as $row ) {
			$rows[] = Planner::present_row( $row, $titles, is_array( $pairing ) ? $pairing : array() );
		}

		$out['rows'] = array(
			'items'    => $rows,
			'total'    => $result['total'],
			'page'     => $result['page'],
			'per_page' => $result['per_page'],
			'filters'  => array(
				'tab'      => $filters['tab'],
				'class'    => $filters['class_key'],
				'kind'     => $filters['kind'],
				'warnings' => $filters['warnings'],
				'search'   => $filters['search'],
				'orderby'  => $filters['orderby'],
				'order'    => $filters['order'],
			),
		);

		return rest_ensure_response( $out );
	}

	/**
	 * GET /admin/plans/{id}/items/{item}/diff: the text diff of one item (reads live through
	 * POST /remote/items/fetch).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_diff( WP_REST_Request $request ) {
		$plan = Planner::usable( absint( $request->get_param( 'id' ) ) );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$key = (string) $request->get_param( 'item' );
		if ( 1 !== preg_match( '#\A' . self::ITEM_KEY . '\z#', $key ) ) {
			return new WP_Error( 'item_not_found', __( 'This item is not in the plan.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
		}
		$row = Plan_Store::item( (int) $plan['id'], $key );
		if ( null === $row ) {
			return new WP_Error( 'item_not_found', __( 'This item is not in the plan.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
		}

		$result = Plan_Diff::build( $plan, $row );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * POST /admin/plans/{id}/decisions: conflict decisions and changed defaults, or the bulk
	 * "Keep live for all remaining".
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function decide( WP_REST_Request $request ) {
		$raw  = $request->get_param( 'decisions' );
		$bulk = $request->get_param( 'bulk' );
		$bulk = is_string( $bulk ) ? sanitize_key( $bulk ) : '';
		if ( '' !== $bulk && 'keep_live_remaining' !== $bulk ) {
			return new WP_Error( 'invalid_bulk', __( 'Unknown bulk action.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}

		$decisions = array();
		foreach ( is_array( $raw ) ? $raw : array() as $entry ) {
			if ( ! is_array( $entry ) ) {
				return self::invalid_decision();
			}
			$item   = isset( $entry['uuid'] ) && is_string( $entry['uuid'] ) ? $entry['uuid'] : '';
			$action = isset( $entry['action'] ) && is_string( $entry['action'] ) ? sanitize_key( $entry['action'] ) : '';
			$group  = isset( $entry['group'] ) && is_string( $entry['group'] ) ? sanitize_key( $entry['group'] ) : '';
			if ( 1 !== preg_match( '#\A' . self::ITEM_KEY . '\z#', $item ) || ! in_array( $action, Classifier::ACTIONS, true ) || ( '' !== $group && ! in_array( $group, array_merge( Classifier::GROUPS, array( 'live_owned' ) ), true ) ) ) {
				return self::invalid_decision();
			}
			$decisions[] = array(
				'uuid'   => $item,
				'action' => $action,
				'group'  => $group,
			);
		}
		if ( empty( $decisions ) && '' === $bulk ) {
			return self::invalid_decision();
		}

		$result = Planner::decide( absint( $request->get_param( 'id' ) ), $decisions, $bulk );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * GET /admin/code-report: the stored report of a pairing; with run=1, one time-boxed step
	 * (live's inventory, then file hashes compared with this site's files).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_code_report( WP_REST_Request $request ) {
		$pairing = self::pairing_uuid( $request->get_param( 'pairing' ) );
		if ( null === Source_Pairing::find( $pairing ) ) {
			return new WP_Error( 'pairing_not_found', __( 'This pairing does not exist on this site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
		}

		if ( true === $request->get_param( 'run' ) ) {
			$result = Code_Report::step( $pairing, true === $request->get_param( 'restart' ) );
			return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
		}

		return rest_ensure_response( Code_Report::present( Code_Report::record( $pairing ) ) );
	}

	/**
	 * The row filters of a request, each from an allowlist.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function filters( WP_REST_Request $request ) {
		$tab      = sanitize_key( (string) $request->get_param( 'tab' ) );
		$class    = sanitize_key( (string) $request->get_param( 'class' ) );
		$kind     = sanitize_key( (string) $request->get_param( 'kind' ) );
		$orderby  = sanitize_key( (string) $request->get_param( 'orderby' ) );
		$order    = sanitize_key( (string) $request->get_param( 'order' ) );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$warnings = true === $request->get_param( 'warnings' );
		$all      = true === $request->get_param( 'all' );

		$tab = isset( Plan_Store::TABS[ $tab ] ) ? $tab : '';
		if ( isset( self::CLASS_FILTERS[ $class ] ) ) {
			$classes = self::CLASS_FILTERS[ $class ];
		} elseif ( in_array( $class, Classifier::CLASSES, true ) ) {
			$classes = array( $class );
		} else {
			$class   = '';
			$classes = array();
		}

		return array(
			'tab'       => $tab,
			'classes'   => $classes,
			'class_key' => $class,
			'kind'      => in_array( $kind, Scope::KINDS, true ) ? $kind : '',
			'warnings'  => $warnings,
			'in_scope'  => ! $all,
			'search'    => substr( $search, 0, 100 ),
			'orderby'   => isset( Plan_Store::ORDERBY[ $orderby ] ) ? $orderby : 'seq',
			'order'     => 'desc' === $order ? 'desc' : 'asc',
		);
	}

	/**
	 * Titles of the rows that the given rows name as dependencies.
	 *
	 * @param int     $plan_id Plan ID.
	 * @param array[] $rows    Rows.
	 * @return array<string,string>
	 */
	private static function titles( $plan_id, array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			foreach ( array( 'blocked_by', 'pulled_in_by' ) as $field ) {
				foreach ( isset( $row['payload'][ $field ] ) ? (array) $row['payload'][ $field ] : array() as $uuid ) {
					if ( isset( $out[ $uuid ] ) ) {
						continue;
					}
					$other        = Plan_Store::item( $plan_id, (string) $uuid );
					$out[ $uuid ] = null === $other || ! isset( $other['payload']['title'] ) ? '' : (string) $other['payload']['title'];
				}
			}
		}
		return $out;
	}

	/**
	 * A pairing UUID from input, '' when malformed.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function pairing_uuid( $value ) {
		return is_string( $value ) && wp_is_uuid( $value, 4 ) ? strtolower( $value ) : '';
	}

	/**
	 * A refused decision list.
	 *
	 * @return WP_Error
	 */
	private static function invalid_decision() {
		return new WP_Error( 'invalid_decision', __( 'The decision could not be read. Reload the plan and try again.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
	}
}
