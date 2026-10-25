import { createElement } from '@wordpress/element';

/**
 * Card: the shared surface. Optional icon chip, title, description and an element pinned to
 * the right of the heading (a badge or a button).
 *
 * @param {Object}   props
 * @param {Function} [props.icon]        Lucide icon component.
 * @param {string}   [props.tone]        'navy' (default) or 'gold' icon chip.
 * @param {string}   [props.title]       Heading text.
 * @param {string}   [props.description] One line under the heading.
 * @param {*}        [props.aside]       Element at the end of the heading row.
 * @param {string}   [props.headingLevel] 'h3' (default) or 'h2'.
 * @param {*}        props.children      Body.
 */
export default function Card( {
	icon: Icon,
	tone = 'navy',
	title,
	description,
	aside,
	headingLevel = 'h3',
	children,
} ) {
	const Heading = headingLevel;

	return (
		<section className="sgsp-card">
			{ ( title || Icon ) && (
				<div className="sgsp-card__head">
					{ Icon && (
						<span
							className={ `sgsp-card__icon${ 'gold' === tone ? ' sgsp-card__icon--gold' : '' }` }
							aria-hidden="true"
						>
							<Icon size={ 18 } strokeWidth={ 2 } />
						</span>
					) }
					<div className="sgsp-card__head-main">
						{ title && <Heading className="sgsp-card__title">{ title }</Heading> }
						{ description && <p className="sgsp-card__desc">{ description }</p> }
					</div>
					{ aside && <div className="sgsp-card__head-end">{ aside }</div> }
				</div>
			) }
			{ children }
		</section>
	);
}
