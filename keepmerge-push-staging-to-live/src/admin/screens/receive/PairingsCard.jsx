import { createElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Inbox, Info, Save, Trash2, Users } from 'lucide-react';
import Badge from '../../components/Badge';
import Card from '../../components/Card';
import DataTable from '../../components/DataTable';
import EmptyState from '../../components/EmptyState';
import { pairingStatusLabel } from '../labels';
import { ago, countdown } from './time';

const TONES = {
	active: 'success',
	awaiting_approval: 'warning',
	pending: 'info',
	repair_needed: 'danger',
};

/**
 * Staging sites paired with this site: status, last use, default author, IP pin, Revoke.
 *
 * @param {Object}   props
 * @param {Array}    props.pairings Pairings (no credentials).
 * @param {Array}    props.authors  [ { id, name } ] users who can write posts.
 * @param {number}   props.now      Server-aligned now.
 * @param {string}   props.busy     UUID of the pairing with a request running.
 * @param {Function} props.onUpdate ( uuid, data ) for default_author / pinned_ip.
 * @param {Function} props.onRevoke ( pairing ) asks to revoke.
 */
export default function PairingsCard( { pairings, authors, now, busy, onUpdate, onRevoke } ) {
	const [ pins, setPins ] = useState( {} );

	const pinValue = ( pairing ) =>
		Object.prototype.hasOwnProperty.call( pins, pairing.uuid ) ? pins[ pairing.uuid ] : pairing.pinned_ip;

	const columns = [
		{
			key: 'site',
			label: __( 'Staging site', 'keepmerge-push-staging-to-live' ),
			render: ( pairing ) =>
				pairing.source_url ? (
					<div className="sgsp-min-w-0">
						<strong>{ pairing.source_host || pairing.source_url }</strong>
						<span className="sgsp-cell-muted sgsp-block">
							{ pairing.source_ip
								? sprintf(
										/* translators: %s: IP address. */
										__( 'from %s', 'keepmerge-push-staging-to-live' ),
										pairing.source_ip
								  )
								: '' }
						</span>
					</div>
				) : (
					<span className="sgsp-cell-muted">
						{ __( 'Key created; waiting for the staging site', 'keepmerge-push-staging-to-live' ) }
					</span>
				),
		},
		{
			key: 'status',
			label: __( 'Status', 'keepmerge-push-staging-to-live' ),
			render: ( pairing ) => (
				<div>
					<Badge tone={ TONES[ pairing.status ] || 'neutral' }>
						{ pairingStatusLabel( pairing.status ) }
					</Badge>
					{ ( 'pending' === pairing.status || 'awaiting_approval' === pairing.status ) && pairing.expires_at && (
						<span className="sgsp-cell-muted sgsp-block sgsp-mt-1">
							{ sprintf(
								/* translators: %s: countdown such as 12:30. */
								__( 'expires in %s', 'keepmerge-push-staging-to-live' ),
								countdown( pairing.expires_at - now )
							) }
						</span>
					) }
				</div>
			),
		},
		{
			key: 'used',
			label: __( 'Last used', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-cell-nowrap',
			render: ( pairing ) => <span className="sgsp-cell-muted">{ ago( pairing.last_used_at, now ) }</span>,
		},
		{
			key: 'author',
			label: __( 'Default author', 'keepmerge-push-staging-to-live' ),
			render: ( pairing ) => (
				<select
					className="sgsp-select sgsp-select--compact"
					value={ pairing.default_author.id || '' }
					disabled={ busy === pairing.uuid || 'repair_needed' === pairing.status }
					aria-label={ sprintf(
						/* translators: %s: staging site host. */
						__( 'Default author for %s', 'keepmerge-push-staging-to-live' ),
						pairing.source_host || pairing.uuid
					) }
					onChange={ ( event ) => onUpdate( pairing.uuid, { default_author: Number( event.target.value ) } ) }
				>
					{ ! pairing.default_author.id && (
						<option value="">{ __( 'Choose…', 'keepmerge-push-staging-to-live' ) }</option>
					) }
					{ authors.map( ( author ) => (
						<option key={ author.id } value={ author.id }>
							{ author.name }
						</option>
					) ) }
				</select>
			),
		},
		{
			key: 'pin',
			label: __( 'IP pin', 'keepmerge-push-staging-to-live' ),
			render: ( pairing ) => (
				<form
					className="sgsp-inline-form"
					onSubmit={ ( event ) => {
						event.preventDefault();
						onUpdate( pairing.uuid, { pinned_ip: pinValue( pairing ).trim() } );
					} }
				>
					<input
						type="text"
						className="sgsp-input sgsp-input--compact"
						value={ pinValue( pairing ) }
						placeholder={ __( 'Not pinned', 'keepmerge-push-staging-to-live' ) }
						autoComplete="off"
						spellCheck={ false }
						maxLength={ 45 }
						disabled={ busy === pairing.uuid || 'repair_needed' === pairing.status }
						aria-label={ sprintf(
							/* translators: %s: staging site host. */
							__( 'IP pin for %s', 'keepmerge-push-staging-to-live' ),
							pairing.source_host || pairing.uuid
						) }
						onChange={ ( event ) => setPins( { ...pins, [ pairing.uuid ]: event.target.value } ) }
					/>
					<button
						type="submit"
						className="sgsp-icon-button sgsp-icon-button--bordered"
						disabled={ busy === pairing.uuid || pinValue( pairing ) === pairing.pinned_ip }
						aria-label={ __( 'Save IP pin', 'keepmerge-push-staging-to-live' ) }
						title={ __( 'Save IP pin', 'keepmerge-push-staging-to-live' ) }
					>
						<Save size={ 15 } aria-hidden="true" />
					</button>
				</form>
			),
		},
		{
			key: 'actions',
			label: __( 'Actions', 'keepmerge-push-staging-to-live' ),
			render: ( pairing ) => (
				<button
					type="button"
					className="sgsp-button sgsp-button--secondary sgsp-button--danger-text sgsp-button--small"
					onClick={ () => onRevoke( pairing ) }
					disabled={ busy === pairing.uuid }
				>
					<Trash2 size={ 14 } aria-hidden="true" />
					{ __( 'Revoke', 'keepmerge-push-staging-to-live' ) }
				</button>
			),
		},
	];

	return (
		<Card icon={ Users } title={ __( 'Paired staging sites', 'keepmerge-push-staging-to-live' ) }>
			{ pairings.length ? (
				<div>
					<DataTable
						caption={ __( 'Staging sites paired with this site', 'keepmerge-push-staging-to-live' ) }
						columns={ columns }
						rows={ pairings }
						getRowKey={ ( pairing ) => pairing.uuid }
					/>
					<p className="sgsp-hint sgsp-mt-4">
						<Info size={ 14 } aria-hidden="true" />
						<span>
							{ __(
								'The IP pin compares the address this site sees for each request. Behind a reverse proxy or CDN that is the proxy\'s address, not the staging site\'s, so leave the pin empty there. The default author is used for pushed content whose author does not exist on this site.',
								'keepmerge-push-staging-to-live'
							) }
						</span>
					</p>
				</div>
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
						__( 'Approve the staging site here when it appears.', 'keepmerge-push-staging-to-live' ),
					] }
				/>
			) }
		</Card>
	);
}
