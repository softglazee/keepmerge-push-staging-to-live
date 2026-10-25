import { createElement, Fragment, useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { History, Inbox, RefreshCw, Send } from 'lucide-react';
import Badge from '../components/Badge';
import Card from '../components/Card';
import DataTable from '../components/DataTable';
import EmptyState from '../components/EmptyState';
import LoadError from '../components/LoadError';
import PanelHead from '../components/PanelHead';
import { SkeletonCard } from '../components/Skeleton';
import { errorMessage, getPushes } from '../api';
import { number } from './send/plan-labels';
import { pushStateInfo } from './send/push-labels';
import { dateTime } from './receive/time';

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
 * Pushes tab: sessions received on this site (live) and pushes sent from it (staging).
 *
 * On live each session shows its state (an interrupted one has had no heartbeat for 5
 * minutes), what was staged, what will need review and what was refused. Nothing here applies
 * anything yet.
 *
 * TODO(Phase 7): push detail, review of held items, "Continue apply on this site".
 * TODO(Phase 8): resume, release a stale lock, roll back.
 *
 * @param {Object} props
 * @param {Object} props.overview Overview data (unused; the tab loads its own).
 */
export default function PushesScreen() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ loading, setLoading ] = useState( false );

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
		const waiting = 'staged' === row.state && 'stale' === row.lock;
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
			key: 'staged',
			label: __( 'Staged', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) => number( row.received.staged + row.received.pending_media ),
		},
		{
			key: 'held',
			label: __( 'Need review', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) => number( row.received.held ),
		},
		{
			key: 'refused',
			label: __( 'Refused', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-num',
			render: ( row ) => number( row.received.refused ),
		},
		{
			key: 'expires',
			label: __( 'Expires', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => ( [ 'open', 'receiving', 'staged' ].includes( row.state ) ? dateTime( row.expires_at ) : '–' ),
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

	const heldTotal = data.received.filter( ( row ) => 'staged' === row.state ).reduce( ( sum, row ) => sum + row.received.held, 0 );

	return (
		<div>
			{ head }
			{ data.received.length > 0 && (
				<Card
					icon={ Inbox }
					title={ __( 'Received on this site', 'keepmerge-push-staging-to-live' ) }
					description={ __( 'Items arrive staged: they wait in Keepmerge\'s own tables and nothing on this site changes until they are applied. A push that is not applied within 24 hours expires and its staged items are removed.', 'keepmerge-push-staging-to-live' ) }
				>
					{ heldTotal > 0 && (
						<p className="sgsp-field__hint sgsp-mt-0">
							{ sprintf(
								/* translators: %s: number of items. */
								_n(
									'%s staged item carries markup and will need review by an administrator of this site before it is applied.',
									'%s staged items carry markup and will need review by an administrator of this site before they are applied.',
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
