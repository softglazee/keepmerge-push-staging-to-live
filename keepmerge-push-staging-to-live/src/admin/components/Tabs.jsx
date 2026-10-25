import { createElement, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Tabs: the WAI-ARIA tabs pattern. One tab is in the tab order at a time; arrow keys move
 * between tabs, Home and End jump to the ends, and moving also selects (automatic
 * activation), which suits panels that load quickly.
 *
 * @param {Object}   props
 * @param {Array}    props.tabs      [ { id, label, icon } ].
 * @param {string}   props.selected  Selected tab ID.
 * @param {Function} props.onSelect  Receives a tab ID.
 * @param {string}   props.idPrefix  Prefix for tab and panel IDs.
 */
export default function Tabs( { tabs, selected, onSelect, idPrefix } ) {
	const refs = useRef( {} );

	function move( event, index ) {
		let next = null;

		switch ( event.key ) {
			case 'ArrowRight':
			case 'ArrowDown':
				next = ( index + 1 ) % tabs.length;
				break;
			case 'ArrowLeft':
			case 'ArrowUp':
				next = ( index - 1 + tabs.length ) % tabs.length;
				break;
			case 'Home':
				next = 0;
				break;
			case 'End':
				next = tabs.length - 1;
				break;
			default:
				return;
		}

		event.preventDefault();
		const id = tabs[ next ].id;
		onSelect( id );
		if ( refs.current[ id ] ) {
			refs.current[ id ].focus();
		}
	}

	return (
		<div
			className="sgsp-tabs"
			role="tablist"
			aria-label={ __( 'Keepmerge sections', 'keepmerge-push-staging-to-live' ) }
		>
			{ tabs.map( ( { id, label, icon: Icon }, index ) => {
				const isSelected = id === selected;
				return (
					<button
						key={ id }
						type="button"
						role="tab"
						id={ `${ idPrefix }-tab-${ id }` }
						aria-selected={ isSelected }
						aria-controls={ `${ idPrefix }-panel-${ id }` }
						tabIndex={ isSelected ? 0 : -1 }
						className="sgsp-tab"
						ref={ ( node ) => {
							refs.current[ id ] = node;
						} }
						onClick={ () => onSelect( id ) }
						onKeyDown={ ( event ) => move( event, index ) }
					>
						<Icon size={ 15 } aria-hidden="true" />
						{ label }
					</button>
				);
			} ) }
		</div>
	);
}
