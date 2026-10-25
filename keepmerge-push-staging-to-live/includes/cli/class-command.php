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
use WP_CLI;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pairs this site and controls the receive window from the command line.
 *
 * Shell access implies full trust on this site; every change is still audited with the actor
 * "cli", and destructive commands ask first unless --yes is given.
 *
 * TODO(Phase 2B): connect. TODO(Phase 4–8): plan, push, pushes, rollback, audit.
 */
final class Command {

	/**
	 * Shows this site's ID, the receive window and the pairings that receive on this site.
	 *
	 * Expired keys and approvals are removed first, as on the Receive tab.
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
			return;
		}

		WP_CLI::line( 'Pairings (receiving):' );
		WP_CLI\Utils\format_items( 'table', $rows, array( 'pairing', 'status', 'source', 'source_ip', 'site_id', 'expires', 'last_used' ) );
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
	 * Revokes a pairing: deletes its receiver account, Application Passwords and secrets.
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
		$pairing = $this->find( isset( $args[0] ) ? $args[0] : '' );

		WP_CLI::confirm( sprintf( 'Revoke pairing %s (%s)? The staging site will need a new key.', $pairing['uuid'], '' === $pairing['source_url'] ? 'no source yet' : $pairing['source_url'] ), $assoc_args );

		$result = Pairing_Service::revoke( $pairing['uuid'] );
		if ( is_wp_error( $result ) ) {
			$this->fail( $result );
		}

		WP_CLI::success( 'Pairing revoked.' );
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
