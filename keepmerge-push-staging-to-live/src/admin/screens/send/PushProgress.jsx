import { createElement, Fragment, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { CircleStop, ExternalLink, LoaderCircle, Play, RefreshCw } from 'lucide-react';
import Alert from '../../components/Alert';
import Badge from '../../components/Badge';
import ConfirmDialog from '../../components/ConfirmDialog';
import { abortPush, errorMessage, stepPush } from '../../api';
import { number } from './plan-labels';
import { clock, logLine, pushStateInfo, reasonLabel, size } from './push-labels';

const ACTIVE = [ 'sending_files', 'sending_items', 'paused', 'staged', 'applying', 'awaiting_review' ];
const SENDING = [ 'sending_files', 'sending_items', 'staged', 'applying' ];

/**
 * A push on its way to live: progress, what live answered per batch, the apply on live and the
 * per-step log (blueprint §16 Send tab). Each step is one request (one batch, or one time-boxed
 * apply call); the browser runs the steps one after the other. When the page is closed the push
 * simply stops: opening the Send tab again shows it here with Resume, and batches live already has
 * are never stored twice. A push with items held for review stops calling apply: "Check status"
 * asks live whether its administrator finished it.
 *
 * @param {Object}   props
 * @param {Object}   props.initial     The push.
 * @param {boolean}  props.autoRun     Start stepping at once (just started from the dialog).
 * @param {Function} props.notify      Toast.
 * @param {Function} props.onChange    Receives every new state of the push.
 * @param {Function} props.onPlanAgain Opens a new plan after a finished push.
 */
export default function PushProgress( { initial, autoRun, notify, onChange, onPlanAgain } ) {
	const [ push, setPush ] = useState( initial );
	const [ running, setRunning ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ abortOpen, setAbortOpen ] = useState( false );
	const [ aborting, setAborting ] = useState( false );
	const alive = useRef( true );
	const started = useRef( false );

	useEffect(
		() => () => {
			alive.current = false;
		},
		[]
	);

	const update = useCallback(
		( next ) => {
			if ( alive.current ) {
				setPush( next );
				onChange( next );
			}
			return next;
		},
		[ onChange ]
	);

	const run = useCallback(
		( current ) => {
			setRunning( true );
			setError( '' );
			const loop = ( p ) => {
				if ( ! alive.current || ! SENDING.includes( p.state ) ) {
					return Promise.resolve( p );
				}
				const go = () => stepPush( p.id, p.steps ).then( update ).then( loop );
				if ( p.wait > 0 ) {
					return new Promise( ( resolve ) => window.setTimeout( resolve, p.wait * 1000 ) ).then( go );
				}
				return go();
			};
			return stepPush( current.id, current.steps )
				.then( update )
				.then( loop )
				.then( ( done ) => {
					if ( ! alive.current ) {
						return;
					}
					if ( 'done' === done.state ) {
						notify( 'success', __( 'The push is applied on live.', 'keepmerge-push-staging-to-live' ) );
					} else if ( 'awaiting_review' === done.state ) {
						notify( 'info', __( 'Live holds items for review. A live administrator finishes the push there.', 'keepmerge-push-staging-to-live' ) );
					} else if ( done.error ) {
						notify( 'error', done.error.message );
					}
				} )
				.catch( ( err ) => alive.current && setError( errorMessage( err ) ) )
				.finally( () => alive.current && setRunning( false ) );
		},
		[ notify, update ]
	);

	useEffect( () => {
		if ( autoRun && ! started.current && ACTIVE.includes( initial.state ) ) {
			started.current = true;
			run( initial );
		}
	}, [ autoRun, initial, run ] );

	const abort = () => {
		setAbortOpen( false );
		setAborting( true );
		abortPush( push.id )
			.then( ( next ) => {
				update( next );
				notify( 'success', __( 'Push aborted. Live removed what it had staged for it.', 'keepmerge-push-staging-to-live' ) );
			} )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => alive.current && setAborting( false ) );
	};

	const info = pushStateInfo( push.state );
	const percent = push.total ? Math.min( 100, Math.round( ( 100 * push.sent ) / push.total ) ) : 0;
	const active = ACTIVE.includes( push.state );
	const sending = SENDING.includes( push.state );
	const stopped = active && ! running && ! push.busy;
	const held = push.counts.held || 0;
	const reviewing = 'awaiting_review' === push.state;
	const applyInfo = push.apply || null;
	const applied = push.applied || {};
	const applyPercent = applyInfo && applyInfo.total ? Math.min( 100, Math.round( ( 100 * applyInfo.done ) / applyInfo.total ) ) : 0;
	const heldNow = applyInfo ? applyInfo.held : held;
	const files = push.files || { total: 0, bytes: 0, sent_bytes: 0 };
	const settled = ( files.have || 0 ) + ( files.committed || 0 ) + ( files.refused || 0 );
	const filePercent = files.bytes ? Math.min( 100, Math.round( ( 100 * files.sent_bytes ) / files.bytes ) ) : 0;
	const fileStats = [
		{ key: 'have', value: files.have || 0, label: __( 'Already on live (not sent)', 'keepmerge-push-staging-to-live' ), tone: 'neutral' },
		{ key: 'committed', value: files.committed || 0, label: __( 'Sent and checked by live', 'keepmerge-push-staging-to-live' ), tone: 'success' },
		{ key: 'renamed', value: files.renamed || 0, label: __( 'Get a new name on live', 'keepmerge-push-staging-to-live' ), tone: files.renamed > 0 ? 'info' : 'neutral' },
		{ key: 'refused', value: files.refused || 0, label: __( 'Refused by live', 'keepmerge-push-staging-to-live' ), tone: files.refused > 0 ? 'warning' : 'neutral' },
	];
	const stats = [
		{ key: 'staged', value: ( push.counts.staged || 0 ) + ( push.counts.pending_media || 0 ), label: __( 'Staged on live', 'keepmerge-push-staging-to-live' ), tone: 'success' },
		{ key: 'held', value: held, label: __( 'Will need review on live', 'keepmerge-push-staging-to-live' ), tone: held > 0 ? 'warning' : 'neutral' },
		{ key: 'refused', value: push.counts.refused || 0, label: __( 'Refused by live', 'keepmerge-push-staging-to-live' ), tone: push.counts.refused > 0 ? 'warning' : 'neutral' },
		{ key: 'left', value: Math.max( 0, push.total - push.sent ), label: __( 'Still to send', 'keepmerge-push-staging-to-live' ), tone: 'info' },
	];
	const log = push.log.slice( -15 );

	return (
		<div className="sgsp-push">
			<div className="sgsp-push__head">
				{ running && (
					<Badge tone="info" icon={ LoaderCircle }>
						{ [ 'staged', 'applying' ].includes( push.state ) ? __( 'Applying on live', 'keepmerge-push-staging-to-live' ) : __( 'Sending', 'keepmerge-push-staging-to-live' ) }
					</Badge>
				) }
				{ ! running && sending && stopped && <Badge tone="warning">{ __( 'Not running', 'keepmerge-push-staging-to-live' ) }</Badge> }
				{ ! running && ! ( sending && stopped ) && <Badge tone={ info.tone }>{ info.label }</Badge> }
				<span className="sgsp-push__to">
					{ sprintf(
						/* translators: %s: live site host. */
						__( 'Push to %s', 'keepmerge-push-staging-to-live' ),
						push.host
					) }
				</span>
			</div>

			{ files.total > 0 && (
				<div className="sgsp-push__files">
					<div className="sgsp-progress" role="status" aria-live="polite">
						<div className="sgsp-progress__label">
							{ sprintf(
								/* translators: 1: files done, 2: files in the push, 3: size sent, 4: size of the files. */
								__( 'Files: %1$s of %2$s done (%3$s of %4$s)', 'keepmerge-push-staging-to-live' ),
								number( settled ),
								number( files.total ),
								size( files.sent_bytes ),
								size( files.bytes )
							) }
						</div>
						<div className="sgsp-progress__track" aria-hidden="true">
							<div className="sgsp-progress__bar sgsp-progress__bar--files" style={ { width: `${ filePercent }%` } } />
						</div>
						{ 'files' === push.phase && files.current && (
							<p className="sgsp-progress__note">
								{ sprintf(
									/* translators: 1: file name, 2: part sent, 3: parts of the file. */
									__( 'Sending %1$s: part %2$s of %3$s', 'keepmerge-push-staging-to-live' ),
									files.current.name,
									number( Math.min( files.current.chunks, files.current.next + 1 ) ),
									number( files.current.chunks )
								) }
							</p>
						) }
					</div>
					<ul className="sgsp-stats sgsp-stats--compact" aria-label={ __( 'Files of this push', 'keepmerge-push-staging-to-live' ) }>
						{ fileStats.map( ( stat ) => (
							<li key={ stat.key } className={ `sgsp-stat sgsp-stat--${ stat.tone }` }>
								<span className="sgsp-stat__value">{ number( stat.value ) }</span>
								<span className="sgsp-stat__label">{ stat.label }</span>
							</li>
						) ) }
					</ul>
					{ 'files' === push.phase && active && (
						<p className="sgsp-field__hint sgsp-m-0">
							{ __( 'Files go first: live checks each image and document against what it received before it stages the items that use them. Items follow when every file is done.', 'keepmerge-push-staging-to-live' ) }
						</p>
					) }
				</div>
			) }

			<div className="sgsp-progress" role="status" aria-live="polite">
				<div className="sgsp-progress__label">
					{ sprintf(
						/* translators: 1: items sent, 2: items in the push. */
						__( 'Items sent: %1$s of %2$s', 'keepmerge-push-staging-to-live' ),
						number( push.sent ),
						number( push.total )
					) }
				</div>
				<div className="sgsp-progress__track" aria-hidden="true">
					<div className="sgsp-progress__bar" style={ { width: `${ percent }%` } } />
				</div>
			</div>

			{ applyInfo && applyInfo.total > 0 && (
				<div className="sgsp-progress" role="status" aria-live="polite">
					<div className="sgsp-progress__label">
						{ sprintf(
							/* translators: 1: items done, 2: items of the push. */
							__( 'Applied on live: %1$s of %2$s', 'keepmerge-push-staging-to-live' ),
							number( 'done' === push.state ? applyInfo.total : applyInfo.done ),
							number( applyInfo.total )
						) }
					</div>
					<div className="sgsp-progress__track" aria-hidden="true">
						<div className="sgsp-progress__bar sgsp-progress__bar--apply" style={ { width: `${ 'done' === push.state ? 100 : applyPercent }%` } } />
					</div>
				</div>
			) }

			<ul className="sgsp-stats" aria-label={ __( 'What live answered', 'keepmerge-push-staging-to-live' ) }>
				{ stats.map( ( stat ) => (
					<li key={ stat.key } className={ `sgsp-stat sgsp-stat--${ stat.tone }` }>
						<span className="sgsp-stat__value">{ number( stat.value ) }</span>
						<span className="sgsp-stat__label">{ stat.label }</span>
					</li>
				) ) }
			</ul>

			{ error && (
				<Alert tone="danger" title={ __( 'The last step did not complete', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ error }</p>
				</Alert>
			) }

			{ 'paused' === push.state && push.error && (
				<Alert tone="warning" title={ __( 'Paused', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ push.error.message }</p>
				</Alert>
			) }

			{ sending && stopped && ! error && (
				<Alert tone="info" title={ __( 'Not running', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ __( 'This push stopped when its page was closed. Resume sends the rest: a file continues at the next part live is missing, and a batch live already has is not stored twice.', 'keepmerge-push-staging-to-live' ) }
					</p>
				</Alert>
			) }

			{ reviewing && (
				<Alert tone="warning" title={ __( 'Waiting for review on live', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ sprintf(
							/* translators: %s: number of items. */
							_n(
								'%s item is waiting for review on live. Nothing of this push was written there yet: an administrator of the live site decides the held items on its Pushes tab and continues the apply there. This site stopped calling apply; Check status asks live where the push is.',
								'%s items are waiting for review on live. Nothing of this push was written there yet: an administrator of the live site decides the held items on its Pushes tab and continues the apply there. This site stopped calling apply; Check status asks live where the push is.',
								heldNow,
								'keepmerge-push-staging-to-live'
							),
							number( heldNow )
						) }
					</p>
				</Alert>
			) }

			{ 'done' === push.state && (
				<Alert tone="success" title={ __( 'Applied on live', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ sprintf(
							/* translators: 1: items written, 2: items skipped, 3: items refused. */
							__( '%1$s items written on live, %2$s skipped, %3$s refused when staged. Live keeps a snapshot of everything it changed.', 'keepmerge-push-staging-to-live' ),
							number( applied.applied || 0 ),
							number( applied.skipped || 0 ),
							number( push.counts.refused || 0 )
						) }
					</p>
				</Alert>
			) }

			{ push.problems && push.problems.length > 0 && (
				<details className="sgsp-push__list">
					<summary>
						{ sprintf(
							/* translators: %s: number of items. */
							_n( '%s item not applied on live', '%s items not applied on live', push.problems.length, 'keepmerge-push-staging-to-live' ),
							number( push.problems.length )
						) }
					</summary>
					<ul>
						{ push.problems.map( ( item ) => (
							<li key={ item.uuid }>
								<strong>{ item.title || item.uuid }</strong>
								<span>{ reasonLabel( item.reason ) }</span>
							</li>
						) ) }
					</ul>
				</details>
			) }

			{ 'staged' === push.state && (
				<Alert tone="info" title={ __( 'Staged on live', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ sprintf(
							/* translators: %s: number of items. */
							_n(
								'Live keeps %s item staged. Nothing on live changes until it is applied.',
								'Live keeps %s items staged. Nothing on live changes until they are applied.',
								push.total,
								'keepmerge-push-staging-to-live'
							),
							number( push.total )
						) }
						{ held > 0 &&
							' ' +
								sprintf(
									/* translators: %s: number of items. */
									_n(
										'%s item will need review by a live administrator first.',
										'%s items will need review by a live administrator first.',
										held,
										'keepmerge-push-staging-to-live'
									),
									number( held )
								) }
					</p>
				</Alert>
			) }

			{ 'failed' === push.state && push.error && (
				<Alert tone="danger" title={ __( 'Stopped', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ push.error.message }</p>
				</Alert>
			) }

			{ 'aborted' === push.state && (
				<Alert tone="info" title={ __( 'Aborted', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ __( 'Live removed what it had staged for this push. Nothing on live was changed.', 'keepmerge-push-staging-to-live' ) }</p>
				</Alert>
			) }

			{ push.refused.length > 0 && (
				<details className="sgsp-push__list">
					<summary>
						{ sprintf(
							/* translators: %s: number of items. */
							_n( '%s item refused by live', '%s items refused by live', push.counts.refused || push.refused.length, 'keepmerge-push-staging-to-live' ),
							number( push.counts.refused || push.refused.length )
						) }
					</summary>
					<ul>
						{ push.refused.map( ( item ) => (
							<li key={ item.uuid }>
								<strong>{ item.title || item.uuid }</strong>
								<span>{ reasonLabel( item.reason, item.field ) }</span>
							</li>
						) ) }
					</ul>
				</details>
			) }

			<div className="sgsp-decisionbar__actions sgsp-push__actions">
				{ active && (
					<button type="button" className="sgsp-button sgsp-button--primary" disabled={ running || aborting || push.busy } onClick={ () => run( push ) }>
						{ running ? <LoaderCircle size={ 15 } aria-hidden="true" /> : <Play size={ 15 } aria-hidden="true" /> }
						{ running && __( 'Working…', 'keepmerge-push-staging-to-live' ) }
						{ ! running && reviewing && __( 'Check status', 'keepmerge-push-staging-to-live' ) }
						{ ! running && ! reviewing && ( 'staged' === push.state ? __( 'Apply on live', 'keepmerge-push-staging-to-live' ) : __( 'Resume', 'keepmerge-push-staging-to-live' ) ) }
					</button>
				) }
				{ active && 'applying' !== push.state && ! ( 'paused' === push.state && applyInfo ) && (
					<button type="button" className="sgsp-button sgsp-button--secondary" disabled={ running || aborting } onClick={ () => setAbortOpen( true ) }>
						<CircleStop size={ 15 } aria-hidden="true" />
						{ __( 'Abort push', 'keepmerge-push-staging-to-live' ) }
					</button>
				) }
				{ ! active && (
					<button type="button" className="sgsp-button sgsp-button--secondary" onClick={ onPlanAgain }>
						<RefreshCw size={ 15 } aria-hidden="true" />
						{ __( 'Plan again', 'keepmerge-push-staging-to-live' ) }
					</button>
				) }
				{ push.live_pushes && 'aborted' !== push.state && (
					<a className="sgsp-button sgsp-button--link" href={ push.live_pushes } target="_blank" rel="noopener noreferrer">
						{ reviewing ? __( 'Review on live\'s Pushes tab', 'keepmerge-push-staging-to-live' ) : __( 'Live\'s Pushes tab', 'keepmerge-push-staging-to-live' ) }
						<ExternalLink size={ 14 } aria-hidden="true" />
						<span className="screen-reader-text">{ __( '(opens in a new tab)', 'keepmerge-push-staging-to-live' ) }</span>
					</a>
				) }
			</div>

			{ log.length > 0 && (
				<div className="sgsp-push__log">
					<h4 className="sgsp-push__logtitle">{ __( 'Step log', 'keepmerge-push-staging-to-live' ) }</h4>
					<ol>
						{ log.map( ( entry, index ) => (
							<li key={ `${ entry.t }-${ index }` } className={ `sgsp-push__logitem is-${ entry.event }` }>
								<time>{ clock( entry.t ) }</time>
								<span>{ logLine( entry ) }</span>
							</li>
						) ) }
					</ol>
				</div>
			) }

			{ abortOpen && (
				<ConfirmDialog
					title={ __( 'Abort this push?', 'keepmerge-push-staging-to-live' ) }
					confirmLabel={ __( 'Abort push', 'keepmerge-push-staging-to-live' ) }
					destructive
					onConfirm={ abort }
					onCancel={ () => setAbortOpen( false ) }
				>
					<p>
						{ sprintf(
							/* translators: %s: number of items. */
							_n(
								'Live removes the %s item it staged for this push and releases its lock. Nothing on live has been changed by this push.',
								'Live removes the %s items it staged for this push and releases its lock. Nothing on live has been changed by this push.',
								push.sent,
								'keepmerge-push-staging-to-live'
							),
							number( push.sent )
						) }
					</p>
				</ConfirmDialog>
			) }
		</div>
	);
}
