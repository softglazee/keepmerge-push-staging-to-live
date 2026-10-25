import { createElement, Fragment, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { LoaderCircle, Send } from 'lucide-react';
import Alert from '../../components/Alert';
import Modal from '../../components/Modal';
import Skeleton from '../../components/Skeleton';
import { errorMessage, getPlan, startPush } from '../../api';
import { actionLabel, groupLabel, number } from './plan-labels';

/**
 * The push dialog: what the push will do, with exact counts, the conflicts as decided, and the
 * live domain typed to confirm. The domain is compared again on the server
 * (POST /admin/pushes); the button only saves a round trip.
 *
 * @param {Object}   props
 * @param {Object}   props.summary   Plan summary.
 * @param {number}   props.planId    Plan ID.
 * @param {string}   props.host      Live host.
 * @param {Function} props.onClose   Closes the dialog.
 * @param {Function} props.onStarted Receives the push once live opened it.
 */
export default function PushDialog( { summary, planId, host, onClose, onStarted } ) {
	const [ typed, setTyped ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ decided, setDecided ] = useState( null );
	const d = summary.dialog;
	const lines = [
		[ d.create, __( 'created on live', 'keepmerge-push-staging-to-live' ) ],
		[ d.update, __( 'updated on live with this site\'s version', 'keepmerge-push-staging-to-live' ) ],
		[ d.recreate, __( 'recreated on live', 'keepmerge-push-staging-to-live' ) ],
		[ d.trash, __( 'moved to the Trash on live', 'keepmerge-push-staging-to-live' ) ],
		[ d.record_base, __( 'recorded as in sync (no content written)', 'keepmerge-push-staging-to-live' ) ],
		[ d.keep_live, __( 'kept as they are on live', 'keepmerge-push-staging-to-live' ) ],
		[ d.skip, __( 'skipped', 'keepmerge-push-staging-to-live' ) ],
	];
	const matches = typed.trim().toLowerCase() === host.toLowerCase();

	useEffect( () => {
		let live = true;
		getPlan( planId, { class: 'conflicts', per_page: 100 } )
			.then( ( data ) => live && setDecided( data.rows ? data.rows.items : [] ) )
			.catch( () => live && setDecided( [] ) );
		return () => {
			live = false;
		};
	}, [ planId ] );

	const push = () => {
		setBusy( true );
		setError( '' );
		startPush( planId, typed.trim() )
			.then( ( started ) => onStarted( started ) )
			.catch( ( err ) => {
				setError( errorMessage( err ) );
				setBusy( false );
			} );
	};

	const decision = ( row ) => {
		if ( row.groups && row.groups.length && 'product' === row.kind ) {
			return row.groups
				.filter( ( group ) => 'conflict' === group.class )
				.map( ( group ) => `${ groupLabel( group.name ) }: ${ actionLabel( group.decision || group.action ) }` )
				.join( ', ' );
		}
		return actionLabel( row.effective );
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: live site host. */
				__( 'Push to %s', 'keepmerge-push-staging-to-live' ),
				host
			) }
			onClose={ busy ? () => {} : onClose }
			initialFocus=".sgsp-confirm-cancel"
			footer={
				<Fragment>
					<button type="button" className="sgsp-button sgsp-button--secondary sgsp-confirm-cancel" onClick={ onClose } disabled={ busy }>
						{ __( 'Cancel', 'keepmerge-push-staging-to-live' ) }
					</button>
					<button
						type="button"
						className="sgsp-button sgsp-button--primary"
						disabled={ ! matches || busy }
						aria-describedby="sgsp-push-what"
						onClick={ push }
					>
						{ busy ? <LoaderCircle size={ 15 } aria-hidden="true" /> : <Send size={ 15 } aria-hidden="true" /> }
						{ busy ? __( 'Opening the push on live…', 'keepmerge-push-staging-to-live' ) : __( 'Push', 'keepmerge-push-staging-to-live' ) }
					</button>
				</Fragment>
			}
		>
			<p>{ __( 'With the decisions in this plan, the push leaves these items:', 'keepmerge-push-staging-to-live' ) }</p>
			<ul className="sgsp-pushcounts">
				{ lines
					.filter( ( [ count ] ) => count > 0 )
					.map( ( [ count, label ] ) => (
						<li key={ label }>
							<strong>{ number( count ) }</strong> { label }
						</li>
					) ) }
			</ul>

			<div className="sgsp-pushdialog__facts">
				<p className="sgsp-m-0">
					<strong>{ number( d.held ) }</strong>{ ' ' }
					{ _n( 'will need review on live', 'will need review on live', d.held, 'keepmerge-push-staging-to-live' ) }
				</p>
				<p className="sgsp-m-0">
					<strong>{ number( d.by_dates ) }</strong>{ ' ' }
					{ _n( 'default decided by dates', 'defaults decided by dates', d.by_dates, 'keepmerge-push-staging-to-live' ) }
				</p>
			</div>

			{ d.held > 0 && (
				<Alert tone="warning" title={ __( 'Review on live', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ sprintf(
							/* translators: %s: number of items. */
							_n(
								'%s item carries markup live holds until a live administrator approves, filters or skips it on live.',
								'%s items carry markup live holds until a live administrator approves, filters or skips them on live.',
								d.held,
								'keepmerge-push-staging-to-live'
							),
							number( d.held )
						) }
					</p>
				</Alert>
			) }
			{ d.by_dates > 0 && (
				<Alert tone="warning" title={ __( 'Decided by dates', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ sprintf(
							/* translators: %s: number of items. */
							_n(
								'%s default was decided by modification dates against the clone date. A change that does not update the date (meta only, for example SEO fields edited on live) is invisible to that rule: check this item.',
								'%s defaults were decided by modification dates against the clone date. A change that does not update the date (meta only, for example SEO fields edited on live) is invisible to that rule: check these items.',
								d.by_dates,
								'keepmerge-push-staging-to-live'
							),
							number( d.by_dates )
						) }
					</p>
				</Alert>
			) }
			{ d.products_split > 0 && (
				<p className="sgsp-field__hint">
					{ sprintf(
						/* translators: %s: number of products. */
						_n(
							'%s product is split: some field groups are pushed while live keeps others (for example its prices). Stock is never pushed unless chosen per product.',
							'%s products are split: some field groups are pushed while live keeps others (for example its prices). Stock is never pushed unless chosen per product.',
							d.products_split,
							'keepmerge-push-staging-to-live'
						),
						number( d.products_split )
					) }
				</p>
			) }

			<h3 className="sgsp-pushdialog__subtitle">{ __( 'Conflicts as decided', 'keepmerge-push-staging-to-live' ) }</h3>
			{ null === decided && <Skeleton width="60%" /> }
			{ null !== decided && 0 === decided.length && (
				<p className="sgsp-field__hint sgsp-mt-0">{ __( 'This plan has no conflicts.', 'keepmerge-push-staging-to-live' ) }</p>
			) }
			{ null !== decided && decided.length > 0 && (
				<ul className="sgsp-decided">
					{ decided.map( ( row ) => (
						<li key={ row.uuid }>
							<span className="sgsp-decided__title">{ row.title || row.uuid }</span>
							<span className="sgsp-decided__type">{ row.type_label }</span>
							<span className="sgsp-decided__action">{ decision( row ) }</span>
						</li>
					) ) }
				</ul>
			) }

			<div className="sgsp-field sgsp-mt-3">
				<label className="sgsp-field__label" htmlFor="sgsp-push-domain">
					{ sprintf(
						/* translators: %s: live site host. */
						__( 'Type %s to confirm', 'keepmerge-push-staging-to-live' ),
						host
					) }
				</label>
				<input
					id="sgsp-push-domain"
					className="sgsp-input"
					type="text"
					autoComplete="off"
					spellCheck="false"
					value={ typed }
					disabled={ busy }
					onChange={ ( event ) => setTyped( event.target.value ) }
					onKeyDown={ ( event ) => {
						if ( 'Enter' === event.key && matches && ! busy ) {
							push();
						}
					} }
					aria-invalid={ '' !== typed && ! matches }
				/>
			</div>
			<p className="sgsp-field__hint" id="sgsp-push-what">
				{ __( 'The items are sent to live and staged there. Nothing on live changes until they are applied; staged items wait up to 24 hours.', 'keepmerge-push-staging-to-live' ) }
			</p>
			{ error && (
				<Alert tone="danger" title={ __( 'The push did not start', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ error }</p>
				</Alert>
			) }
		</Modal>
	);
}
