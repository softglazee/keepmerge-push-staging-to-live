<?php
/**
 * Attachments and their files on live.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Apply\Appliers;

use SoftGlaze\SafePush\Apply\Item_Context;
use SoftGlaze\SafePush\Apply\Snapshot_Store;
use SoftGlaze\SafePush\Tables;
use SoftGlaze\SafePush\Transfer\Media_Receiver;
use SoftGlaze\SafePush\Transfer\Path_Guard;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Moves an attachment's committed files from the push's temporary folder into the uploads tree
 * with WP_Filesystem, each target folder checked with realpath() to lie inside the uploads base. A
 * target must be free or a file of this same attachment (snapshotted first); anything else skips
 * the item as path_taken. Moved files are re-checked against their SHA-256.
 */
final class Attachment extends Base {

	const SIDE = 'destination';

	/**
	 * Allocate pass: an attachment post without a file yet, with the item's UUID and live's own
	 * file URL as its GUID (core sets a GUID only when a post is created, as on upload).
	 *
	 * @param Item_Context $ctx Context.
	 * @return int|WP_Error
	 */
	public static function allocate( Item_Context $ctx ) {
		$file  = self::str( $ctx->data, 'file' );
		$final = $file;
		foreach ( self::media_files( $ctx->row ) as $entry ) {
			if ( $entry['path'] === $file ) {
				$final = $entry['final'];
			}
		}
		$uploads = wp_upload_dir( null, false );
		$id      = self::save_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => self::str( $ctx->data, 'post_title' ),
				'post_mime_type' => self::str( $ctx->data, 'mime_type' ),
				'post_author'    => $ctx->author_id( isset( $ctx->data['author'] ) ? $ctx->data['author'] : array() ),
				'guid'           => trailingslashit( (string) $uploads['baseurl'] ) . $final,
			)
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		\SoftGlaze\SafePush\Sync\Identity::stamp( 'post', $id, (string) $ctx->row['uuid'] );
		return $id;
	}

	/**
	 * Snapshot pass of an attachment update: files of this attachment that the push will replace
	 * are copied to the snapshot folder and recorded in their file rows.
	 *
	 * @param array $session Session.
	 * @param array $row     Item row (decoded).
	 * @return int|WP_Error Bytes copied.
	 */
	public static function snapshot_files( array $session, array $row ) {
		$base = Media_Receiver::uploads_base();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$own   = self::own_files( (int) $row['local_object_id'] );
		$rows  = Media_Receiver::all_rows( (int) $session['id'] );
		$bytes = 0;
		foreach ( self::media_files( $row ) as $file ) {
			if ( 'committed' !== $file['status'] || ! in_array( $file['final'], $own, true ) ) {
				continue;
			}
			$full = $base . '/' . $file['final'];
			if ( ! is_file( $full ) || ! isset( $rows[ $file['ref'] ] ) || '' !== self::file_column( (int) $rows[ $file['ref'] ]['id'], 'snapshot_file_rel' ) ) {
				continue;
			}
			$rel = Snapshot_Store::copy_file( $session, $full, $file['ref'] );
			if ( is_wp_error( $rel ) ) {
				return $rel;
			}
			self::update_file_row(
				(int) $rows[ $file['ref'] ]['id'],
				array(
					'snapshot_file_rel' => $rel,
					'pre_hash'          => (string) hash_file( 'sha256', $full ),
					'snapshot_gmt'      => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$bytes += (int) filesize( $full );
		}
		return $bytes;
	}

	/**
	 * Write pass.
	 *
	 * @param Item_Context $ctx Context.
	 * @return array|WP_Error
	 */
	public static function write( Item_Context $ctx ) {
		$data = $ctx->data;
		$base = Media_Receiver::uploads_base();
		$fs   = Media_Receiver::direct_filesystem();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$files    = self::media_files( $ctx->row );
		$by_path  = array();
		$own      = 'created' === $ctx->row['action'] ? array() : self::own_files( $ctx->live_id );
		$rows     = Media_Receiver::all_rows( (int) $ctx->session['id'] );
		$moves    = array();
		$warnings = array();

		// Every target is checked before anything moves.
		foreach ( $files as $file ) {
			$by_path[ $file['path'] ] = $file['final'];
			if ( 'have' === $file['status'] ) {
				continue;
			}
			if ( 'committed' !== $file['status'] || ! isset( $rows[ $file['ref'] ] ) || '' !== Path_Guard::reason( $file['final'] ) ) {
				return new WP_Error( 'media_missing', 'media_missing' );
			}
			$src = Media_Receiver::committed_file( $ctx->session, $file['ref'] );
			if ( '' === $src || ! is_file( $src ) ) {
				return new WP_Error( 'media_missing', 'media_missing' );
			}
			$target   = $base . '/' . $file['final'];
			$replaced = false;
			if ( file_exists( $target ) || is_link( $target ) ) {
				if ( is_link( $target ) || ! in_array( $file['final'], $own, true ) ) {
					return new WP_Error( 'path_taken', 'path_taken' );
				}
				if ( '' === self::file_column( (int) $rows[ $file['ref'] ]['id'], 'snapshot_file_rel' ) ) {
					return new WP_Error( 'snapshot_missing', 'snapshot_missing' );
				}
				$replaced = true;
			}
			$moves[] = array(
				'src'      => $src,
				'target'   => $target,
				'final'    => $file['final'],
				'row'      => (int) $rows[ $file['ref'] ]['id'],
				'sha256'   => (string) $rows[ $file['ref'] ]['t']['sha256'],
				'replaced' => $replaced,
			);
		}

		foreach ( $moves as $move ) {
			$dir = self::folder( $fs, $base, dirname( $move['final'] ) );
			if ( is_wp_error( $dir ) ) {
				return $dir;
			}
			if ( ! $fs->move( $move['src'], $move['target'], true ) ) {
				return new WP_Error( 'move_failed', 'move_failed' );
			}
			$hash = hash_file( 'sha256', $move['target'] );
			if ( ! is_string( $hash ) || ! hash_equals( $move['sha256'], $hash ) ) {
				return new WP_Error( 'checksum_mismatch', 'checksum_mismatch' );
			}
			self::update_file_row(
				$move['row'],
				array(
					'action'      => $move['replaced'] ? 'file_replaced' : 'file_added',
					'applied_gmt' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}

		$file     = self::str( $data, 'file' );
		$final    = isset( $by_path[ $file ] ) ? $by_path[ $file ] : $file;
		$metadata = isset( $data['metadata'] ) && is_array( $data['metadata'] ) ? self::final_names( $data['metadata'], $file, $final, $by_path ) : array();
		if ( '' !== Path_Guard::reason( $final ) || '' !== Path_Guard::metadata_reason( $final, $metadata ) ) {
			return new WP_Error( 'path_refused', 'path_refused' );
		}

		$postarr                   = Post::post_fields( $ctx, $data, 'attachment' );
		$postarr['post_status']    = 'inherit';
		$postarr['post_mime_type'] = self::str( $data, 'mime_type' );
		$id                        = self::save_post( $postarr );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, '_wp_attached_file', wp_slash( $final ) );
		update_post_meta( $id, '_wp_attachment_metadata', self::slash( $metadata ) );
		$alt = self::str( $data, 'alt' );
		if ( '' === $alt ) {
			delete_post_meta( $id, '_wp_attachment_image_alt' );
		} else {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
		}
		self::replace_meta( 'post', $id, isset( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : array(), $ctx, array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt' ) );

		if ( $final !== $file ) {
			$warnings[] = 'file_renamed';
		}
		return array(
			'id'       => $id,
			'warnings' => $warnings,
		);
	}

	/**
	 * The attachment's files as the payload's media record lists them.
	 *
	 * @param array $row Item row (decoded).
	 * @return array[] path, final, ref, status.
	 */
	private static function media_files( array $row ) {
		$out   = array();
		$files = isset( $row['payload']['media']['files'] ) && is_array( $row['payload']['media']['files'] ) ? $row['payload']['media']['files'] : array();
		foreach ( $files as $file ) {
			if ( is_array( $file ) && isset( $file['path'], $file['final'], $file['ref'], $file['status'] ) ) {
				$out[] = array(
					'path'   => (string) $file['path'],
					'final'  => (string) $file['final'],
					'ref'    => (int) $file['ref'],
					'status' => (string) $file['status'],
				);
			}
		}
		return $out;
	}

	/**
	 * The files live's attachment has now (its file, sizes and original image).
	 *
	 * @param int $id Attachment ID.
	 * @return string[]
	 */
	private static function own_files( $id ) {
		if ( $id < 1 || 'attachment' !== get_post_type( $id ) ) {
			return array();
		}
		$metadata = wp_get_attachment_metadata( $id );
		return Path_Guard::attachment_paths( (string) get_post_meta( $id, '_wp_attached_file', true ), is_array( $metadata ) ? $metadata : array() );
	}

	/**
	 * Metadata with the final names of renamed files (file, sizes, original image).
	 *
	 * @param array  $metadata Metadata.
	 * @param string $file     Staged main path.
	 * @param string $main     Final main path.
	 * @param array  $by_path  Staged path => final path.
	 * @return array
	 */
	private static function final_names( array $metadata, $file, $main, array $by_path ) {
		$dir = dirname( $file );
		$dir = '.' === $dir ? '' : $dir . '/';
		if ( isset( $metadata['file'] ) && is_string( $metadata['file'] ) ) {
			$metadata['file'] = $main;
		}
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $name => $size ) {
				if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) && isset( $by_path[ $dir . $size['file'] ] ) ) {
					$metadata['sizes'][ $name ]['file'] = wp_basename( $by_path[ $dir . $size['file'] ] );
				}
			}
		}
		if ( isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && isset( $by_path[ $dir . $metadata['original_image'] ] ) ) {
			$metadata['original_image'] = wp_basename( $by_path[ $dir . $metadata['original_image'] ] );
		}
		return $metadata;
	}

	/**
	 * Creates the folders of a relative directory under the uploads base with WP_Filesystem and
	 * checks with realpath() that the result lies inside the base (a link cannot lead out).
	 *
	 * @param \WP_Filesystem_Base $fs   Filesystem.
	 * @param string              $base Uploads base.
	 * @param string              $rel  Relative directory ('.' for the base itself).
	 * @return string|WP_Error The folder.
	 */
	private static function folder( $fs, $base, $rel ) {
		$current = $base;
		if ( '.' !== $rel && '' !== $rel ) {
			foreach ( explode( '/', $rel ) as $segment ) {
				$current .= '/' . $segment;
				if ( ! $fs->is_dir( $current ) && ! $fs->mkdir( $current, FS_CHMOD_DIR ) ) {
					return new WP_Error( 'storage_unwritable', 'storage_unwritable' );
				}
			}
		}
		if ( $current !== $base && ! Media_Receiver::is_inside( $current, $base ) ) {
			return new WP_Error( 'path_refused', 'path_refused' );
		}
		return $current;
	}

	/**
	 * One column of a file row.
	 *
	 * @param int    $id     Row ID.
	 * @param string $column snapshot_file_rel.
	 * @return string
	 */
	private static function file_column( $id, $column ) {
		global $wpdb;

		if ( 'snapshot_file_rel' !== $column ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT snapshot_file_rel FROM %i WHERE id = %d AND side = %s', Tables::table_name( 'push_items' ), absint( $id ), self::SIDE ) );
	}

	/**
	 * Updates a file row's apply columns.
	 *
	 * @param int   $id     Row ID.
	 * @param array $fields Columns.
	 * @return void
	 */
	private static function update_file_row( $id, array $fields ) {
		global $wpdb;

		$data = array_intersect_key( $fields, array_flip( array( 'action', 'snapshot_file_rel', 'pre_hash', 'snapshot_gmt', 'applied_gmt' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Tables::table_name( 'push_items' ), $data, array( 'id' => absint( $id ) ) );
	}
}
