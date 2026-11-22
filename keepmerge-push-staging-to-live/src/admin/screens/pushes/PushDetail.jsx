import { createElement, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ArrowLeft, Camera, ListChecks, LoaderCircle, Play, ShieldAlert } from 'lucide-react';
import Alert from '../../components/Alert';
import Badge from '../../components/Badge';
import Card from '../../components/Card';
import ConfirmDialog from '../../components/ConfirmDialog';
import LoadError from '../../components/LoadError';
import { SkeletonCard } from '../../components/Skeleton';
import { continueApply, errorMessage, getPush } from '../../api';
import { number } from '../send/plan-labels';
import { pushStateInfo, reasonLabel, size } from '../send/push-labels';
import { dateTime } from '../receive/time';
import HeldReview from './HeldReview';
import { kindName } from './labels';

const RUNNING = [ 'snapshotting', 'applying' ];

/**
 * One push received on this site (blueprint §16 Pushes, destination): what was applied, skipped,
 * held and refused, the snapshot size, the review of held items, and "Continue apply on this
 * site" (typed domain) that writes the push from this administrator's own session (§10.5).
 *
 * TODO(Phase 8): Resume, release a stale lock, Roll back.
 *
 * @param {Object}   props
 * @param {number}   props.id     Push row ID.
 * @param {Function} props.onBack Back to the list.
 * @param {Function} props.notify Toast.
 */
export default function PushDetail( { id, onBack, notify } ) {
	const [ push, setPush ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ confirmOpen, setConfirmOpen ] = useState( false );
	const [ typed, setTyped ] = useState( '' );
	const [ running, setRunning ] = useState( false );
	const [ runError, setRunError ] = useState( '' );
	const alive = useRef( true );

	useEffect(
		() => () => {
			alive.current = false;
		},
		[]
	);

	const load = useCallback( () => {
		setError( '' );
		return getPush( id )
			.then( ( data ) => alive.current && setPush( data ) )
			.catch( ( err ) => alive.current && setError( errorMessage( err ) ) );
	}, [ id ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const run = () => {
		const confirm = typed.trim();
		setConfirmOpen( false );
		setRunning( true );
		setRunError( '' );
		const loop = () =>
			continueApply( id, confirm ).then( ( answer ) => {
				if ( ! alive.current ) {
					return answer;
				}
				if ( answer.detail ) {
					setPush( answer.detail );
				}
				return RUNNING.includes( answer.state ) ? loop() : answer;
			} );
		loop()
			.then( ( answer ) => {
				if ( alive.current && answer && 'applied' === answer.state ) {
					notify( 'success', __( 'The push is applied on this site.', 'keepmerge-push-staging-to-live' ) );
				}
			} )
			.catch( ( err ) => alive.current && setRunError( errorMessage( err ) ) )
			.finally( () => {
				if ( alive.current ) {
					setRunning( false );
					load();
				}
			} );
	};

	const back = (
		<button type="button" className="sgsp-button sgsp-button--link sgsp-detail__back" onClick={ onBack }>
			<ArrowLeft size={ 15 } aria-hidden="true" />
			{ __( 'All pushes', 'keepmerge-push-staging-to-live' ) }
		</button>
	);

	if ( error ) {
		return (
			<div>
				{ back }
				<LoadError message={ error } onRetry={ load } />
			</div>
		);
	}
	if ( ! push ) {
		return (
			<div>
				{ back }
				<SkeletonCard lines={ 6 } />
			</div>
		);
	}

	const info = pushStateInfo( push.state, push.interrupted );
	const counts = push.received;
	const undecided = push.undecided || 0;
	const reviewing = 'awaiting_review' === push.state;
	const continuing = reviewing || ( RUNNING.includes( push.state ) && push.apply && 'user' === push.apply.actor );
	const matches = typed.trim().toLowerCase() === push.host;
	const approvals = push.held_items.filter( ( item ) => 'approve' === item.review && 'held' === item.status ).length;
	const filtered = push.held_items.filter( ( item ) => 'filtered' === item.review && 'held' === item.status ).length;
	const skipped = push.held_items.filter( ( item ) => 'skip' === item.review && 'held' === item.status ).length;
	const stats = [
		{ key: 'applied', value: counts.applied, label: __( 'Applied', 'keepmerge-push-staging-to-live' ), tone: 'success' },
		{ key: 'waiting', value: counts.staged + counts.pending_media, label: __( 'Waiting to be applied', 'keepmerge-push-staging-to-live' ), tone: 'info' },
		{ key: 'held', value: counts.held, label: __( 'Held for review', 'keepmerge-push-staging-to-live' ), tone: counts.held > 0 ? 'warning' : 'neutral' },
		{ key: 'skipped', value: counts.skipped, label: __( 'Skipped', 'keepmerge-push-staging-to-live' ), tone: counts.skipped > 0 ? 'warning' : 'neutral' },
		{ key: 'failed', value: counts.failed, label: __( 'Failed', 'keepmerge-push-staging-to-live' ), tone: counts.failed > 0 ? 'danger' : 'neutral' },
		{ key: 'refused', value: counts.refused, label: __( 'Refused when staged', 'keepmerge-push-staging-to-live' ), tone: counts.refused > 0 ? 'warning' : 'neutral' },
	];

	return (
		<div className="sgsp-detail">
			{ back }
			<Card
				icon={ ListChecks }
				title={ sprintf(
					/* translators: %s: short push ID. */
					__( 'Push %s', 'keepmerge-push-staging-to-live' ),
					push.push.slice( 0, 8 )
				) }
				description={ sprintf(
					/* translators: 1: source site address, 2: date and time. */
					__( 'From %1$s, started %2$s', 'keepmerge-push-staging-to-live' ),
					push.source_url || '–',
					dateTime( push.started_at )
				) }
				aside={ <Badge tone={ info.tone }>{ info.label }</Badge> }
			>
				<ul className="sgsp-stats" aria-label={ __( 'Items of this push', 'keepmerge-push-staging-to-live' ) }>
					{ stats.map( ( stat ) => (
						<li key={ stat.key } className={ `sgsp-stat sgsp-stat--${ stat.tone }` }>
							<span className="sgsp-stat__value">{ number( stat.value ) }</span>
							<span className="sgsp-stat__label">{ stat.label }</span>
						</li>
					) ) }
				</ul>
				<p className="sgsp-detail__meta">
					<Camera size={ 15 } aria-hidden="true" />
					{ sprintf(
						/* translators: %s: size. */
						__( 'Snapshots of this push: %s (kept for rollback; content stays in the database, replaced media files in a protected folder).', 'keepmerge-push-staging-to-live' ),
						size( push.snapshot_bytes )
					) }
				</p>
				{ RUNNING.includes( push.state ) && push.apply && (
					<div className="sgsp-progress" role="status" aria-live="polite">
						<div className="sgsp-progress__label">
							{ sprintf(
								/* translators: 1: items done, 2: items of the push. */
								__( 'Applying: %1$s of %2$s items', 'keepmerge-push-staging-to-live' ),
								number( push.apply.done ),
								number( push.apply.total )
							) }
						</div>
						<div className="sgsp-progress__track" aria-hidden="true">
							<div className="sgsp-progress__bar" style={ { width: `${ push.apply.total ? Math.min( 100, Math.round( ( 100 * push.apply.done ) / push.apply.total ) ) : 0 }%` } } />
						</div>
					</div>
				) }
				{ 'failed_partial' === push.state && (
					<Alert tone="danger" title={ __( 'Stopped at an item', 'keepmerge-push-staging-to-live' ) }>
						<p className="sgsp-m-0">
							{ sprintf(
								/* translators: %s: error code. */
								__( 'An item could not be written (%s). Everything applied before it stays applied and has its snapshot.', 'keepmerge-push-staging-to-live' ),
								push.error || push.apply.error
							) }
						</p>
					</Alert>
				) }
			</Card>

			{ reviewing && (
				<Card
					icon={ ShieldAlert }
					tone="gold"
					title={ __( 'Review held items', 'keepmerge-push-staging-to-live' ) }
					description={ __( 'These items carry markup that WordPress would filter, or script-like markup in custom fields, options or widgets. Nothing of this push is written until each one is decided; then you finish the push here, from your own account.', 'keepmerge-push-staging-to-live' ) }
				>
					<HeldReview push={ push } notify={ notify } onSaved={ () => load() } />
				</Card>
			) }

			{ continuing && (
				<Card
					icon={ Play }
					title={ __( 'Continue apply on this site', 'keepmerge-push-staging-to-live' ) }
					description={ __( 'Takes a snapshot of every item, then writes the push from your account: approved items unchanged, filtered items through WordPress\'s HTML filter, skipped items not at all.', 'keepmerge-push-staging-to-live' ) }
				>
					{ undecided > 0 && (
						<p className="sgsp-field__hint sgsp-mt-0">
							{ sprintf(
								/* translators: %s: number of items. */
								_n( '%s held item still needs a decision.', '%s held items still need a decision.', undecided, 'keepmerge-push-staging-to-live' ),
								number( undecided )
							) }
						</p>
					) }
					{ runError && (
						<Alert tone="danger" title={ __( 'The apply did not continue', 'keepmerge-push-staging-to-live' ) }>
							<p className="sgsp-m-0">{ runError }</p>
						</Alert>
					) }
					<div className="sgsp-decisionbar__actions">
						<button type="button" className="sgsp-button sgsp-button--primary" disabled={ undecided > 0 || running } onClick={ () => setConfirmOpen( true ) }>
							{ running ? <LoaderCircle size={ 15 } aria-hidden="true" /> : <Play size={ 15 } aria-hidden="true" /> }
							{ running ? __( 'Applying…', 'keepmerge-push-staging-to-live' ) : __( 'Continue apply on this site', 'keepmerge-push-staging-to-live' ) }
						</button>
					</div>
				</Card>
			) }

			{ push.problems.length > 0 && (
				<Card title={ __( 'Items not applied', 'keepmerge-push-staging-to-live' ) } description={ __( 'Skipped, failed or refused items and the reason for each. Live kept its own version of these.', 'keepmerge-push-staging-to-live' ) }>
					<ul className="sgsp-detail__problems">
						{ push.problems.map( ( item ) => (
							<li key={ item.uuid }>
								<span className="sgsp-detail__problem-head">
									<strong>{ item.title || item.uuid }</strong>
									<span className="sgsp-review__kind">{ kindName( item.kind, item.type ) }</span>
								</span>
								<span>{ reasonLabel( item.reason ) }</span>
							</li>
						) ) }
					</ul>
				</Card>
			) }

			{ confirmOpen && (
				<ConfirmDialog
					title={ __( 'Continue the apply on this site?', 'keepmerge-push-staging-to-live' ) }
					confirmLabel={ __( 'Continue apply', 'keepmerge-push-staging-to-live' ) }
					confirmDisabled={ ! matches }
					onConfirm={ run }
					onCancel={ () => setConfirmOpen( false ) }
				>
					<p>
						{ sprintf(
							/* translators: 1: items waiting, 2: approved, 3: filtered, 4: skipped. */
							__( 'Writes %1$s waiting items and the decided held items (%2$s approved as is, %3$s with WordPress\'s HTML filter); %4$s skipped items stay as they are. Every item gets its snapshot first.', 'keepmerge-push-staging-to-live' ),
							number( counts.staged + counts.pending_media ),
							number( approvals ),
							number( filtered ),
							number( skipped )
						) }
					</p>
					<div className="sgsp-field">
						<label className="sgsp-field__label" htmlFor="sgsp-continue-domain">
							{ sprintf(
								/* translators: %s: this site's host. */
								__( 'Type %s to confirm', 'keepmerge-push-staging-to-live' ),
								push.host
							) }
						</label>
						<input
							id="sgsp-continue-domain"
							className="sgsp-input"
							type="text"
							autoComplete="off"
							spellCheck="false"
							value={ typed }
							onChange={ ( event ) => setTyped( event.target.value ) }
							aria-invalid={ '' !== typed && ! matches }
						/>
					</div>
				</ConfirmDialog>
			) }
		</div>
	);
}
