import { createElement } from '@wordpress/element';
import { CircleAlert, Info, TriangleAlert } from 'lucide-react';

const ICONS = {
	danger: CircleAlert,
	warning: TriangleAlert,
	info: Info,
};

/**
 * Alert: a message inside our own screen; the plugin shows no notices anywhere else. Errors are
 * announced immediately (role="alert"); the others politely.
 *
 * @param {Object} props
 * @param {string} [props.tone]  danger, warning or info.
 * @param {string} [props.title] Bold first line.
 * @param {*}      props.children Body.
 */
export default function Alert( { tone = 'info', title, children } ) {
	const Icon = ICONS[ tone ] || Info;

	return (
		<div
			className={ `sgsp-alert sgsp-alert--${ tone }` }
			role={ 'danger' === tone ? 'alert' : 'status' }
		>
			<Icon size={ 16 } aria-hidden="true" />
			<div className="sgsp-min-w-0">
				{ title && <strong className="sgsp-alert__title">{ title }</strong> }
				{ children }
			</div>
		</div>
	);
}
