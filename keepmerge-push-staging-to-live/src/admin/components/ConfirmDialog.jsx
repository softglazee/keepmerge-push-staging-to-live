import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Modal from './Modal';

/**
 * ConfirmDialog: asks before a consequential action. The cancel button has focus first, so
 * pressing Enter by habit does not confirm.
 *
 * The confirming button can wait for a condition (confirmDisabled), for example a domain typed
 * into the dialog (push, continue and rollback need the live domain typed).
 *
 * @param {Object}   props
 * @param {string}   props.title        Dialog title.
 * @param {*}        props.children     What will happen, in plain words.
 * @param {string}   props.confirmLabel Label of the confirming button.
 * @param {boolean}  [props.destructive] Style the confirming button as dangerous.
 * @param {boolean}  [props.confirmDisabled] Keep the confirming button disabled.
 * @param {Function} props.onConfirm    Confirms.
 * @param {Function} props.onCancel     Cancels.
 */
export default function ConfirmDialog( {
	title,
	children,
	confirmLabel,
	destructive = false,
	confirmDisabled = false,
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
						disabled={ confirmDisabled }
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
