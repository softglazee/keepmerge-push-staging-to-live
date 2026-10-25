<?php
/**
 * Pairing on the destination.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

use WP_Application_Passwords;
use WP_Error;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The destination side of pairing (blueprint §5.3, §5.9).
 *
 * 1. A live administrator creates a key: receiver user, bootstrap Application Password,
 *    bootstrap secret, pairing "pending" for 30 minutes. The key is returned once.
 * 2. The source confirms with the bootstrap credentials: live issues the long-term Application
 *    Password and secret (a repeated confirm replaces the unfinalized pair).
 * 3. The source finalizes with the long-term credentials: the bootstrap credentials are deleted,
 *    the pairing waits 24 hours in "awaiting_approval".
 * 4. A live administrator approves (active) or rejects (revoked).
 *
 * The source side (connect, stored credentials, source-role deactivation) is Source_Pairing.
 */
final class Pairing_Service {

	const ROLE = 'destination';

	const BOOTSTRAP_TTL = 1800;

	const APPROVAL_TTL = DAY_IN_SECONDS;

	/**
	 * Whether this site is served over HTTPS for the purpose at hand.
	 *
	 * In a web request that is is_ssl() plus an https REST root (the key carries it). WP-CLI
	 * runs without a request, so is_ssl() is always false there; the CLI requires https in both
	 * home_url() and rest_url(), because every request that uses the key arrives through them.
	 *
	 * @return bool
	 */
	public static function site_is_https() {
		$rest_https = 'https' === strtolower( (string) wp_parse_url( rest_url(), PHP_URL_SCHEME ) );

		if ( self::is_cli() ) {
			return $rest_https && 'https' === strtolower( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) );
		}

		return is_ssl() && $rest_https;
	}

	/**
	 * Whether core makes Application Passwords available on this site.
	 *
	 * Web: core's own wp_is_application_passwords_available(). WP-CLI: core's answer depends on
	 * is_ssl(), which is false without a request, so the CLI applies core's filter to the value
	 * core starts from for an HTTPS request (true), exactly as core would for the requests that
	 * will use the credentials. A plugin that turns Application Passwords off therefore still
	 * blocks pairing from the CLI.
	 *
	 * @return bool
	 */
	public static function app_passwords_available() {
		if ( ! self::is_cli() ) {
			return wp_is_application_passwords_available();
		}

		if ( ! self::site_is_https() ) {
			return false;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, applied with the value core uses for an HTTPS request (WP-CLI has none).
		return (bool) apply_filters( 'wp_is_application_passwords_available', true );
	}

	/**
	 * Whether core makes Application Passwords available for one user.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function app_passwords_available_for( WP_User $user ) {
		if ( ! self::is_cli() ) {
			return wp_is_application_passwords_available_for_user( $user );
		}

		if ( ! self::app_passwords_available() || ! $user->exists() ) {
			return false;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, applied as core applies it (see app_passwords_available()).
		return (bool) apply_filters( 'wp_is_application_passwords_available_for_user', true, $user );
	}

	/**
	 * Why a pairing key cannot be created right now.
	 *
	 * @return WP_Error|null Null when a key can be created.
	 */
	public static function key_blocker() {
		if ( is_multisite() ) {
			return new WP_Error( 'not_supported', __( 'Receiving pushes on a multisite network is not supported in this version.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}
		if ( ! Receive_Window::is_open() ) {
			return new WP_Error( 'window_closed', __( 'Open the receive window first. The staging site can use the key only while the window is open.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		if ( ! self::site_is_https() ) {
			return new WP_Error( 'https_required', __( 'This site must use HTTPS (WordPress Address, Site Address and this screen) before it can be paired.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}
		if ( ! self::app_passwords_available() ) {
			return new WP_Error( 'app_passwords_unavailable', __( 'Application Passwords are turned off on this site, often by a security plugin. Pairing needs them.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}
		if ( ! Crypto::available() ) {
			return new WP_Error( 'sodium_unavailable', __( 'Credentials cannot be stored encrypted on this server (no sodium library).', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}
		return null;
	}

	/**
	 * Creates a pairing key (step 1). The key is returned once and stored nowhere.
	 *
	 * @param int $author_id Default author for pushed content whose author does not exist on live.
	 * @return array{key:string,pairing:array}|WP_Error
	 */
	public static function create_key( $author_id ) {
		$blocker = self::key_blocker();
		if ( null !== $blocker ) {
			return $blocker;
		}

		$user = Receiver_User::create();
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		if ( ! self::app_passwords_available_for( $user ) ) {
			Receiver_User::delete( $user->ID, $author_id );
			return new WP_Error(
				'app_passwords_unavailable_for_user',
				__( 'Application Passwords are not available for the receiver account on this site (a plugin limits them to some users). The receiver was removed again; allow Application Passwords for it and create a new key.', 'keepmerge-push-staging-to-live' ),
				array( 'status' => 400 )
			);
		}

		$created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'Keepmerge bootstrap ' . bin2hex( random_bytes( 4 ) ) ) );
		$secret  = Signer::new_secret();
		$enc     = Crypto::encrypt( $secret );

		if ( is_wp_error( $created ) || '' === $enc ) {
			Receiver_User::delete( $user->ID, $author_id );
			return new WP_Error( 'credentials_not_created', __( 'The pairing credentials could not be created. Try again.', 'keepmerge-push-staging-to-live' ), array( 'status' => 500 ) );
		}

		list( $password, $item ) = $created;

		$uuid    = wp_generate_uuid4();
		$now     = time();
		$expires = $now + self::BOOTSTRAP_TTL;

		$id = Pairing_Store::insert(
			array(
				'pairing_uuid'            => $uuid,
				'role'                    => self::ROLE,
				'status'                  => 'pending',
				'receiver_user_id'        => $user->ID,
				'bootstrap_password_uuid' => $item['uuid'],
				'bootstrap_secret_enc'    => $enc,
				'local_home_url'          => Installer::normalised_home(),
				'local_home_hash'         => Installer::home_hash(),
				'default_author_id'       => absint( $author_id ),
				'expires_gmt'             => gmdate( 'Y-m-d H:i:s', $expires ),
				'created_gmt'             => gmdate( 'Y-m-d H:i:s', $now ),
			)
		);

		$key = Pairing_Key::build( rest_url(), $uuid, $user->user_login, $password, $secret, $expires );

		if ( $id < 1 || is_wp_error( $key ) ) {
			Receiver_User::delete( $user->ID, $author_id );
			if ( $id > 0 ) {
				Pairing_Store::delete( $id );
			}
			return new WP_Error( 'credentials_not_created', __( 'The pairing credentials could not be created. Try again.', 'keepmerge-push-staging-to-live' ), array( 'status' => 500 ) );
		}

		Receiver_User::flush_cache();

		Audit_Log::write(
			'pairing_key_created',
			array(
				'pairing_id'  => $id,
				'object_type' => 'pairing',
				'object_ref'  => $uuid,
				'details'     => array(
					'receiver'   => $user->user_login,
					'expires_at' => gmdate( 'Y-m-d H:i:s', $expires ),
				),
			)
		);

		return array(
			'key'     => $key,
			'pairing' => self::present( Pairing_Store::find( $uuid ) ),
		);
	}

	/**
	 * Confirm (step 3): issues the long-term Application Password and secret. A repeated confirm
	 * (the first response was lost) deletes the unfinalized pair and issues a new one.
	 *
	 * @param array $pairing Pairing row (state checked by the permission chain).
	 * @param array $fields  Validated body fields: site_id, home_url, wp_version, plugin_version, php_version.
	 * @return array|WP_Error Response data carrying the new credentials once.
	 */
	public static function confirm( array $pairing, array $fields ) {
		$user_id  = (int) $pairing['receiver_user_id'];
		$repeated = '' !== (string) $pairing['app_password_uuid'];

		if ( $repeated ) {
			WP_Application_Passwords::delete_application_password( $user_id, (string) $pairing['app_password_uuid'] );
		}

		$created = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'Keepmerge ' . bin2hex( random_bytes( 4 ) ) ) );
		$secret  = Signer::new_secret();
		$enc     = Crypto::encrypt( $secret );

		if ( is_wp_error( $created ) || '' === $enc ) {
			if ( ! is_wp_error( $created ) ) {
				WP_Application_Passwords::delete_application_password( $user_id, $created[1]['uuid'] );
			}
			Pairing_Store::update(
				(int) $pairing['id'],
				array(
					'app_password_uuid' => '',
					'secret_enc'        => null,
				)
			);
			return new WP_Error( 'credentials_not_created', 'credentials_not_created', array( 'status' => 500 ) );
		}

		list( $password, $item ) = $created;

		Pairing_Store::update(
			(int) $pairing['id'],
			array(
				'app_password_uuid' => $item['uuid'],
				'secret_enc'        => $enc,
				'source_site_id'    => $fields['site_id'],
				'remote_url'        => $fields['home_url'],
			)
		);

		Audit_Log::write(
			'pairing_confirmed',
			array(
				'actor_type'  => 'remote',
				'actor_id'    => 0,
				'pairing_id'  => (int) $pairing['id'],
				'object_type' => 'pairing',
				'object_ref'  => $pairing['pairing_uuid'],
				'details'     => array(
					'stage'          => 'credentials_issued',
					'repeated'       => $repeated,
					'source_url'     => $fields['home_url'],
					'source_site_id' => $fields['site_id'],
				),
			)
		);

		$user = get_userdata( $user_id );

		return array(
			'pairing'        => $pairing['pairing_uuid'],
			'receiver_login' => $user instanceof WP_User ? $user->user_login : '',
			'app_password'   => $password,
			'secret'         => $secret,
			'site'           => array(
				'site_id'  => Installer::site_id(),
				'home_url' => home_url( '/' ),
			),
			'finalize_by'    => self::timestamp( $pairing['expires_gmt'] ),
		);
	}

	/**
	 * Finalize (step 4): deletes the bootstrap credentials and waits for a live administrator.
	 *
	 * @param array  $pairing Pairing row.
	 * @param string $ip      Address the request came from.
	 * @return array
	 */
	public static function finalize( array $pairing, $ip ) {
		$user_id = (int) $pairing['receiver_user_id'];
		if ( '' !== (string) $pairing['bootstrap_password_uuid'] ) {
			WP_Application_Passwords::delete_application_password( $user_id, (string) $pairing['bootstrap_password_uuid'] );
		}

		$now     = time();
		$approve = $now + self::APPROVAL_TTL;

		Pairing_Store::update(
			(int) $pairing['id'],
			array(
				'bootstrap_password_uuid' => '',
				'bootstrap_secret_enc'    => null,
				'status'                  => 'awaiting_approval',
				'confirmed_gmt'           => gmdate( 'Y-m-d H:i:s', $now ),
				'expires_gmt'             => gmdate( 'Y-m-d H:i:s', $approve ),
				'source_ip'               => (string) filter_var( $ip, FILTER_VALIDATE_IP ),
			)
		);

		Audit_Log::write(
			'pairing_confirmed',
			array(
				'actor_type'  => 'remote',
				'actor_id'    => 0,
				'pairing_id'  => (int) $pairing['id'],
				'object_type' => 'pairing',
				'object_ref'  => $pairing['pairing_uuid'],
				'details'     => array(
					'stage'          => 'finalized',
					'source_url'     => (string) $pairing['remote_url'],
					'source_site_id' => (string) $pairing['source_site_id'],
				),
			)
		);

		return array(
			'status'     => 'awaiting_approval',
			'approve_by' => $approve,
		);
	}

	/**
	 * Approve (step 5): the pairing becomes active.
	 *
	 * @param string $uuid    Pairing UUID.
	 * @param int    $user_id Approving administrator (0 from WP-CLI without --user).
	 * @return array|WP_Error Pairing summary.
	 */
	public static function approve( $uuid, $user_id ) {
		$row = self::destination_row( $uuid );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( 'awaiting_approval' !== $row['status'] ) {
			return new WP_Error( 'not_awaiting_approval', __( 'This pairing is not waiting for approval.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}
		if ( self::is_expired( $row ) ) {
			return new WP_Error( 'pairing_expired', __( 'This pairing waited more than 24 hours and has expired. Create a new pairing key.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		Pairing_Store::update(
			(int) $row['id'],
			array(
				'status'       => 'active',
				'approved_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'approved_by'  => absint( $user_id ),
				'expires_gmt'  => null,
			)
		);

		Audit_Log::write(
			'pairing_activated',
			array(
				'pairing_id'  => (int) $row['id'],
				'object_type' => 'pairing',
				'object_ref'  => $row['pairing_uuid'],
				'details'     => array(
					'source_url'     => (string) $row['remote_url'],
					'source_ip'      => (string) $row['source_ip'],
					'source_site_id' => (string) $row['source_site_id'],
					'approved_by'    => absint( $user_id ),
				),
			)
		);

		return self::present( Pairing_Store::find( $uuid ) );
	}

	/**
	 * Reject: revokes a pairing waiting for approval (or still pending).
	 *
	 * @param string $uuid Pairing UUID.
	 * @return array|WP_Error
	 */
	public static function reject( $uuid ) {
		$row = self::destination_row( $uuid );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( ! in_array( $row['status'], array( 'pending', 'awaiting_approval' ), true ) ) {
			return new WP_Error( 'not_awaiting_approval', __( 'This pairing is not waiting for approval.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		return self::revoke_row( $row, 'pairing_rejected' );
	}

	/**
	 * Revoke: deletes the receiver, its Application Passwords and the secrets; the row stays as
	 * "revoked" for the audit trail.
	 *
	 * @param string $uuid Pairing UUID.
	 * @return array|WP_Error
	 */
	public static function revoke( $uuid ) {
		$row = self::destination_row( $uuid );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( 'revoked' === $row['status'] ) {
			return new WP_Error( 'already_revoked', __( 'This pairing is already revoked.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		return self::revoke_row( $row, 'pairing_revoked' );
	}

	/**
	 * Default author and IP pin.
	 *
	 * @param string      $uuid      Pairing UUID.
	 * @param int|null    $author_id New default author, or null to keep.
	 * @param string|null $pinned_ip New IP pin ('' removes it), or null to keep.
	 * @return array|WP_Error
	 */
	public static function update( $uuid, $author_id, $pinned_ip ) {
		$row = self::destination_row( $uuid );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( 'revoked' === $row['status'] ) {
			return new WP_Error( 'already_revoked', __( 'This pairing is already revoked.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		$data = array();

		if ( null !== $author_id ) {
			$author = get_userdata( absint( $author_id ) );
			if ( ! $author instanceof WP_User || Receiver_User::is_receiver( $author ) || ! user_can( $author, 'edit_posts' ) ) {
				return new WP_Error( 'invalid_author', __( 'Choose a user who can write posts on this site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
			}
			$data['default_author_id'] = (int) $author->ID;
		}

		if ( null !== $pinned_ip ) {
			$pinned_ip = trim( (string) $pinned_ip );
			if ( '' !== $pinned_ip ) {
				$valid = filter_var( $pinned_ip, FILTER_VALIDATE_IP );
				if ( ! is_string( $valid ) ) {
					return new WP_Error( 'invalid_ip', __( 'Enter a single IPv4 or IPv6 address, or leave the field empty.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
				}
				$pinned_ip = Throttle::bucket( $valid );
				$pinned_ip = substr( $pinned_ip, 3 );
			}
			$data['pinned_ip'] = $pinned_ip;
		}

		if ( empty( $data ) ) {
			return self::present( $row );
		}

		Pairing_Store::update( (int) $row['id'], $data );

		Audit_Log::write(
			'settings_changed',
			array(
				'pairing_id'  => (int) $row['id'],
				'object_type' => 'pairing',
				'object_ref'  => $row['pairing_uuid'],
				'details'     => array( 'keys' => array_keys( $data ) ),
			)
		);

		return self::present( Pairing_Store::find( $uuid ) );
	}

	/**
	 * Removes pending pairings older than 30 minutes and pairings that waited 24 hours for
	 * approval, with their receivers and Application Passwords (blueprint §5.3 step 6).
	 *
	 * @return int Pairings removed.
	 */
	public static function cleanup_expired() {
		$removed = 0;

		foreach ( Pairing_Store::by_role( self::ROLE, array( 'pending', 'awaiting_approval' ), 1000 ) as $row ) {
			if ( ! self::is_expired( $row ) ) {
				continue;
			}

			Receiver_User::delete( (int) $row['receiver_user_id'], (int) $row['default_author_id'] );
			Nonce_Store::forget_pairing( (int) $row['id'] );
			Pairing_Store::delete( (int) $row['id'] );
			++$removed;

			Audit_Log::write(
				'pairing_expired',
				array(
					'actor_type'  => 'system',
					'pairing_id'  => (int) $row['id'],
					'object_type' => 'pairing',
					'object_ref'  => $row['pairing_uuid'],
					'details'     => array( 'stage' => $row['status'] ),
				)
			);
		}

		Receiver_User::flush_cache();

		return $removed;
	}

	/**
	 * Deactivation: every receiver Application Password and secret is deleted and the pairings
	 * need a new key (blueprint §5.9).
	 *
	 * @return int Pairings changed.
	 */
	public static function revoke_credentials_for_deactivation() {
		$changed = 0;
		foreach ( Pairing_Store::by_role( self::ROLE, array( 'pending', 'awaiting_approval', 'active' ), 1000 ) as $row ) {
			self::to_repair_needed( $row, 'deactivated' );
			++$changed;
		}
		return $changed;
	}

	/**
	 * Clone detection (threat model T21): pairings recorded for another home URL lose their
	 * receiver Application Passwords and secrets and need a new key.
	 *
	 * @return int Pairings changed.
	 */
	public static function handle_clone() {
		$hash    = Installer::home_hash();
		$changed = 0;

		foreach ( Pairing_Store::not_revoked() as $row ) {
			if ( hash_equals( $hash, (string) $row['local_home_hash'] ) ) {
				continue;
			}
			self::to_repair_needed( $row, 'clone_detected' );
			++$changed;
		}

		return $changed;
	}

	/**
	 * Destination pairings for the Receive tab, without credential columns.
	 *
	 * @return array[]
	 */
	public static function list_destination() {
		$out = array();
		foreach ( Pairing_Store::by_role( self::ROLE, array( 'pending', 'awaiting_approval', 'active', 'repair_needed' ), 200 ) as $row ) {
			$out[] = self::present( $row );
		}
		return $out;
	}

	/**
	 * A pairing as screens and REST responses show it: no credentials, times as Unix seconds.
	 *
	 * @param array|null $row Row.
	 * @return array|null
	 */
	public static function present( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$receiver = get_userdata( (int) $row['receiver_user_id'] );
		$author   = get_userdata( (int) $row['default_author_id'] );
		$approver = get_userdata( (int) $row['approved_by'] );
		$host     = wp_parse_url( (string) $row['remote_url'], PHP_URL_HOST );

		return array(
			'uuid'           => (string) $row['pairing_uuid'],
			'status'         => (string) $row['status'],
			'source_url'     => (string) $row['remote_url'],
			'source_host'    => is_string( $host ) ? $host : '',
			'source_ip'      => (string) $row['source_ip'],
			'source_site_id' => (string) $row['source_site_id'],
			'receiver_login' => $receiver instanceof WP_User ? $receiver->user_login : '',
			'default_author' => array(
				'id'   => (int) $row['default_author_id'],
				'name' => $author instanceof WP_User ? $author->display_name : '',
			),
			'pinned_ip'      => (string) $row['pinned_ip'],
			'created_at'     => self::timestamp( $row['created_gmt'] ),
			'expires_at'     => self::timestamp( $row['expires_gmt'] ),
			'confirmed_at'   => self::timestamp( $row['confirmed_gmt'] ),
			'approved_at'    => self::timestamp( $row['approved_gmt'] ),
			'approved_by'    => $approver instanceof WP_User ? $approver->display_name : '',
			'last_used_at'   => self::timestamp( $row['last_used_gmt'] ),
		);
	}

	/**
	 * Whether a pending or awaiting pairing has passed its expiry.
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	public static function is_expired( array $row ) {
		if ( ! in_array( $row['status'], array( 'pending', 'awaiting_approval' ), true ) ) {
			return false;
		}
		$expires = self::timestamp( $row['expires_gmt'] );
		return null === $expires || $expires <= time();
	}

	/**
	 * A GMT datetime column as Unix seconds.
	 *
	 * @param mixed $value Column value.
	 * @return int|null
	 */
	public static function timestamp( $value ) {
		if ( ! is_string( $value ) || '' === $value || '0000-00-00 00:00:00' === $value ) {
			return null;
		}
		$ts = strtotime( $value . ' UTC' );
		return false === $ts ? null : $ts;
	}

	/**
	 * A destination row by UUID, or a 404 error.
	 *
	 * @param mixed $uuid Pairing UUID.
	 * @return array|WP_Error
	 */
	private static function destination_row( $uuid ) {
		$row = Pairing_Store::find( is_string( $uuid ) ? strtolower( $uuid ) : '' );
		if ( null === $row || self::ROLE !== $row['role'] ) {
			return new WP_Error( 'pairing_not_found', __( 'No such pairing on this site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
		}
		return $row;
	}

	/**
	 * Deletes everything a pairing's credentials consist of and marks it revoked.
	 *
	 * @param array  $row   Row.
	 * @param string $event pairing_revoked or pairing_rejected.
	 * @return array
	 */
	private static function revoke_row( array $row, $event ) {
		Receiver_User::delete( (int) $row['receiver_user_id'], (int) $row['default_author_id'] );
		Nonce_Store::forget_pairing( (int) $row['id'] );

		Pairing_Store::update(
			(int) $row['id'],
			array(
				'status'                  => 'revoked',
				'revoked_gmt'             => gmdate( 'Y-m-d H:i:s' ),
				'expires_gmt'             => null,
				'app_password_uuid'       => '',
				'app_password_enc'        => null,
				'secret_enc'              => null,
				'bootstrap_password_uuid' => '',
				'bootstrap_secret_enc'    => null,
			)
		);
		Receiver_User::flush_cache();

		Audit_Log::write(
			$event,
			array(
				'pairing_id'  => (int) $row['id'],
				'object_type' => 'pairing',
				'object_ref'  => $row['pairing_uuid'],
				'details'     => array(
					'previous_status' => $row['status'],
					'source_url'      => (string) $row['remote_url'],
				),
			)
		);

		return self::present( Pairing_Store::find( $row['pairing_uuid'] ) );
	}

	/**
	 * Deletes a pairing's credentials and marks it repair_needed.
	 *
	 * @param array  $row    Row.
	 * @param string $reason deactivated or clone_detected.
	 * @return void
	 */
	private static function to_repair_needed( array $row, $reason ) {
		if ( self::ROLE === $row['role'] && (int) $row['receiver_user_id'] > 0 && get_userdata( (int) $row['receiver_user_id'] ) ) {
			WP_Application_Passwords::delete_all_application_passwords( (int) $row['receiver_user_id'] );
		}

		Pairing_Store::update(
			(int) $row['id'],
			array(
				'status'                  => 'repair_needed',
				'expires_gmt'             => null,
				'app_password_uuid'       => '',
				'app_password_enc'        => null,
				'secret_enc'              => null,
				'bootstrap_password_uuid' => '',
				'bootstrap_secret_enc'    => null,
			)
		);

		Audit_Log::write(
			'pairing_repair_needed',
			array(
				'actor_type'  => 'system',
				'pairing_id'  => (int) $row['id'],
				'object_type' => 'pairing',
				'object_ref'  => $row['pairing_uuid'],
				'details'     => array(
					'reason'          => $reason,
					'previous_status' => $row['status'],
				),
			)
		);
	}

	/**
	 * Whether the code runs under WP-CLI.
	 *
	 * @return bool
	 */
	private static function is_cli() {
		return defined( 'WP_CLI' ) && WP_CLI;
	}
}
