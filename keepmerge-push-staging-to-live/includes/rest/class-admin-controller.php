<?php
/**
 * Admin REST routes.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Rest;

use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Plugin;
use SoftGlaze\SafePush\Readiness;
use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Sync\Option_Policy;
use SoftGlaze\SafePush\Tables;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes under keepmerge/v1/admin/ used by the admin screen on both sites (threat model §5.2).
 *
 * Every route needs manage_options and a valid wp_rest nonce in the X-WP-Nonce header, so they
 * work from the logged-in admin screen only, not with an Application Password. Every input is
 * read by name with get_param() and validated on its own.
 */
final class Admin_Controller {

	/**
	 * Route prefix inside the namespace.
	 */
	const BASE = '/admin';

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
			self::BASE . '/overview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_overview' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => $permission,
					'args'                => $this->settings_args(),
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_audit' ),
				'permission_callback' => $permission,
				'args'                => $this->audit_args(
					array(
						'page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100000,
							'default' => 1,
						),
					)
				),
			)
		);

		register_rest_route(
			$ns,
			self::BASE . '/audit/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_audit' ),
				'permission_callback' => $permission,
				'args'                => $this->audit_args(
					array(
						'before' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					)
				),
			)
		);
	}

	/**
	 * Requires manage_options plus a valid wp_rest nonce.
	 *
	 * Core already treats a cookie request without a valid nonce as logged out; the explicit
	 * check also keeps Application Password requests away from these routes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to manage Keepmerge.', 'keepmerge-push-staging-to-live' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$nonce = sanitize_text_field( (string) $request->get_header( 'X-WP-Nonce' ) );
		if ( '' === $nonce || false === wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Your session has expired. Reload the page and try again.', 'keepmerge-push-staging-to-live' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * GET /admin/overview: this site, pairings, last push, readiness checks.
	 *
	 * @return WP_REST_Response
	 */
	public function get_overview() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; credential columns are never selected.
		$pairings = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, pairing_uuid, role, remote_url, status, last_used_gmt FROM %i WHERE status <> %s ORDER BY id DESC LIMIT 50',
				Tables::table_name( 'pairings' ),
				'revoked'
			),
			ARRAY_A
		);
		$pairings = is_array( $pairings ) ? $pairings : array();

		$sends_to      = array();
		$receives_from = array();
		foreach ( $pairings as $row ) {
			$item = array(
				'id'        => (int) $row['id'],
				'uuid'      => $row['pairing_uuid'],
				'url'       => $row['remote_url'],
				'status'    => $row['status'],
				'last_used' => $row['last_used_gmt'],
			);
			if ( 'source' === $row['role'] ) {
				$sends_to[] = $item;
			} elseif ( 'destination' === $row['role'] ) {
				$receives_from[] = $item;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the latest push must be current.
		$last_push = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, push_uuid, side, state, started_gmt, finished_gmt FROM %i ORDER BY id DESC LIMIT %d', Tables::table_name( 'pushes' ), 1 ),
			ARRAY_A
		);

		$receive = get_option( 'softglaze_sp_receive', array() );
		$open    = is_array( $receive ) && ! empty( $receive['open'] ) && isset( $receive['closes_at'] ) && (int) $receive['closes_at'] > time();

		return rest_ensure_response(
			array(
				'site'      => array(
					'name'        => get_bloginfo( 'name' ),
					'home_url'    => home_url( '/' ),
					'site_id'     => Installer::site_id(),
					'wp_version'  => get_bloginfo( 'version' ),
					'php_version' => PHP_VERSION,
					'version'     => SOFTGLAZE_SP_VERSION,
					'multisite'   => is_multisite(),
					'woocommerce' => class_exists( 'WooCommerce' ),
				),
				'receive'   => array(
					'open'      => $open,
					'closes_at' => $open ? (int) $receive['closes_at'] : null,
				),
				'pairings'  => array(
					'sends_to'      => $sends_to,
					'receives_from' => $receives_from,
				),
				'last_push' => is_array( $last_push ) ? $last_push : null,
				'readiness' => Readiness::checks(),
			)
		);
	}

	/**
	 * GET /admin/settings: values plus what the form needs to offer them.
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response( $this->settings_payload( Settings::get() ) );
	}

	/**
	 * POST /admin/settings. Nothing is saved if any field is refused; the response lists every
	 * refused entry with its reason.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_settings( WP_REST_Request $request ) {
		$input = array();

		$skip_post_types = $request->get_param( 'skip_post_types' );
		if ( null !== $skip_post_types ) {
			$input['skip_post_types'] = $skip_post_types;
		}
		$skip_taxonomies = $request->get_param( 'skip_taxonomies' );
		if ( null !== $skip_taxonomies ) {
			$input['skip_taxonomies'] = $skip_taxonomies;
		}
		$option_groups = $request->get_param( 'option_groups' );
		if ( null !== $option_groups ) {
			$input['option_groups'] = $option_groups;
		}
		$custom_options = $request->get_param( 'custom_options' );
		if ( null !== $custom_options ) {
			$input['custom_options'] = $custom_options;
		}
		$excluded_meta = $request->get_param( 'excluded_meta' );
		if ( null !== $excluded_meta ) {
			$input['excluded_meta'] = $excluded_meta;
		}
		$item_batch = $request->get_param( 'item_batch' );
		if ( null !== $item_batch ) {
			$input['item_batch'] = $item_batch;
		}
		$chunk_kb = $request->get_param( 'chunk_kb' );
		if ( null !== $chunk_kb ) {
			$input['chunk_kb'] = $chunk_kb;
		}
		$snapshot_days = $request->get_param( 'snapshot_days' );
		if ( null !== $snapshot_days ) {
			$input['snapshot_days'] = $snapshot_days;
		}
		$audit_days = $request->get_param( 'audit_days' );
		if ( null !== $audit_days ) {
			$input['audit_days'] = $audit_days;
		}
		$allow_code_report = $request->get_param( 'allow_code_report' );
		if ( null !== $allow_code_report ) {
			$input['allow_code_report'] = $allow_code_report;
		}
		$remove_data = $request->get_param( 'remove_data_on_uninstall' );
		if ( null !== $remove_data ) {
			$input['remove_data_on_uninstall'] = $remove_data;
		}

		$result = Settings::save( $input );

		if ( ! $result['saved'] ) {
			return new WP_Error(
				'invalid_settings',
				__( 'Nothing was saved. Correct the entries listed below and save again.', 'keepmerge-push-staging-to-live' ),
				array(
					'status' => 400,
					'errors' => $result['errors'],
				)
			);
		}

		$payload            = $this->settings_payload( $result['settings'] );
		$payload['changed'] = $result['changed'];

		return rest_ensure_response( $payload );
	}

	/**
	 * GET /admin/audit: one page of events.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_audit( WP_REST_Request $request ) {
		$page = absint( $request->get_param( 'page' ) );
		$data = Audit_Log::query( $this->audit_filters( $request ), max( 1, $page ) );

		$labels = Audit_Log::event_labels();
		$events = array();
		foreach ( Audit_Log::EVENTS as $event ) {
			$events[] = array(
				'id'    => $event,
				'label' => $labels[ $event ],
			);
		}
		$data['events'] = $events;

		return rest_ensure_response( $data );
	}

	/**
	 * GET /admin/audit/export: one chunk of CSV. The screen requests chunks until has_more is
	 * false and joins them, so no single request holds the whole log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function export_audit( WP_REST_Request $request ) {
		$before = absint( $request->get_param( 'before' ) );
		$chunk  = Audit_Log::export_csv( $this->audit_filters( $request ), $before );

		$chunk['filename'] = 'keepmerge-audit-' . gmdate( 'Y-m-d' ) . '.csv';

		return rest_ensure_response( $chunk );
	}

	/**
	 * Filters for the audit routes, each read by name.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private function audit_filters( WP_REST_Request $request ) {
		$event  = sanitize_key( (string) $request->get_param( 'event' ) );
		$result = sanitize_key( (string) $request->get_param( 'result' ) );
		$from   = Audit_Log::valid_date( sanitize_text_field( (string) $request->get_param( 'from' ) ) );
		$to     = Audit_Log::valid_date( sanitize_text_field( (string) $request->get_param( 'to' ) ) );

		return array(
			'event'      => $event,
			'result'     => $result,
			'from'       => $from,
			'to'         => $to,
			'pairing_id' => absint( $request->get_param( 'pairing' ) ),
		);
	}

	/**
	 * Argument schema shared by the audit routes: cheap type and format checks only.
	 *
	 * @param array $extra Route-specific arguments.
	 * @return array
	 */
	private function audit_args( array $extra ) {
		$date = array(
			'type'    => 'string',
			'pattern' => '^(\d{4}-\d{2}-\d{2})?$',
			'default' => '',
		);

		return array_merge(
			array(
				'event'   => array(
					'type'    => 'string',
					'enum'    => array_merge( array( '' ), Audit_Log::EVENTS ),
					'default' => '',
				),
				'result'  => array(
					'type'    => 'string',
					'enum'    => array_merge( array( '' ), Audit_Log::RESULTS ),
					'default' => '',
				),
				'from'    => $date,
				'to'      => $date,
				'pairing' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'default' => 0,
				),
			),
			$extra
		);
	}

	/**
	 * Argument schema for POST settings: types only. Names, ranges and the denylist are checked
	 * by Settings::validate(), which explains each refusal.
	 *
	 * @return array
	 */
	private function settings_args() {
		$names = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		$groups = array();
		foreach ( array_keys( Settings::defaults()['option_groups'] ) as $group ) {
			$groups[ $group ] = array( 'type' => 'boolean' );
		}

		return array(
			'skip_post_types'          => $names,
			'skip_taxonomies'          => $names,
			'custom_options'           => $names,
			'excluded_meta'            => $names,
			'option_groups'            => array(
				'type'                 => 'object',
				'properties'           => $groups,
				'additionalProperties' => false,
			),
			'item_batch'               => array( 'type' => 'integer' ),
			'chunk_kb'                 => array( 'type' => 'integer' ),
			'snapshot_days'            => array( 'type' => 'integer' ),
			'audit_days'               => array( 'type' => 'integer' ),
			'allow_code_report'        => array( 'type' => 'boolean' ),
			'remove_data_on_uninstall' => array( 'type' => 'boolean' ),
		);
	}

	/**
	 * Settings plus the choices and fixed lists the form shows.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	private function settings_payload( array $settings ) {
		$groups = array();
		foreach ( Option_Policy::groups() as $id => $group ) {
			if ( 'woocommerce' === $group['requires'] && ! class_exists( 'WooCommerce' ) ) {
				continue;
			}
			$groups[] = array(
				'id'      => $id,
				'label'   => $group['label'],
				'options' => $group['options'],
			);
		}

		$ranges = array();
		foreach ( Settings::RANGES as $field => $range ) {
			$ranges[ $field ] = array(
				'min' => $range[0],
				'max' => $range[1],
			);
		}

		return array(
			'settings' => $settings,
			'choices'  => array(
				'content_types' => Settings::content_type_choices(),
				'option_groups' => $groups,
			),
			'policy'   => array(
				'option_denylist'      => Option_Policy::option_denylist(),
				'always_excluded_meta' => Option_Policy::excluded_meta_keys(),
			),
			'ranges'   => $ranges,
		);
	}
}
