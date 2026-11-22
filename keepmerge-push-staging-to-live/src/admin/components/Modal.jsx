import { createElement, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { X } from 'lucide-react';

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Modal: a dialog with a focus trap, Escape to close, and focus returned to whatever opened
 * it. Clicking the backdrop does nothing, so a stray click cannot confirm or discard anything.
 *
 * @param {Object}   props
 * @param {string}   props.title    Dialog title.
 * @param {Function} props.onClose  Closes the dialog.
 * @param {*}        props.children Body.
 * @param {*}        [props.footer] Buttons.
 * @param {string}   [props.initialFocus] Selector of the element to focus first.
 * @param {string}   [props.size]     'wide' for a larger dialog (the diff drawer).
 * @param {*}        [props.subtitle] Line under the title.
 */
export default function Modal( { title, onClose, children, footer, initialFocus, size, subtitle } ) {
	const dialogRef = useRef( null );
	const titleId = useRef( `sgsp-modal-${ Math.random().toString( 36 ).slice( 2 ) }` );
	// The latest onClose, read by the key handler. Kept in a ref so a parent re-render (a new
	// inline callback, a countdown tick) does not re-run the effect and move focus while the
	// dialog is open.
	const closeRef = useRef( onClose );
	closeRef.current = onClose;

	useEffect( () => {
		const dialog = dialogRef.current;
		if ( ! dialog ) {
			return undefined;
		}
		const doc = dialog.ownerDocument;
		const returnTo = doc.activeElement;
		const first =
			( initialFocus && dialog.querySelector( initialFocus ) ) ||
			dialog.querySelector( FOCUSABLE );

		if ( first ) {
			first.focus();
		}

		function onKeyDown( event ) {
			if ( 'Escape' === event.key ) {
				event.stopPropagation();
				closeRef.current();
				return;
			}
			if ( 'Tab' !== event.key ) {
				return;
			}
			const nodes = Array.from( dialog.querySelectorAll( FOCUSABLE ) ).filter(
				( node ) => null !== node.offsetParent
			);
			if ( ! nodes.length ) {
				event.preventDefault();
				return;
			}
			const firstNode = nodes[ 0 ];
			const lastNode = nodes[ nodes.length - 1 ];
			const active = doc.activeElement;
			if ( ! dialog.contains( active ) ) {
				// Focus somehow left the dialog (a click on the backdrop): bring it back.
				event.preventDefault();
				( event.shiftKey ? lastNode : firstNode ).focus();
			} else if ( event.shiftKey && active === firstNode ) {
				event.preventDefault();
				lastNode.focus();
			} else if ( ! event.shiftKey && active === lastNode ) {
				event.preventDefault();
				firstNode.focus();
			}
		}

		doc.addEventListener( 'keydown', onKeyDown );

		return () => {
			doc.removeEventListener( 'keydown', onKeyDown );
			if ( returnTo && 'function' === typeof returnTo.focus && doc.contains( returnTo ) ) {
				returnTo.focus();
			}
		};
	}, [ initialFocus ] );

	return (
		<div className="sgsp-modal-overlay">
			<div
				className={ 'wide' === size ? 'sgsp-modal sgsp-modal--wide' : 'sgsp-modal' }
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId.current }
				ref={ dialogRef }
			>
				<div className="sgsp-modal__head">
					<div className="sgsp-min-w-0">
						<h2 className="sgsp-modal__title" id={ titleId.current }>
							{ title }
						</h2>
						{ subtitle && <div className="sgsp-modal__subtitle">{ subtitle }</div> }
					</div>
					<button
						type="button"
						className="sgsp-icon-button"
						onClick={ onClose }
						aria-label={ __( 'Close', 'keepmerge-push-staging-to-live' ) }
					>
						<X size={ 18 } aria-hidden="true" />
					</button>
				</div>
				<div className="sgsp-modal__body">{ children }</div>
				{ footer && <div className="sgsp-modal__foot">{ footer }</div> }
			</div>
		</div>
	);
}
