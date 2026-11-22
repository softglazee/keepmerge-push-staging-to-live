<?php
/**
 * Relative upload paths a push may name.
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Transfer;

use SoftGlaze\SafePush\Woo\Guard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The path guard of blueprint §9 step 2 (threat model §7.5). Live is authoritative: every
 * relative path that arrives in a push (an attachment's file, the files of its metadata) passes
 * this check before it is stored, and the files steps of Phase 6 use the same check before
 * anything is written.
 *
 * A path is accepted only if: it is at most 255 bytes; it has no NUL, backslash or colon; core's
 * validate_file() accepts it; no segment is empty, "." or ".." or starts with a dot; it has at
 * most 4 segments; its first segment is not a protected folder; its basename is what
 * sanitize_file_name() makes of it; every dot-separated part of the basename after the first is
 * not a script or markup extension (so "x.php.jpg" is refused); and the last extension is one
 * WordPress allows a user without unfiltered_upload to upload.
 */
final class Path_Guard {

	const MAX_BYTES = 255;

	const MAX_SEGMENTS = 4;

	/**
	 * First segments that are never written by a push: Keepmerge's own folder, WooCommerce's logs
	 * (its downloads folder comes from Woo\Guard), the Font Library's folder, the uploads of other
	 * sites of a multisite network (sites/<id>/), and the protected upload folders of common
	 * download and form plugins. The filter softglaze_sp_protected_upload_dirs may add names.
	 */
	const PROTECTED_DIRS = array( 'keepmerge-push-staging-to-live', 'wc-logs', 'fonts', 'sites', 'edd', 'wpforms', 'gravity_forms' );

	/**
	 * Extensions refused anywhere in a basename, whatever the upload settings allow. Entries ending
	 * in "*" are prefixes (php3, php7, phtml are covered by "php*" and the list).
	 */
	const HARD_EXTENSIONS = array( 'php*', 'phtml', 'phar', 'pht', 'phps', 'cgi', 'pl', 'py', 'sh', 'asp*', 'jsp', 'exe', 'dll', 'htaccess', 'htpasswd', 'ini', 'svg', 'svgz', 'html', 'htm', 'js', 'shtml', 'xhtml' );

	/**
	 * Why a relative upload path is refused, or '' when it is accepted.
	 *
	 * Every relative path a push names passes here before anything is stored or written, so a received
	 * path can never leave the uploads tree or reach a protected folder (threat model §7.5).
	 *
	 * @param mixed $path Relative path (uploads base excluded), as received.
	 * @return string '' or a reason code.
	 */
	public static function reason( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return 'empty';
		}
		if ( strlen( $path ) > self::MAX_BYTES ) {
			return 'too_long';
		}
		if ( 1 === preg_match( '/[\x00-\x1f\x7f\\\\:]/', $path ) ) {
			return 'bad_character';
		}
		// A percent sign is never part of a path WordPress writes; "%2e%2e" is refused as such.
		if ( false !== strpos( $path, '%' ) ) {
			return 'encoded';
		}
		if ( 0 !== validate_file( $path ) ) {
			return 'validate_file';
		}

		$segments = explode( '/', $path );
		if ( count( $segments ) > self::MAX_SEGMENTS ) {
			return 'too_deep';
		}
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment[0] ) {
				return 'bad_segment';
			}
		}

		$first = strtolower( $segments[0] );
		if ( count( $segments ) > 1 && ( in_array( $first, self::protected_dirs(), true ) || Guard::is_protected_upload( $path ) ) ) {
			return 'protected_dir';
		}

		return self::basename_reason( (string) end( $segments ) );
	}

	/**
	 * The protected first segments: the built-in list plus what the filter adds. The filter can
	 * only add (filters narrow what is pushed); built-in names always stay.
	 *
	 * @return string[]
	 */
	public static function protected_dirs() {
		/**
		 * Adds upload folders (first path segment) a push never writes into.
		 *
		 * @param string[] $dirs Extra folder names (lower case).
		 */
		$extra = apply_filters( 'softglaze_sp_protected_upload_dirs', array() );
		$out   = self::PROTECTED_DIRS;
		foreach ( is_array( $extra ) ? $extra : array() as $dir ) {
			if ( is_string( $dir ) && 1 === preg_match( '/\A[a-z0-9._-]{1,100}\z/i', $dir ) ) {
				$out[] = strtolower( $dir );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Why an attachment's metadata names a file it may not have, or '' (threat model §7.5, S-49).
	 * The metadata's own "file" must pass the guard; every "sizes" entry and "original_image" must
	 * be a plain name that passes the guard inside the folder of the attachment's file; a "thumb"
	 * (written by WordPress before 2.9) must be one of those files.
	 *
	 * Core deletes every file named in attachment metadata together with the attachment, so a name a
	 * push stores there must be the attachment's own file (S-49, security review KM-01).
	 *
	 * @param string $attached The attachment's relative path (already accepted).
	 * @param mixed  $metadata Attachment metadata as received.
	 * @return string '' or the field that was refused: metadata.file, metadata.sizes,
	 *                metadata.original_image, metadata.thumb.
	 */
	public static function metadata_reason( $attached, $metadata ) {
		if ( ! is_array( $metadata ) ) {
			return '';
		}
		if ( isset( $metadata['file'] ) && ( ! is_string( $metadata['file'] ) || '' !== self::reason( $metadata['file'] ) ) ) {
			return 'metadata.file';
		}
		if ( isset( $metadata['sizes'] ) ) {
			if ( ! is_array( $metadata['sizes'] ) ) {
				return 'metadata.sizes';
			}
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! is_array( $size ) || ( isset( $size['file'] ) && '' !== self::sibling_reason( $attached, $size['file'] ) ) ) {
					return 'metadata.sizes';
				}
			}
		}
		if ( isset( $metadata['original_image'] ) && '' !== self::sibling_reason( $attached, $metadata['original_image'] ) ) {
			return 'metadata.original_image';
		}
		// Core deletes "thumb" with the attachment, checked only against the attachment's folder,
		// which is the whole uploads base for a file in the root: it may only name one of the
		// attachment's own files (security review 2026-11-22, KM-01).
		if ( isset( $metadata['thumb'] ) && '' !== $metadata['thumb'] && ! self::is_own_file( $attached, $metadata, $metadata['thumb'] ) ) {
			return 'metadata.thumb';
		}
		return '';
	}

	/**
	 * Whether a plain file name is the attachment's own file, one of its sizes or its original image.
	 *
	 * @param string $attached The attachment's relative path.
	 * @param array  $metadata Attachment metadata.
	 * @param mixed  $name     File name.
	 * @return bool
	 */
	private static function is_own_file( $attached, array $metadata, $name ) {
		if ( ! is_string( $name ) || '' !== self::sibling_reason( $attached, $name ) ) {
			return false;
		}
		$dir = dirname( (string) $attached );
		$own = array();
		foreach ( self::attachment_paths( $attached, $metadata ) as $path ) {
			$own[] = '.' === $dir ? $path : substr( $path, strlen( $dir ) + 1 );
		}
		return in_array( $name, $own, true );
	}

	/**
	 * The relative paths of every file an attachment has: its own file, each size and the
	 * original image of a scaled image (the same set the source's exporter lists). Only names
	 * that pass the guard are returned.
	 *
	 * @param string $attached The attachment's relative path.
	 * @param mixed  $metadata Attachment metadata.
	 * @return string[]
	 */
	public static function attachment_paths( $attached, $metadata ) {
		$attached = (string) $attached;
		$paths    = array();
		if ( '' === self::reason( $attached ) ) {
			$paths[] = $attached;
		}
		$dir   = dirname( $attached );
		$dir   = '.' === $dir ? '' : $dir . '/';
		$names = array();
		if ( is_array( $metadata ) && isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) ) {
					$names[] = $size['file'];
				}
			}
		}
		if ( is_array( $metadata ) && isset( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			$names[] = $metadata['original_image'];
		}
		foreach ( $names as $name ) {
			if ( '' === self::sibling_reason( $attached, $name ) ) {
				$paths[] = $dir . $name;
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/**
	 * Why a basename is refused, or ''.
	 *
	 * @param string $basename File name without folders.
	 * @return string
	 */
	public static function basename_reason( $basename ) {
		$basename = (string) $basename;
		if ( '' === $basename || false !== strpos( $basename, '/' ) || '.' === $basename[0] ) {
			return 'bad_segment';
		}
		if ( sanitize_file_name( $basename ) !== $basename ) {
			return 'not_sanitized';
		}

		$parts = explode( '.', strtolower( $basename ) );
		if ( count( $parts ) < 2 ) {
			return 'no_extension';
		}
		array_shift( $parts );
		foreach ( $parts as $part ) {
			if ( self::is_hard_extension( $part ) ) {
				return 'extension_refused';
			}
		}

		return self::extension_allowed( (string) end( $parts ) ) ? '' : 'extension_refused';
	}

	/**
	 * Whether a file name in an attachment's metadata ("sizes" entries, "original_image") is
	 * acceptable inside the folder of the attachment's file.
	 *
	 * @param string $attached The attachment's own relative path (already accepted).
	 * @param mixed  $basename File name from the metadata.
	 * @return string '' or a reason code.
	 */
	public static function sibling_reason( $attached, $basename ) {
		if ( ! is_string( $basename ) || '' === $basename || false !== strpos( $basename, '/' ) ) {
			return 'bad_segment';
		}
		$dir = dirname( (string) $attached );
		return self::reason( '.' === $dir ? $basename : $dir . '/' . $basename );
	}

	/**
	 * Mime types a user without unfiltered_upload (and without unfiltered_html) may upload here,
	 * keyed by extension pattern, as core reports them.
	 *
	 * @return array<string,string>
	 */
	public static function allowed_mime_types() {
		// A user object with no capabilities: core then removes the types only trusted users may
		// upload, whatever the current user is.
		return (array) get_allowed_mime_types( new \WP_User( 0 ) );
	}

	/**
	 * Whether a mime type is one of those types.
	 *
	 * @param mixed $mime Mime type.
	 * @return bool
	 */
	public static function mime_allowed( $mime ) {
		return is_string( $mime ) && '' !== $mime && in_array( strtolower( $mime ), array_map( 'strtolower', array_values( self::allowed_mime_types() ) ), true );
	}

	/**
	 * Whether an extension is in the hard list.
	 *
	 * @param string $ext Extension, lower case.
	 * @return bool
	 */
	private static function is_hard_extension( $ext ) {
		foreach ( self::HARD_EXTENSIONS as $entry ) {
			if ( '*' === substr( $entry, -1 ) ? 0 === strpos( $ext, substr( $entry, 0, -1 ) ) : $ext === $entry ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the last extension is allowed for uploads by a user without unfiltered_upload.
	 *
	 * @param string $ext Extension, lower case.
	 * @return bool
	 */
	private static function extension_allowed( $ext ) {
		if ( '' === $ext || self::is_hard_extension( $ext ) ) {
			return false;
		}
		foreach ( array_keys( self::allowed_mime_types() ) as $pattern ) {
			if ( in_array( $ext, explode( '|', strtolower( (string) $pattern ) ), true ) ) {
				return true;
			}
		}
		return false;
	}
}
