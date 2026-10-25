import { createElement, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { CodeXml, LoaderCircle, RefreshCw } from 'lucide-react';
import Alert from '../../components/Alert';
import Badge from '../../components/Badge';
import { errorMessage, getCodeReport } from '../../api';
import { number } from './plan-labels';
import { dateTime } from '../receive/time';

/**
 * A small table of components.
 *
 * @param {Object} props
 * @param {string} props.title   Heading.
 * @param {Array}  props.rows    Lines.
 * @param {Array}  props.columns [ { key, label, render } ].
 * @param {string} props.empty   Sentence when there is nothing.
 */
function Section( { title, rows, columns, empty } ) {
	return (
		<section className="sgsp-code-section">
			<h4 className="sgsp-code-section__title">
				{ title } <span className="sgsp-code-section__count">{ number( rows.length ) }</span>
			</h4>
			{ 0 === rows.length ? (
				<p className="sgsp-cell-muted sgsp-m-0">{ empty }</p>
			) : (
				<div className="sgsp-table-scroll">
					<table className="sgsp-datatable sgsp-datatable--compact">
						<caption className="screen-reader-text">{ title }</caption>
						<thead>
							<tr>
								{ columns.map( ( column ) => (
									<th key={ column.key } scope="col">
										{ column.label }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row, index ) => (
								<tr key={ index }>
									{ columns.map( ( column ) => (
										<td key={ column.key } data-label={ column.label }>
											{ column.render( row ) }
										</td>
									) ) }
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</section>
	);
}

const typeLabel = ( type ) => ( 'theme' === type ? __( 'Theme', 'keepmerge-push-staging-to-live' ) : __( 'Plugin', 'keepmerge-push-staging-to-live' ) );

/**
 * The Code sub-tab (blueprint §6.11, F8): plugins and themes missing, extra or at another version
 * on live, and per-file hash differences of live's active theme, its parent and active plugins.
 * Code is reported, never pushed. Live answers only after its administrator allows code report
 * requests; until then this tab says how to do that.
 *
 * @param {Object} props
 * @param {Object} props.pairing Pairing.
 */
export default function CodeReport( { pairing } ) {
	const [ report, setReport ] = useState( null );
	const [ running, setRunning ] = useState( false );
	const [ error, setError ] = useState( '' );
	const mounted = useRef( true );

	useEffect(
		() => () => {
			mounted.current = false;
		},
		[]
	);

	const steps = useCallback(
		( restart ) => {
			setRunning( true );
			setError( '' );
			const next = ( first ) =>
				getCodeReport( pairing.uuid, true, first && restart ).then( ( result ) => {
					if ( ! mounted.current ) {
						return result;
					}
					setReport( result );
					if ( 'running' === result.state || 'inventory' === result.state ) {
						return next( false );
					}
					return result;
				} );
			return next( true )
				.catch( ( err ) => mounted.current && setError( errorMessage( err ) ) )
				.finally( () => mounted.current && setRunning( false ) );
		},
		[ pairing.uuid ]
	);

	useEffect( () => {
		getCodeReport( pairing.uuid, false, false )
			.then( ( result ) => {
				if ( ! mounted.current ) {
					return;
				}
				setReport( result );
				if ( 'inventory' === result.state || 'running' === result.state ) {
					steps( false );
				}
			} )
			.catch( ( err ) => mounted.current && setError( errorMessage( err ) ) );
	}, [ pairing.uuid, steps ] );

	const header = (
		<Alert tone="info" title={ __( 'Code is reported, not pushed', 'keepmerge-push-staging-to-live' ) }>
			<p className="sgsp-m-0">
				{ __( 'Keepmerge reports code differences. It does not copy, install, update or activate plugins or themes.', 'keepmerge-push-staging-to-live' ) }
			</p>
		</Alert>
	);

	if ( ! report ) {
		return (
			<div className="sgsp-stack">
				{ header }
				{ error ? (
					<Alert tone="danger" title={ __( 'The code report could not be read', 'keepmerge-push-staging-to-live' ) }>
						<p className="sgsp-m-0">{ error }</p>
					</Alert>
				) : (
					<p className="sgsp-cell-muted" role="status">
						{ __( 'Loading…', 'keepmerge-push-staging-to-live' ) }
					</p>
				) }
			</div>
		);
	}

	const again = (
		<button type="button" className="sgsp-button sgsp-button--secondary" disabled={ running } onClick={ () => steps( true ) }>
			{ running ? <LoaderCircle size={ 15 } aria-hidden="true" /> : <RefreshCw size={ 15 } aria-hidden="true" /> }
			{ 'disabled' === report.state
				? __( 'Check again', 'keepmerge-push-staging-to-live' )
				: __( 'Run the code report again', 'keepmerge-push-staging-to-live' ) }
		</button>
	);

	if ( 'disabled' === report.state ) {
		return (
			<div className="sgsp-stack">
				{ header }
				<Alert tone="warning" title={ __( 'The live site does not allow code report requests', 'keepmerge-push-staging-to-live' ) }>
					<p>
						{ __( 'Code reports are off on live by default, because a list of plugins and versions is useful to an attacker who holds the pairing credentials.', 'keepmerge-push-staging-to-live' ) }
					</p>
					<ol className="sgsp-steps sgsp-steps--left">
						<li>{ __( 'On the live site, open Tools → Staging Push → Settings.', 'keepmerge-push-staging-to-live' ) }</li>
						<li>{ __( 'Switch on "Allow code report requests" and save. The change is recorded in live\'s audit log.', 'keepmerge-push-staging-to-live' ) }</li>
						<li>{ __( 'Come back here and choose "Check again".', 'keepmerge-push-staging-to-live' ) }</li>
					</ol>
					<p className="sgsp-m-0">
						{ __( 'The plan\'s warnings about missing themes and block types do not depend on this switch.', 'keepmerge-push-staging-to-live' ) }
					</p>
				</Alert>
				{ error && (
					<Alert tone="danger" title={ __( 'The request failed', 'keepmerge-push-staging-to-live' ) }>
						<p className="sgsp-m-0">{ error }</p>
					</Alert>
				) }
				<div>{ again }</div>
			</div>
		);
	}

	if ( 'not_run' === report.state || ( 'error' === report.state && ! report.diff ) ) {
		return (
			<div className="sgsp-stack">
				{ header }
				{ ( error || report.error ) && (
					<Alert tone="danger" title={ __( 'The last code report stopped', 'keepmerge-push-staging-to-live' ) }>
						<p className="sgsp-m-0">{ error || report.error.message }</p>
					</Alert>
				) }
				<div>
					<button type="button" className="sgsp-button sgsp-button--primary" disabled={ running } onClick={ () => steps( true ) }>
						<CodeXml size={ 15 } aria-hidden="true" />
						{ __( 'Run the code report', 'keepmerge-push-staging-to-live' ) }
					</button>
				</div>
			</div>
		);
	}

	const diff = report.diff || {};
	const core = diff.core || {};
	const progress = report.progress.total > 0 ? Math.round( ( 100 * report.progress.done ) / report.progress.total ) : 0;
	const versionColumns = [
		{ key: 'name', label: __( 'Name', 'keepmerge-push-staging-to-live' ), render: ( row ) => row.name || row.slug },
		{ key: 'type', label: __( 'Type', 'keepmerge-push-staging-to-live' ), render: ( row ) => typeLabel( row.type ) },
		{ key: 'here', label: __( 'This site', 'keepmerge-push-staging-to-live' ), render: ( row ) => row.here || '–' },
		{ key: 'live', label: __( 'Live', 'keepmerge-push-staging-to-live' ), render: ( row ) => row.live || '–' },
	];

	return (
		<div className="sgsp-stack">
			{ header }
			<div className="sgsp-code-head">
				<span className="sgsp-cell-muted">
					{ report.checked_at
						? sprintf(
								/* translators: %s: date and time. */
								__( 'Live\'s inventory read %s', 'keepmerge-push-staging-to-live' ),
								dateTime( report.checked_at )
						  )
						: '' }
				</span>
				{ again }
			</div>
			{ error && (
				<Alert tone="danger" title={ __( 'The request failed', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ error }</p>
				</Alert>
			) }
			{ 'error' === report.state && report.error && (
				<Alert tone="warning" title={ __( 'The file comparison stopped', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ report.error.message }</p>
				</Alert>
			) }

			<dl className="sgsp-facts">
				<dt>{ __( 'WordPress', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>
					{ sprintf(
						/* translators: 1: version here, 2: version on live. */
						__( 'This site %1$s · live %2$s', 'keepmerge-push-staging-to-live' ),
						core.wordpress ? core.wordpress.here : '–',
						core.wordpress ? core.wordpress.live : '–'
					) }
				</dd>
				<dt>{ __( 'PHP', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>
					{ sprintf(
						/* translators: 1: version here, 2: version on live. */
						__( 'This site %1$s · live %2$s', 'keepmerge-push-staging-to-live' ),
						core.php ? core.php.here : '–',
						core.php ? core.php.live : '–'
					) }
				</dd>
				<dt>{ __( 'Active theme', 'keepmerge-push-staging-to-live' ) }</dt>
				<dd>
					{ diff.active_theme
						? sprintf(
								/* translators: 1: theme and version here, 2: theme and version on live. */
								__( 'This site %1$s · live %2$s', 'keepmerge-push-staging-to-live' ),
								`${ diff.active_theme.here.slug } ${ diff.active_theme.here.version }`,
								`${ diff.active_theme.live.slug } ${ diff.active_theme.live.version }`
						  )
						: '–' }
				</dd>
			</dl>

			<Section
				title={ __( 'Missing on live', 'keepmerge-push-staging-to-live' ) }
				rows={ diff.missing_on_live || [] }
				columns={ versionColumns }
				empty={ __( 'Live has every plugin and theme this site has.', 'keepmerge-push-staging-to-live' ) }
			/>
			<Section
				title={ __( 'Extra on live', 'keepmerge-push-staging-to-live' ) }
				rows={ diff.extra_on_live || [] }
				columns={ versionColumns }
				empty={ __( 'Live has nothing this site lacks.', 'keepmerge-push-staging-to-live' ) }
			/>
			<Section
				title={ __( 'Version differs', 'keepmerge-push-staging-to-live' ) }
				rows={ diff.version_differs || [] }
				columns={ versionColumns }
				empty={ __( 'Every plugin and theme on both sites has the same version.', 'keepmerge-push-staging-to-live' ) }
			/>
			{ ( diff.active_differs || [] ).length > 0 && (
				<Section
					title={ __( 'Active on one site only', 'keepmerge-push-staging-to-live' ) }
					rows={ diff.active_differs }
					columns={ [
						versionColumns[ 0 ],
						{
							key: 'here',
							label: __( 'This site', 'keepmerge-push-staging-to-live' ),
							render: ( row ) => ( row.active_here ? __( 'Active', 'keepmerge-push-staging-to-live' ) : __( 'Inactive', 'keepmerge-push-staging-to-live' ) ),
						},
						{
							key: 'live',
							label: __( 'Live', 'keepmerge-push-staging-to-live' ),
							render: ( row ) => ( row.active_live ? __( 'Active', 'keepmerge-push-staging-to-live' ) : __( 'Inactive', 'keepmerge-push-staging-to-live' ) ),
						},
					] }
					empty=""
				/>
			) }

			<section className="sgsp-code-section">
				<h4 className="sgsp-code-section__title">
					{ __( 'Files differ', 'keepmerge-push-staging-to-live' ) }{ ' ' }
					<span className="sgsp-code-section__count">{ number( report.files.filter( ( f ) => f.differ + f.only_live + f.only_here > 0 ).length ) }</span>
				</h4>
				<p className="sgsp-field__hint sgsp-m-0">
					{ __( 'Live\'s active theme, its parent and its active plugins, compared file by file with SHA-1 hashes. No file content is transferred.', 'keepmerge-push-staging-to-live' ) }
				</p>
				{ ( running || 'running' === report.state ) && (
					<div className="sgsp-progress" role="status">
						<div className="sgsp-progress__label">
							{ sprintf(
								/* translators: 1: components compared, 2: all components. */
								__( 'Comparing files: %1$s of %2$s components', 'keepmerge-push-staging-to-live' ),
								number( report.progress.done ),
								number( report.progress.total )
							) }
						</div>
						<div className="sgsp-progress__track" aria-hidden="true">
							<div className="sgsp-progress__bar" style={ { width: `${ progress }%` } } />
						</div>
					</div>
				) }
				<ul className="sgsp-filelist">
					{ report.files.map( ( file ) => {
						const changed = file.differ + file.only_live + file.only_here;
						return (
							<li key={ file.id } className="sgsp-filelist__item">
								<div className="sgsp-filelist__head">
									<span className="sgsp-itemcell__title">{ file.name }</span>
									<span className="sgsp-cell-muted">
										{ typeLabel( file.type ) } · { file.local_version || '–' } / { file.live_version || '–' }
									</span>
									{ ! file.done ? (
										<Badge tone="info" icon={ LoaderCircle }>{ __( 'Comparing', 'keepmerge-push-staging-to-live' ) }</Badge>
									) : changed > 0 ? (
										<Badge tone="warning">
											{ sprintf(
												/* translators: %s: number of files. */
												_n( '%s file differs', '%s files differ', changed, 'keepmerge-push-staging-to-live' ),
												number( changed )
											) }
										</Badge>
									) : (
										<Badge tone="success">
											{ sprintf(
												/* translators: %s: number of files. */
												__( 'Same (%s files)', 'keepmerge-push-staging-to-live' ),
												number( file.compared )
											) }
										</Badge>
									) }
								</div>
								{ changed > 0 && (
									<details className="sgsp-disclosure">
										<summary>
											{ sprintf(
												/* translators: 1: changed files, 2: only on live, 3: only here. */
												__( 'Changed %1$s · only on live %2$s · only here %3$s', 'keepmerge-push-staging-to-live' ),
												number( file.differ ),
												number( file.only_live ),
												number( file.only_here )
											) }
										</summary>
										<ul className="sgsp-pathlist">
											{ file.paths.map( ( entry ) => (
												<li key={ entry.path }>
													<code className="sgsp-code">{ entry.path }</code>{ ' ' }
													<span className="sgsp-cell-muted">
														{ { differ: __( 'changed', 'keepmerge-push-staging-to-live' ), only_live: __( 'only on live', 'keepmerge-push-staging-to-live' ), only_here: __( 'only here', 'keepmerge-push-staging-to-live' ) }[ entry.state ] }
													</span>
												</li>
											) ) }
											{ file.more_paths > 0 && (
												<li className="sgsp-cell-muted">
													{ sprintf(
														/* translators: %s: number of paths. */
														__( 'and %s more', 'keepmerge-push-staging-to-live' ),
														number( file.more_paths )
													) }
												</li>
											) }
										</ul>
									</details>
								) }
							</li>
						);
					} ) }
				</ul>
			</section>

			{ report.not_comparable.length > 0 && (
				<Section
					title={ __( 'Not comparable', 'keepmerge-push-staging-to-live' ) }
					rows={ report.not_comparable }
					columns={ [
						{ key: 'name', label: __( 'Name', 'keepmerge-push-staging-to-live' ), render: ( row ) => row.name },
						{
							key: 'reason',
							label: __( 'Why', 'keepmerge-push-staging-to-live' ),
							render: ( row ) => {
								if ( 'too_many_files' === row.reason ) {
									return __( 'More than 20,000 files, or too many to list in one request', 'keepmerge-push-staging-to-live' );
								}
								if ( 'failed' === row.reason ) {
									return __( 'The live site did not answer in time for this one (try again later)', 'keepmerge-push-staging-to-live' );
								}
								return __( 'Not installed on this site', 'keepmerge-push-staging-to-live' );
							},
						},
					] }
					empty=""
				/>
			) }

			{ diff.mu_plugins && ( diff.mu_plugins.missing_on_live.length > 0 || diff.mu_plugins.extra_on_live.length > 0 || diff.dropins.missing_on_live.length > 0 || diff.dropins.extra_on_live.length > 0 ) && (
				<section className="sgsp-code-section">
					<h4 className="sgsp-code-section__title">{ __( 'Must-use plugins and drop-ins', 'keepmerge-push-staging-to-live' ) }</h4>
					<ul className="sgsp-pathlist">
						{ diff.mu_plugins.missing_on_live.map( ( name ) => (
							<li key={ `mu-m-${ name }` }>
								<code className="sgsp-code">{ name }</code> { __( 'missing on live', 'keepmerge-push-staging-to-live' ) }
							</li>
						) ) }
						{ diff.mu_plugins.extra_on_live.map( ( name ) => (
							<li key={ `mu-e-${ name }` }>
								<code className="sgsp-code">{ name }</code> { __( 'only on live', 'keepmerge-push-staging-to-live' ) }
							</li>
						) ) }
						{ diff.dropins.missing_on_live.map( ( name ) => (
							<li key={ `d-m-${ name }` }>
								<code className="sgsp-code">{ name }</code> { __( 'missing on live', 'keepmerge-push-staging-to-live' ) }
							</li>
						) ) }
						{ diff.dropins.extra_on_live.map( ( name ) => (
							<li key={ `d-e-${ name }` }>
								<code className="sgsp-code">{ name }</code> { __( 'only on live', 'keepmerge-push-staging-to-live' ) }
							</li>
						) ) }
					</ul>
				</section>
			) }
		</div>
	);
}
