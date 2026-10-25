import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Skeleton: one placeholder bar. The shimmer stops under prefers-reduced-motion.
 *
 * @param {Object}        props
 * @param {string|number} [props.width]  CSS width.
 * @param {number}        [props.height] Height in pixels.
 * @param {number}        [props.radius] Corner radius in pixels.
 */
export default function Skeleton( { width = '100%', height = 14, radius = 6 } ) {
	return (
		<span
			className="sgsp-skeleton"
			style={ { width, height, borderRadius: radius } }
			aria-hidden="true"
		/>
	);
}

/**
 * A card-shaped loading placeholder, announced once to screen readers.
 *
 * @param {Object} props
 * @param {number} [props.lines] Number of text lines.
 */
export function SkeletonCard( { lines = 3 } ) {
	return (
		<div className="sgsp-card sgsp-skeleton-card" role="status">
			<span className="screen-reader-text">
				{ __( 'Loading…', 'keepmerge-push-staging-to-live' ) }
			</span>
			<div className="sgsp-flex sgsp-items-center sgsp-gap-3">
				<Skeleton width={ 36 } height={ 36 } radius={ 10 } />
				<Skeleton width="40%" height={ 16 } />
			</div>
			{ Array.from( { length: lines } ).map( ( unused, index ) => (
				<Skeleton key={ index } width={ `${ 92 - index * 14 }%` } />
			) ) }
		</div>
	);
}
