import { __ } from '@wordpress/i18n';

/**
 * Words for a pairing status.
 *
 * @param {string} status Status.
 * @return {string} Label.
 */
export function pairingStatusLabel( status ) {
	switch ( status ) {
		case 'active':
			return __( 'Active', 'keepmerge-push-staging-to-live' );
		case 'pending':
			return __( 'Pending', 'keepmerge-push-staging-to-live' );
		case 'awaiting_approval':
			return __( 'Awaiting approval', 'keepmerge-push-staging-to-live' );
		case 'repair_needed':
			return __( 'Re-pair needed', 'keepmerge-push-staging-to-live' );
		default:
			return status;
	}
}

/**
 * Words for an audit result.
 *
 * @param {string} result ok, denied or error.
 * @return {string} Label.
 */
export function resultLabel( result ) {
	switch ( result ) {
		case 'ok':
			return __( 'Succeeded', 'keepmerge-push-staging-to-live' );
		case 'denied':
			return __( 'Denied', 'keepmerge-push-staging-to-live' );
		case 'error':
			return __( 'Failed', 'keepmerge-push-staging-to-live' );
		default:
			return result;
	}
}

/**
 * Words for who acted.
 *
 * @param {Object} row Audit row.
 * @return {string} Label.
 */
export function actorLabel( row ) {
	switch ( row.actor_type ) {
		case 'user':
			return (
				row.actor ||
				__( 'Deleted user', 'keepmerge-push-staging-to-live' )
			);
		case 'cli':
			return row.actor
				? `${ row.actor } (WP-CLI)`
				: __( 'WP-CLI', 'keepmerge-push-staging-to-live' );
		case 'remote':
			return __( 'Paired site', 'keepmerge-push-staging-to-live' );
		default:
			return __( 'System', 'keepmerge-push-staging-to-live' );
	}
}
