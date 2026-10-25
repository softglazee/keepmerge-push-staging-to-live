import { createElement } from '@wordpress/element';

/**
 * EmptyState: what this area will hold and what to do next, instead of a blank box.
 *
 * @param {Object}   props
 * @param {Function} [props.icon]   Lucide icon component.
 * @param {string}   props.title    Short statement of the state.
 * @param {string}   [props.text]   One or two sentences on what to do next.
 * @param {string[]} [props.steps]  Numbered next steps.
 * @param {*}        [props.action] A button or link.
 */
export default function EmptyState( { icon: Icon, title, text, steps, action } ) {
	return (
		<div className="sgsp-empty">
			{ Icon && (
				<span className="sgsp-empty__icon" aria-hidden="true">
					<Icon size={ 22 } strokeWidth={ 1.75 } />
				</span>
			) }
			<h3 className="sgsp-empty__title">{ title }</h3>
			{ text && <p className="sgsp-empty__text">{ text }</p> }
			{ steps && steps.length > 0 && (
				<ol className="sgsp-steps">
					{ steps.map( ( step ) => (
						<li key={ step }>{ step }</li>
					) ) }
				</ol>
			) }
			{ action && <div className="sgsp-empty__action">{ action }</div> }
		</div>
	);
}
