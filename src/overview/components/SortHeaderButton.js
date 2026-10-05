/**
 * The sortable column header the overview tables share.
 *
 * The caret is its own element with no space before it, so it can never wrap
 * onto a line of its own beneath the label; its gap is the caret's margin.
 */

/** The caret each sort direction draws. */
const CARETS = { asc: '▲', desc: '▼' };

/**
 * One column header that sorts, carrying the caret for the current sort.
 *
 * Sorting is the parent's: this reports the click and draws the direction it
 * is handed.
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
