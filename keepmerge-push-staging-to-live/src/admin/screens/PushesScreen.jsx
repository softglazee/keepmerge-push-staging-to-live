import { createElement, Fragment, useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ChevronRight, History, Inbox, RefreshCw, Send } from 'lucide-react';
import Alert from '../components/Alert';
import Badge from '../components/Badge';
import Card from '../components/Card';
import ConfirmDialog from '../components/ConfirmDialog';
import DataTable from '../components/DataTable';
import EmptyState from '../components/EmptyState';
import LoadError from '../components/LoadError';
import PanelHead from '../components/PanelHead';
import { SkeletonCard } from '../components/Skeleton';
import { errorMessage, getPushes, releasePushLock } from '../api';
import { number } from './send/plan-labels';
import { pushStateInfo } from './send/push-labels';
import { dateTime } from './receive/time';
import PushDetail from './pushes/PushDetail';

/**
 * Host name of a URL for compact display.
 *
 * @param {string} url URL.
 * @return {string} Host.
 */
function hostOf( url ) {
	try {
		return new window.URL( url ).host;
	} catch ( e ) {
		return url || '–';
	}
}

/**
 * Files of a push: done of total, and how many get a new name on live.
 *
 * @param {number} done    Files committed or already on live.
 * @param {number} total   Files of the push.
 * @param {number} renamed Files with a new name on live.
 * @return {Element|string} Cell.
 */
function fileCell( done, total, renamed ) {
	if ( ! total ) {
		return '–';
	}
	return (
		<span className="sgsp-pushcell">
			<span>
				{ sprintf(
					/* translators: 1: files done, 2: files in the push. */
					__( '%1$s of %2$s', 'keepmerge-push-staging-to-live' ),
					number( done ),
					number( total )
				) }
			</span>
			{ renamed > 0 && (
				<span className="sgsp-pushcell__note">
					{ sprintf(
						/* translators: %s: number of files. */
						_n( '%s with a new name', '%s with new names', renamed, 'keepmerge-push-staging-to-live' ),
						number( renamed )
					) }
				</span>
			) }
		</span>
	);
}

/**
 * Pushes tab: sessions received on this site (live) and pushes sent from it (staging).
 *
 * On live each session shows its state (an interrupted one has had no heartbeat for 5
 * minutes), what was staged, applied, held for review or refused; Details opens one push with
 * the review of its held items and "Continue apply on this site" (blueprint §10.5), and recovery
 * (Resume, Retry, Skip, Roll back). A stale push lock can be released here (typed domain).
 *
 * @param {Object}   props
 * @param {Function} props.notify Toast.
 */
export default function PushesScreen( { notify } ) {
	const [ selected, setSelected ] = useState( 0 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ releasing, setReleasing ] = useState( false );
	const [ typed, setTyped ] = useState( '' );
	const [ releaseError, setReleaseError ] = useState( '' );

	const load = useCallback( () => {
		setLoading( true );
		setError( '' );
		return getPushes()
			.then( setData )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	if ( selected ) {
		return (
			<PushDetail
				id={ selected }
				notify={ notify }
				onBack={ () => {
					setSelected( 0 );
					load();
				} }
			/>
		);
	}

	const head = (
		<PanelHead
			title={ __( 'Pushes', 'keepmerge-push-staging-to-live' ) }
			description={ __(
				'Every push sent from or received on this site, with its result. On the live site, this is also where a push is reviewed and rolled back.',
				'keepmerge-push-staging-to-live'
			) }
			actions={
				<button type="button" className="sgsp-button sgsp-button--secondary" onClick={ load } disabled={ loading }>
					<RefreshCw size={ 15 } aria-hidden="true" />
					{ __( 'Refresh', 'keepmerge-push-staging-to-live' ) }
				</button>
			}
		/>
	);

	if ( error ) {
		return (
			<div>
				{ head }
				<LoadError message={ error } onRetry={ load } />
			</div>
		);
	}

	if ( ! data ) {
		return (
			<div>
				{ head }
				<SkeletonCard lines={ 5 } />
			</div>
		);
	}

	if ( 0 === data.received.length && 0 === data.sent.length ) {
		return (
			<div>
				{ head }
				<Card>
					<EmptyState
						icon={ History }
						title={ __( 'No pushes yet', 'keepmerge-push-staging-to-live' ) }
						text={ __(
							'Pair this site first: connect a staging site to its live site from the Send and Receive tabs. Each push then appears here with the items it staged, held for review or refused.',
							'keepmerge-push-staging-to-live'
						) }
					/>
				</Card>
			</div>
		);
	}

	const state = ( row ) => {
		const info = pushStateInfo( row.state, row.interrupted );
		const waiting = [ 'staged', 'awaiting_review' ].includes( row.state ) && 'stale' === row.lock;
		return (
			<span className="sgsp-pushcell">
				<Badge tone={ info.tone }>{ info.label }</Badge>
				{ waiting && (
					<span className="sgsp-pushcell__note">
						{ __( 'Holds the lock: other pushes wait until it is applied, aborted from staging, or expires.', 'keepmerge-push-staging-to-live' ) }
					</span>
				) }
			</span>
		);
	};

	const received = [
		{
			key: 'push',
			label: __( 'Push', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => (
				<span className="sgsp-pushcell">
					<span className="sgsp-pushcell__when">{ dateTime( row.started_at ) }</span>
					<code className="sgsp-pushcell__id">{ row.push.slice( 0, 8 ) }</code>
				</span>
			),
		},
		{
			key: 'from',
			label: __( 'From', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => hostOf( row.source_url ),
		},
		{ key: 'state', label: __( 'State', 'keepmerge-push-staging-to-live' ), render: state },
		{
			key: 'items',
			label: __( 'Items received', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) =>
				sprintf(
					/* translators: 1: items received, 2: items in the push. */
					__( '%1$s of %2$s', 'keepmerge-push-staging-to-live' ),
					number( row.received.total ),
					number( row.items )
				),
		},
		{
			key: 'files',
			label: __( 'Files', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) => fileCell( row.files ? row.files.committed + row.files.have : 0, row.files ? row.files.declared : 0, row.files ? row.files.renamed : 0 ),
		},
		{
			key: 'applied',
			label: __( 'Applied', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) => number( row.received.applied ),
		},
		{
			key: 'held',
			label: __( 'Need review', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) => number( row.received.held ),
		},
		{
			key: 'skipped',
			label: __( 'Skipped or refused', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) => number( row.received.skipped + row.received.refused + row.received.failed ),
		},
		{
			key: 'open',
			label: __( 'Details', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => (
				<button type="button" className="sgsp-button sgsp-button--secondary sgsp-button--small" onClick={ () => setSelected( row.id ) }>
					{ 'awaiting_review' === row.state ? __( 'Review', 'keepmerge-push-staging-to-live' ) : __( 'Open', 'keepmerge-push-staging-to-live' ) }
					<ChevronRight size={ 14 } aria-hidden="true" />
					<span className="screen-reader-text">
						{ sprintf(
							/* translators: %s: short push ID. */
							__( 'push %s', 'keepmerge-push-staging-to-live' ),
							row.push.slice( 0, 8 )
						) }
					</span>
				</button>
			),
		},
	];

	const sent = [
		{
			key: 'push',
			label: __( 'Push', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => (
				<span className="sgsp-pushcell">
					<span className="sgsp-pushcell__when">{ dateTime( row.started_at ) }</span>
					<code className="sgsp-pushcell__id">{ row.push.slice( 0, 8 ) }</code>
				</span>
			),
		},
		{ key: 'to', label: __( 'To', 'keepmerge-push-staging-to-live' ), render: ( row ) => row.host },
		{ key: 'state', label: __( 'State', 'keepmerge-push-staging-to-live' ), render: state },
		{
			key: 'sent',
			label: __( 'Items sent', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) =>
				sprintf(
					/* translators: 1: items sent, 2: items in the push. */
					__( '%1$s of %2$s', 'keepmerge-push-staging-to-live' ),
					number( row.sent ),
					number( row.total )
				),
		},
		{
			key: 'files',
			label: __( 'Files', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) => fileCell( row.files ? row.files.committed + row.files.have : 0, row.files ? row.files.total : 0, row.files ? row.files.renamed : 0 ),
		},
		{
			key: 'staged',
			label: __( 'Staged', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) => number( row.counts.staged + row.counts.pending_media ),
		},
		{
			key: 'held',
			label: __( 'Need review', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) => number( row.counts.held ),
		},
		{
			key: 'refused',
			label: __( 'Refused', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) => number( row.counts.refused ),
		},
	];

	const heldTotal = data.received.filter( ( row ) => 'awaiting_review' === row.state || 'staged' === row.state ).reduce( ( sum, row ) => sum + row.received.held, 0 );
	const stopped = data.received.filter( ( row ) => [ 'interrupted', 'failed_partial' ].includes( row.state ) || ( 'rolling_back' === row.state && row.interrupted ) ).length;
	const lock = data.lock;
	const lockMatches = typed.trim().toLowerCase() === data.host;

	const release = () => {
		const confirm = typed.trim();
		setReleasing( false );
		setReleaseError( '' );
		releasePushLock( confirm )
			.then( () => notify( 'success', __( 'The push lock is released.', 'keepmerge-push-staging-to-live' ) ) )
			.catch( ( err ) => setReleaseError( errorMessage( err ) ) )
			.finally( load );
	};

	return (
		<div>
			{ head }
			{ stopped > 0 && (
				<Alert tone="warning" title={ __( 'Pushes waiting for your decision', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ sprintf(
							/* translators: %s: number of pushes. */
							_n(
								'%s push stopped before it finished. Open it to resume it, retry or skip the failed item, or roll it back. The staging site is not needed for any of these.',
								'%s pushes stopped before they finished. Open each to resume it, retry or skip the failed item, or roll it back. The staging site is not needed for any of these.',
								stopped,
								'keepmerge-push-staging-to-live'
							),
							number( stopped )
						) }
					</p>
				</Alert>
			) }
			{ lock && lock.releasable && (
				<Alert tone="warning" title={ __( 'A push holds this site\'s push lock', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ lock.fresh
							? __( 'The push that holds it is not running any more. No other push can open until the lock is released.', 'keepmerge-push-staging-to-live' )
							: sprintf(
									/* translators: %s: date and time. */
									__( 'Its last heartbeat was %s, more than 5 minutes ago. No other push can open until the lock is released. Release it only if that push has stopped; a push waiting for review or for a decision can still be continued later.', 'keepmerge-push-staging-to-live' ),
									dateTime( lock.heartbeat )
							  ) }
					</p>
					{ releaseError && <p className="sgsp-m-0">{ releaseError }</p> }
					<div className="sgsp-decisionbar__actions">
						<button type="button" className="sgsp-button sgsp-button--secondary" onClick={ () => setReleasing( true ) }>
							{ __( 'Release lock…', 'keepmerge-push-staging-to-live' ) }
						</button>
					</div>
				</Alert>
			) }
			{ releasing && (
				<ConfirmDialog
					title={ __( 'Release the push lock?', 'keepmerge-push-staging-to-live' ) }
					confirmLabel={ __( 'Release lock', 'keepmerge-push-staging-to-live' ) }
					destructive
					confirmDisabled={ ! lockMatches }
					onConfirm={ release }
					onCancel={ () => setReleasing( false ) }
				>
					<p>
						{ __( 'A push that was still receiving items ends (nothing of it was written). A push that was being applied is marked interrupted, so you can resume or roll it back later.', 'keepmerge-push-staging-to-live' ) }
					</p>
					<div className="sgsp-field">
						<label className="sgsp-field__label" htmlFor="sgsp-release-domain">
							{ sprintf(
								/* translators: %s: this site's host. */
								__( 'Type %s to confirm', 'keepmerge-push-staging-to-live' ),
								data.host
							) }
						</label>
						<input id="sgsp-release-domain" className="sgsp-input" type="text" autoComplete="off" spellCheck="false" value={ typed } onChange={ ( event ) => setTyped( event.target.value ) } />
					</div>
				</ConfirmDialog>
			) }
			{ data.received.length > 0 && (
				<Card
					icon={ Inbox }
					title={ __( 'Received on this site', 'keepmerge-push-staging-to-live' ) }
					description={ __( 'Items arrive staged and wait in Keepmerge\'s own tables. A push is applied after a snapshot of every item it changes; a push with held items waits until an administrator of this site reviews them. A push not applied within 24 hours expires and its staged items are removed.', 'keepmerge-push-staging-to-live' ) }
				>
					{ heldTotal > 0 && (
						<p className="sgsp-field__hint sgsp-mt-0">
							{ sprintf(
								/* translators: %s: number of items. */
								_n(
									'%s item carries markup and waits for review by an administrator of this site: open the push to review it.',
									'%s items carry markup and wait for review by an administrator of this site: open the push to review them.',
									heldTotal,
									'keepmerge-push-staging-to-live'
								),
								number( heldTotal )
							) }
						</p>
					) }
					<DataTable
						caption={ __( 'Pushes received on this site', 'keepmerge-push-staging-to-live' ) }
						columns={ received }
						rows={ data.received }
						getRowKey={ ( row ) => row.push }
						isLoading={ loading }
					/>
				</Card>
			) }
			{ data.sent.length > 0 && (
				<Card
					icon={ Send }
					title={ __( 'Sent from this site', 'keepmerge-push-staging-to-live' ) }
					description={ __( 'Pushes of this site\'s plans to its live site. Follow a running push on the Send tab.', 'keepmerge-push-staging-to-live' ) }
				>
					<DataTable
						caption={ __( 'Pushes sent from this site', 'keepmerge-push-staging-to-live' ) }
						columns={ sent }
						rows={ data.sent }
						getRowKey={ ( row ) => row.push }
						isLoading={ loading }
					/>
				</Card>
			) }
			{ 0 === data.received.length && (
				<p className="sgsp-field__hint">{ __( 'This site has not received a push.', 'keepmerge-push-staging-to-live' ) }</p>
			) }
			{ 0 === data.sent.length && (
				<p className="sgsp-field__hint">{ __( 'This site has not sent a push.', 'keepmerge-push-staging-to-live' ) }</p>
			) }
		</div>
	);
}
