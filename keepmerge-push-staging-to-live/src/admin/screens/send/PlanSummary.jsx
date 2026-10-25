import { createElement, Fragment } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import Alert from '../../components/Alert';
import { number, tabList, warningInfo } from './plan-labels';

/**
 * The Summary sub-tab: counts per kind of content, what a push would do, how authors map on live,
 * and how the first plan decided by dates.
 *
 * @param {Object}   props
 * @param {Object}   props.summary Plan summary.
 * @param {Array}    props.tabs    Visible list tabs.
 * @param {Function} props.onTab   Opens a sub-tab.
 */
export function SummaryPanel( { summary, tabs, onTab } ) {
	const labels = {};
	tabList().forEach( ( tab ) => {
		labels[ tab.id ] = tab.label;
	} );
	const d = summary.dialog;

	return (
		<div className="sgsp-stack">
			<div className="sgsp-table-scroll">
				<table className="sgsp-datatable sgsp-datatable--compact">
					<caption className="screen-reader-text">{ __( 'Items by kind', 'keepmerge-push-staging-to-live' ) }</caption>
					<thead>
						<tr>
							<th scope="col">{ __( 'Kind', 'keepmerge-push-staging-to-live' ) }</th>
							<th scope="col">{ __( 'Items', 'keepmerge-push-staging-to-live' ) }</th>
							<th scope="col">{ __( 'Differ', 'keepmerge-push-staging-to-live' ) }</th>
							<th scope="col">{ __( 'Conflicts to decide', 'keepmerge-push-staging-to-live' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ tabs.map( ( tab ) => {
							const counts = summary.by_tab[ tab ] || { total: 0, changes: 0, conflicts: 0 };
							return (
								<tr key={ tab }>
									<td data-label={ __( 'Kind', 'keepmerge-push-staging-to-live' ) }>
										<button type="button" className="sgsp-button sgsp-button--link" onClick={ () => onTab( tab ) }>
											{ labels[ tab ] }
										</button>
									</td>
									<td data-label={ __( 'Items', 'keepmerge-push-staging-to-live' ) }>{ number( counts.total ) }</td>
									<td data-label={ __( 'Differ', 'keepmerge-push-staging-to-live' ) }>{ number( counts.changes ) }</td>
									<td data-label={ __( 'Conflicts to decide', 'keepmerge-push-staging-to-live' ) }>
										{ counts.conflicts > 0 ? <strong>{ number( counts.conflicts ) }</strong> : number( 0 ) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>

			<section>
				<h4 className="sgsp-code-section__title">{ __( 'What a push would do now', 'keepmerge-push-staging-to-live' ) }</h4>
				<ul className="sgsp-pushcounts">
					<li className={ d.create ? '' : 'is-zero' }>
						<strong>{ number( d.create ) }</strong> { __( 'to create on live', 'keepmerge-push-staging-to-live' ) }
					</li>
					<li className={ d.update ? '' : 'is-zero' }>
						<strong>{ number( d.update ) }</strong> { __( 'to update on live', 'keepmerge-push-staging-to-live' ) }
					</li>
					<li className={ d.recreate ? '' : 'is-zero' }>
						<strong>{ number( d.recreate ) }</strong> { __( 'to recreate on live', 'keepmerge-push-staging-to-live' ) }
					</li>
					<li className={ d.trash ? '' : 'is-zero' }>
						<strong>{ number( d.trash ) }</strong> { __( 'to move to the Trash on live', 'keepmerge-push-staging-to-live' ) }
					</li>
					<li className={ d.keep_live ? '' : 'is-zero' }>
						<strong>{ number( d.keep_live ) }</strong> { __( 'kept as they are on live', 'keepmerge-push-staging-to-live' ) }
					</li>
					<li className={ d.record_base ? '' : 'is-zero' }>
						<strong>{ number( d.record_base ) }</strong> { __( 'already the same on both (recorded as in sync)', 'keepmerge-push-staging-to-live' ) }
					</li>
				</ul>
			</section>

			{ ! summary.clone.unknown && d.by_dates > 0 && (
				<Alert tone="warning" title={ __( 'Some defaults come from dates', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">
						{ sprintf(
							/* translators: 1: number of items, 2: clone date. */
							_n(
								'%1$s item has no history yet, so its default was decided by modification dates against the clone date %2$s. Changes that do not update the date (meta only) are invisible to that rule.',
								'%1$s items have no history yet, so their defaults were decided by modification dates against the clone date %2$s. Changes that do not update the date (meta only) are invisible to that rule.',
								d.by_dates,
								'keepmerge-push-staging-to-live'
							),
							number( d.by_dates ),
							summary.clone.date
						) }
					</p>
				</Alert>
			) }
			{ summary.clone.unknown && (
				<p className="sgsp-field__hint">
					{ __( 'The clone date is not known, so every matched item that differs and has no history yet is a conflict for you to decide.', 'keepmerge-push-staging-to-live' ) }
				</p>
			) }

			<section>
				<h4 className="sgsp-code-section__title">{ __( 'Authors on live', 'keepmerge-push-staging-to-live' ) }</h4>
				<p className="sgsp-field__hint">
					{ __( 'Users are never pushed. Live looks up each author by login, then by email, and otherwise uses the default author its administrator chose for this pairing. Live does not reveal its user list, so the match itself happens there.', 'keepmerge-push-staging-to-live' ) }
				</p>
				{ 0 === summary.authors.length ? (
					<p className="sgsp-cell-muted sgsp-m-0">{ __( 'No item that would be sent has an author.', 'keepmerge-push-staging-to-live' ) }</p>
				) : (
					<ul className="sgsp-pathlist">
						{ summary.authors.map( ( author ) => (
							<li key={ author.login }>
								<strong>{ author.name || author.login }</strong>{ ' ' }
								<span className="sgsp-cell-muted">
									{ sprintf(
										/* translators: 1: login, 2: email, 3: number of items. */
										_n( 'login %1$s, email %2$s: %3$s item', 'login %1$s, email %2$s: %3$s items', author.items, 'keepmerge-push-staging-to-live' ),
										author.login,
										author.email || '–',
										number( author.items )
									) }
								</span>
							</li>
						) ) }
					</ul>
				) }
			</section>

			{ summary.woocommerce.staging && false === summary.woocommerce.live && (
				<Alert tone="warning" title={ __( 'WooCommerce is not active on live', 'keepmerge-push-staging-to-live' ) }>
					<p className="sgsp-m-0">{ __( 'Products are skipped, and the WooCommerce tab is hidden, until WooCommerce is active on both sites.', 'keepmerge-push-staging-to-live' ) }</p>
				</Alert>
			) }
			<p className="sgsp-field__hint">
				{ __( 'Never part of a push: orders, refunds, customers, coupons, users and comments. Tax rates, shipping zones and payment settings are not pushed either: set those on live.', 'keepmerge-push-staging-to-live' ) }
			</p>
		</div>
	);
}

/**
 * The Warnings sub-tab: what this plan cannot do as asked, grouped, with sample items.
 *
 * @param {Object} props
 * @param {Object} props.summary  Plan summary.
 * @param {*}      props.children The list of items with warnings.
 */
export function WarningsPanel( { summary, children } ) {
	// Missing themes and block types are listed once, by name, from the plan itself.
	const codes = Object.keys( summary.warnings ).filter( ( code ) => ! [ 'theme_missing', 'missing_blocks' ].includes( code ) );
	const order = [ 'held_for_review', 'theme_missing', 'missing_blocks', 'unmapped_refs', 'unsupported', 'woo_not_on_live', 'excluded_on_live', 'blocked', 'dependency_missing', 'slug_taken', 'stock_changed_on_live' ];
	codes.sort( ( a, b ) => order.indexOf( a ) - order.indexOf( b ) );

	return (
		<div className="sgsp-stack">
			{ 0 === codes.length && 0 === summary.missing_themes.length && 0 === summary.missing_blocks.length ? (
				<p className="sgsp-baseline__empty">{ __( 'No warnings: nothing in this plan needs extra attention.', 'keepmerge-push-staging-to-live' ) }</p>
			) : (
				<ul className="sgsp-warnlist">
					{ summary.missing_themes.length > 0 && (
						<li className="sgsp-warnlist__item">
							<strong className="sgsp-warnlist__title">{ __( 'Themes not installed on live', 'keepmerge-push-staging-to-live' ) }</strong>
							<span className="sgsp-warnlist__text">
								{ summary.missing_themes.join( ', ' ) }
								{ ' — ' }
								{ __( 'their templates, parts and global styles are skipped. Found from the plan, without the code report.', 'keepmerge-push-staging-to-live' ) }
							</span>
						</li>
					) }
					{ summary.missing_blocks.length > 0 && (
						<li className="sgsp-warnlist__item">
							<strong className="sgsp-warnlist__title">{ __( 'Block types not registered on live', 'keepmerge-push-staging-to-live' ) }</strong>
							<span className="sgsp-warnlist__text">
								{ summary.missing_blocks
									.map( ( block ) =>
										sprintf(
											/* translators: 1: block type name, 2: number of items. */
											_n( '%1$s (%2$s item)', '%1$s (%2$s items)', block.items, 'keepmerge-push-staging-to-live' ),
											block.name,
											number( block.items )
										)
									)
									.join( ', ' ) }
							</span>
						</li>
					) }
					{ codes.map( ( code ) => {
						const info = warningInfo( code );
						const warning = summary.warnings[ code ];
						return (
							<li key={ code } className="sgsp-warnlist__item">
								<strong className="sgsp-warnlist__title">
									{ info.title } <span className="sgsp-code-section__count">{ number( warning.count ) }</span>
								</strong>
								<span className="sgsp-warnlist__text">{ info.text }</span>
								{ warning.samples.length > 0 && (
									<span className="sgsp-warnlist__samples">
										{ warning.samples.map( ( sample ) => sample.title || __( '(no title)', 'keepmerge-push-staging-to-live' ) ).join( ' · ' ) }
										{ warning.count > warning.samples.length ? ' …' : '' }
									</span>
								) }
							</li>
						);
					} ) }
				</ul>
			) }
			<Fragment>{ children }</Fragment>
		</div>
	);
}
