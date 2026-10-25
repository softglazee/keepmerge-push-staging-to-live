import { createElement, useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { ExternalLink, FileDiff, Link2, Lock, Search } from 'lucide-react';
import Badge from '../../components/Badge';
import DataTable from '../../components/DataTable';
import { decidePlan, errorMessage, getPlan } from '../../api';
import { actionLabel, badgeInfo, classInfo, groupLabel, number, warningDetail, warningInfo } from './plan-labels';

const PER_PAGE = 25;

/**
 * The class filters of a list, in the order shown.
 *
 * @return {Array} [ { id, label } ].
 */
function classFilters() {
	return [
		{ id: 'changes', label: __( 'Everything that differs', 'keepmerge-push-staging-to-live' ) },
		{ id: 'conflicts', label: __( 'Conflicts', 'keepmerge-push-staging-to-live' ) },
		{ id: 'push', label: __( 'Changed or new here', 'keepmerge-push-staging-to-live' ) },
		{ id: 'live', label: __( 'Changed or deleted on live', 'keepmerge-push-staging-to-live' ) },
		{ id: 'skipped', label: __( 'Skipped', 'keepmerge-push-staging-to-live' ) },
		{ id: 'unchanged', label: __( 'Unchanged', 'keepmerge-push-staging-to-live' ) },
		{ id: 'all', label: __( 'All items', 'keepmerge-push-staging-to-live' ) },
	];
}

/**
 * A choice for one row or one product group: a select labelled with what it decides.
 *
 * @param {Object}   props
 * @param {string}   props.label    Accessible label.
 * @param {string}   props.value    Current action ('' = undecided).
 * @param {string[]} props.choices  Allowed actions.
 * @param {boolean}  props.busy     Saving.
 * @param {Function} props.onChange Receives the action.
 */
function Choice( { label, value, choices, busy, onChange } ) {
	return (
		<select
			className={ `sgsp-select sgsp-select--compact sgsp-choice${ '' === value ? ' is-open' : '' }` }
			aria-label={ label }
			value={ value }
			disabled={ busy }
			onChange={ ( event ) => onChange( event.target.value ) }
		>
			{ '' === value && <option value="">{ actionLabel( '' ) }</option> }
			{ choices.map( ( choice ) => (
				<option key={ choice } value={ choice }>
					{ actionLabel( choice ) }
				</option>
			) ) }
		</select>
	);
}

/**
 * What happens to a row, and the control to change it.
 *
 * @param {Object}   props
 * @param {Object}   props.row      Row.
 * @param {boolean}  props.busy     Saving.
 * @param {Function} props.onDecide ( row, action, group ).
 */
function RowAction( { row, busy, onDecide } ) {
	if ( row.groups.length > 0 ) {
		return (
			<ul className="sgsp-groups">
				{ row.groups.map( ( group ) => {
					const info = classInfo( group.class );
					const value = group.decision || group.action;
					return (
						<li key={ group.name } className="sgsp-groups__item">
							<span className="sgsp-groups__name">{ groupLabel( group.name ) }</span>
							<Badge tone={ info.tone }>{ info.label }</Badge>
							{ group.choices.length > 1 || ( '' === value && group.choices.length > 0 ) ? (
								<Choice
									label={ sprintf(
										/* translators: 1: field group, 2: item title. */
										__( 'Action for %1$s of %2$s', 'keepmerge-push-staging-to-live' ),
										groupLabel( group.name ),
										row.title
									) }
									value={ value }
									choices={ group.choices }
									busy={ busy }
									onChange={ ( action ) => onDecide( row, action, group.name ) }
								/>
							) : (
								<span className="sgsp-groups__action">{ actionLabel( value ) }</span>
							) }
						</li>
					);
				} ) }
			</ul>
		);
	}

	if ( row.choices.length > 0 && row.in_scope && ! row.pulled_in ) {
		return (
			<Choice
				label={ sprintf(
					/* translators: %s: item title. */
					__( 'Action for %s', 'keepmerge-push-staging-to-live' ),
					row.title || row.type_label
				) }
				value={ row.decision || row.action }
				choices={ row.choices }
				busy={ busy }
				onChange={ ( action ) => onDecide( row, action, '' ) }
			/>
		);
	}

	return <span className="sgsp-action-text">{ actionLabel( row.effective ) }</span>;
}

/**
 * The item cell: title, type, badges, and what depends on what.
 *
 * @param {Object} props
 * @param {Object} props.row Row.
 */
function ItemCell( { row } ) {
	const badges = row.badges.map( badgeInfo ).filter( Boolean );
	const groupActions = row.groups
		.filter( ( group ) => 'live_owned' !== group.name )
		.map( ( group ) => group.decision || group.action );
	if ( ( groupActions.includes( 'push' ) || groupActions.includes( 'use_staging' ) ) && groupActions.includes( 'keep_live' ) ) {
		badges.unshift( {
			label: __( 'Split by field group', 'keepmerge-push-staging-to-live' ),
			tone: 'info',
		} );
	}
	return (
		<span className="sgsp-itemcell">
			<span className="sgsp-itemcell__title">{ row.title || __( '(no title)', 'keepmerge-push-staging-to-live' ) }</span>
			<span className="sgsp-itemcell__type">
				{ row.type_label }
				{ row.local_id > 0 && ` · #${ row.local_id }` }
			</span>
			{ ( badges.length > 0 || row.pulled_in ) && (
				<span className="sgsp-badges">
					{ badges.map( ( badge ) => (
						<Badge key={ badge.label } tone={ badge.tone }>
							{ badge.label }
						</Badge>
					) ) }
					{ row.pulled_in && (
						<Badge tone="info" icon={ Link2 }>
							{ __( 'Pulled in by a pushed item', 'keepmerge-push-staging-to-live' ) }
						</Badge>
					) }
				</span>
			) }
			{ row.blocked_by.length > 0 && (
				<span className="sgsp-itemcell__note sgsp-itemcell__note--danger">
					<Lock size={ 12 } aria-hidden="true" />
					{ sprintf(
						/* translators: %s: titles of the items this one waits for. */
						__( 'Waits for: %s', 'keepmerge-push-staging-to-live' ),
						row.blocked_by.map( ( dep ) => dep.title || __( '(no title)', 'keepmerge-push-staging-to-live' ) ).join( ', ' )
					) }
				</span>
			) }
			{ row.pulled_in_by.length > 0 && (
				<span className="sgsp-itemcell__note">
					{ sprintf(
						/* translators: %s: titles of the items that need this one. */
						__( 'Needed by: %s', 'keepmerge-push-staging-to-live' ),
						row.pulled_in_by.map( ( dep ) => dep.title || __( '(no title)', 'keepmerge-push-staging-to-live' ) ).join( ', ' )
					) }
				</span>
			) }
			{ row.warnings
				// Shown already: as the "waits for" line, or as a badge of the same name.
				.filter( ( warning ) => ! [ 'blocked', 'slug_taken', 'theme_missing', 'woo_not_on_live', 'stock_changed_on_live' ].includes( warning.code ) )
				.map( ( warning ) => (
					<span key={ warning.code } className="sgsp-itemcell__note sgsp-itemcell__note--warning">
						{ warningInfo( warning.code ).title }
						{ warning.detail ? `: ${ warningDetail( warning ) }` : '' }
					</span>
				) ) }
		</span>
	);
}

/**
 * Links to the item on each site (new tab).
 *
 * @param {Object} props
 * @param {Object} props.row Row.
 */
function Links( { row } ) {
	const link = ( href, label, id ) => (
		<a className="sgsp-itemlink" href={ href } target="_blank" rel="noopener noreferrer">
			{ label }
			{ id > 0 && ` #${ id }` }
			<ExternalLink size={ 12 } aria-hidden="true" />
			<span className="screen-reader-text">{ __( '(opens in a new tab)', 'keepmerge-push-staging-to-live' ) }</span>
		</a>
	);
	return (
		<span className="sgsp-linkstack">
			{ row.local_url ? link( row.local_url, __( 'Here', 'keepmerge-push-staging-to-live' ), row.local_id ) : <span className="sgsp-cell-muted">{ row.exists_here ? '–' : __( 'Not here', 'keepmerge-push-staging-to-live' ) }</span> }
			{ row.live_url ? link( row.live_url, __( 'Live', 'keepmerge-push-staging-to-live' ), row.live_id ) : <span className="sgsp-cell-muted">{ row.on_live ? '–' : __( 'Not on live', 'keepmerge-push-staging-to-live' ) }</span> }
		</span>
	);
}

/**
 * The rows of one sub-tab (or every row with warnings), with filters, paging, row actions and
 * the diff drawer.
 *
 * @param {Object}   props
 * @param {Object}   props.plan          Plan.
 * @param {string}   props.tab           Sub-tab ('' for all).
 * @param {boolean}  props.warningsOnly  Only rows with warnings.
 * @param {string}   props.initialFilter Class filter to start with.
 * @param {Function} props.onPlan        Receives the updated plan after a decision.
 * @param {Function} props.onOpenDiff    Opens the diff drawer for a row.
 * @param {Function} props.notify        Toast.
 * @param {number}   props.version       Changes when decisions changed elsewhere.
 */
export default function PlanRows( { plan, tab, warningsOnly = false, initialFilter = 'changes', onPlan, onOpenDiff, notify, version } ) {
	const [ filter, setFilter ] = useState( initialFilter );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const [ saving, setSaving ] = useState( '' );

	const load = useCallback( () => {
		setBusy( true );
		return getPlan( plan.id, {
			tab,
			class: 'all' === filter || warningsOnly ? '' : filter,
			warnings: warningsOnly,
			search: query,
			page,
			per_page: PER_PAGE,
		} )
			.then( ( result ) => setData( result.rows ) )
			.catch( ( err ) => notify( 'error', errorMessage( err ) ) )
			.finally( () => setBusy( false ) );
	}, [ plan.id, tab, filter, warningsOnly, query, page, notify ] );

	useEffect( () => {
		load();
	}, [ load, version ] );

	const decide = ( row, action, group ) => {
		setSaving( row.uuid );
		decidePlan( plan.id, { decisions: [ { uuid: row.uuid, action, group } ] } )
			.then( ( next ) => {
				onPlan( next );
				return load();
			} )
			.catch( ( err ) => notify( 'error', errorMessage( err ) ) )
			.finally( () => setSaving( '' ) );
	};

	const idPrefix = `sgsp-rows-${ plan.id }-${ tab || 'all' }${ warningsOnly ? '-w' : '' }`;

	const columns = [
		{
			key: 'item',
			label: __( 'Item', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => <ItemCell row={ row } />,
		},
		{
			key: 'status',
			label: __( 'What differs', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => {
				const info = classInfo( row.class );
				return <Badge tone={ info.tone }>{ info.label }</Badge>;
			},
		},
		{
			key: 'action',
			label: __( 'Action', 'keepmerge-push-staging-to-live' ),
			render: ( row ) => <RowAction row={ row } busy={ saving === row.uuid } onDecide={ decide } />,
		},
		{
			key: 'links',
			label: __( 'Open', 'keepmerge-push-staging-to-live' ),
			className: 'sgsp-cell-nowrap',
			render: ( row ) => <Links row={ row } />,
		},
		{
			key: 'diff',
			label: __( 'Changes', 'keepmerge-push-staging-to-live' ),
			render: ( row ) =>
				'unchanged' === row.class ? (
					<span className="sgsp-cell-muted">–</span>
				) : (
					<button type="button" className="sgsp-button sgsp-button--secondary sgsp-button--small" onClick={ () => onOpenDiff( row ) }>
						<FileDiff size={ 14 } aria-hidden="true" />
						{ __( 'View changes', 'keepmerge-push-staging-to-live' ) }
						<span className="screen-reader-text">{ `: ${ row.title }` }</span>
					</button>
				),
		},
	];

	return (
		<div>
			<form
				className="sgsp-rowfilters"
				onSubmit={ ( event ) => {
					event.preventDefault();
					setPage( 1 );
					setQuery( search.trim() );
				} }
			>
				{ ! warningsOnly && (
					<div className="sgsp-field">
						<label className="sgsp-field__label" htmlFor={ `${ idPrefix }-filter` }>
							{ __( 'Show', 'keepmerge-push-staging-to-live' ) }
						</label>
						<select
							id={ `${ idPrefix }-filter` }
							className="sgsp-select"
							value={ filter }
							onChange={ ( event ) => {
								setFilter( event.target.value );
								setPage( 1 );
							} }
						>
							{ classFilters().map( ( option ) => (
								<option key={ option.id } value={ option.id }>
									{ option.label }
								</option>
							) ) }
						</select>
					</div>
				) }
				<div className="sgsp-field">
					<label className="sgsp-field__label" htmlFor={ `${ idPrefix }-search` }>
						{ __( 'Find by title', 'keepmerge-push-staging-to-live' ) }
					</label>
					<span className="sgsp-searchbox">
						<input
							id={ `${ idPrefix }-search` }
							className="sgsp-input"
							type="search"
							value={ search }
							maxLength={ 100 }
							onChange={ ( event ) => setSearch( event.target.value ) }
						/>
						<button type="submit" className="sgsp-button sgsp-button--secondary">
							<Search size={ 14 } aria-hidden="true" />
							{ __( 'Find', 'keepmerge-push-staging-to-live' ) }
						</button>
					</span>
				</div>
			</form>

			{ data && 0 === data.total && ! busy ? (
				<p className="sgsp-baseline__empty">
					{ query
						? __( 'No item matches this search.', 'keepmerge-push-staging-to-live' )
						: __( 'Nothing to show here: no item of this kind differs.', 'keepmerge-push-staging-to-live' ) }
				</p>
			) : (
				<DataTable
					caption={ __( 'Plan items', 'keepmerge-push-staging-to-live' ) }
					columns={ columns }
					rows={ data ? data.items : [] }
					getRowKey={ ( row ) => row.uuid }
					isLoading={ busy && ! data }
					skeletonRows={ 5 }
				/>
			) }

			{ data && data.total > data.per_page && (
				<div className="sgsp-pager">
					<span>
						{ sprintf(
							/* translators: 1: first row, 2: last row, 3: all rows. */
							__( '%1$s–%2$s of %3$s', 'keepmerge-push-staging-to-live' ),
							number( ( data.page - 1 ) * data.per_page + 1 ),
							number( Math.min( data.total, data.page * data.per_page ) ),
							number( data.total )
						) }
					</span>
					<span className="sgsp-pager__buttons">
						<button
							type="button"
							className="sgsp-button sgsp-button--secondary sgsp-button--small"
							disabled={ data.page <= 1 || busy }
							onClick={ () => setPage( data.page - 1 ) }
						>
							{ __( 'Previous', 'keepmerge-push-staging-to-live' ) }
						</button>
						<button
							type="button"
							className="sgsp-button sgsp-button--secondary sgsp-button--small"
							disabled={ data.page * data.per_page >= data.total || busy }
							onClick={ () => setPage( data.page + 1 ) }
						>
							{ __( 'Next', 'keepmerge-push-staging-to-live' ) }
						</button>
					</span>
				</div>
			) }
		</div>
	);
}
