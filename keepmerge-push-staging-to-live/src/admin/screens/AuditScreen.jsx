import { createElement, useCallback, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ChevronLeft, ChevronRight, Download, LoaderCircle, ScrollText } from 'lucide-react';
import Badge, { toneFor } from '../components/Badge';
import Card from '../components/Card';
import DataTable from '../components/DataTable';
import EmptyState from '../components/EmptyState';
import LoadError from '../components/LoadError';
import PanelHead from '../components/PanelHead';
import { errorMessage, getAudit, getAuditExportChunk } from '../api';
import { actorLabel, resultLabel } from './labels';

const NO_FILTERS = { event: '', result: '', from: '', to: '' };

/**
 * Details of an event as "key: value" lines, rendered as text.
 *
 * @param {Object} props
 * @param {Object} props.details Details object.
 */
function Details( { details } ) {
	const keys = Object.keys( details || {} );

	if ( ! keys.length ) {
		return <span className="sgsp-cell-muted">—</span>;
	}

	return (
		<ul className="sgsp-details">
			{ keys.map( ( key ) => {
				const value = details[ key ];
				let text = String( value );
				if ( Array.isArray( value ) ) {
					text = value.join( ', ' );
				} else if ( 'object' === typeof value && null !== value ) {
					text = JSON.stringify( value );
				}
				return (
					<li key={ key }>
						<span className="sgsp-details__key">{ key }:</span> { text }
					</li>
				);
			} ) }
		</ul>
	);
}

/**
 * Starts a browser download of text, without putting anything in a URL query string.
 *
 * @param {string} text     File content.
 * @param {string} filename File name.
 */
function download( text, filename ) {
	const blob = new window.Blob( [ text ], { type: 'text/csv;charset=utf-8' } );
	const url = window.URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	window.setTimeout( () => window.URL.revokeObjectURL( url ), 1000 );
}

/**
 * Audit log tab: filterable list and CSV export.
 *
 * @param {Object}   props
 * @param {Function} props.notify Shows a toast.
 */
export default function AuditScreen( { notify } ) {
	const [ filters, setFilters ] = useState( NO_FILTERS );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ events, setEvents ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ isExporting, setIsExporting ] = useState( false );

	const load = useCallback( () => {
		setIsLoading( true );
		setError( '' );
		return getAudit( filters, page )
			.then( ( response ) => {
				setData( response );
				if ( response.events ) {
					setEvents( response.events );
				}
			} )
			.catch( ( err ) => setError( errorMessage( err ) ) )
			.finally( () => setIsLoading( false ) );
	}, [ filters, page ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const filtered = Object.keys( filters ).some( ( key ) => '' !== filters[ key ] );

	function update( key, value ) {
		setFilters( ( current ) => ( { ...current, [ key ]: value } ) );
		setPage( 1 );
	}

	async function exportCsv() {
		setIsExporting( true );
		try {
			let before = 0;
			let csv = '';
			let rows = 0;
			let filename = 'keepmerge-audit.csv';
			// Chunks are paged by ID on the server, so each request stays small.
			for ( let guard = 0; guard < 500; guard++ ) {
				const chunk = await getAuditExportChunk( filters, before );
				csv += chunk.csv;
				rows += chunk.rows;
				filename = chunk.filename || filename;
				if ( ! chunk.has_more ) {
					break;
				}
				before = chunk.next_before;
			}
			download( csv, filename );
			notify(
				'success',
				sprintf(
					/* translators: %d: number of exported events. */
					_n( 'Exported %d event.', 'Exported %d events.', rows, 'keepmerge-push-staging-to-live' ),
					rows
				)
			);
		} catch ( err ) {
			notify( 'error', errorMessage( err ) );
		} finally {
			setIsExporting( false );
		}
	}

	const columns = [
		{
			key: 'when',
			label: __( 'When', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-cell-nowrap',
			render: ( row ) => (
				<span title={ `${ row.created_gmt } UTC` }>{ row.created }</span>
			),
		},
		{
			key: 'event',
			label: __( 'Event', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => (
				<span>
					<strong>{ row.event_label }</strong>
					<br />
					<span className="sgsp-cell-muted">{ row.event }</span>
				</span>
			),
		},
		{
			key: 'result',
			label: __( 'Result', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => (
				<Badge tone={ toneFor( row.result ) }>{ resultLabel( row.result ) }</Badge>
			),
		},
		{
			key: 'actor',
			label: __( 'By', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => actorLabel( row ),
		},
		{
			key: 'ip',
			label: __( 'Address', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => row.ip || <span className="sgsp-cell-muted">—</span>,
		},
		{
			key: 'details',
			label: __( 'Details', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => <Details details={ row.details } />,
		},
	];

	const rows = data ? data.rows : [];
	const showEmpty = ! isLoading && ! error && data && 0 === rows.length;

	return (
		<div>
			<PanelHead
				title={ __( 'Audit log', 'keepmerge-push-staging-to-live' ) }
				description={ __(
					'What happened on this site: settings changes, pairing, failed sign-ins and pushes. Secrets, credentials and content are never recorded.',
					'keepmerge-push-staging-to-live'
				) }
				actions={
					<button
						type="button"
						className="sgsp-button sgsp-button--secondary"
						onClick={ exportCsv }
						disabled={ isExporting || ! data || 0 === data.total }
					>
						{ isExporting ? (
							<LoaderCircle size={ 15 } aria-hidden="true" className="sgsp-animate-spin" />
						) : (
							<Download size={ 15 } aria-hidden="true" />
						) }
						{ isExporting
							? __( 'Exporting…', 'keepmerge-push-staging-to-live' )
							: __( 'Export CSV', 'keepmerge-push-staging-to-live' ) }
					</button>
				}
			/>

			<Card>
				<div className="sgsp-filters">
					<div className="sgsp-field">
						<label className="sgsp-field__label" htmlFor="sgsp-audit-event">
							{ __( 'Event', 'keepmerge-push-staging-to-live' ) }
						</label>
						<select
							id="sgsp-audit-event"
							className="sgsp-select"
							value={ filters.event }
							onChange={ ( event ) => update( 'event', event.target.value ) }
						>
							<option value="">{ __( 'All events', 'keepmerge-push-staging-to-live' ) }</option>
							{ events.map( ( item ) => (
								<option key={ item.id } value={ item.id }>
									{ item.label }
								</option>
							) ) }
						</select>
					</div>
					<div className="sgsp-field">
						<label className="sgsp-field__label" htmlFor="sgsp-audit-result">
							{ __( 'Result', 'keepmerge-push-staging-to-live' ) }
						</label>
						<select
							id="sgsp-audit-result"
							className="sgsp-select"
							value={ filters.result }
							onChange={ ( event ) => update( 'result', event.target.value ) }
						>
							<option value="">{ __( 'All results', 'keepmerge-push-staging-to-live' ) }</option>
							<option value="ok">{ resultLabel( 'ok' ) }</option>
							<option value="denied">{ resultLabel( 'denied' ) }</option>
							<option value="error">{ resultLabel( 'error' ) }</option>
						</select>
					</div>
					<div className="sgsp-field">
						<label className="sgsp-field__label" htmlFor="sgsp-audit-from">
							{ __( 'From (UTC)', 'keepmerge-push-staging-to-live' ) }
						</label>
						<input
							id="sgsp-audit-from"
							type="date"
							className="sgsp-input"
							value={ filters.from }
							max={ filters.to || undefined }
							onChange={ ( event ) => update( 'from', event.target.value ) }
						/>
					</div>
					<div className="sgsp-field">
						<label className="sgsp-field__label" htmlFor="sgsp-audit-to">
							{ __( 'To (UTC)', 'keepmerge-push-staging-to-live' ) }
						</label>
						<input
							id="sgsp-audit-to"
							type="date"
							className="sgsp-input"
							value={ filters.to }
							min={ filters.from || undefined }
							onChange={ ( event ) => update( 'to', event.target.value ) }
						/>
					</div>
				</div>

				{ filtered && (
					<p className="sgsp-m-0 sgsp-mb-4">
						<button
							type="button"
							className="sgsp-button sgsp-button--link"
							onClick={ () => {
								setFilters( NO_FILTERS );
								setPage( 1 );
							} }
						>
							{ __( 'Clear filters', 'keepmerge-push-staging-to-live' ) }
						</button>
					</p>
				) }

				{ error && <LoadError message={ error } onRetry={ load } /> }

				{ showEmpty && (
					<EmptyState
						icon={ ScrollText }
						title={
							filtered
								? __( 'No events match these filters', 'keepmerge-push-staging-to-live' )
								: __( 'No events recorded yet', 'keepmerge-push-staging-to-live' )
						}
						text={
							filtered
								? __( 'Widen the date range or choose another event or result.', 'keepmerge-push-staging-to-live' )
								: __(
										'Settings changes, pairing, failed sign-ins and pushes are recorded here as they happen. Change a setting to see the first entry.',
										'keepmerge-push-staging-to-live'
								  )
						}
					/>
				) }

				{ ! error && ! showEmpty && (
					<DataTable
						caption={ __( 'Audit log events, newest first', 'keepmerge-push-staging-to-live' ) }
						columns={ columns }
						rows={ rows }
						getRowKey={ ( row ) => row.id }
						isLoading={ isLoading && ! data }
					/>
				) }

				{ data && data.total > 0 && (
					<div className="sgsp-pager">
						<span role="status">
							{ sprintf(
								/* translators: 1: page number, 2: number of pages, 3: number of events. */
								_n(
									'Page %1$d of %2$d · %3$d event',
									'Page %1$d of %2$d · %3$d events',
									data.total,
									'keepmerge-push-staging-to-live'
								),
								data.page,
								data.pages,
								data.total
							) }
						</span>
						<span className="sgsp-pager__buttons">
							<button
								type="button"
								className="sgsp-button sgsp-button--secondary"
								disabled={ page <= 1 || isLoading }
								onClick={ () => setPage( page - 1 ) }
							>
								<ChevronLeft size={ 15 } aria-hidden="true" />
								{ __( 'Newer', 'keepmerge-push-staging-to-live' ) }
							</button>
							<button
								type="button"
								className="sgsp-button sgsp-button--secondary"
								disabled={ page >= data.pages || isLoading }
								onClick={ () => setPage( page + 1 ) }
							>
								{ __( 'Older', 'keepmerge-push-staging-to-live' ) }
								<ChevronRight size={ 15 } aria-hidden="true" />
							</button>
						</span>
					</div>
				) }
			</Card>
		</div>
	);
}
