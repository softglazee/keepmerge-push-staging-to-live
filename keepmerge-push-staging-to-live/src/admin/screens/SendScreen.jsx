import { createElement, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { ListChecks, Plus } from 'lucide-react';
import Card from '../components/Card';
import ConfirmDialog from '../components/ConfirmDialog';
import EmptyState from '../components/EmptyState';
import LoadError from '../components/LoadError';
import PanelHead from '../components/PanelHead';
import { SkeletonCard } from '../components/Skeleton';
import { checkPairing, confirmConnect, errorMessage, getSend, previewConnect, revokePairing } from '../api';
import ConnectCard from './send/ConnectCard';
import ConnectionCard from './send/ConnectionCard';
import { useTick } from './receive/time';

const CHECKABLE = [ 'pending', 'awaiting_approval', 'active' ];

/**
 * Send tab (the staging side): the connection to live, then plan, diff and push.
 *
 * The connection card pastes a key, previews the live site, connects and follows the approval
 * on live. Status calls go through this site's admin routes; the long-term credentials never
 * reach the browser.
 *
 * TODO(Phase 3): baseline. TODO(Phase 4): plan, diff and conflict decisions.
 * TODO(Phase 5): the push dialog with counts and the typed live domain.
 *
 * @param {Object}   props
 * @param {Function} props.notify    Toast.
 * @param {Function} props.onChanged Reloads the header status.
 */
export default function SendScreen( { notify, onChanged } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ offset, setOffset ] = useState( 0 );
	const [ busy, setBusy ] = useState( '' );
	const [ key, setKey ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const [ connectError, setConnectError ] = useState( '' );
	const [ showForm, setShowForm ] = useState( false );
	const [ confirm, setConfirm ] = useState( null );
	const checkedOnLoad = useRef( false );
	const latest = useRef( null );
	latest.current = data;

	const apply = useCallback( ( next ) => {
		setData( next );
		setOffset( next.now - Date.now() / 1000 );
		setError( '' );
		return next;
	}, [] );

	const load = useCallback(
		() =>
			getSend()
				.then( apply )
				.catch( ( err ) => {
					setError( errorMessage( err ) );
					return null;
				} ),
		[ apply ]
	);

	const replace = useCallback( ( pairing ) => {
		setData( ( current ) =>
			current
				? {
						...current,
						pairings: current.pairings.map( ( item ) => ( item.uuid === pairing.uuid ? pairing : item ) ),
				  }
				: current
		);
	}, [] );

	// One status call per usable pairing, one after the other.
	const checkAll = useCallback(
		( pairings, onlyWaiting ) =>
			pairings
				.filter( ( pairing ) =>
					onlyWaiting
						? 'awaiting_approval' === pairing.status || 'pending' === pairing.status
						: CHECKABLE.includes( pairing.status )
				)
				.reduce(
					( chain, pairing ) =>
						chain.then( () =>
							checkPairing( pairing.uuid )
								.then( ( next ) => {
									replace( next );
									if ( 'active' === next.status && 'active' !== pairing.status ) {
										notify(
											'success',
											sprintf(
												/* translators: %s: live site host. */
												__( '%s approved this site. It is connected.', 'keepmerge-push-staging-to-live' ),
												next.live.host
											)
										);
										onChanged();
									}
								} )
								.catch( () => null )
						),
					Promise.resolve()
				),
		[ notify, onChanged, replace ]
	);

	useEffect( () => {
		load().then( ( next ) => {
			if ( next && ! checkedOnLoad.current ) {
				checkedOnLoad.current = true;
				checkAll( next.pairings, false );
			}
		} );
	}, [ load, checkAll ] );

	const waiting =
		!! data &&
		data.pairings.some( ( pairing ) => 'awaiting_approval' === pairing.status || 'pending' === pairing.status );

	// While live has not approved yet, ask again every 10 seconds.
	useEffect( () => {
		if ( ! waiting ) {
			return undefined;
		}
		const id = window.setInterval( () => {
			if ( latest.current ) {
				checkAll( latest.current.pairings, true );
			}
		}, 10000 );
		return () => window.clearInterval( id );
	}, [ waiting, checkAll ] );

	useTick( !! preview || waiting || ( !! data && data.pairings.some( ( p ) => p.window_closes_at ) ) );

	const now = Date.now() / 1000 + offset;

	const runPreview = () => {
		setBusy( 'preview' );
		setConnectError( '' );
		previewConnect( key.trim() )
			.then( ( result ) => setPreview( result ) )
			.catch( ( err ) => setConnectError( errorMessage( err ) ) )
			.finally( () => setBusy( '' ) );
	};

	const runConnect = () => {
		setBusy( 'connect' );
		setConnectError( '' );
		confirmConnect( key.trim() )
			.then( ( pairing ) => {
				setKey( '' );
				setPreview( null );
				setShowForm( false );
				notify(
					'success',
					sprintf(
						/* translators: %s: live site host. */
						__( 'Connected to %s. Approve this site on the live site to finish.', 'keepmerge-push-staging-to-live' ),
						pairing.live.host
					)
				);
				return load().then( onChanged );
			} )
			.catch( ( err ) => {
				setConnectError( errorMessage( err ) );
				// A connect whose last step failed leaves a pairing to finish: show it.
				load();
			} )
			.finally( () => setBusy( '' ) );
	};

	const cancelPreview = () => {
		setPreview( null );
		setKey( '' );
		setConnectError( '' );
	};

	const runCheck = ( pairing ) => {
		setBusy( pairing.uuid );
		checkPairing( pairing.uuid )
			.then( ( next ) => {
				replace( next );
				if ( next.last_check && next.last_check.ok ) {
					notify( 'success', __( 'The live site answered.', 'keepmerge-push-staging-to-live' ) );
				}
				if ( next.status !== pairing.status ) {
					onChanged();
				}
			} )
			.catch( ( err ) => notify( 'error', errorMessage( err ) ) )
			.finally( () => setBusy( '' ) );
	};

	const runRevoke = () => {
		const pairing = confirm;
		setConfirm( null );
		setBusy( pairing.uuid );
		revokePairing( pairing.uuid )
			.then( ( result ) => {
				notify( 'success', result.next_step || __( 'Pairing revoked on this site.', 'keepmerge-push-staging-to-live' ) );
				return load().then( onChanged );
			} )
			.catch( ( err ) => notify( 'error', errorMessage( err ) ) )
			.finally( () => setBusy( '' ) );
	};

	const head = (
		<PanelHead
			title={ __( 'Send', 'keepmerge-push-staging-to-live' ) }
			description={ __(
				'Push from this site to its live site: connect once, review a dry-run plan of every difference, decide conflicts, then push. Nothing is written to live before you confirm.',
				'keepmerge-push-staging-to-live'
			) }
		/>
	);

	if ( error && ! data ) {
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
				<div className="sgsp-stack">
					<SkeletonCard lines={ 4 } />
					<SkeletonCard lines={ 2 } />
				</div>
			</div>
		);
	}

	const pairings = data.pairings;
	const formOpen = 0 === pairings.length || showForm || !! preview;

	return (
		<div>
			{ head }
			<div className="sgsp-stack">
				{ pairings.map( ( pairing ) => (
					<ConnectionCard
						key={ pairing.uuid }
						pairing={ pairing }
						site={ data.site }
						now={ now }
						busy={ busy === pairing.uuid }
						onCheck={ () => runCheck( pairing ) }
						onRevoke={ () => setConfirm( pairing ) }
					/>
				) ) }

				{ formOpen ? (
					<ConnectCard
						canConnect={ data.can_connect }
						blocker={ data.blocker }
						first={ 0 === pairings.length }
						value={ key }
						onChange={ setKey }
						preview={ preview }
						error={ connectError }
						busy={ 'preview' === busy || 'connect' === busy ? busy : '' }
						now={ now }
						onPreview={ runPreview }
						onConnect={ runConnect }
						onCancel={ () => {
							cancelPreview();
							setShowForm( false );
						} }
					/>
				) : (
					<div>
						<button
							type="button"
							className="sgsp-button sgsp-button--secondary"
							onClick={ () => setShowForm( true ) }
						>
							<Plus size={ 15 } aria-hidden="true" />
							{ __( 'Connect another live site', 'keepmerge-push-staging-to-live' ) }
						</button>
					</div>
				) }

				<Card icon={ ListChecks } title={ __( 'Plan and push', 'keepmerge-push-staging-to-live' ) }>
					<EmptyState
						title={ __( 'Nothing to compare yet', 'keepmerge-push-staging-to-live' ) }
						text={ __(
							'After connecting, Keepmerge matches the items on both sites and shows what a push would create, update or keep, with conflicts for you to decide. Orders, customers and code are never part of a push.',
							'keepmerge-push-staging-to-live'
						) }
					/>
				</Card>
			</div>

			{ confirm && (
				<ConfirmDialog
					title={ __( 'Revoke this pairing on this site?', 'keepmerge-push-staging-to-live' ) }
					confirmLabel={ __( 'Revoke on this site', 'keepmerge-push-staging-to-live' ) }
					destructive
					onConfirm={ runRevoke }
					onCancel={ () => setConfirm( null ) }
				>
					<p>
						{ 'repair_needed' === confirm.status
							? sprintf(
									/* translators: 1: live site address, 2: pairing UUID. */
									__(
										'Live site: %1$s. The credentials of pairing %2$s were already deleted on this site; revoking removes the pairing from this list.',
										'keepmerge-push-staging-to-live'
									),
									confirm.live.home_url || confirm.live.host,
									confirm.uuid
							  )
							: sprintf(
									/* translators: 1: live site address, 2: pairing UUID. */
									__(
										'Live site: %1$s. This deletes the 2 credentials this site keeps for pairing %2$s (the receiver\'s Application Password and the signing secret). This site can then no longer push there.',
										'keepmerge-push-staging-to-live'
									),
									confirm.live.home_url || confirm.live.host,
									confirm.uuid
							  ) }
					</p>
					<p>
						{ 'repair_needed' === confirm.status
							? __(
									'If the live site still lists this pairing under Tools → Staging Push → Receive, revoke it there too.',
									'keepmerge-push-staging-to-live'
							  )
							: __(
									'There is no remote revoke: the live site keeps its receiver account for this site until an administrator revokes the pairing there, under Tools → Staging Push → Receive.',
									'keepmerge-push-staging-to-live'
							  ) }
					</p>
				</ConfirmDialog>
			) }
		</div>
	);
}
