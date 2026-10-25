<?php
/**
 * The audit log.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records what happened on this site (blueprint §17): who, what, from which address, with what
 * result. Never secrets, credentials, signatures, request bodies, content or option values.
 *
 * Rules enforced here rather than trusted to callers:
 * - the event must be one of the fixed EVENTS;
 * - details are stored as JSON, small, at most three levels deep, and any key named like a
 *   secret or a content body is dropped (and reported with _doing_it_wrong());
 * - repeated identical failures from one address are collapsed: one row per address, event and
 *   reason per 10 minutes, whose details.count says how many failures it stands for.
 */
final class Audit_Log {

	const EVENTS = array(
		'receive_opened',
		'receive_closed',
		'pairing_key_created',
		'pairing_expired',
		'pairing_confirmed',
		'pairing_activated',
		'pairing_rejected',
		'pairing_revoked',
		'pairing_repair_needed',
		'clone_detected',
		'auth_failed',
		'replay_blocked',
		'rate_limited',
		'ip_blocked',
		'status_checked',
		'address_refused',
		'baseline_requested',
		'manifest_requested',
		'items_fetched',
		'code_manifest_requested',
		'code_report_enabled',
		'code_report_disabled',
		'push_opened',
		'files_needed',
		'file_committed',
		'file_refused',
		'items_staged',
		'items_held',
		'held_items_decided',
		'snapshot_done',
		'push_applied',
		'push_failed',
		'push_interrupted',
		'push_resumed',
		'push_aborted',
		'lock_released',
		'rollback_started',
		'rollback_done',
		'settings_changed',
		'data_removed',
	);

	const RESULTS = array( 'ok', 'denied', 'error' );

	const ACTOR_TYPES = array( 'user', 'remote', 'system', 'cli' );

	/**
	 * Seconds during which identical failures from one address share a row.
	 */
	const COLLAPSE_WINDOW = 600;

	const PAGE_SIZE = 50;

	const EXPORT_CHUNK = 2000;

	const MAX_DETAILS_BYTES = 4096;

	const MAX_STRING = 191;

	/**
	 * Key names that would mean a secret or a content body is about to be logged.
	 */
	const FORBIDDEN_KEY_PATTERN = '/(secret|passw|token|signature|api_?key|private_?key|pairing_?key|authorization|cookie|credential|salt|^key$|_key$|^body$|_body$|^content$|^payload$|^values?$|_values?$)/i';

	/**
	 * Translated labels for every event, keyed by event name.
	 *
	 * @return array<string,string>
	 */
	public static function event_labels() {
		return array(
			'receive_opened'          => __( 'Receive window opened', 'keepmerge-push-staging-to-live' ),
			'receive_closed'          => __( 'Receive window closed', 'keepmerge-push-staging-to-live' ),
			'pairing_key_created'     => __( 'Pairing key created', 'keepmerge-push-staging-to-live' ),
			'pairing_expired'         => __( 'Pairing key expired', 'keepmerge-push-staging-to-live' ),
			'pairing_confirmed'       => __( 'Pairing confirmed', 'keepmerge-push-staging-to-live' ),
			'pairing_activated'       => __( 'Pairing approved', 'keepmerge-push-staging-to-live' ),
			'pairing_rejected'        => __( 'Pairing rejected', 'keepmerge-push-staging-to-live' ),
			'pairing_revoked'         => __( 'Pairing revoked', 'keepmerge-push-staging-to-live' ),
			'pairing_repair_needed'   => __( 'Pairing needs re-pairing', 'keepmerge-push-staging-to-live' ),
			'clone_detected'          => __( 'Copied database detected', 'keepmerge-push-staging-to-live' ),
			'auth_failed'             => __( 'Authentication failed', 'keepmerge-push-staging-to-live' ),
			'replay_blocked'          => __( 'Replayed request blocked', 'keepmerge-push-staging-to-live' ),
			'rate_limited'            => __( 'Rate limit reached', 'keepmerge-push-staging-to-live' ),
			'ip_blocked'              => __( 'Address blocked', 'keepmerge-push-staging-to-live' ),
			'status_checked'          => __( 'Status checked', 'keepmerge-push-staging-to-live' ),
			'address_refused'         => __( 'Address refused', 'keepmerge-push-staging-to-live' ),
			'baseline_requested'      => __( 'Baseline requested', 'keepmerge-push-staging-to-live' ),
			'manifest_requested'      => __( 'Manifest requested', 'keepmerge-push-staging-to-live' ),
			'items_fetched'           => __( 'Items fetched for a diff', 'keepmerge-push-staging-to-live' ),
			'code_manifest_requested' => __( 'Code report requested', 'keepmerge-push-staging-to-live' ),
			'code_report_enabled'     => __( 'Code report requests allowed', 'keepmerge-push-staging-to-live' ),
			'code_report_disabled'    => __( 'Code report requests turned off', 'keepmerge-push-staging-to-live' ),
			'push_opened'             => __( 'Push opened', 'keepmerge-push-staging-to-live' ),
			'files_needed'            => __( 'Files needed', 'keepmerge-push-staging-to-live' ),
			'file_committed'          => __( 'File received', 'keepmerge-push-staging-to-live' ),
			'file_refused'            => __( 'File refused', 'keepmerge-push-staging-to-live' ),
			'items_staged'            => __( 'Items staged', 'keepmerge-push-staging-to-live' ),
			'items_held'              => __( 'Items held for review', 'keepmerge-push-staging-to-live' ),
			'held_items_decided'      => __( 'Held items decided', 'keepmerge-push-staging-to-live' ),
			'snapshot_done'           => __( 'Snapshot written', 'keepmerge-push-staging-to-live' ),
			'push_applied'            => __( 'Push applied', 'keepmerge-push-staging-to-live' ),
			'push_failed'             => __( 'Push failed', 'keepmerge-push-staging-to-live' ),
			'push_interrupted'        => __( 'Push interrupted', 'keepmerge-push-staging-to-live' ),
			'push_resumed'            => __( 'Push resumed', 'keepmerge-push-staging-to-live' ),
			'push_aborted'            => __( 'Push aborted', 'keepmerge-push-staging-to-live' ),
			'lock_released'           => __( 'Push lock released', 'keepmerge-push-staging-to-live' ),
			'rollback_started'        => __( 'Rollback started', 'keepmerge-push-staging-to-live' ),
			'rollback_done'           => __( 'Rollback finished', 'keepmerge-push-staging-to-live' ),
			'settings_changed'        => __( 'Settings changed', 'keepmerge-push-staging-to-live' ),
			'data_removed'            => __( 'Plugin data removed', 'keepmerge-push-staging-to-live' ),
		);
	}

	/**
	 * Writes one event.
	 *
	 * @param string $event One of self::EVENTS.
	 * @param array  $args  {
	 *     Optional.
	 *
	 *     @type string $result      'ok' (default), 'denied' or 'error'.
	 *     @type string $actor_type  'user', 'remote', 'system' or 'cli'; detected when omitted.
	 *     @type int    $actor_id    User ID for user and cli actors.
	 *     @type int    $pairing_id  Pairing row ID.
	 *     @type string $object_type Object type, for example 'post' or 'option'.
	 *     @type string $object_ref  UUID or name of the object, never its content.
	 *     @type string $ip          Address to record; defaults to the client address for user and remote actors.
	 *     @type array  $details     Small array of facts; 'reason' takes part in collapsing.
	 * }
	 * @return int|false Row ID written or updated, false when nothing was written.
	 */
	public static function write( $event, array $args = array() ) {
		global $wpdb;

		if ( ! is_string( $event ) || ! in_array( $event, self::EVENTS, true ) ) {
			_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Unknown audit event "%s".', is_string( $event ) ? $event : gettype( $event ) ) ), '1.0.0' );
			return false;
		}

		$result = isset( $args['result'] ) && in_array( $args['result'], self::RESULTS, true ) ? $args['result'] : 'ok';

		list( $detected_type, $detected_id ) = self::detect_actor();
		$actor_type                          = isset( $args['actor_type'] ) && in_array( $args['actor_type'], self::ACTOR_TYPES, true ) ? $args['actor_type'] : $detected_type;
		if ( isset( $args['actor_id'] ) ) {
			$actor_id = absint( $args['actor_id'] );
		} else {
			$actor_id = $actor_type === $detected_type ? $detected_id : 0;
		}

		if ( array_key_exists( 'ip', $args ) ) {
			$ip = is_string( $args['ip'] ) ? (string) filter_var( $args['ip'], FILTER_VALIDATE_IP ) : '';
		} else {
			$ip = in_array( $actor_type, array( 'user', 'remote' ), true ) ? Throttle::client_ip() : '';
		}

		$details = self::clean_details( isset( $args['details'] ) && is_array( $args['details'] ) ? $args['details'] : array() );
		$table   = Tables::table_name( 'audit' );
		$now     = gmdate( 'Y-m-d H:i:s' );

		if ( 'ok' !== $result && '' !== $ip ) {
			$reason = isset( $details['reason'] ) && is_scalar( $details['reason'] ) ? (string) $details['reason'] : '';
			$open   = self::find_open_row( $event, $ip, $reason );
			if ( null !== $open ) {
				$open['details']['count']   = ( isset( $open['details']['count'] ) ? absint( $open['details']['count'] ) : 1 ) + 1;
				$open['details']['last_at'] = $now;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; $wpdb->update prepares the statement.
				$wpdb->update( $table, array( 'details' => wp_json_encode( $open['details'] ) ), array( 'id' => $open['id'] ), array( '%s' ), array( '%d' ) );
				return $open['id'];
			}
			$details['count'] = 1;
		}

		$row = array(
			'created_gmt' => $now,
			'pairing_id'  => isset( $args['pairing_id'] ) ? absint( $args['pairing_id'] ) : 0,
			'actor_type'  => $actor_type,
			'actor_id'    => $actor_id,
			'event'       => $event,
			'object_type' => isset( $args['object_type'] ) && is_string( $args['object_type'] ) ? substr( sanitize_key( $args['object_type'] ), 0, 32 ) : '',
			'object_ref'  => isset( $args['object_ref'] ) && is_scalar( $args['object_ref'] ) ? substr( sanitize_text_field( (string) $args['object_ref'] ), 0, self::MAX_STRING ) : '',
			'ip'          => $ip,
			'result'      => $result,
			'details'     => empty( $details ) ? null : wp_json_encode( $details ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Own table; $wpdb->insert prepares the statement.
		$ok = $wpdb->insert( $table, $row, array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * One page of events, newest first.
	 *
	 * @param array $filters Keys event, result, from, to (Y-m-d, site time not applied: UTC), pairing_id.
	 * @param int   $page    Page number, from 1.
	 * @return array{rows:array,total:int,page:int,pages:int,per_page:int}
	 */
	public static function query( array $filters, $page = 1 ) {
		global $wpdb;

		$f      = self::normalise_filters( $filters );
		$page   = max( 1, absint( $page ) );
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$table  = Tables::table_name( 'audit' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the log must show current rows.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE ( %s = '' OR event = %s ) AND ( %s = '' OR result = %s ) AND created_gmt >= %s AND created_gmt <= %s AND ( 0 = %d OR pairing_id = %d )",
				$table,
				$f['event'],
				$f['event'],
				$f['result'],
				$f['result'],
				$f['from'],
				$f['to'],
				$f['pairing_id'],
				$f['pairing_id']
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the log must show current rows.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE ( %s = '' OR event = %s ) AND ( %s = '' OR result = %s ) AND created_gmt >= %s AND created_gmt <= %s AND ( 0 = %d OR pairing_id = %d ) ORDER BY id DESC LIMIT %d OFFSET %d",
				$table,
				$f['event'],
				$f['event'],
				$f['result'],
				$f['result'],
				$f['from'],
				$f['to'],
				$f['pairing_id'],
				$f['pairing_id'],
				self::PAGE_SIZE,
				$offset
			),
			ARRAY_A
		);

		return array(
			'rows'     => array_map( array( __CLASS__, 'present' ), is_array( $rows ) ? $rows : array() ),
			'total'    => $total,
			'page'     => $page,
			'pages'    => max( 1, (int) ceil( $total / self::PAGE_SIZE ) ),
			'per_page' => self::PAGE_SIZE,
		);
	}

	/**
	 * One chunk of the CSV export, newest first, paged by ID so rows written during the export
	 * neither repeat nor shift the pages.
	 *
	 * @param array $filters   Same filters as query().
	 * @param int   $before_id Export rows with an ID below this; 0 starts at the newest row and adds the header line.
	 * @return array{csv:string,rows:int,next_before:int,has_more:bool}
	 */
	public static function export_csv( array $filters, $before_id = 0 ) {
		global $wpdb;

		$f         = self::normalise_filters( $filters );
		$before_id = absint( $before_id );
		$table     = Tables::table_name( 'audit' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; an export must be current.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE ( %s = '' OR event = %s ) AND ( %s = '' OR result = %s ) AND created_gmt >= %s AND created_gmt <= %s AND ( 0 = %d OR pairing_id = %d ) AND ( 0 = %d OR id < %d ) ORDER BY id DESC LIMIT %d",
				$table,
				$f['event'],
				$f['event'],
				$f['result'],
				$f['result'],
				$f['from'],
				$f['to'],
				$f['pairing_id'],
				$f['pairing_id'],
				$before_id,
				$before_id,
				self::EXPORT_CHUNK + 1
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$has_more = count( $rows ) > self::EXPORT_CHUNK;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$lines = array();
		if ( 0 === $before_id ) {
			$lines[] = self::csv_line( array( 'id', 'created_gmt', 'event', 'result', 'actor_type', 'actor_id', 'actor', 'pairing_id', 'object_type', 'object_ref', 'ip', 'details' ) );
		}

		$last_id = 0;
		foreach ( $rows as $raw ) {
			$row     = self::present( $raw );
			$lines[] = self::csv_line(
				array(
					$row['id'],
					$row['created_gmt'],
					$row['event'],
					$row['result'],
					$row['actor_type'],
					$row['actor_id'],
					$row['actor'],
					$row['pairing_id'],
					$row['object_type'],
					$row['object_ref'],
					$row['ip'],
					empty( $row['details'] ) ? '' : wp_json_encode( $row['details'] ),
				)
			);
			$last_id = (int) $row['id'];
		}

		return array(
			'csv'         => implode( '', $lines ),
			'rows'        => count( $rows ),
			'next_before' => $has_more ? $last_id : 0,
			'has_more'    => $has_more,
		);
	}

	/**
	 * Deletes events older than the retention, in bounded batches.
	 *
	 * @param int $days Retention in days (clamped to 1–3650).
	 * @return int Rows deleted.
	 */
	public static function prune( $days ) {
		global $wpdb;

		$days    = min( 3650, max( 1, absint( $days ) ) );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$table   = Tables::table_name( 'audit' );
		$deleted = 0;

		for ( $batch = 0; $batch < 20; $batch++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; bounded batch delete.
			$n        = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_gmt < %s LIMIT %d', $table, $cutoff, 5000 ) );
			$deleted += $n;
			if ( $n < 5000 ) {
				break;
			}
		}

		return $deleted;
	}

	/**
	 * One CSV cell, quoted, with spreadsheet formula triggers neutralised: a cell that starts
	 * with =, +, -, @, a tab or a carriage return gets a leading apostrophe, so a value such as
	 * =HYPERLINK(...) is shown as text instead of being evaluated (threat model S-72).
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function csv_cell( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = str_replace( "\0", '', $value );

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}

		return '"' . str_replace( '"', '""', $value ) . '"';
	}

	/**
	 * One CSV line (RFC 4180: CRLF).
	 *
	 * @param array $cells Cells.
	 * @return string
	 */
	public static function csv_line( array $cells ) {
		return implode( ',', array_map( array( __CLASS__, 'csv_cell' ), $cells ) ) . "\r\n";
	}

	/**
	 * Validated filters with defaults.
	 *
	 * @param array $filters Raw filters.
	 * @return array{event:string,result:string,from:string,to:string,pairing_id:int}
	 */
	public static function normalise_filters( array $filters ) {
		$event  = isset( $filters['event'] ) && in_array( $filters['event'], self::EVENTS, true ) ? $filters['event'] : '';
		$result = isset( $filters['result'] ) && in_array( $filters['result'], self::RESULTS, true ) ? $filters['result'] : '';

		$from = self::valid_date( isset( $filters['from'] ) ? $filters['from'] : '' );
		$to   = self::valid_date( isset( $filters['to'] ) ? $filters['to'] : '' );

		return array(
			'event'      => $event,
			'result'     => $result,
			'from'       => '' === $from ? '1970-01-01 00:00:00' : $from . ' 00:00:00',
			'to'         => '' === $to ? '9999-12-31 23:59:59' : $to . ' 23:59:59',
			'pairing_id' => isset( $filters['pairing_id'] ) ? absint( $filters['pairing_id'] ) : 0,
		);
	}

	/**
	 * A Y-m-d date string when valid, else ''.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	public static function valid_date( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return '';
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
	}

	/**
	 * Shapes a database row for the screen and the export.
	 *
	 * @param array $raw Row from the audit table.
	 * @return array
	 */
	private static function present( array $raw ) {
		static $users = array();

		$labels  = self::event_labels();
		$details = isset( $raw['details'] ) && is_string( $raw['details'] ) ? json_decode( $raw['details'], true ) : null;
		$actor   = '';
		$user_id = absint( $raw['actor_id'] );

		if ( in_array( $raw['actor_type'], array( 'user', 'cli' ), true ) && $user_id > 0 ) {
			if ( ! array_key_exists( $user_id, $users ) ) {
				$user              = get_userdata( $user_id );
				$users[ $user_id ] = $user ? $user->user_login : '';
			}
			$actor = $users[ $user_id ];
		}

		$timestamp = strtotime( $raw['created_gmt'] . ' UTC' );

		return array(
			'id'          => (int) $raw['id'],
			'created_gmt' => $raw['created_gmt'],
			'created'     => false === $timestamp ? $raw['created_gmt'] : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ),
			'event'       => $raw['event'],
			'event_label' => isset( $labels[ $raw['event'] ] ) ? $labels[ $raw['event'] ] : $raw['event'],
			'result'      => $raw['result'],
			'actor_type'  => $raw['actor_type'],
			'actor_id'    => $user_id,
			'actor'       => $actor,
			'pairing_id'  => (int) $raw['pairing_id'],
			'object_type' => $raw['object_type'],
			'object_ref'  => $raw['object_ref'],
			'ip'          => $raw['ip'],
			'details'     => is_array( $details ) ? $details : array(),
		);
	}

	/**
	 * The newest row inside the collapse window for this event, address and reason.
	 *
	 * Rows inside one window are already collapsed per reason, so a handful of rows is the most
	 * that can match; the LIMIT keeps the lookup bounded either way.
	 *
	 * @param string $event  Event.
	 * @param string $ip     Address.
	 * @param string $reason Reason code, '' when the caller gave none.
	 * @return array{id:int,details:array}|null
	 */
	private static function find_open_row( $event, $ip, $reason ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - self::COLLAPSE_WINDOW );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; a cached answer would split one burst into many rows.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, details FROM %i WHERE event = %s AND created_gmt >= %s AND ip = %s AND result <> %s ORDER BY id DESC LIMIT 20',
				Tables::table_name( 'audit' ),
				$event,
				$since,
				$ip,
				'ok'
			),
			ARRAY_A
		);

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$details = is_string( $row['details'] ) ? json_decode( $row['details'], true ) : null;
			$details = is_array( $details ) ? $details : array();
			$theirs  = isset( $details['reason'] ) && is_scalar( $details['reason'] ) ? (string) $details['reason'] : '';
			if ( $theirs === $reason ) {
				return array(
					'id'      => (int) $row['id'],
					'details' => $details,
				);
			}
		}

		return null;
	}

	/**
	 * Keeps details small and free of secrets.
	 *
	 * @param array $details Raw details.
	 * @param int   $depth   Current depth.
	 * @return array
	 */
	private static function clean_details( array $details, $depth = 0 ) {
		$clean = array();

		foreach ( $details as $key => $value ) {
			if ( is_string( $key ) ) {
				if ( 1 !== preg_match( '/^[A-Za-z0-9_]{1,64}$/', $key ) ) {
					continue;
				}
				if ( 1 === preg_match( self::FORBIDDEN_KEY_PATTERN, $key ) ) {
					_doing_it_wrong( __METHOD__, esc_html( sprintf( 'Audit details may not carry "%s": secrets and content are never logged.', $key ) ), '1.0.0' );
					continue;
				}
			} elseif ( ! is_int( $key ) ) {
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$clean[ $key ] = $value;
			} elseif ( is_string( $value ) ) {
				$clean[ $key ] = substr( sanitize_text_field( $value ), 0, self::MAX_STRING );
			} elseif ( is_array( $value ) && $depth < 2 ) {
				$clean[ $key ] = self::clean_details( $value, $depth + 1 );
			}
		}

		if ( 0 === $depth && strlen( (string) wp_json_encode( $clean ) ) > self::MAX_DETAILS_BYTES ) {
			$clean              = array_filter( $clean, 'is_scalar' );
			$clean['truncated'] = true;
		}

		return $clean;
	}

	/**
	 * Who is acting in this request.
	 *
	 * @return array{0:string,1:int} Actor type and user ID.
	 */
	private static function detect_actor() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return array( 'cli', get_current_user_id() );
		}
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return array( 'user', $user_id );
		}
		return array( 'system', 0 );
	}
}
