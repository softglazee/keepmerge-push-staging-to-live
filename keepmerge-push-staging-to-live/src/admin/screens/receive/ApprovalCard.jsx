import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Ban, LoaderCircle, ShieldQuestionMark, UserCheck } from 'lucide-react';
import Card from '../../components/Card';
import { countdown, dateTime } from './time';

/**
 * A staging site finalized pairing and waits for this site's approval. Until then it can only
 * check the status. The address it connected from is the one value this site observed itself,
 * so it comes first; the URL and site ID are what the connecting site sent and are labelled as
 * its claim (whoever holds the key chooses them).
 *
 * @param {Object}   props
 * @param {Object}   props.pairing   Pairing.
 * @param {number}   props.now       Server-aligned now.
 * @param {boolean}  props.busy      An action on this pairing is running.
 * @param {Function} props.onApprove Approves.
 * @param {Function} props.onReject  Asks to reject.
 */
export default function ApprovalCard( { pairing, now, busy, onApprove, onReject } ) {
	return (
		<Card
			icon={ ShieldQuestionMark }
			tone="gold"
			title={ __( 'A staging site asks to push to this site', 'keepmerge-push-staging-to-live' ) }
			description={ __(
				'Approve only if you created the key for this site and you recognise the address it connected from. The site address and ID below are sent by the connecting site and are not proof of where it runs. Until you approve, it can only check the status.',
				'keepmerge-push-staging-to-live'
			) }
		>
			<dl className="sgsp-facts">
				<dt>{ __( 'Connected from (seen by this site)', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>
					<code className="sgsp-code">{ pairing.source_ip || '–' }</code>
				</dd>
				<dt>{ __( 'Site address (claimed by the connecting site)', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>
					<code className="sgsp-code">{ pairing.source_url || '–' }</code>
				</dd>
				<dt>{ __( 'Site ID (claimed by the connecting site)', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>
					<code className="sgsp-code">{ pairing.source_site_id || '–' }</code>
				</dd>
				<dt>{ __( 'Confirmed', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>{ dateTime( pairing.confirmed_at ) }</dd>
				<dt>{ __( 'Expires in', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>{ countdown( ( pairing.expires_at || now ) - now ) }</dd>
			</dl>
			<div className="sgsp-flex sgsp-flex-wrap sgsp-gap-2 sgsp-mt-4">
				<button
					type="button"
					className="sgsp-button sgsp-button--primary"
					onClick={ onApprove }
					disabled={ busy }
				>
					{ busy ? (
						<LoaderCircle size={ 15 } aria-hidden="true" className="sgsp-animate-spin" />
					) : (
						<UserCheck size={ 15 } aria-hidden="true" />
					) }
					{ __( 'Approve', 'keepmerge-push-staging-to-live' ) }
				</button>
				<button
					type="button"
					className="sgsp-button sgsp-button--secondary sgsp-button--danger-text"
					onClick={ onReject }
					disabled={ busy }
				>
					<Ban size={ 15 } aria-hidden="true" />
					{ __( 'Reject', 'keepmerge-push-staging-to-live' ) }
				</button>
			</div>
		</Card>
	);
}
