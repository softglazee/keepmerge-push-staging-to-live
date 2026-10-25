import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Inbox, Lock, RadioTower, Users } from 'lucide-react';
import Alert from '../components/Alert';
import Badge from '../components/Badge';
import Card from '../components/Card';
import EmptyState from '../components/EmptyState';
import LoadError from '../components/LoadError';
import PanelHead from '../components/PanelHead';
import { SkeletonCard } from '../components/Skeleton';
import { pairingStatusLabel } from './labels';

/**
 * Receive tab (the live side): the receive window, pairing keys, approvals and pairings.
 *
 * TODO(Phase 2A): open and close the window (1, 8 or 24 hours), create a pairing key, approve
 * or reject a source, set the default author and IP pin, revoke.
 *
 * @param {Object}   props
 * @param {Object}   props.overview Overview data.
 * @param {string}   props.error    Load error.
 * @param {Function} props.onRetry  Reloads.
 */
export default function ReceiveScreen( { overview, error, onRetry } ) {
	const head = (
		<PanelHead
			title={ __( 'Receive', 'keepmerge-push-staging-to-live' ) }
			description={ __(
				'Let a staging site push to this site. Pushes are accepted only while the receive window is open, and only from staging sites you approved here.',
				'keepmerge-push-staging-to-live'
			) }
		/>
	);

	if ( error ) {
		return (
			<div>
				{ head }
				<LoadError message={ error } onRetry={ onRetry } />
			</div>
		);
	}

	if ( ! overview ) {
		return (
			<div>
				{ head }
				<div className="sgsp-stack">
					<SkeletonCard lines={ 2 } />
					<SkeletonCard lines={ 4 } />
				</div>
			</div>
		);
	}

	const sources = overview.pairings.receives_from;
	const isOpen = overview.receive.open;

	return (
		<div>
			{ head }

			{ overview.site.multisite && (
				<Alert tone="warning" title={ __( 'Not supported on multisite in this version', 'keepmerge-push-staging-to-live' ) }>
					{ __(
						'Receiving pushes on a multisite network is not supported yet. Sending from a single site works.',
						'keepmerge-push-staging-to-live'
					) }
				</Alert>
			) }

			<div className="sgsp-stack">
				<Card
					icon={ isOpen ? RadioTower : Lock }
					title={ __( 'Receive window', 'keepmerge-push-staging-to-live' ) }
					aside={
						<Badge tone={ isOpen ? 'success' : 'neutral' } icon={ isOpen ? RadioTower : Lock }>
							{ isOpen
								? __( 'Open', 'keepmerge-push-staging-to-live' )
								: __( 'Closed', 'keepmerge-push-staging-to-live' ) }
						</Badge>
					}
				>
					<p className="sgsp-card__desc sgsp-m-0">
						{ isOpen
							? __(
									'This site accepts requests from approved staging sites until the window closes.',
									'keepmerge-push-staging-to-live'
							  )
							: __(
									'While the window is closed, this site has no routes a staging site can call. Open it when you pair or push: it stays open for 1, 8 or 24 hours and then closes by itself.',
									'keepmerge-push-staging-to-live'
							  ) }
					</p>
				</Card>

				<Card icon={ Users } title={ __( 'Paired staging sites', 'keepmerge-push-staging-to-live' ) }>
					{ sources.length ? (
						<ul className="sgsp-checks">
							{ sources.map( ( pairing ) => (
								<li className="sgsp-check" key={ pairing.uuid }>
									<span />
									<span className="sgsp-check__label">{ pairing.url }</span>
									<Badge tone={ 'active' === pairing.status ? 'success' : 'warning' }>
										{ pairingStatusLabel( pairing.status ) }
									</Badge>
								</li>
							) ) }
						</ul>
					) : (
						<EmptyState
							icon={ Inbox }
							title={ __( 'No staging site paired', 'keepmerge-push-staging-to-live' ) }
							text={ __(
								'Pair the staging site that should push to this site. Each staging site gets its own receiver account that can do nothing but receive pushes.',
								'keepmerge-push-staging-to-live'
							) }
							steps={ [
								__( 'Open the receive window on this tab.', 'keepmerge-push-staging-to-live' ),
								__(
									'Create a pairing key and paste it into Tools → Staging Push → Send on the staging site.',
									'keepmerge-push-staging-to-live'
								),
								__(
									'Approve the staging site here when it appears.',
									'keepmerge-push-staging-to-live'
								),
							] }
						/>
					) }
				</Card>
			</div>
		</div>
	);
}
