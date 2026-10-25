import { createElement, Fragment, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import Alert from '../../components/Alert';
import Badge from '../../components/Badge';
import Modal from '../../components/Modal';
import Skeleton from '../../components/Skeleton';
import { errorMessage, getPlanDiff } from '../../api';
import { classInfo, fieldLabel, groupLabel, number } from './plan-labels';
import { collapse, detokenize, isMultiline, lineDiff } from './textdiff';

/**
 * One side of a short value.
 *
 * @param {Object}      props
 * @param {string}      props.label  Side name.
 * @param {string|null} props.value  Text, or null when the side has no such field.
 * @param {string}      props.origin Address for the URL tokens.
 */
function Side( { label, value, origin } ) {
	return (
		<div className="sgsp-diff__side">
			<div className="sgsp-diff__side-label">{ label }</div>
			{ null === value ? (
				<p className="sgsp-diff__absent">{ __( 'Not on this side', 'keepmerge-push-staging-to-live' ) }</p>
			) : (
				<pre className="sgsp-diff__pre">{ '' === value ? __( '(empty)', 'keepmerge-push-staging-to-live' ) : detokenize( value, origin ) }</pre>
			) }
		</div>
	);
}

/**
 * A line diff of a long value: removed lines are live's, added lines this site's.
 *
 * @param {Object} props
 * @param {Object} props.field   Field.
 * @param {Object} props.origins { staging, live }.
 */
function Lines( { field, origins } ) {
	const lines = lineDiff( field.live || '', field.staging || '' );
	if ( null === lines ) {
		return (
			<div className="sgsp-diff__pair">
				<Side label={ __( 'On live', 'keepmerge-push-staging-to-live' ) } value={ field.live } origin={ origins.live } />
				<Side label={ __( 'On this site', 'keepmerge-push-staging-to-live' ) } value={ field.staging } origin={ origins.staging } />
			</div>
		);
	}
	return (
		<ol className="sgsp-diff__lines" aria-label={ __( 'Line by line: minus is live, plus is this site', 'keepmerge-push-staging-to-live' ) }>
			{ collapse( lines ).map( ( line, index ) => {
				if ( 'skip' === line.type ) {
					return (
						<li key={ index } className="sgsp-diff__line sgsp-diff__line--skip">
							{ sprintf(
								/* translators: %s: number of lines. */
								_n( '%s unchanged line', '%s unchanged lines', line.count, 'keepmerge-push-staging-to-live' ),
								number( line.count )
							) }
						</li>
					);
				}
				const marker = { same: ' ', del: '−', add: '+' }[ line.type ];
				const origin = 'del' === line.type ? origins.live : origins.staging;
				return (
					<li key={ index } className={ `sgsp-diff__line sgsp-diff__line--${ line.type }` }>
						<span className="sgsp-diff__marker" aria-hidden="true">
							{ marker }
						</span>
						<span className="screen-reader-text">
							{ { same: '', del: __( 'Live:', 'keepmerge-push-staging-to-live' ), add: __( 'This site:', 'keepmerge-push-staging-to-live' ) }[ line.type ] }
						</span>
						<span className="sgsp-diff__text">{ detokenize( line.text, origin ) || ' ' }</span>
					</li>
				);
			} ) }
		</ol>
	);
}

/**
 * The text diff of one plan item (blueprint F6): only the fields that differ, each shown as
 * escaped text. Live's copy is read when the drawer opens.
 *
 * @param {Object}   props
 * @param {number}   props.planId Plan ID.
 * @param {Object}   props.row    Plan row.
 * @param {Function} props.onClose Closes the drawer.
 */
export default function DiffDrawer( { planId, row, onClose } ) {
	const [ diff, setDiff ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let live = true;
		getPlanDiff( planId, row.uuid )
			.then( ( result ) => live && setDiff( result ) )
			.catch( ( err ) => live && setError( errorMessage( err ) ) );
		return () => {
			live = false;
		};
	}, [ planId, row.uuid ] );

	const info = classInfo( row.class );
	const origins = diff ? { staging: diff.staging.origin, live: diff.live.origin } : { staging: '', live: '' };

	return (
		<Modal
			size="wide"
			title={ row.title || __( '(no title)', 'keepmerge-push-staging-to-live' ) }
			subtitle={
				<span className="sgsp-diff__subtitle">
					<Badge tone={ info.tone }>{ info.label }</Badge>
					<span>{ row.type_label }</span>
				</span>
			}
			onClose={ onClose }
			footer={
				<button type="button" className="sgsp-button sgsp-button--secondary" onClick={ onClose }>
					{ __( 'Close', 'keepmerge-push-staging-to-live' ) }
				</button>
			}
		>
			{ error && (
				<Alert tone="danger" title={ __( 'The differences could not be read', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ error }</p>
				</Alert>
			) }
			{ ! diff && ! error && (
				<div className="sgsp-stack" role="status">
					<span className="screen-reader-text">{ __( 'Loading…', 'keepmerge-push-staging-to-live' ) }</span>
					<Skeleton width="60%" />
					<Skeleton height={ 80 } />
					<Skeleton height={ 80 } />
				</div>
			) }
			{ diff && (
				<Fragment>
					<dl className="sgsp-diff__facts">
						<div>
							<dt>{ __( 'This site', 'keepmerge-push-staging-to-live' ) }</dt>
							<dd>
								{ diff.staging.exists
									? sprintf(
											/* translators: 1: item ID, 2: GMT date and time. */
											__( '#%1$s, changed %2$s (GMT)', 'keepmerge-push-staging-to-live' ),
											diff.staging.id,
											diff.staging.modified || '–'
									  )
									: __( 'Deleted here', 'keepmerge-push-staging-to-live' ) }
							</dd>
						</div>
						<div>
							<dt>{ __( 'Live', 'keepmerge-push-staging-to-live' ) }</dt>
							<dd>
								{ diff.live.exists
									? sprintf(
											/* translators: 1: item ID, 2: GMT date and time. */
											__( '#%1$s, changed %2$s (GMT)', 'keepmerge-push-staging-to-live' ),
											diff.live.id,
											diff.live.modified || '–'
									  )
									: __( 'Not on live', 'keepmerge-push-staging-to-live' ) }
							</dd>
						</div>
						{ ( diff.staging.author || diff.live.author ) && (
							<div>
								<dt>{ __( 'Author on live', 'keepmerge-push-staging-to-live' ) }</dt>
								<dd>
									{ sprintf(
										/* translators: %s: user login on this site. */
										__( '"%s" is looked up on live by login, then by email; if neither exists there, live uses the pairing\'s default author.', 'keepmerge-push-staging-to-live' ),
										diff.staging.author || '–'
									) }
									{ diff.live.author && diff.live.author !== diff.staging.author
										? ' ' +
										  sprintf(
												/* translators: %s: user login on live. */
												__( 'Live\'s copy is by "%s" now.', 'keepmerge-push-staging-to-live' ),
												diff.live.author
										  )
										: '' }
								</dd>
							</div>
						) }
					</dl>

					{ diff.gate.length > 0 && (
						<Alert tone="warning" title={ __( 'Live will hold this item for review', 'keepmerge-push-staging-to-live' ) }>
							<ul className="sgsp-m-0">
								{ diff.gate.map( ( reason, index ) => (
									<li key={ index }>
										<code className="sgsp-code">{ reason.field }</code>{ ' ' }
										{ reason.excerpt }
									</li>
								) ) }
							</ul>
						</Alert>
					) }

					{ 0 === diff.fields.length ? (
						<p className="sgsp-baseline__empty">
							{ __( 'No field differs any more: the item changed since the plan was made. Plan again to see the current state.', 'keepmerge-push-staging-to-live' ) }
						</p>
					) : (
						<div className="sgsp-diff__fields">
							{ diff.fields.map( ( field ) => (
								<section key={ field.path } className="sgsp-diff__field">
									<h3 className="sgsp-diff__field-title">
										{ fieldLabel( field.path ) }
										{ field.group && <span className="sgsp-diff__group">{ groupLabel( field.group ) }</span> }
										<code className="sgsp-diff__path">{ field.path }</code>
									</h3>
									{ null !== field.live && null !== field.staging && isMultiline( field.live, field.staging ) ? (
										<Lines field={ field } origins={ origins } />
									) : (
										<div className="sgsp-diff__pair">
											<Side label={ __( 'On live', 'keepmerge-push-staging-to-live' ) } value={ field.live } origin={ origins.live } />
											<Side label={ __( 'On this site', 'keepmerge-push-staging-to-live' ) } value={ field.staging } origin={ origins.staging } />
										</div>
									) }
								</section>
							) ) }
						</div>
					) }

					<p className="sgsp-field__hint sgsp-mt-3">
						{ sprintf(
							/* translators: %s: number of fields. */
							_n(
								'%s other field is the same on both sites.',
								'%s other fields are the same on both sites.',
								diff.equal_fields,
								'keepmerge-push-staging-to-live'
							),
							number( diff.equal_fields )
						) }{ ' ' }
						{ __( 'Values are shown as text. Addresses and IDs that differ only because the sites differ are mapped when pushing and are not listed as changes.', 'keepmerge-push-staging-to-live' ) }
					</p>
				</Fragment>
			) }
		</Modal>
	);
}
