import { createElement, Fragment, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ListChecks, LoaderCircle, RefreshCw, Send } from 'lucide-react';
import Alert from '../../components/Alert';
import Badge from '../../components/Badge';
import Card from '../../components/Card';
import ConfirmDialog from '../../components/ConfirmDialog';
import EmptyState from '../../components/EmptyState';
import { createPlan, decidePlan, errorMessage, stepPlan } from '../../api';
import CodeReport from './CodeReport';
import DiffDrawer from './DiffDrawer';
import PlanRows from './PlanRows';
import { SummaryPanel, WarningsPanel } from './PlanSummary';
import PushDialog from './PushDialog';
import { number, scopeList, stageLabel, tabList } from './plan-labels';

const LIST_TABS = [ 'content', 'woocommerce', 'media', 'menus', 'site_editor', 'options' ];

/**
 * Sub-tabs of the diff: the WAI-ARIA tabs pattern (arrow keys move and select), each with its
 * count in words for screen readers.
 *
 * @param {Object}   props
 * @param {Array}    props.tabs     [ { id, label, count, alert } ].
 * @param {string}   props.selected Selected ID.
 * @param {Function} props.onSelect Receives an ID.
 * @param {string}   props.idPrefix Prefix for IDs.
 */
function SubTabs( { tabs, selected, onSelect, idPrefix } ) {
	const refs = useRef( {} );
	const move = ( event, index ) => {
		const keys = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 };
		let next = null;
		if ( undefined !== keys[ event.key ] ) {
			next = ( index + keys[ event.key ] + tabs.length ) % tabs.length;
		} else if ( 'Home' === event.key ) {
			next = 0;
		} else if ( 'End' === event.key ) {
			next = tabs.length - 1;
		}
		if ( null === next ) {
			return;
		}
		event.preventDefault();
		onSelect( tabs[ next ].id );
		if ( refs.current[ tabs[ next ].id ] ) {
			refs.current[ tabs[ next ].id ].focus();
		}
	};

	return (
		<div className="sgsp-subtabs" role="tablist" aria-label={ __( 'Plan details', 'keepmerge-push-staging-to-live' ) }>
			{ tabs.map( ( tab, index ) => (
				<button
					key={ tab.id }
					type="button"
					role="tab"
					id={ `${ idPrefix }-tab-${ tab.id }` }
					aria-selected={ tab.id === selected }
					aria-controls={ `${ idPrefix }-panel` }
					tabIndex={ tab.id === selected ? 0 : -1 }
					className={ `sgsp-subtab${ tab.alert ? ' has-alert' : '' }` }
					ref={ ( node ) => {
						refs.current[ tab.id ] = node;
					} }
					onClick={ () => onSelect( tab.id ) }
					onKeyDown={ ( event ) => move( event, index ) }
				>
					{ tab.label }
					{ null !== tab.count && (
						<span className="sgsp-subtab__count">
							{ number( tab.count ) }
							{ tab.alert && <span className="screen-reader-text">{ ` ${ tab.alert }` }</span> }
						</span>
					) }
				</button>
			) ) }
		</div>
	);
}

/**
 * The plan of one active pairing (blueprint F6, F7; §16 Send tab): scope, a dry run in
 * time-boxed steps, then the diff with its sub-tabs, conflict decisions and the push dialog.
 *
 * @param {Object}   props
 * @param {Object}   props.pairing     Pairing.
 * @param {Object}   props.baseline    Baseline summary, or null.
 * @param {Object}   props.initialPlan The newest usable plan, or null.
 * @param {Function} props.notify      Toast.
 */
export default function PlanCard( { pairing, baseline, initialPlan, notify } ) {
	const [ plan, setPlan ] = useState( initialPlan || null );
	const [ running, setRunning ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ scopeMode, setScopeMode ] = useState( 'all' );
	const [ groups, setGroups ] = useState( [] );
	const [ formOpen, setFormOpen ] = useState( ! initialPlan );
	const [ tab, setTab ] = useState( 'summary' );
	const [ diffRow, setDiffRow ] = useState( null );
	const [ pushOpen, setPushOpen ] = useState( false );
	const [ bulkOpen, setBulkOpen ] = useState( false );
	const [ version, setVersion ] = useState( 0 );
	const idPrefix = `sgsp-plan-${ pairing.uuid }`;

	// The minutes left are shown in whole minutes: a tick every 30 seconds is enough.
	const [ now, setNow ] = useState( Date.now() / 1000 );
	useEffect( () => {
		const id = window.setInterval( () => setNow( Date.now() / 1000 ), 30000 );
		return () => window.clearInterval( id );
	}, [] );
	const expired = !! plan && ( plan.expired || ( plan.expires_at && now > plan.expires_at ) );

	const step = useCallback(
		( current ) =>
			stepPlan( current.id, current.steps ).then( ( next ) => {
				setPlan( next );
				return 'planning' === next.state ? step( next ) : next;
			} ),
		[]
	);

	const start = ( event ) => {
		event.preventDefault();
		setError( '' );
		setRunning( true );
		createPlan( { pairing: pairing.uuid, types: 'all' === scopeMode ? [] : groups } )
			.then( ( created ) => {
				setPlan( created );
				return step( created );
			} )
			.then( ( done ) => {
				setFormOpen( false );
				setTab( 'summary' );
				setVersion( ( v ) => v + 1 );
				const s = done.summary;
				notify(
					'success',
					s.undecided > 0
						? sprintf(
								/* translators: 1: items that differ, 2: conflicts. */
								__( 'Plan ready: %1$s items differ, %2$s conflicts to decide.', 'keepmerge-push-staging-to-live' ),
								number( s.totals.changes ),
								number( s.undecided )
						  )
						: sprintf(
								/* translators: %s: items that differ. */
								__( 'Plan ready: %s items differ.', 'keepmerge-push-staging-to-live' ),
								number( s.totals.changes )
						  )
				);
			} )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => setRunning( false ) );
	};

	const bulkKeepLive = () => {
		setBulkOpen( false );
		decidePlan( plan.id, { bulk: 'keep_live_remaining' } )
			.then( ( next ) => {
				setPlan( next );
				setVersion( ( v ) => v + 1 );
				notify( 'success', __( 'Live keeps its version of every remaining conflict.', 'keepmerge-push-staging-to-live' ) );
			} )
			.catch( ( err ) => notify( 'error', errorMessage( err ) ) );
	};

	const baselineDone = !! baseline && 'done' === baseline.state;
	const planned = !! plan && 'planned' === plan.state && ! expired;
	const summary = planned ? plan.summary : null;

	let badge = <Badge tone="neutral">{ __( 'No plan yet', 'keepmerge-push-staging-to-live' ) }</Badge>;
	if ( running ) {
		badge = <Badge tone="info" icon={ LoaderCircle }>{ __( 'Planning', 'keepmerge-push-staging-to-live' ) }</Badge>;
	} else if ( expired ) {
		badge = <Badge tone="warning">{ __( 'Expired', 'keepmerge-push-staging-to-live' ) }</Badge>;
	} else if ( summary && summary.undecided > 0 ) {
		badge = (
			<Badge tone="danger">
				{ sprintf(
					/* translators: %s: number of conflicts. */
					_n( '%s conflict to decide', '%s conflicts to decide', summary.undecided, 'keepmerge-push-staging-to-live' ),
					number( summary.undecided )
				) }
			</Badge>
		);
	} else if ( summary ) {
		badge = <Badge tone="success">{ __( 'Ready', 'keepmerge-push-staging-to-live' ) }</Badge>;
	}

	const card = ( body ) => (
		<Card
			icon={ ListChecks }
			title={ __( 'Plan and push', 'keepmerge-push-staging-to-live' ) }
			description={ __( 'A dry run: compares every item of this site with live and with the last push. Live is only read; nothing is sent until you push.', 'keepmerge-push-staging-to-live' ) }
			aside={ badge }
		>
			{ body }
		</Card>
	);

	if ( ! baselineDone ) {
		return card(
			<EmptyState
				title={ __( 'Run the baseline first', 'keepmerge-push-staging-to-live' ) }
				text={ __( 'The plan needs to know which items already match on live. Run the baseline above, then create a plan here.', 'keepmerge-push-staging-to-live' ) }
			/>
		);
	}

	const scopeForm = (
		<form className={ planned ? 'sgsp-planform sgsp-planform--again' : 'sgsp-planform' } onSubmit={ start }>
			<fieldset className="sgsp-segmented sgsp-segmented--stack sgsp-mt-0">
				<legend className="sgsp-segmented__legend">{ __( 'What to compare', 'keepmerge-push-staging-to-live' ) }</legend>
				<div className="sgsp-segmented__options">
					{ [
						[ 'all', __( 'Everything included in Settings', 'keepmerge-push-staging-to-live' ) ],
						[ 'some', __( 'Only some kinds', 'keepmerge-push-staging-to-live' ) ],
					].map( ( [ value, label ] ) => (
						<label key={ value } className={ `sgsp-segmented__option${ scopeMode === value ? ' is-selected' : '' }` }>
							<input type="radio" name={ `${ idPrefix }-scope` } value={ value } checked={ scopeMode === value } onChange={ () => setScopeMode( value ) } />
							{ label }
						</label>
					) ) }
				</div>
			</fieldset>
			{ 'some' === scopeMode && (
				<fieldset className="sgsp-choices sgsp-mt-3">
					<legend className="screen-reader-text">{ __( 'Kinds to compare', 'keepmerge-push-staging-to-live' ) }</legend>
					{ scopeList().map( ( option ) => (
						<label key={ option.id } className="sgsp-checkbox">
							<input
								type="checkbox"
								checked={ groups.includes( option.id ) }
								onChange={ ( event ) =>
									setGroups( ( current ) => ( event.target.checked ? [ ...current, option.id ] : current.filter( ( id ) => id !== option.id ) ) )
								}
							/>
							<span>{ option.label }</span>
						</label>
					) ) }
					<p className="sgsp-field__hint">
						{ __( 'New items the chosen ones depend on (a featured image, a parent page) are pulled in even when their kind is not chosen.', 'keepmerge-push-staging-to-live' ) }
					</p>
				</fieldset>
			) }
			<div className="sgsp-baseline__actions">
				<button type="submit" className="sgsp-button sgsp-button--primary" disabled={ running || ( 'some' === scopeMode && 0 === groups.length ) }>
					<ListChecks size={ 15 } aria-hidden="true" />
					{ planned || expired ? __( 'Plan again', 'keepmerge-push-staging-to-live' ) : __( 'Create the plan', 'keepmerge-push-staging-to-live' ) }
				</button>
				{ planned && (
					<button type="button" className="sgsp-button sgsp-button--link" onClick={ () => setFormOpen( false ) }>
						{ __( 'Cancel', 'keepmerge-push-staging-to-live' ) }
					</button>
				) }
			</div>
		</form>
	);

	if ( running && plan ) {
		const p = plan.progress || {};
		const percent = Math.round( ( 100 * ( p.stage_index + ( 'export' === plan.stage && p.total ? p.done / p.total : 0 ) ) ) / Math.max( 1, p.stages - 1 ) );
		return card(
			<div className="sgsp-progress" role="status">
				<div className="sgsp-progress__label">{ stageLabel( plan ) }</div>
				<div className="sgsp-progress__track" aria-hidden="true">
					<div className="sgsp-progress__bar" style={ { width: `${ Math.min( 100, percent ) }%` } } />
				</div>
			</div>
		);
	}

	if ( ! planned ) {
		return card(
			<Fragment>
				{ error && (
					<Alert tone="danger" title={ __( 'The plan could not finish', 'keepmerge-push-staging-to-live' ) }>
						<p className="sgsp-m-0">{ error }</p>
					</Alert>
				) }
				{ expired && (
					<Alert tone="warning" title={ __( 'This plan expired', 'keepmerge-push-staging-to-live' ) }>
						<p className="sgsp-m-0">{ __( 'Plans are valid for 60 minutes, because either site may have changed since. Create a new plan.', 'keepmerge-push-staging-to-live' ) }</p>
					</Alert>
				) }
				{ scopeForm }
			</Fragment>
		);
	}

	const woo = summary.woocommerce.staging && summary.woocommerce.live;
	const listTabs = LIST_TABS.filter( ( id ) => 'woocommerce' !== id || woo );
	const warningCount = Object.keys( summary.warnings ).reduce( ( sum, code ) => sum + summary.warnings[ code ].count, 0 );
	const tabs = tabList()
		.filter( ( entry ) => 'woocommerce' !== entry.id || woo )
		.map( ( entry ) => {
			const counts = summary.by_tab[ entry.id ];
			let count = null;
			let alert = '';
			if ( counts ) {
				count = counts.changes;
				if ( counts.conflicts > 0 ) {
					alert = sprintf(
						/* translators: %s: number of conflicts. */
						_n( '(%s conflict)', '(%s conflicts)', counts.conflicts, 'keepmerge-push-staging-to-live' ),
						number( counts.conflicts )
					);
				}
			} else if ( 'warnings' === entry.id ) {
				count = warningCount;
			}
			return { ...entry, count, alert };
		} );
	const selected = tabs.some( ( entry ) => entry.id === tab ) ? tab : 'summary';
	const host = pairing.live.host;
	const minutes = Math.max( 0, Math.round( ( plan.expires_at - now ) / 60 ) );

	let pushReason = '';
	if ( summary.undecided > 0 ) {
		pushReason = sprintf(
			/* translators: %s: number of conflicts. */
			_n( 'Decide %s conflict first.', 'Decide %s conflicts first.', summary.undecided, 'keepmerge-push-staging-to-live' ),
			number( summary.undecided )
		);
	} else if ( summary.blocked > 0 ) {
		pushReason = sprintf(
			/* translators: %s: number of items. */
			_n( '%s item waits for a dependency.', '%s items wait for a dependency.', summary.blocked, 'keepmerge-push-staging-to-live' ),
			number( summary.blocked )
		);
	} else if ( 0 === summary.sent ) {
		pushReason = __( 'Nothing to send: live already has every change you chose.', 'keepmerge-push-staging-to-live' );
	}

	const stats = [
		{ key: 'create', value: summary.dialog.create, label: __( 'To create', 'keepmerge-push-staging-to-live' ), tone: 'info' },
		{ key: 'update', value: summary.dialog.update, label: __( 'To update', 'keepmerge-push-staging-to-live' ), tone: 'info' },
		{ key: 'keep', value: summary.dialog.keep_live, label: __( 'Kept on live', 'keepmerge-push-staging-to-live' ), tone: 'neutral' },
		{ key: 'conflicts', value: summary.undecided, label: __( 'Conflicts to decide', 'keepmerge-push-staging-to-live' ), tone: summary.undecided > 0 ? 'danger' : 'success' },
		{ key: 'same', value: summary.by_class.unchanged, label: __( 'Unchanged', 'keepmerge-push-staging-to-live' ), tone: 'success' },
	];

	let panel = null;
	if ( 'summary' === selected ) {
		panel = <SummaryPanel summary={ summary } tabs={ listTabs } onTab={ setTab } />;
	} else if ( 'code' === selected ) {
		panel = <CodeReport pairing={ pairing } />;
	} else if ( 'warnings' === selected ) {
		panel = (
			<WarningsPanel summary={ summary }>
				{ warningCount > 0 && (
					<PlanRows plan={ plan } tab="" warningsOnly onPlan={ setPlan } onOpenDiff={ setDiffRow } notify={ notify } version={ version } />
				) }
			</WarningsPanel>
		);
	} else {
		panel = (
			<PlanRows
				key={ selected }
				plan={ plan }
				tab={ selected }
				initialFilter={ summary.by_tab[ selected ] && summary.by_tab[ selected ].changes > 0 ? 'changes' : 'all' }
				onPlan={ setPlan }
				onOpenDiff={ setDiffRow }
				notify={ notify }
				version={ version }
			/>
		);
	}

	return card(
		<Fragment>
			<p className="sgsp-baseline__lead">
				<strong>
					{ sprintf(
						/* translators: 1: items that differ, 2: items compared. */
						__( '%1$s of %2$s items differ', 'keepmerge-push-staging-to-live' ),
						number( summary.totals.changes ),
						number( summary.totals.in_scope )
					) }
				</strong>
				{ ' · ' }
				{ summary.clone.unknown
					? __( 'Clone date: not known', 'keepmerge-push-staging-to-live' )
					: sprintf(
							/* translators: %s: date. */
							__( 'Clone date: %s', 'keepmerge-push-staging-to-live' ),
							summary.clone.date
					  ) }
				{ ' · ' }
				{ sprintf(
					/* translators: %s: minutes. */
					_n( 'valid for %s more minute', 'valid for %s more minutes', minutes, 'keepmerge-push-staging-to-live' ),
					number( minutes )
				) }
			</p>

			<ul className="sgsp-stats" aria-label={ __( 'Plan totals', 'keepmerge-push-staging-to-live' ) }>
				{ stats.map( ( stat ) => (
					<li key={ stat.key } className={ `sgsp-stat sgsp-stat--${ stat.tone }` }>
						<span className="sgsp-stat__value">{ number( stat.value ) }</span>
						<span className="sgsp-stat__label">{ stat.label }</span>
					</li>
				) ) }
			</ul>

			<div className="sgsp-decisionbar">
				<div className="sgsp-decisionbar__text" role="status">
					{ pushReason ||
						sprintf(
							/* translators: %s: number of items. */
							_n( 'Ready: %s item would be sent.', 'Ready: %s items would be sent.', summary.sent, 'keepmerge-push-staging-to-live' ),
							number( summary.sent )
						) }
				</div>
				<div className="sgsp-decisionbar__actions">
					{ summary.undecided > 0 && (
						<button type="button" className="sgsp-button sgsp-button--secondary" onClick={ () => setBulkOpen( true ) }>
							{ __( 'Keep live for all remaining', 'keepmerge-push-staging-to-live' ) }
						</button>
					) }
					<button type="button" className="sgsp-button sgsp-button--secondary" onClick={ () => setFormOpen( true ) }>
						<RefreshCw size={ 15 } aria-hidden="true" />
						{ __( 'Plan again', 'keepmerge-push-staging-to-live' ) }
					</button>
					<button
						type="button"
						className="sgsp-button sgsp-button--primary"
						disabled={ !! pushReason }
						aria-describedby={ `${ idPrefix }-pushreason` }
						onClick={ () => setPushOpen( true ) }
					>
						<Send size={ 15 } aria-hidden="true" />
						{ __( 'Push…', 'keepmerge-push-staging-to-live' ) }
					</button>
					<span id={ `${ idPrefix }-pushreason` } className="screen-reader-text">
						{ pushReason }
					</span>
				</div>
			</div>

			{ formOpen && scopeForm }

			<SubTabs tabs={ tabs } selected={ selected } onSelect={ setTab } idPrefix={ idPrefix } />
			<div
				className="sgsp-subpanel"
				role="tabpanel"
				id={ `${ idPrefix }-panel` }
				aria-labelledby={ `${ idPrefix }-tab-${ selected }` }
				tabIndex={ 0 }
			>
				{ panel }
			</div>

			{ diffRow && <DiffDrawer planId={ plan.id } row={ diffRow } onClose={ () => setDiffRow( null ) } /> }
			{ pushOpen && <PushDialog summary={ summary } host={ host } onClose={ () => setPushOpen( false ) } /> }
			{ bulkOpen && (
				<ConfirmDialog
					title={ __( 'Keep live for all remaining conflicts?', 'keepmerge-push-staging-to-live' ) }
					confirmLabel={ __( 'Keep live for all remaining', 'keepmerge-push-staging-to-live' ) }
					onConfirm={ bulkKeepLive }
					onCancel={ () => setBulkOpen( false ) }
				>
					<p>
						{ sprintf(
							/* translators: %s: number of conflicts. */
							_n(
								'%s undecided conflict will keep live\'s version (items deleted on live stay deleted). Conflicts you already decided are not changed.',
								'%s undecided conflicts will keep live\'s version (items deleted on live stay deleted). Conflicts you already decided are not changed.',
								summary.undecided,
								'keepmerge-push-staging-to-live'
							),
							number( summary.undecided )
						) }
					</p>
				</ConfirmDialog>
			) }
		</Fragment>
	);
}
