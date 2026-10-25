import { createElement, Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Hourglass, Link2, Link2Off, LoaderCircle, RefreshCw, Trash2 } from 'lucide-react';
import Alert from '../../components/Alert';
import Badge from '../../components/Badge';
import Card from '../../components/Card';
import { ago, countdown, dateTime } from '../receive/time';
import { headerLabel, sendStatus, skewLabel, versionsLabel } from './format';

/**
 * The words for the live site's receive window, as the last status call saw it.
 *
 * @param {Object} pairing Pairing.
 * @param {number} now     Server-aligned now.
 * @return {string} Label.
 */
function windowLabel( pairing, now ) {
	if ( false === pairing.window_open ) {
		return __( 'Closed', 'keepmerge-push-staging-to-live' );
	}
	if ( pairing.window_closes_at && pairing.window_closes_at > now ) {
		return sprintf(
			/* translators: %s: countdown such as 45:12. */
			__( 'Open, closes in %s', 'keepmerge-push-staging-to-live' ),
			countdown( pairing.window_closes_at - now )
		);
	}
	if ( pairing.window_closes_at ) {
		return __( 'Closed by now', 'keepmerge-push-staging-to-live' );
	}
	return __( 'Not checked yet', 'keepmerge-push-staging-to-live' );
}

/**
 * One connection from this site to a live site: its state, what live reported in the last
 * status call, and the actions (check status, revoke here).
 *
 * @param {Object}   props
 * @param {Object}   props.pairing  Pairing (no credentials).
 * @param {Object}   props.site     This site: { home_url, site_id }.
 * @param {number}   props.now      Server-aligned now.
 * @param {boolean}  props.busy     A request for this pairing is running.
 * @param {Function} props.onCheck  Runs a status call.
 * @param {Function} props.onRevoke Asks to revoke.
 */
export default function ConnectionCard( { pairing, site, now, busy, onCheck, onRevoke } ) {
	const status = sendStatus( pairing.display_status );
	const live = pairing.live;
	const last = pairing.last_check;
	const retired = 'repair_needed' === pairing.status;
	const failed = last && ! last.ok && last.message && ! retired;

	return (
		<Card
			icon={ retired ? Link2Off : Link2 }
			tone={ 'active' === pairing.status ? 'navy' : 'gold' }
			title={ live.name || live.host }
			description={ live.home_url || live.host }
			aside={ <Badge tone={ status.tone }>{ status.label }</Badge> }
		>
			{ 'awaiting_approval' === pairing.status && (
				<Alert tone="warning" title={ __( 'Waiting for approval on live', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ sprintf(
							/* translators: 1: live site host, 2: this site's address, 3: this site's ID. */
							__(
								'This site is connected, but cannot push until an administrator approves it on the live site. On %1$s, open Tools → Staging Push → Receive and approve the staging site %2$s (site ID %3$s).',
								'keepmerge-push-staging-to-live'
							),
							live.host,
							site.home_url,
							site.site_id
						) }
					</p>
					{ pairing.expires_at && (
						<p className="sgsp-m-0 sgsp-mt-2">
							<Hourglass size={ 13 } aria-hidden="true" />{ ' ' }
							{ sprintf(
								/* translators: %s: countdown such as 23:59:10. */
								__( 'The live site keeps the request for %s; after that, connect again with a new key.', 'keepmerge-push-staging-to-live' ),
								countdown( pairing.expires_at - now )
							) }
						</p>
					) }
				</Alert>
			) }

			{ 'pending' === pairing.status && (
				<Alert tone="info" title={ __( 'The last pairing step did not finish', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ __(
							'The live site issued this site\'s credentials, but did not confirm the last step. Check status to finish it.',
							'keepmerge-push-staging-to-live'
						) }
					</p>
				</Alert>
			) }

			{ retired && (
				<Alert
					tone="danger"
					title={
						'revoked_on_live' === pairing.display_status
							? __( 'The live site revoked or reset this pairing', 'keepmerge-push-staging-to-live' )
							: __( 'This pairing needs to be set up again', 'keepmerge-push-staging-to-live' )
					}
				>
					<p className="sgsp-m-0">{ pairing.reason_message }</p>
					<p className="sgsp-m-0 sgsp-mt-2">
						{ __(
							'Its credentials on this site were deleted; nothing can be sent through it any more.',
							'keepmerge-push-staging-to-live'
						) }
					</p>
				</Alert>
			) }

			{ failed && (
				<Alert
					tone={ 'window_closed' === last.code ? 'warning' : 'danger' }
					title={
						'window_closed' === last.code
							? __( 'The live site is not receiving right now', 'keepmerge-push-staging-to-live' )
							: __( 'The last status check failed', 'keepmerge-push-staging-to-live' )
					}
				>
					<p className="sgsp-m-0">{ last.message }</p>
				</Alert>
			) }

			<dl className="sgsp-facts sgsp-mt-4">
				<dt>{ __( 'Pairing', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>
					<code className="sgsp-code">{ pairing.uuid }</code>
				</dd>
				<dt>{ __( 'Receiver on live', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>
					<code className="sgsp-code">{ pairing.receiver_login || '–' }</code>
				</dd>
				<dt>{ __( 'Connects to', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>
					{ pairing.address ? <code className="sgsp-code">{ pairing.address }</code> : '–' }
				</dd>
				<dt>{ __( 'Live site ID', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>
					<code className="sgsp-code">{ live.site_id || '–' }</code>
				</dd>
				<dt>{ __( 'Versions on live', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>{ versionsLabel( pairing.versions ) }</dd>
				<dt>{ __( 'Clock difference', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>{ skewLabel( pairing.skew ) }</dd>
				<dt>{ __( 'Authorization header', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>{ headerLabel( pairing.authorization_header_seen ) }</dd>
				<dt>{ __( 'Receive window on live', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>{ windowLabel( pairing, now ) }</dd>
				<dt>{ __( 'Last checked', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>{ last ? ago( last.at, now ) : __( 'Never', 'keepmerge-push-staging-to-live' ) }</dd>
				{ pairing.approved_at && (
					<>
						<dt>{ __( 'Approved on live', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>{ dateTime( pairing.approved_at ) }</dd>
					</>
				) }
			</dl>

			<div className="sgsp-flex sgsp-flex-wrap sgsp-gap-2 sgsp-mt-4">
				{ ! retired && (
					<button
						type="button"
						className="sgsp-button sgsp-button--secondary"
						onClick={ onCheck }
						disabled={ busy }
					>
						{ busy ? (
							<LoaderCircle size={ 15 } aria-hidden="true" className="sgsp-animate-spin" />
						) : (
							<RefreshCw size={ 15 } aria-hidden="true" />
						) }
						{ __( 'Check status', 'keepmerge-push-staging-to-live' ) }
					</button>
				) }
				<button
					type="button"
					className="sgsp-button sgsp-button--secondary sgsp-button--danger-text"
					onClick={ onRevoke }
					disabled={ busy }
				>
					<Trash2 size={ 15 } aria-hidden="true" />
					{ __( 'Revoke', 'keepmerge-push-staging-to-live' ) }
				</button>
			</div>
		</Card>
	);
}
