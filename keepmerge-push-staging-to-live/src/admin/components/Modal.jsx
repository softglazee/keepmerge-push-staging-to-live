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
 */
export default function Modal( { title, onClose, children, footer, initialFocus } ) {
	const dialogRef = useRef( null );
	const titleId = useRef( `sgsp-modal-${ Math.random().toString( 36 ).slice( 2 ) }` );

	useEffect( () => {
		const returnTo = document.activeElement;
		const dialog = dialogRef.current;
		const first =
			( initialFocus && dialog?.querySelector( initialFocus ) ) ||
			dialog?.querySelector( FOCUSABLE );

		if ( first ) {
			first.focus();
		}

		function onKeyDown( event ) {
			if ( 'Escape' === event.key ) {
				event.stopPropagation();
				onClose();
				return;
			}
			if ( 'Tab' !== event.key || ! dialog ) {
				return;
			}
			const nodes = Array.from( dialog.querySelectorAll( FOCUSABLE ) ).filter(
				( node ) => null !== node.offsetParent
			);
			if ( ! nodes.length ) {
				return;
			}
			const firstNode = nodes[ 0 ];
			const lastNode = nodes[ nodes.length - 1 ];
			if ( event.shiftKey && document.activeElement === firstNode ) {
				event.preventDefault();
				lastNode.focus();
			} else if ( ! event.shiftKey && document.activeElement === lastNode ) {
				event.preventDefault();
				firstNode.focus();
			}
		}

		document.addEventListener( 'keydown', onKeyDown );

		return () => {
			document.removeEventListener( 'keydown', onKeyDown );
			if ( returnTo instanceof window.HTMLElement ) {
				returnTo.focus();
			}
		};
	}, [ onClose, initialFocus ] );

	return (
		<div className="sgsp-modal-overlay">
			<div
				className="sgsp-modal"
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId.current }
				ref={ dialogRef }
			>
				<div className="sgsp-modal__head">
					<h2 className="sgsp-modal__title" id={ titleId.current }>
						{ title }
					</h2>
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
