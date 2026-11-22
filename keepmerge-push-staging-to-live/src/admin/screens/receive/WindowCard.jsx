import { createElement, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { LoaderCircle, Lock, RadioTower } from 'lucide-react';
import Badge from '../../components/Badge';
import Card from '../../components/Card';
import { countdown, dateTime } from './time';

/**
 * The receive window: closed by default, opened for 1, 8 or 24 hours, closes by itself.
 *
 * @param {Object}   props
 * @param {Object}   props.window  { open, closes_at, choices, default_hours }.
 * @param {number}   props.now     Server-aligned now (Unix seconds).
 * @param {boolean}  props.busy    A window request is running.
 * @param {boolean}  props.disabled Receiving is not possible here (multisite).
 * @param {Function} props.onOpen  Receives hours.
 * @param {Function} props.onClose Closes now.
 */
export default function WindowCard( { window: win, now, busy, disabled, onOpen, onClose } ) {
	const [ hours, setHours ] = useState( win.default_hours || 1 );
	const isOpen = win.open;
	const left = isOpen ? win.closes_at - now : 0;

	return (
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
			{ isOpen ? (
				<div>
					<p className="sgsp-countdown">
						<span className="sgsp-countdown__label">
							{ __( 'Closes in', 'keepmerge-push-staging-to-live' ) }
						</span>
						<time
							className="sgsp-countdown__value"
							dateTime={ new Date( win.closes_at * 1000 ).toISOString() }
						>
							{ countdown( left ) }
						</time>
					</p>
					<p className="sgsp-card__desc sgsp-m-0">
						{ sprintf(
							/* translators: %s: date and time the window closes. */
							__(
								'Approved staging sites can reach this site until %s. Then the window closes by itself and the remote routes disappear.',
								'keepmerge-push-staging-to-live'
							),
							dateTime( win.closes_at )
						) }
					</p>
					<button
						type="button"
						className="sgsp-button sgsp-button--secondary sgsp-mt-4"
						onClick={ onClose }
						disabled={ busy }
					>
						{ busy ? (
							<LoaderCircle size={ 15 } aria-hidden="true" className="sgsp-animate-spin" />
						) : (
							<Lock size={ 15 } aria-hidden="true" />
						) }
						{ __( 'Close now', 'keepmerge-push-staging-to-live' ) }
					</button>
				</div>
			) : (
				<div>
					<p className="sgsp-card__desc sgsp-m-0">
						{ __(
							'While the window is closed this site has no routes a staging site can call. Open it when you pair or push; it closes by itself.',
							'keepmerge-push-staging-to-live'
						) }
					</p>
					<fieldset className="sgsp-segmented" disabled={ busy || disabled }>
						<legend className="sgsp-segmented__legend">
							{ __( 'Keep it open for', 'keepmerge-push-staging-to-live' ) }
						</legend>
						<div className="sgsp-segmented__options">
							{ win.choices.map( ( choice ) => (
								<label
									key={ choice }
									className={ `sgsp-segmented__option${ choice === hours ? ' is-selected' : '' }` }
									htmlFor={ `sgsp-window-hours-${ choice }` }
								>
									<input
										id={ `sgsp-window-hours-${ choice }` }
										type="radio"
										name="sgsp-window-hours"
										value={ choice }
										checked={ choice === hours }
										onChange={ () => setHours( choice ) }
									/>
									<span>
										{ sprintf(
											/* translators: %d: number of hours. */
											_n( '%d hour', '%d hours', choice, 'keepmerge-push-staging-to-live' ),
											choice
										) }
									</span>
								</label>
							) ) }
						</div>
					</fieldset>
					<button
						type="button"
						className="sgsp-button sgsp-button--primary sgsp-mt-4"
						onClick={ () => onOpen( hours ) }
						disabled={ busy || disabled }
					>
						{ busy ? (
							<LoaderCircle size={ 15 } aria-hidden="true" className="sgsp-animate-spin" />
						) : (
							<RadioTower size={ 15 } aria-hidden="true" />
						) }
						{ __( 'Open receive window', 'keepmerge-push-staging-to-live' ) }
					</button>
				</div>
			) }
		</Card>
	);
}
