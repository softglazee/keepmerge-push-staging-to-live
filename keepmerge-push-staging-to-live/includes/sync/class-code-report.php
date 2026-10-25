<?php
/**
 * Code report: versions and file hashes of themes and plugins, compared, never copied.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync;

use SoftGlaze\SafePush\Limits;
use SoftGlaze\SafePush\Source_Pairing;
use SoftGlaze\SafePush\Transfer\Remote_Client;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blueprint §6.11 and F8. Code is reported, never pushed: no file content travels in either
 * direction, nothing is installed, updated, activated or switched, and nothing is written into a
 * plugin or theme folder. This is the only class that reads plugin and theme folders, and it only
 * lists them and hashes files with sha1_file().
 *
 * Live (POST /remote/code-manifest, R7, answered only while "Allow code report requests" is on):
 * - without a component: the inventory (WordPress and PHP versions, the active theme and its
 *   parent, installed themes and plugins with versions and active state, must-use plugin and
 *   drop-in file names, and the components whose files can be compared: the active theme, its
 *   parent and each active plugin);
 * - with a component from that list and a cursor: one page of at most 500 files (path, size,
 *   SHA-1; files over 5 MB by size only), at most 20,000 files per component.
 *
 * Source: asks for live's inventory and pages, compares them with its own files and keeps the
 * result in the softglaze_sp_code_report option (one record per pairing, not autoloaded).
 */
final class Code_Report {

	const ROUTE = '/keepmerge/v1/remote/code-manifest';

	const OPTION = 'softglaze_sp_code_report';

	const PAGE_SIZE = 500;

	const MAX_FILES = 20000;

	const MAX_HASH_BYTES = 5242880;

	/**
	 * Paths kept per component in the source's report.
	 */
	const MAX_PATHS = 100;

	const COMPONENT_PATTERN = '#\A(theme|plugin):([A-Za-z0-9._-]{1,100}(?:/[A-Za-z0-9._-]{1,100})?)\z#';

	// ------------------------------------------------------------------------------------------
	// Live: answering R7.
	// ------------------------------------------------------------------------------------------

	/**
	 * Answers POST /remote/code-manifest (the permission chain has checked the setting).
	 *
	 * @param array $body Decoded body: component (optional), cursor (int).
	 * @return array|WP_Error
	 */
	public static function answer( array $body ) {
		$unknown = array_diff( array_keys( $body ), array( 'component', 'cursor' ) );
		if ( ! empty( $unknown ) ) {
			return self::invalid( 'invalid_body' );
		}
		if ( ! isset( $body['component'] ) ) {
			if ( isset( $body['cursor'] ) ) {
				return self::invalid( 'invalid_body' );
			}
			return array( 'inventory' => self::inventory_here() );
		}

		$component = $body['component'];
		if ( ! is_string( $component ) || 1 !== preg_match( self::COMPONENT_PATTERN, $component ) || false !== strpos( $component, '..' ) ) {
			return self::invalid( 'invalid_component' );
		}
		$components = self::components();
		if ( ! isset( $components[ $component ] ) ) {
			return self::invalid( 'invalid_component' );
		}
		$cursor = isset( $body['cursor'] ) ? $body['cursor'] : 0;
		if ( ! is_int( $cursor ) || $cursor < 0 ) {
			return self::invalid( 'invalid_cursor' );
		}

		$info     = $components[ $component ];
		$deadline = microtime( true ) + max( 1, Limits::time_budget() - 2 );
		$files    = self::files( $info, microtime( true ) + max( 1, ( Limits::time_budget() - 2 ) / 2 ) );
		$total    = count( $files['paths'] );
		if ( $files['truncated'] ) {
			// More than 20,000 files, or more than one request can list: reported as too large,
			// so no page ever covers an arbitrary part of the component.
			return array(
				'component'   => $component,
				'version'     => $info['version'],
				'total'       => $total,
				'truncated'   => true,
				'cursor'      => 0,
				'next_cursor' => null,
				'files'       => array(),
			);
		}
		if ( $cursor > 0 && $cursor >= $total ) {
			return self::invalid( 'cursor_out_of_range' );
		}

		$out  = array();
		$end  = min( $total, $cursor + self::PAGE_SIZE );
		$next = $cursor;
		for ( $i = $cursor; $i < $end; $i++ ) {
			if ( $i > $cursor && microtime( true ) > $deadline ) {
				break;
			}
			$out[] = self::file_entry( $files['root'], $files['paths'][ $i ] );
			$next  = $i + 1;
		}

		return array(
			'component'   => $component,
			'version'     => $info['version'],
			'total'       => $total,
			'truncated'   => $files['truncated'],
			'cursor'      => $cursor,
			'next_cursor' => $next < $total ? $next : null,
			'files'       => $out,
		);
	}

	/**
	 * This site's inventory: versions and names only.
	 *
	 * @return array
	 */
	public static function inventory_here() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$theme   = wp_get_theme();
		$parent  = $theme->parent();
		$themes  = array();
		$plugins = array();

		foreach ( wp_get_themes() as $stylesheet => $installed ) {
			$themes[] = array(
				'slug'    => (string) $stylesheet,
				'name'    => wp_strip_all_tags( (string) $installed->get( 'Name' ) ),
				'version' => (string) $installed->get( 'Version' ),
			);
		}

		$active = (array) get_option( 'active_plugins', array() );
		foreach ( get_plugins() as $basename => $data ) {
			$plugins[] = array(
				'slug'    => (string) $basename,
				'name'    => wp_strip_all_tags( (string) $data['Name'] ),
				'version' => (string) $data['Version'],
				'active'  => in_array( $basename, $active, true ),
			);
		}

		$components = array();
		foreach ( self::components() as $id => $info ) {
			$components[] = array(
				'id'      => $id,
				'type'    => $info['type'],
				'slug'    => $info['slug'],
				'name'    => $info['name'],
				'version' => $info['version'],
			);
		}

		return array(
			'wordpress'  => (string) get_bloginfo( 'version' ),
			'php'        => PHP_VERSION,
			'theme'      => array(
				'slug'    => (string) $theme->get_stylesheet(),
				'version' => (string) $theme->get( 'Version' ),
				'parent'  => $parent ? (string) $parent->get_stylesheet() : '',
			),
			'themes'     => $themes,
			'plugins'    => $plugins,
			'mu_plugins' => array_values( array_map( 'strval', array_keys( get_mu_plugins() ) ) ),
			'dropins'    => array_values( array_map( 'strval', array_keys( get_dropins() ) ) ),
			'components' => $components,
		);
	}

	/**
	 * The components whose files can be compared on this site: the active theme, its parent and
	 * each active plugin, keyed by ID ("theme:" plus the stylesheet, "plugin:" plus the basename).
	 *
	 * @return array<string,array{type:string,slug:string,name:string,version:string,root:string,single:string}>
	 */
	public static function components() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out   = array();
		$theme = wp_get_theme();
		foreach ( array_filter( array( $theme, $theme->parent() ) ) as $item ) {
			$slug = (string) $item->get_stylesheet();
			if ( '' === $slug || ! $item->exists() ) {
				continue;
			}
			$out[ 'theme:' . $slug ] = array(
				'type'    => 'theme',
				'slug'    => $slug,
				'name'    => wp_strip_all_tags( (string) $item->get( 'Name' ) ),
				'version' => (string) $item->get( 'Version' ),
				'root'    => trailingslashit( get_theme_root( $slug ) ) . $slug,
				'single'  => '',
			);
		}

		$plugins = get_plugins();
		foreach ( (array) get_option( 'active_plugins', array() ) as $basename ) {
			if ( ! is_string( $basename ) || ! isset( $plugins[ $basename ] ) || 1 !== preg_match( self::COMPONENT_PATTERN, 'plugin:' . $basename ) ) {
				continue;
			}
			$dir                          = dirname( $basename );
			$out[ 'plugin:' . $basename ] = array(
				'type'    => 'plugin',
				'slug'    => $basename,
				'name'    => wp_strip_all_tags( (string) $plugins[ $basename ]['Name'] ),
				'version' => (string) $plugins[ $basename ]['Version'],
				'root'    => '.' === $dir ? WP_PLUGIN_DIR : WP_PLUGIN_DIR . '/' . $dir,
				'single'  => '.' === $dir ? $basename : '',
			);
		}

		return $out;
	}

	/**
	 * The files of a component, sorted by path (byte order). Symbolic links to folders are not
	 * followed and files that resolve outside the component are left out. A component with more
	 * than MAX_FILES files, or one that cannot be listed before the deadline, is "truncated".
	 *
	 * @param array      $info     Component.
	 * @param float|null $deadline Stop listing at this time (microtime).
	 * @return array{root:string,paths:string[],truncated:bool}
	 */
	public static function files( array $info, $deadline = null ) {
		$root = realpath( $info['root'] );
		if ( false === $root || ! is_dir( $root ) ) {
			return array(
				'root'      => '',
				'paths'     => array(),
				'truncated' => false,
			);
		}
		$root = str_replace( '\\', '/', $root );

		if ( '' !== $info['single'] ) {
			$file = $root . '/' . $info['single'];
			return array(
				'root'      => $root,
				'paths'     => is_file( $file ) ? array( (string) $info['single'] ) : array(),
				'truncated' => false,
			);
		}

		$paths     = array();
		$truncated = false;
		$iterator  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO ),
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ( $iterator as $file ) {
			if ( null !== $deadline && microtime( true ) > $deadline ) {
				$truncated = true;
				break;
			}
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() ) {
				continue;
			}
			// Folders reached through links are not entered; a linked file must resolve inside.
			if ( $file->isLink() ) {
				$real = realpath( $file->getPathname() );
				if ( false === $real || 0 !== strpos( str_replace( '\\', '/', $real ), $root . '/' ) ) {
					continue;
				}
			}
			$relative = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $root ) + 1 );
			if ( count( $paths ) >= self::MAX_FILES ) {
				$truncated = true;
				break;
			}
			$paths[] = $relative;
		}
		sort( $paths, SORT_STRING );

		return array(
			'root'      => $root,
			'paths'     => $paths,
			'truncated' => $truncated,
		);
	}

	/**
	 * One file as the report carries it: path, size and SHA-1 (null over 5 MB). Never content.
	 *
	 * @param string $root     Component root.
	 * @param string $relative Relative path.
	 * @return array{path:string,size:int,sha1:string|null}
	 */
	public static function file_entry( $root, $relative ) {
		$file = $root . '/' . $relative;
		$size = is_file( $file ) ? (int) filesize( $file ) : -1;
		$sha1 = null;
		if ( $size >= 0 && $size <= self::MAX_HASH_BYTES ) {
			$hash = sha1_file( $file );
			$sha1 = is_string( $hash ) ? $hash : null;
		}
		return array(
			'path' => (string) $relative,
			'size' => $size,
			'sha1' => $sha1,
		);
	}

	// ------------------------------------------------------------------------------------------
	// Source: asking and comparing.
	// ------------------------------------------------------------------------------------------

	/**
	 * Asks live for its inventory and compares versions (the plan's "code" stage, and the first
	 * step of a report run). Live refusing (code_report_disabled) is recorded, not an error.
	 *
	 * @param string $pairing_uuid Pairing UUID.
	 * @param mixed  $client       Client with post().
	 * @return array The record.
	 */
	public static function inventory( $pairing_uuid, $client ) {
		$record = self::empty_record();

		$response = $client->post( self::ROUTE, array(), array( 'retries' => 1 ) );
		if ( is_wp_error( $response ) ) {
			$code                 = $response->get_error_code();
			$record['state']      = 'code_report_disabled' === $code ? 'disabled' : 'error';
			$record['error']      = array(
				'code'    => $code,
				'message' => $response->get_error_message(),
			);
			$record['checked_at'] = time();
			self::save( $pairing_uuid, $record );
			return $record;
		}

		$live = self::clean_inventory( isset( $response['data']['inventory'] ) ? $response['data']['inventory'] : null );
		if ( null === $live ) {
			$record['state']      = 'error';
			$record['error']      = array(
				'code'    => 'response_invalid',
				'message' => Remote_Client::message( 'response_invalid' ),
			);
			$record['checked_at'] = time();
			self::save( $pairing_uuid, $record );
			return $record;
		}

		$local = self::inventory_here();
		$here  = self::components();

		$record['state']      = 'inventory';
		$record['checked_at'] = time();
		$record['live']       = $live;
		$record['local']      = array(
			'wordpress' => $local['wordpress'],
			'php'       => $local['php'],
			'theme'     => $local['theme'],
		);
		$record['diff']       = self::compare_inventories( $local, $live );

		foreach ( $live['components'] as $component ) {
			$local_info = self::local_component( $component, $here );
			if ( null === $local_info ) {
				$record['files']['not_comparable'][] = array(
					'id'     => $component['id'],
					'name'   => $component['name'],
					'reason' => 'not_installed_here',
				);
				continue;
			}
			$record['files']['queue'][] = $component['id'];
		}

		self::save( $pairing_uuid, $record );
		return $record;
	}

	/**
	 * One time-boxed step of the report for the Code tab: the inventory first (again when live
	 * refused before), then pages of file hashes, compared with this site's files.
	 *
	 * @param string     $pairing_uuid Pairing UUID.
	 * @param bool       $restart      Start again from the inventory.
	 * @param mixed|null $client       Client (tests); the pairing's own by default.
	 * @return array|WP_Error The report as presented.
	 */
	public static function step( $pairing_uuid, $restart = false, $client = null ) {
		$row = null;
		if ( null === $client ) {
			$active = Source_Pairing::active_client( $pairing_uuid );
			if ( is_wp_error( $active ) ) {
				return $active;
			}
			$row    = $active['row'];
			$client = $active['client'];
		}

		$record   = self::record( $pairing_uuid );
		$deadline = microtime( true ) + max( 3, Limits::time_budget() - 3 );

		if ( $restart || in_array( $record['state'], array( 'not_run', 'disabled', 'error' ), true ) ) {
			$record = self::inventory( $pairing_uuid, $client );
		}

		$error = null;
		while ( in_array( $record['state'], array( 'inventory', 'running' ), true ) && microtime( true ) < $deadline ) {
			$record['state'] = 'running';
			$error           = self::compare_page( $record, $client );
			if ( null !== $error ) {
				$record['state'] = 'error';
				$record['error'] = array(
					'code'    => $error->get_error_code(),
					'message' => $error->get_error_message(),
				);
				break;
			}
			if ( empty( $record['files']['queue'] ) && null === $record['files']['current'] ) {
				$record['state'] = 'done';
			}
		}

		self::save( $pairing_uuid, $record );
		if ( null !== $row && $client instanceof Remote_Client ) {
			Source_Pairing::after_call( $row, $client, $error );
		}

		return self::present( $record );
	}

	/**
	 * Requests and compares one page of the current component.
	 *
	 * @param array $record Record (changed).
	 * @param mixed $client Client.
	 * @return WP_Error|null
	 */
	private static function compare_page( array &$record, $client ) {
		if ( null === $record['files']['current'] ) {
			$id = array_shift( $record['files']['queue'] );
			if ( null === $id ) {
				return null;
			}
			$record['files']['current'] = array(
				'id'     => $id,
				'cursor' => 0,
				'local'  => 0,
			);
		}
		$current = $record['files']['current'];
		$id      = (string) $current['id'];

		$component = null;
		foreach ( $record['live']['components'] as $candidate ) {
			if ( $candidate['id'] === $id ) {
				$component = $candidate;
			}
		}
		$local_info = null === $component ? null : self::local_component( $component, self::components() );
		if ( null === $local_info ) {
			$record['files']['not_comparable'][] = array(
				'id'     => $id,
				'name'   => null === $component ? $id : $component['name'],
				'reason' => 'not_installed_here',
			);
			$record['files']['current']          = null;
			return null;
		}

		$response = $client->post(
			self::ROUTE,
			array(
				'component' => $id,
				'cursor'    => (int) $current['cursor'],
			),
			array(
				// Live lists and hashes inside its own time budget (at most 20 s): wait for it,
				// whatever is left of this step's budget (waiting on the network uses no CPU time).
				'budget'  => 28,
				'timeout' => 28,
				'retries' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			if ( ! in_array( $response->get_error_code(), array( 'network_error', 'server_error', 'response_invalid' ), true ) ) {
				return $response;
			}
			// A component live could not answer for (too slow, or an error there) is reported as
			// not comparable, and the report goes on with the next one.
			$record['files']['not_comparable'][] = array(
				'id'     => $id,
				'name'   => $component['name'],
				'reason' => 'failed',
			);
			unset( $record['files']['results'][ $id ] );
			$record['files']['current'] = null;
			return null;
		}
		$data  = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$files = self::clean_files( isset( $data['files'] ) ? $data['files'] : null );
		if ( null === $files ) {
			return new WP_Error( 'response_invalid', Remote_Client::message( 'response_invalid' ) );
		}

		$local_files = self::files( $local_info, microtime( true ) + max( 1, ( Limits::time_budget() - 2 ) / 2 ) );
		if ( ! empty( $data['truncated'] ) || $local_files['truncated'] ) {
			$record['files']['not_comparable'][] = array(
				'id'     => $id,
				'name'   => $component['name'],
				'reason' => 'too_many_files',
			);
			unset( $record['files']['results'][ $id ] );
			$record['files']['current'] = null;
			return null;
		}

		if ( ! isset( $record['files']['results'][ $id ] ) ) {
			$record['files']['results'][ $id ] = array(
				'id'            => $id,
				'name'          => $component['name'],
				'type'          => $component['type'],
				'live_version'  => $component['version'],
				'local_version' => $local_info['version'],
				'compared'      => 0,
				'differ'        => 0,
				'only_live'     => 0,
				'only_here'     => 0,
				'paths'         => array(),
				'more_paths'    => 0,
				'truncated'     => ! empty( $data['truncated'] ),
				'done'          => false,
			);
		}
		$result = $record['files']['results'][ $id ];
		$local  = $local_files['paths'];
		$count  = count( $local );
		$index  = (int) $current['local'];

		foreach ( $files as $file ) {
			while ( $index < $count && strcmp( $local[ $index ], $file['path'] ) < 0 ) {
				self::note( $result, $local[ $index ], 'only_here' );
				++$index;
			}
			if ( $index < $count && $local[ $index ] === $file['path'] ) {
				$mine = self::file_entry( $local_files['root'], $local[ $index ] );
				++$result['compared'];
				$same = $mine['size'] === $file['size'] && ( null === $mine['sha1'] || null === $file['sha1'] ? true : hash_equals( $file['sha1'], $mine['sha1'] ) );
				if ( ! $same ) {
					self::note( $result, $file['path'], 'differ' );
				}
				++$index;
			} else {
				self::note( $result, $file['path'], 'only_live' );
			}
		}

		$next = isset( $data['next_cursor'] ) && is_int( $data['next_cursor'] ) && $data['next_cursor'] > (int) $current['cursor'] ? $data['next_cursor'] : null;
		if ( null === $next ) {
			while ( $index < $count ) {
				self::note( $result, $local[ $index ], 'only_here' );
				++$index;
			}
			$result['done']             = true;
			$record['files']['current'] = null;
		} else {
			$record['files']['current']['cursor'] = $next;
			$record['files']['current']['local']  = $index;
		}
		$record['files']['results'][ $id ] = $result;

		return null;
	}

	/**
	 * Counts a file difference and keeps its path (up to MAX_PATHS).
	 *
	 * @param array  $result Component result (changed).
	 * @param string $path   Path.
	 * @param string $state  differ, only_live or only_here.
	 * @return void
	 */
	private static function note( array &$result, $path, $state ) {
		++$result[ $state ];
		if ( count( $result['paths'] ) < self::MAX_PATHS ) {
			$result['paths'][] = array(
				'path'  => (string) $path,
				'state' => $state,
			);
		} else {
			++$result['more_paths'];
		}
	}

	/**
	 * The stored record of a pairing (an empty one when none).
	 *
	 * @param string $pairing_uuid Pairing UUID.
	 * @return array
	 */
	public static function record( $pairing_uuid ) {
		$all  = get_option( self::OPTION, array() );
		$uuid = strtolower( (string) $pairing_uuid );
		return is_array( $all ) && isset( $all[ $uuid ] ) && is_array( $all[ $uuid ] ) ? array_merge( self::empty_record(), $all[ $uuid ] ) : self::empty_record();
	}

	/**
	 * The report as the Code tab shows it.
	 *
	 * @param array $record Record.
	 * @return array
	 */
	public static function present( array $record ) {
		$files = $record['files'];
		$done  = 0;
		foreach ( $files['results'] as $result ) {
			if ( ! empty( $result['done'] ) ) {
				++$done;
			}
		}
		$total = count( $files['results'] ) + count( $files['queue'] ) + ( null === $files['current'] || isset( $files['results'][ $files['current']['id'] ] ) ? 0 : 1 );

		return array(
			'state'          => (string) $record['state'],
			'checked_at'     => $record['checked_at'],
			'error'          => $record['error'],
			'live'           => null === $record['live'] ? null : array(
				'wordpress' => $record['live']['wordpress'],
				'php'       => $record['live']['php'],
				'theme'     => $record['live']['theme'],
			),
			'local'          => $record['local'],
			'diff'           => $record['diff'],
			'files'          => array_values( $files['results'] ),
			'not_comparable' => $files['not_comparable'],
			'progress'       => array(
				'done'  => $done,
				'total' => $total,
			),
		);
	}

	/**
	 * What the plan summary carries about the report.
	 *
	 * @param string $pairing_uuid Pairing UUID.
	 * @return array{state:string,missing_on_live:int,extra_on_live:int,version_differs:int}
	 */
	public static function summary( $pairing_uuid ) {
		$record = self::record( $pairing_uuid );
		$diff   = is_array( $record['diff'] ) ? $record['diff'] : array();
		return array(
			'state'           => (string) $record['state'],
			'missing_on_live' => isset( $diff['missing_on_live'] ) ? count( $diff['missing_on_live'] ) : 0,
			'extra_on_live'   => isset( $diff['extra_on_live'] ) ? count( $diff['extra_on_live'] ) : 0,
			'version_differs' => isset( $diff['version_differs'] ) ? count( $diff['version_differs'] ) : 0,
		);
	}

	/**
	 * Forgets a pairing's report (test tools).
	 *
	 * @param string $pairing_uuid Pairing UUID.
	 * @return void
	 */
	public static function forget( $pairing_uuid ) {
		$all = get_option( self::OPTION, array() );
		if ( is_array( $all ) && isset( $all[ strtolower( (string) $pairing_uuid ) ] ) ) {
			unset( $all[ strtolower( (string) $pairing_uuid ) ] );
			update_option( self::OPTION, $all, false );
		}
	}

	/**
	 * Differences between this site's inventory and live's.
	 *
	 * @param array $local This site.
	 * @param array $live  Live.
	 * @return array
	 */
	public static function compare_inventories( array $local, array $live ) {
		$diff = array(
			'core'            => array(
				'wordpress' => array(
					'here' => $local['wordpress'],
					'live' => $live['wordpress'],
				),
				'php'       => array(
					'here' => $local['php'],
					'live' => $live['php'],
				),
			),
			'missing_on_live' => array(),
			'extra_on_live'   => array(),
			'version_differs' => array(),
			'active_differs'  => array(),
			'mu_plugins'      => array(
				'missing_on_live' => array_values( array_diff( $local['mu_plugins'], $live['mu_plugins'] ) ),
				'extra_on_live'   => array_values( array_diff( $live['mu_plugins'], $local['mu_plugins'] ) ),
			),
			'dropins'         => array(
				'missing_on_live' => array_values( array_diff( $local['dropins'], $live['dropins'] ) ),
				'extra_on_live'   => array_values( array_diff( $live['dropins'], $local['dropins'] ) ),
			),
			'active_theme'    => array(
				'here' => $local['theme'],
				'live' => $live['theme'],
			),
		);

		foreach ( array(
			'theme'  => 'themes',
			'plugin' => 'plugins',
		) as $type => $list ) {
			$mine   = array();
			$theirs = array();
			foreach ( $local[ $list ] as $entry ) {
				$mine[ $entry['slug'] ] = $entry;
			}
			foreach ( $live[ $list ] as $entry ) {
				$theirs[ $entry['slug'] ] = $entry;
			}
			foreach ( $mine as $slug => $entry ) {
				if ( ! isset( $theirs[ $slug ] ) ) {
					$diff['missing_on_live'][] = self::component_line( $type, $entry, $entry['version'], '' );
					continue;
				}
				if ( $entry['version'] !== $theirs[ $slug ]['version'] ) {
					$diff['version_differs'][] = self::component_line( $type, $entry, $entry['version'], $theirs[ $slug ]['version'] );
				}
				if ( 'plugin' === $type && $entry['active'] !== $theirs[ $slug ]['active'] ) {
					$line                     = self::component_line( $type, $entry, $entry['version'], $theirs[ $slug ]['version'] );
					$line['active_here']      = (bool) $entry['active'];
					$line['active_live']      = (bool) $theirs[ $slug ]['active'];
					$diff['active_differs'][] = $line;
				}
			}
			foreach ( $theirs as $slug => $entry ) {
				if ( ! isset( $mine[ $slug ] ) ) {
					$diff['extra_on_live'][] = self::component_line( $type, $entry, '', $entry['version'] );
				}
			}
		}

		return $diff;
	}

	/**
	 * One line of an inventory difference.
	 *
	 * @param string $type    theme or plugin.
	 * @param array  $entry   Entry.
	 * @param string $here    Version here.
	 * @param string $live    Version on live.
	 * @return array
	 */
	private static function component_line( $type, array $entry, $here, $live ) {
		return array(
			'type'   => $type,
			'slug'   => (string) $entry['slug'],
			'name'   => (string) $entry['name'],
			'here'   => (string) $here,
			'live'   => (string) $live,
			'active' => isset( $entry['active'] ) ? (bool) $entry['active'] : null,
		);
	}

	/**
	 * This site's copy of one of live's components (same type and slug), or null.
	 *
	 * @param array $component Live's component.
	 * @param array $here      This site's comparable components (active ones).
	 * @return array|null
	 */
	private static function local_component( array $component, array $here ) {
		if ( isset( $here[ $component['id'] ] ) ) {
			return $here[ $component['id'] ];
		}
		// Installed here but not active: still comparable.
		if ( 'theme' === $component['type'] ) {
			$theme = wp_get_theme( $component['slug'] );
			if ( ! $theme->exists() ) {
				return null;
			}
			return array(
				'type'    => 'theme',
				'slug'    => $component['slug'],
				'name'    => wp_strip_all_tags( (string) $theme->get( 'Name' ) ),
				'version' => (string) $theme->get( 'Version' ),
				'root'    => trailingslashit( get_theme_root( $component['slug'] ) ) . $component['slug'],
				'single'  => '',
			);
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $component['slug'] ] ) ) {
			return null;
		}
		$dir = dirname( $component['slug'] );
		return array(
			'type'    => 'plugin',
			'slug'    => $component['slug'],
			'name'    => wp_strip_all_tags( (string) $plugins[ $component['slug'] ]['Name'] ),
			'version' => (string) $plugins[ $component['slug'] ]['Version'],
			'root'    => '.' === $dir ? WP_PLUGIN_DIR : WP_PLUGIN_DIR . '/' . $dir,
			'single'  => '.' === $dir ? $component['slug'] : '',
		);
	}

	/**
	 * Live's inventory, checked field by field; null when malformed.
	 *
	 * @param mixed $raw Raw.
	 * @return array|null
	 */
	private static function clean_inventory( $raw ) {
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$text = static function ( $value, $max = 191 ) {
			return is_string( $value ) ? substr( sanitize_text_field( $value ), 0, $max ) : '';
		};

		$out = array(
			'wordpress'  => $text( isset( $raw['wordpress'] ) ? $raw['wordpress'] : '', 20 ),
			'php'        => $text( isset( $raw['php'] ) ? $raw['php'] : '', 40 ),
			'theme'      => array(
				'slug'    => $text( isset( $raw['theme']['slug'] ) ? $raw['theme']['slug'] : '' ),
				'version' => $text( isset( $raw['theme']['version'] ) ? $raw['theme']['version'] : '', 40 ),
				'parent'  => $text( isset( $raw['theme']['parent'] ) ? $raw['theme']['parent'] : '' ),
			),
			'themes'     => array(),
			'plugins'    => array(),
			'mu_plugins' => array(),
			'dropins'    => array(),
			'components' => array(),
		);

		foreach ( array( 'themes', 'plugins' ) as $list ) {
			foreach ( isset( $raw[ $list ] ) && is_array( $raw[ $list ] ) ? array_slice( $raw[ $list ], 0, 2000 ) : array() as $entry ) {
				if ( ! is_array( $entry ) || ! isset( $entry['slug'] ) || ! is_string( $entry['slug'] ) || '' === $entry['slug'] ) {
					continue;
				}
				$out[ $list ][] = array(
					'slug'    => $text( $entry['slug'] ),
					'name'    => $text( isset( $entry['name'] ) ? $entry['name'] : '' ),
					'version' => $text( isset( $entry['version'] ) ? $entry['version'] : '', 40 ),
					'active'  => ! empty( $entry['active'] ),
				);
			}
		}
		foreach ( array( 'mu_plugins', 'dropins' ) as $list ) {
			foreach ( isset( $raw[ $list ] ) && is_array( $raw[ $list ] ) ? array_slice( $raw[ $list ], 0, 500 ) : array() as $name ) {
				if ( is_string( $name ) && '' !== $name ) {
					$out[ $list ][] = $text( $name );
				}
			}
		}
		foreach ( isset( $raw['components'] ) && is_array( $raw['components'] ) ? array_slice( $raw['components'], 0, 200 ) : array() as $component ) {
			if ( ! is_array( $component ) || ! isset( $component['id'] ) || ! is_string( $component['id'] ) || 1 !== preg_match( self::COMPONENT_PATTERN, $component['id'], $m ) || false !== strpos( $component['id'], '..' ) ) {
				continue;
			}
			$out['components'][] = array(
				'id'      => $component['id'],
				'type'    => $m[1],
				'slug'    => $m[2],
				'name'    => $text( isset( $component['name'] ) ? $component['name'] : $m[2] ),
				'version' => $text( isset( $component['version'] ) ? $component['version'] : '', 40 ),
			);
		}

		return $out;
	}

	/**
	 * A page of live's file entries, checked; null when malformed.
	 *
	 * @param mixed $raw Raw.
	 * @return array[]|null
	 */
	private static function clean_files( $raw ) {
		if ( ! is_array( $raw ) || count( $raw ) > self::PAGE_SIZE ) {
			return null;
		}
		$out  = array();
		$prev = null;
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['path'], $entry['size'] ) || ! is_string( $entry['path'] ) || ! is_int( $entry['size'] ) ) {
				return null;
			}
			$path = $entry['path'];
			if ( '' === $path || strlen( $path ) > 1024 || false !== strpos( $path, '..' ) || 1 === preg_match( '/[\x00-\x1F\\\\]/', $path ) || '/' === $path[0] ) {
				return null;
			}
			// Live sends the list sorted; the comparison relies on it.
			if ( null !== $prev && strcmp( $prev, $path ) >= 0 ) {
				return null;
			}
			$prev  = $path;
			$sha1  = isset( $entry['sha1'] ) && is_string( $entry['sha1'] ) && 1 === preg_match( '/\A[0-9a-f]{40}\z/', $entry['sha1'] ) ? $entry['sha1'] : null;
			$out[] = array(
				'path' => $path,
				'size' => $entry['size'],
				'sha1' => $sha1,
			);
		}
		return $out;
	}

	/**
	 * A record with nothing in it.
	 *
	 * @return array
	 */
	private static function empty_record() {
		return array(
			'state'      => 'not_run',
			'checked_at' => null,
			'error'      => null,
			'live'       => null,
			'local'      => null,
			'diff'       => null,
			'files'      => array(
				'queue'          => array(),
				'current'        => null,
				'results'        => array(),
				'not_comparable' => array(),
			),
		);
	}

	/**
	 * Stores a pairing's record.
	 *
	 * @param string $pairing_uuid Pairing UUID.
	 * @param array  $record       Record.
	 * @return void
	 */
	private static function save( $pairing_uuid, array $record ) {
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();

		$all[ strtolower( (string) $pairing_uuid ) ] = $record;
		update_option( self::OPTION, $all, false );
	}

	/**
	 * A step-12 refusal.
	 *
	 * @param string $code Code.
	 * @return WP_Error
	 */
	private static function invalid( $code ) {
		return new WP_Error( $code, $code, array( 'status' => 400 ) );
	}
}
