<?php
/**
 * Media files received for a push (destination).
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Transfer;

use SoftGlaze\SafePush\Audit_Log;
use SoftGlaze\SafePush\Installer;
use SoftGlaze\SafePush\Limits;
use SoftGlaze\SafePush\Settings;
use SoftGlaze\SafePush\Sync\Identity;
use SoftGlaze\SafePush\Sync\Sync_State;
use SoftGlaze\SafePush\Tables;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The destination half of blueprint §9 steps 3–5, 8 and 9 (threat model R10–R12, §7.5, §7.7):
 * the need list, chunks and commit of a push's media files, kept in the push's own temporary
 * folder until apply. Nothing here touches the uploads tree outside
 * uploads/keepmerge-push-staging-to-live/tmp/<32 hex>/; files are moved into place in Phase 7.
 *
 * Files are addressed by an integer ref chosen by the source, never by a path in a URL. Every
 * path in a need list passes Path_Guard first (live is authoritative). Temporary names are made
 * here: <ref>.part<n> and <ref>.bin inside a random folder that has index.php and an .htaccess
 * deny rule. Files are written through WP_Filesystem with the direct method only.
 *
 * Each file is a push_items row (object_type "file", uuid "file:<ref>", seq = ref) whose
 * transfer JSON keeps its path, final path, size, SHA-256, owning attachment, chunk size and a
 * received-chunk bitmap for resume. A file that will be written in Phase 7 also reserves its final
 * path with a marker row (object_type "file_path", uuid "path:<sha1 of the lower-case path>"), so
 * two files of one push can never be given the same name.
 */
final class Media_Receiver {

	const SIDE = 'destination';

	const KIND = 'file';

	const RESERVATION = 'file_path';

	/**
	 * Largest file a push may carry (blueprint §9.9; the filter softglaze_sp_limits may lower it).
	 */
	const MAX_FILE_BYTES = 536870912;

	/**
	 * Largest media total of a push (blueprint §9.9; the filter may lower it).
	 */
	const MAX_PUSH_BYTES = 2147483648;

	/**
	 * Most files in one need list (threat model R10).
	 */
	const MAX_FILES_PER_CALL = 2000;

	/**
	 * Most files a push may declare.
	 */
	const MAX_DECLARED_FILES = 100000;

	/**
	 * Largest need-list body, bytes.
	 */
	const FILES_MAX_BYTES = 1048576;

	/**
	 * Largest commit body, bytes.
	 */
	const COMMIT_MAX_BYTES = 1024;

	/**
	 * Chunk sizes: the setting is kept between 256 KB and 8 MB and below half of post_max_size;
	 * 413 halving on the source goes down to 64 KB.
	 */
	const CHUNK_MIN = 65536;

	const CHUNK_LOW = 262144;

	const CHUNK_MAX = 8388608;

	/**
	 * Share of the free disk space a push's media may use (blueprint §9.9).
	 */
	const FREE_SHARE = 0.8;

	/**
	 * Seconds after which a temporary folder no session uses is removed (blueprint §9.8).
	 */
	const TEMP_TTL = DAY_IN_SECONDS;

	/**
	 * Tries to find a free name for a renamed file.
	 */
	const RENAME_TRIES = 50;

	/**
	 * Commit refusal codes that end a file (it is not sent again).
	 */
	const FINAL_REFUSALS = array( 'size_mismatch', 'checksum_mismatch', 'file_type_refused', 'file_type_mismatch', 'image_unreadable' );

	// --- limits --------------------------------------------------------------------------------

	/**
	 * The media limits the source must keep to (in /status and the session answer).
	 *
	 * @return array{chunk_bytes:int,chunk_min:int,max_file_bytes:int,max_push_bytes:int,max_files_per_call:int}
	 */
	public static function limits() {
		$caps = self::caps();
		return array(
			'chunk_bytes'        => self::chunk_bytes(),
			'chunk_min'          => self::CHUNK_MIN,
			'max_file_bytes'     => $caps['max_file'],
			'max_push_bytes'     => $caps['max_push'],
			'max_files_per_call' => self::MAX_FILES_PER_CALL,
		);
	}

	/**
	 * The per-file and per-push caps. The filter softglaze_sp_limits may lower them, never raise
	 * them above 512 MB and 2 GB.
	 *
	 * @return array{max_file:int,max_push:int}
	 */
	public static function caps() {
		$defaults = array(
			'max_file' => self::MAX_FILE_BYTES,
			'max_push' => self::MAX_PUSH_BYTES,
		);
		/**
		 * Lowers the media caps of a push (bytes). Values above the defaults are ignored.
		 *
		 * @param array $limits max_file and max_push in bytes.
		 */
		$limits = apply_filters( 'softglaze_sp_limits', $defaults );
		$limits = is_array( $limits ) ? $limits : array();
		$out    = array();
		foreach ( $defaults as $key => $default ) {
			$value       = isset( $limits[ $key ] ) && is_numeric( $limits[ $key ] ) ? (int) $limits[ $key ] : $default;
			$out[ $key ] = max( 1, min( $default, $value ) );
		}
		return $out;
	}

	/**
	 * Chunk size this site accepts: the setting (256 KB to 8 MB), never above half of
	 * post_max_size (blueprint §13), never below 64 KB.
	 *
	 * @return int Bytes.
	 */
	public static function chunk_bytes() {
		$settings = Settings::get();
		$size     = max( self::CHUNK_LOW, min( self::CHUNK_MAX, (int) $settings['chunk_kb'] * 1024 ) );
		$post     = Limits::post_max_size_bytes();
		if ( $post > 0 ) {
			$size = min( $size, intdiv( $post, 2 ) );
		}
		return max( self::CHUNK_MIN, $size );
	}

	/**
	 * Free bytes on the uploads volume, or -1 when the host does not say. The filter
	 * softglaze_sp_free_space may only report less space than the host does (or add a figure
	 * where the host gives none), so it can make the check stricter, never weaker.
	 *
	 * @return float
	 */
	public static function free_space() {
		$real    = -1.0;
		$uploads = wp_upload_dir( null, false );
		if ( function_exists( 'disk_free_space' ) && empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) ) {
			$dir = is_dir( $uploads['basedir'] ) ? $uploads['basedir'] : WP_CONTENT_DIR;
			// Many hosts disable the function or deny it on the volume; that means "unknown".
			$free = @disk_free_space( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a disabled or denied disk_free_space() reports unknown, not an error.
			if ( is_numeric( $free ) && $free >= 0 ) {
				$real = (float) $free;
			}
		}

		/**
		 * Reports less free space on the uploads volume (bytes). It can only lower the figure.
		 *
		 * @param float $free Free bytes, -1 when unknown.
		 */
		$filtered = apply_filters( 'softglaze_sp_free_space', $real );
		if ( is_numeric( $filtered ) && (float) $filtered >= 0 && ( $real < 0 || (float) $filtered < $real ) ) {
			return (float) $filtered;
		}
		return $real;
	}

	/**
	 * Why a push with this much media cannot start here, or '' (S-64): the per-push cap, then 80%
	 * of the free space when it is known.
	 *
	 * @param int $bytes Media total the source declared.
	 * @return string '' or push_cap / free_space.
	 */
	public static function storage_reason( $bytes ) {
		$bytes = (int) $bytes;
		if ( $bytes <= 0 ) {
			return '';
		}
		if ( $bytes > self::caps()['max_push'] ) {
			return 'push_cap';
		}
		$free = self::free_space();
		if ( $free >= 0 && $bytes > self::FREE_SHARE * $free ) {
			return 'free_space';
		}
		return '';
	}

	// --- R10: the need list --------------------------------------------------------------------

	/**
	 * POST /remote/push/{id}/files: which files live needs (blueprint §9 step 3).
	 *
	 * Body: chunk_size and files [{ref, path, size, sha256, attachment}], each read by name. One
	 * refused path or oversize file refuses the whole call. Existing files are hashed within the
	 * time budget; files not reached are answered as "later" and not stored.
	 *
	 * @param array $session Session (this pairing's).
	 * @param array $pairing Pairing row.
	 * @param array $body    Decoded body.
	 * @return array|WP_Error
	 */
	public static function announce( array $session, array $pairing, array $body ) {
		$open = self::files_open( $session );
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		$chunk = isset( $body['chunk_size'] ) && is_int( $body['chunk_size'] ) ? $body['chunk_size'] : 0;
		if ( $chunk < self::CHUNK_MIN || $chunk > self::chunk_bytes() ) {
			return self::error( 'invalid_chunk_size', 400 );
		}
		if ( ! isset( $body['files'] ) || ! is_array( $body['files'] ) || array() === $body['files'] || ! wp_is_numeric_array( $body['files'] ) ) {
			return self::error( 'invalid_body', 400 );
		}
		if ( count( $body['files'] ) > self::MAX_FILES_PER_CALL ) {
			return self::error( 'too_many_files', 400 );
		}

		// Every entry is read and checked before anything is stored.
		$caps    = self::caps();
		$entries = array();
		$paths   = array();
		foreach ( array_values( $body['files'] ) as $raw ) {
			$entry = self::entry( $raw );
			if ( null === $entry || isset( $entries[ $entry['ref'] ] ) ) {
				return self::error( 'invalid_body', 400 );
			}
			$reason = Path_Guard::reason( $entry['path'] );
			if ( '' !== $reason ) {
				self::audit_refusal( $session, $pairing, 'path_refused', $reason, $entry['ref'] );
				return self::error( 'path_refused', 400 );
			}
			if ( $entry['size'] > $caps['max_file'] ) {
				self::audit_refusal( $session, $pairing, 'file_too_large', 'max_file', $entry['ref'] );
				return self::error( 'file_too_large', 400 );
			}
			$key = strtolower( $entry['path'] );
			if ( isset( $paths[ $key ] ) ) {
				return self::error( 'invalid_body', 400 );
			}
			$paths[ $key ]            = true;
			$entries[ $entry['ref'] ] = $entry;
		}

		$existing = self::rows( (int) $session['id'], array_keys( $entries ) );
		$new      = array();
		$bytes    = 0;
		foreach ( $entries as $ref => $entry ) {
			if ( isset( $existing[ $ref ] ) ) {
				$t = $existing[ $ref ]['t'];
				if ( $t['path'] !== $entry['path'] || (int) $t['size'] !== $entry['size'] || ! hash_equals( (string) $t['sha256'], $entry['sha256'] ) || $t['attachment'] !== $entry['attachment'] ) {
					return self::error( 'file_mismatch', 409 );
				}
				continue;
			}
			$new[ $ref ] = $entry;
			$bytes      += $entry['size'];
		}

		$cursor   = $session['cursor_json'];
		$declared = $session['counts'];
		if ( self::count_files( (int) $session['id'] ) + count( $new ) > (int) $declared['files'] ) {
			self::audit_refusal( $session, $pairing, 'file_count_exceeded', 'declared', 0 );
			return self::error( 'file_count_exceeded', 409 );
		}
		$announced = isset( $cursor['file_bytes'] ) ? (int) $cursor['file_bytes'] : 0;
		if ( $announced + $bytes > (int) $declared['file_bytes'] || $announced + $bytes > $caps['max_push'] ) {
			self::audit_refusal( $session, $pairing, 'file_bytes_exceeded', 'declared', 0 );
			return self::error( 'file_bytes_exceeded', 409 );
		}

		$base = self::base();
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$deadline = microtime( true ) + max( 2, Limits::time_budget() - 2 );
		$answers  = array();
		$later    = array();
		$counts   = array(
			'have'    => 0,
			'need'    => 0,
			'renamed' => 0,
		);
		$stored   = 0;
		foreach ( $entries as $ref => $entry ) {
			if ( isset( $existing[ $ref ] ) ) {
				$row = $existing[ $ref ];
				if ( 'need' === $row['status'] && (int) $row['t']['chunk'] !== $chunk && $entry['size'] > 0 ) {
					// The source halved its chunk size (413): the chunks start again at that size.
					$row = self::reset_chunks( $session, $row, $chunk );
				}
				$answers[] = self::answer( $row );
				continue;
			}
			if ( microtime( true ) > $deadline ) {
				$later[] = $ref;
				continue;
			}

			$decision = self::decide( $session, $pairing, $entry, $base );
			if ( is_wp_error( $decision ) ) {
				return $decision;
			}
			$row = self::insert( (int) $session['id'], $entry, $decision, $chunk );
			if ( null === $row ) {
				// Stored by a parallel call a moment ago: report what is stored.
				$again = self::rows( (int) $session['id'], array( $ref ) );
				if ( isset( $again[ $ref ] ) ) {
					$answers[] = self::answer( $again[ $ref ] );
				}
				continue;
			}
			++$counts[ $decision['answer'] ];
			$stored   += $entry['size'];
			$answers[] = self::answer( $row );
		}

		$values = array( 'file_bytes' => $announced + $stored );
		if ( $counts['need'] + $counts['renamed'] > 0 && ! self::temp_name( $session ) ) {
			$values['tmp'] = bin2hex( random_bytes( 16 ) );
		}
		Push_Session::cursor_set( (int) $session['id'], $values );
		Push_Lock::heartbeat( $session['push_uuid'] );

		if ( array_sum( $counts ) > 0 ) {
			self::audit( 'files_needed', $session, $pairing, array_merge( $counts, array( 'later' => count( $later ) ) ) );
		}

		return array(
			'push'       => (string) $session['push_uuid'],
			'chunk_size' => $chunk,
			'files'      => $answers,
			'later'      => $later,
			'counts'     => $counts,
		);
	}

	/**
	 * One need-list entry read by name, or null when malformed.
	 *
	 * @param mixed $raw Entry.
	 * @return array|null ref, path, size, sha256, attachment.
	 */
	private static function entry( $raw ) {
		if ( ! is_array( $raw ) || ! isset( $raw['ref'], $raw['path'], $raw['size'], $raw['sha256'], $raw['attachment'] ) ) {
			return null;
		}
		if ( ! is_int( $raw['ref'] ) || $raw['ref'] < 1 || $raw['ref'] > self::MAX_DECLARED_FILES ) {
			return null;
		}
		if ( ! is_string( $raw['path'] ) || ! is_int( $raw['size'] ) || $raw['size'] < 0 ) {
			return null;
		}
		if ( ! is_string( $raw['sha256'] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $raw['sha256'] ) ) {
			return null;
		}
		if ( ! is_string( $raw['attachment'] ) || ! wp_is_uuid( $raw['attachment'], 4 ) || strtolower( $raw['attachment'] ) !== $raw['attachment'] ) {
			return null;
		}
		return array(
			'ref'        => $raw['ref'],
			'path'       => $raw['path'],
			'size'       => $raw['size'],
			'sha256'     => $raw['sha256'],
			'attachment' => $raw['attachment'],
		);
	}

	/**
	 * Have, need or renamed for one new file (blueprint §9 step 3).
	 *
	 * @param array  $session Session.
	 * @param array  $pairing Pairing row.
	 * @param array  $entry   Entry.
	 * @param string $base    Uploads base directory.
	 * @return array{answer:string,status:string,final:string}|WP_Error
	 */
	private static function decide( array $session, array $pairing, array $entry, $base ) {
		$state = self::disk_state( $base, $entry );
		if ( 'same' === $state ) {
			return array(
				'answer' => 'have',
				'status' => 'have',
				'final'  => $entry['path'],
			);
		}

		// Free, or the file this very attachment has on live (replaced in Phase 7, after its
		// snapshot): the path is kept if no other file of this push reserved it.
		if ( 'absent' === $state || self::owned_by( $pairing, $entry['attachment'], $entry['path'] ) ) {
			if ( self::reserve( (int) $session['id'], $entry['path'], $entry['ref'] ) ) {
				return array(
					'answer' => 'need',
					'status' => 'need',
					'final'  => $entry['path'],
				);
			}
		}

		// Another file is there (or another file of this push takes the name): a unique name.
		$final = self::unique_path( (int) $session['id'], $base, $entry );
		if ( '' === $final ) {
			self::audit_refusal( $session, $pairing, 'path_conflict', 'rename', $entry['ref'] );
			return self::error( 'path_conflict', 409 );
		}
		return array(
			'answer' => 'renamed',
			'status' => 'need',
			'final'  => $final,
		);
	}

	/**
	 * What is at a path on live: absent, the same file, or something else. Only a regular file
	 * inside the uploads base is read; a link, a folder or a path that resolves elsewhere is
	 * "other" and never opened.
	 *
	 * @param string $base  Uploads base.
	 * @param array  $entry Entry.
	 * @return string absent, same or other.
	 */
	private static function disk_state( $base, array $entry ) {
		$full = $base . '/' . $entry['path'];
		if ( ! file_exists( $full ) && ! is_link( $full ) ) {
			return 'absent';
		}
		if ( is_link( $full ) || ! is_file( $full ) || ! self::inside( $full, $base ) ) {
			return 'other';
		}
		$size = filesize( $full );
		if ( false === $size || (int) $size !== $entry['size'] ) {
			return 'other';
		}
		$hash = hash_file( 'sha256', $full );
		return is_string( $hash ) && hash_equals( $entry['sha256'], $hash ) ? 'same' : 'other';
	}

	/**
	 * Whether live's own attachment with this UUID (UUID meta, or the pairing's linked sync
	 * state) has this file: then the file is that attachment's own and may be replaced.
	 *
	 * @param array  $pairing Pairing row.
	 * @param string $uuid    Attachment UUID.
	 * @param string $path    Relative path.
	 * @return bool
	 */
	private static function owned_by( array $pairing, $uuid, $path ) {
		$ids = Identity::find( 'post', $uuid );
		$row = Sync_State::find( (int) $pairing['id'], $uuid );
		if ( is_array( $row ) && in_array( $row['match_confidence'], Sync_State::LINKED, true ) && (int) $row['object_id'] > 0 ) {
			$ids[] = (int) $row['object_id'];
		}
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( 'attachment' !== get_post_type( $id ) ) {
				continue;
			}
			$own = Path_Guard::attachment_paths( (string) get_post_meta( $id, '_wp_attached_file', true ), wp_get_attachment_metadata( $id ) );
			if ( in_array( strtolower( $path ), array_map( 'strtolower', $own ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A free name in the same folder: core's wp_unique_filename() (which avoids files on disk),
	 * then the same with a counter while another file of this push holds the name.
	 *
	 * @param int    $push_id Session row ID.
	 * @param string $base    Uploads base.
	 * @param array  $entry   Entry.
	 * @return string The new relative path, or '' when none was found.
	 */
	private static function unique_path( $push_id, $base, array $entry ) {
		$dir    = dirname( $entry['path'] );
		$prefix = '.' === $dir ? '' : $dir . '/';
		$folder = '' === $prefix ? $base : $base . '/' . $dir;
		$name   = wp_basename( $entry['path'] );
		$dot    = strrpos( $name, '.' );
		$stem   = false === $dot ? $name : substr( $name, 0, $dot );
		$ext    = false === $dot ? '' : substr( $name, $dot );

		for ( $i = 1; $i <= self::RENAME_TRIES; $i++ ) {
			$candidate = 1 === $i ? $name : $stem . '-' . $i . $ext;
			$unique    = wp_unique_filename( $folder, $candidate );
			$final     = $prefix . $unique;
			if ( wp_basename( $final ) !== $unique || '' !== Path_Guard::reason( $final ) ) {
				continue;
			}
			if ( self::reserve( $push_id, $final, $entry['ref'] ) ) {
				return $final;
			}
		}
		return '';
	}

	/**
	 * Reserves a final path for one file of a push (the unique key makes it atomic).
	 *
	 * @param int    $push_id Session row ID.
	 * @param string $path    Final relative path.
	 * @param int    $ref     File ref.
	 * @return bool Whether this file now holds the path.
	 */
	private static function reserve( $push_id, $path, $ref ) {
		global $wpdb;

		$uuid = 'path:' . sha1( strtolower( $path ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; INSERT IGNORE on the unique key reserves the name atomically.
		$result = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (push_id, side, seq, uuid, object_type, action, status) VALUES (%d, %s, %d, %s, %s, %s, %s)', Tables::table_name( 'push_items' ), absint( $push_id ), self::SIDE, absint( $ref ), $uuid, self::RESERVATION, 'file', 'reserved' ) );
		if ( 1 === (int) $result ) {
			return true;
		}
		// Held already: by this same file (an answer sent again) it still counts as its own.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; unique key lookup.
		$owner = $wpdb->get_var( $wpdb->prepare( 'SELECT seq FROM %i WHERE push_id = %d AND side = %s AND uuid = %s', Tables::table_name( 'push_items' ), absint( $push_id ), self::SIDE, $uuid ) );
		return null !== $owner && (int) $owner === (int) $ref;
	}

	/**
	 * Stores a new file row.
	 *
	 * @param int   $push_id  Session row ID.
	 * @param array $entry    Entry.
	 * @param array $decision Decision.
	 * @param int   $chunk    Chunk size.
	 * @return array|null The row, or null when a row for the ref exists already.
	 */
	private static function insert( $push_id, array $entry, array $decision, $chunk ) {
		global $wpdb;

		$chunks   = 'need' === $decision['status'] && $entry['size'] > 0 ? (int) ceil( $entry['size'] / $chunk ) : 0;
		$transfer = array(
			'path'       => $entry['path'],
			'final'      => $decision['final'],
			'size'       => $entry['size'],
			'sha256'     => $entry['sha256'],
			'attachment' => $entry['attachment'],
			'chunk'      => $chunk,
			'chunks'     => $chunks,
			'got'        => str_repeat( '0', $chunks ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; INSERT IGNORE keeps the first row of a ref (files are idempotent by push and ref).
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (push_id, side, seq, uuid, object_type, action, decision, status, payload, refs, transfer, post_hash, error) VALUES (%d, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
				Tables::table_name( 'push_items' ),
				absint( $push_id ),
				self::SIDE,
				absint( $entry['ref'] ),
				'file:' . absint( $entry['ref'] ),
				self::KIND,
				'file',
				$decision['answer'],
				$decision['status'],
				'{"v":1}',
				'[]',
				(string) wp_json_encode( $transfer ),
				$entry['sha256'],
				''
			)
		);
		if ( 1 !== (int) $result ) {
			return null;
		}
		$rows = self::rows( $push_id, array( $entry['ref'] ) );
		return isset( $rows[ $entry['ref'] ] ) ? $rows[ $entry['ref'] ] : null;
	}

	/**
	 * Starts a file's chunks again at another size (the source halved it after a 413).
	 *
	 * @param array $session Session.
	 * @param array $row     Row.
	 * @param int   $chunk   New chunk size.
	 * @return array The row.
	 */
	private static function reset_chunks( array $session, array $row, $chunk ) {
		$dir = self::temp_dir( $session, false );
		if ( is_string( $dir ) && '' !== $dir ) {
			$fs = self::filesystem();
			if ( ! is_wp_error( $fs ) ) {
				for ( $n = 0; $n < (int) $row['t']['chunks']; $n++ ) {
					$part = $dir . '/' . $row['ref'] . '.part' . $n;
					if ( $fs->exists( $part ) ) {
						$fs->delete( $part );
					}
				}
			}
		}
		$t           = $row['t'];
		$t['chunk']  = $chunk;
		$t['chunks'] = (int) ceil( (int) $t['size'] / $chunk );
		$t['got']    = str_repeat( '0', $t['chunks'] );
		self::update_row( $row['id'], array( 'transfer' => $t ) );
		$row['t'] = $t;
		return $row;
	}

	/**
	 * A file's answer to the source.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function answer( array $row ) {
		$t   = $row['t'];
		$out = array(
			'ref'        => (int) $row['ref'],
			'status'     => 'need' === $row['status'] && 'renamed' === $row['decision'] ? 'renamed' : (string) $row['status'],
			'path'       => (string) $t['final'],
			'chunk_size' => (int) $t['chunk'],
			'chunks'     => (int) $t['chunks'],
			'next'       => self::next_chunk( $t ),
		);
		if ( 'refused' === $row['status'] ) {
			$out['reason'] = (string) $row['error'];
		}
		return $out;
	}

	// --- R11: one chunk ------------------------------------------------------------------------

	/**
	 * PUT /remote/push/{id}/files/{ref}/chunks/{n}: stores one chunk (blueprint §9 step 4).
	 *
	 * @param array  $session Session (this pairing's).
	 * @param array  $pairing Pairing row.
	 * @param string $ref_raw ref from the URL.
	 * @param string $n_raw   n from the URL.
	 * @param string $bytes   Raw body.
	 * @param mixed  $sha     X-SGSP-Chunk-SHA256.
	 * @return array|WP_Error
	 */
	public static function put_chunk( array $session, array $pairing, $ref_raw, $n_raw, $bytes, $sha ) {
		$open = self::files_open( $session );
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		$ref = self::url_int( $ref_raw );
		if ( $ref < 1 ) {
			self::audit_refusal( $session, $pairing, 'ref_out_of_range', 'ref', 0 );
			return self::error( 'ref_out_of_range', 400 );
		}
		$row = self::row( (int) $session['id'], $ref );
		if ( null === $row ) {
			return self::error( 'file_not_found', 404 );
		}
		if ( 'need' !== $row['status'] ) {
			return self::error( 'committed' === $row['status'] ? 'file_committed' : 'file_not_needed', 409 );
		}

		$t = $row['t'];
		$n = self::url_int( $n_raw );
		if ( $n < 0 || $n >= (int) $t['chunks'] ) {
			self::audit_refusal( $session, $pairing, 'chunk_out_of_range', 'n', $ref );
			return self::error( 'chunk_out_of_range', 400 );
		}

		$bytes  = (string) $bytes;
		$length = strlen( $bytes );
		if ( $length > (int) $t['chunk'] ) {
			self::audit_refusal( $session, $pairing, 'chunk_too_large', 'body', $ref );
			return self::error( 'chunk_too_large', 413 );
		}
		if ( self::chunk_length( $t, $n ) !== $length ) {
			self::audit_refusal( $session, $pairing, 'chunk_size_mismatch', 'body', $ref );
			return self::error( 'chunk_size_mismatch', 400 );
		}
		$hash = hash( 'sha256', $bytes );
		if ( ! is_string( $sha ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $sha ) || ! hash_equals( $hash, $sha ) ) {
			self::audit_refusal( $session, $pairing, 'chunk_hash_mismatch', 'chunk', $ref );
			return self::error( 'chunk_hash_mismatch', 400 );
		}

		$dir = self::temp_dir( $session, true );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$part      = $dir . '/' . $ref . '.part' . $n;
		$duplicate = $fs->exists( $part ) && hash_equals( $hash, (string) hash_file( 'sha256', $part ) );
		if ( ! $duplicate ) {
			$free = self::free_space();
			if ( $free >= 0 && $free < $length + self::CHUNK_MAX ) {
				self::audit_refusal( $session, $pairing, 'storage_full', 'chunk', $ref );
				return self::error( 'storage_full', 400 );
			}
			if ( ! $fs->put_contents( $part, $bytes, FS_CHMOD_FILE ) ) {
				return self::error( 'storage_unwritable', 500 );
			}
		}

		// The bitmap is read again just before it is written, so chunks of one file sent close
		// together do not undo each other; commit checks every part on disk anyway.
		$fresh = self::row( (int) $session['id'], $ref );
		$t     = null === $fresh ? $t : $fresh['t'];
		if ( isset( $t['got'][ $n ] ) && '1' !== $t['got'][ $n ] ) {
			$t['got'][ $n ] = '1';
			self::update_row( $row['id'], array( 'transfer' => $t ) );
		}
		Push_Lock::heartbeat( $session['push_uuid'] );

		return array(
			'ref'       => $ref,
			'n'         => $n,
			'duplicate' => $duplicate,
			'next'      => self::next_chunk( $t ),
			'chunks'    => (int) $t['chunks'],
		);
	}

	// --- R12: commit ---------------------------------------------------------------------------

	/**
	 * POST /remote/push/{id}/files/{ref}/commit: one file from its parts, checked (blueprint §9
	 * step 5). The file stays in the push's temporary folder until apply.
	 *
	 * @param array  $session Session (this pairing's).
	 * @param array  $pairing Pairing row.
	 * @param string $ref_raw ref from the URL.
	 * @return array|WP_Error
	 */
	public static function commit( array $session, array $pairing, $ref_raw ) {
		$open = self::files_open( $session );
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		$ref = self::url_int( $ref_raw );
		if ( $ref < 1 ) {
			self::audit_refusal( $session, $pairing, 'ref_out_of_range', 'ref', 0 );
			return self::error( 'ref_out_of_range', 400 );
		}
		$row = self::row( (int) $session['id'], $ref );
		if ( null === $row ) {
			return self::error( 'file_not_found', 404 );
		}
		if ( in_array( $row['status'], array( 'committed', 'have' ), true ) ) {
			// Sent again (its answer was lost), or never needed: the stored result.
			return self::commit_answer( $row );
		}
		if ( 'refused' === $row['status'] ) {
			return self::error( (string) $row['error'], 400 );
		}

		$t = $row['t'];
		if ( false !== strpos( (string) $t['got'], '0' ) ) {
			return self::error( 'chunks_missing', 409 );
		}

		$dir = self::temp_dir( $session, true );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$bin = $dir . '/' . $ref . '.bin';
		if ( ! $fs->put_contents( $bin, '', FS_CHMOD_FILE ) ) {
			return self::error( 'storage_unwritable', 500 );
		}
		$ctx   = hash_init( 'sha256' );
		$total = 0;
		for ( $n = 0; $n < (int) $t['chunks']; $n++ ) {
			$data = $fs->get_contents( $dir . '/' . $ref . '.part' . $n );
			if ( false === $data || strlen( $data ) !== self::chunk_length( $t, $n ) ) {
				// A part went missing on disk: the source sends it again.
				$fs->delete( $bin );
				$t['got'][ $n ] = '0';
				self::update_row( $row['id'], array( 'transfer' => $t ) );
				return self::error( 'chunks_missing', 409 );
			}
			hash_update( $ctx, $data );
			$total += strlen( $data );
			// WP_Filesystem has no append, and holding the whole file (up to 512 MB) in memory is
			// not an option: the direct method was confirmed above and the path is ours.
			if ( false === file_put_contents( $bin, $data, FILE_APPEND ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- append one part at a time; WP_Filesystem (direct, checked) cannot append.
				$fs->delete( $bin );
				return self::error( 'storage_unwritable', 500 );
			}
			unset( $data );
		}
		$sha = hash_final( $ctx );

		$reason = '';
		if ( $total !== (int) $t['size'] ) {
			$reason = 'size_mismatch';
		} elseif ( ! hash_equals( (string) $t['sha256'], $sha ) ) {
			$reason = 'checksum_mismatch';
		} else {
			$reason = self::type_reason( $bin, (string) $t['final'] );
		}

		if ( '' !== $reason ) {
			self::delete_file_parts( $fs, $dir, $ref, (int) $t['chunks'] );
			$fs->delete( $bin );
			self::update_row(
				$row['id'],
				array(
					'status' => 'refused',
					'error'  => $reason,
				)
			);
			self::audit_refusal( $session, $pairing, $reason, 'commit', $ref );
			Push_Lock::heartbeat( $session['push_uuid'] );
			return self::error( $reason, 400 );
		}

		self::delete_file_parts( $fs, $dir, $ref, (int) $t['chunks'] );
		$t['committed_gmt'] = gmdate( 'Y-m-d H:i:s' );
		self::update_row(
			$row['id'],
			array(
				'status'   => 'committed',
				'transfer' => $t,
			)
		);
		Push_Lock::heartbeat( $session['push_uuid'] );

		self::audit(
			'file_committed',
			$session,
			$pairing,
			array(
				'ref'     => $ref,
				'bytes'   => (int) $t['size'],
				'renamed' => 'renamed' === $row['decision'],
			)
		);

		$row['status'] = 'committed';
		$row['t']      = $t;
		return self::commit_answer( $row );
	}

	/**
	 * Why the committed bytes are not acceptable under their final name, or '': core's check of
	 * the real content against the name (for a user without unfiltered_upload), and a readable
	 * image for image types.
	 *
	 * @param string $bin    Path of the committed file.
	 * @param string $target Final relative path.
	 * @return string
	 */
	private static function type_reason( $bin, $target ) {
		$name  = wp_basename( $target );
		$check = wp_check_filetype_and_ext( $bin, $name, Path_Guard::allowed_mime_types() );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! Path_Guard::mime_allowed( $check['type'] ) ) {
			return 'file_type_refused';
		}
		if ( ! empty( $check['proper_filename'] ) ) {
			// The bytes are another type than the name says: refused, never renamed.
			return 'file_type_mismatch';
		}
		if ( 0 === strpos( (string) $check['type'], 'image/' ) && false === wp_getimagesize( $bin ) ) {
			return 'image_unreadable';
		}
		return '';
	}

	/**
	 * The answer to a commit.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function commit_answer( array $row ) {
		return array(
			'ref'    => (int) $row['ref'],
			'status' => (string) $row['status'],
			'path'   => (string) $row['t']['final'],
			'size'   => (int) $row['t']['size'],
			'sha256' => (string) $row['t']['sha256'],
		);
	}

	// --- the files phase and cleanup -----------------------------------------------------------

	/**
	 * Whether the session still takes files: open or receiving, holding the lock, and no item
	 * batch received yet (files come first; blueprint §12.1).
	 *
	 * @param array $session Session.
	 * @return true|WP_Error
	 */
	private static function files_open( array $session ) {
		if ( ! in_array( $session['state'], array( 'open', 'receiving' ), true ) ) {
			return 'expired' === $session['state'] ? self::error( 'push_expired', 410 ) : self::error( 'push_' . sanitize_key( (string) $session['state'] ), 409 );
		}
		$lock = Push_Lock::get();
		if ( null === $lock || ! hash_equals( $lock['push_uuid'], (string) $session['push_uuid'] ) ) {
			return self::error( null === $lock ? 'lock_lost' : 'push_locked', 409 );
		}
		if ( ! empty( $session['cursor_json']['files_closed'] ) || (int) $session['cursor_json']['last_seq'] > 0 ) {
			return self::error( 'files_closed', 409 );
		}
		return true;
	}

	/**
	 * Ends the files phase when the first item batch arrives: parts of files that were never
	 * committed are deleted (their attachments are refused when staged).
	 *
	 * @param array $session Session.
	 * @return void
	 */
	public static function close_files( array $session ) {
		if ( ! empty( $session['cursor_json']['files_closed'] ) ) {
			return;
		}
		Push_Session::cursor_set( (int) $session['id'], array( 'files_closed' => true ) );

		$dir = self::temp_dir( $session, false );
		if ( ! is_string( $dir ) || '' === $dir ) {
			return;
		}
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return;
		}
		$list = $fs->dirlist( $dir, false, false );
		foreach ( is_array( $list ) ? $list : array() as $name => $info ) {
			if ( 1 === preg_match( '/\A[0-9]{1,9}\.part[0-9]{1,9}\z/', (string) $name ) && isset( $info['type'] ) && 'f' === $info['type'] ) {
				$fs->delete( $dir . '/' . $name );
			}
		}
	}

	/**
	 * Deletes a session's temporary folder (finish, abort, expiry, failed final pass).
	 *
	 * @param array $session Session.
	 * @return bool Whether it is gone.
	 */
	public static function delete_temp( array $session ) {
		$dir = self::temp_dir( $session, false );
		if ( is_wp_error( $dir ) ) {
			return false;
		}
		if ( '' === $dir ) {
			return true;
		}
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return false;
		}
		return (bool) $fs->delete( $dir, true, 'd' );
	}

	/**
	 * The daily prune (blueprint §9.8, §14.5): temporary folders older than 24 hours that no
	 * session still receiving or waiting for apply uses.
	 *
	 * @param int $max_age Seconds.
	 * @return int Folders deleted.
	 */
	public static function prune_temp( $max_age = self::TEMP_TTL ) {
		global $wpdb;

		$base = self::base();
		if ( is_wp_error( $base ) ) {
			return 0;
		}
		$tmp = $base . '/' . Installer::UPLOADS_DIR . '/tmp';
		if ( ! is_dir( $tmp ) || ! self::inside( $tmp, $base ) ) {
			return 0;
		}
		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; the folders still in use.
		$cursors = $wpdb->get_col( $wpdb->prepare( "SELECT cursor_json FROM %i WHERE side = %s AND state IN ('open','receiving','staged')", Tables::table_name( 'pushes' ), self::SIDE ) );
		$in_use  = array();
		foreach ( is_array( $cursors ) ? $cursors : array() as $json ) {
			$cursor = json_decode( (string) $json, true );
			if ( is_array( $cursor ) && isset( $cursor['tmp'] ) && is_string( $cursor['tmp'] ) ) {
				$in_use[ $cursor['tmp'] ] = true;
			}
		}

		$deleted = 0;
		$cutoff  = time() - max( 0, (int) $max_age );
		$list    = $fs->dirlist( $tmp, false, false );
		foreach ( is_array( $list ) ? $list : array() as $name => $info ) {
			$name = (string) $name;
			if ( 1 !== preg_match( '/\A[0-9a-f]{32}\z/', $name ) || ! isset( $info['type'] ) || 'd' !== $info['type'] || isset( $in_use[ $name ] ) ) {
				continue;
			}
			$modified = isset( $info['lastmodunix'] ) ? (int) $info['lastmodunix'] : 0;
			if ( $modified > 0 && $modified >= $cutoff ) {
				continue;
			}
			if ( $fs->delete( $tmp . '/' . $name, true, 'd' ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * The committed (or already present) file of a ref, for apply: absolute path of the
	 * temporary file, or '' for a file live already has.
	 *
	 * TODO(Phase 7): the attachment step moves this file to its final path after checking that
	 * realpath() of the target folder lies inside the uploads base, and rewrites URLs of renamed
	 * files with rewrites().
	 *
	 * @param array $session Session.
	 * @param int   $ref     Ref.
	 * @return string
	 */
	public static function committed_file( array $session, $ref ) {
		$dir = self::temp_dir( $session, false );
		return is_string( $dir ) && '' !== $dir ? $dir . '/' . absint( $ref ) . '.bin' : '';
	}

	// --- reading file rows ---------------------------------------------------------------------

	/**
	 * File rows of a session by ref (for the item stager).
	 *
	 * @param int   $push_id Session row ID.
	 * @param int[] $refs    Refs.
	 * @return array<int,array> ref => row (status, decision, error, t).
	 */
	public static function rows( $push_id, array $refs ) {
		global $wpdb;

		$out  = array();
		$refs = array_values( array_unique( array_filter( array_map( 'absint', $refs ) ) ) );
		foreach ( array_chunk( $refs, 50 ) as $chunk ) {
			$slots = array_pad(
				array_map(
					static function ( $ref ) {
						return 'file:' . $ref;
					},
					$chunk
				),
				50,
				''
			);
			// Fifty fixed slots keep the statement one literal string (unused slots are '').
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Own table; the replacements are passed as one array (54 values for 54 placeholders).
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, seq, status, decision, transfer, error FROM %i WHERE push_id = %d AND side = %s AND object_type = %s AND uuid IN (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)', array_merge( array( Tables::table_name( 'push_items' ), absint( $push_id ), self::SIDE, self::KIND ), $slots ) ), ARRAY_A );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$decoded                = self::decode_row( $row );
				$out[ $decoded['ref'] ] = $decoded;
			}
		}
		return $out;
	}

	/**
	 * One file row by ref, or null.
	 *
	 * @param int $push_id Session row ID.
	 * @param int $ref     Ref.
	 * @return array|null
	 */
	private static function row( $push_id, $ref ) {
		$rows = self::rows( $push_id, array( $ref ) );
		return isset( $rows[ $ref ] ) ? $rows[ $ref ] : null;
	}

	/**
	 * Counts of a session's file rows: have, need, renamed, committed, refused, total.
	 *
	 * @param int $push_id Session row ID.
	 * @return array<string,int>
	 */
	public static function summary( $push_id ) {
		global $wpdb;

		$out = array(
			'have'      => 0,
			'need'      => 0,
			'committed' => 0,
			'refused'   => 0,
			'renamed'   => 0,
			'total'     => 0,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, decision, COUNT(*) AS n FROM %i WHERE push_id = %d AND side = %s AND object_type = %s GROUP BY status, decision', Tables::table_name( 'push_items' ), absint( $push_id ), self::SIDE, self::KIND ), ARRAY_A );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$n = (int) $row['n'];
			if ( isset( $out[ $row['status'] ] ) ) {
				$out[ $row['status'] ] += $n;
			}
			if ( 'renamed' === $row['decision'] ) {
				$out['renamed'] += $n;
			}
			$out['total'] += $n;
		}
		return $out;
	}

	/**
	 * The URL rewrite list of a push: each renamed file's path on the source and its new path on
	 * live (blueprint §9 step 3). Phase 7 rewrites the pushed content with it.
	 *
	 * @param int $push_id Session row ID.
	 * @return array<string,string> path => final path.
	 */
	public static function rewrites( $push_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, seq, status, decision, transfer, error FROM %i WHERE push_id = %d AND side = %s AND object_type = %s AND decision = 'renamed' AND status <> 'refused' ORDER BY seq ASC", Tables::table_name( 'push_items' ), absint( $push_id ), self::SIDE, self::KIND ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$decoded                      = self::decode_row( $row );
			$out[ $decoded['t']['path'] ] = (string) $decoded['t']['final'];
		}
		return $out;
	}

	/**
	 * File rows of a session.
	 *
	 * @param int $push_id Session row ID.
	 * @return int
	 */
	private static function count_files( $push_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE push_id = %d AND side = %s AND object_type = %s', Tables::table_name( 'push_items' ), absint( $push_id ), self::SIDE, self::KIND ) );
	}

	/**
	 * A row with its transfer JSON decoded.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decode_row( array $row ) {
		$t = json_decode( (string) $row['transfer'], true );
		$t = array_merge(
			array(
				'path'       => '',
				'final'      => '',
				'size'       => 0,
				'sha256'     => '',
				'attachment' => '',
				'chunk'      => 0,
				'chunks'     => 0,
				'got'        => '',
			),
			is_array( $t ) ? $t : array()
		);
		return array(
			'id'       => (int) $row['id'],
			'ref'      => (int) $row['seq'],
			'status'   => (string) $row['status'],
			'decision' => (string) $row['decision'],
			'error'    => (string) $row['error'],
			't'        => $t,
		);
	}

	/**
	 * Updates a file row.
	 *
	 * @param int   $id     Row ID.
	 * @param array $fields status, error, transfer (array).
	 * @return void
	 */
	private static function update_row( $id, array $fields ) {
		global $wpdb;

		$data = array();
		foreach ( array( 'status', 'error' ) as $name ) {
			if ( isset( $fields[ $name ] ) ) {
				$data[ $name ] = (string) $fields[ $name ];
			}
		}
		if ( isset( $fields['transfer'] ) ) {
			$data['transfer'] = (string) wp_json_encode( $fields['transfer'] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update( Tables::table_name( 'push_items' ), $data, array( 'id' => absint( $id ) ) );
	}

	// --- chunks --------------------------------------------------------------------------------

	/**
	 * Bytes chunk n of a file must have.
	 *
	 * @param array $t Transfer.
	 * @param int   $n Chunk.
	 * @return int
	 */
	private static function chunk_length( array $t, $n ) {
		$chunks = (int) $t['chunks'];
		if ( $n < $chunks - 1 ) {
			return (int) $t['chunk'];
		}
		return (int) $t['size'] - (int) $t['chunk'] * ( $chunks - 1 );
	}

	/**
	 * The first chunk not received yet (= chunks when every chunk is there).
	 *
	 * @param array $t Transfer.
	 * @return int
	 */
	private static function next_chunk( array $t ) {
		$pos = strpos( (string) $t['got'], '0' );
		return false === $pos ? (int) $t['chunks'] : (int) $pos;
	}

	/**
	 * A URL integer: digits with an optional minus sign, at most nine digits; -1 otherwise.
	 *
	 * @param mixed $raw Value.
	 * @return int
	 */
	private static function url_int( $raw ) {
		$raw = is_scalar( $raw ) ? (string) $raw : '';
		if ( 1 !== preg_match( '/\A-?[0-9]{1,9}\z/', $raw ) ) {
			return -1;
		}
		return (int) $raw;
	}

	/**
	 * Deletes the parts of one file.
	 *
	 * @param \WP_Filesystem_Base $fs     Filesystem.
	 * @param string              $dir    Push folder.
	 * @param int                 $ref    Ref.
	 * @param int                 $chunks Chunks.
	 * @return void
	 */
	private static function delete_file_parts( $fs, $dir, $ref, $chunks ) {
		for ( $n = 0; $n < $chunks; $n++ ) {
			$part = $dir . '/' . $ref . '.part' . $n;
			if ( $fs->exists( $part ) ) {
				$fs->delete( $part );
			}
		}
	}

	// --- the temporary folder ------------------------------------------------------------------

	/**
	 * The uploads base directory (normalised, no trailing slash).
	 *
	 * @return string|WP_Error
	 */
	private static function base() {
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return self::error( 'storage_unwritable', 500 );
		}
		return untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );
	}

	/**
	 * The session's temporary folder name, or ''.
	 *
	 * @param array $session Session.
	 * @return string
	 */
	private static function temp_name( array $session ) {
		$fresh = Push_Session::find( (string) $session['push_uuid'] );
		$name  = is_array( $fresh ) && isset( $fresh['cursor_json']['tmp'] ) ? (string) $fresh['cursor_json']['tmp'] : '';
		return 1 === preg_match( '/\A[0-9a-f]{32}\z/', $name ) ? $name : '';
	}

	/**
	 * The session's temporary folder: uploads/keepmerge-push-staging-to-live/tmp/<32 hex>, each
	 * level with index.php and an .htaccess deny rule, written with the direct method only and
	 * resolving inside the uploads base.
	 *
	 * @param array $session Session.
	 * @param bool  $create  Create it (and name it) when missing.
	 * @return string|WP_Error The folder, or '' when it has no folder and $create is false.
	 */
	private static function temp_dir( array $session, $create ) {
		$base = self::base();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$name = self::temp_name( $session );
		if ( '' === $name ) {
			if ( ! $create ) {
				return '';
			}
			$name = bin2hex( random_bytes( 16 ) );
			Push_Session::cursor_set( (int) $session['id'], array( 'tmp' => $name ) );
		}

		$root = $base . '/' . Installer::UPLOADS_DIR;
		$tmp  = $root . '/tmp';
		$dir  = $tmp . '/' . $name;

		if ( ! $create ) {
			return is_dir( $dir ) && self::inside( $dir, $base ) ? $dir : '';
		}

		$fs = self::filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		// The uploads base itself on a site that never stored an upload (no index.php there).
		if ( ! $fs->is_dir( $base ) && ! $fs->mkdir( $base, FS_CHMOD_DIR ) ) {
			return self::error( 'storage_unwritable', 500 );
		}
		foreach ( array( $root, $tmp, $dir ) as $folder ) {
			if ( ! $fs->is_dir( $folder ) && ! $fs->mkdir( $folder, FS_CHMOD_DIR ) ) {
				return self::error( 'storage_unwritable', 500 );
			}
			// tmp/ and the push folder get index.php and the deny rule; the plugin's own folder
			// holds only tmp/ (and snapshots/ from Phase 7, protected the same way).
			if ( $root !== $folder && ! self::protect( $fs, $folder ) ) {
				return self::error( 'storage_unwritable', 500 );
			}
		}
		if ( ! self::inside( $dir, $base ) ) {
			return self::error( 'storage_unwritable', 500 );
		}
		return $dir;
	}

	/**
	 * Writes index.php and an .htaccess deny rule into a folder (blueprint §9.10). On nginx the
	 * rule is ignored: the folder name is random and file names carry no meaning.
	 *
	 * @param \WP_Filesystem_Base $fs     Filesystem.
	 * @param string              $folder Folder.
	 * @return bool
	 */
	private static function protect( $fs, $folder ) {
		$files = array(
			'index.php' => "<?php\n// Silence is golden.\n",
			'.htaccess' => "# Keepmerge temporary files are never served.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n",
		);
		foreach ( $files as $name => $content ) {
			$path = $folder . '/' . $name;
			if ( ! $fs->exists( $path ) && ! $fs->put_contents( $path, $content, FS_CHMOD_FILE ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * WP_Filesystem with the direct method only (blueprint §9 step 4): anything else would need
	 * credentials a remote request cannot give, and fails with storage_unwritable.
	 *
	 * @return \WP_Filesystem_Base|WP_Error
	 */
	private static function filesystem() {
		global $wp_filesystem;

		$base = self::base();
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$context = is_dir( $base ) ? $base : WP_CONTENT_DIR;
		if ( 'direct' !== get_filesystem_method( array(), $context, true ) ) {
			return self::error( 'storage_unwritable', 500 );
		}
		if ( ! WP_Filesystem( false, $context, true ) || ! $wp_filesystem instanceof \WP_Filesystem_Base || 'direct' !== $wp_filesystem->method ) {
			return self::error( 'storage_unwritable', 500 );
		}
		return $wp_filesystem;
	}

	/**
	 * Whether a path resolves inside the uploads base.
	 *
	 * @param string $path Path.
	 * @param string $base Uploads base.
	 * @return bool
	 */
	private static function inside( $path, $base ) {
		$real  = realpath( $path );
		$rbase = realpath( $base );
		if ( false === $real || false === $rbase ) {
			return false;
		}
		return 0 === strpos( wp_normalize_path( $real ), trailingslashit( wp_normalize_path( $rbase ) ) );
	}

	// --- audit and errors ----------------------------------------------------------------------

	/**
	 * Audit row of a refused file request: the reason and the ref, never the path or content.
	 *
	 * @param array  $session Session.
	 * @param array  $pairing Pairing row.
	 * @param string $reason  Code.
	 * @param string $field   What was refused (a short word).
	 * @param int    $ref     Ref (0 when none).
	 * @return void
	 */
	private static function audit_refusal( array $session, array $pairing, $reason, $field, $ref ) {
		Audit_Log::write(
			'file_refused',
			array(
				'result'      => 'denied',
				'actor_type'  => 'remote',
				'actor_id'    => 0,
				'pairing_id'  => (int) $pairing['id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => array(
					'reason' => (string) $reason,
					'field'  => (string) $field,
					'ref'    => (int) $ref,
				),
			)
		);
	}

	/**
	 * Audit row of a file event: counts and refs only.
	 *
	 * @param string $event   Event.
	 * @param array  $session Session.
	 * @param array  $pairing Pairing row.
	 * @param array  $details Details.
	 * @return void
	 */
	private static function audit( $event, array $session, array $pairing, array $details ) {
		Audit_Log::write(
			$event,
			array(
				'result'      => 'ok',
				'actor_type'  => 'remote',
				'actor_id'    => 0,
				'pairing_id'  => (int) $pairing['id'],
				'object_type' => 'push',
				'object_ref'  => (string) $session['push_uuid'],
				'details'     => $details,
			)
		);
	}

	/**
	 * An error that carries only its code.
	 *
	 * @param string $code   Code.
	 * @param int    $status HTTP status.
	 * @return WP_Error
	 */
	private static function error( $code, $status ) {
		return new WP_Error( $code, $code, array( 'status' => $status ) );
	}
}
