import { createElement, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { LoaderCircle, Save } from 'lucide-react';
import Alert from '../../components/Alert';
import Badge from '../../components/Badge';
import { decideHeld, errorMessage } from '../../api';
import { number } from '../send/plan-labels';
import { gateRuleLabel, kindName } from './labels';

const DECISIONS = [ 'approve', 'filtered', 'skip' ];

/**
 * Review of held items (blueprint §10.5): every item with the reason it was held and an excerpt of
 * each flagged field, shown as plain text. Nothing is pre-chosen. "Approve as is" is offered only
 * to an account with unfiltered_html (and edit_css for global styles with CSS); the server checks
 * again per item. "Apply with WordPress's HTML filter" exists only for items held for titles,
 * content, excerpts or descriptions.
 *
 * @param {Object}   props
 * @param {Object}   props.push     The received push in detail.
 * @param {Function} props.onSaved  Receives the server's answer.
 * @param {Function} props.notify   Toast.
 */
export default function HeldReview( { push, onSaved, notify } ) {
	const items = push.held_items.filter( ( item ) => 'held' === item.status );
	const [ choices, setChoices ] = useState( () => {
		const start = {};
		items.forEach( ( item ) => {
			start[ item.uuid ] = item.review || '';
		} );
		return start;
	} );
	const [ selected, setSelected ] = useState( {} );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const canApprove = ( item ) => push.can.unfiltered_html && ( ! item.needs_css || push.can.edit_css );
	const allowed = ( item, decision ) => {
		if ( 'approve' === decision ) {
			return canApprove( item );
		}
		if ( 'filtered' === decision ) {
			return item.can_filter;
		}
		return true;
	};
	const label = ( decision ) => {
		switch ( decision ) {
			case 'approve':
				return __( 'Approve as is', 'keepmerge-push-staging-to-live' );
			case 'filtered':
				return __( 'Apply with WordPress\'s HTML filter', 'keepmerge-push-staging-to-live' );
			default:
				return __( 'Skip', 'keepmerge-push-staging-to-live' );
		}
	};

	const pending = useMemo( () => {
		const out = {};
		items.forEach( ( item ) => {
			if ( choices[ item.uuid ] && choices[ item.uuid ] !== item.review ) {
				out[ item.uuid ] = choices[ item.uuid ];
			}
		} );
		return out;
	}, [ choices, items ] );
	const pendingCount = Object.keys( pending ).length;
	const selectedUuids = Object.keys( selected ).filter( ( uuid ) => selected[ uuid ] );

	const bulk = ( decision ) => {
		const next = { ...choices };
		items.forEach( ( item ) => {
			if ( selected[ item.uuid ] && allowed( item, decision ) ) {
				next[ item.uuid ] = decision;
			}
		} );
		setChoices( next );
	};

	const save = () => {
		setBusy( true );
		setError( '' );
		decideHeld( push.id, pending )
			.then( ( answer ) => {
				notify(
					'success',
					sprintf(
						/* translators: %s: number of items. */
						_n( 'Decision saved for %s item.', 'Decisions saved for %s items.', pendingCount, 'keepmerge-push-staging-to-live' ),
						number( pendingCount )
					)
				);
				setSelected( {} );
				onSaved( answer );
			} )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => setBusy( false ) );
	};

	const bulkDisabled = 0 === selectedUuids.length || busy;

	return (
		<div className="sgsp-review">
			{ ! push.can.unfiltered_html && (
				<Alert tone="info" title={ __( 'Approve as is is not available to your account', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ __( 'Writing markup unchanged needs the "unfiltered_html" capability, which your account does not have on this site (for example because DISALLOW_UNFILTERED_HTML is set). You can apply items with WordPress\'s HTML filter or skip them.', 'keepmerge-push-staging-to-live' ) }
					</p>
				</Alert>
			) }

			<div className="sgsp-review__bulk" role="group" aria-label={ __( 'Decide the selected items', 'keepmerge-push-staging-to-live' ) }>
				<span className="sgsp-review__count">
					{ sprintf(
						/* translators: %s: number of items. */
						_n( '%s selected', '%s selected', selectedUuids.length, 'keepmerge-push-staging-to-live' ),
						number( selectedUuids.length )
					) }
				</span>
				{ DECISIONS.map( ( decision ) => (
					<button key={ decision } type="button" className="sgsp-button sgsp-button--secondary sgsp-button--small" disabled={ bulkDisabled } onClick={ () => bulk( decision ) }>
						{ label( decision ) }
					</button>
				) ) }
			</div>

			<ul className="sgsp-review__list">
				{ items.map( ( item ) => {
					const name = `sgsp-held-${ item.uuid }`;
					return (
						<li key={ item.uuid } className="sgsp-review__item">
							<div className="sgsp-review__head">
								<label className="sgsp-review__pick">
									<input
										type="checkbox"
										checked={ !! selected[ item.uuid ] }
										onChange={ ( event ) => setSelected( { ...selected, [ item.uuid ]: event.target.checked } ) }
									/>
									<span className="screen-reader-text">{ __( 'Select', 'keepmerge-push-staging-to-live' ) }</span>
								</label>
								<div className="sgsp-review__title">
									<strong>{ item.title || item.uuid }</strong>
									<span className="sgsp-review__kind">{ kindName( item.kind, item.type ) }</span>
								</div>
								{ item.review ? (
									<Badge tone="success">
										{ sprintf(
											/* translators: %s: decision. */
											__( 'Decided: %s', 'keepmerge-push-staging-to-live' ),
											label( item.review )
										) }
									</Badge>
								) : (
									<Badge tone="warning">{ __( 'Not decided', 'keepmerge-push-staging-to-live' ) }</Badge>
								) }
							</div>
							<ul className="sgsp-review__reasons">
								{ item.reasons.map( ( reason, index ) => (
									<li key={ index }>
										<span className="sgsp-review__why">
											{ sprintf(
												/* translators: 1: field, 2: why it was held. */
												__( '%1$s: %2$s', 'keepmerge-push-staging-to-live' ),
												reason.field,
												gateRuleLabel( reason.rule )
											) }
										</span>
										{ reason.excerpt && <code className="sgsp-review__excerpt">{ reason.excerpt }</code> }
									</li>
								) ) }
							</ul>
							<fieldset className="sgsp-review__choices">
								<legend className="screen-reader-text">
									{ sprintf(
										/* translators: %s: item title. */
										__( 'Decision for %s', 'keepmerge-push-staging-to-live' ),
										item.title || item.uuid
									) }
								</legend>
								{ DECISIONS.map( ( decision ) => (
									<label key={ decision } className={ `sgsp-review__choice${ allowed( item, decision ) ? '' : ' is-disabled' }` }>
										<input
											type="radio"
											name={ name }
											value={ decision }
											checked={ decision === choices[ item.uuid ] }
											disabled={ ! allowed( item, decision ) || busy }
											onChange={ () => setChoices( { ...choices, [ item.uuid ]: decision } ) }
										/>
										<span>{ label( decision ) }</span>
									</label>
								) ) }
							</fieldset>
							{ ! item.can_filter && (
								<p className="sgsp-field__hint sgsp-m-0">
									{ __( 'Held for markup in custom fields, options or widgets, which WordPress\'s HTML filter does not cover: approve it as is or skip it.', 'keepmerge-push-staging-to-live' ) }
								</p>
							) }
							{ item.needs_css && ! push.can.edit_css && (
								<p className="sgsp-field__hint sgsp-m-0">
									{ __( 'These global styles carry custom CSS: approving them needs the "edit_css" capability.', 'keepmerge-push-staging-to-live' ) }
								</p>
							) }
						</li>
					);
				} ) }
			</ul>

			{ error && (
				<Alert tone="danger" title={ __( 'The decisions were not saved', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ error }</p>
				</Alert>
			) }

			<div className="sgsp-decisionbar__actions">
				<button type="button" className="sgsp-button sgsp-button--primary" disabled={ 0 === pendingCount || busy } onClick={ save }>
					{ busy ? <LoaderCircle size={ 15 } aria-hidden="true" /> : <Save size={ 15 } aria-hidden="true" /> }
					{ pendingCount > 0
						? sprintf(
								/* translators: %s: number of decisions. */
								_n( 'Save %s decision', 'Save %s decisions', pendingCount, 'keepmerge-push-staging-to-live' ),
								number( pendingCount )
						  )
						: __( 'Save decisions', 'keepmerge-push-staging-to-live' ) }
				</button>
			</div>
		</div>
	);
}

