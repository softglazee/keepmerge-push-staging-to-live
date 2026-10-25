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
		sending_items: [
			__( 'Sending', 'keepmerge-push-staging-to-live' ),
			'info',
		],
		paused: [ __( 'Paused', 'keepmerge-push-staging-to-live' ), 'warning' ],
		staged: [
			__( 'Staged on live', 'keepmerge-push-staging-to-live' ),
			'success',
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
			return sprintf(
				/* translators: %s: number of items. */
				__(
					'Push opened on live for %s items.',
					'keepmerge-push-staging-to-live'
				),
				number( entry.items )
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
		default:
			return entry.event;
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
