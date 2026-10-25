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
		if (
			undefined !== value &&
			null !== value &&
			'' !== value &&
			0 !== value
		) {
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
	return apiFetch( {
		path: `${ BASE }/audit${ query( { ...filters, page } ) }`,
	} );
}

export function getAuditExportChunk( filters, before ) {
	return apiFetch( {
		path: `${ BASE }/audit/export${ query( { ...filters, before } ) }`,
	} );
}

export function getReceive() {
	return apiFetch( { path: `${ BASE }/receive` } );
}

/**
 * Opens (with hours 1–24) or closes the receive window.
 *
 * @param {Object} data { action: 'open'|'close', hours }.
 * @return {Promise} Receive tab data.
 */
export function setReceive( data ) {
	return apiFetch( { path: `${ BASE }/receive`, method: 'POST', data } );
}

/**
 * Creates a pairing key. The response is the only place the key ever appears.
 *
 * @return {Promise} { key, pairing }.
 */
export function createPairingKey() {
	return apiFetch( { path: `${ BASE }/pairings`, method: 'POST' } );
}

export function updatePairing( uuid, data ) {
	return apiFetch( {
		path: `${ BASE }/pairings/${ uuid }`,
		method: 'PATCH',
		data,
	} );
}

export function revokePairing( uuid ) {
	return apiFetch( {
		path: `${ BASE }/pairings/${ uuid }`,
		method: 'DELETE',
	} );
}

export function approvePairing( uuid ) {
	return apiFetch( {
		path: `${ BASE }/pairings/${ uuid }/approve`,
		method: 'POST',
	} );
}

export function rejectPairing( uuid ) {
	return apiFetch( {
		path: `${ BASE }/pairings/${ uuid }/reject`,
		method: 'POST',
	} );
}

/**
 * Send tab data: this site's pairings to live sites. Makes no request to live.
 *
 * @return {Promise} { now, site, can_connect, blocker, pairings }.
 */
export function getSend() {
	return apiFetch( { path: `${ BASE }/send` } );
}

/**
 * Reads what a pasted pairing key points to (live's status with the key's bootstrap credentials).
 *
 * @param {string} key Pairing key.
 * @return {Promise} Preview.
 */
export function previewConnect( key ) {
	return apiFetch( {
		path: `${ BASE }/connect`,
		method: 'POST',
		data: { key },
	} );
}

/**
 * Confirms and finalizes with a pasted pairing key.
 *
 * @param {string} key Pairing key.
 * @return {Promise} The new pairing (waiting for approval on live).
 */
export function confirmConnect( key ) {
	return apiFetch( {
		path: `${ BASE }/connect/confirm`,
		method: 'POST',
		data: { key },
	} );
}

/**
 * One signed status call for a pairing this site sends through.
 *
 * @param {string} uuid Pairing UUID.
 * @return {Promise} The pairing with last_check.
 */
export function checkPairing( uuid ) {
	return apiFetch( {
		path: `${ BASE }/pairings/${ uuid }/status`,
		method: 'POST',
	} );
}
