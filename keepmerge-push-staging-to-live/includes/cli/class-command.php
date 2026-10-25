<?php
/**
 * WP-CLI commands.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Cli;

use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Pairing_Service;
use SoftGlaze\SafePush\Receive_Window;
use SoftGlaze\SafePush\Source_Pairing;
use SoftGlaze\SafePush\Sync\Classifier;
use SoftGlaze\SafePush\Sync\Plan_Store;
use SoftGlaze\SafePush\Sync\Planner;
use WP_CLI;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pairs this site, controls the receive window and builds dry-run plans from the command line.
 *
 * Shell access implies full trust on this site; every change is still audited with the actor
 * "cli", and destructive commands ask first unless --yes is given.
 *
 * TODO(Phase 5–8): push, pushes, rollback, audit.
 */
final class Command {

	/**
	 * Shows this site's ID, the receive window, the pairings that receive on this site and the
	 * pairings this site sends through.
	 *
	 * Expired keys and approvals are removed first, as on the Receive tab. Each pairing this site
	 * sends through is checked with one signed status call to its live site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp keepmerge status
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		Pairing_Service::cleanup_expired();

		$open  = Receive_Window::is_open();
		$state = Receive_Window::state();

		WP_CLI::line( 'Site ID:        ' . Installer::site_id() );
		WP_CLI::line( 'Home URL:       ' . home_url( '/' ) );
		WP_CLI::line( 'Receive window: ' . ( $open ? 'open until ' . gmdate( 'Y-m-d H:i:s', $state['closes_at'] ) . ' UTC' : 'closed' ) );

		$rows = array();
		foreach ( Pairing_Service::list_destination() as $pairing ) {
			$rows[] = array(
				'pairing'   => $pairing['uuid'],
				'status'    => $pairing['status'],
				'source'    => '' === $pairing['source_url'] ? '-' : $pairing['source_url'],
				'source_ip' => '' === $pairing['source_ip'] ? '-' : $pairing['source_ip'],
				'site_id'   => '' === $pairing['source_site_id'] ? '-' : $pairing['source_site_id'],
				'expires'   => null === $pairing['expires_at'] ? '-' : gmdate( 'Y-m-d H:i:s', $pairing['expires_at'] ),
				'last_used' => null === $pairing['last_used_at'] ? '-' : gmdate( 'Y-m-d H:i:s', $pairing['last_used_at'] ),
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::line( 'Pairings (receiving): none' );
		} else {
			WP_CLI::line( 'Pairings (receiving):' );
			WP_CLI\Utils\format_items( 'table', $rows, array( 'pairing', 'status', 'source', 'source_ip', 'site_id', 'expires', 'last_used' ) );
		}

		$sending = Source_Pairing::list_pairings();
		if ( empty( $sending ) ) {
			WP_CLI::line( 'Pairings (sending): none' );
			return;
		}

		$rows     = array();
		$messages = array();
		foreach ( $sending as $pairing ) {
			$checked = Source_Pairing::check( $pairing['uuid'] );
			if ( is_array( $checked ) ) {
				$pairing = $checked;
			}
			$last   = $pairing['last_check'];
			$rows[] = array(
				'pairing'    => $pairing['uuid'],
				'status'     => $pairing['display_status'],
				'live'       => '' === $pairing['live']['home_url'] ? $pairing['live']['host'] : $pairing['live']['home_url'],
				'check'      => null === $last ? '-' : ( $last['ok'] ? 'ok (' . $last['state'] . ')' : $last['code'] ),
				'address'    => '' === $pairing['address'] ? '-' : $pairing['address'],
				'clock_skew' => null === $pairing['skew'] ? '-' : $pairing['skew'] . ' s',
				'auth_hdr'   => null === $pairing['authorization_header_seen'] ? '-' : ( $pairing['authorization_header_seen'] ? 'yes' : 'no' ),
			);
			if ( null !== $last && ! $last['ok'] && '' !== $last['message'] ) {
				$messages[] = $pairing['uuid'] . ': ' . $last['message'];
			} elseif ( '' !== $pairing['reason_message'] && 'active' !== $pairing['status'] ) {
				$messages[] = $pairing['uuid'] . ': ' . $pairing['reason_message'];
			} elseif ( 'awaiting_approval' === $pairing['status'] ) {
				$messages[] = $pairing['uuid'] . ': Waiting for approval on live (wp keepmerge pair-approve ' . $pairing['uuid'] . ' there).';
			}
		}

		WP_CLI::line( 'Pairings (sending):' );
		WP_CLI\Utils\format_items( 'table', $rows, array( 'pairing', 'status', 'live', 'check', 'address', 'clock_skew', 'auth_hdr' ) );
		foreach ( $messages as $message ) {
			WP_CLI::line( $message );
		}
	}

	/**
	 * Connects this site to a live site with a pairing key created there.
	 *
	 * Shows what the key points to (live's name, address, versions, clock difference, whether
	 * the Authorization header arrives), asks, then confirms and finalizes. The live
	 * administrator approves the pairing afterwards. The key works once.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The pairing key shown on the live site (quote it).
	 *
	 * [--yes]
	 * : Connect without asking.
	 *
	 * ## EXAMPLES
	 *
	 *     wp keepmerge connect "SGSP1|https://example.com/wp-json/|…" --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function connect( $args, $assoc_args ) {
		$key = isset( $args[0] ) ? (string) $args[0] : '';

		$preview = Source_Pairing::preview( $key );
		if ( is_wp_error( $preview ) ) {
			$this->fail( $preview );
		}

		WP_CLI::line( 'Live site:      ' . ( '' === $preview['live']['name'] ? '-' : $preview['live']['name'] ) );
		WP_CLI::line( 'Address:        ' . $preview['live']['home_url'] . ' (connects to ' . $preview['address'] . ')' );
		WP_CLI::line( 'Live site ID:   ' . $preview['live']['site_id'] );
		WP_CLI::line( 'Versions:       WordPress ' . $preview['versions']['wordpress'] . ', PHP ' . $preview['versions']['php'] . ', Keepmerge ' . $preview['versions']['plugin'] );
		WP_CLI::line( 'Clock skew:     ' . $preview['skew'] . ' s' );
		WP_CLI::line( 'Authorization:  ' . ( $preview['authorization_header_seen'] ? 'header arrives on live' : 'header missing on live' ) );
		WP_CLI::line( 'Pairing:        ' . $preview['pairing'] );

		if ( ! $preview['can_connect'] ) {
			WP_CLI::error( $preview['blocker']['message'] );
		}

		WP_CLI::confirm( 'Connect this site to ' . $preview['live']['home_url'] . '?', $assoc_args );

		$result = Source_Pairing::connect( $key );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result );
		}

		WP_CLI::success( sprintf( 'Connected. Waiting for approval on live: approve pairing %1$s under Tools > Staging Push > Receive on %2$s (or run wp keepmerge pair-approve %1$s there).', $result['uuid'], $result['live']['host'] ) );
	}

	/**
	 * Opens or closes the receive window.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : open or close.
	 * ---
	 * options:
	 *   - open
	 *   - close
	 * ---
	 *
	 * [--hours=<hours>]
	 * : Whole hours from 1 to 24 (open only). The window always closes by itself.
	 * ---
	 * default: 1
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp keepmerge receive open --hours=8
	 *     wp keepmerge receive close
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function receive( $args, $assoc_args ) {
		$action = isset( $args[0] ) ? (string) $args[0] : '';

		if ( 'close' === $action ) {
			$was_open = Receive_Window::close( 'manual' );
			WP_CLI::success( $was_open ? 'Receive window closed.' : 'The receive window was already closed.' );
			return;
		}

		if ( 'open' !== $action ) {
			WP_CLI::error( 'Use "open" or "close".' );
		}

		$hours  = isset( $assoc_args['hours'] ) ? (string) $assoc_args['hours'] : '1';
		$result = Receive_Window::open( $hours, 'cli' );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( sprintf( 'Receive window open until %s UTC.', gmdate( 'Y-m-d H:i:s', $result['closes_at'] ) ) );
	}

	/**
	 * Creates a pairing key and prints it once.
	 *
	 * The key holds bootstrap credentials that work for 30 minutes, for the status and confirm
	 * steps only. Treat it like a password. The receive window must be open.
	 *
	 * ## EXAMPLES
	 *
	 *     wp keepmerge pair-key
	 *
	 * @subcommand pair-key
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (unused).
	 * @return void
	 */
	public function pair_key( $args, $assoc_args ) {
		unset( $args, $assoc_args );

		$author = $this->default_author();
		$result = Pairing_Service::create_key( $author );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result );
		}

		$pairing = $result['pairing'];

		WP_CLI::line( 'Pairing key (shown once; treat it like a password; it expires ' . gmdate( 'Y-m-d H:i:s', (int) $pairing['expires_at'] ) . ' UTC):' );
		WP_CLI::line( $result['key'] );
		WP_CLI::line( 'Pairing:        ' . $pairing['uuid'] );
		WP_CLI::line( 'Default author: ' . ( '' === $pairing['default_author']['name'] ? '-' : $pairing['default_author']['name'] ) );
		WP_CLI::success( 'Paste the key into Tools > Staging Push > Send on the staging site within 30 minutes, then approve the pairing here.' );
	}

	/**
	 * Approves a staging site that finalized pairing and is waiting for approval.
	 *
	 * ## OPTIONS
	 *
	 * <pairing>
	 * : Pairing UUID (see wp keepmerge status).
	 *
	 * [--yes]
	 * : Approve without asking.
	 *
	 * ## EXAMPLES
	 *
	 *     wp keepmerge pair-approve 9f1c2a3b-0000-4000-8000-000000000000 --yes
	 *
	 * @subcommand pair-approve
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function pair_approve( $args, $assoc_args ) {
		$pairing = $this->find( isset( $args[0] ) ? $args[0] : '' );

		WP_CLI::line( 'Source URL:     ' . ( '' === $pairing['source_url'] ? '-' : $pairing['source_url'] ) );
		WP_CLI::line( 'Source address: ' . ( '' === $pairing['source_ip'] ? '-' : $pairing['source_ip'] ) );
		WP_CLI::line( 'Source site ID: ' . ( '' === $pairing['source_site_id'] ? '-' : $pairing['source_site_id'] ) );
		WP_CLI::confirm( 'Approve this staging site? It can then push content to this site while the receive window is open.', $assoc_args );

		$result = Pairing_Service::approve( $pairing['uuid'], get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result );
		}

		WP_CLI::success( 'Pairing approved. Status: ' . $result['status'] . '.' );
	}

	/**
	 * Revokes a pairing. On the live site: deletes its receiver account, Application Passwords
	 * and secrets. On the staging site: forgets the stored credentials (then revoke it on live).
	 *
	 * ## OPTIONS
	 *
	 * <pairing>
	 * : Pairing UUID (see wp keepmerge status).
	 *
	 * [--yes]
	 * : Revoke without asking.
	 *
	 * ## EXAMPLES
	 *
	 *     wp keepmerge revoke 9f1c2a3b-0000-4000-8000-000000000000 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function revoke( $args, $assoc_args ) {
		$source = Source_Pairing::find( isset( $args[0] ) ? (string) $args[0] : '' );
		if ( null !== $source ) {
			if ( 'revoked' === $source['status'] ) {
				WP_CLI::error( 'This pairing is already revoked.' );
			}
			WP_CLI::confirm( sprintf( 'Revoke pairing %s to %s? This site forgets its credentials; revoke it on live too.', $source['pairing_uuid'], $source['remote_url'] ), $assoc_args );
			$result = Source_Pairing::revoke( $source['pairing_uuid'] );
			if ( is_wp_error( $result ) ) {
				$this->fail( $result );
			}
			WP_CLI::success( 'Pairing revoked on this site. ' . $result['next_step'] );
			return;
		}

		$pairing = $this->find( isset( $args[0] ) ? $args[0] : '' );

		WP_CLI::confirm( sprintf( 'Revoke pairing %s (%s)? The staging site will need a new key.', $pairing['uuid'], '' === $pairing['source_url'] ? 'no source yet' : $pairing['source_url'] ), $assoc_args );

		$result = Pairing_Service::revoke( $pairing['uuid'] );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result );
		}

		WP_CLI::success( 'Pairing revoked.' );
	}

	/**
	 * Builds a dry-run plan against the live site and prints it: counts per class, the conflicts
	 * to decide, and every item that differs. Live is only read; nothing is sent for writing.
	 *
	 * ## OPTIONS
	 *
	 * [--pairing=<uuid>]
	 * : Pairing UUID. Default: the first active pairing this site sends through.
	 *
	 * [--types=<groups>]
	 * : Comma-separated scope: content, products, media, terms, menus, site_editor, options.
	 * Default: every included type.
	 *
	 * [--all]
	 * : List unchanged items too.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp keepmerge plan
	 *     wp keepmerge plan --types=content,media --format=json
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function plan( $args, $assoc_args ) {
		unset( $args );

		$uuid = isset( $assoc_args['pairing'] ) ? strtolower( trim( (string) $assoc_args['pairing'] ) ) : '';
		if ( '' === $uuid ) {
			foreach ( Source_Pairing::list_pairings() as $pairing ) {
				if ( 'active' === $pairing['status'] ) {
					$uuid = $pairing['uuid'];
					break;
				}
			}
		}
		if ( '' === $uuid ) {
			WP_CLI::error( 'This site has no active pairing to a live site. See wp keepmerge status.' );
		}

		$groups = array();
		if ( isset( $assoc_args['types'] ) && '' !== trim( (string) $assoc_args['types'] ) ) {
			$groups = array_values( array_filter( array_map( 'trim', explode( ',', strtolower( (string) $assoc_args['types'] ) ) ) ) );
		}

		$plan = Planner::run( $uuid, $groups, get_current_user_id() );
		if ( is_wp_error( $plan ) ) {
			$this->fail( $plan );
		}
		if ( 'planned' !== $plan['state'] ) {
			WP_CLI::error( 'The plan did not finish. Run the command again.' );
		}

		$all  = ! empty( $assoc_args['all'] );
		$rows = array();
		foreach ( Plan_Store::all_items( (int) $plan['id'] ) as $row ) {
			$in_scope = 'out_of_scope' !== $row['action'] || ! empty( $row['payload']['pulled_in'] );
			if ( ! $in_scope || ( ! $all && Classifier::UNCHANGED === $row['status'] ) ) {
				continue;
			}
			$rows[] = $this->plan_line( $row );
		}

		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			WP_CLI::line(
				(string) wp_json_encode(
					array(
						'plan' => $plan,
						'rows' => $rows,
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				)
			);
			return;
		}

		$summary = $plan['summary'];
		WP_CLI::line( sprintf( 'Plan %d (pairing %s), clone date: %s', $plan['id'], $plan['pairing'], $summary['clone']['unknown'] ? 'unknown' : (string) $summary['clone']['date'] ) );
		WP_CLI::line( sprintf( 'Items: %d in scope, %d differ. Conflicts to decide: %d. Blocked: %d. Would send: %d.', $summary['totals']['in_scope'], $summary['totals']['changes'], $summary['undecided'], $summary['blocked'], $summary['sent'] ) );

		$counts = array();
		foreach ( $summary['by_class'] as $class => $count ) {
			if ( $count > 0 ) {
				$counts[] = array(
					'class' => $class,
					'items' => $count,
				);
			}
		}
		WP_CLI\Utils\format_items( 'table', $counts, array( 'class', 'items' ) );

		$dialog = $summary['dialog'];
		WP_CLI::line( sprintf( 'A push would: create %d, update %d, trash %d, recreate %d, record base %d; keep live %d; skip %d. Held for review on live (predicted): %d. Decided by dates: %d.', $dialog['create'], $dialog['update'], $dialog['trash'], $dialog['recreate'], $dialog['record_base'], $dialog['keep_live'], $dialog['skip'], $dialog['held'], $dialog['by_dates'] ) );
		foreach ( $summary['warnings'] as $code => $warning ) {
			WP_CLI::line( sprintf( 'Warning %s: %d item(s)', $code, $warning['count'] ) );
		}
		if ( ! empty( $summary['missing_themes'] ) ) {
			WP_CLI::line( 'Themes missing on live: ' . implode( ', ', $summary['missing_themes'] ) );
		}
		foreach ( $summary['missing_blocks'] as $block ) {
			WP_CLI::line( sprintf( 'Block type not registered on live: %s (%d item(s))', $block['name'], $block['items'] ) );
		}
		WP_CLI::line( 'Code report: ' . $summary['code']['state'] );

		if ( empty( $rows ) ) {
			WP_CLI::success( 'Nothing differs.' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'item', 'kind', 'type', 'local_id', 'live_id', 'class', 'action', 'groups', 'badges', 'warnings' ) );
	}

	/**
	 * One plan row for the table.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private function plan_line( array $row ) {
		$p      = $row['payload'];
		$groups = array();
		foreach ( isset( $p['groups'] ) && is_array( $p['groups'] ) ? $p['groups'] : array() as $name => $group ) {
			$action   = ! empty( $group['decision'] ) ? $group['decision'] : ( '' === $group['action'] ? 'decide' : $group['action'] );
			$groups[] = $name . ':' . $action;
		}
		$warnings = array();
		foreach ( isset( $p['warnings'] ) ? (array) $p['warnings'] : array() as $warning ) {
			$warnings[] = $warning['code'];
		}
		$effective = Planner::effective( $row );

		return array(
			'item'     => wp_strip_all_tags( isset( $p['title'] ) ? (string) $p['title'] : '' ),
			'uuid'     => (string) $row['uuid'],
			'kind'     => (string) $row['object_type'],
			'type'     => isset( $p['type'] ) ? (string) $p['type'] : '',
			'local_id' => (int) $row['local_object_id'],
			'live_id'  => isset( $p['live_id'] ) ? (int) $p['live_id'] : 0,
			'class'    => (string) $row['status'],
			'action'   => '' === $effective ? 'DECIDE' : $effective,
			'groups'   => implode( ' ', $groups ),
			'badges'   => implode( ',', isset( $p['badges'] ) ? (array) $p['badges'] : array() ),
			'warnings' => implode( ',', $warnings ),
		);
	}

	/**
	 * A destination pairing by UUID, or exit with an error.
	 *
	 * @param string $uuid UUID.
	 * @return array
	 */
	private function find( $uuid ) {
		$uuid = strtolower( trim( (string) $uuid ) );
		foreach ( Pairing_Service::list_destination() as $pairing ) {
			if ( $pairing['uuid'] === $uuid ) {
				return $pairing;
			}
		}
		WP_CLI::error( 'No such pairing on this site. See wp keepmerge status.' );
		return array();
	}

	/**
	 * The default author for a key made from the shell: the current user when it can manage
	 * the site (--user), else the administrator with the lowest ID.
	 *
	 * @return int
	 */
	private function default_author() {
		if ( current_user_can( 'manage_options' ) ) {
			return get_current_user_id();
		}

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'fields'  => 'ID',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
			)
		);

		return empty( $admins ) ? 0 : (int) $admins[0];
	}

	/**
	 * Exits with the error's message.
	 *
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private function fail( WP_Error $error ) {
		WP_CLI::error( $error->get_error_message() );
	}
}
