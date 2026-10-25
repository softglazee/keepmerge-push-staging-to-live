import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { CircleCheck, CircleDashed, LoaderCircle, Save } from 'lucide-react';

/**
 * The sticky save row at the end of the Settings tab.
 *
 * @param {Object}   props
 * @param {boolean}  props.isDirty  Unsaved changes exist.
 * @param {boolean}  props.isSaving A save is running.
 * @param {Function} props.onSave   Saves.
 * @param {Function} props.onReset  Discards unsaved changes.
 */
export default function SaveBar( { isDirty, isSaving, onSave, onReset } ) {
	return (
		<div className="sgsp-savebar">
			<span className="sgsp-savebar__state" role="status">
				{ isDirty ? (
					<CircleDashed size={ 15 } aria-hidden="true" />
				) : (
					<CircleCheck size={ 15 } aria-hidden="true" />
				) }
				{ isDirty
					? __( 'Unsaved changes', 'keepmerge-push-staging-to-live' )
					: __( 'All changes saved', 'keepmerge-push-staging-to-live' ) }
			</span>
			<span className="sgsp-flex sgsp-flex-wrap sgsp-gap-2">
				<button
					type="button"
					className="sgsp-button sgsp-button--secondary"
					onClick={ onReset }
					disabled={ isSaving || ! isDirty }
				>
					{ __( 'Discard changes', 'keepmerge-push-staging-to-live' ) }
				</button>
				<button
					type="button"
					className="sgsp-button sgsp-button--primary"
					onClick={ onSave }
					disabled={ isSaving || ! isDirty }
				>
					{ isSaving ? (
						<LoaderCircle size={ 15 } aria-hidden="true" className="sgsp-animate-spin" />
					) : (
						<Save size={ 15 } aria-hidden="true" />
					) }
					{ isSaving
						? __( 'Saving…', 'keepmerge-push-staging-to-live' )
						: __( 'Save settings', 'keepmerge-push-staging-to-live' ) }
				</button>
			</span>
		</div>
	);
}
