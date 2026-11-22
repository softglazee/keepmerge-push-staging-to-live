import { __, sprintf } from '@wordpress/i18n';
import { number } from './plan-labels';

/**
 * A push state as words and tone (shown with an icon too, never by colour alone).
 *
 * @param {string}  state       State.
 * @param {boolean} interrupted Whether live sees no heartbeat for 5 minutes.
 * @return {Object} { label, tone }.
 */
export function pushStateInfo( state, interrupted = false ) {
	if ( interrupted ) {
		return {
			label: __( 'Interrupted', 'keepmerge-push-staging-to-live' ),
			tone: 'warning',
		};
	}
	const map = {
		sending_files: [
			__( 'Sending files', 'keepmerge-push-staging-to-live' ),
			'info',
		],
		sending_items: [
			__( 'Sending', 'keepmerge-push-staging-to-live' ),
			'info',
		],
		paused: [ __( 'Paused', 'keepmerge-push-staging-to-live' ), 'warning' ],
		staged: [
			__( 'Staged on live', 'keepmerge-push-staging-to-live' ),
			'success',
		],
		awaiting_review: [
			__(
				'Waiting for review on live',
				'keepmerge-push-staging-to-live'
			),
			'warning',
		],
		snapshotting: [
			__( 'Taking snapshots', 'keepmerge-push-staging-to-live' ),
			'info',
		],
		applying: [
			__( 'Applying on live', 'keepmerge-push-staging-to-live' ),
			'info',
		],
		applied: [
			__( 'Applied', 'keepmerge-push-staging-to-live' ),
			'success',
		],
		done: [
			__( 'Applied on live', 'keepmerge-push-staging-to-live' ),
			'success',
		],
		failed_partial: [
			__( 'Stopped at an item', 'keepmerge-push-staging-to-live' ),
			'danger',
		],
		failed: [ __( 'Stopped', 'keepmerge-push-staging-to-live' ), 'danger' ],
		aborted: [
			__( 'Aborted', 'keepmerge-push-staging-to-live' ),
			'neutral',
		],
		open: [ __( 'Opened', 'keepmerge-push-staging-to-live' ), 'info' ],
		receiving: [
			__( 'Receiving', 'keepmerge-push-staging-to-live' ),
			'info',
		],
		expired: [
			__( 'Expired', 'keepmerge-push-staging-to-live' ),
			'neutral',
		],
		interrupted: [
			__( 'Interrupted', 'keepmerge-push-staging-to-live' ),
			'warning',
		],
		rolling_back: [
			__( 'Rolling back', 'keepmerge-push-staging-to-live' ),
			'info',
		],
		rolled_back: [
			__( 'Rolled back', 'keepmerge-push-staging-to-live' ),
			'neutral',
		],
	};
	const entry = map[ state ] || [ state, 'neutral' ];
	return { label: entry[ 0 ], tone: entry[ 1 ] };
}

/**
 * Why live refused an item, in words.
 *
 * @param {string} code  Reason code.
 * @param {string} field Field live named, if any.
 * @return {string} Text.
 */
export function reasonLabel( code, field = '' ) {
	const labels = {
		changed_since_plan: __(
			'Changed on this site after the plan, so it was not sent. Plan again to push it.',
			'keepmerge-push-staging-to-live'
		),
		too_large: __(
			'Larger than the live site accepts in one request.',
			'keepmerge-push-staging-to-live'
		),
		not_exportable: __(
			'Could no longer be read on this site.',
			'keepmerge-push-staging-to-live'
		),
		type_denied: __(
			'This type is never pushed.',
			'keepmerge-push-staging-to-live'
		),
		type_not_included: __(
			'The live site does not include this type (its own Settings).',
			'keepmerge-push-staging-to-live'
		),
		taxonomy_not_included: __(
			'The live site does not include this taxonomy.',
			'keepmerge-push-staging-to-live'
		),
		option_denied: __(
			'The live site never accepts this option.',
			'keepmerge-push-staging-to-live'
		),
		option_not_allowed: __(
			"Not on the live site's own option allowlist. Add it in live's Settings to push it.",
			'keepmerge-push-staging-to-live'
		),
		widgets_not_included: __(
			'The live site does not include widgets.',
			'keepmerge-push-staging-to-live'
		),
		target_exists: __(
			'The live site already has this item.',
			'keepmerge-push-staging-to-live'
		),
		target_missing: __(
			'The live site no longer has this item.',
			'keepmerge-push-staging-to-live'
		),
		product_never_trashed: __(
			'Products are never moved to the Trash by a push.',
			'keepmerge-push-staging-to-live'
		),
		action_not_allowed: __(
			'This action is not available for this kind of item.',
			'keepmerge-push-staging-to-live'
		),
		invalid_value: __(
			'Carries a value the live site does not take as data.',
			'keepmerge-push-staging-to-live'
		),
		invalid_refs: __(
			'Its references to other items could not be read.',
			'keepmerge-push-staging-to-live'
		),
		unresolved_reference: __(
			'Points to an item the live site does not have and this push does not bring.',
			'keepmerge-push-staging-to-live'
		),
		path_refused: __(
			'A file path was refused by the live site.',
			'keepmerge-push-staging-to-live'
		),
		mime_refused: __(
			'The file type is not allowed on the live site.',
			'keepmerge-push-staging-to-live'
		),
		reserved_meta: __(
			'Carries file paths as custom fields, which the live site refuses.',
			'keepmerge-push-staging-to-live'
		),
		theme_missing: __(
			'Its theme is not installed on the live site.',
			'keepmerge-push-staging-to-live'
		),
		file_missing: __(
			'Its file is missing on this site, so it was not sent.',
			'keepmerge-push-staging-to-live'
		),
		media_missing: __(
			'The live site did not receive all of its files.',
			'keepmerge-push-staging-to-live'
		),
		file_refused: __(
			'The live site refused one of its files (its content does not match its type).',
			'keepmerge-push-staging-to-live'
		),
		file_shared: __(
			'One of its files already belongs to another item on the live site, which a push never shares.',
			'keepmerge-push-staging-to-live'
		),
		changed_during_push: __(
			'Changed on the live site after the plan was made, so live kept its own version.',
			'keepmerge-push-staging-to-live'
		),
		review_skipped: __(
			'Skipped by a live administrator in the review.',
			'keepmerge-push-staging-to-live'
		),
		dependency_skipped: __(
			'Uses an item that was not created on live, so it was not written.',
			'keepmerge-push-staging-to-live'
		),
		reference_missing: __(
			'Uses an item the live site no longer has.',
			'keepmerge-push-staging-to-live'
		),
		reference_mismatch: __(
			'Its references did not match its data, so live did not write it.',
			'keepmerge-push-staging-to-live'
		),
		path_taken: __(
			'Another file took its file name on live in the meantime.',
			'keepmerge-push-staging-to-live'
		),
		woocommerce_inactive: __(
			'WooCommerce is not active on the live site.',
			'keepmerge-push-staging-to-live'
		),
		attribute_missing: __(
			'Uses a product attribute the live site does not have.',
			'keepmerge-push-staging-to-live'
		),
		write_failed: __(
			'WordPress could not save it on live.',
			'keepmerge-push-staging-to-live'
		),
		item_exception: __(
			'Writing it stopped with an error on live.',
			'keepmerge-push-staging-to-live'
		),
		interrupted_item: __(
			'The apply stopped while this item was being written; live holds neither its old nor its new version.',
			'keepmerge-push-staging-to-live'
		),
		admin_skipped: __(
			'Skipped by a live administrator after it failed; it was put back as it was.',
			'keepmerge-push-staging-to-live'
		),
		edited_on_live: __(
			'Edited on this site after the push.',
			'keepmerge-push-staging-to-live'
		),
		ordered_since_push: __(
			'A product the push created that was ordered since.',
			'keepmerge-push-staging-to-live'
		),
		file_changed: __(
			'The file was replaced on this site after the push.',
			'keepmerge-push-staging-to-live'
		),
		snapshots_pruned: __(
			'Its snapshots were removed by the retention setting, so it can no longer be rolled back.',
			'keepmerge-push-staging-to-live'
		),
		already_rolled_back: __(
			'It was rolled back already.',
			'keepmerge-push-staging-to-live'
		),
		nothing_to_roll_back: __(
			'Nothing of it was written on this site.',
			'keepmerge-push-staging-to-live'
		),
		restore_filtered: __(
			'Your own HTML filter would change it when it is put back.',
			'keepmerge-push-staging-to-live'
		),
	};
	if ( labels[ code ] ) {
		return labels[ code ];
	}
	if (
		'invalid_field' === code ||
		'missing_field' === code ||
		'invalid_item' === code
	) {
		return field
			? sprintf(
					/* translators: %s: field name. */
					__(
						'The live site refused the field "%s".',
						'keepmerge-push-staging-to-live'
					),
					field
				)
			: __(
					"The live site refused this item's data.",
					'keepmerge-push-staging-to-live'
				);
	}
	return sprintf(
		/* translators: %s: reason code. */
		__(
			'Refused by the live site (%s).',
			'keepmerge-push-staging-to-live'
		),
		code
	);
}

/**
 * One entry of a push's step log, in words.
 *
 * @param {Object} entry Log entry.
 * @return {string} Text.
 */
export function logLine( entry ) {
	switch ( entry.event ) {
		case 'open':
			if ( entry.files ) {
				return sprintf(
					/* translators: 1: number of items, 2: number of files, 3: their size. */
					__(
						'Push opened on live for %1$s items and %2$s files (%3$s).',
						'keepmerge-push-staging-to-live'
					),
					number( entry.items ),
					number( entry.files ),
					size( entry.bytes )
				);
			}
			return sprintf(
				/* translators: %s: number of items. */
				__(
					'Push opened on live for %s items.',
					'keepmerge-push-staging-to-live'
				),
				number( entry.items )
			);
		case 'files':
			return sprintf(
				/* translators: 1: files live already has, 2: files to send, 3: files to send under a new name. */
				__(
					'Live answered for its files: %1$s already there, %2$s to send, %3$s to send under a new name.',
					'keepmerge-push-staging-to-live'
				),
				number( entry.have ),
				number( entry.need ),
				number( entry.renamed )
			);
		case 'file_done':
			return entry.renamed
				? sprintf(
						/* translators: %s: file name. */
						__(
							'%s sent and checked by live (it gets a new name there).',
							'keepmerge-push-staging-to-live'
						),
						entry.name
					)
				: sprintf(
						/* translators: %s: file name. */
						__(
							'%s sent and checked by live.',
							'keepmerge-push-staging-to-live'
						),
						entry.name
					);
		case 'file_refused':
			return sprintf(
				/* translators: 1: file name, 2: reason code. */
				__(
					'%1$s was refused (%2$s); the item that uses it will be refused on live.',
					'keepmerge-push-staging-to-live'
				),
				entry.name,
				entry.code
			);
		case 'chunk_halved':
			return sprintf(
				/* translators: %s: size of a file part. */
				__(
					'The live server refused the request size; files now go in parts of %s.',
					'keepmerge-push-staging-to-live'
				),
				size( entry.chunk )
			);
		case 'chunk_retry':
			return __(
				'A part of a file arrived damaged on live; it was sent again.',
				'keepmerge-push-staging-to-live'
			);
		case 'files_done':
			return sprintf(
				/* translators: 1: files sent, 2: files live already had, 3: files refused. */
				__(
					'Files done: %1$s sent, %2$s already on live, %3$s refused. Sending items.',
					'keepmerge-push-staging-to-live'
				),
				number( entry.committed ),
				number( entry.have ),
				number( entry.refused )
			);
		case 'batch': {
			const counts = [
				number( entry.seq ),
				number( entry.items ),
				number( ( entry.staged || 0 ) + ( entry.pending_media || 0 ) ),
				number( entry.held ),
				number( entry.refused ),
			];
			if ( entry.replay ) {
				return sprintf(
					/* translators: 1: batch number, 2: items, 3: staged, 4: held, 5: refused. */
					__(
						'Batch %1$s sent again (live already had it): %2$s items, %3$s staged, %4$s held for review, %5$s refused.',
						'keepmerge-push-staging-to-live'
					),
					...counts
				);
			}
			return sprintf(
				/* translators: 1: batch number, 2: items, 3: staged, 4: held, 5: refused. */
				__(
					'Batch %1$s: %2$s items, %3$s staged, %4$s held for review, %5$s refused.',
					'keepmerge-push-staging-to-live'
				),
				...counts
			);
		}
		case 'halved':
			return sprintf(
				/* translators: %s: items per batch. */
				__(
					'The live server refused the request size; batches now carry %s items.',
					'keepmerge-push-staging-to-live'
				),
				number( entry.batch )
			);
		case 'too_large':
			return __(
				'One item is larger than the live server accepts; it is skipped.',
				'keepmerge-push-staging-to-live'
			);
		case 'wait':
			return sprintf(
				/* translators: %s: seconds. */
				__(
					'The live site asked to wait %s seconds before the next batch.',
					'keepmerge-push-staging-to-live'
				),
				number( entry.seconds )
			);
		case 'reconciled':
			return sprintf(
				/* translators: %s: batch number. */
				__(
					'Batch %s was already on live.',
					'keepmerge-push-staging-to-live'
				),
				number( entry.seq )
			);
		case 'paused':
			return sprintf(
				/* translators: %s: error code. */
				__( 'Paused (%s).', 'keepmerge-push-staging-to-live' ),
				entry.code
			);
		case 'failed':
			return sprintf(
				/* translators: %s: error code. */
				__( 'Stopped (%s).', 'keepmerge-push-staging-to-live' ),
				entry.code
			);
		case 'resume':
			return __( 'Resumed.', 'keepmerge-push-staging-to-live' );
		case 'staged':
			return __(
				'Every item is staged on live.',
				'keepmerge-push-staging-to-live'
			);
		case 'abort':
			return __(
				'Aborted: live removed what it had staged for this push.',
				'keepmerge-push-staging-to-live'
			);
		case 'awaiting_review':
			return sprintf(
				/* translators: %s: number of items. */
				__(
					'%s items are waiting for review on live. Nothing was written there; a live administrator finishes the push.',
					'keepmerge-push-staging-to-live'
				),
				number( entry.held )
			);
		case 'reviewed':
			return __(
				'A live administrator finished the push on live.',
				'keepmerge-push-staging-to-live'
			);
		case 'done':
			return sprintf(
				/* translators: 1: items applied, 2: items skipped. */
				__(
					'Applied on live: %1$s items written, %2$s skipped.',
					'keepmerge-push-staging-to-live'
				),
				number( entry.applied || 0 ),
				number( entry.skipped || 0 )
			);
		default:
			return entry.event;
	}
}

/**
 * A size in bytes in words (KB, MB, GB; 1 KB = 1,024 bytes).
 *
 * @param {number} bytes Bytes.
 * @return {string} Size.
 */
export function size( bytes ) {
	let amount = Number( bytes ) || 0;
	let unit = 0;
	while ( amount >= 1024 && unit < 3 ) {
		amount /= 1024;
		unit++;
	}
	const text = new Intl.NumberFormat( undefined, {
		maximumFractionDigits: unit > 1 ? 1 : 0,
	} ).format( amount );
	switch ( unit ) {
		case 0:
			return sprintf(
				/* translators: %s: a size in bytes. */
				__( '%s bytes', 'keepmerge-push-staging-to-live' ),
				text
			);
		case 1:
			return sprintf(
				/* translators: %s: a size in kilobytes. */
				__( '%s KB', 'keepmerge-push-staging-to-live' ),
				text
			);
		case 2:
			return sprintf(
				/* translators: %s: a size in megabytes. */
				__( '%s MB', 'keepmerge-push-staging-to-live' ),
				text
			);
		default:
			return sprintf(
				/* translators: %s: a size in gigabytes. */
				__( '%s GB', 'keepmerge-push-staging-to-live' ),
				text
			);
	}
}

/**
 * A Unix time as a local clock time.
 *
 * @param {number} timestamp Unix seconds.
 * @return {string} Time.
 */
export function clock( timestamp ) {
	if ( ! timestamp ) {
		return '';
	}
	return new Date( timestamp * 1000 ).toLocaleTimeString( undefined, {
		hour: '2-digit',
		minute: '2-digit',
		second: '2-digit',
	} );
}
