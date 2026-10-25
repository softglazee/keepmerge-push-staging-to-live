import { __, sprintf } from '@wordpress/i18n';

/**
 * "WordPress 7.1.3 · PHP 8.3 · Keepmerge 1.0.0" from live's versions.
 *
 * @param {Object} versions { wordpress, php, plugin }.
 * @return {string} Label.
 */
export function versionsLabel( versions ) {
	if ( ! versions || ! versions.wordpress ) {
		return '–';
	}
	return sprintf(
		/* translators: 1: WordPress version, 2: PHP version, 3: Keepmerge version. */
		__(
			'WordPress %1$s · PHP %2$s · Keepmerge %3$s',
			'keepmerge-push-staging-to-live'
		),
		versions.wordpress,
		versions.php || '–',
		versions.plugin || '–'
	);
}

/**
 * The clock difference between the two servers.
 *
 * @param {number|null} skew Seconds (live minus this site).
 * @return {string} Label.
 */
export function skewLabel( skew ) {
	if ( null === skew || undefined === skew ) {
		return '–';
	}
	const seconds = Math.abs( skew );
	if ( seconds > 300 ) {
		return sprintf(
			/* translators: %d: seconds. */
			__(
				'%d seconds: too far apart (at most 300)',
				'keepmerge-push-staging-to-live'
			),
			seconds
		);
	}
	return sprintf(
		/* translators: %d: seconds. */
		__( '%d s (within 300 s)', 'keepmerge-push-staging-to-live' ),
		seconds
	);
}

/**
 * Whether the Authorization header reached live.
 *
 * @param {boolean|null} seen Value from the last status call.
 * @return {string} Label.
 */
export function headerLabel( seen ) {
	if ( true === seen ) {
		return __( 'Arrives on live', 'keepmerge-push-staging-to-live' );
	}
	if ( false === seen ) {
		return __(
			'Removed before it reaches WordPress on live',
			'keepmerge-push-staging-to-live'
		);
	}
	return __( 'Not checked yet', 'keepmerge-push-staging-to-live' );
}

/**
 * Words and badge tone for a source pairing's display status.
 *
 * @param {string} status display_status from the server.
 * @return {{label: string, tone: string}} Label and tone.
 */
export function sendStatus( status ) {
	switch ( status ) {
		case 'active':
			return {
				label: __( 'Connected', 'keepmerge-push-staging-to-live' ),
				tone: 'success',
			};
		case 'awaiting_approval':
			return {
				label: __(
					'Waiting for approval on live',
					'keepmerge-push-staging-to-live'
				),
				tone: 'warning',
			};
		case 'connecting':
			return {
				label: __(
					'Finishing connection',
					'keepmerge-push-staging-to-live'
				),
				tone: 'info',
			};
		case 'revoked_on_live':
		case 'repair_needed':
			return {
				label: __( 'Re-pair needed', 'keepmerge-push-staging-to-live' ),
				tone: 'danger',
			};
		default:
			return { label: status, tone: 'neutral' };
	}
}
