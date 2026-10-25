import { createElement, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Alert from '../components/Alert';
import ConfirmDialog from '../components/ConfirmDialog';
import LoadError from '../components/LoadError';
import PanelHead from '../components/PanelHead';
import { SkeletonCard } from '../components/Skeleton';
import {
	approvePairing,
	createPairingKey,
	errorMessage,
	getReceive,
	rejectPairing,
	revokePairing,
	setReceive,
	updatePairing,
} from '../api';
import ApprovalCard from './receive/ApprovalCard';
import KeyCard from './receive/KeyCard';
import PairingsCard from './receive/PairingsCard';
import WindowCard from './receive/WindowCard';
import { useTick } from './receive/time';

/**
 * Receive tab (the live side): the receive window, pairing keys, approvals and pairings.
 *
 * Everything here goes through the admin routes (manage_options plus the wp_rest nonce). No
 * credential is ever loaded into this screen except the pairing key in the response that
 * created it, which stays in memory until hidden.
 *
 * @param {Object}   props
 * @param {Function} props.notify    Toast.
 * @param {Function} props.onChanged Reloads the header status.
 */
export default function ReceiveScreen( { notify, onChanged } ) {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ offset, setOffset ] = useState( 0 );
	const [ busy, setBusy ] = useState( '' );
	const [ issued, setIssued ] = useState( null );
	const [ confirm, setConfirm ] = useState( null );
	const closingAt = useRef( 0 );

	const apply = useCallback( ( next ) => {
		setData( next );
		setOffset( next.now - Date.now() / 1000 );
		setError( '' );
	}, [] );

	const load = useCallback(
		() =>
			getReceive()
				.then( apply )
				.catch( ( err ) => setError( errorMessage( err ) ) ),
		[ apply ]
	);

	useEffect( () => {
		load();
	}, [ load ] );

	const waiting =
		!! issued ||
		( !! data &&
			data.pairings.some(
				( pairing ) => 'pending' === pairing.status || 'awaiting_approval' === pairing.status
			) );

	// While a key is out or a staging site waits for approval, look for news every 10 seconds.
	useEffect( () => {
		if ( ! waiting ) {
			return undefined;
		}
		const id = window.setInterval( load, 10000 );
		return () => window.clearInterval( id );
	}, [ waiting, load ] );

	useTick( !! data && ( data.window.open || waiting ) );

	// The key is useless once its pairing moved on (finalized, expired, revoked): stop showing it.
	useEffect( () => {
		if ( issued && data && ! data.pairings.some( ( pairing ) => pairing.uuid === issued.uuid && 'pending' === pairing.status ) ) {
			setIssued( null );
		}
	}, [ data, issued ] );

	const now = Date.now() / 1000 + offset;

	// When the window's time is up, ask the server once so the screen shows what it decided.
	useEffect( () => {
		if ( data && data.window.open && data.window.closes_at <= now && closingAt.current !== data.window.closes_at ) {
			closingAt.current = data.window.closes_at;
			load().then( onChanged );
		}
	} );

	function run( key, request, success ) {
		setBusy( key );
		return request()
			.then( ( result ) => {
				if ( success ) {
					notify( 'success', success );
				}
				return result;
			} )
			.catch( ( err ) => {
				notify( 'error', errorMessage( err ) );
				return null;
			} )
			.finally( () => setBusy( '' ) );
	}

	const openWindow = ( hours ) =>
		run(
			'window',
			() => setReceive( { action: 'open', hours } ),
			__( 'Receive window opened.', 'keepmerge-push-staging-to-live' )
		).then( ( next ) => {
			if ( next ) {
				apply( next );
				onChanged();
			}
		} );

	const closeWindow = () =>
		run(
			'window',
			() => setReceive( { action: 'close' } ),
			__( 'Receive window closed.', 'keepmerge-push-staging-to-live' )
		).then( ( next ) => {
			if ( next ) {
				apply( next );
				onChanged();
			}
		} );

	const createKey = () =>
		run( 'key', createPairingKey ).then( ( result ) => {
			if ( ! result || ! result.key ) {
				return null;
			}
			// Show the key together with the list that already holds its pending pairing.
			return getReceive()
				.then( apply )
				.catch( () => null )
				.then( () =>
					setIssued( {
						key: result.key,
						uuid: result.pairing.uuid,
						expires: result.pairing.expires_at,
					} )
				);
		} );

	const approve = ( pairing ) =>
		run(
			pairing.uuid,
			() => approvePairing( pairing.uuid ),
			sprintf(
				/* translators: %s: staging site host. */
				__( '%s can now push to this site while the receive window is open.', 'keepmerge-push-staging-to-live' ),
				pairing.source_host || pairing.source_url
			)
		).then( ( result ) => {
			if ( result ) {
				if ( issued && issued.uuid === pairing.uuid ) {
					setIssued( null );
				}
				load();
				onChanged();
			}
		} );

	const update = ( uuid, values ) =>
		run( uuid, () => updatePairing( uuid, values ), __( 'Pairing updated.', 'keepmerge-push-staging-to-live' ) ).then(
			( result ) => {
				if ( result ) {
					load();
				}
			}
		);

	const confirmAction = () => {
		const { type, pairing } = confirm;
		setConfirm( null );
		const request = 'reject' === type ? () => rejectPairing( pairing.uuid ) : () => revokePairing( pairing.uuid );
		const message =
			'reject' === type
				? __( 'Pairing rejected. Its receiver account and credentials were deleted.', 'keepmerge-push-staging-to-live' )
				: __( 'Pairing revoked. Its receiver account and credentials were deleted.', 'keepmerge-push-staging-to-live' );
		run( pairing.uuid, request, message ).then( ( result ) => {
			if ( result ) {
				if ( issued && issued.uuid === pairing.uuid ) {
					setIssued( null );
				}
				load();
				onChanged();
			}
		} );
	};

	const head = (
		<PanelHead
			title={ __( 'Receive', 'keepmerge-push-staging-to-live' ) }
			description={ __(
				'Let a staging site push to this site. Pushes are accepted only while the receive window is open, and only from staging sites you approved here.',
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
				<div className="sgsp-columns">
					<SkeletonCard lines={ 3 } />
					<SkeletonCard lines={ 3 } />
				</div>
				<div className="sgsp-mt-6">
					<SkeletonCard lines={ 4 } />
				</div>
			</div>
		);
	}

	const approvals = data.pairings.filter( ( pairing ) => 'awaiting_approval' === pairing.status );
	const listed = data.pairings;

	return (
		<div>
			{ head }

			{ data.multisite && (
				<Alert tone="warning" title={ __( 'Not supported on multisite in this version', 'keepmerge-push-staging-to-live' ) }>
					{ __(
						'Receiving pushes on a multisite network is not supported yet. Sending from a single site works.',
						'keepmerge-push-staging-to-live'
					) }
				</Alert>
			) }

			<div className="sgsp-stack">
				{ approvals.map( ( pairing ) => (
					<ApprovalCard
						key={ pairing.uuid }
						pairing={ pairing }
						now={ now }
						busy={ busy === pairing.uuid }
						onApprove={ () => approve( pairing ) }
						onReject={ () => setConfirm( { type: 'reject', pairing } ) }
					/>
				) ) }

				<div className="sgsp-columns">
					<WindowCard
						window={ data.window }
						now={ now }
						busy={ 'window' === busy }
						disabled={ data.multisite }
						onOpen={ openWindow }
						onClose={ closeWindow }
					/>
					<KeyCard
						issued={ issued }
						canCreate={ data.can_create_key }
						blocker={ data.key_blocker }
						now={ now }
						busy={ 'key' === busy }
						onCreate={ createKey }
						onHide={ () => setIssued( null ) }
						notify={ notify }
					/>
				</div>

				<PairingsCard
					pairings={ listed }
					authors={ data.authors }
					now={ now }
					busy={ busy }
					onUpdate={ update }
					onRevoke={ ( pairing ) => setConfirm( { type: 'revoke', pairing } ) }
				/>
			</div>

			{ confirm && (
				<ConfirmDialog
					title={
						'reject' === confirm.type
							? __( 'Reject this staging site?', 'keepmerge-push-staging-to-live' )
							: __( 'Revoke this pairing?', 'keepmerge-push-staging-to-live' )
					}
					confirmLabel={
						'reject' === confirm.type
							? __( 'Reject', 'keepmerge-push-staging-to-live' )
							: __( 'Revoke pairing', 'keepmerge-push-staging-to-live' )
					}
					destructive
					onConfirm={ confirmAction }
					onCancel={ () => setConfirm( null ) }
				>
					<p>
						{ sprintf(
							/* translators: 1: staging site URL or "not connected yet", 2: receiver login. */
							__(
								'Staging site: %1$s. This deletes 1 receiver account (%2$s) with all of its Application Passwords, and the pairing secrets. The staging site will need a new key to push again.',
								'keepmerge-push-staging-to-live'
							),
							confirm.pairing.source_url || __( 'not connected yet', 'keepmerge-push-staging-to-live' ),
							confirm.pairing.receiver_login || '–'
						) }
					</p>
					<p>
						{ __(
							'Nothing on this site\'s content changes. The pairing stays in the audit log.',
							'keepmerge-push-staging-to-live'
						) }
					</p>
				</ConfirmDialog>
			) }
		</div>
	);
}
