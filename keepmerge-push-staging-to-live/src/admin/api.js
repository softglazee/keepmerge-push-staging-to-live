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

/**
 * A pairing's baseline record and one page of its sample table.
 *
 * @param {string} pairing Pairing UUID.
 * @param {string} filter  strong, medium, none, duplicate or excluded.
 * @param {number} page    1-based page.
 * @return {Promise} { baseline, table }.
 */
export function getBaseline( pairing, filter, page ) {
	return apiFetch( {
		path: `${ BASE }/baseline${ query( { pairing, filter, page } ) }`,
	} );
}

/**
 * One time-boxed step of a pairing's baseline (reads live, writes this site's sync state).
 *
 * @param {Object} data { pairing, restart, clone_date, clone_unknown, cursor }.
 * @return {Promise} The baseline record.
 */
export function runBaseline( data ) {
	return apiFetch( { path: `${ BASE }/baseline`, method: 'POST', data } );
}

/**
 * Starts a dry-run plan (reads live, writes only this site's plan rows).
 *
 * @param {Object} data { pairing, types }.
 * @return {Promise} The plan.
 */
export function createPlan( data ) {
	return apiFetch( { path: `${ BASE }/plans`, method: 'POST', data } );
}

/**
 * One time-boxed step of a plan.
 *
 * @param {number} id    Plan ID.
 * @param {number} steps Step count the screen last saw.
 * @return {Promise} The plan.
 */
export function stepPlan( id, steps ) {
	return apiFetch( {
		path: `${ BASE }/plans/${ id }/step`,
		method: 'POST',
		data: { steps },
	} );
}

/**
 * The plan and one page of rows.
 *
 * @param {number} id     Plan ID.
 * @param {Object} params tab, class, kind, warnings, all, search, orderby, order, page, per_page.
 * @return {Promise} { plan, rows }.
 */
export function getPlan( id, params ) {
	const clean = { ...params };
	[ 'warnings', 'all' ].forEach( ( key ) => {
		if ( clean[ key ] ) {
			clean[ key ] = 'true';
		} else {
			delete clean[ key ];
		}
	} );
	return apiFetch( { path: `${ BASE }/plans/${ id }${ query( clean ) }` } );
}

/**
 * The text diff of one plan item (reads live's copy).
 *
 * @param {number} id   Plan ID.
 * @param {string} item Item key (UUID, option:<name> or unit:widgets).
 * @return {Promise} Diff.
 */
export function getPlanDiff( id, item ) {
	return apiFetch( {
		path: `${ BASE }/plans/${ id }/items/${ item }/diff`,
	} );
}

/**
 * Saves decisions, or the bulk "Keep live for all remaining".
 *
 * @param {number} id   Plan ID.
 * @param {Object} data { decisions: [ { uuid, action, group } ], bulk }.
 * @return {Promise} The plan.
 */
export function decidePlan( id, data ) {
	return apiFetch( {
		path: `${ BASE }/plans/${ id }/decisions`,
		method: 'POST',
		data,
	} );
}

/**
 * The code report of a pairing; with run, one time-boxed step of it.
 *
 * @param {string}  pairing Pairing UUID.
 * @param {boolean} run     Run one step.
 * @param {boolean} restart Start again from live's inventory.
 * @return {Promise} Report.
 */
export function getCodeReport( pairing, run, restart ) {
	return apiFetch( {
		path: `${ BASE }/code-report${ query( {
			pairing,
			run: run ? 'true' : '',
			restart: restart ? 'true' : '',
		} ) }`,
	} );
}
