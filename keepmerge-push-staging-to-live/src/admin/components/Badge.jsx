import { createElement } from '@wordpress/element';
import {
	CircleAlert,
	CircleCheck,
	CircleDashed,
	CircleX,
	Info,
	TriangleAlert,
} from 'lucide-react';

const ICONS = {
	success: CircleCheck,
	warning: TriangleAlert,
	danger: CircleX,
	info: Info,
	neutral: CircleDashed,
	alert: CircleAlert,
};

/**
 * Badge: a status shown by icon shape and words as well as colour, so it reads the same
 * without colour vision.
 *
 * @param {Object}   props
 * @param {string}   [props.tone]  success, warning, danger, info or neutral.
 * @param {Function} [props.icon]  Icon to use instead of the tone's default.
 * @param {string}   props.children Label.
 */
export default function Badge( { tone = 'neutral', icon, children } ) {
	const Icon = icon || ICONS[ tone ] || CircleDashed;
	const modifier = 'neutral' === tone ? '' : ` sgsp-badge--${ tone }`;

	return (
		<span className={ `sgsp-badge${ modifier }` }>
			<Icon size={ 13 } strokeWidth={ 2.25 } aria-hidden="true" />
			{ children }
		</span>
	);
}

/**
 * Maps a readiness or audit result to a badge tone.
 *
 * @param {string} status ok, warning, error, denied.
 * @return {string} Tone.
 */
export function toneFor( status ) {
	switch ( status ) {
		case 'ok':
			return 'success';
		case 'warning':
			return 'warning';
		case 'error':
		case 'denied':
			return 'danger';
		default:
			return 'neutral';
	}
}
