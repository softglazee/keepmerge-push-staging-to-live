/**
 * Text helpers of the diff drawer. Everything here returns plain strings; the drawer renders
 * them as React text, never as HTML.
 */

/**
 * Replaces Keepmerge's URL tokens with one site's address, in the four forms the tokens stand
 * for (plain, JSON-escaped, URL-encoded, protocol-relative).
 *
 * @param {string} text   Text with tokens.
 * @param {string} origin The site's address without a trailing slash.
 * @return {string} Text for display.
 */
export function detokenize( text, origin ) {
	if ( 'string' !== typeof text || -1 === text.indexOf( '{{sgsp:' ) ) {
		return text;
	}
	const url = origin || '';
	const rest = url.replace( /^[a-z][a-z0-9+.-]*:\/\//i, '' );
	const forms = {
		'': url,
		':j': url.replace( /\//g, '\\/' ),
		':u': encodeURIComponent( url ),
		':p': `//${ rest }`,
	};
	return text.replace(
		/\{\{sgsp:(?:home|site)(:[jup])?\}\}/g,
		( match, flag ) => forms[ flag || '' ]
	);
}

/**
 * Whether a value reads better as lines than side by side.
 *
 * @param {string|null} a One side.
 * @param {string|null} b Other side.
 * @return {boolean} Multiline.
 */
export function isMultiline( a, b ) {
	return [ a, b ].some(
		( value ) =>
			'string' === typeof value &&
			( -1 !== value.indexOf( '\n' ) || value.length > 160 )
	);
}

const MAX_CELLS = 2000000;

/**
 * A line diff of live's text (before) and this site's text (after), compared with their URL
 * tokens in place so an address that only differs between the sites is not a change.
 *
 * @param {string} before Live's text.
 * @param {string} after  This site's text.
 * @return {Array|null} [ { type: 'same'|'del'|'add', text } ], or null when too large to compare.
 */
export function lineDiff( before, after ) {
	const x = ( before || '' ).split( '\n' );
	const y = ( after || '' ).split( '\n' );

	let start = 0;
	while (
		start < x.length &&
		start < y.length &&
		x[ start ] === y[ start ]
	) {
		start++;
	}
	let endX = x.length - 1;
	let endY = y.length - 1;
	while ( endX >= start && endY >= start && x[ endX ] === y[ endY ] ) {
		endX--;
		endY--;
	}

	const xs = x.slice( start, endX + 1 );
	const ys = y.slice( start, endY + 1 );
	if ( xs.length * ys.length > MAX_CELLS ) {
		return null;
	}

	const n = xs.length;
	const m = ys.length;
	const table = [];
	for ( let i = 0; i <= n; i++ ) {
		table.push( new Uint32Array( m + 1 ) );
	}
	for ( let i = n - 1; i >= 0; i-- ) {
		for ( let j = m - 1; j >= 0; j-- ) {
			table[ i ][ j ] =
				xs[ i ] === ys[ j ]
					? table[ i + 1 ][ j + 1 ] + 1
					: Math.max( table[ i + 1 ][ j ], table[ i ][ j + 1 ] );
		}
	}

	const out = [];
	for ( let i = 0; i < start; i++ ) {
		out.push( { type: 'same', text: x[ i ] } );
	}
	let i = 0;
	let j = 0;
	while ( i < n && j < m ) {
		if ( xs[ i ] === ys[ j ] ) {
			out.push( { type: 'same', text: xs[ i ] } );
			i++;
			j++;
		} else if ( table[ i + 1 ][ j ] >= table[ i ][ j + 1 ] ) {
			out.push( { type: 'del', text: xs[ i ] } );
			i++;
		} else {
			out.push( { type: 'add', text: ys[ j ] } );
			j++;
		}
	}
	while ( i < n ) {
		out.push( { type: 'del', text: xs[ i++ ] } );
	}
	while ( j < m ) {
		out.push( { type: 'add', text: ys[ j++ ] } );
	}
	for ( let k = endX + 1; k < x.length; k++ ) {
		out.push( { type: 'same', text: x[ k ] } );
	}
	return out;
}

/**
 * Collapses long runs of unchanged lines to a few lines of context.
 *
 * @param {Array}  lines   Diff lines.
 * @param {number} context Lines kept around each change.
 * @return {Array} Lines and { type: 'skip', count } markers.
 */
export function collapse( lines, context = 3 ) {
	const keep = lines.map( () => false );
	lines.forEach( ( line, index ) => {
		if ( 'same' !== line.type ) {
			for (
				let k = Math.max( 0, index - context );
				k <= Math.min( lines.length - 1, index + context );
				k++
			) {
				keep[ k ] = true;
			}
		}
	} );
	const out = [];
	let skipped = 0;
	lines.forEach( ( line, index ) => {
		if ( keep[ index ] ) {
			if ( skipped ) {
				out.push( { type: 'skip', count: skipped } );
				skipped = 0;
			}
			out.push( line );
		} else {
			skipped++;
		}
	} );
	if ( skipped ) {
		out.push( { type: 'skip', count: skipped } );
	}
	return out;
}
