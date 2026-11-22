import { createElement, Fragment, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { LifeBuoy, LoaderCircle, Play, RotateCcw, SkipForward, Undo2 } from 'lucide-react';
import Alert from '../../components/Alert';
import Card from '../../components/Card';
import ConfirmDialog from '../../components/ConfirmDialog';
import { SkeletonCard } from '../../components/Skeleton';
import { continueApply, errorMessage, getRollbackPreview, resumePush, rollbackStep, skipFailedItem } from '../../api';
import { number } from '../send/plan-labels';
import { reasonLabel } from '../send/push-labels';
import { dateTime } from '../receive/time';
import { kindName } from './labels';

/**
 * Why live stopped an apply, in words.
 *
 * @param {string} code Error code of the session.
 * @return {string} Text.
 */
function interruptedReason( code ) {
	const labels = {
		lock_stale: __( 'No step of the apply has run for more than 5 minutes (the request that ran it stopped, for example when PHP restarted).', 'keepmerge-push-staging-to-live' ),
		lock_gone: __( 'Its push lock was gone (for example after Keepmerge was deactivated and activated again).', 'keepmerge-push-staging-to-live' ),
		lock_released: __( 'An administrator released its push lock.', 'keepmerge-push-staging-to-live' ),
		window_closed: __( 'The receive window was closed while the staging site was applying it.', 'keepmerge-push-staging-to-live' ),
		pairing_revoked: __( 'The pairing was revoked while the staging site was applying it.', 'keepmerge-push-staging-to-live' ),
	};
	return labels[ code ] || __( 'The apply stopped without finishing.', 'keepmerge-push-staging-to-live' );
}

/**
 * A typed-domain field for the dialogs.
 *
 * @param {Object}   props
 * @param {string}   props.id       Input ID.
 * @param {string}   props.host     This site's host.
 * @param {string}   props.value    Typed text.
 * @param {Function} props.onChange Change handler.
 */
function DomainField( { id, host, value, onChange } ) {
	const matches = value.trim().toLowerCase() === host;
	return (
		<div className="sgsp-field">
			<label className="sgsp-field__label" htmlFor={ id }>
				{ sprintf(
					/* translators: %s: domain of the live site. */
					__( 'Type %s to confirm', 'keepmerge-push-staging-to-live' ),
					host
				) }
			</label>
			<input
				id={ id }
				className="sgsp-input"
				type="text"
				autoComplete="off"
				spellCheck="false"
				value={ value }
				onChange={ ( event ) => onChange( event.target.value ) }
				aria-invalid={ '' !== value && ! matches }
			/>
		</div>
	);
}

/**
 * The rollback dialog (blueprint §11.3): the preview with exact counts, the conflicts (none
 * ticked), the items the administrator's own filter would change, and the typed domain.
 *
 * @param {Object}   props
 * @param {Object}   props.push      Push detail.
 * @param {Function} props.onCancel  Close.
 * @param {Function} props.onConfirm Start with the typed domain and the ticked conflicts.
 */
function RollbackDialog( { push, onCancel, onConfirm } ) {
	const [ preview, setPreview ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ typed, setTyped ] = useState( '' );
	const [ ticked, setTicked ] = useState( [] );

	useEffect( () => {
		let alive = true;
		getRollbackPreview( push.id )
			.then( ( data ) => alive && setPreview( data ) )
			.catch( ( err ) => alive && setError( errorMessage( err ) ) );
		return () => {
			alive = false;
		};
	}, [ push.id ] );

	const matches = typed.trim().toLowerCase() === push.host;
	const toggle = ( uuid ) => setTicked( ( list ) => ( list.includes( uuid ) ? list.filter( ( one ) => one !== uuid ) : [ ...list, uuid ] ) );

	return (
		<ConfirmDialog
			title={ __( 'Roll back this push?', 'keepmerge-push-staging-to-live' ) }
			confirmLabel={ __( 'Roll back', 'keepmerge-push-staging-to-live' ) }
			destructive
			confirmDisabled={ ! preview || ! preview.can || ! matches }
			onConfirm={ () => onConfirm( typed.trim(), ticked ) }
			onCancel={ onCancel }
		>
			{ error && (
				<Alert tone="danger" title={ __( 'The preview did not load', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ error }</p>
				</Alert>
			) }
			{ ! preview && ! error && <SkeletonCard lines={ 4 } /> }
			{ preview && ! preview.can && (
				<Alert tone="warning" title={ __( 'This push cannot be rolled back', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ preview.message }</p>
				</Alert>
			) }
			{ preview && preview.can && (
				<>
					<ul className="sgsp-rollback__counts">
						<li>
							<strong>{ number( preview.restore ) }</strong>{ ' ' }
							{ _n( 'item goes back to its snapshot', 'items go back to their snapshot', preview.restore, 'keepmerge-push-staging-to-live' ) }
						</li>
						<li>
							<strong>{ number( preview.remove ) }</strong>{ ' ' }
							{ _n( 'item the push created is removed (posts and products to the Trash)', 'items the push created are removed (posts and products to the Trash)', preview.remove, 'keepmerge-push-staging-to-live' ) }
						</li>
						<li>
							<strong>{ number( preview.files_restore ) }</strong>{ ' ' }
							{ _n( 'replaced media file is put back', 'replaced media files are put back', preview.files_restore, 'keepmerge-push-staging-to-live' ) }
							{ ', ' }
							<strong>{ number( preview.files_remove ) }</strong>{ ' ' }
							{ _n( 'added file is deleted if nothing uses it', 'added files are deleted if nothing uses them', preview.files_remove, 'keepmerge-push-staging-to-live' ) }
						</li>
						<li>
							<strong>{ number( preview.conflict_count ) }</strong>{ ' ' }
							{ _n( 'conflict: changed on this site after the push, skipped unless you tick it', 'conflicts: changed on this site after the push, skipped unless you tick them', preview.conflict_count, 'keepmerge-push-staging-to-live' ) }
						</li>
					</ul>
					<p className="sgsp-field__hint">
						{ __( 'Orders, customers and stock are never touched: orders placed since the push stay, and stock keeps their effect.', 'keepmerge-push-staging-to-live' ) }
						{ preview.done > 0 &&
							' ' +
								sprintf(
									/* translators: %s: number of items. */
									__( '%s items were handled already by the rollback that stopped; they are not done again.', 'keepmerge-push-staging-to-live' ),
									number( preview.done )
								) }
					</p>
					{ ! preview.complete && (
						<p className="sgsp-field__hint">{ __( 'This push is large: not every item could be checked for conflicts in time. Each item is checked again when the rollback reaches it.', 'keepmerge-push-staging-to-live' ) }</p>
					) }
					{ preview.conflicts.length > 0 && (
						<fieldset className="sgsp-rollback__conflicts">
							<legend className="sgsp-field__label">{ __( 'Conflicts (tick to roll back anyway; the later change on this site is lost)', 'keepmerge-push-staging-to-live' ) }</legend>
							{ preview.conflicts.map( ( item ) => (
								<label key={ item.uuid } className="sgsp-checkbox" htmlFor={ `sgsp-rollback-${ item.uuid }` }>
									<input id={ `sgsp-rollback-${ item.uuid }` } type="checkbox" checked={ ticked.includes( item.uuid ) } onChange={ () => toggle( item.uuid ) } />
									<span>
										{ item.title || item.uuid } <span className="sgsp-review__kind">{ kindName( item.kind, item.type ) }</span>
										<span className="sgsp-checkbox__meta">{ reasonLabel( item.reason ) }</span>
									</span>
								</label>
							) ) }
						</fieldset>
					) }
					{ preview.filtered.length > 0 && (
						<Alert tone="warning" title={ __( 'Restored through your own HTML filter', 'keepmerge-push-staging-to-live' ) }>
							<p className="sgsp-m-0">
								{ sprintf(
									/* translators: %s: number of items. */
									_n( '%s item had markup before the push that your account may not save unfiltered, so it comes back filtered:', '%s items had markup before the push that your account may not save unfiltered, so they come back filtered:', preview.filtered.length, 'keepmerge-push-staging-to-live' ),
									number( preview.filtered.length )
								) }
							</p>
							<ul className="sgsp-rollback__filtered">
								{ preview.filtered.map( ( item ) => (
									<li key={ item.uuid }>{ item.title || item.uuid }</li>
								) ) }
							</ul>
						</Alert>
					) }
					<DomainField id="sgsp-rollback-domain" host={ push.host } value={ typed } onChange={ setTyped } />
				</>
			) }
		</ConfirmDialog>
	);
}

/**
 * Recovery of a push received on this site (blueprint §10.4, §11, §12): Resume an interrupted
 * apply; Retry, Skip or Roll back after a failed item; Roll back an applied push. Every action
 * runs from this administrator's own session after the site's domain is typed.
 *
 * @param {Object}   props
 * @param {Object}   props.push      Push detail.
 * @param {Function} props.onChanged Reload the detail.
 * @param {Function} props.notify    Toast.
 */
export default function RecoveryCard( { push, onChanged, notify } ) {
	const [ dialog, setDialog ] = useState( '' );
	const [ typed, setTyped ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ runError, setRunError ] = useState( '' );
	const [ progress, setProgress ] = useState( null );
	const alive = useRef( true );

	useEffect(
		() => () => {
			alive.current = false;
		},
		[]
	);

	const recovery = push.recovery || {};
	const state = push.state;
	const rolling = 'rolling_back' === state;
	const failed = push.failed_item;
	const showCard = recovery.resume || recovery.retry || recovery.rollback || 'rolled_back' === state || ( 'applied' === state && ! recovery.rollback );
	if ( ! showCard ) {
		return null;
	}

	const finish = ( promise, success ) => {
		setBusy( true );
		setRunError( '' );
		promise
			.then( ( answer ) => {
				if ( alive.current && success && answer && success.states.includes( answer.state ) ) {
					notify( 'success', success.text );
				}
			} )
			.catch( ( err ) => alive.current && setRunError( errorMessage( err ) ) )
			.finally( () => {
				if ( alive.current ) {
					setBusy( false );
					setProgress( null );
					onChanged();
				}
			} );
	};

	const runRollback = ( confirm, include ) => {
		setDialog( '' );
		const loop = () =>
			rollbackStep( push.id, confirm, include ).then( ( answer ) => {
				if ( alive.current && answer.rollback ) {
					setProgress( answer.rollback );
				}
				return 'rolling_back' === answer.state && alive.current ? loop() : answer;
			} );
		finish( loop(), { states: [ 'rolled_back' ], text: __( 'The push is rolled back on this site.', 'keepmerge-push-staging-to-live' ) } );
	};

	// After a resume, the remaining steps go on through "continue" (same session, same rights).
	const resumeLoop = ( confirm ) =>
		continueApply( push.id, confirm ).then( ( answer ) => ( [ 'snapshotting', 'applying' ].includes( answer.state ) && alive.current ? resumeLoop( confirm ) : answer ) );

	const runResume = ( action ) => {
		const confirm = typed.trim();
		setDialog( '' );
		const first = 'skip' === action ? skipFailedItem( push.id, confirm, failed ? failed.uuid : '' ) : resumePush( push.id, confirm );
		const more = ( answer ) => ( [ 'snapshotting', 'applying' ].includes( answer.state ) && alive.current ? resumeLoop( confirm ) : answer );
		finish( first.then( more ), { states: [ 'applied' ], text: __( 'The push is applied on this site.', 'keepmerge-push-staging-to-live' ) } );
	};

	const matches = typed.trim().toLowerCase() === push.host;
	const rbProgress = progress || ( rolling ? push.rollback : null );

	return (
		<Card
			icon={ LifeBuoy }
			title={ __( 'Recovery and rollback', 'keepmerge-push-staging-to-live' ) }
			description={ __( 'Everything here runs from your own account on this site; the staging site is not needed. Each item\'s snapshot was taken before it was written.', 'keepmerge-push-staging-to-live' ) }
		>
			{ 'interrupted' === state && (
				<Alert tone="warning" title={ __( 'Interrupted', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ interruptedReason( push.error ) }{ ' ' }
						{ __( 'Resume finishes it from where it stopped: an item that was fully written is not written again, one that was not started is written, and one left half-written stops for your decision. Or roll back what was applied.', 'keepmerge-push-staging-to-live' ) }
					</p>
				</Alert>
			) }
			{ 'failed_partial' === state && failed && (
				<Alert tone="danger" title={ __( 'Stopped at an item', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						<strong>{ failed.title || failed.uuid }</strong> <span className="sgsp-review__kind">{ kindName( failed.kind, failed.type ) }</span>
						{ ' – ' }
						{ reasonLabel( failed.reason ) }
					</p>
					<p className="sgsp-m-0">
						{ __( 'Retry puts the item back to its snapshot and writes it again. Skip puts it back as it was and goes on with the rest. Roll back undoes the whole push.', 'keepmerge-push-staging-to-live' ) }
					</p>
				</Alert>
			) }
			{ rolling && (
				<Alert tone={ push.interrupted ? 'warning' : 'info' } title={ push.interrupted ? __( 'Rollback interrupted', 'keepmerge-push-staging-to-live' ) : __( 'Rolling back', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ __( 'Continue the rollback: items it already handled are not handled again.', 'keepmerge-push-staging-to-live' ) }</p>
				</Alert>
			) }
			{ 'rolled_back' === state && (
				<p className="sgsp-field__hint sgsp-mt-0">
					{ sprintf(
						/* translators: %s: date and time. */
						__( 'Rolled back on %s. A rolled-back push cannot be rolled back again; push again from the staging site instead.', 'keepmerge-push-staging-to-live' ),
						dateTime( push.rollback && push.rollback.finished ? push.rollback.finished : 0 )
					) }
				</p>
			) }
			{ 'applied' === state && ! recovery.rollback && (
				<p className="sgsp-field__hint sgsp-mt-0">{ reasonLabel( recovery.rollback_reason ) }</p>
			) }
			{ rbProgress && rbProgress.total > 0 && ( busy || rolling ) && (
				<div className="sgsp-progress" role="status" aria-live="polite">
					<div className="sgsp-progress__label">
						{ sprintf(
							/* translators: 1: items handled, 2: items of the rollback. */
							__( 'Rolling back: %1$s of %2$s', 'keepmerge-push-staging-to-live' ),
							number( rbProgress.done ),
							number( rbProgress.total )
						) }
					</div>
					<div className="sgsp-progress__track" aria-hidden="true">
						<div className="sgsp-progress__bar" style={ { width: `${ Math.min( 100, Math.round( ( 100 * rbProgress.done ) / rbProgress.total ) ) }%` } } />
					</div>
				</div>
			) }
			{ runError && (
				<Alert tone="danger" title={ __( 'That did not complete', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ runError }</p>
				</Alert>
			) }
			<div className="sgsp-decisionbar__actions">
				{ recovery.resume && (
					<button type="button" className="sgsp-button sgsp-button--primary" disabled={ busy } onClick={ () => setDialog( 'resume' ) }>
						{ busy ? <LoaderCircle size={ 15 } aria-hidden="true" /> : <Play size={ 15 } aria-hidden="true" /> }
						{ __( 'Resume', 'keepmerge-push-staging-to-live' ) }
					</button>
				) }
				{ recovery.retry && (
					<button type="button" className="sgsp-button sgsp-button--primary" disabled={ busy } onClick={ () => setDialog( 'retry' ) }>
						<RotateCcw size={ 15 } aria-hidden="true" />
						{ __( 'Retry', 'keepmerge-push-staging-to-live' ) }
					</button>
				) }
				{ recovery.skip && (
					<button type="button" className="sgsp-button sgsp-button--secondary" disabled={ busy } onClick={ () => setDialog( 'skip' ) }>
						<SkipForward size={ 15 } aria-hidden="true" />
						{ __( 'Skip this item and continue', 'keepmerge-push-staging-to-live' ) }
					</button>
				) }
				{ recovery.rollback && (
					<button type="button" className="sgsp-button sgsp-button--danger" disabled={ busy } onClick={ () => setDialog( 'rollback' ) }>
						<Undo2 size={ 15 } aria-hidden="true" />
						{ rolling ? __( 'Continue rollback', 'keepmerge-push-staging-to-live' ) : __( 'Roll back…', 'keepmerge-push-staging-to-live' ) }
					</button>
				) }
			</div>

			{ 'rollback' === dialog && <RollbackDialog push={ push } onCancel={ () => setDialog( '' ) } onConfirm={ runRollback } /> }
			{ [ 'resume', 'retry', 'skip' ].includes( dialog ) && (
				<ConfirmDialog
					title={
						{
							resume: __( 'Resume this push on this site?', 'keepmerge-push-staging-to-live' ),
							retry: __( 'Retry the failed item?', 'keepmerge-push-staging-to-live' ),
							skip: __( 'Skip the failed item?', 'keepmerge-push-staging-to-live' ),
						}[ dialog ]
					}
					confirmLabel={
						{
							resume: __( 'Resume', 'keepmerge-push-staging-to-live' ),
							retry: __( 'Retry', 'keepmerge-push-staging-to-live' ),
							skip: __( 'Skip and continue', 'keepmerge-push-staging-to-live' ),
						}[ dialog ]
					}
					confirmDisabled={ ! matches }
					onConfirm={ () => runResume( dialog ) }
					onCancel={ () => setDialog( '' ) }
				>
					<p>
						{
							{
								resume: __( 'The apply goes on from where it stopped, written from your account. Items already written are not written again.', 'keepmerge-push-staging-to-live' ),
								retry: __( 'The item goes back to its snapshot, then it is written again from your account; the rest of the push follows.', 'keepmerge-push-staging-to-live' ),
								skip: __( 'The item goes back to how it was before the push and stays so; the rest of the push is applied.', 'keepmerge-push-staging-to-live' ),
							}[ dialog ]
						}
					</p>
					<DomainField id="sgsp-recovery-domain" host={ push.host } value={ typed } onChange={ setTyped } />
				</ConfirmDialog>
			) }
		</Card>
	);
}
