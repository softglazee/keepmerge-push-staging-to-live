import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { History } from 'lucide-react';
import Card from '../components/Card';
import EmptyState from '../components/EmptyState';
import LoadError from '../components/LoadError';
import PanelHead from '../components/PanelHead';
import { SkeletonCard } from '../components/Skeleton';

/**
 * Pushes tab: history of pushes sent or received.
 *
 * TODO(Phase 5): history table from GET /admin/pushes. TODO(Phase 7): push detail, review of
 * held items, continue apply. TODO(Phase 8): resume, release a stale lock, roll back.
 *
 * @param {Object}   props
 * @param {Object}   props.overview Overview data.
 * @param {string}   props.error    Load error.
 * @param {Function} props.onRetry  Reloads.
 */
export default function PushesScreen( { overview, error, onRetry } ) {
	const head = (
		<PanelHead
			title={ __( 'Pushes', 'keepmerge-push-staging-to-live' ) }
			description={ __(
				'Every push sent from or received on this site, with its result. On the live site, this is also where a push is reviewed and rolled back.',
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
				<SkeletonCard lines={ 5 } />
			</div>
		);
	}

	return (
		<div>
			{ head }
			<Card>
				<EmptyState
					icon={ History }
					title={ __( 'No pushes yet', 'keepmerge-push-staging-to-live' ) }
					text={ __(
						'Pair this site first: connect a staging site to its live site from the Send and Receive tabs. Each push then appears here with the items it created, updated or skipped, and the live site keeps a snapshot of every changed item so the push can be rolled back.',
						'keepmerge-push-staging-to-live'
					) }
				/>
			</Card>
		</div>
	);
}
