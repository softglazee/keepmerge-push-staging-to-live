<?php
/**
 * Pairing on the source.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

use SoftGlaze\SafePush\Transfer\Outbound_Guard;
use SoftGlaze\SafePush\Transfer\Remote_Client;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The source side of pairing (the destination side is Pairing_Service): preview the pasted key,
 * then confirm, store the long-term credentials encrypted and finalize with them; a live
 * administrator approves afterwards. The key's bootstrap values are never stored.
 *
 * Long-term credentials are decrypted only to build a Remote_Client; they never appear in a
 * return value, screen, CLI output, audit detail or error.
 */
final class Source_Pairing {

	const ROLE = 'source';

	const MAX_SKEW = 300;

	const ROUTE_STATUS = '/keepmerge/v1/remote/status';

	const ROUTE_CONFIRM = '/keepmerge/v1/remote/pair/confirm';

	const ROUTE_FINALIZE = '/keepmerge/v1/remote/pair/finalize';

	/**
	 * Answers from live that can never turn good again for this pairing, with the reason the
	 * row keeps: the pairing becomes repair_needed and its stored credentials are deleted.
	 */
	const PERMANENT = array(
		'pairing_invalid'     => 'refused_by_live',
		'pairing_revoked'     => 'revoked_on_live',
		'credentials_refused' => 'credentials_reset_on_live',
		'signature_invalid'   => 'signature_refused',
	);

	/**
	 * Why this site cannot connect to a live site right now.
	 *
	 * @return WP_Error|null
	 */
	public static function blocker() {
		if ( ! Pairing_Service::site_is_https() ) {
			return new WP_Error( 'https_required', __( 'This site must use HTTPS (WordPress Address, Site Address and this screen) before it can connect. Keepmerge connects over HTTPS only.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}
		if ( ! Crypto::available() ) {
			return new WP_Error( 'sodium_unavailable', __( 'Credentials cannot be stored encrypted on this server (no sodium library).', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}
		if ( ! Outbound_Guard::transport_available() ) {
			return new WP_Error( 'transport_unpinned', __( 'This server lacks PHP\'s cURL extension. Keepmerge needs it to make sure each connection goes to the address it checked (protection against DNS rebinding), so it does not connect without it. Ask your host to enable the cURL extension.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}
		if ( '' === Installer::site_id() ) {
			return new WP_Error( 'not_installed', __( 'Keepmerge is not fully installed on this site. Deactivate and activate it again.', 'keepmerge-push-staging-to-live' ), array( 'status' => 400 ) );
		}
		return null;
	}

	/**
	 * Step 1: what the key points to, read from live with the key's bootstrap credentials.
	 *
	 * @param mixed $key Pasted key.
	 * @return array|WP_Error Preview for the screen or CLI.
	 */
	public static function preview( $key ) {
		$blocker = self::blocker();
		if ( null !== $blocker ) {
			return $blocker;
		}

		$parsed = self::parse_key( $key );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$client = self::key_client( $parsed );
		$info   = self::fetch_status( $client, $parsed['pairing'] );
		if ( is_wp_error( $info ) ) {
			return $info;
		}

		Audit_Log::write(
			'status_checked',
			array(
				'object_type' => 'pairing',
				'object_ref'  => $parsed['pairing'],
				'details'     => array(
					'role'  => self::ROLE,
					'stage' => 'preview',
					'host'  => self::host_of( $parsed['rest_root'] ),
				),
			)
		);

		$blocked = self::connect_blocker( $info );

		return array(
			'pairing'                   => $parsed['pairing'],
			'receiver_login'            => $parsed['login'],
			'key_expires_at'            => (int) $parsed['expires'],
			'rest_host'                 => self::host_of( $parsed['rest_root'] ),
			'address'                   => is_array( $client->pin() ) ? $client->pin()['ip'] : '',
			'live'                      => $info['site'],
			'versions'                  => $info['versions'],
			'skew'                      => $info['skew'],
			'authorization_header_seen' => $info['authorization_header_seen'],
			'window_closes_at'          => $info['window_closes_at'],
			'can_connect'               => null === $blocked,
			'blocker'                   => null === $blocked ? null : array(
				'code'    => $blocked->get_error_code(),
				'message' => $blocked->get_error_message(),
			),
		);
	}

	/**
	 * Steps 2–4: confirm, store, finalize.
	 *
	 * @param mixed $key Pasted key.
	 * @return array|WP_Error The pairing as the screen shows it (awaiting approval on live).
	 */
	public static function connect( $key ) {
		$blocker = self::blocker();
		if ( null !== $blocker ) {
			return $blocker;
		}

		$parsed = self::parse_key( $key );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$existing = Pairing_Store::find( $parsed['pairing'] );
		if ( null !== $existing && self::ROLE !== $existing['role'] ) {
			return new WP_Error( 'same_site', Remote_Client::message( 'same_site' ), array( 'status' => 400 ) );
		}
		if ( null !== $existing && 'pending' !== $existing['status'] ) {
			return new WP_Error( 'key_already_used', Remote_Client::message( 'key_already_used' ), array( 'status' => 409 ) );
		}

		$client = self::key_client( $parsed );
		$info   = self::fetch_status( $client, $parsed['pairing'] );
		if ( is_wp_error( $info ) ) {
			if ( null !== $existing && 'key_already_used' === $info->get_error_code() ) {
				// This site confirmed with the key earlier and only lost finalize's answer: the
				// stored long-term credentials finish the pairing instead.
				$checked = self::check( $parsed['pairing'] );
				if ( is_array( $checked ) && in_array( $checked['status'], array( 'awaiting_approval', 'active' ), true ) ) {
					return $checked;
				}
			}
			return $info;
		}
		$blocked = self::connect_blocker( $info );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$site_id   = Installer::site_id();
		$confirmed = $client->post(
			self::ROUTE_CONFIRM,
			array(
				'site_id'        => $site_id,
				'home_url'       => home_url( '/' ),
				'wp_version'     => self::version_string( get_bloginfo( 'version' ) ),
				'plugin_version' => self::version_string( SOFTGLAZE_SP_VERSION ),
				'php_version'    => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION,
			)
		);
		if ( is_wp_error( $confirmed ) ) {
			return $confirmed;
		}

		$issued = self::issued_credentials( $confirmed['data'], $parsed, $info );
		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		$password_enc = Crypto::encrypt( $issued['password'] );
		$secret_enc   = Crypto::encrypt( $issued['secret'] );
		if ( '' === $password_enc || '' === $secret_enc ) {
			return new WP_Error( 'credentials_not_stored', __( 'The credentials could not be stored encrypted. Nothing was saved; try again.', 'keepmerge-push-staging-to-live' ), array( 'status' => 500 ) );
		}

		$pin  = $client->pin();
		$now  = time();
		$data = array(
			'pairing_uuid'     => $parsed['pairing'],
			'role'             => self::ROLE,
			'status'           => 'pending',
			'remote_url'       => $info['site']['home_url'],
			'rest_root'        => $parsed['rest_root'],
			'local_home_url'   => Installer::normalised_home(),
			'local_home_hash'  => Installer::home_hash(),
			'remote_login'     => $issued['login'],
			'app_password_enc' => $password_enc,
			'secret_enc'       => $secret_enc,
			'remote_info'      => self::encode_info( self::info_from_status( array(), $info, 'pending' ) ),
			'resolved_ip'      => is_array( $pin ) ? $pin['ip'] : '',
			'resolved_gmt'     => is_array( $pin ) ? gmdate( 'Y-m-d H:i:s', (int) $pin['checked_at'] ) : null,
			'expires_gmt'      => gmdate( 'Y-m-d H:i:s', (int) $parsed['expires'] ),
			'created_gmt'      => gmdate( 'Y-m-d H:i:s', $now ),
			'last_used_gmt'    => gmdate( 'Y-m-d H:i:s', $now ),
		);

		if ( null === $existing ) {
			$id = Pairing_Store::insert( $data );
			if ( $id < 1 ) {
				return new WP_Error( 'credentials_not_stored', __( 'The pairing could not be saved on this site. Try again with the same key.', 'keepmerge-push-staging-to-live' ), array( 'status' => 500 ) );
			}
		} else {
			unset( $data['created_gmt'] );
			Pairing_Store::update( (int) $existing['id'], $data );
		}

		$row = Pairing_Store::find( $parsed['pairing'] );

		$client->use_credentials( $issued['login'], $issued['password'], $issued['secret'], $site_id );
		$issued = null;

		$finalized = self::finalize( $row, $client );
		if ( is_wp_error( $finalized ) ) {
			return new WP_Error(
				'finalize_failed',
				sprintf(
					/* translators: %s: the reason the last step failed. */
					__( 'The live site issued this site\'s credentials, but the last pairing step did not complete: %s Use "Check status" on the Send tab (or wp keepmerge status) to finish it before the key expires.', 'keepmerge-push-staging-to-live' ),
					$finalized->get_error_message()
				),
				array(
					'status'      => 502,
					'remote_code' => $finalized->get_error_code(),
				)
			);
		}

		return self::present( Pairing_Store::find( $parsed['pairing'] ) );
	}

	/**
	 * A status call for a stored pairing: finishes a pending finalize, notices approval on
	 * live, records what live reported, and retires the pairing when live refuses it for good.
	 *
	 * @param string $uuid Pairing UUID.
	 * @return array|WP_Error The pairing as the screen shows it, with the result in last_check.
	 */
	public static function check( $uuid ) {
		$row = self::find( $uuid );
		if ( null === $row ) {
			return self::not_found();
		}
		if ( ! in_array( $row['status'], array( 'pending', 'awaiting_approval', 'active' ), true ) ) {
			return self::present( $row );
		}

		$client = self::row_client( $row );
		if ( is_wp_error( $client ) ) {
			self::to_repair_needed( $row, 'decrypt_failed' );
			return self::present( Pairing_Store::find( $row['pairing_uuid'] ) );
		}

		if ( 'pending' === $row['status'] ) {
			// A finalize whose answer was lost: try it again; if live already finalized, the
			// status call below finds the pairing waiting for approval.
			self::finalize( $row, $client );
			$row = Pairing_Store::find( $row['pairing_uuid'] );
		}

		$info = self::fetch_status( $client, $row['pairing_uuid'] );
		self::keep_pin( $row, $client );

		if ( is_wp_error( $info ) ) {
			self::record_failure( $row, $info );
			return self::present( Pairing_Store::find( $row['pairing_uuid'] ) );
		}

		$state = $info['state'];
		$data  = array(
			'last_used_gmt' => gmdate( 'Y-m-d H:i:s' ),
			'remote_info'   => self::encode_info( self::info_from_status( self::decode_info( $row ), $info, $state ) ),
		);

		if ( 'active' === $state && 'active' !== $row['status'] ) {
			$data['status']       = 'active';
			$data['approved_gmt'] = gmdate( 'Y-m-d H:i:s' );
			$data['expires_gmt']  = null;
		} elseif ( 'awaiting_approval' === $state ) {
			$data['status']      = 'awaiting_approval';
			$data['expires_gmt'] = null === $info['expires_at'] ? null : gmdate( 'Y-m-d H:i:s', (int) $info['expires_at'] );
		}

		Pairing_Store::update( (int) $row['id'], $data );

		Audit_Log::write(
			'status_checked',
			array(
				'pairing_id'  => (int) $row['id'],
				'object_type' => 'pairing',
				'object_ref'  => $row['pairing_uuid'],
				'collapse'    => true,
				'details'     => array(
					'role'  => self::ROLE,
					'state' => $state,
				),
			)
		);

		if ( isset( $data['status'] ) && 'active' === $data['status'] ) {
			Audit_Log::write(
				'pairing_activated',
				array(
					'actor_type'  => 'system',
					'pairing_id'  => (int) $row['id'],
					'object_type' => 'pairing',
					'object_ref'  => $row['pairing_uuid'],
					'details'     => array(
						'role'     => self::ROLE,
						'stage'    => 'approved_on_live',
						'live_url' => (string) $row['remote_url'],
					),
				)
			);
		}

		return self::present( Pairing_Store::find( $row['pairing_uuid'] ) );
	}

	/**
	 * Revoke on the source: deletes the stored credentials. There is no remote revoke route; the
	 * live administrator revokes the pairing on live.
	 *
	 * @param string $uuid Pairing UUID.
	 * @return array|WP_Error
	 */
	public static function revoke( $uuid ) {
		$row = self::find( $uuid );
		if ( null === $row ) {
			return self::not_found();
		}
		if ( 'revoked' === $row['status'] ) {
			return new WP_Error( 'already_revoked', __( 'This pairing is already revoked.', 'keepmerge-push-staging-to-live' ), array( 'status' => 409 ) );
		}

		self::retire( $row, 'revoked', 'revoked_here' );

		Audit_Log::write(
			'pairing_revoked',
			array(
				'pairing_id'  => (int) $row['id'],
				'object_type' => 'pairing',
				'object_ref'  => $row['pairing_uuid'],
				'details'     => array(
					'role'            => self::ROLE,
					'previous_status' => $row['status'],
					'live_url'        => (string) $row['remote_url'],
				),
			)
		);

		$out              = self::present( Pairing_Store::find( $row['pairing_uuid'] ) );
		$out['next_step'] = self::revoke_on_live_hint( $row );

		return $out;
	}

	/**
	 * Deactivation: every usable source pairing becomes repair_needed and its stored credentials
	 * are deleted, so a copy of this database holds nothing live still accepts.
	 *
	 * @return int Pairings changed.
	 */
	public static function mark_repair_needed_for_deactivation() {
		$changed = 0;
		foreach ( Pairing_Store::by_role( self::ROLE, array( 'pending', 'awaiting_approval', 'active' ), 1000 ) as $row ) {
			self::to_repair_needed( $row, 'deactivated' );
			++$changed;
		}
		return $changed;
	}

	/**
	 * Source pairings for the Send tab and the CLI (revoked ones are left out).
	 *
	 * @return array[]
	 */
	public static function list_pairings() {
		$out = array();
		foreach ( Pairing_Store::by_role( self::ROLE, array( 'pending', 'awaiting_approval', 'active', 'repair_needed' ), 200 ) as $row ) {
			$out[] = self::present( $row );
		}
		return $out;
	}

	/**
	 * A source row by UUID.
	 *
	 * @param mixed $uuid Pairing UUID.
	 * @return array|null
	 */
	public static function find( $uuid ) {
		$row = Pairing_Store::find( is_string( $uuid ) ? strtolower( trim( $uuid ) ) : '' );
		return null !== $row && self::ROLE === $row['role'] ? $row : null;
	}

	/**
	 * A source pairing as screens, REST responses and the CLI show it. No credential, no
	 * ciphertext.
	 *
	 * @param array|null $row Row.
	 * @return array|null
	 */
	public static function present( $row ) {
		if ( ! is_array( $row ) ) {
			return null;
		}

		$info   = self::decode_info( $row );
		$last   = isset( $info['last'] ) && is_array( $info['last'] ) ? $info['last'] : array();
		$reason = isset( $info['reason'] ) ? (string) $info['reason'] : '';
		$status = (string) $row['status'];

		$display = $status;
		if ( 'pending' === $status ) {
			$display = 'connecting';
		} elseif ( 'repair_needed' === $status && 'revoked_on_live' === $reason ) {
			$display = 'revoked_on_live';
		}

		$last_code = isset( $last['code'] ) ? (string) $last['code'] : '';

		return array(
			'uuid'                      => (string) $row['pairing_uuid'],
			'status'                    => $status,
			'display_status'            => $display,
			'reason'                    => $reason,
			'reason_message'            => self::reason_message( '' === $reason && 'repair_needed' === $status ? 'reset_here' : $reason ),
			'live'                      => array(
				'name'      => isset( $info['site']['name'] ) ? (string) $info['site']['name'] : '',
				'home_url'  => (string) $row['remote_url'],
				'host'      => self::host_of( (string) $row['rest_root'] ),
				'site_id'   => isset( $info['site']['site_id'] ) ? (string) $info['site']['site_id'] : '',
				'rest_root' => (string) $row['rest_root'],
			),
			'receiver_login'            => (string) $row['remote_login'],
			'versions'                  => isset( $info['versions'] ) && is_array( $info['versions'] ) ? $info['versions'] : array(),
			'skew'                      => isset( $info['skew'] ) ? (int) $info['skew'] : null,
			'authorization_header_seen' => isset( $info['authorization_header_seen'] ) ? (bool) $info['authorization_header_seen'] : null,
			'window_closes_at'          => isset( $info['window_closes_at'] ) ? (int) $info['window_closes_at'] : null,
			'window_open'               => isset( $info['window_open'] ) ? (bool) $info['window_open'] : null,
			'address'                   => (string) $row['resolved_ip'],
			'address_checked_at'        => Pairing_Service::timestamp( $row['resolved_gmt'] ),
			'last_check'                => empty( $last ) ? null : array(
				'at'      => isset( $last['at'] ) ? (int) $last['at'] : null,
				'ok'      => ! empty( $last['ok'] ),
				'code'    => $last_code,
				'reason'  => isset( $last['reason'] ) ? (string) $last['reason'] : '',
				'state'   => isset( $last['state'] ) ? (string) $last['state'] : '',
				'message' => '' === $last_code || ! empty( $last['ok'] ) ? '' : ( isset( $last['message'] ) && '' !== $last['message'] ? (string) $last['message'] : Remote_Client::message( $last_code ) ),
			),
			'created_at'                => Pairing_Service::timestamp( $row['created_gmt'] ),
			'confirmed_at'              => Pairing_Service::timestamp( $row['confirmed_gmt'] ),
			'approved_at'               => Pairing_Service::timestamp( $row['approved_gmt'] ),
			'expires_at'                => Pairing_Service::timestamp( $row['expires_gmt'] ),
			'last_used_at'              => Pairing_Service::timestamp( $row['last_used_gmt'] ),
			'revoked_at'                => Pairing_Service::timestamp( $row['revoked_gmt'] ),
		);
	}

	/**
	 * An active source pairing and a client with its long-term credentials, for the read-only
	 * routes (baseline, manifest, items/fetch). The credentials stay inside the client.
	 *
	 * @param mixed $uuid Pairing UUID.
	 * @return array{row:array,client:Remote_Client}|WP_Error
	 */
	public static function active_client( $uuid ) {
		$row = self::find( $uuid );
		if ( null === $row ) {
			return self::not_found();
		}
		if ( 'active' !== $row['status'] ) {
			return new WP_Error(
				'pairing_not_active',
				'awaiting_approval' === $row['status'] || 'pending' === $row['status']
					? Remote_Client::message( 'pairing_not_approved' )
					: __( 'This pairing cannot be used: connect again with a new key from the live site.', 'keepmerge-push-staging-to-live' ),
				array( 'status' => 409 )
			);
		}

		$client = self::row_client( $row );
		if ( is_wp_error( $client ) ) {
			self::to_repair_needed( $row, 'decrypt_failed' );
			return new WP_Error( 'decrypt_failed', $client->get_error_message(), array( 'status' => 409 ) );
		}

		return array(
			'row'    => $row,
			'client' => $client,
		);
	}

	/**
	 * After a read-route call: keeps a newly checked address, records a refusal in the pairing's
	 * last check and retires the pairing when live refused it for good (the PERMANENT codes).
	 *
	 * @param array         $row    Row the client was built from.
	 * @param Remote_Client $client Client.
	 * @param WP_Error|null $error  The call's error, if any.
	 * @return void
	 */
	public static function after_call( array $row, Remote_Client $client, $error = null ) {
		self::keep_pin( $row, $client );
		if ( ! $error instanceof WP_Error ) {
			Pairing_Store::update( (int) $row['id'], array( 'last_used_gmt' => gmdate( 'Y-m-d H:i:s' ) ) );
			return;
		}

		$code         = $error->get_error_code();
		$info         = self::decode_info( Pairing_Store::find( $row['pairing_uuid'] ) );
		$info['last'] = self::last_entry( false, $code, $error );
		if ( 'window_closed' === $code ) {
			$info['window_open']      = false;
			$info['window_closes_at'] = null;
		}
		Pairing_Store::update( (int) $row['id'], array( 'remote_info' => self::encode_info( $info ) ) );

		if ( isset( self::PERMANENT[ $code ] ) ) {
			self::to_repair_needed( Pairing_Store::find( $row['pairing_uuid'] ), self::PERMANENT[ $code ] );
		}
	}

	/**
	 * What to tell the admin after revoking here.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public static function revoke_on_live_hint( array $row ) {
		$info = self::decode_info( $row );
		if ( isset( $info['reason'] ) && in_array( $info['reason'], array( 'revoked_on_live', 'refused_by_live', 'credentials_reset_on_live' ), true ) ) {
			return sprintf(
				/* translators: 1: live site host, 2: pairing UUID. */
				__( 'The live site already refuses this pairing. If %1$s still lists pairing %2$s under Tools → Staging Push → Receive, revoke it there too.', 'keepmerge-push-staging-to-live' ),
				self::host_of( (string) $row['rest_root'] ),
				(string) $row['pairing_uuid']
			);
		}

		return sprintf(
			/* translators: 1: live site host, 2: pairing UUID. */
			__( 'Revoke the pairing on the live site too: on %1$s, open Tools → Staging Push → Receive and revoke pairing %2$s under "Paired staging sites" (or run wp keepmerge revoke %2$s there). Until then live keeps its receiver account for this site.', 'keepmerge-push-staging-to-live' ),
			self::host_of( (string) $row['rest_root'] ),
			(string) $row['pairing_uuid']
		);
	}

	/**
	 * Finalize with the long-term credentials.
	 *
	 * @param array         $row    Pending source row.
	 * @param Remote_Client $client Client holding the long-term credentials.
	 * @return true|WP_Error
	 */
	private static function finalize( array $row, Remote_Client $client ) {
		$result = $client->post( self::ROUTE_FINALIZE, array() );
		self::keep_pin( $row, $client );

		if ( is_wp_error( $result ) ) {
			$info         = self::decode_info( $row );
			$info['last'] = self::last_entry( false, $result->get_error_code(), $result );
			Pairing_Store::update( (int) $row['id'], array( 'remote_info' => self::encode_info( $info ) ) );
			return $result;
		}

		$status  = isset( $result['data']['status'] ) && is_string( $result['data']['status'] ) ? $result['data']['status'] : '';
		$approve = isset( $result['data']['approve_by'] ) && is_int( $result['data']['approve_by'] ) ? $result['data']['approve_by'] : 0;
		if ( 'awaiting_approval' !== $status || $approve < time() ) {
			return new WP_Error( 'response_invalid', Remote_Client::message( 'response_invalid' ), array( 'status' => 502 ) );
		}

		$info          = self::decode_info( $row );
		$info['state'] = 'awaiting_approval';
		$info['last']  = self::last_entry( true, '', null, 'awaiting_approval' );

		Pairing_Store::update(
			(int) $row['id'],
			array(
				'status'        => 'awaiting_approval',
				'confirmed_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'expires_gmt'   => gmdate( 'Y-m-d H:i:s', $approve ),
				'last_used_gmt' => gmdate( 'Y-m-d H:i:s' ),
				'remote_info'   => self::encode_info( $info ),
			)
		);

		Audit_Log::write(
			'pairing_confirmed',
			array(
				'pairing_id'  => (int) $row['id'],
				'object_type' => 'pairing',
				'object_ref'  => $row['pairing_uuid'],
				'details'     => array(
					'role'         => self::ROLE,
					'stage'        => 'finalized',
					'live_url'     => (string) $row['remote_url'],
					'live_site_id' => isset( $info['site']['site_id'] ) ? (string) $info['site']['site_id'] : '',
				),
			)
		);

		self::supersede_older( $row, $info );

		return true;
	}

	/**
	 * Older source pairings to the same live site are retired once a new one is finalized, so
	 * this site never pushes to one live site through two pairings.
	 *
	 * @param array $row  The new row.
	 * @param array $info Its remote_info.
	 * @return void
	 */
	private static function supersede_older( array $row, array $info ) {
		$site_id = isset( $info['site']['site_id'] ) ? (string) $info['site']['site_id'] : '';
		if ( '' === $site_id ) {
			return;
		}

		foreach ( Pairing_Store::by_role( self::ROLE, array( 'pending', 'awaiting_approval', 'active', 'repair_needed' ), 1000 ) as $old ) {
			if ( (int) $old['id'] === (int) $row['id'] ) {
				continue;
			}
			$old_info = self::decode_info( $old );
			if ( ! isset( $old_info['site']['site_id'] ) || $old_info['site']['site_id'] !== $site_id ) {
				continue;
			}

			self::retire( $old, 'revoked', 'superseded' );

			Audit_Log::write(
				'pairing_revoked',
				array(
					'actor_type'  => 'system',
					'pairing_id'  => (int) $old['id'],
					'object_type' => 'pairing',
					'object_ref'  => $old['pairing_uuid'],
					'details'     => array(
						'role'            => self::ROLE,
						'reason'          => 'superseded',
						'previous_status' => $old['status'],
						'replaced_by'     => $row['pairing_uuid'],
					),
				)
			);
		}
	}

	/**
	 * Reads live's status and checks every field by name.
	 *
	 * @param Remote_Client $client          Client.
	 * @param string        $expected_uuid   The pairing the answer must be about.
	 * @return array|WP_Error site, versions, skew, state, expires_at, window_closes_at, authorization_header_seen.
	 */
	private static function fetch_status( Remote_Client $client, $expected_uuid ) {
		$sent     = microtime( true );
		$response = $client->get( self::ROUTE_STATUS );
		$received = microtime( true );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data    = $response['data'];
		$site    = isset( $data['site'] ) && is_array( $data['site'] ) ? $data['site'] : array();
		$ver     = isset( $data['versions'] ) && is_array( $data['versions'] ) ? $data['versions'] : array();
		$pairing = isset( $data['pairing'] ) && is_array( $data['pairing'] ) ? $data['pairing'] : array();
		$window  = isset( $data['window'] ) && is_array( $data['window'] ) ? $data['window'] : array();

		$home    = isset( $site['home_url'] ) ? self::https_url( $site['home_url'] ) : '';
		$site_id = isset( $site['site_id'] ) && is_string( $site['site_id'] ) && wp_is_uuid( $site['site_id'], 4 ) ? strtolower( $site['site_id'] ) : '';
		$state   = isset( $pairing['state'] ) && is_string( $pairing['state'] ) ? $pairing['state'] : '';
		$uuid    = isset( $pairing['uuid'] ) && is_string( $pairing['uuid'] ) ? $pairing['uuid'] : '';
		$time    = isset( $data['server_time'] ) && is_int( $data['server_time'] ) ? $data['server_time'] : 0;

		if ( '' === $home || '' === $site_id || $uuid !== $expected_uuid || ! in_array( $state, array( 'pending', 'awaiting_approval', 'active' ), true ) || $time < 1 ) {
			return new WP_Error( 'response_invalid', Remote_Client::message( 'response_invalid' ), array( 'status' => 502 ) );
		}

		$versions = array();
		foreach ( array( 'wordpress', 'php', 'plugin' ) as $name ) {
			$versions[ $name ] = isset( $ver[ $name ] ) && is_string( $ver[ $name ] ) ? self::version_string( $ver[ $name ] ) : '';
		}
		$versions['protocol'] = isset( $ver['protocol'] ) && is_int( $ver['protocol'] ) ? $ver['protocol'] : 0;

		$name = isset( $site['name'] ) && is_string( $site['name'] ) ? sanitize_text_field( $site['name'] ) : '';

		return array(
			'site'                      => array(
				'name'     => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 200 ) : substr( $name, 0, 200 ),
				'home_url' => $home,
				'site_id'  => $site_id,
			),
			'versions'                  => $versions,
			'skew'                      => (int) round( $time - ( $sent + $received ) / 2 ),
			'state'                     => $state,
			'expires_at'                => isset( $pairing['expires_at'] ) && is_int( $pairing['expires_at'] ) ? $pairing['expires_at'] : null,
			'window_closes_at'          => isset( $window['closes_at'] ) && is_int( $window['closes_at'] ) ? $window['closes_at'] : null,
			'authorization_header_seen' => isset( $data['authorization_header_seen'] ) && true === $data['authorization_header_seen'],
		);
	}

	/**
	 * Why a previewed live site cannot be connected: clock skew, the site itself, an unknown
	 * protocol, a pairing no longer waiting for this key.
	 *
	 * @param array $info Status facts.
	 * @return WP_Error|null
	 */
	private static function connect_blocker( array $info ) {
		if ( abs( (int) $info['skew'] ) > self::MAX_SKEW ) {
			return new WP_Error(
				'clock_skew',
				sprintf(
					/* translators: %d: seconds. */
					__( 'The clocks of the two servers differ by %d seconds; pairing needs them within 300 seconds. Ask the host of the site whose clock is wrong to synchronise it (NTP), then try again.', 'keepmerge-push-staging-to-live' ),
					abs( (int) $info['skew'] )
				),
				array( 'status' => 409 )
			);
		}
		if ( hash_equals( Installer::site_id(), $info['site']['site_id'] ) ) {
			return new WP_Error( 'same_site', Remote_Client::message( 'same_site' ), array( 'status' => 400 ) );
		}
		if ( (int) Signer::PROTOCOL_VERSION !== (int) $info['versions']['protocol'] ) {
			return new WP_Error( 'bad_headers', Remote_Client::message( 'bad_headers' ), array( 'status' => 409 ) );
		}
		if ( 'pending' !== $info['state'] ) {
			return new WP_Error( 'key_already_used', Remote_Client::message( 'key_already_used' ), array( 'status' => 409 ) );
		}
		return null;
	}

	/**
	 * The long-term credentials from live's confirm answer, each checked by name.
	 *
	 * @param array $data   Signed answer.
	 * @param array $parsed Parsed key.
	 * @param array $info   Status facts.
	 * @return array{login:string,password:string,secret:string}|WP_Error
	 */
	private static function issued_credentials( array $data, array $parsed, array $info ) {
		$login    = isset( $data['receiver_login'] ) && is_string( $data['receiver_login'] ) ? $data['receiver_login'] : '';
		$password = isset( $data['app_password'] ) && is_string( $data['app_password'] ) ? $data['app_password'] : '';
		$secret   = isset( $data['secret'] ) && is_string( $data['secret'] ) ? $data['secret'] : '';
		$pairing  = isset( $data['pairing'] ) && is_string( $data['pairing'] ) ? $data['pairing'] : '';
		$site_id  = isset( $data['site']['site_id'] ) && is_string( $data['site']['site_id'] ) ? strtolower( $data['site']['site_id'] ) : '';

		if (
			$pairing !== $parsed['pairing']
			|| $login !== $parsed['login']
			|| 1 !== preg_match( '/\A[A-Za-z0-9]{24}\z/', $password )
			|| ! Signer::is_secret( $secret )
			|| $site_id !== $info['site']['site_id']
		) {
			return new WP_Error( 'response_invalid', Remote_Client::message( 'response_invalid' ), array( 'status' => 502 ) );
		}

		return array(
			'login'    => $login,
			'password' => $password,
			'secret'   => $secret,
		);
	}

	/**
	 * Records a failed status call; live's permanent refusals retire the pairing.
	 *
	 * @param array    $row   Row.
	 * @param WP_Error $error Error.
	 * @return void
	 */
	private static function record_failure( array $row, WP_Error $error ) {
		$code         = $error->get_error_code();
		$info         = self::decode_info( Pairing_Store::find( $row['pairing_uuid'] ) );
		$info['last'] = self::last_entry( false, $code, $error );
		if ( 'window_closed' === $code ) {
			$info['window_open']      = false;
			$info['window_closes_at'] = null;
		}
		if ( 'authorization_header_missing' === $code ) {
			$info['authorization_header_seen'] = false;
		}

		Pairing_Store::update( (int) $row['id'], array( 'remote_info' => self::encode_info( $info ) ) );

		Audit_Log::write(
			'status_checked',
			array(
				'result'      => 'denied',
				'pairing_id'  => (int) $row['id'],
				'object_type' => 'pairing',
				'object_ref'  => $row['pairing_uuid'],
				'details'     => array(
					'role'   => self::ROLE,
					'reason' => $code,
				),
			)
		);

		if ( isset( self::PERMANENT[ $code ] ) ) {
			self::to_repair_needed( Pairing_Store::find( $row['pairing_uuid'] ), self::PERMANENT[ $code ] );
		}
	}

	/**
	 * Marks a pairing repair_needed and deletes its stored credentials.
	 *
	 * @param array  $row    Row.
	 * @param string $reason Reason kept with the row.
	 * @return void
	 */
	private static function to_repair_needed( array $row, $reason ) {
		self::retire( $row, 'repair_needed', $reason );

		Audit_Log::write(
			'pairing_repair_needed',
			array(
				'actor_type'  => 'system',
				'pairing_id'  => (int) $row['id'],
				'object_type' => 'pairing',
				'object_ref'  => $row['pairing_uuid'],
				'details'     => array(
					'role'            => self::ROLE,
					'reason'          => $reason,
					'previous_status' => $row['status'],
				),
			)
		);
	}

	/**
	 * Deletes a row's credentials and sets its final status.
	 *
	 * @param array  $row    Row.
	 * @param string $status repair_needed or revoked.
	 * @param string $reason Reason kept in remote_info.
	 * @return void
	 */
	private static function retire( array $row, $status, $reason ) {
		$info           = self::decode_info( $row );
		$info['reason'] = $reason;

		$data = array(
			'status'           => $status,
			'app_password_enc' => null,
			'secret_enc'       => null,
			'expires_gmt'      => null,
			'resolved_ip'      => '',
			'resolved_gmt'     => null,
			'remote_info'      => self::encode_info( $info ),
		);
		if ( 'revoked' === $status ) {
			$data['revoked_gmt'] = gmdate( 'Y-m-d H:i:s' );
		}

		Pairing_Store::update( (int) $row['id'], $data );
	}

	/**
	 * A client with the key's bootstrap credentials.
	 *
	 * @param array $parsed Parsed key.
	 * @return Remote_Client
	 */
	private static function key_client( array $parsed ) {
		return new Remote_Client( $parsed['rest_root'], $parsed['pairing'], $parsed['login'], $parsed['password'], $parsed['secret'], '-', null, 'key' );
	}

	/**
	 * A client with a stored pairing's long-term credentials.
	 *
	 * @param array $row Row.
	 * @return Remote_Client|WP_Error
	 */
	private static function row_client( array $row ) {
		$password = Crypto::decrypt( $row['app_password_enc'] );
		$secret   = Crypto::decrypt( $row['secret_enc'] );
		if ( is_wp_error( $password ) || is_wp_error( $secret ) || ! Signer::is_secret( $secret ) || '' === (string) $row['remote_login'] ) {
			return new WP_Error( 'decrypt_failed', __( 'A stored credential could not be decrypted. Pair the sites again.', 'keepmerge-push-staging-to-live' ) );
		}

		$pin = null;
		$at  = Pairing_Service::timestamp( $row['resolved_gmt'] );
		if ( '' !== (string) $row['resolved_ip'] && null !== $at ) {
			$pin = array(
				'host'       => self::host_of( (string) $row['rest_root'] ),
				'ip'         => (string) $row['resolved_ip'],
				'checked_at' => $at,
			);
		}

		return new Remote_Client( (string) $row['rest_root'], (string) $row['pairing_uuid'], (string) $row['remote_login'], $password, $secret, Installer::site_id(), $pin );
	}

	/**
	 * Stores the address a client checked when it resolved the host again.
	 *
	 * @param array         $row    Row.
	 * @param Remote_Client $client Client.
	 * @return void
	 */
	private static function keep_pin( array $row, Remote_Client $client ) {
		$pin = $client->pin();
		if ( ! $client->pin_changed() || ! is_array( $pin ) ) {
			return;
		}
		Pairing_Store::update(
			(int) $row['id'],
			array(
				'resolved_ip'  => (string) $pin['ip'],
				'resolved_gmt' => gmdate( 'Y-m-d H:i:s', (int) $pin['checked_at'] ),
			)
		);
	}

	/**
	 * Parses a pasted key with Pairing_Key's parser (one pattern per part).
	 *
	 * @param mixed $key Key.
	 * @return array|WP_Error
	 */
	private static function parse_key( $key ) {
		$parsed = Pairing_Key::parse( is_string( $key ) ? $key : '' );
		if ( is_wp_error( $parsed ) ) {
			$parsed->add_data( array( 'status' => 400 ) );
		}
		return $parsed;
	}

	/**
	 * The remote_info of a row after a successful status call.
	 *
	 * @param array  $info  Current remote_info.
	 * @param array  $facts Status facts.
	 * @param string $state Pairing state on live.
	 * @return array
	 */
	private static function info_from_status( array $info, array $facts, $state ) {
		$info['site']                      = $facts['site'];
		$info['versions']                  = $facts['versions'];
		$info['skew']                      = (int) $facts['skew'];
		$info['authorization_header_seen'] = (bool) $facts['authorization_header_seen'];
		$info['window_closes_at']          = $facts['window_closes_at'];
		$info['window_open']               = true;
		$info['state']                     = $state;
		$info['last']                      = self::last_entry( true, '', null, $state );
		return $info;
	}

	/**
	 * One "last check" record.
	 *
	 * @param bool          $ok    Whether it succeeded.
	 * @param string        $code  Error code.
	 * @param WP_Error|null $error The error, for its reason and message.
	 * @param string        $state Pairing state on live.
	 * @return array
	 */
	private static function last_entry( $ok, $code, $error = null, $state = '' ) {
		$data = $error instanceof WP_Error ? (array) $error->get_error_data() : array();
		return array(
			'at'      => time(),
			'ok'      => (bool) $ok,
			'code'    => (string) $code,
			'reason'  => isset( $data['reason'] ) ? (string) $data['reason'] : '',
			'state'   => (string) $state,
			// The guard's messages name the refused host or address; the rest are rebuilt from the code.
			'message' => $error instanceof WP_Error && in_array( $code, array( 'address_refused', 'transport_unpinned', 'clock_skew' ), true ) ? $error->get_error_message() : '',
		);
	}

	/**
	 * Words for the reason a pairing was retired.
	 *
	 * @param string $reason Reason.
	 * @return string
	 */
	private static function reason_message( $reason ) {
		switch ( $reason ) {
			case 'deactivated':
				return __( 'Keepmerge was deactivated on this site, which retires its pairings and deletes their credentials here. Revoke the pairing on the live site and connect again with a new key.', 'keepmerge-push-staging-to-live' );
			case 'decrypt_failed':
				return __( 'The stored credentials could not be decrypted (the security keys or SOFTGLAZE_SP_KEY changed). Revoke the pairing here and on live, and connect again with a new key.', 'keepmerge-push-staging-to-live' );
			case 'superseded':
				return __( 'Replaced by a newer pairing to the same live site.', 'keepmerge-push-staging-to-live' );
			case 'reset_here':
				return __( 'This pairing\'s credentials were reset on this site (for example because this database was copied to another address). Revoke it here and connect again with a new key.', 'keepmerge-push-staging-to-live' );
			case 'revoked_here':
				return __( 'Revoked on this site.', 'keepmerge-push-staging-to-live' );
			case 'refused_by_live':
				return Remote_Client::message( 'pairing_invalid' );
			case 'revoked_on_live':
				return Remote_Client::message( 'pairing_revoked' );
			case 'credentials_reset_on_live':
				return Remote_Client::message( 'credentials_refused' );
			case 'signature_refused':
				return Remote_Client::message( 'signature_invalid' );
			default:
				return '';
		}
	}

	/**
	 * The decoded remote_info of a row.
	 *
	 * @param array|null $row Row.
	 * @return array
	 */
	private static function decode_info( $row ) {
		if ( ! is_array( $row ) || empty( $row['remote_info'] ) || ! is_string( $row['remote_info'] ) ) {
			return array();
		}
		$info = json_decode( $row['remote_info'], true, 8 );
		return is_array( $info ) ? $info : array();
	}

	/**
	 * Encodes remote_info.
	 *
	 * @param array $info Info.
	 * @return string
	 */
	private static function encode_info( array $info ) {
		return (string) wp_json_encode( $info );
	}

	/**
	 * The lowercase host of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host_of( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * A version string as live's confirm route accepts it: up to 20 of [0-9A-Za-z.+-].
	 *
	 * @param mixed $value Version.
	 * @return string
	 */
	private static function version_string( $value ) {
		return substr( (string) preg_replace( '/[^0-9A-Za-z.+-]/', '', (string) $value ), 0, 20 );
	}

	/**
	 * An https URL without user-info, query or fragment; '' otherwise.
	 *
	 * @param mixed $value Candidate.
	 * @return string
	 */
	private static function https_url( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 255 || 1 === preg_match( '/[\s<>"\'\\\\]/', $value ) ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return '';
		}
		return esc_url_raw( $value, array( 'https' ) );
	}

	/**
	 * The error for an unknown source pairing.
	 *
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'pairing_not_found', __( 'No such pairing on this site.', 'keepmerge-push-staging-to-live' ), array( 'status' => 404 ) );
	}
}
