/**
 * The sortable column header the overview tables share.
 *
 * The caret is its own element with no space before it, so it can never wrap
 * onto a line of its own beneath the label; its gap is the caret's margin.
 */

import { useState, useCallback } from '@wordpress/element';

/** The caret each sort direction draws. */
const CARETS = { asc: '▲', desc: '▼' };

/**
 * One column header that sorts, carrying the caret for the current sort.
 *
 * The parent owns the sort: this reports the click and draws the direction
 * it is handed.
 *
 * @param {Object}                  props           Component props.
 * @param {string}                  props.field     Sort field this header stands for.
 * @param {string}                  props.label     Already-translated column label.
 * @param {?string}                 [props.dir]     'asc' or 'desc' on the sorted column; null on every other.
 * @param {string}                  [props.variant] Alignment modifier: 'numeric', 'center' or ''.
 * @param {(field: string) => void} props.onSort    Receives `field` on click.
 * @return {import('react').ReactElement} Header element.
 */
export default function SortHeaderButton( {
	field,
	label,
	dir = null,
	variant = '',
	onSort,
} ) {
	return (
		<button
			type="button"
			data-field={ field }
			className={ `newspack-nodes-sortable-header-button event-logger-table__header-btn newspack-nodes-table__cell${
				variant ? ` event-logger-table__header-btn--${ variant }` : ''
			}` }
			onClick={ () => onSort( field ) }
		>
			{ label }
			{ CARETS[ dir ] && (
				<span className="event-logger-table__sort-caret">
					{ CARETS[ dir ] }
				</span>
			) }
		</button>
	);
}

/**
 * A table's own column sort, as the overview's URL tables sort: a second
 * click on the sorted column flips it, and a new column sorts descending.
 *
 * `sorted` reads each row's value through `valueOf`, comparing strings by
 * locale and anything else as a number; equal rows keep their order.
 *
 * @param {string} field The column the rows first sort by.
 * @return {{sorted: (rows: Array<Object>, valueOf: (row: Object, field: string) => (string|number)) => Array<Object>, dirOf: (field: string) => ?string, onSort: (field: string) => void}} The sort.
 */
export function useColumnSort( field ) {
	const [ sort, setSort ] = useState( { field, dir: 'desc' } );
	const onSort = useCallback(
		( next ) =>
			setSort( ( current ) =>
				current.field === next
					? {
							field: next,
							dir: 'desc' === current.dir ? 'asc' : 'desc',
					  }
					: { field: next, dir: 'desc' }
			),
		[]
	);
	const dirOf = ( name ) => ( sort.field === name ? sort.dir : null );
	const sorted = ( rows, valueOf ) => {
		const sign = 'asc' === sort.dir ? 1 : -1;
		return [ ...rows ].sort( ( a, b ) => {
			const x = valueOf( a, sort.field );
			const y = valueOf( b, sort.field );
			return (
				sign * ( 'string' === typeof x ? x.localeCompare( y ) : x - y )
			);
		} );
	};
	return { sorted, dirOf, onSort };
}
