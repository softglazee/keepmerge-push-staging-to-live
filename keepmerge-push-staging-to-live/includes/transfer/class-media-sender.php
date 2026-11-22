<?php
/**
 * Sending a push's media files to live (source side).
 *
 * @package SoftGlaze\SafePush
 */

namespace SoftGlaze\SafePush\Transfer;

use SoftGlaze\SafePush\Sync\Export_Context;
use SoftGlaze\SafePush\Sync\Exporters\Media;
use SoftGlaze\SafePush\Sync\Items;
use SoftGlaze\SafePush\Sync\Plan_Store;
use SoftGlaze\SafePush\Tables;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The source half of blueprint §9 steps 1, 3 and 4 and §12: the files of the attachments a push
 * sends go to live before the items (state sending_files), so that live can check each attachment
 * against files it already holds when the item arrives.
 *
 * - At start the files are counted by size only (nothing is read), so live can refuse a push it
 *   has no room for before any transfer (blueprint §9.9).
 * - While sending, each attachment is exported again (its hash must still be the planned one) and
 *   its files are recorded as rows (push_items, side "source_file", uuid "path:<sha1>", seq = the
 *   file's ref), then announced to live in need lists of at most 200 files.
 * - Files live does not have are sent in chunks of live's size; each step first asks live where a
 *   file stands, so a step that died mid-file resumes at the next chunk live is missing (I-02).
 * - A chunk damaged on the way is sent again (I-03); a 413 halves the chunk size (≥ 64 KB).
 *
 * The rows are bookkeeping for one push; they are deleted when the push ends.
 */
final class Media_Sender {

	const SIDE = 'source_file';

	const KIND = 'file';

	/**
	 * Plan actions that send an attachment's data (and so its files).
	 */
	const SEND_ACTIONS = array( 'create', 'push', 'use_staging', 'recreate' );

	/**
	 * Files per need list.
	 */
	const ANNOUNCE_MAX = 200;

	/**
	 * Times a damaged chunk is sent again before the push pauses.
	 */
	const CHUNK_RETRIES = 3;

	/**
	 * Default chunk size when live does not name one.
	 */
	const CHUNK_DEFAULT = 1048576;

	/**
	 * Codes after which the push pauses during file transfer (Resume once fixed on live).
	 */
	const PAUSE_CODES = array( 'storage_full', 'storage_unwritable', 'chunk_hash_mismatch', 'body_hash_mismatch' );

	const ROUTE = '/keepmerge/v1/remote/push';

	/**
	 * The file plan of a push, by size only: every file of the attachments sent with data that the
	 * path guard accepts and that exists. An attachment with a file over the per-file cap is not
	 * sent (a too_large stub); a media total over the per-push cap stops the push before any
	 * request (blueprint §9.9).
	 *
	 * @param array $attachments List of array( uuid, local ID ).
	 * @return array{media:array,files:int,bytes:int,stubs:array}|WP_Error
	 */
	public static function plan( array $attachments ) {
		$caps  = Media_Receiver::caps();
		$seen  = array();
		$media = array();
		$stubs = array();
		$bytes = 0;

		foreach ( $attachments as $attachment ) {
			list( $uuid, $local_id ) = $attachment;
			$files                   = self::local_files( (int) $local_id, false );
			$too_large               = false;
			foreach ( $files as $file ) {
				if ( $file['size'] > $caps['max_file'] ) {
					$too_large = true;
				}
			}
			if ( $too_large ) {
				$stubs[ (string) $uuid ] = 'too_large';
				continue;
			}
			foreach ( $files as $file ) {
				$key = strtolower( $file['path'] );
				if ( ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = true;
					$bytes       += $file['size'];
				}
			}
			$media[] = array( (string) $uuid, (int) $local_id );
		}

		if ( $bytes > $caps['max_push'] ) {
			return new WP_Error(
				'media_too_large',
				Remote_Client::message( 'media_too_large' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'media' => $media,
			'files' => count( $seen ),
			'bytes' => $bytes,
			'stubs' => $stubs,
		);
	}

	/**
	 * The chunk size to use: live's own (it is never above half of live's post_max_size), between
	 * 64 KB and 8 MB.
	 *
	 * @param mixed $limits Live's limits from its answer.
	 * @return int
	 */
	public static function chunk_from( $limits ) {
		$live = is_array( $limits ) && isset( $limits['chunk_bytes'] ) && is_int( $limits['chunk_bytes'] ) ? $limits['chunk_bytes'] : self::CHUNK_DEFAULT;
		return max( Media_Receiver::CHUNK_MIN, min( Media_Receiver::CHUNK_MAX, $live ) );
	}

	/**
	 * The files part of a new push's cursor.
	 *
	 * @param array $plan  plan().
	 * @param int   $chunk Chunk size.
	 * @return array
	 */
	public static function cursor( array $plan, $chunk ) {
		return array(
			'total'      => (int) $plan['files'],
			'bytes'      => (int) $plan['bytes'],
			'pos'        => 0,
			'chunk'      => (int) $chunk,
			'sent_bytes' => 0,
			'have'       => 0,
			'need'       => 0,
			'renamed'    => 0,
			'committed'  => 0,
			'refused'    => 0,
			'current'    => null,
		);
	}

	/**
	 * Works on the files of a push until the step's time is up.
	 *
	 * @param array          $row      Push row.
	 * @param array          $cursor   Cursor (changed).
	 * @param mixed          $client   Client.
	 * @param Export_Context $ctx      Export context.
	 * @param float          $deadline Deadline (microtime).
	 * @return array{state:string,error:WP_Error|null} sending_files (more to do), sending_items
	 *                                                 (every file settled), paused or failed.
	 */
	public static function step( array $row, array &$cursor, $client, Export_Context $ctx, $deadline ) {
		$plan_id = (int) $row['id'];
		$synced  = array();
		$media   = count( $cursor['push']['media'] );

		while ( microtime( true ) < $deadline - 1 ) {
			// 1. Files recorded but not answered by live yet.
			$listed = self::rows_with_status( $plan_id, 'listed', self::ANNOUNCE_MAX );
			if ( ! empty( $listed ) ) {
				$out = self::announce( $row, $cursor, $client, $listed, $deadline );
				if ( null !== $out ) {
					return $out;
				}
				continue;
			}

			// 2. Attachments not looked at yet: their files become rows.
			if ( (int) $cursor['push']['files']['pos'] < $media ) {
				self::collect( $plan_id, $cursor, $ctx, $deadline );
				Plan_Store::update( $plan_id, array( 'cursor_json' => $cursor ) );
				continue;
			}

			// 3. A file live still needs.
			$need = self::rows_with_status( $plan_id, 'need', 1 );
			if ( ! empty( $need ) ) {
				$out = self::send_file( $row, $cursor, $client, $need[0], $deadline, $synced );
				if ( null !== $out ) {
					return $out;
				}
				continue;
			}

			// 4. Every file is on live, committed, or refused.
			$f                                  = $cursor['push']['files'];
			$cursor['push']['files']['current'] = null;
			self::log(
				$cursor,
				'files_done',
				array(
					'have'      => (int) $f['have'],
					'committed' => (int) $f['committed'],
					'renamed'   => (int) $f['renamed'],
					'refused'   => (int) $f['refused'],
				)
			);
			return array(
				'state' => 'sending_items',
				'error' => null,
			);
		}

		return array(
			'state' => 'sending_files',
			'error' => null,
		);
	}

	/**
	 * The media map of an attachment envelope: each sent file's path and its ref (Item_Stager
	 * finds the file rows with it).
	 *
	 * @param int   $plan_id Push ID.
	 * @param mixed $files   The item's data.files.
	 * @return array[] List of array( path, ref ).
	 */
	public static function media_map( $plan_id, $files ) {
		$out = array();
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['path'] ) || ! is_string( $file['path'] ) ) {
				continue;
			}
			$row = self::row_by_path( $plan_id, $file['path'] );
			if ( null !== $row ) {
				$out[] = array(
					'path' => $file['path'],
					'ref'  => (int) $row['ref'],
				);
			}
		}
		return $out;
	}

	/**
	 * Deletes a push's file rows (the push ended; the counts stay in its cursor).
	 *
	 * @param int $plan_id Push ID.
	 * @return int Rows deleted.
	 */
	public static function forget( $plan_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE push_id = %d AND side = %s', Tables::table_name( 'push_items' ), absint( $plan_id ), self::SIDE ) );
	}

	/**
	 * Files of a local attachment that can be sent: accepted by the path guard here too (live
	 * checks again) and present on disk.
	 *
	 * @param int  $local_id Attachment ID.
	 * @param bool $hash     Whether to read the files for their SHA-256.
	 * @return array[] path, size, sha256.
	 */
	public static function local_files( $local_id, $hash ) {
		$file     = (string) get_post_meta( $local_id, '_wp_attached_file', true );
		$metadata = get_post_meta( $local_id, '_wp_attachment_metadata', true );
		$out      = array();
		foreach ( Media::files( $file, is_array( $metadata ) ? $metadata : array(), $hash ) as $entry ) {
			if ( $entry['size'] >= 0 && '' === Path_Guard::reason( $entry['path'] ) ) {
				$out[] = $entry;
			}
		}
		return $out;
	}

	// --- the steps -----------------------------------------------------------------------------

	/**
	 * Exports the next attachments and records their files (hashed now) as rows.
	 *
	 * @param int            $plan_id  Push ID.
	 * @param array          $cursor   Cursor (changed).
	 * @param Export_Context $ctx      Context.
	 * @param float          $deadline Deadline.
	 * @return void
	 */
	private static function collect( $plan_id, array &$cursor, Export_Context $ctx, $deadline ) {
		$media = $cursor['push']['media'];
		$total = count( $media );
		$added = 0;
		while ( (int) $cursor['push']['files']['pos'] < $total && $added < self::ANNOUNCE_MAX && microtime( true ) < $deadline - 1 ) {
			list( $uuid, $local_id ) = $media[ (int) $cursor['push']['files']['pos'] ];
			++$cursor['push']['files']['pos'];
			if ( isset( $cursor['push']['stubs'][ $uuid ] ) ) {
				continue;
			}
			$plan_row = Plan_Store::item( $plan_id, $uuid );
			if ( null === $plan_row ) {
				continue;
			}
			$ctx->normalizer->reset();
			$item = Items::export( 'attachment', (int) $local_id, $ctx );
			if ( null === $item || ! empty( $item['unsupported'] ) ) {
				$cursor['push']['stubs'][ $uuid ] = 'not_exportable';
				continue;
			}
			// Only what the admin reviewed is sent: a changed attachment waits for a new plan, and
			// its files are not sent either.
			if ( ! isset( $plan_row['payload']['s'] ) || ! hash_equals( (string) $plan_row['payload']['s'], (string) $item['hash'] ) ) {
				$cursor['push']['stubs'][ $uuid ] = 'changed_since_plan';
				continue;
			}
			$files = isset( $item['data']['files'] ) && is_array( $item['data']['files'] ) ? $item['data']['files'] : array();
			foreach ( $files as $file ) {
				if ( ! is_array( $file ) || ! isset( $file['path'], $file['size'], $file['sha256'] ) || ! is_int( $file['size'] ) || $file['size'] < 0 || ! is_string( $file['sha256'] ) || 1 !== preg_match( '/\A[0-9a-f]{64}\z/', $file['sha256'] ) || '' !== Path_Guard::reason( $file['path'] ) ) {
					continue;
				}
				if ( self::insert( $plan_id, $file, $uuid, (int) $local_id ) ) {
					++$added;
				}
			}
		}
	}

	/**
	 * Sends a need list and records live's answers.
	 *
	 * @param array $row      Push row.
	 * @param array $cursor   Cursor (changed).
	 * @param mixed $client   Client.
	 * @param array $rows     File rows.
	 * @param float $deadline Deadline.
	 * @return array|null Null to go on, or the step's outcome.
	 */
	private static function announce( array $row, array &$cursor, $client, array $rows, $deadline ) {
		$entries = array();
		$by_ref  = array();
		foreach ( $rows as $file ) {
			$t                            = $file['t'];
			$entries[]                    = array(
				'ref'        => (int) $file['ref'],
				'path'       => (string) $t['path'],
				'size'       => (int) $t['size'],
				'sha256'     => (string) $t['sha256'],
				'attachment' => (string) $t['attachment'],
			);
			$by_ref[ (int) $file['ref'] ] = $file;
		}

		$answer = $client->post(
			self::ROUTE . '/' . $row['push_uuid'] . '/files',
			array(
				'chunk_size' => (int) $cursor['push']['files']['chunk'],
				'files'      => $entries,
			),
			array(
				'retries' => 3,
				'budget'  => (int) max( 1, floor( $deadline - microtime( true ) ) ),
				'timeout' => 30,
			)
		);
		if ( is_wp_error( $answer ) ) {
			return self::failure( $cursor, $answer );
		}

		$counts  = array(
			'have'    => 0,
			'need'    => 0,
			'renamed' => 0,
		);
		$items   = isset( $answer['data']['files'] ) && is_array( $answer['data']['files'] ) ? $answer['data']['files'] : array();
		$later   = isset( $answer['data']['later'] ) && is_array( $answer['data']['later'] ) ? $answer['data']['later'] : array();
		$updated = 0;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['ref'], $item['status'] ) || ! is_int( $item['ref'] ) || ! isset( $by_ref[ $item['ref'] ] ) || ! is_string( $item['status'] ) ) {
				continue;
			}
			$file   = $by_ref[ $item['ref'] ];
			$t      = $file['t'];
			$status = $item['status'];
			$first  = 'listed' === $file['status'];
			if ( isset( $item['path'] ) && is_string( $item['path'] ) && '' === Path_Guard::reason( $item['path'] ) ) {
				$t['final'] = $item['path'];
			}

			if ( 'have' === $status ) {
				$new = 'have';
			} elseif ( in_array( $status, array( 'need', 'renamed' ), true ) ) {
				$new         = 'need';
				$t['chunk']  = isset( $item['chunk_size'] ) && is_int( $item['chunk_size'] ) ? $item['chunk_size'] : (int) $cursor['push']['files']['chunk'];
				$t['chunks'] = isset( $item['chunks'] ) && is_int( $item['chunks'] ) && $item['chunks'] >= 0 ? $item['chunks'] : 0;
				$t['next']   = isset( $item['next'] ) && is_int( $item['next'] ) ? max( 0, min( $t['chunks'], $item['next'] ) ) : 0;
				$t['live']   = $status;
			} elseif ( 'committed' === $status ) {
				$new = 'committed';
			} elseif ( 'refused' === $status ) {
				$new         = 'refused';
				$t['reason'] = isset( $item['reason'] ) && is_string( $item['reason'] ) ? sanitize_key( $item['reason'] ) : 'refused';
			} else {
				continue;
			}

			if ( $first ) {
				$key = 'have' === $new ? 'have' : ( 'renamed' === $status ? 'renamed' : 'need' );
				if ( isset( $counts[ $key ] ) ) {
					++$counts[ $key ];
					++$cursor['push']['files'][ $key ];
				}
				if ( 'have' === $new ) {
					$cursor['push']['files']['sent_bytes'] += (int) $t['size'];
				}
			}
			if ( $new !== $file['status'] && in_array( $new, array( 'committed', 'refused' ), true ) ) {
				// Settled on live while this site did not hear the answer (a lost commit answer).
				++$cursor['push']['files'][ $new ];
			}
			self::update_row( (int) $file['id'], $new, $t );
			++$updated;
		}

		if ( 0 === $updated && empty( $later ) ) {
			// An answer that settles nothing would send the same list for ever.
			return self::failure( $cursor, new WP_Error( 'response_invalid', Remote_Client::message( 'response_invalid' ) ) );
		}
		if ( array_sum( $counts ) > 0 ) {
			self::log( $cursor, 'files', $counts );
		}
		Plan_Store::update( (int) $row['id'], array( 'cursor_json' => $cursor ) );
		return null;
	}

	/**
	 * Sends a needed file chunk by chunk, then commits it.
	 *
	 * @param array $row      Push row.
	 * @param array $cursor   Cursor (changed).
	 * @param mixed $client   Client.
	 * @param array $file     File row.
	 * @param float $deadline Deadline.
	 * @param array $synced   Refs whose state was asked from live in this step (changed).
	 * @return array|null Null to go on, or the step's outcome.
	 */
	private static function send_file( array $row, array &$cursor, $client, array $file, $deadline, array &$synced ) {
		$plan_id = (int) $row['id'];
		$ref     = (int) $file['ref'];

		// Once per step, live says where this file stands: a step that died after live stored a
		// chunk resumes at the next chunk live is missing (blueprint §12.2, I-02).
		if ( ! isset( $synced[ $ref ] ) ) {
			$synced[ $ref ] = true;
			$out            = self::announce( $row, $cursor, $client, array( $file ), $deadline );
			if ( null !== $out ) {
				return $out;
			}
			$file = self::row_by_ref( $plan_id, $ref );
			if ( null === $file || 'need' !== $file['status'] ) {
				return null;
			}
		}

		$t    = $file['t'];
		$full = self::local_path( (string) $t['path'] );
		if ( '' === $full ) {
			return self::refuse_file( $cursor, $file, 'not_readable' );
		}
		$cursor['push']['files']['current'] = array(
			'ref'    => $ref,
			'name'   => wp_basename( (string) $t['path'] ),
			'next'   => (int) $t['next'],
			'chunks' => (int) $t['chunks'],
		);

		while ( (int) $t['next'] < (int) $t['chunks'] && microtime( true ) < $deadline - 1 ) {
			$n      = (int) $t['next'];
			$offset = $n * (int) $t['chunk'];
			$length = (int) min( (int) $t['chunk'], (int) $t['size'] - $offset );
			// A slice of the site's own upload; WP_Filesystem reads whole files only.
			$bytes = file_get_contents( $full, false, null, $offset, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside this site's uploads (checked above), read one chunk at a time.
			if ( false === $bytes || strlen( $bytes ) !== $length ) {
				return self::refuse_file( $cursor, $file, 'changed_on_source' );
			}

			$answer = $client->put_chunk(
				self::ROUTE . '/' . $row['push_uuid'] . '/files/' . $ref . '/chunks/' . $n,
				$bytes,
				array(
					'retries' => 3,
					'budget'  => (int) max( 1, floor( $deadline - microtime( true ) ) ),
					'timeout' => 30,
				)
			);
			unset( $bytes );

			if ( is_wp_error( $answer ) ) {
				$data   = (array) $answer->get_error_data();
				$status = isset( $data['remote_status'] ) ? (int) $data['remote_status'] : 0;
				$code   = $answer->get_error_code();

				if ( 413 === $status ) {
					$half = intdiv( (int) $t['chunk'], 2 );
					if ( $half < Media_Receiver::CHUNK_MIN ) {
						return self::failure( $cursor, $answer );
					}
					// Smaller chunks from here on; live starts this file again at that size.
					$cursor['push']['files']['chunk'] = $half;
					self::log(
						$cursor,
						'chunk_halved',
						array(
							'ref'   => $ref,
							'chunk' => $half,
						)
					);
					unset( $synced[ $ref ] );
					Plan_Store::update( $plan_id, array( 'cursor_json' => $cursor ) );
					return null;
				}

				if ( in_array( $code, array( 'body_hash_mismatch', 'chunk_hash_mismatch' ), true ) ) {
					// Damaged on the way: the same chunk again, a few times (I-03).
					$t['retries'] = isset( $t['retries'] ) ? (int) $t['retries'] + 1 : 1;
					self::update_row( (int) $file['id'], 'need', $t );
					if ( $t['retries'] <= self::CHUNK_RETRIES ) {
						self::log(
							$cursor,
							'chunk_retry',
							array(
								'ref' => $ref,
								'n'   => $n,
							)
						);
						continue;
					}
					return self::failure( $cursor, $answer );
				}

				return self::failure( $cursor, $answer );
			}

			$data = isset( $answer['data'] ) && is_array( $answer['data'] ) ? $answer['data'] : array();
			$next = isset( $data['next'] ) && is_int( $data['next'] ) ? max( 0, min( (int) $t['chunks'], $data['next'] ) ) : $n + 1;
			if ( empty( $data['duplicate'] ) ) {
				$cursor['push']['files']['sent_bytes'] += $length;
			}
			$t['next']    = $next;
			$t['retries'] = 0;
			self::update_row( (int) $file['id'], 'need', $t );
			$cursor['push']['files']['current']['next'] = $next;
		}

		if ( (int) $t['next'] < (int) $t['chunks'] ) {
			Plan_Store::update( $plan_id, array( 'cursor_json' => $cursor ) );
			return null;
		}

		$answer = $client->post(
			self::ROUTE . '/' . $row['push_uuid'] . '/files/' . $ref . '/commit',
			array(),
			array(
				'retries' => 3,
				'budget'  => (int) max( 1, floor( $deadline - microtime( true ) ) ),
				'timeout' => 30,
			)
		);
		if ( is_wp_error( $answer ) ) {
			$code = $answer->get_error_code();
			if ( in_array( $code, Media_Receiver::FINAL_REFUSALS, true ) ) {
				// Live checked the file and refused it: the push goes on, and live refuses the
				// attachment when its item arrives.
				return self::refuse_file( $cursor, $file, $code );
			}
			if ( 'chunks_missing' === $code ) {
				unset( $synced[ $ref ] );
				return null;
			}
			return self::failure( $cursor, $answer );
		}

		self::update_row( (int) $file['id'], 'committed', $t );
		++$cursor['push']['files']['committed'];
		$cursor['push']['files']['current'] = null;
		self::log(
			$cursor,
			'file_done',
			array(
				'ref'     => $ref,
				'name'    => wp_basename( (string) $t['path'] ),
				'renamed' => isset( $t['live'] ) && 'renamed' === $t['live'],
			)
		);
		Plan_Store::update( $plan_id, array( 'cursor_json' => $cursor ) );
		return null;
	}

	/**
	 * Gives up on one file (the push goes on; live refuses the attachment that needs it).
	 *
	 * @param array  $cursor Cursor (changed).
	 * @param array  $file   File row.
	 * @param string $reason Reason.
	 * @return null
	 */
	private static function refuse_file( array &$cursor, array $file, $reason ) {
		$t           = $file['t'];
		$t['reason'] = sanitize_key( (string) $reason );
		self::update_row( (int) $file['id'], 'refused', $t );
		++$cursor['push']['files']['refused'];
		$cursor['push']['files']['current'] = null;
		self::log(
			$cursor,
			'file_refused',
			array(
				'ref'  => (int) $file['ref'],
				'name' => wp_basename( (string) $t['path'] ),
				'code' => $t['reason'],
			)
		);
		return null;
	}

	/**
	 * The outcome of a call that stops the step: pause (Resume) or fail.
	 *
	 * @param array    $cursor Cursor (changed).
	 * @param WP_Error $error  Error.
	 * @return array{state:string,error:WP_Error}
	 */
	private static function failure( array &$cursor, WP_Error $error ) {
		$code  = $error->get_error_code();
		$pause = in_array( $code, Push_Runner::PAUSE_CODES, true ) || in_array( $code, self::PAUSE_CODES, true );
		self::log( $cursor, $pause ? 'paused' : 'failed', array( 'code' => $code ) );
		return array(
			'state' => $pause ? 'paused' : 'failed',
			'error' => $error,
		);
	}

	// --- rows ----------------------------------------------------------------------------------

	/**
	 * Records one file of a push (once per path: two attachments sharing a file send it once).
	 *
	 * @param int    $plan_id  Push ID.
	 * @param array  $file     path, size, sha256.
	 * @param string $uuid     Attachment UUID.
	 * @param int    $local_id Attachment ID.
	 * @return bool Whether a new row was stored.
	 */
	private static function insert( $plan_id, array $file, $uuid, $local_id ) {
		global $wpdb;

		$table = Tables::table_name( 'push_items' );
		$key   = 'path:' . sha1( strtolower( (string) $file['path'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; unique key lookup.
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE push_id = %d AND side = %s AND uuid = %s', $table, absint( $plan_id ), self::SIDE, $key ) ) ) {
			return false;
		}
		// The ref follows the stored rows (not a counter in the cursor), so a step that died after
		// storing rows never gives two files the same ref.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$ref = 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(seq) FROM %i WHERE push_id = %d AND side = %s', $table, absint( $plan_id ), self::SIDE ) );

		$transfer = array(
			'path'       => (string) $file['path'],
			'size'       => (int) $file['size'],
			'sha256'     => (string) $file['sha256'],
			'attachment' => (string) $uuid,
			'final'      => (string) $file['path'],
			'chunk'      => 0,
			'chunks'     => 0,
			'next'       => 0,
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; INSERT IGNORE keeps the first row of a path.
		$result = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (push_id, side, seq, uuid, object_type, action, status, transfer, local_object_id) VALUES (%d, %s, %d, %s, %s, %s, %s, %s, %d)', $table, absint( $plan_id ), self::SIDE, $ref, $key, self::KIND, 'file', 'listed', (string) wp_json_encode( $transfer ), absint( $local_id ) ) );
		return 1 === (int) $result;
	}

	/**
	 * File rows of a push with a status, by ref.
	 *
	 * @param int    $plan_id Push ID.
	 * @param string $status  Status.
	 * @param int    $limit   Most rows.
	 * @return array[]
	 */
	private static function rows_with_status( $plan_id, $status, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, seq, status, transfer FROM %i WHERE push_id = %d AND side = %s AND status = %s ORDER BY seq ASC LIMIT %d', Tables::table_name( 'push_items' ), absint( $plan_id ), self::SIDE, (string) $status, max( 1, absint( $limit ) ) ), ARRAY_A );
		return array_map( array( __CLASS__, 'decode' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * One file row by ref.
	 *
	 * @param int $plan_id Push ID.
	 * @param int $ref     Ref.
	 * @return array|null
	 */
	private static function row_by_ref( $plan_id, $ref ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, seq, status, transfer FROM %i WHERE push_id = %d AND side = %s AND seq = %d', Tables::table_name( 'push_items' ), absint( $plan_id ), self::SIDE, absint( $ref ) ), ARRAY_A );
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * One file row by path.
	 *
	 * @param int    $plan_id Push ID.
	 * @param string $path    Relative path.
	 * @return array|null
	 */
	private static function row_by_path( $plan_id, $path ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table; unique key lookup.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, seq, status, transfer FROM %i WHERE push_id = %d AND side = %s AND uuid = %s', Tables::table_name( 'push_items' ), absint( $plan_id ), self::SIDE, 'path:' . sha1( strtolower( (string) $path ) ) ), ARRAY_A );
		return is_array( $row ) ? self::decode( $row ) : null;
	}

	/**
	 * A row with its transfer decoded.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decode( array $row ) {
		$t = json_decode( (string) $row['transfer'], true );
		return array(
			'id'     => (int) $row['id'],
			'ref'    => (int) $row['seq'],
			'status' => (string) $row['status'],
			't'      => is_array( $t ) ? $t : array(),
		);
	}

	/**
	 * Updates a file row.
	 *
	 * @param int    $id     Row ID.
	 * @param string $status Status.
	 * @param array  $t      Transfer.
	 * @return void
	 */
	private static function update_row( $id, $status, array $t ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Own table.
		$wpdb->update(
			Tables::table_name( 'push_items' ),
			array(
				'status'   => (string) $status,
				'transfer' => (string) wp_json_encode( $t ),
			),
			array( 'id' => absint( $id ) )
		);
	}

	/**
	 * The absolute path of one of this site's uploads, or '' when it is not a regular file inside
	 * the uploads base (it is never opened then).
	 *
	 * @param string $relative Relative path.
	 * @return string
	 */
	private static function local_path( $relative ) {
		if ( '' !== Path_Guard::reason( $relative ) || ! Media::safe_path( $relative ) ) {
			return '';
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			return '';
		}
		$base = realpath( (string) $uploads['basedir'] );
		$full = realpath( untrailingslashit( (string) $uploads['basedir'] ) . '/' . $relative );
		if ( false === $base || false === $full || ! is_file( $full ) || 0 !== strpos( wp_normalize_path( $full ), trailingslashit( wp_normalize_path( $base ) ) ) ) {
			return '';
		}
		return $full;
	}

	/**
	 * Adds an entry to the push's step log (Push_Runner keeps it short).
	 *
	 * @param array  $cursor Cursor (changed).
	 * @param string $event  Event.
	 * @param array  $facts  Facts.
	 * @return void
	 */
	private static function log( array &$cursor, $event, array $facts ) {
		$cursor['push']['log'][] = array_merge(
			array(
				't'     => time(),
				'event' => $event,
			),
			$facts
		);
		if ( count( $cursor['push']['log'] ) > Push_Runner::LOG_MAX ) {
			$cursor['push']['log'] = array_slice( $cursor['push']['log'], -Push_Runner::LOG_MAX );
		}
	}
}
