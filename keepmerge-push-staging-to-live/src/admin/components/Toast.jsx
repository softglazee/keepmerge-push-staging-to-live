import { createElement, useEffect } from '@wordpress/element';
import { CircleAlert, Check } from 'lucide-react';

/**
 * Toast: a short confirmation of what just happened, in a live region so screen readers hear
 * it too. Errors stay until dismissed; confirmations go after four seconds.
 *
 * @param {Object}   props
 * @param {Object}   [props.notice]   { type: 'success'|'error', message }.
 * @param {Function} props.onDismiss  Clears the notice.
 */
export default function Toast( { notice, onDismiss } ) {
	const isError = notice && 'error' === notice.type;

	useEffect( () => {
		if ( ! notice || isError ) {
			return undefined;
		}
		const timer = window.setTimeout( onDismiss, 4000 );
		return () => window.clearTimeout( timer );
	}, [ notice, isError, onDismiss ] );

	return (
		<div className="sgsp-toast-region" aria-live="polite" aria-atomic="true">
			{ notice && (
				<button
					type="button"
					className={ `sgsp-toast${ isError ? ' is-error' : '' }` }
					onClick={ onDismiss }
				>
					{ isError ? (
						<CircleAlert size={ 16 } aria-hidden="true" />
					) : (
						<Check size={ 16 } aria-hidden="true" />
					) }
					{ notice.message }
				</button>
			) }
		</div>
	);
}
