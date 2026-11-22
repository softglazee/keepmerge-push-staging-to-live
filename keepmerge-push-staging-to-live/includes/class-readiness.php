<?php
/**
 * Readiness checks for the Overview tab.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What this server needs before it can pair and push (blueprint F16). Each check reports a
 * status ('ok', 'warning', 'error'), a label and one sentence saying what was found and, when
 * something is missing, what to do.
 *
 * Sending adds two checks: PHP's cURL transport (address pinning, blueprint §5.10) and, once a
 * status call reached live, whether the Authorization header arrived there.
 */
final class Readiness {

	const MIN_MEMORY = 134217728;

	const MIN_TIME = 15;

	/**
	 * All checks, in display order.
	 *
	 * @return array<int,array{id:string,label:string,status:string,detail:string}>
	 */
	public static function checks() {
		$checks = array(
			self::https(),
			self::application_passwords(),
			self::sodium(),
			self::filesystem(),
			self::time_limit(),
			self::memory_limit(),
			self::key_source(),
			self::pinned_transport(),
		);

		$header = self::authorization_header();
		if ( null !== $header ) {
			$checks[] = $header;
		}

		return $checks;
	}

	/**
	 * Sending needs PHP's cURL extension: each connection is pinned to the address the outbound
	 * guard checked, and without cURL the site refuses to connect (DECISIONS A6, threat model RR4).
	 * A site that only receives does not need it, so it is a warning there.
	 *
	 * @return array
	 */
	private static function pinned_transport() {
		$sending  = ! empty( Pairing_Store::by_role( Source_Pairing::ROLE, array( 'pending', 'awaiting_approval', 'active' ), 1 ) );
		$unpinned = (int) get_option( 'softglaze_sp_last_unpinned', 0 );

		if ( ! Transfer\Outbound_Guard::transport_available() ) {
			return array(
				'id'     => 'pinned_transport',
				'label'  => __( 'cURL for sending', 'keepmerge-push-staging-to-live' ),
				'status' => $sending ? 'error' : 'warning',
				'detail' => __( 'PHP\'s cURL extension is missing. This site can receive pushes, but it cannot connect to a live site or send: Keepmerge pins each connection to the address it checked (protection against DNS rebinding) and needs cURL for that. Ask your host to enable it.', 'keepmerge-push-staging-to-live' ),
			);
		}

		if ( $unpinned > 0 ) {
			return array(
				'id'     => 'pinned_transport',
				'label'  => __( 'cURL for sending', 'keepmerge-push-staging-to-live' ),
				'status' => 'error',
				'detail' => sprintf(
					/* translators: %s: date and time. */
					__( 'The cURL extension is loaded, but a request to live on %s did not go through it (another plugin or the server setup replaced WordPress\'s HTTP transport), so its answer was discarded. Sending needs WordPress to use cURL: Keepmerge pins each connection to the address it checked (protection against DNS rebinding).', 'keepmerge-push-staging-to-live' ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $unpinned )
				),
			);
		}

		return array(
			'id'     => 'pinned_transport',
			'label'  => __( 'cURL for sending', 'keepmerge-push-staging-to-live' ),
			'status' => 'ok',
			'detail' => __( 'Available. Each connection to live goes to the address Keepmerge checked (protection against DNS rebinding).', 'keepmerge-push-staging-to-live' ),
		);
	}

	/**
	 * Whether the Authorization header reached live in the latest status call of a source
	 * pairing; null before any status call.
	 *
	 * @return array|null
	 */
	private static function authorization_header() {
		$latest = null;
		foreach ( Source_Pairing::list_pairings() as $pairing ) {
			if ( null === $pairing['authorization_header_seen'] || null === $pairing['last_check'] ) {
				continue;
			}
			if ( null === $latest || (int) $pairing['last_check']['at'] > (int) $latest['last_check']['at'] ) {
				$latest = $pairing;
			}
		}

		if ( null === $latest ) {
			return null;
		}

		$ok = true === $latest['authorization_header_seen'];

		return array(
			'id'     => 'authorization_header',
			'label'  => __( 'Authorization header on live', 'keepmerge-push-staging-to-live' ),
			'status' => $ok ? 'ok' : 'error',
			'detail' => $ok
				? sprintf(
					/* translators: %s: live site host. */
					__( 'The last status call showed that %s receives the Authorization header, so its receiver account can sign in.', 'keepmerge-push-staging-to-live' ),
					$latest['live']['host']
				)
				: sprintf(
					/* translators: %s: live site host. */
					__( 'The Authorization header does not reach WordPress on %s: its web server or a proxy removes it, so pairing and pushing fail. Ask that host to pass it to PHP (on Apache: CGIPassAuth On, or a SetEnvIf Authorization rule in .htaccess).', 'keepmerge-push-staging-to-live' ),
					$latest['live']['host']
				),
		);
	}

	/**
	 * Both site URLs use HTTPS and this request arrived over HTTPS.
	 *
	 * @return array
	 */
	private static function https() {
		$ok = wp_is_using_https() && is_ssl();

		return array(
			'id'     => 'https',
			'label'  => __( 'HTTPS', 'keepmerge-push-staging-to-live' ),
			'status' => $ok ? 'ok' : 'error',
			'detail' => $ok
				? __( 'This site is served over HTTPS.', 'keepmerge-push-staging-to-live' )
				: __( 'Keepmerge pairs and pushes over HTTPS only. Switch the WordPress Address and Site Address to https:// and load this screen over HTTPS.', 'keepmerge-push-staging-to-live' ),
		);
	}

	/**
	 * Core Application Passwords are available (they identify the paired site).
	 *
	 * @return array
	 */
	private static function application_passwords() {
		$ok = wp_is_application_passwords_available();

		return array(
			'id'     => 'application_passwords',
			'label'  => __( 'Application Passwords', 'keepmerge-push-staging-to-live' ),
			'status' => $ok ? 'ok' : 'error',
			'detail' => $ok
				? __( 'Available. The paired site signs in as a dedicated receiver user with one.', 'keepmerge-push-staging-to-live' )
				: __( 'Turned off on this site, often by a security plugin. Pairing needs them; Keepmerge uses one for a receiver user that can do nothing but receive pushes.', 'keepmerge-push-staging-to-live' ),
		);
	}

	/**
	 * Sodium is available for encrypting stored credentials.
	 *
	 * @return array
	 */
	private static function sodium() {
		$impl = Crypto::implementation();

		if ( 'none' === $impl ) {
			$status = 'error';
			$detail = __( 'Neither the PHP sodium extension nor WordPress\'s sodium_compat is available, so credentials cannot be stored encrypted.', 'keepmerge-push-staging-to-live' );
		} elseif ( 'ext-sodium' === $impl ) {
			$status = 'ok';
			$detail = __( 'The PHP sodium extension encrypts stored credentials.', 'keepmerge-push-staging-to-live' );
		} else {
			$status = 'ok';
			$detail = __( 'WordPress\'s bundled sodium_compat encrypts stored credentials (the PHP sodium extension is faster, but not required).', 'keepmerge-push-staging-to-live' );
		}

		return array(
			'id'     => 'sodium',
			'label'  => __( 'Encryption library', 'keepmerge-push-staging-to-live' ),
			'status' => $status,
			'detail' => $detail,
		);
	}

	/**
	 * Uploads are writable through WP_Filesystem with the direct method: push steps run in REST
	 * requests and cannot stop to ask for FTP credentials.
	 *
	 * @return array
	 */
	private static function filesystem() {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return array(
				'id'     => 'filesystem',
				'label'  => __( 'Uploads folder', 'keepmerge-push-staging-to-live' ),
				'status' => 'error',
				'detail' => __( 'The uploads folder is missing or not readable, so media cannot be received.', 'keepmerge-push-staging-to-live' ),
			);
		}

		// A site that never stored an upload has no uploads folder yet. Media_Receiver creates it
		// with the first file and checks the method on wp-content in that case; so does this check.
		$exists = is_dir( $uploads['basedir'] );

		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$method = get_filesystem_method( array(), $exists ? $uploads['basedir'] : WP_CONTENT_DIR, true );
		$ok     = 'direct' === $method;

		if ( ! $ok ) {
			$detail = sprintf(
				/* translators: %s: the filesystem method WordPress would use, for example ftpext. */
				__( 'WordPress would need FTP credentials to write here (method: %s). Pushes run in the background and cannot ask for them; ask your host to let PHP write to the uploads folder.', 'keepmerge-push-staging-to-live' ),
				$method
			);
		} elseif ( $exists ) {
			$detail = __( 'Writable directly by WordPress.', 'keepmerge-push-staging-to-live' );
		} else {
			$detail = __( 'Not created yet; WordPress can create it directly when the first file arrives.', 'keepmerge-push-staging-to-live' );
		}

		return array(
			'id'     => 'filesystem',
			'label'  => __( 'Uploads folder', 'keepmerge-push-staging-to-live' ),
			'status' => $ok ? 'ok' : 'error',
			'detail' => $detail,
		);
	}

	/**
	 * PHP max_execution_time; Keepmerge works in steps inside it rather than raising it.
	 *
	 * @return array
	 */
	private static function time_limit() {
		$max    = Limits::max_execution_time();
		$budget = Limits::time_budget();
		$ok     = 0 === $max || $max >= self::MIN_TIME;

		if ( 0 === $max ) {
			/* translators: %d: seconds per step. */
			$detail = sprintf( __( 'No PHP time limit. Keepmerge still works in steps of up to %d seconds.', 'keepmerge-push-staging-to-live' ), $budget );
		} else {
			/* translators: 1: PHP time limit in seconds, 2: seconds per step. */
			$detail = sprintf( __( 'PHP allows %1$d seconds per request; Keepmerge works in steps of up to %2$d seconds.', 'keepmerge-push-staging-to-live' ), $max, $budget );
			if ( ! $ok ) {
				$detail .= ' ' . __( 'Pushes will take more, smaller steps.', 'keepmerge-push-staging-to-live' );
			}
		}

		return array(
			'id'     => 'time_limit',
			'label'  => __( 'PHP time limit', 'keepmerge-push-staging-to-live' ),
			'status' => $ok ? 'ok' : 'warning',
			'detail' => $detail,
		);
	}

	/**
	 * PHP memory_limit; batches stop early on small limits.
	 *
	 * @return array
	 */
	private static function memory_limit() {
		$bytes = Limits::memory_limit_bytes();
		$ok    = -1 === $bytes || $bytes >= self::MIN_MEMORY;

		if ( -1 === $bytes ) {
			$detail = __( 'No PHP memory limit.', 'keepmerge-push-staging-to-live' );
		} else {
			/* translators: %s: memory size such as 128 MB. */
			$detail = sprintf( __( 'PHP allows %s per request.', 'keepmerge-push-staging-to-live' ), size_format( $bytes ) );
			if ( ! $ok ) {
				$detail .= ' ' . __( 'Below 128 MB, pushes use smaller batches.', 'keepmerge-push-staging-to-live' );
			}
		}

		return array(
			'id'     => 'memory_limit',
			'label'  => __( 'PHP memory limit', 'keepmerge-push-staging-to-live' ),
			'status' => $ok ? 'ok' : 'warning',
			'detail' => $detail,
		);
	}

	/**
	 * Where the key that encrypts stored credentials comes from (blueprint §5.7).
	 *
	 * @return array
	 */
	private static function key_source() {
		$source = Crypto::key_source();

		if ( Crypto::SOURCE_CONSTANT === $source ) {
			$status = 'ok';
			$detail = __( 'From the SOFTGLAZE_SP_KEY constant in wp-config.php.', 'keepmerge-push-staging-to-live' );
		} elseif ( Crypto::SOURCE_CONFIG === $source ) {
			$status = 'ok';
			$detail = __( 'From the WordPress security keys in wp-config.php.', 'keepmerge-push-staging-to-live' );
		} else {
			$status = 'warning';
			$detail = __( 'The WordPress security keys are not set in wp-config.php, so the key comes from the database. A copy of the database would then hold both the key and the encrypted credentials. Add SOFTGLAZE_SP_KEY (at least 32 random characters) or real security keys to wp-config.php, then pair again.', 'keepmerge-push-staging-to-live' );
		}

		return array(
			'id'     => 'key_source',
			'label'  => __( 'Encryption key', 'keepmerge-push-staging-to-live' ),
			'status' => $status,
			'detail' => $detail,
		);
	}
}
