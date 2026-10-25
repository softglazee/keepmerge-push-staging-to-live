import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Re-renders every second while `active` is true, for countdowns.
 *
 * @param {boolean} active Whether to tick.
 */
export function useTick( active ) {
	const [ , setTick ] = useState( 0 );

	useEffect( () => {
		if ( ! active ) {
			return undefined;
		}
		const id = window.setInterval( () => setTick( ( tick ) => tick + 1 ), 1000 );
		return () => window.clearInterval( id );
	}, [ active ] );
}

/**
 * Time left as h:mm:ss or mm:ss.
 *
 * @param {number} seconds Seconds left.
 * @return {string} Countdown text.
 */
export function countdown( seconds ) {
	const total = Math.max( 0, Math.floor( seconds ) );
	const hours = Math.floor( total / 3600 );
	const minutes = Math.floor( ( total % 3600 ) / 60 );
	const secs = total % 60;
	const pad = ( value ) => String( value ).padStart( 2, '0' );

	return hours > 0
		? `${ hours }:${ pad( minutes ) }:${ pad( secs ) }`
		: `${ pad( minutes ) }:${ pad( secs ) }`;
}

/**
 * A Unix time as a local date and time.
 *
 * @param {number|null} timestamp Unix seconds.
 * @return {string} Date and time, or a dash.
 */
export function dateTime( timestamp ) {
	if ( ! timestamp ) {
		return '–';
	}
	return new Date( timestamp * 1000 ).toLocaleString( undefined, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} );
}

/**
 * How long ago, in words, for "last used".
 *
 * @param {number|null} timestamp Unix seconds.
 * @param {number}      now       Server-aligned now.
 * @return {string} Text.
 */
export function ago( timestamp, now ) {
	if ( ! timestamp ) {
		return __( 'Never', 'keepmerge-push-staging-to-live' );
	}
	const minutes = Math.max( 0, Math.round( ( now - timestamp ) / 60 ) );
	if ( minutes < 1 ) {
		return __( 'Just now', 'keepmerge-push-staging-to-live' );
	}
	if ( minutes < 60 ) {
		/* translators: %d: minutes. */
		return sprintf( __( '%d min ago', 'keepmerge-push-staging-to-live' ), minutes );
	}
	return dateTime( timestamp );
}
