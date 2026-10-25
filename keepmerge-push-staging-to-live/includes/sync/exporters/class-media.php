<?php
/**
 * Attachments.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Sync\Exporters;

use SoftGlaze\SafePush\Sync\Scope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attachments (blueprint §6.2): the post fields, _wp_attached_file, the metadata, the alt text
 * and the list of its files (the original, every size and the original_image of a scaled image)
 * with their size and SHA-256. Files are only read; nothing about them leaves the site but
 * their path, size and checksum. Paths are checked before a file is opened.
 */
final class Media extends Exporter {

	/**
	 * Meta carried as fields.
	 */
	const FIELDS = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_image_alt' );

	/**
	 * Exports one attachment.
	 *
	 * @param int|string $id Attachment ID.
	 * @return array|null
	 */
	public function export( $id ) {
		$post = get_post( absint( $id ) );
		if ( ! Scope::allows_post( $post, 'attachment' ) ) {
			return null;
		}

		$file     = (string) get_post_meta( $post->ID, '_wp_attached_file', true );
		$metadata = get_post_meta( $post->ID, '_wp_attachment_metadata', true );

		$raw = array(
			'post_type'     => 'attachment',
			'post_title'    => $post->post_title,
			'post_excerpt'  => $post->post_excerpt,
			'post_content'  => $post->post_content,
			'post_name'     => $post->post_name,
			'post_status'   => $post->post_status,
			'post_date_gmt' => $post->post_date_gmt,
			'post_parent'   => (int) $post->post_parent,
			'author'        => $this->author( $post->post_author ),
			'mime_type'     => $post->post_mime_type,
			'file'          => $file,
			'metadata'      => is_array( $metadata ) ? $metadata : array(),
			'alt'           => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'files'         => self::files( $file, is_array( $metadata ) ? $metadata : array() ),
			'meta'          => $this->post_meta( $post->ID, self::FIELDS ),
		);

		return $this->item(
			'attachment',
			'attachment',
			$post->ID,
			$this->ctx->uuid( 'post', $post->ID ),
			$raw,
			array(
				'title'        => '' === $post->post_title ? $file : (string) $post->post_title,
				'modified_gmt' => (string) $post->post_modified_gmt,
			)
		);
	}

	/**
	 * The files of an attachment with size and SHA-256 (-1 and '' for a file that is missing).
	 *
	 * @param string $file     _wp_attached_file.
	 * @param array  $metadata Attachment metadata.
	 * @return array[]
	 */
	public static function files( $file, array $metadata ) {
		$paths = array();
		if ( self::safe_path( $file ) ) {
			$paths[] = $file;
		}
		$dir = dirname( $file );
		$dir = '.' === $dir ? '' : $dir . '/';
		if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) && '' !== $size['file'] && false === strpos( $size['file'], '/' ) ) {
					$paths[] = $dir . $size['file'];
				}
			}
		}
		if ( isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) && '' !== $metadata['original_image'] && false === strpos( $metadata['original_image'], '/' ) ) {
			$paths[] = $dir . $metadata['original_image'];
		}

		$base = trailingslashit( (string) wp_upload_dir( null, false )['basedir'] );
		$out  = array();
		foreach ( array_unique( $paths ) as $path ) {
			if ( ! self::safe_path( $path ) ) {
				continue;
			}
			$full  = $base . $path;
			$size  = is_file( $full ) ? (int) filesize( $full ) : -1;
			$out[] = array(
				'path'   => $path,
				'size'   => $size,
				'sha256' => $size >= 0 ? (string) hash_file( 'sha256', $full ) : '',
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);
		return $out;
	}

	/**
	 * Whether a relative uploads path is safe to open: relative, no dot segments, no backslash,
	 * colon or NUL, and accepted by core's validate_file().
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	public static function safe_path( $path ) {
		$path = (string) $path;
		if ( '' === $path || '/' === $path[0] || strlen( $path ) > 255 || 1 === preg_match( '#[\\\\:\x00]|(?:\A|/)\.\.?(?:/|\z)#', $path ) ) {
			return false;
		}
		return 0 === validate_file( $path );
	}
}
