import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

/**
 * Wrappers over the keepmerge/v1/admin routes.
 *
 * apiFetch carries wp-admin's wp_rest nonce in the X-WP-Nonce header (core sets up the
 * middleware on admin screens); every route checks manage_options and that nonce server-side.
 */

const BASE = '/keepmerge/v1/admin';

/**
 * A readable sentence for whatever the request was rejected with.
 *
 * @param {*} error Rejected value from apiFetch.
 * @return {string} Message to show.
 */
export function errorMessage( error ) {
	if ( error && 'string' === typeof error.message && error.message ) {
		return error.message;
	}

	return __(
		'The request did not complete. Check your connection and try again.',
		'keepmerge-push-staging-to-live'
	);
}

/**
 * Query string from an object, leaving out empty values.
 *
 * @param {Object} params Parameters.
 * @return {string} "?a=1&b=2" or "".
 */
function query( params ) {
	const search = new URLSearchParams();

	Object.keys( params ).forEach( ( key ) => {
		const value = params[ key ];
		if ( undefined !== value && null !== value && '' !== value && 0 !== value ) {
			search.append( key, String( value ) );
		}
	} );

	const text = search.toString();
	return text ? `?${ text }` : '';
}

export function getOverview() {
	return apiFetch( { path: `${ BASE }/overview` } );
}

export function getSettings() {
	return apiFetch( { path: `${ BASE }/settings` } );
}

export function saveSettings( data ) {
	return apiFetch( { path: `${ BASE }/settings`, method: 'POST', data } );
}

export function getAudit( filters, page ) {
	return apiFetch( { path: `${ BASE }/audit${ query( { ...filters, page } ) }` } );
}

export function getAuditExportChunk( filters, before ) {
	return apiFetch( {
		path: `${ BASE }/audit/export${ query( { ...filters, before } ) }`,
	} );
}
