import { createElement, Fragment, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Send } from 'lucide-react';
import Alert from '../../components/Alert';
import Modal from '../../components/Modal';
import { number } from './plan-labels';

/**
 * The push dialog: what the push would do, with exact counts, and the live domain typed to
 * confirm.
 *
 * TODO(Phase 5): the confirm button sends the decided items to live (POST /admin/pushes with
 * the typed domain checked server-side). In this build the plan is a dry run and nothing is sent.
 *
 * @param {Object}   props
 * @param {Object}   props.summary Plan summary.
 * @param {string}   props.host    Live host.
 * @param {Function} props.onClose Closes the dialog.
 */
export default function PushDialog( { summary, host, onClose } ) {
	const [ typed, setTyped ] = useState( '' );
	const d = summary.dialog;
	const lines = [
		[ d.create, __( 'created on live', 'keepmerge-push-staging-to-live' ) ],
		[ d.update, __( 'updated on live with this site\'s version', 'keepmerge-push-staging-to-live' ) ],
		[ d.recreate, __( 'recreated on live', 'keepmerge-push-staging-to-live' ) ],
		[ d.trash, __( 'moved to the Trash on live', 'keepmerge-push-staging-to-live' ) ],
		[ d.record_base, __( 'recorded as in sync (no content written)', 'keepmerge-push-staging-to-live' ) ],
		[ d.keep_live, __( 'kept as they are on live', 'keepmerge-push-staging-to-live' ) ],
		[ d.skip, __( 'skipped', 'keepmerge-push-staging-to-live' ) ],
	];
	const matches = typed.trim().toLowerCase() === host.toLowerCase();

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: live site host. */
				__( 'Push to %s', 'keepmerge-push-staging-to-live' ),
				host
			) }
			onClose={ onClose }
			initialFocus=".sgsp-confirm-cancel"
			footer={
				<Fragment>
					<button type="button" className="sgsp-button sgsp-button--secondary sgsp-confirm-cancel" onClick={ onClose }>
						{ __( 'Cancel', 'keepmerge-push-staging-to-live' ) }
					</button>
					<button type="button" className="sgsp-button sgsp-button--primary" disabled aria-describedby="sgsp-push-dryrun">
						<Send size={ 15 } aria-hidden="true" />
						{ __( 'Push', 'keepmerge-push-staging-to-live' ) }
					</button>
				</Fragment>
			}
		>
			<p>{ __( 'With the decisions in this plan, a push would leave these items:', 'keepmerge-push-staging-to-live' ) }</p>
			<ul className="sgsp-pushcounts">
				{ lines.map( ( [ count, label ] ) => (
					<li key={ label } className={ count > 0 ? '' : 'is-zero' }>
						<strong>{ number( count ) }</strong> { label }
					</li>
				) ) }
			</ul>
			{ d.held > 0 && (
				<Alert tone="warning" title={ __( 'Review on live', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ sprintf(
							/* translators: %s: number of items. */
							_n(
								'%s item carries markup live holds until a live administrator approves, filters or skips it on live.',
								'%s items carry markup live holds until a live administrator approves, filters or skips them on live.',
								d.held,
								'keepmerge-push-staging-to-live'
							),
							number( d.held )
						) }
					</p>
				</Alert>
			) }
			{ d.by_dates > 0 && (
				<Alert tone="warning" title={ __( 'Decided by dates', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ sprintf(
							/* translators: %s: number of items. */
							_n(
								'%s default was decided by modification dates against the clone date. A change that does not update the date (meta only, for example SEO fields edited on live) is invisible to that rule: check this item.',
								'%s defaults were decided by modification dates against the clone date. A change that does not update the date (meta only, for example SEO fields edited on live) is invisible to that rule: check these items.',
								d.by_dates,
								'keepmerge-push-staging-to-live'
							),
							number( d.by_dates )
						) }
					</p>
				</Alert>
			) }
			{ d.products_split > 0 && (
				<p className="sgsp-field__hint">
					{ sprintf(
						/* translators: %s: number of products. */
						_n(
							'%s product is split: some field groups are pushed while live keeps others (for example its prices). Stock is never pushed unless chosen per product.',
							'%s products are split: some field groups are pushed while live keeps others (for example its prices). Stock is never pushed unless chosen per product.',
							d.products_split,
							'keepmerge-push-staging-to-live'
						),
						number( d.products_split )
					) }
				</p>
			) }
			<div className="sgsp-field sgsp-mt-3">
				<label className="sgsp-field__label" htmlFor="sgsp-push-domain">
					{ sprintf(
						/* translators: %s: live site host. */
						__( 'Type %s to confirm', 'keepmerge-push-staging-to-live' ),
						host
					) }
				</label>
				<input
					id="sgsp-push-domain"
					className="sgsp-input"
					type="text"
					autoComplete="off"
					spellCheck="false"
					value={ typed }
					onChange={ ( event ) => setTyped( event.target.value ) }
					aria-invalid={ '' !== typed && ! matches }
				/>
			</div>
			<p className="sgsp-field__hint" id="sgsp-push-dryrun">
				{ __( 'Dry run only in this build: sending to live is not available yet, so nothing is sent.', 'keepmerge-push-staging-to-live' ) }
			</p>
		</Modal>
	);
}
