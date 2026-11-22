<?php
/**
 * Outbound address checks and pinning (source only).
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Transfer;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether the source may connect to a URL and makes the connection go to the address it
 * checked (blueprint §5.10, threat model §7.3).
 *
 * The REST root comes from a pasted pairing key, so it is untrusted input. Before every request:
 * https on port 443 only, no user-info, no fragment, never this site's own host; the host is
 * resolved once with gethostbynamel() and every IPv4 address must be public. The only exception
 * is core's http_request_host_is_external filter, called with the arguments core uses; the plugin
 * adds nothing to it. The checked address is kept for at most PIN_TTL seconds and then resolved
 * and checked again.
 *
 * The connection itself is pinned to the checked address on core's own cURL handle in the
 * http_api_curl hook, so a second, different DNS answer (DNS rebinding) is never used; TLS still
 * verifies the certificate for the host name. A request that did not pass the pin is discarded
 * by Remote_Client as transport_unpinned. Without the cURL extension the source refuses to
 * connect: there is no unpinned mode (threat model RR4).
 */
final class Outbound_Guard {

	/**
	 * Seconds a checked address may be reused before it is resolved and checked again.
	 */
	const PIN_TTL = 600;

	/**
	 * The only port the source connects to.
	 */
	const PORT = 443;

	/**
	 * IPv4 ranges never connected to: this network, private, CGNAT, loopback, link-local and
	 * cloud metadata, IETF protocol assignments, documentation, 6to4 relay, benchmarking,
	 * multicast and reserved (blueprint §5.10). Network address => prefix length.
	 */
	const REFUSED_RANGES = array(
		'0.0.0.0'      => 8,
		'10.0.0.0'     => 8,
		'100.64.0.0'   => 10,
		'127.0.0.0'    => 8,
		'169.254.0.0'  => 16,
		'172.16.0.0'   => 12,
		'192.0.0.0'    => 24,
		'192.0.2.0'    => 24,
		'192.88.99.0'  => 24,
		'192.168.0.0'  => 16,
		'198.18.0.0'   => 15,
		'198.51.100.0' => 24,
		'203.0.113.0'  => 24,
		'224.0.0.0'    => 4,
		'240.0.0.0'    => 4,
	);

	/**
	 * Requests armed by Remote_Client for this PHP process: nonce => host and address to pin.
	 *
	 * @var array<string,array{host:string,ip:string}>
	 */
	private static $armed = array();

	/**
	 * Nonces of armed requests whose cURL handle was pinned.
	 *
	 * @var array<string,bool>
	 */
	private static $pinned = array();

	/**
	 * Whether this server can pin connections: PHP's cURL extension with the resolve option.
	 *
	 * @return bool
	 */
	public static function transport_available() {
		return extension_loaded( 'curl' ) && defined( 'CURLOPT_RESOLVE' );
	}

	/**
	 * Checks a URL and returns the address to connect to.
	 *
	 * @param string     $url Full request URL.
	 * @param array|null $pin Cached result of an earlier check: host, ip, checked_at.
	 * @return array{host:string,ip:string,checked_at:int,fresh:bool}|WP_Error fresh is true when the host was resolved now.
	 */
	public static function check( $url, $pin = null ) {
		if ( ! self::transport_available() ) {
			return self::unpinned( 'no_curl' );
		}

		$host = self::check_url( $url );
		if ( is_wp_error( $host ) ) {
			return $host;
		}

		$proxy = new \WP_HTTP_Proxy();
		if ( $proxy->is_enabled() && $proxy->send_through_proxy( $url ) ) {
			// A proxy resolves the host itself, so the checked address could not be pinned.
			return self::unpinned( 'proxy' );
		}

		if ( self::pin_is_valid( $pin, $host ) ) {
			return array(
				'host'       => $host,
				'ip'         => $pin['ip'],
				'checked_at' => (int) $pin['checked_at'],
				'fresh'      => false,
			);
		}

		$ip = self::resolve( $host, $url );
		if ( is_wp_error( $ip ) ) {
			return $ip;
		}

		return array(
			'host'       => $host,
			'ip'         => $ip,
			'checked_at' => time(),
			'fresh'      => true,
		);
	}

	/**
	 * The checks that need no DNS: scheme, user-info, fragment, port, host syntax, own host.
	 *
	 * @param string $url URL.
	 * @return string|WP_Error The lowercase host.
	 */
	public static function check_url( $url ) {
		$parts = is_string( $url ) && '' !== $url && strlen( $url ) <= 2048 ? wp_parse_url( $url ) : false;
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return self::refused( 'invalid_url' );
		}
		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return self::refused( 'scheme' );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return self::refused( 'userinfo' );
		}
		if ( isset( $parts['fragment'] ) ) {
			return self::refused( 'fragment' );
		}
		if ( isset( $parts['port'] ) && self::PORT !== (int) $parts['port'] ) {
			return self::refused( 'port', '', (string) (int) $parts['port'] );
		}

		$host = strtolower( (string) $parts['host'] );

		if ( false !== strpos( $host, ':' ) || '[' === substr( $host, 0, 1 ) ) {
			return self::refused( 'ipv6_unsupported', $host );
		}
		if ( ! self::is_ipv4_literal( $host ) && ! self::is_hostname( $host ) ) {
			return self::refused( 'invalid_host', $host );
		}

		foreach ( array( home_url(), site_url() ) as $own ) {
			$own_host = wp_parse_url( $own, PHP_URL_HOST );
			if ( is_string( $own_host ) && strtolower( trim( $own_host, '.' ) ) === $host ) {
				return self::refused( 'own_host', $host );
			}
		}

		return $host;
	}

	/**
	 * Resolves a host once and checks every IPv4 address it has.
	 *
	 * @param string $host Lowercase host (a name or a dotted-quad IPv4 address).
	 * @param string $url  The URL, passed on to core's filter as core passes it.
	 * @return string|WP_Error The address to pin (the first one returned).
	 */
	public static function resolve( $host, $url ) {
		$addresses = self::is_ipv4_literal( $host ) ? array( $host ) : gethostbynamel( $host );

		if ( ! is_array( $addresses ) || empty( $addresses ) ) {
			return self::refused( 'no_ipv4', $host );
		}

		$refused = '';
		foreach ( $addresses as $address ) {
			if ( ! is_string( $address ) || ! filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
				return self::refused( 'no_ipv4', $host );
			}
			if ( '' === $refused && '' !== self::refused_range( $address ) ) {
				$refused = $address;
			}
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, called with the arguments wp_http_validate_url() passes; the plugin never adds to it.
		if ( '' !== $refused && ! apply_filters( 'http_request_host_is_external', false, $host, $url ) ) {
			return self::refused( 'private_address', $host, $refused . ' (' . self::refused_range( $refused ) . ')' );
		}

		return (string) $addresses[0];
	}

	/**
	 * The refused range an IPv4 address falls in.
	 *
	 * @param string $ip Dotted-quad IPv4 address.
	 * @return string The range in CIDR notation, '' when the address is public, 'invalid' when it is not an IPv4 address.
	 */
	public static function refused_range( $ip ) {
		if ( ! is_string( $ip ) || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return 'invalid';
		}

		$value = (int) sprintf( '%u', ip2long( $ip ) );
		foreach ( self::REFUSED_RANGES as $network => $bits ) {
			$size  = (int) pow( 2, 32 - $bits );
			$start = (int) sprintf( '%u', ip2long( $network ) );
			if ( $value >= $start && $value < $start + $size ) {
				return $network . '/' . $bits;
			}
		}

		return '';
	}

	/**
	 * Registers the address one request must connect to. Remote_Client calls this right before
	 * the request and take() right after it.
	 *
	 * @param string $nonce Request nonce (X-SGSP-Nonce), unique per request.
	 * @param string $host  Lowercase host of the request URL.
	 * @param string $ip    Checked address.
	 * @return void
	 */
	public static function arm( $nonce, $host, $ip ) {
		self::$armed[ (string) $nonce ] = array(
			'host' => (string) $host,
			'ip'   => (string) $ip,
		);
	}

	/**
	 * Whether the request with this nonce was pinned; forgets the request either way.
	 *
	 * @param string $nonce Request nonce.
	 * @return bool
	 */
	public static function take( $nonce ) {
		$nonce  = (string) $nonce;
		$pinned = ! empty( self::$pinned[ $nonce ] );
		unset( self::$armed[ $nonce ], self::$pinned[ $nonce ] );
		return $pinned;
	}

	/**
	 * Hook: http_api_curl. Pins one of the plugin's own requests to the address the guard checked.
	 *
	 * CURLOPT_RESOLVE on core's own cURL handle, inside core's http_api_curl hook, is the only way to
	 * connect to the address the guard checked while the certificate is still verified for the host
	 * name (DNS rebinding, threat model §7.3); requests the plugin did not arm are untouched.
	 *
	 * Acts only when the request carries X-SGSP-Version and the nonce Remote_Client armed for it,
	 * and only for the armed host on port 443; every other request is left exactly as it is.
	 *
	 * @param mixed  $handle Core's cURL handle (passed by reference by core).
	 * @param mixed  $args   Request arguments as WP_Http parsed them.
	 * @param string $url    Request URL.
	 * @return void
	 */
	public static function pin_handle( $handle, $args = array(), $url = '' ) {
		if ( ! is_array( $args ) || empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			return;
		}

		$headers = array_change_key_case( $args['headers'], CASE_LOWER );
		if ( ! isset( $headers['x-sgsp-version'], $headers['x-sgsp-nonce'] ) || ! is_string( $headers['x-sgsp-nonce'] ) ) {
			return;
		}

		$nonce = $headers['x-sgsp-nonce'];
		if ( ! isset( self::$armed[ $nonce ] ) || ! defined( 'CURLOPT_RESOLVE' ) || ( ! is_resource( $handle ) && ! is_object( $handle ) ) ) {
			return;
		}

		$armed = self::$armed[ $nonce ];
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) || strtolower( $parts['host'] ) !== $armed['host'] || ( isset( $parts['port'] ) && self::PORT !== (int) $parts['port'] ) ) {
			return;
		}

		$pinned = curl_setopt( $handle, CURLOPT_RESOLVE, array( $armed['host'] . ':' . self::PORT . ':' . $armed['ip'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- pins the address checked by Outbound_Guard on core's own cURL handle (DNS rebinding)

		if ( $pinned ) {
			self::$pinned[ $nonce ] = true;
		}
	}

	/**
	 * Whether a cached check may be reused for this host now.
	 *
	 * @param mixed  $pin  Cached check.
	 * @param string $host Host.
	 * @return bool
	 */
	private static function pin_is_valid( $pin, $host ) {
		if ( ! is_array( $pin ) || ! isset( $pin['host'], $pin['ip'], $pin['checked_at'] ) || $pin['host'] !== $host ) {
			return false;
		}
		$age = time() - (int) $pin['checked_at'];
		if ( $age < 0 || $age >= self::PIN_TTL ) {
			return false;
		}
		// The address passed resolve() when it was cached (public, or allowed by core's filter
		// at that time); within the run it is used as checked, without asking DNS again.
		return is_string( $pin['ip'] ) && false !== filter_var( $pin['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 );
	}

	/**
	 * A strict dotted-quad IPv4 address (no octal, hex or short forms).
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_ipv4_literal( $host ) {
		return 1 === preg_match( '/\A(?:(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\.){3}(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9]?[0-9])\z/', $host );
	}

	/**
	 * A host name: labels of 1–63 letters, digits and inner hyphens, at most 253 characters,
	 * and a last label that is not all digits (so no spelling of an address passes as a name).
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_hostname( $host ) {
		if ( strlen( $host ) > 253 || 1 !== preg_match( '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host ) ) {
			return false;
		}
		$labels = explode( '.', $host );
		return ! ctype_digit( (string) end( $labels ) );
	}

	/**
	 * An address refusal.
	 *
	 * @param string $reason  Reason code.
	 * @param string $host    Host, when known.
	 * @param string $address Refused address or port, when known.
	 * @return WP_Error
	 */
	private static function refused( $reason, $host = '', $address = '' ) {
		$messages = array(
			'invalid_url'      => __( 'The address in the pairing key is not a valid URL.', 'keepmerge-push-staging-to-live' ),
			'scheme'           => __( 'The live site\'s address does not use HTTPS. Keepmerge connects over HTTPS only.', 'keepmerge-push-staging-to-live' ),
			'userinfo'         => __( 'The live site\'s address contains a user name or password. Use the key exactly as the live site shows it.', 'keepmerge-push-staging-to-live' ),
			'fragment'         => __( 'The live site\'s address contains a fragment (#). Use the key exactly as the live site shows it.', 'keepmerge-push-staging-to-live' ),
			/* translators: %s: port number. */
			'port'             => sprintf( __( 'The live site\'s address uses port %s. Keepmerge connects to the standard HTTPS port 443 only.', 'keepmerge-push-staging-to-live' ), $address ),
			'ipv6_unsupported' => __( 'The live site\'s address is an IPv6 address. Keepmerge connects over IPv4 only in this version (as WordPress checks addresses over IPv4); give the live site a host name with an IPv4 address.', 'keepmerge-push-staging-to-live' ),
			'invalid_host'     => __( 'The live site\'s host name is not valid.', 'keepmerge-push-staging-to-live' ),
			'own_host'         => __( 'The pairing key points to this site itself. Create the key on the live site and paste it here, on the staging site.', 'keepmerge-push-staging-to-live' ),
			/* translators: %s: host name. */
			'no_ipv4'          => sprintf( __( '%s has no IPv4 address. Keepmerge connects over IPv4 only in this version (as WordPress checks addresses over IPv4); IPv6-only hosts are not supported.', 'keepmerge-push-staging-to-live' ), $host ),
			/* translators: 1: host name, 2: IP address and range. */
			'private_address'  => sprintf( __( '%1$s resolves to %2$s, a private, local or reserved address. Keepmerge connects only to public addresses.', 'keepmerge-push-staging-to-live' ), $host, $address ),
			'core_url_check'   => __( 'WordPress\'s own address check refused the live site\'s address (it resolved to a private, local or reserved address).', 'keepmerge-push-staging-to-live' ),
		);

		return new WP_Error(
			'address_refused',
			isset( $messages[ $reason ] ) ? $messages[ $reason ] : $messages['invalid_url'],
			array(
				'status'  => 400,
				'reason'  => $reason,
				'host'    => $host,
				'address' => $address,
			)
		);
	}

	/**
	 * A refusal because the connection could not be pinned.
	 *
	 * @param string $reason no_curl or proxy.
	 * @return WP_Error
	 */
	private static function unpinned( $reason ) {
		$message = 'proxy' === $reason
			? __( 'This site sends its HTTP requests through a proxy (WP_PROXY_HOST), so Keepmerge cannot make sure the connection goes to the address it checked. Exclude the live site from the proxy (WP_PROXY_BYPASS_HOSTS) to connect.', 'keepmerge-push-staging-to-live' )
			: __( 'This server lacks PHP\'s cURL extension. Keepmerge needs it to make sure each connection goes to the address it checked (protection against DNS rebinding), so it does not connect without it. Ask your host to enable the cURL extension.', 'keepmerge-push-staging-to-live' );

		return new WP_Error(
			'transport_unpinned',
			$message,
			array(
				'status' => 400,
				'reason' => $reason,
			)
		);
	}
}
