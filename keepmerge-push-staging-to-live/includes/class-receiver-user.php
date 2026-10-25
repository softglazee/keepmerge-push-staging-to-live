<?php
/**
 * The receiver user and its lock-down.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

use WP_Application_Passwords;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The only user the plugin creates (blueprint §5.8, threat model §7.4).
 *
 * Core Application Passwords belong to a user, so each pairing gets its own receiver: role
 * softglaze_sp_receiver with the single capability softglaze_sp_receive_push (no read), a random
 * 64-character password that is never shown, no email address. It is created only by a live
 * administrator's own action and deleted on revoke, reject, expiry and uninstall.
 *
 * Lock-down, all limited to receivers and without effect on any other user:
 * - interactive login refused (wp_authenticate_user, which core runs before the password check);
 * - XML-RPC refused (authenticate at priority 30, after core's Application Password check at 20,
 *   because core does not apply wp_authenticate_user to Application Password logins);
 * - REST limited to /keepmerge/v1/remote/ by an allowlist in rest_pre_dispatch that fails closed
 *   (core applies the filter to /batch/v1 and to each request inside a batch);
 * - the role is hidden from role dropdowns (editable_roles).
 */
final class Receiver_User {

	const LOGIN_PREFIX = 'softglaze-sp-';

	const LOGIN_PATTERN = '/\Asoftglaze-sp-[0-9a-f]{8}\z/';

	/**
	 * Routes a receiver may call: our remote namespace, lowercase, no dots, no empty segments,
	 * no trailing slash. Core matches routes case-insensitively, so any other spelling of our own
	 * route is refused here rather than let through.
	 */
	const ROUTE_ALLOWLIST = '#\A/keepmerge/v1/remote/[a-z0-9_-]+(?:/[a-z0-9_-]+)*\z#';

	const DISPLAY_NAME = 'Keepmerge receiver';

	/**
	 * Receiver user IDs recorded in the pairings table, loaded once per request.
	 *
	 * @var int[]|null
	 */
	private static $recorded = null;

	/**
	 * Registers the lock-down hooks.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'refuse_interactive_login' ), 10, 2 );
		add_filter( 'authenticate', array( __CLASS__, 'refuse_xmlrpc' ), 30, 3 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'limit_rest_scope' ), 10, 3 );
		add_filter( 'editable_roles', array( __CLASS__, 'hide_role' ) );
	}

	/**
	 * Creates a receiver.
	 *
	 * @return WP_User|WP_Error
	 */
	public static function create() {
		Installer::ensure_role();

		$login = '';
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$candidate = self::LOGIN_PREFIX . bin2hex( random_bytes( 4 ) );
			if ( ! username_exists( $candidate ) ) {
				$login = $candidate;
				break;
			}
		}
		if ( '' === $login ) {
			return new WP_Error( 'receiver_not_created', __( 'The receiver account could not be created. Try again.', 'keepmerge-push-staging-to-live' ) );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'           => $login,
				'user_pass'            => wp_generate_password( 64, true, true ),
				'user_email'           => '',
				'display_name'         => self::DISPLAY_NAME,
				'nickname'             => self::DISPLAY_NAME,
				'role'                 => Installer::ROLE,
				'show_admin_bar_front' => 'false',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'receiver_not_created', __( 'The receiver account could not be created. Try again.', 'keepmerge-push-staging-to-live' ) );
		}

		self::$recorded = null;

		$user = get_userdata( $user_id );

		return $user instanceof WP_User ? $user : new WP_Error( 'receiver_not_created', __( 'The receiver account could not be created. Try again.', 'keepmerge-push-staging-to-live' ) );
	}

	/**
	 * Deletes a receiver, its Application Passwords first.
	 *
	 * Refuses any user that is not a receiver. A receiver owns nothing, but the deletion still
	 * names a user to reassign to, so content can never be deleted along with it.
	 *
	 * @param int $user_id   Receiver user ID.
	 * @param int $reassign  Preferred user to reassign to (the pairing's default author).
	 * @return bool Whether a user was deleted.
	 */
	public static function delete( $user_id, $reassign = 0 ) {
		$user_id = absint( $user_id );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof WP_User || ! self::is_receiver( $user ) ) {
			return false;
		}

		WP_Application_Passwords::delete_all_application_passwords( $user_id );

		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$target  = self::reassign_target( $reassign, $user_id );
		$deleted = wp_delete_user( $user_id, $target > 0 ? $target : null );

		self::$recorded = null;

		return (bool) $deleted;
	}

	/**
	 * Deletes receivers that belong to no pending, awaiting or active pairing (activation).
	 *
	 * @return int Users deleted.
	 */
	public static function delete_orphans() {
		$keep    = Pairing_Store::receiver_ids( array( 'pending', 'awaiting_approval', 'active' ) );
		$deleted = 0;

		$users = get_users(
			array(
				'role'   => Installer::ROLE,
				'fields' => 'ID',
				'number' => 500,
			)
		);

		foreach ( $users as $id ) {
			if ( ! in_array( (int) $id, $keep, true ) && self::delete( (int) $id ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Whether a user is a receiver: it has the role, or its login has the receiver form and a
	 * pairing records it (so a role changed by hand does not lift the lock-down).
	 *
	 * @param WP_User|int $user User or ID.
	 * @return bool
	 */
	public static function is_receiver( $user ) {
		if ( ! $user instanceof WP_User ) {
			$user = get_userdata( absint( $user ) );
		}
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}

		if ( in_array( Installer::ROLE, (array) $user->roles, true ) ) {
			return true;
		}

		if ( 1 !== preg_match( self::LOGIN_PATTERN, (string) $user->user_login ) ) {
			return false;
		}

		if ( null === self::$recorded ) {
			self::$recorded = Pairing_Store::receiver_ids();
		}

		return in_array( (int) $user->ID, self::$recorded, true );
	}

	/**
	 * Whether a REST route is one a receiver may call.
	 *
	 * @param mixed $route Route from WP_REST_Request::get_route().
	 * @return bool
	 */
	public static function route_allowed( $route ) {
		return is_string( $route ) && 1 === preg_match( self::ROUTE_ALLOWLIST, $route );
	}

	/**
	 * Hook: wp_authenticate_user. Refuses receivers before the password is checked, so the
	 * answer does not depend on the password.
	 *
	 * @param WP_User|WP_Error $user     User found by login or email.
	 * @param string           $password Password given (unused).
	 * @return WP_User|WP_Error
	 */
	public static function refuse_interactive_login( $user, $password ) {
		unset( $password );

		if ( $user instanceof WP_User && self::is_receiver( $user ) ) {
			// An XML-RPC login passes here too; refuse_xmlrpc() audits that one.
			if ( ! ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
				self::audit_refusal( 'interactive_login_refused', $user );
			}
			return new WP_Error( 'softglaze_sp_receiver_login', __( '<strong>Error:</strong> This account cannot sign in.', 'keepmerge-push-staging-to-live' ) );
		}

		return $user;
	}

	/**
	 * Hook: authenticate, priority 30. Under XML-RPC, refuses a receiver that core's Application
	 * Password check (priority 20) accepted.
	 *
	 * @param WP_User|WP_Error|null $user     Result so far.
	 * @param string                $username Login (unused).
	 * @param string                $password Password (unused).
	 * @return WP_User|WP_Error|null
	 */
	public static function refuse_xmlrpc( $user, $username, $password ) {
		unset( $username, $password );

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST && $user instanceof WP_User && self::is_receiver( $user ) ) {
			self::audit_refusal( 'xmlrpc_refused', $user );
			return new WP_Error( 'softglaze_sp_receiver_xmlrpc', __( '<strong>Error:</strong> This account cannot use XML-RPC.', 'keepmerge-push-staging-to-live' ) );
		}

		return $user;
	}

	/**
	 * Hook: rest_pre_dispatch. A receiver may reach our remote routes only; everything else,
	 * including /batch/v1 and any odd spelling of our own routes, gets 403.
	 *
	 * @param mixed           $result  Result so far.
	 * @param mixed           $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function limit_rest_scope( $result, $server, $request ) {
		unset( $server );

		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! self::is_receiver( $user_id ) ) {
			return $result;
		}

		if ( $request instanceof WP_REST_Request && self::route_allowed( $request->get_route() ) ) {
			return $result;
		}

		$ip = Throttle::client_ip();
		if ( '' !== $ip ) {
			Throttle::record_failure( $ip );
		}
		self::audit_refusal( 'receiver_scope_refused', get_userdata( $user_id ) );

		return new WP_REST_Response( array( 'code' => 'receiver_scope_refused' ), 403 );
	}

	/**
	 * Hook: editable_roles. The receiver role cannot be given to anyone from the user screens.
	 *
	 * @param array $roles Roles.
	 * @return array
	 */
	public static function hide_role( $roles ) {
		if ( is_array( $roles ) ) {
			unset( $roles[ Installer::ROLE ] );
		}
		return $roles;
	}

	/**
	 * Clears the per-request cache of recorded receivers (after pairing changes).
	 *
	 * @return void
	 */
	public static function flush_cache() {
		self::$recorded = null;
	}

	/**
	 * Collapsed audit row for a refused receiver action.
	 *
	 * @param string       $reason Reason code.
	 * @param WP_User|bool $user   Receiver.
	 * @return void
	 */
	private static function audit_refusal( $reason, $user ) {
		Audit_Log::write(
			'auth_failed',
			array(
				'result'      => 'denied',
				'actor_type'  => 'remote',
				'actor_id'    => 0,
				'object_type' => 'user',
				'object_ref'  => $user instanceof WP_User ? $user->user_login : '',
				'details'     => array( 'reason' => $reason ),
			)
		);
	}

	/**
	 * A user to reassign a deleted receiver's (non-existent) content to.
	 *
	 * @param int $preferred Preferred user ID.
	 * @param int $receiver  The receiver being deleted.
	 * @return int User ID, 0 when there is none.
	 */
	private static function reassign_target( $preferred, $receiver ) {
		$preferred = absint( $preferred );
		if ( $preferred > 0 && $preferred !== $receiver && get_userdata( $preferred ) && ! self::is_receiver( $preferred ) ) {
			return $preferred;
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
}
