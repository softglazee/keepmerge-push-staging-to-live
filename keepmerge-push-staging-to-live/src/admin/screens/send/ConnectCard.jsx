import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { KeyRound, Link2, LoaderCircle, ScanSearch, TriangleAlert, X } from 'lucide-react';
import Alert from '../../components/Alert';
import Card from '../../components/Card';
import { countdown } from '../receive/time';
import { headerLabel, skewLabel, versionsLabel } from './format';

/**
 * Connect card: paste the key created on live, see what it points to, connect.
 *
 * The key stays in this form's memory only; it is sent to this site's own admin route twice
 * (preview, then connect) and is never stored in the browser.
 *
 * @param {Object}      props
 * @param {boolean}     props.canConnect Whether this site can connect at all.
 * @param {Object|null} props.blocker    { code, message } when it cannot.
 * @param {boolean}     props.first      No pairing exists yet (shows the steps).
 * @param {string}      props.value      Key typed or pasted.
 * @param {Function}    props.onChange   ( value ).
 * @param {Object|null} props.preview    Preview from the server.
 * @param {string}      props.error      Error message to show.
 * @param {string}      props.busy       'preview' or 'connect' while a request runs.
 * @param {number}      props.now        Server-aligned now.
 * @param {Function}    props.onPreview  Checks the key.
 * @param {Function}    props.onConnect  Connects with the previewed key.
 * @param {Function}    props.onCancel   Clears the preview and the key.
 */
export default function ConnectCard( {
	canConnect,
	blocker,
	first,
	value,
	onChange,
	preview,
	error,
	busy,
	now,
	onPreview,
	onConnect,
	onCancel,
} ) {
	const inputId = 'sgsp-connect-key';
	const hintId = 'sgsp-connect-key-hint';
	const working = '' !== busy;
	const live = preview ? preview.live : null;

	return (
		<Card
			icon={ KeyRound }
			tone="gold"
			title={ __( 'Connect to a live site', 'keepmerge-push-staging-to-live' ) }
			description={ __(
				'Paste the pairing key created on the live site. The key works once, for 30 minutes, and the live administrator approves this site afterwards.',
				'keepmerge-push-staging-to-live'
			) }
		>
			{ first && ! preview && (
				<ol className="sgsp-steps sgsp-steps--left">
					<li>
						{ __(
							'On the live site, open Tools → Staging Push → Receive, open the receive window and create a pairing key.',
							'keepmerge-push-staging-to-live'
						) }
					</li>
					<li>{ __( 'Paste the key below and check it.', 'keepmerge-push-staging-to-live' ) }</li>
					<li>
						{ __(
							'Connect, then approve this site on the live site\'s Receive tab.',
							'keepmerge-push-staging-to-live'
						) }
					</li>
				</ol>
			) }

			{ ! canConnect && blocker && (
				<Alert tone="danger" title={ __( 'This site cannot connect yet', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ blocker.message }</p>
				</Alert>
			) }

			{ ! preview && (
				<form
					className="sgsp-mt-4"
					onSubmit={ ( event ) => {
						event.preventDefault();
						onPreview();
					} }
				>
					<div className="sgsp-field">
						<label className="sgsp-field__label" htmlFor={ inputId }>
							{ __( 'Pairing key', 'keepmerge-push-staging-to-live' ) }
						</label>
						<input
							id={ inputId }
							type="text"
							className="sgsp-input sgsp-input--key"
							value={ value }
							onChange={ ( event ) => onChange( event.target.value ) }
							placeholder="SGSP1|https://…"
							autoComplete="off"
							autoCorrect="off"
							autoCapitalize="off"
							spellCheck={ false }
							maxLength={ 600 }
							disabled={ ! canConnect || working }
							aria-invalid={ error ? 'true' : undefined }
							aria-describedby={ hintId }
						/>
						<p className="sgsp-field__hint" id={ hintId }>
							{ __(
								'Treat the key like a password. Checking it reads the live site\'s name, versions and clock; nothing is changed on either site.',
								'keepmerge-push-staging-to-live'
							) }
						</p>
					</div>
					{ error && (
						<div className="sgsp-mt-4">
							<Alert tone="danger" title={ __( 'The key cannot be used', 'keepmerge-push-staging-to-live' ) }>
								<p className="sgsp-m-0">{ error }</p>
							</Alert>
						</div>
					) }
					<button
						type="submit"
						className="sgsp-button sgsp-button--primary sgsp-mt-4"
						disabled={ ! canConnect || working || '' === value.trim() }
					>
						{ 'preview' === busy ? (
							<LoaderCircle size={ 15 } aria-hidden="true" className="sgsp-animate-spin" />
						) : (
							<ScanSearch size={ 15 } aria-hidden="true" />
						) }
						{ __( 'Check key', 'keepmerge-push-staging-to-live' ) }
					</button>
				</form>
			) }

			{ preview && (
				<div className="sgsp-preview">
					<h4 className="sgsp-subhead">
						{ sprintf(
							/* translators: %s: live site name or host. */
							__( 'The key points to %s', 'keepmerge-push-staging-to-live' ),
							live.name || preview.rest_host
						) }
					</h4>
					<dl className="sgsp-facts sgsp-preview__facts">
						<dt>{ __( 'Address', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>
							<code className="sgsp-code">{ live.home_url }</code>
						</dd>
						<dt>{ __( 'Connects to', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>
							<code className="sgsp-code">{ preview.address || '–' }</code>
						</dd>
						<dt>{ __( 'Live site ID', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>
							<code className="sgsp-code">{ live.site_id }</code>
						</dd>
						<dt>{ __( 'Versions', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>{ versionsLabel( preview.versions ) }</dd>
						<dt>{ __( 'Clock difference', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>{ skewLabel( preview.skew ) }</dd>
						<dt>{ __( 'Authorization header', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>{ headerLabel( preview.authorization_header_seen ) }</dd>
						<dt>{ __( 'Receive window', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>
							{ preview.window_closes_at
								? sprintf(
										/* translators: %s: countdown such as 45:12. */
										__( 'Open, closes in %s', 'keepmerge-push-staging-to-live' ),
										countdown( preview.window_closes_at - now )
								  )
								: __( 'Open', 'keepmerge-push-staging-to-live' ) }
						</dd>
						<dt>{ __( 'Key expires in', 'keepmerge-push-staging-to-live' ) }</dt>
						<dd>{ countdown( preview.key_expires_at - now ) }</dd>
					</dl>

					{ preview.blocker && (
						<div className="sgsp-mt-4">
							<Alert tone="danger" title={ __( 'Connecting is blocked', 'keepmerge-push-staging-to-live' ) }>
								<p className="sgsp-m-0">{ preview.blocker.message }</p>
							</Alert>
						</div>
					) }
					{ error && (
						<div className="sgsp-mt-4">
							<Alert tone="danger" title={ __( 'Not connected', 'keepmerge-push-staging-to-live' ) }>
								<p className="sgsp-m-0">{ error }</p>
							</Alert>
						</div>
					) }

					<p className="sgsp-hint sgsp-preview__hint">
						<TriangleAlert size={ 14 } aria-hidden="true" />
						<span>
							{ __(
								'Connect only if this is your live site. Connecting uses up the key; the live administrator then approves this site before it can push.',
								'keepmerge-push-staging-to-live'
							) }
						</span>
					</p>

					<div className="sgsp-flex sgsp-flex-wrap sgsp-gap-2 sgsp-mt-4">
						<button
							type="button"
							className="sgsp-button sgsp-button--primary"
							onClick={ onConnect }
							disabled={ working || ! preview.can_connect }
						>
							{ 'connect' === busy ? (
								<LoaderCircle size={ 15 } aria-hidden="true" className="sgsp-animate-spin" />
							) : (
								<Link2 size={ 15 } aria-hidden="true" />
							) }
							{ sprintf(
								/* translators: %s: live site host. */
								__( 'Connect to %s', 'keepmerge-push-staging-to-live' ),
								preview.rest_host
							) }
						</button>
						<button
							type="button"
							className="sgsp-button sgsp-button--secondary"
							onClick={ onCancel }
							disabled={ working }
						>
							<X size={ 15 } aria-hidden="true" />
							{ __( 'Cancel', 'keepmerge-push-staging-to-live' ) }
						</button>
					</div>
				</div>
			) }
		</Card>
	);
}
