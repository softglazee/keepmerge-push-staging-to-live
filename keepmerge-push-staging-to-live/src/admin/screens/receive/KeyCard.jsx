import { createElement, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Check, Copy, EyeOff, KeyRound, LoaderCircle, TriangleAlert } from 'lucide-react';
import Alert from '../../components/Alert';
import Card from '../../components/Card';
import { countdown } from './time';

/**
 * Create pairing key. The key is shown once, here, until the admin hides it or leaves the
 * tab; the server keeps only a hash of its password and the encrypted secret.
 *
 * @param {Object}      props
 * @param {Object|null} props.issued   { key, uuid, expires } of the key just created.
 * @param {boolean}     props.canCreate Whether a key can be created now.
 * @param {Object|null} props.blocker  { code, message } when it cannot.
 * @param {number}      props.now      Server-aligned now.
 * @param {boolean}     props.busy     Creating.
 * @param {Function}    props.onCreate Creates a key.
 * @param {Function}    props.onHide   Forgets the shown key.
 * @param {Function}    props.notify   Toast.
 */
export default function KeyCard( { issued, canCreate, blocker, now, busy, onCreate, onHide, notify } ) {
	const [ copied, setCopied ] = useState( false );
	const keyRef = useRef( null );
	const left = issued ? issued.expires - now : 0;
	const expired = issued && left <= 0;

	function selectKey() {
		const node = keyRef.current;
		const view = node ? node.ownerDocument.defaultView : null;
		if ( ! view || ! view.getSelection ) {
			return;
		}
		const range = node.ownerDocument.createRange();
		range.selectNodeContents( node );
		const selection = view.getSelection();
		selection.removeAllRanges();
		selection.addRange( range );
	}

	function copy() {
		if ( window.navigator.clipboard && window.isSecureContext ) {
			window.navigator.clipboard
				.writeText( issued.key )
				.then( () => {
					setCopied( true );
					notify( 'success', __( 'Pairing key copied.', 'keepmerge-push-staging-to-live' ) );
				} )
				.catch( () => {
					selectKey();
					notify( 'error', __( 'Copying was blocked. The key is selected: press Ctrl+C (or Cmd+C).', 'keepmerge-push-staging-to-live' ) );
				} );
			return;
		}
		selectKey();
		notify( 'error', __( 'Copying was blocked. The key is selected: press Ctrl+C (or Cmd+C).', 'keepmerge-push-staging-to-live' ) );
	}

	return (
		<Card icon={ KeyRound } tone="gold" title={ __( 'Pairing key', 'keepmerge-push-staging-to-live' ) }>
			{ issued ? (
				<div>
					<Alert tone="warning" title={ __( 'Treat this key like a password.', 'keepmerge-push-staging-to-live' ) }>
						<p className="sgsp-m-0">
							{ __(
								'It is shown only now. Paste it into Tools → Staging Push → Send on your staging site. Do not send it by email or chat. After pairing, it no longer works.',
								'keepmerge-push-staging-to-live'
							) }
						</p>
					</Alert>
					<div className="sgsp-keybox sgsp-mt-4">
						<code
							className="sgsp-keybox__key"
							ref={ keyRef }
							aria-label={ __( 'Pairing key', 'keepmerge-push-staging-to-live' ) }
						>
							{ expired ? __( 'Expired', 'keepmerge-push-staging-to-live' ) : issued.key }
						</code>
					</div>
					<p className="sgsp-countdown sgsp-countdown--small sgsp-mt-3">
						<span className="sgsp-countdown__label">
							{ expired
								? __( 'This key has expired.', 'keepmerge-push-staging-to-live' )
								: __( 'Expires in', 'keepmerge-push-staging-to-live' ) }
						</span>
						{ ! expired && <time className="sgsp-countdown__value">{ countdown( left ) }</time> }
					</p>
					<div className="sgsp-flex sgsp-flex-wrap sgsp-gap-2 sgsp-mt-4">
						<button
							type="button"
							className="sgsp-button sgsp-button--primary"
							onClick={ copy }
							disabled={ expired }
						>
							{ copied ? <Check size={ 15 } aria-hidden="true" /> : <Copy size={ 15 } aria-hidden="true" /> }
							{ copied
								? __( 'Copied', 'keepmerge-push-staging-to-live' )
								: __( 'Copy key', 'keepmerge-push-staging-to-live' ) }
						</button>
						<button type="button" className="sgsp-button sgsp-button--secondary" onClick={ onHide }>
							<EyeOff size={ 15 } aria-hidden="true" />
							{ __( 'Hide key', 'keepmerge-push-staging-to-live' ) }
						</button>
					</div>
				</div>
			) : (
				<div>
					<p className="sgsp-card__desc sgsp-m-0">
						{ __(
							'A key lets one staging site start pairing. It works for 30 minutes, once, and the staging site still needs your approval here.',
							'keepmerge-push-staging-to-live'
						) }
					</p>
					{ ! canCreate && blocker && (
						<p className="sgsp-hint sgsp-mt-3">
							<TriangleAlert size={ 14 } aria-hidden="true" />
							<span>{ blocker.message }</span>
						</p>
					) }
					<button
						type="button"
						className="sgsp-button sgsp-button--primary sgsp-mt-4"
						onClick={ onCreate }
						disabled={ busy || ! canCreate }
					>
						{ busy ? (
							<LoaderCircle size={ 15 } aria-hidden="true" className="sgsp-animate-spin" />
						) : (
							<KeyRound size={ 15 } aria-hidden="true" />
						) }
						{ __( 'Create pairing key', 'keepmerge-push-staging-to-live' ) }
					</button>
				</div>
			) }
		</Card>
	);
}
