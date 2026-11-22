<?php
/**
 * Pre-images of the items a push changes on live.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply;

use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Sync\Export_Context;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Items;
use SoftGlaze\SafePush\Sync\Scope;
use SoftGlaze\SafePush\Sync\Sync_State;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Media_Receiver;
use SoftGlaze\SafePush\Transfer\Push_Session;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Blueprint §10.3 step 1 and §11.1–§11.2: before any item of a push is written, live records what
 * each item was. The record lives in the item's own push_items row (there is no snapshots table):
 * pre_image (live's normal form and hash, plus the raw values an exact restore needs: the post or
 * term row, every meta value as stored, term assignments, an option's stored text and autoload, the
 * sync_state row), pre_hash and snapshot_gmt. A media file an attachment update replaces is copied
 * to uploads/keepmerge-push-staging-to-live/snapshots/<random 32 hex>/<ref>.bin first and its path
 * recorded in the file's row (snapshot_file_rel).
 *
 * Content snapshots stay in the database because content can include drafts and private posts and
 * uploads folders are often public; only media files (already public) go to the snapshot folder.
 * Everything here reads live's own data through WordPress APIs; nothing received is involved.
 */
final class Snapshot_Store {

	const SIDE = 'destination';

	/**
	 * Takes the snapshot of one item row: live's current state of the object the row is about.
	 *
	 * The snapshot is written to the plugin's own table before the item is touched (blueprint §10.3);
	 * prepared through $wpdb->update(), table name from Tables::table_name().
	 *
	 * @param array $session Session.
	 * @param array $row     Item row (id, uuid, object_type, action, local_object_id, payload).
	 * @return array{pre_hash:string,bytes:int}|WP_Error
	 */
	public static function take( array $session, array $row ) {
		$kind   = (string) $row['object_type'];
		$action = (string) $row['action'];
		$ref    = self::ref( $kind, $row );

		$image = array(
			'v'    => 1,
			'kind' => $kind,
			'sync' => Sync_State::find( (int) $session['pairing_id'], (string) $row['uuid'] ),
		);

		if ( in_array( $action, array( 'create', 'recreate' ), true ) ) {
			// Nothing exists yet: a rollback removes what the push creates.
			$image['absent'] = true;
			$pre_hash        = '';
			if ( in_array( $kind, array( 'option', 'widgets' ), true ) ) {
				// An option may appear on live between staging and apply: record what is there.
				$image['raw'] = self::raw( $kind, $ref, $row );
			} elseif ( '' !== Scope::space( $kind ) ) {
				// Objects that already carry the item's UUID (outside live's scope, so staging did not
				// see them: trashed, or of a type live excludes now). The allocate pass never takes one
				// of them for the placeholder it makes (security review 2026-11-22, KM-03).
				$image['uuid_owners'] = Identity::find( Scope::space( $kind ), (string) $row['uuid'] );
			}
		} else {
			$item = Items::export( $kind, $ref, Export_Context::destination() );
			if ( null === $item && 'option' !== $kind ) {
				return new WP_Error( 'snapshot_target_missing', 'snapshot_target_missing' );
			}
			$image['id']     = is_int( $ref ) ? $ref : 0;
			$image['form']   = null === $item ? null : $item['data'];
			$image['hash']   = null === $item ? '' : (string) $item['hash'];
			$image['groups'] = null === $item ? array() : $item['groups'];
			$image['raw']    = self::raw( $kind, $ref, $row );
			$pre_hash        = null === $item ? '' : (string) $item['hash'];
		}

		$json = wp_json_encode( $image );
		if ( ! is_string( $json ) ) {
			return new WP_Error( 'snapshot_unencodable', 'snapshot_unencodable' );
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the item's own row.
		$wpdb->update(
			Tables::table_name( 'push_items' ),
			array(
				'pre_image'    => $json,
				'pre_hash'     => $pre_hash,
				'snapshot_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => absint( $row['id'] ) )
		);

		return array(
			'pre_hash' => $pre_hash,
			'bytes'    => strlen( $json ),
		);
	}

	/**
	 * The local reference of an item: its live ID, the option name, or "widgets".
	 *
	 * @param string $kind Kind.
	 * @param array  $row  Row.
	 * @return int|string
	 */
	public static function ref( $kind, array $row ) {
		if ( 'option' === $kind ) {
			return substr( (string) $row['uuid'], strlen( 'option:' ) );
		}
		if ( 'widgets' === $kind ) {
			return 'widgets';
		}
		return (int) $row['local_object_id'];
	}

	/**
	 * Raw values for an exact restore.
	 *
	 * @param string     $kind Kind.
	 * @param int|string $ref  Local ID or option name.
	 * @param array      $row  Item row (the widgets unit names its options in the payload).
	 * @return array
	 */
	public static function raw( $kind, $ref, array $row ) {
		switch ( $kind ) {
			case 'option':
				return array( 'options' => array( (string) $ref => self::raw_option( (string) $ref ) ) );

			case 'widgets':
				$names = array( 'sidebars_widgets' );
				$data  = isset( $row['payload']['data']['widgets'] ) && is_array( $row['payload']['data']['widgets'] ) ? array_keys( $row['payload']['data']['widgets'] ) : array();
				foreach ( $data as $name ) {
					$names[] = (string) $name;
				}
				$out = array();
				foreach ( array_unique( $names ) as $name ) {
					$out[ $name ] = self::raw_option( $name );
				}
				return array( 'options' => $out );

			case 'term':
				return self::raw_term( (int) $ref );

			case 'menu':
				$out   = self::raw_term( (int) $ref );
				$items = array();
				foreach ( \SoftGlaze\SafePush\Sync\Exporters\Menus::item_posts( (int) $ref ) as $post ) {
					$items[] = self::raw_post( (int) $post->ID );
				}
				$out['items'] = $items;
				return $out;

			default:
				return self::raw_post( (int) $ref );
		}
	}

	/**
	 * A post row (every column), its meta as stored and its term assignments.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function raw_post( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}
		$columns = get_object_vars( $post );
		unset( $columns['filter'] );

		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $ids ) && ! empty( $ids ) ) {
				$ids = array_map( 'intval', $ids );
				sort( $ids );
				$terms[ $taxonomy ] = $ids;
			}
		}

		return array(
			'post'  => self::encode( $columns ),
			// With no key, get_post_meta() returns every value exactly as stored (not unserialized).
			'meta'  => self::encode( self::plain_meta( get_post_meta( $post->ID ) ) ),
			'terms' => $terms,
		);
	}

	/**
	 * A term (its term and taxonomy rows) and its meta as stored.
	 *
	 * @param int $term_id Term ID.
	 * @return array
	 */
	public static function raw_term( $term_id ) {
		$term = get_term( (int) $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return array();
		}
		$columns = get_object_vars( $term );
		unset( $columns['filter'] );
		return array(
			'term' => self::encode( $columns ),
			'meta' => self::encode( self::plain_meta( get_term_meta( $term->term_id ) ) ),
		);
	}

	/**
	 * An option as stored: whether it exists, its stored text (serialized as WordPress stored it)
	 * and its autoload flag.
	 *
	 * Reads the option row as stored, through a prepared query, so a rollback can put the exact stored
	 * text back without unserializing it.
	 *
	 * @param string $name Option name.
	 * @return array{exists:bool,value:mixed,autoload:string}
	 */
	public static function raw_option( $name ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The exact stored text and autoload flag for an exact restore; get_option() returns the unserialized value only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", (string) $name ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return array(
				'exists'   => false,
				'value'    => null,
				'autoload' => '',
			);
		}
		return array(
			'exists'   => true,
			'value'    => self::encode( (string) $row['option_value'] ),
			'autoload' => (string) $row['autoload'],
		);
	}

	/**
	 * Copies a file the push is about to replace into the push's snapshot folder (blueprint §11.2).
	 *
	 * Copies a file the push is about to replace into the push's random snapshot folder under uploads,
	 * through WP_Filesystem, so a rollback can restore it byte for byte.
	 *
	 * @param array  $session Session.
	 * @param string $full    Absolute path of the file on live.
	 * @param int    $ref     File ref (the copy is named <ref>.bin).
	 * @return string|WP_Error Path relative to the plugin's uploads folder.
	 */
	public static function copy_file( array $session, $full, $ref ) {
		$base = Media_Receiver::uploads_base();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		if ( ! is_file( $full ) || is_link( $full ) || ! Media_Receiver::is_inside( $full, $base ) ) {
			return new WP_Error( 'snapshot_file_refused', 'snapshot_file_refused' );
		}
		$dir = self::folder( $session, true );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$fs = Media_Receiver::direct_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		$name   = absint( $ref ) . '.bin';
		$target = $dir . '/' . $name;
		if ( ! $fs->copy( $full, $target, true, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'snapshot_unwritable', 'snapshot_unwritable' );
		}
		return 'snapshots/' . basename( $dir ) . '/' . $name;
	}

	/**
	 * The push's snapshot folder: uploads/keepmerge-push-staging-to-live/snapshots/<32 hex>, with
	 * index.php and the deny rule, resolving inside the uploads base.
	 *
	 * The snapshot folder has a random 32-hex name from random_bytes() under uploads, is created
	 * through WP_Filesystem and gets index.php and a deny rule.
	 *
	 * @param array $session Session.
	 * @param bool  $create  Create it (and name it) when missing.
	 * @return string|WP_Error '' when it has no folder and $create is false.
	 */
	public static function folder( array $session, $create ) {
		$base = Media_Receiver::uploads_base();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$fresh = Push_Session::find( (string) $session['push_uuid'] );
		$name  = is_array( $fresh ) && isset( $fresh['cursor_json']['snap'] ) ? (string) $fresh['cursor_json']['snap'] : '';
		if ( 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $name ) ) {
			if ( ! $create ) {
				return '';
			}
			$name = bin2hex( random_bytes( 16 ) );
			Push_Session::cursor_set( (int) $session['id'], array( 'snap' => $name ) );
		}

		$root  = $base . '/' . Installer::UPLOADS_DIR;
		$snaps = $root . '/snapshots';
		$dir   = $snaps . '/' . $name;
		if ( ! $create ) {
			return is_dir( $dir ) && Media_Receiver::is_inside( $dir, $base ) ? $dir : '';
		}

		$fs = Media_Receiver::direct_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		foreach ( array( $root, $snaps, $dir ) as $folder ) {
			if ( ! $fs->is_dir( $folder ) && ! $fs->mkdir( $folder, FS_CHMOD_DIR ) ) {
				return new WP_Error( 'snapshot_unwritable', 'snapshot_unwritable' );
			}
			if ( $root !== $folder && ! Media_Receiver::protect_folder( $fs, $folder ) ) {
				return new WP_Error( 'snapshot_unwritable', 'snapshot_unwritable' );
			}
		}
		if ( ! Media_Receiver::is_inside( $dir, $base ) ) {
			return new WP_Error( 'snapshot_unwritable', 'snapshot_unwritable' );
		}
		return $dir;
	}

	/**
	 * Copies a replaced file back from the push's snapshot folder (blueprint §11.3 file_replaced)
	 * and checks the result against the SHA-256 the snapshot recorded.
	 *
	 * Copies the snapshot back through WP_Filesystem and compares the SHA-256 with hash_equals(), so a
	 * rollback never leaves a file that differs from the one it replaced.
	 *
	 * @param array  $session Session.
	 * @param string $rel     Snapshot path relative to the plugin's uploads folder (snapshots/<hex>/<ref>.bin).
	 * @param string $file_rel Path of the file relative to the uploads base.
	 * @param string $sha256  SHA-256 of the file before the push.
	 * @return true|WP_Error
	 */
	public static function restore_file( array $session, $rel, $file_rel, $sha256 ) {
		$base = Media_Receiver::uploads_base();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$dir = self::folder( $session, false );
		if ( ! is_string( $dir ) || '' === $dir || 1 !== preg_match( '#\Asnapshots/[0-9a-f]{32}/[0-9]{1,9}\.bin\z#', (string) $rel ) || basename( $dir ) !== explode( '/', (string) $rel )[1] ) {
			return new WP_Error( 'snapshot_missing', 'snapshot_missing' );
		}
		$source = $dir . '/' . basename( (string) $rel );
		if ( ! is_file( $source ) || ! hash_equals( (string) $sha256, (string) hash_file( 'sha256', $source ) ) ) {
			return new WP_Error( 'snapshot_missing', 'snapshot_missing' );
		}
		if ( '' !== \SoftGlaze\SafePush\Transfer\Path_Guard::reason( (string) $file_rel ) ) {
			return new WP_Error( 'path_refused', 'path_refused' );
		}
		$target = $base . '/' . $file_rel;
		if ( is_link( $target ) || ! is_dir( dirname( $target ) ) || ! Media_Receiver::is_inside( dirname( $target ), $base ) ) {
			return new WP_Error( 'path_refused', 'path_refused' );
		}
		$fs = Media_Receiver::direct_filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		if ( ! $fs->copy( $source, $target, true, FS_CHMOD_FILE ) ) {
			return new WP_Error( 'storage_unwritable', 'storage_unwritable' );
		}
		return hash_equals( (string) $sha256, (string) hash_file( 'sha256', $target ) ) ? true : new WP_Error( 'checksum_mismatch', 'checksum_mismatch' );
	}

	/**
	 * Deletes a push's snapshot folder (retention, blueprint §11.4).
	 *
	 * Deletes only a push's own snapshot folder under uploads, through WP_Filesystem, after its path
	 * resolved inside the uploads base.
	 *
	 * @param array $session Session.
	 * @return bool Whether a folder was deleted.
	 */
	public static function delete_folder( array $session ) {
		$dir = self::folder( $session, false );
		if ( ! is_string( $dir ) || '' === $dir ) {
			return false;
		}
		$fs = Media_Receiver::direct_filesystem();
		return ! is_wp_error( $fs ) && (bool) $fs->delete( $dir, true, 'd' );
	}

	/**
	 * Deletes snapshot folders that no push names any more (their push was pruned or is gone),
	 * at most 20 per run.
	 *
	 * Deletes only snapshot folders no push names any more; the names in use come from a prepared
	 * query on the plugin's own table.
	 *
	 * @return int Folders deleted.
	 */
	public static function prune_orphans() {
		global $wpdb;

		$base = Media_Receiver::uploads_base();
		if ( is_wp_error( $base ) ) {
			return 0;
		}
		$snaps = $base . '/' . Installer::UPLOADS_DIR . '/snapshots';
		if ( ! is_dir( $snaps ) || ! Media_Receiver::is_inside( $snaps, $base ) ) {
			return 0;
		}
		$fs = Media_Receiver::direct_filesystem();
		if ( is_wp_error( $fs ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the folders sessions still name.
		$cursors = $wpdb->get_col( $wpdb->prepare( 'SELECT cursor_json FROM %i WHERE side = %s', Tables::table_name( 'pushes' ), self::SIDE ) );
		$named   = array();
		foreach ( is_array( $cursors ) ? $cursors : array() as $json ) {
			$cursor = json_decode( (string) $json, true );
			if ( is_array( $cursor ) && isset( $cursor['snap'] ) && is_string( $cursor['snap'] ) && empty( $cursor['pruned'] ) ) {
				$named[ $cursor['snap'] ] = true;
			}
		}

		$deleted = 0;
		$list    = $fs->dirlist( $snaps, false, false );
		foreach ( is_array( $list ) ? $list : array() as $name => $info ) {
			$name = (string) $name;
			if ( $deleted >= 20 || 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $name ) || ! isset( $info['type'] ) || 'd' !== $info['type'] || isset( $named[ $name ] ) ) {
				continue;
			}
			if ( $fs->delete( $snaps . '/' . $name, true, 'd' ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Size of a push's snapshots: the pre-images in its rows and the files in its folder.
	 *
	 * Direct query on the plugin's own table through $wpdb->prepare(), table name from
	 * Tables::table_name(): core has no API for plugin tables, and a cached answer could be stale
	 * during a push.
	 *
	 * @param array $session Session.
	 * @return int Bytes.
	 */
	public static function size( array $session ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$bytes = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(LENGTH(pre_image)), 0) FROM %i WHERE push_id = %d AND side = %s', Tables::table_name( 'push_items' ), absint( $session['id'] ), self::SIDE ) );

		$dir = self::folder( $session, false );
		if ( is_string( $dir ) && '' !== $dir ) {
			foreach ( (array) glob( $dir . '/*.bin' ) as $file ) {
				if ( is_string( $file ) && is_file( $file ) ) {
					$bytes += (int) filesize( $file );
				}
			}
		}
		return $bytes;
	}

	/**
	 * Meta as returned by get_*_meta() with no key: key => list of stored strings.
	 *
	 * @param mixed $meta Meta.
	 * @return array<string,string[]>
	 */
	private static function plain_meta( $meta ) {
		$out = array();
		foreach ( is_array( $meta ) ? $meta : array() as $key => $values ) {
			$list = array();
			foreach ( (array) $values as $value ) {
				$list[] = is_scalar( $value ) || null === $value ? (string) $value : '';
			}
			$out[ (string) $key ] = $list;
		}
		ksort( $out, SORT_STRING );
		return $out;
	}

	/**
	 * Strings that are not UTF-8 as {"__sgsp":"bin","hex":…} so the pre-image is valid JSON and
	 * byte-exact (the same marker as the transport's typed JSON, blueprint §8.1).
	 *
	 * @param mixed $value Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	private static function encode( $value, $depth = 0 ) {
		if ( $depth > 16 ) {
			return null;
		}
		if ( is_string( $value ) ) {
			if ( 1 === preg_match( '//u', $value ) ) {
				return $value;
			}
			return array(
				'__sgsp' => 'bin',
				'hex'    => bin2hex( $value ),
			);
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = self::encode( $item, $depth + 1 );
			}
			return $out;
		}
		return is_scalar( $value ) || null === $value ? $value : null;
	}
}
