import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Skeleton from './Skeleton';

/**
 * DataTable: the shared table. On narrow screens every row turns into a small card with the
 * column name before each value (data-label + CSS), so the page never scrolls sideways.
 * Cell content is rendered as text by React; nothing is inserted as HTML.
 *
 * @param {Object}   props
 * @param {string}   props.caption      Caption for screen readers.
 * @param {Array}    props.columns      [ { key, label, className, render( row ) } ].
 * @param {Array}    props.rows         Rows.
 * @param {Function} props.getRowKey    row => key.
 * @param {boolean}  [props.isLoading]  Show placeholder rows.
 * @param {number}   [props.skeletonRows] Placeholder rows.
 */
export default function DataTable( {
	caption,
	columns,
	rows,
	getRowKey,
	isLoading = false,
	skeletonRows = 5,
} ) {
	return (
		<div className="sgsp-table-scroll">
			<table className="sgsp-datatable" aria-busy={ isLoading }>
				<caption className="screen-reader-text">{ caption }</caption>
				<thead>
					<tr>
						{ columns.map( ( column ) => (
							<th key={ column.key } scope="col" className={ column.className }>
								{ column.label }
							</th>
						) ) }
					</tr>
				</thead>
				<tbody>
					{ isLoading
						? Array.from( { length: skeletonRows } ).map( ( unused, index ) => (
								<tr key={ index }>
									{ columns.map( ( column ) => (
										<td key={ column.key } data-label={ column.label }>
											<Skeleton width={ index % 2 ? '70%' : '90%' } />
										</td>
									) ) }
								</tr>
						  ) )
						: rows.map( ( row ) => (
								<tr key={ getRowKey( row ) }>
									{ columns.map( ( column ) => (
										<td
											key={ column.key }
											className={ column.className }
											data-label={ column.label }
										>
											{ column.render( row ) }
										</td>
									) ) }
								</tr>
						  ) ) }
				</tbody>
			</table>
			{ isLoading && (
				<span className="screen-reader-text" role="status">
					{ __( 'Loading…', 'keepmerge-push-staging-to-live' ) }
				</span>
			) }
		</div>
	);
}
