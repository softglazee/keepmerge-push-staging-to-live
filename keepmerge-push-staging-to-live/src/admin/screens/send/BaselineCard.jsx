import { createElement, Fragment, useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ExternalLink, GitCompareArrows, LoaderCircle, RefreshCw } from 'lucide-react';
import Alert from '../../components/Alert';
import Badge from '../../components/Badge';
import Card from '../../components/Card';
import DataTable from '../../components/DataTable';
import { errorMessage, getBaseline, runBaseline } from '../../api';
import { dateTime } from '../receive/time';

const FILTERS = [ 'strong', 'medium', 'none', 'duplicate' ];

const number = ( value ) => new Intl.NumberFormat().format( value || 0 );

/**
 * Words and tone for each match result.
 *
 * @param {string} confidence strong, medium, none, duplicate or excluded.
 * @return {Object} { label, note, tone }.
 */
function describe( confidence ) {
	switch ( confidence ) {
		case 'strong':
			return {
				label: __( 'Matched', 'keepmerge-push-staging-to-live' ),
				note: __( 'Linked to the same item on live.', 'keepmerge-push-staging-to-live' ),
				tone: 'success',
			};
		case 'medium':
			return {
				label: __( 'Same slug and date', 'keepmerge-push-staging-to-live' ),
				note: __( 'Not linked: the plan asks you to confirm each one.', 'keepmerge-push-staging-to-live' ),
				tone: 'warning',
			};
		case 'duplicate':
			return {
				label: __( 'Possible duplicates', 'keepmerge-push-staging-to-live' ),
				note: __( 'Live has another item with this slug: skipped unless you decide.', 'keepmerge-push-staging-to-live' ),
				tone: 'warning',
			};
		case 'excluded':
			return {
				label: __( 'Not compared', 'keepmerge-push-staging-to-live' ),
				note: __( 'The live site does not include this type.', 'keepmerge-push-staging-to-live' ),
				tone: 'neutral',
			};
		default:
			return {
				label: __( 'Only on this site', 'keepmerge-push-staging-to-live' ),
				note: __( 'A push would create it on live.', 'keepmerge-push-staging-to-live' ),
				tone: 'info',
			};
	}
}

/**
 * Label for an item kind or type in the sample table.
 *
 * @param {Object} row Table row.
 * @return {string} Label.
 */
function typeLabel( row ) {
	const kinds = {
		attachment: __( 'Media', 'keepmerge-push-staging-to-live' ),
		menu_item: __( 'Menu item', 'keepmerge-push-staging-to-live' ),
		menu: __( 'Menu', 'keepmerge-push-staging-to-live' ),
		pattern: __( 'Pattern', 'keepmerge-push-staging-to-live' ),
		navigation: __( 'Navigation', 'keepmerge-push-staging-to-live' ),
		template: __( 'Template', 'keepmerge-push-staging-to-live' ),
		template_part: __( 'Template part', 'keepmerge-push-staging-to-live' ),
		global_styles: __( 'Global styles', 'keepmerge-push-staging-to-live' ),
	};
	return kinds[ row.kind ] || row.type_label || row.type;
}

/**
 * A link to an item on one site, opening in a new tab.
 *
 * @param {Object} props
 * @param {string} props.href  URL ('' for none).
 * @param {number} props.id    Item ID.
 * @param {string} props.where Site name for screen readers.
 */
function ItemLink( { href, id, where } ) {
	if ( ! href ) {
		return <span className="sgsp-cell-muted">{ id > 0 ? `#${ id }` : '–' }</span>;
	}
	return (
		<a className="sgsp-itemlink" href={ href } target="_blank" rel="noopener noreferrer">
			{ `#${ id }` }
			<ExternalLink size={ 12 } aria-hidden="true" />
			<span className="screen-reader-text">
				{ sprintf(
					/* translators: %s: "this site" or "live". */
					__( 'Open on %s (new tab)', 'keepmerge-push-staging-to-live' ),
					where
				) }
			</span>
		</a>
	);
}

/**
 * The baseline of one active pairing (blueprint §6.4; F5): the clone date, the run in
 * time-boxed steps, the match counts and a sample table with links to each item on both sites.
 * Live is only read; this site keeps the result.
 *
 * @param {Object}   props
 * @param {Object}   props.pairing  Active pairing.
 * @param {Object}   props.initial  Stored baseline summary, or null.
 * @param {Function} props.notify   Toast.
 * @param {Function} [props.onDone] Called after a run finished (the plan card needs it).
 */
export default function BaselineCard( { pairing, initial, notify, onDone } ) {
	const [ baseline, setBaseline ] = useState( initial || null );
	const [ cloneDate, setCloneDate ] = useState( initial && initial.clone_date ? initial.clone_date : '' );
	const [ unknown, setUnknown ] = useState( !! ( initial && initial.clone_unknown ) );
	const [ formOpen, setFormOpen ] = useState( ! initial || 'done' !== initial.state );
	const [ running, setRunning ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ filter, setFilter ] = useState( 'strong' );
	const [ page, setPage ] = useState( 1 );
	const [ table, setTable ] = useState( null );
	const [ tableBusy, setTableBusy ] = useState( false );

	const done = baseline && 'done' === baseline.state;
	const idPrefix = `sgsp-baseline-${ pairing.uuid }`;

	const loadTable = useCallback(
		( nextFilter, nextPage ) => {
			setTableBusy( true );
			return getBaseline( pairing.uuid, nextFilter, nextPage )
				.then( ( result ) => setTable( result.table ) )
				.catch( ( err ) => notify( 'error', errorMessage( err ) ) )
				.finally( () => setTableBusy( false ) );
		},
		[ pairing.uuid, notify ]
	);

	useEffect( () => {
		if ( done ) {
			loadTable( filter, page );
		}
	}, [ done, filter, page, loadTable ] );

	// Steps until the run is done; each request stays inside the server's time budget.
	const step = ( params ) =>
		runBaseline( params ).then( ( result ) => {
			setBaseline( result );
			if ( 'running' === result.state ) {
				return step( { pairing: pairing.uuid, cursor: result.cursor } );
			}
			return result;
		} );

	const start = ( event ) => {
		event.preventDefault();
		setError( '' );
		setRunning( true );
		step( {
			pairing: pairing.uuid,
			restart: true,
			clone_date: unknown ? '' : cloneDate,
			clone_unknown: unknown,
		} )
			.then( ( result ) => {
				if ( 'done' === result.state ) {
					setFormOpen( false );
					setFilter( 'strong' );
					setPage( 1 );
					setTable( null );
					loadTable( 'strong', 1 );
					if ( onDone ) {
						onDone();
					}
					notify(
						'success',
						sprintf(
							/* translators: 1: matched items, 2: items compared. */
							__( 'Baseline done: %1$s of %2$s items matched.', 'keepmerge-push-staging-to-live' ),
							number( result.counts.strong ),
							number( result.compared )
						)
					);
				}
			} )
			.catch( ( err ) => {
				setError( errorMessage( err ) );
				// The server recorded the run as stopped; show the same.
				setBaseline( ( current ) => ( current && 'running' === current.state ? { ...current, state: 'failed' } : current ) );
			} )
			.finally( () => setRunning( false ) );
	};

	let badge = <Badge tone="neutral">{ __( 'Not run yet', 'keepmerge-push-staging-to-live' ) }</Badge>;
	if ( running ) {
		badge = <Badge tone="info" icon={ LoaderCircle }>{ __( 'Running', 'keepmerge-push-staging-to-live' ) }</Badge>;
	} else if ( done ) {
		badge = <Badge tone="success">{ __( 'Done', 'keepmerge-push-staging-to-live' ) }</Badge>;
	} else if ( baseline && 'failed' === baseline.state ) {
		badge = <Badge tone="danger">{ __( 'Stopped', 'keepmerge-push-staging-to-live' ) }</Badge>;
	}

	const progress = baseline && baseline.total > 0 ? Math.round( ( 100 * baseline.cursor ) / baseline.total ) : 0;

	const columns = [
		{
			key: 'item',
			label: __( 'Item', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => (
				<span className="sgsp-itemcell">
					<span className="sgsp-itemcell__title">{ row.title || __( '(no title)', 'keepmerge-push-staging-to-live' ) }</span>
					<span className="sgsp-itemcell__type">{ typeLabel( row ) }</span>
				</span>
			),
		},
		{
			key: 'local',
			label: __( 'On this site', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-cell-nowrap',
			render: ( row ) => (
				<ItemLink href={ row.local_url } id={ row.local_id } where={ __( 'this site', 'keepmerge-push-staging-to-live' ) } />
			),
		},
		{
			key: 'live',
			label: __( 'On live', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-cell-nowrap',
			render: ( row ) =>
				row.live_id > 0 ? (
					<ItemLink href={ row.live_url } id={ row.live_id } where={ __( 'live', 'keepmerge-push-staging-to-live' ) } />
				) : (
					<span className="sgsp-cell-muted">{ __( 'Not on live', 'keepmerge-push-staging-to-live' ) }</span>
				),
		},
	];

	return (
		<Card
			icon={ GitCompareArrows }
			title={ __( 'Baseline', 'keepmerge-push-staging-to-live' ) }
			description={ __(
				'Matches the items of both sites once, before the first plan. It reads the live site and writes nothing there.',
				'keepmerge-push-staging-to-live'
			) }
			aside={ badge }
		>
			{ error && (
				<Alert tone="danger" title={ __( 'The baseline could not finish', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ error }</p>
				</Alert>
			) }

			{ baseline && 'failed' === baseline.state && ! error && baseline.error && (
				<Alert tone="danger" title={ __( 'The last run stopped', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ baseline.error.message }</p>
				</Alert>
			) }

			{ running && baseline && (
				<div className="sgsp-progress" role="status">
					<div className="sgsp-progress__label">
						{ sprintf(
							/* translators: 1: items matched so far, 2: all items. */
							__( 'Matching items: %1$s of %2$s', 'keepmerge-push-staging-to-live' ),
							number( baseline.cursor ),
							number( baseline.total )
						) }
					</div>
					<div className="sgsp-progress__track" aria-hidden="true">
						<div className="sgsp-progress__bar" style={ { width: `${ progress }%` } } />
					</div>
				</div>
			) }

			{ done && ! running && (
				<Fragment>
					<p className="sgsp-baseline__lead">
						<strong>
							{ sprintf(
								/* translators: 1: matched items, 2: items compared. */
								__( '%1$s of %2$s items matched', 'keepmerge-push-staging-to-live' ),
								number( baseline.counts.strong ),
								number( baseline.compared )
							) }
						</strong>
						{ null !== baseline.strong_rate && ` (${ baseline.strong_rate }%)` }
						{ ' · ' }
						{ baseline.clone_unknown
							? __( 'Clone date: not known', 'keepmerge-push-staging-to-live' )
							: sprintf(
									/* translators: %s: date. */
									__( 'Clone date: %s', 'keepmerge-push-staging-to-live' ),
									baseline.clone_date
							  ) }
						{ ' · ' }
						{ sprintf(
							/* translators: %s: date and time. */
							__( 'Run %s', 'keepmerge-push-staging-to-live' ),
							dateTime( baseline.finished_at )
						) }
					</p>

					<ul className="sgsp-stats" aria-label={ __( 'Baseline results', 'keepmerge-push-staging-to-live' ) }>
						{ FILTERS.map( ( key ) => {
							const info = describe( key );
							return (
								<li key={ key } className={ `sgsp-stat sgsp-stat--${ info.tone }` }>
									<span className="sgsp-stat__value">{ number( baseline.counts[ key ] ) }</span>
									<span className="sgsp-stat__label">{ info.label }</span>
									<span className="sgsp-stat__note">{ info.note }</span>
								</li>
							);
						} ) }
					</ul>

					{ baseline.counts.excluded > 0 && (
						<p className="sgsp-field__hint">
							{ sprintf(
								/* translators: %s: number of items. */
								_n(
									'%s item was not compared: the live site does not include its type.',
									'%s items were not compared: the live site does not include their types.',
									baseline.counts.excluded,
									'keepmerge-push-staging-to-live'
								),
								number( baseline.counts.excluded )
							) }
						</p>
					) }
					{ ! baseline.clone_unknown ? null : (
						<p className="sgsp-field__hint">
							{ __(
								'Without a clone date, every matched item that differs between the sites becomes a conflict in the first plan.',
								'keepmerge-push-staging-to-live'
							) }
						</p>
					) }

					<fieldset className="sgsp-segmented sgsp-segmented--stack">
						<legend className="sgsp-segmented__legend">{ __( 'Show', 'keepmerge-push-staging-to-live' ) }</legend>
						<div className="sgsp-segmented__options">
							{ FILTERS.map( ( key ) => (
								<label key={ key } className={ `sgsp-segmented__option${ filter === key ? ' is-selected' : '' }` } htmlFor={ `${ idPrefix }-filter-${ key }` }>
									<input
										id={ `${ idPrefix }-filter-${ key }` }
										type="radio"
										name={ `${ idPrefix }-filter` }
										value={ key }
										checked={ filter === key }
										onChange={ () => {
											setFilter( key );
											setPage( 1 );
										} }
									/>
									{ `${ describe( key ).label } (${ number( baseline.counts[ key ] ) })` }
								</label>
							) ) }
						</div>
					</fieldset>

					<div className="sgsp-mt-3">
						{ table && 0 === table.total && ! tableBusy ? (
							<p className="sgsp-baseline__empty">{ describe( filter ).note } { __( 'No items in this group.', 'keepmerge-push-staging-to-live' ) }</p>
						) : (
							<DataTable
								caption={ describe( filter ).label }
								columns={ columns }
								rows={ table ? table.rows : [] }
								getRowKey={ ( row ) => row.uuid }
								isLoading={ tableBusy || ! table }
								skeletonRows={ 4 }
							/>
						) }
						{ table && table.total > table.per_page && (
							<div className="sgsp-pager">
								<span>
									{ sprintf(
										/* translators: 1: first row, 2: last row, 3: all rows. */
										__( '%1$s–%2$s of %3$s', 'keepmerge-push-staging-to-live' ),
										number( ( table.page - 1 ) * table.per_page + 1 ),
										number( Math.min( table.total, table.page * table.per_page ) ),
										number( table.total )
									) }
								</span>
								<span className="sgsp-pager__buttons">
									<button
										type="button"
										className="sgsp-button sgsp-button--secondary sgsp-button--small"
										disabled={ table.page <= 1 || tableBusy }
										onClick={ () => setPage( table.page - 1 ) }
									>
										{ __( 'Previous', 'keepmerge-push-staging-to-live' ) }
									</button>
									<button
										type="button"
										className="sgsp-button sgsp-button--secondary sgsp-button--small"
										disabled={ table.page * table.per_page >= table.total || tableBusy }
										onClick={ () => setPage( table.page + 1 ) }
									>
										{ __( 'Next', 'keepmerge-push-staging-to-live' ) }
									</button>
								</span>
							</div>
						) }
					</div>

					{ ! formOpen && (
						<div className="sgsp-mt-4">
							<button type="button" className="sgsp-button sgsp-button--secondary" onClick={ () => setFormOpen( true ) }>
								<RefreshCw size={ 15 } aria-hidden="true" />
								{ __( 'Run the baseline again', 'keepmerge-push-staging-to-live' ) }
							</button>
						</div>
					) }
				</Fragment>
			) }

			{ formOpen && ! running && (
				<form
					className={ done ? 'sgsp-baseline__form sgsp-baseline__form--again' : 'sgsp-baseline__form' }
					onSubmit={ start }
				>
					<div className="sgsp-field">
						<label className="sgsp-field__label" htmlFor={ `${ idPrefix }-date` }>
							{ __( 'When was this site copied from live?', 'keepmerge-push-staging-to-live' ) }
						</label>
						<input
							id={ `${ idPrefix }-date` }
							className="sgsp-input sgsp-input--date"
							type="date"
							value={ cloneDate }
							disabled={ unknown }
							required={ ! unknown }
							max={ new Date().toISOString().slice( 0, 10 ) }
							aria-describedby={ `${ idPrefix }-date-hint` }
							onChange={ ( event ) => setCloneDate( event.target.value ) }
						/>
						<p className="sgsp-field__hint" id={ `${ idPrefix }-date-hint` }>
							{ __(
								'The first plan uses it: an item changed after this date on only one site is pushed or kept by date, and the plan marks those decisions.',
								'keepmerge-push-staging-to-live'
							) }
						</p>
					</div>
					<label className="sgsp-checkbox sgsp-mt-3" htmlFor={ `${ idPrefix }-unknown` }>
						<input id={ `${ idPrefix }-unknown` } type="checkbox" checked={ unknown } onChange={ ( event ) => setUnknown( event.target.checked ) } />
						<span>
							{ __( 'I don\'t know the date', 'keepmerge-push-staging-to-live' ) }
							<span className="sgsp-field__hint sgsp-block">
								{ __( 'Then every matched item that differs becomes a conflict for you to decide.', 'keepmerge-push-staging-to-live' ) }
							</span>
						</span>
					</label>
					<div className="sgsp-baseline__actions">
						<button type="submit" className="sgsp-button sgsp-button--primary" disabled={ ! unknown && '' === cloneDate }>
							<GitCompareArrows size={ 15 } aria-hidden="true" />
							{ done
								? __( 'Run the baseline again', 'keepmerge-push-staging-to-live' )
								: __( 'Run the baseline', 'keepmerge-push-staging-to-live' ) }
						</button>
						{ done && (
							<button type="button" className="sgsp-button sgsp-button--link" onClick={ () => setFormOpen( false ) }>
								{ __( 'Cancel', 'keepmerge-push-staging-to-live' ) }
							</button>
						) }
					</div>
				</form>
			) }
		</Card>
	);
}
