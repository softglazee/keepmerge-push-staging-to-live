import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Modal from './Modal';

/**
 * ConfirmDialog: asks before a consequential action. The cancel button has focus first, so
 * pressing Enter by habit does not confirm.
 *
 * Later phases extend this with the exact counts and the typed live domain that pushes and
 * rollbacks require.
 *
 * @param {Object}   props
 * @param {string}   props.title        Dialog title.
 * @param {*}        props.children     What will happen, in plain words.
 * @param {string}   props.confirmLabel Label of the confirming button.
 * @param {boolean}  [props.destructive] Style the confirming button as dangerous.
 * @param {Function} props.onConfirm    Confirms.
 * @param {Function} props.onCancel     Cancels.
 */
export default function ConfirmDialog( {
	title,
	children,
	confirmLabel,
	destructive = false,
	onConfirm,
	onCancel,
} ) {
	return (
		<Modal
			title={ title }
			onClose={ onCancel }
			initialFocus=".sgsp-confirm-cancel"
			footer={
				<>
					<button
						type="button"
						className="sgsp-button sgsp-button--secondary sgsp-confirm-cancel"
						onClick={ onCancel }
					>
						{ __( 'Cancel', 'keepmerge-push-staging-to-live' ) }
					</button>
					<button
						type="button"
						className={ `sgsp-button ${ destructive ? 'sgsp-button--danger' : 'sgsp-button--primary' }` }
						onClick={ onConfirm }
					>
						{ confirmLabel }
					</button>
				</>
			}
		>
			{ children }
		</Modal>
	);
}
