import { createElement } from '@wordpress/element';

/**
 * Toggle: a switch on a real button with role="switch", so it is reachable with the keyboard
 * and announced with its state.
 *
 * @param {Object}   props
 * @param {boolean}  props.checked   Current state.
 * @param {Function} props.onChange  Receives the new state.
 * @param {string}   [props.id]      Element ID (for a <label htmlFor>).
 * @param {string}   [props.labelledBy] ID of the visible label.
 * @param {string}   [props.describedBy] ID of the hint.
 * @param {boolean}  [props.disabled] Disabled.
 */
export default function Toggle( {
	checked,
	onChange,
	id,
	labelledBy,
	describedBy,
	disabled,
} ) {
	return (
		<button
			type="button"
			id={ id }
			className="sgsp-toggle"
			role="switch"
			aria-checked={ !! checked }
			aria-labelledby={ labelledBy }
			aria-describedby={ describedBy }
			disabled={ disabled }
			onClick={ () => onChange( ! checked ) }
		>
			<span className="sgsp-toggle__thumb" />
		</button>
	);
}
