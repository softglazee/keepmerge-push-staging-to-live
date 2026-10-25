import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Toggle from './Toggle';
import Badge from './Badge';

/**
 * A labelled switch with a hint and an On/Off word next to the label, so the state is
 * readable without relying on the switch colour.
 *
 * @param {Object}   props
 * @param {string}   props.id       Base ID.
 * @param {string}   props.label    Label.
 * @param {string}   [props.hint]   Hint under the label.
 * @param {boolean}  props.checked  State.
 * @param {Function} props.onChange Receives the new state.
 * @param {*}        [props.children] Extra content under the hint.
 */
export default function ToggleRow( { id, label, hint, checked, onChange, children } ) {
	const labelId = `${ id }-label`;
	const hintId = `${ id }-hint`;

	return (
		<div className="sgsp-toggle-row">
			<Toggle
				id={ id }
				checked={ checked }
				onChange={ onChange }
				labelledBy={ labelId }
				describedBy={ hint ? hintId : undefined }
			/>
			<div className="sgsp-min-w-0">
				<label className="sgsp-toggle-row__label" id={ labelId } htmlFor={ id }>
					{ label }
					{ /* The switch announces its own state; the word is for sighted users. */ }
					<span aria-hidden="true">
						<Badge tone={ checked ? 'success' : 'neutral' }>
							{ checked
								? __( 'On', 'keepmerge-push-staging-to-live' )
								: __( 'Off', 'keepmerge-push-staging-to-live' ) }
						</Badge>
					</span>
				</label>
				{ hint && (
					<span className="sgsp-toggle-row__hint" id={ hintId }>
						{ hint }
					</span>
				) }
				{ children }
			</div>
		</div>
	);
}
