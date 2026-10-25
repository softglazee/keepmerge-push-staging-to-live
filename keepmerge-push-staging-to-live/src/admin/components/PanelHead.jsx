import { createElement } from '@wordpress/element';

/**
 * Heading row of a tab panel: an h2, one line on what the tab is for, and optional actions.
 *
 * @param {Object} props
 * @param {string} props.title       Heading.
 * @param {string} [props.description] One or two sentences.
 * @param {*}      [props.actions]   Buttons at the end of the row.
 */
export default function PanelHead( { title, description, actions } ) {
	return (
		<div className="sgsp-panel__head">
			<div className="sgsp-min-w-0">
				<h2 className="sgsp-panel__title">{ title }</h2>
				{ description && <p className="sgsp-panel__desc">{ description }</p> }
			</div>
			{ actions && <div className="sgsp-flex sgsp-flex-wrap sgsp-gap-2">{ actions }</div> }
		</div>
	);
}
