<?php
/**
 * The source-code halves of S-20 (V10), S-34 (V9) and S-71, as tests. They read the plugin
 * folder the suite loads.
 *
 * @package SoftGlaze\SafePush\Tests
 */

/**
 * @group security
 */
class SecurityStaticTest extends WP_UnitTestCase {

	/**
	 * Lines of plugin files under $dir matching $pattern, as "relative/path:line".
	 *
	 * @param string   $dir        Folder inside the plugin.
	 * @param string   $pattern    PCRE.
	 * @param string[] $extensions File extensions to read.
	 * @return string[]
	 */
	private static function grep( $dir, $pattern, array $extensions = array( 'php' ) ) {
		$root = dirname( SOFTGLAZE_SP_FILE );
		$hits = array();
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$path = wp_normalize_path( $file->getPathname() );
			if ( false !== strpos( $path, '/node_modules/' ) || ! in_array( strtolower( $file->getExtension() ), $extensions, true ) ) {
				continue;
			}
			foreach ( file( $path ) as $n => $line ) {
				// Code only: docblock and line comments may name what the code never calls.
				if ( 'php' === strtolower( $file->getExtension() ) && 1 === preg_match( '#\A\s*(\*|/\*|//)#', $line ) ) {
					continue;
				}
				if ( 1 === preg_match( $pattern, $line ) ) {
					$hits[] = substr( $path, strlen( wp_normalize_path( $root ) ) + 1 ) . ':' . ( $n + 1 );
				}
			}
		}
		sort( $hits );
		return $hits;
	}

	private static function files( array $hits ) {
		$files = array();
		foreach ( $hits as $hit ) {
			$files[] = substr( $hit, 0, strrpos( $hit, ':' ) );
		}
		return array_values( array_unique( $files ) );
	}

	/**
	 * S-20 / V10: core's HTTP API is called in one file, Remote_Client, which only the source side
	 * builds (Source_Pairing); no other transport (cURL, sockets, streams) is used to reach a URL.
	 */
	public function test_s20_v10_only_the_source_client_calls_the_http_api() {
		$this->assertSame( array( 'includes/transfer/class-remote-client.php' ), self::files( self::grep( 'includes', '/\bwp_(safe_)?remote_\w+\s*\(|\bWP_Http\b|_wp_http_get_object|\bdownload_url\s*\(/' ) ) );
		$this->assertSame( array( 'includes/class-source-pairing.php' ), self::files( self::grep( 'includes', '/new\s+Remote_Client\s*\(/' ) ) );
		$this->assertSame( array(), self::grep( 'includes', '/\b(fsockopen|pfsockopen|stream_socket_client|curl_exec|curl_multi_exec|file_get_contents\s*\(\s*[\'"]https?:)/' ) );
		// The one cURL call is the pin on core's own handle (CURLOPT_RESOLVE in http_api_curl).
		$this->assertSame( array( 'includes/transfer/class-outbound-guard.php' ), self::files( self::grep( 'includes', '/\bcurl_\w+\s*\(/' ) ) );
	}

	/**
	 * S-34 / V9: order, customer, user and comment tables and APIs appear only in the WooCommerce
	 * guard (the denylists) and the receiver user class (the live admin's own pairing action).
	 */
	public function test_s34_v9_order_customer_user_and_comment_data_is_out_of_reach() {
		$pattern = '/wc_orders|wc_order_|woocommerce_order_items|woocommerce_sessions|woocommerce_payment_tokens|downloadable_product_permissions|wc_customer_lookup|shop_order|shop_coupon|shop_subscription|->users|->usermeta|->comments|->commentmeta|wp_insert_user|wp_update_user|update_user_meta|wp_insert_comment/';
		$files   = self::files( self::grep( 'includes', $pattern ) );
		sort( $files );
		$this->assertSame( array( 'includes/class-receiver-user.php', 'includes/woo/class-guard.php' ), $files );
	}

	/**
	 * S-71 (code half): the admin screens render text, never HTML. No React escape hatch
	 * (dangerouslySetInnerHTML), no innerHTML/outerHTML/insertAdjacentHTML/document.write in the
	 * shipped source or the build. The browser half (a staging title with an onerror payload in the
	 * plan and the diff drawer, no dialog) is a browser test outside this suite.
	 */
	public function test_s71_admin_screens_never_render_strings_as_html() {
		$pattern = '/dangerouslySetInnerHTML|\.innerHTML\s*=|\.outerHTML\s*=|insertAdjacentHTML|document\.write\s*\(/';
		$this->assertSame( array(), self::grep( 'src', $pattern, array( 'js', 'jsx' ) ) );
		$this->assertSame( array(), self::grep( 'build', '/dangerouslySetInnerHTML|insertAdjacentHTML|document\.write\s*\(/', array( 'js' ) ) );
	}
}
