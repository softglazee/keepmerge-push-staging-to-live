import { createElement, Fragment, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { CircleAlert, Plus, X } from 'lucide-react';

/**
 * Shape of a name: lowercase letters, digits, _ and -, optionally ending in * for a prefix.
 * The server applies the same rule (Settings::NAME_PATTERN) and is the authority; checking
 * here only saves a round trip.
 */
const NAME = /^[a-z0-9_-]{1,191}\*?$/;

/**
 * NamesField: a list of names entered one at a time and shown as removable chips. There is no
 * free-text box, because these settings take names only, never code or markup.
 *
 * @param {Object}   props
 * @param {string}   props.id          Input ID.
 * @param {string}   props.label       Field label.
 * @param {string}   [props.hint]      Hint under the field.
 * @param {string[]} props.value       Current names.
 * @param {Function} props.onChange    Receives the new list.
 * @param {Array}    [props.errors]    Server errors: [ { entry, reason } ].
 * @param {string}   [props.placeholder] Placeholder.
 */
export default function NamesField( {
	id,
	label,
	hint,
	value,
	onChange,
	errors = [],
	placeholder,
} ) {
	const [ draft, setDraft ] = useState( '' );
	const [ localError, setLocalError ] = useState( '' );
	const hintId = `${ id }-hint`;
	const errorId = `${ id }-errors`;

	function add() {
		const entries = draft
			.split( /[\s,]+/ )
			.map( ( entry ) => entry.trim().toLowerCase() )
			.filter( Boolean );

		if ( ! entries.length ) {
			return;
		}

		const bad = entries.find( ( entry ) => ! NAME.test( entry ) );
		if ( bad ) {
			setLocalError(
				sprintf(
					/* translators: %s: the text that was refused. */
					__(
						'"%s" is not a name. Use lowercase letters, digits, _ and -, with an optional * at the end for a prefix.',
						'keepmerge-push-staging-to-live'
					),
					bad.slice( 0, 60 )
				)
			);
			return;
		}

		const next = value.slice();
		entries.forEach( ( entry ) => {
			if ( ! next.includes( entry ) ) {
				next.push( entry );
			}
		} );
		onChange( next );
		setDraft( '' );
		setLocalError( '' );
	}

	const hasErrors = errors.length > 0 || '' !== localError;

	return (
		<div className="sgsp-field">
			<label className="sgsp-field__label" htmlFor={ id }>
				{ label }
			</label>
			<div className="sgsp-adder">
				<input
					id={ id }
					type="text"
					className="sgsp-input"
					value={ draft }
					placeholder={ placeholder }
					autoComplete="off"
					spellCheck={ false }
					aria-describedby={ [ hint ? hintId : '', hasErrors ? errorId : '' ].filter( Boolean ).join( ' ' ) || undefined }
					aria-invalid={ hasErrors }
					onChange={ ( event ) => {
						setDraft( event.target.value );
						setLocalError( '' );
					} }
					onKeyDown={ ( event ) => {
						if ( 'Enter' === event.key ) {
							event.preventDefault();
							add();
						}
					} }
				/>
				<button
					type="button"
					className="sgsp-button sgsp-button--secondary"
					onClick={ add }
					disabled={ '' === draft.trim() }
				>
					<Plus size={ 15 } aria-hidden="true" />
					{ __( 'Add', 'keepmerge-push-staging-to-live' ) }
				</button>
			</div>
			{ hint && (
				<p className="sgsp-field__hint" id={ hintId }>
					{ hint }
				</p>
			) }
			{ hasErrors && (
				<ul className="sgsp-field__errors" id={ errorId }>
					{ localError && (
						<li>
							<CircleAlert size={ 13 } aria-hidden="true" />
							<span>{ localError }</span>
						</li>
					) }
					{ errors.map( ( error, index ) => (
						<li key={ index }>
							<CircleAlert size={ 13 } aria-hidden="true" />
							<span>
								{ error.entry ? (
									<>
										<code className="sgsp-code">{ error.entry }</code>{ ' ' }
									</>
								) : null }
								{ error.reason }
							</span>
						</li>
					) ) }
				</ul>
			) }
			{ value.length > 0 ? (
				<ul
					className="sgsp-chipbox"
					aria-label={ sprintf(
						/* translators: %s: field label. */
						__( '%s: current entries', 'keepmerge-push-staging-to-live' ),
						label
					) }
				>
					{ value.map( ( name ) => (
						<li className="sgsp-chipbox__item" key={ name }>
							<span className="sgsp-chipbox__text">{ name }</span>
							<button
								type="button"
								className="sgsp-chipbox__remove"
								aria-label={ sprintf(
									/* translators: %s: a name in the list. */
									__( 'Remove %s', 'keepmerge-push-staging-to-live' ),
									name
								) }
								onClick={ () => onChange( value.filter( ( item ) => item !== name ) ) }
							>
								<X size={ 13 } aria-hidden="true" />
							</button>
						</li>
					) ) }
				</ul>
			) : (
				<p className="sgsp-field__hint">
					{ __( 'No entries yet.', 'keepmerge-push-staging-to-live' ) }
				</p>
			) }
		</div>
	);
}
