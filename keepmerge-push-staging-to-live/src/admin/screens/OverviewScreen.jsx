import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	ArrowRight,
	CircleCheck,
	CircleX,
	History,
	Inbox,
	Send,
	Server,
	ShieldCheck,
	TriangleAlert,
} from 'lucide-react';
import Badge, { toneFor } from '../components/Badge';
import Card from '../components/Card';
import EmptyState from '../components/EmptyState';
import LoadError from '../components/LoadError';
import PanelHead from '../components/PanelHead';
import { SkeletonCard } from '../components/Skeleton';
import { pairingStatusLabel } from './labels';
import { pushStateInfo } from './send/push-labels';

const CHECK_ICONS = {
	ok: CircleCheck,
	warning: TriangleAlert,
	error: CircleX,
};

/**
 * Words for a readiness status, shown next to its icon.
 *
 * @param {string} status ok, warning or error.
 * @return {string} Label.
 */
function checkLabel( status ) {
	if ( 'ok' === status ) {
		return __( 'Ready', 'keepmerge-push-staging-to-live' );
	}
	if ( 'warning' === status ) {
		return __( 'Check', 'keepmerge-push-staging-to-live' );
	}
	return __( 'Needs action', 'keepmerge-push-staging-to-live' );
}

/**
 * One connection card ("Sends to" or "Receives from").
 *
 * @param {Object}   props
 * @param {Function} props.icon      Icon.
 * @param {string}   props.title     Card title.
 * @param {Array}    props.pairings  Pairings in this direction.
 * @param {string}   props.emptyTitle Empty-state title.
 * @param {string}   props.emptyText  Empty-state text.
 * @param {string}   props.actionLabel Button label.
 * @param {Function} props.onAction  Opens the related tab.
 */
function ConnectionCard( {
	icon,
	title,
	pairings,
	emptyTitle,
	emptyText,
	actionLabel,
	onAction,
} ) {
	return (
		<Card icon={ icon } title={ title }>
			{ pairings.length ? (
				<ul className="sgsp-checks">
					{ pairings.map( ( pairing ) => (
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
				<div className="sgsp-text-left">
					<p className="sgsp-card__desc sgsp-m-0">
						<strong>{ emptyTitle }</strong> { emptyText }
					</p>
					<button
						type="button"
						className="sgsp-button sgsp-button--secondary sgsp-mt-4"
						onClick={ onAction }
					>
						{ actionLabel }
						<ArrowRight size={ 15 } aria-hidden="true" />
					</button>
				</div>
			) }
		</Card>
	);
}

/**
 * Overview tab: connections, the latest push, this site's facts and the readiness checks.
 *
 * @param {Object}   props
 * @param {Object}   props.overview   Overview data (null while loading).
 * @param {string}   props.error      Load error.
 * @param {Function} props.onRetry    Reloads.
 * @param {Function} props.onNavigate Selects another tab.
 */
export default function OverviewScreen( { overview, error, onRetry, onNavigate } ) {
	const head = (
		<PanelHead
			title={ __( 'Overview', 'keepmerge-push-staging-to-live' ) }
			description={ __(
				'Where this site stands: what it is connected to, whether the server is ready, and the latest push.',
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
				<div className="sgsp-columns">
					<SkeletonCard lines={ 2 } />
					<SkeletonCard lines={ 2 } />
					<SkeletonCard lines={ 2 } />
				</div>
				<div className="sgsp-mt-6">
					<SkeletonCard lines={ 6 } />
				</div>
			</div>
		);
	}

	const checks = overview.readiness || [];
	const ready = checks.filter( ( check ) => 'ok' === check.status ).length;
	const blocked = checks.some( ( check ) => 'error' === check.status );
	const site = overview.site;

	return (
		<div>
			{ head }

			<div className="sgsp-columns">
				<ConnectionCard
					icon={ Send }
					title={ __( 'Sends to', 'keepmerge-push-staging-to-live' ) }
					pairings={ overview.pairings.sends_to }
					emptyTitle={ __( 'Not connected to a live site.', 'keepmerge-push-staging-to-live' ) }
					emptyText={ __(
						'On a staging site, connect it to its live site from the Send tab.',
						'keepmerge-push-staging-to-live'
					) }
					actionLabel={ __( 'Open Send', 'keepmerge-push-staging-to-live' ) }
					onAction={ () => onNavigate( 'send' ) }
				/>
				<ConnectionCard
					icon={ Inbox }
					title={ __( 'Receives from', 'keepmerge-push-staging-to-live' ) }
					pairings={ overview.pairings.receives_from }
					emptyTitle={ __( 'No staging site paired.', 'keepmerge-push-staging-to-live' ) }
					emptyText={ __(
						'On a live site, pair its staging site from the Receive tab.',
						'keepmerge-push-staging-to-live'
					) }
					actionLabel={ __( 'Open Receive', 'keepmerge-push-staging-to-live' ) }
					onAction={ () => onNavigate( 'receive' ) }
				/>
				<Card icon={ History } tone="gold" title={ __( 'Last push', 'keepmerge-push-staging-to-live' ) }>
					{ overview.last_push ? (
						<dl className="sgsp-facts">
							<dt>{ __( 'Started', 'keepmerge-push-staging-to-live' ) }</dt>
							<dd>{ overview.last_push.started_gmt } UTC</dd>
							<dt>{ __( 'State', 'keepmerge-push-staging-to-live' ) }</dt>
							<dd>
								{
									pushStateInfo( overview.last_push.state )
										.label
								}
							</dd>
						</dl>
					) : (
						<div>
							<p className="sgsp-card__desc sgsp-m-0">
								<strong>{ __( 'No pushes yet.', 'keepmerge-push-staging-to-live' ) }</strong>{ ' ' }
								{ __(
									'Once sites are paired, every push is listed with its result in the Pushes tab.',
									'keepmerge-push-staging-to-live'
								) }
							</p>
							<button
								type="button"
								className="sgsp-button sgsp-button--secondary sgsp-mt-4"
								onClick={ () => onNavigate( 'pushes' ) }
							>
								{ __( 'Open Pushes', 'keepmerge-push-staging-to-live' ) }
								<ArrowRight size={ 15 } aria-hidden="true" />
							</button>
						</div>
					) }
				</Card>
			</div>

			<div className="sgsp-mt-6 sgsp-grid sgsp-items-start sgsp-gap-6 xl:sgsp-grid-cols-3">
				<div className="xl:sgsp-col-span-2 sgsp-min-w-0">
					<Card
						icon={ ShieldCheck }
						title={ __( 'Readiness', 'keepmerge-push-staging-to-live' ) }
						description={ __(
							'What this server needs before it can pair and push. Keepmerge reads these settings; it never changes them.',
							'keepmerge-push-staging-to-live'
						) }
						aside={
							<Badge tone={ blocked ? 'danger' : 'success' }>
								{ sprintf(
									/* translators: 1: checks passed, 2: total checks. */
									__( '%1$d of %2$d ready', 'keepmerge-push-staging-to-live' ),
									ready,
									checks.length
								) }
							</Badge>
						}
					>
						{ checks.length ? (
							<ul className="sgsp-checks">
								{ checks.map( ( check ) => {
									const Icon = CHECK_ICONS[ check.status ] || TriangleAlert;
									return (
										<li className="sgsp-check" key={ check.id }>
											<span
												className={ `sgsp-check__icon sgsp-check__icon--${ check.status }` }
												aria-hidden="true"
											>
												<Icon size={ 18 } />
											</span>
											<span className="sgsp-min-w-0">
												<span className="sgsp-check__label">{ check.label }</span>
												<span className="sgsp-check__detail">{ check.detail }</span>
											</span>
											<Badge tone={ toneFor( check.status ) }>
												{ checkLabel( check.status ) }
											</Badge>
										</li>
									);
								} ) }
							</ul>
						) : (
							<EmptyState
								title={ __( 'No checks to show.', 'keepmerge-push-staging-to-live' ) }
							/>
						) }
					</Card>
				</div>

				<Card icon={ Server } title={ __( 'This site', 'keepmerge-push-staging-to-live' ) }>
					<dl className="sgsp-facts">
						<dt>{ __( 'Address', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>{ site.home_url }</dd>
						<dt>{ __( 'Site ID', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>
							<code className="sgsp-code">{ site.site_id }</code>
						</dd>
						<dt>{ __( 'WordPress', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>{ site.wp_version }</dd>
						<dt>{ __( 'PHP', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>{ site.php_version }</dd>
						<dt>{ __( 'Keepmerge', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>{ site.version }</dd>
						<dt>{ __( 'WooCommerce', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>
							{ site.woocommerce
								? __( 'Active', 'keepmerge-push-staging-to-live' )
								: __( 'Not active', 'keepmerge-push-staging-to-live' ) }
						</dd>
					</dl>
					<p className="sgsp-field__hint sgsp-mt-4">
						{ __(
							'The site ID identifies this install to a paired site. A copied database gets a new one automatically.',
							'keepmerge-push-staging-to-live'
						) }
					</p>
				</Card>
			</div>
		</div>
	);
}
