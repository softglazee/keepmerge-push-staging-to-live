import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Link2, ListChecks, PlugZap } from 'lucide-react';
import Badge from '../components/Badge';
import Card from '../components/Card';
import EmptyState from '../components/EmptyState';
import LoadError from '../components/LoadError';
import PanelHead from '../components/PanelHead';
import { SkeletonCard } from '../components/Skeleton';
import { pairingStatusLabel } from './labels';

/**
 * Send tab (the staging side): connection, then plan, diff and push.
 *
 * TODO(Phase 2B): paste a pairing key, preview the live site, connect, revoke.
 * TODO(Phase 3): baseline. TODO(Phase 4): plan, diff and conflict decisions.
 * TODO(Phase 5): the push dialog with counts and the typed live domain.
 *
 * @param {Object}   props
 * @param {Object}   props.overview Overview data.
 * @param {string}   props.error    Load error.
 * @param {Function} props.onRetry  Reloads.
 */
export default function SendScreen( { overview, error, onRetry } ) {
	const head = (
		<PanelHead
			title={ __( 'Send', 'keepmerge-push-staging-to-live' ) }
			description={ __(
				'Push from this site to its live site: connect once, review a dry-run plan of every difference, decide conflicts, then push. Nothing is written to live before you confirm.',
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
					<SkeletonCard lines={ 4 } />
					<SkeletonCard lines={ 2 } />
				</div>
			</div>
		);
	}

	const targets = overview.pairings.sends_to;

	return (
		<div>
			{ head }
			<div className="sgsp-stack">
				<Card
					icon={ Link2 }
					title={ __( 'Connection to live', 'keepmerge-push-staging-to-live' ) }
				>
					{ targets.length ? (
						<ul className="sgsp-checks">
							{ targets.map( ( pairing ) => (
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
							icon={ PlugZap }
							title={ __( 'Not connected to a live site', 'keepmerge-push-staging-to-live' ) }
							text={ __(
								'Connect this site to the live site it should push to. You need to be an administrator on both.',
								'keepmerge-push-staging-to-live'
							) }
							steps={ [
								__(
									'On the live site, open Tools → Staging Push → Receive and open the receive window.',
									'keepmerge-push-staging-to-live'
								),
								__(
									'Create a pairing key there. It is shown once and works for 30 minutes.',
									'keepmerge-push-staging-to-live'
								),
								__(
									'Paste the key on this tab to connect, then approve this site on the live site.',
									'keepmerge-push-staging-to-live'
								),
							] }
						/>
					) }
				</Card>

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
		</div>
	);
}
