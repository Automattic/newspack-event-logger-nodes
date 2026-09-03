/**
 * The Performance dashboard's URL leaderboard.
 *
 * The server owns the set. `PerformanceDashboard` polls the `performance` CI's
 * `urls` verb and this table draws the page it is handed, in the order it
 * arrives: the search box, the sort headers, the two filter toggles and the
 * pager are local state that only reports itself upward through
 * `onParamsChange`. Re-applying any of it here makes the footer's total
 * describe a different population than the rows, and `localeCompare` re-orders
 * a page the server already cut with PHP's byte-order `<=>`, so rows skip and
 * repeat across pages.
 *
 * Rows virtualize against window scroll, and each row's URL cell carries a
 * background bar scaling the active chart metric against the page's p95.
 */

import {
	useState,
	useMemo,
	useRef,
	useCallback,
	useEffect,
	memo,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';
import useVirtualization from '@newspack-nodes/shared/hooks/useVirtualization';
import { gridTemplate } from '@newspack-nodes/shared/hooks/useColumnPicker';

/**
 * Row height in pixels.
 *
 * The virtualizer's arithmetic and each row's inline style read this one
 * constant. Let them disagree and the padding spacers mis-size the runway,
 * drifting the visible window away from the scroll position.
 */
const ROW_HEIGHT = 40;

/**
 * Page size, in rows.
 *
 * `usePerformanceGraph` fixes the `urls` verb's `limit` at 100 to match, since
 * every `offset` this table sends is derived from this number: the two are one
 * page size split across two files.
 */
const URLS_PER_PAGE = 100;

/**
 * One status class's share of a row's requests, as a whole percentage.
 *
 * A share that rounds to zero reads '-' rather than '0%', so a scan down the
 * four status columns lands on the ones carrying traffic.
 *
 * @param {number} part  Requests in this status class.
 * @param {number} total Requests on the row.
 * @return {string} The percentage, or '-' when it rounds to zero.
 */
const pct = ( part, total ) => {
	if ( ! total || total === 0 ) {
		return '-';
	}
	const p = Math.round( ( ( part || 0 ) / total ) * 100 );
	return p > 0 ? `${ p }%` : '-';
};

/**
 * The one column list; the header and every row are two renderings of it.
 *
 * `kind` defaults to `numeric` — a sortable right-aligned header over a
 * numeric cell. `status` is an unsortable HTTP-class heading over a `pct()`
 * share, `code` the sortable URL heading over the bar-backed `<code>` cell.
 * `render` overrides the default `formatNum( url[ field ], 'ms' )`, and
 * `width` is the column's grid track.
 *
 * A `status` column's `status` is a representative code, not a count of that
 * one status: the shared `.entry-status[data-status^="2"]` rules colour the
 * cell by the first digit, so any 2xx code paints the 2xx column.
 *
 * @type {Array<{field: string, width: string, label: string, kind: string, status?: string, render?: Function}>}
 */
const COLUMNS = [
	{
		field: 'count',
		width: '60px',
		label: __( 'Reqs', 'newspack-event-logger-nodes' ),
		render: ( url, formatNum ) => formatNum( url.count ),
	},
	{
		field: 'url',
		width: 'minmax(0, 1fr)',
		label: __( 'URL', 'newspack-event-logger-nodes' ),
		kind: 'code',
		render: ( url ) => (
			<code>
				{ url.aggregate
					? __(
							'traffic from URLs beyond the per-shard cap',
							'newspack-event-logger-nodes'
					  )
					: url.url }
			</code>
		),
	},
	{
		field: 'count_2xx',
		label: '2xx',
		kind: 'status',
		status: '218',
		width: '50px',
	},
	{
		field: 'count_3xx',
		label: '3xx',
		kind: 'status',
		status: '307',
		width: '50px',
	},
	{
		field: 'count_4xx',
		label: '4xx',
		kind: 'status',
		status: '418',
		width: '50px',
	},
	{
		field: 'count_5xx',
		label: '5xx',
		kind: 'status',
		status: '599',
		width: '50px',
	},
	{
		field: 'avg_ms',
		label: __( 'Avg', 'newspack-event-logger-nodes' ),
		width: '55px',
	},
	{
		field: 'min_ms',
		label: __( 'Min', 'newspack-event-logger-nodes' ),
		width: '55px',
	},
	{
		field: 'max_ms',
		label: __( 'Max', 'newspack-event-logger-nodes' ),
		width: '55px',
	},
	{
		field: 'avg_peak_mb',
		width: '60px',
		label: __( 'Mem', 'newspack-event-logger-nodes' ),
		render: ( url, formatNum ) =>
			url.avg_peak_mb > 0 ? formatNum( url.avg_peak_mb, 'MB' ) : '-',
	},
].map( ( col ) => ( { kind: 'numeric', ...col } ) );

/**
 * The `grid-template-columns` the header and every row are laid out on.
 *
 * One owner, derived from COLUMNS itself, so a deleted column cannot leave a
 * stray track behind for the cells after it to slide into.
 */
const GRID_TEMPLATE = gridTemplate(
	Object.fromEntries( COLUMNS.map( ( col ) => [ col.field, col ] ) ),
	COLUMNS.map( ( col ) => col.field )
);

/** Per-kind cell modifiers; `code` adds none. */
const CELL_CLASS = {
	code: '',
	numeric: ' event-logger-table__cell--numeric',
	status: ' event-logger-table__cell--status entry-status',
};

/**
 * Per-kind header modifiers; `code` adds none.
 *
 * `status` needs no entry: an HTTP-class share is not a sort key, so that
 * heading is a `<span>` and never reaches this map.
 */
const HEADER_CLASS = {
	code: '',
	numeric: ' event-logger-table__header-btn--numeric',
};

/**
 * The class list for one cell, in the header or in a row.
 *
 * @param {Object} col Column declaration from COLUMNS.
 * @return {string} The canonical table-cell classes plus the kind's modifier.
 */
const cellClass = ( col ) =>
	`event-logger-table__cell newspack-nodes-table__cell${
		CELL_CLASS[ col.kind ]
	}`;

/**
 * Render one column's cell for one URL.
 *
 * A `status` column declares no `render` because its cell divides two fields,
 * the class count over the row's total, which a single field lookup cannot
 * express.
 *
 * @param {Object}   col       Column declaration from COLUMNS.
 * @param {Object}   url       One row of the `urls` reply.
 * @param {Function} formatNum Number formatter, from the table.
 * @return {string|import('react').ReactElement} Cell content.
 */
const renderCell = ( col, url, formatNum ) => {
	if ( 'status' === col.kind ) {
		return pct( url[ col.field ], url.count );
	}
	return col.render
		? col.render( url, formatNum )
		: formatNum( url[ col.field ], 'ms' );
};

// JSDoc rides the inner function: on the const, memo() infers props as `{}`.
const UrlRow = memo(
	/**
	 * One URL row, memoized so a scroll re-renders only the rows that entered
	 * the window.
	 *
	 * The URL cell carries a background bar whose width is this row's value as
	 * a fraction of `maxAvg`; a row above that value fills the cell and stops.
	 * A selectable row is a button carrying the `?` picker's `url:`
	 * descriptor, so a picker click asks about this URL rather than the page.
	 *
	 * @param {Object}                       props            Component props.
	 * @param {Object}                       props.url        One row of the `urls` reply.
	 * @param {boolean}                      props.isSelected Whether the detail modal is open on this row.
	 * @param {(url: Object) => void}        props.onSelect   Receives the row on click or Enter/Space.
	 * @param {(n: number, s?: string) => *} props.formatNum  Number formatter, from the table.
	 * @param {number}                       props.maxAvg     The page's p95 of the bar metric; 0 draws no bar.
	 * @param {string}                       props.metric     'memory' bars avg_peak_mb, 'volume' bars count, 'avg' and 'cumulative' bar avg_ms.
	 * @return {import('react').ReactElement} Rendered row.
	 */
	function UrlRow( {
		url,
		isSelected,
		onSelect,
		formatNum,
		maxAvg,
		metric,
	} ) {
		let barField = 'avg_ms';
		if ( metric === 'memory' ) {
			barField = 'avg_peak_mb';
		} else if ( metric === 'volume' ) {
			barField = 'count';
		}
		const barValue = url[ barField ] || 0;
		const barPct = maxAvg > 0 ? ( barValue / maxAvg ) * 100 : 0;
		const barStyle = {
			background: `linear-gradient(to right, rgba(100, 181, 246, 0.15) ${ barPct }%, transparent ${ barPct }%)`,
		};
		const handleKeyDown = ( e ) => {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				onSelect( url );
			}
		};

		// Stands for many URLs; its key is no url_hash, so nothing to open.
		const selectable = ! url.aggregate;

		return (
			<div
				{ ...( selectable
					? {
							role: 'button',
							tabIndex: 0,
							'data-ask': `url:${ url.hash }`,
							onClick: () => onSelect( url ),
							onKeyDown: handleKeyDown,
					  }
					: {} ) }
				className={ `event-logger-table__row newspack-nodes-table__row${
					isSelected ? ' is-selected' : ''
				}${ selectable ? '' : ' is-aggregate' }` }
				style={ {
					height: ROW_HEIGHT,
					gridTemplateColumns: GRID_TEMPLATE,
				} }
			>
				{ COLUMNS.map( ( col ) => (
					<div
						key={ col.field }
						data-field={ col.field }
						data-status={ col.status }
						className={ cellClass( col ) }
						style={ 'code' === col.kind ? barStyle : undefined }
					>
						{ renderCell( col, url, formatNum ) }
					</div>
				) ) }
			</div>
		);
	}
);

/**
 * The URL leaderboard: its search and filter controls, the virtualized table,
 * and the pager beneath it.
 *
 * @param {Object}                   props                Component props.
 * @param {Array<Object>}            props.urls           The page of rows the `urls` verb returned.
 * @param {?Object}                  props.selectedUrl    The row the detail modal is open on, or null.
 * @param {(url: Object) => void}    props.onSelect       Receives a row on click or Enter/Space, and is forwarded to each row.
 * @param {(params: Object) => void} props.onParamsChange Receives `search`, `sort`, `order`, `offset`, `errorsOnly` and `includeWorkers` whenever one of them changes.
 * @param {number}                   props.totalUrls      Rows the server's filters left, the synthetic overflow rows included; the pager counts rows, not distinct URLs.
 * @param {string}                   [props.metric]       Chart metric the row bars scale.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function UrlTable( {
	urls,
	selectedUrl,
	onSelect,
	onParamsChange,
	totalUrls,
	metric = 'volume',
} ) {
	const [ sortField, setSortField ] = useState( 'count' );
	const [ sortOrder, setSortOrder ] = useState( 'desc' );
	const [ searchTerm, setSearchTerm ] = useState( '' );
	const [ errorsOnly, setErrorsOnly ] = useState( false );
	// Opts IN, where Errors opts in to narrow; workers are out by default.
	const [ includeWorkers, setIncludeWorkers ] = useState( false );
	const [ currentPage, setCurrentPage ] = useState( 1 );
	const listRef = useRef( null );
	const searchContainerRef = useRef( null );

	// Focus search input on '/'.
	useEffect( () => {
		const handleKeyDown = ( e ) => {
			if ( e.key === '/' ) {
				const tag = e.target.tagName;
				if ( tag === 'INPUT' || tag === 'TEXTAREA' ) {
					return;
				}
				e.preventDefault();
				const input =
					searchContainerRef.current?.querySelector( 'input' );
				if ( input ) {
					input.focus();
				}
			}
		};
		document.addEventListener( 'keydown', handleKeyDown );
		return () => document.removeEventListener( 'keydown', handleKeyDown );
	}, [] );

	/**
	 * Sort by a column, flipping direction when it already holds the sort.
	 *
	 * A new field starts descending, which puts the busiest and costliest
	 * rows first on the columns that rank them. The page resets: the same
	 * offset under a new order names a different set of rows.
	 *
	 * @param {string} field Field to sort by.
	 */
	const handleSort = ( field ) => {
		if ( sortField === field ) {
			setSortOrder( sortOrder === 'asc' ? 'desc' : 'asc' );
		} else {
			setSortField( field );
			setSortOrder( 'desc' );
		}
		setCurrentPage( 1 );
	};

	/**
	 * Search for a new term, from the first page.
	 *
	 * @param {string} value New search value.
	 */
	const handleSearchChange = ( value ) => {
		setSearchTerm( value );
		setCurrentPage( 1 );
	};

	// Clamped on READ: a shrinking set strands the page, pager and all.
	const total = totalUrls || 0;
	const totalPages = Math.max( 1, Math.ceil( total / URLS_PER_PAGE ) );
	const page = Math.min( currentPage, totalPages );
	const offset = ( page - 1 ) * URLS_PER_PAGE;
	// Move the state too, or a growing set springs the page back.
	useEffect( () => {
		if ( currentPage !== page ) {
			setCurrentPage( page );
		}
	}, [ currentPage, page ] );

	useEffect( () => {
		onParamsChange?.( {
			search: searchTerm,
			sort: sortField,
			order: sortOrder,
			offset,
			errorsOnly,
			includeWorkers,
		} );
	}, [
		searchTerm,
		sortField,
		sortOrder,
		page,
		offset,
		errorsOnly,
		includeWorkers,
		onParamsChange,
	] );

	const filteredUrls = urls;

	// Bar-background max uses p95 so outliers don't blow out the scale.
	const maxAvg = useMemo( () => {
		let field = 'avg_ms';
		if ( metric === 'memory' ) {
			field = 'avg_peak_mb';
		} else if ( metric === 'volume' ) {
			field = 'count';
		}
		const values = filteredUrls
			.map( ( u ) => u[ field ] || 0 )
			.sort( ( a, b ) => a - b );
		if ( values.length === 0 ) {
			return 0;
		}
		const p95Index = Math.floor( values.length * 0.95 );
		return values[ Math.min( p95Index, values.length - 1 ) ];
	}, [ filteredUrls, metric ] );

	/**
	 * Format a count or a measurement for one cell.
	 *
	 * @param {?number} num      The value; null and undefined read '-'.
	 * @param {string}  [suffix] Unit to append, such as 'ms' or 'MB'.
	 * @return {string} The rounded, locale-grouped number, or '-'.
	 */
	const formatNum = useCallback( ( num, suffix = '' ) => {
		if ( num === null || num === undefined ) {
			return '-';
		}
		return Math.round( num ).toLocaleString() + suffix;
	}, [] );

	/**
	 * The arrow marking the column the server sorted on.
	 *
	 * @param {string} field Field name.
	 * @return {string} ' ▲' ascending, ' ▼' descending, '' on every other column.
	 */
	const sortIndicator = ( field ) => {
		if ( sortField !== field ) {
			return '';
		}
		return sortOrder === 'asc' ? ' ▲' : ' ▼';
	};

	// Window scroll drives it; the list scrolls only sideways.
	const { startIndex, endIndex, paddingTop, paddingBottom } =
		useVirtualization( listRef, ROW_HEIGHT, filteredUrls.length, null );
	const visibleUrls = filteredUrls.slice( startIndex, endIndex );

	return (
		<div className="event-logger-table event-logger-table--urls">
			<div
				className="event-logger-url-search"
				style={ {
					marginBottom: '10px',
					display: 'flex',
					gap: '8px',
					alignItems: 'center',
				} }
			>
				<div ref={ searchContainerRef } style={ { flex: 1 } }>
					<TextControl
						__next40pxDefaultSize
						placeholder={ __(
							'Search URLs…',
							'newspack-event-logger-nodes'
						) }
						value={ searchTerm }
						onChange={ handleSearchChange }
						__nextHasNoMarginBottom
					/>
				</div>
				<button
					type="button"
					className={ errorsOnly ? 'button is-active' : 'button' }
					onClick={ () => setErrorsOnly( ! errorsOnly ) }
				>
					{ errorsOnly
						? __( 'Showing Errors', 'newspack-event-logger-nodes' )
						: __( 'Errors Only', 'newspack-event-logger-nodes' ) }
				</button>
				<button
					type="button"
					className={ includeWorkers ? 'button is-active' : 'button' }
					onClick={ () => setIncludeWorkers( ! includeWorkers ) }
				>
					{ includeWorkers
						? __( 'Showing Workers', 'newspack-event-logger-nodes' )
						: __(
								'Include Workers',
								'newspack-event-logger-nodes'
						  ) }
				</button>
			</div>

			{ /* One x-scroller, so header and rows keep the same tracks. */ }
			<div className="event-logger-table__scroll">
				<div
					className="event-logger-table__header newspack-nodes-table__header"
					role="row"
					style={ { gridTemplateColumns: GRID_TEMPLATE } }
				>
					{ COLUMNS.map( ( col ) =>
						'status' === col.kind ? (
							<span
								key={ col.field }
								data-field={ col.field }
								data-status={ col.status }
								className={ cellClass( col ) }
							>
								{ col.label }
							</span>
						) : (
							<button
								key={ col.field }
								type="button"
								data-field={ col.field }
								className={ `newspack-nodes-sortable-header-button event-logger-table__header-btn newspack-nodes-table__cell${
									HEADER_CLASS[ col.kind ]
								}` }
								onClick={ () => handleSort( col.field ) }
							>
								{ col.label }
								{ sortIndicator( col.field ) }
							</button>
						)
					) }
				</div>

				<div
					ref={ listRef }
					className="event-logger-table__list newspack-nodes-table"
					style={ { paddingTop, paddingBottom } }
				>
					{ filteredUrls.length === 0 ? (
						<div className="event-logger-table__empty newspack-nodes-empty-state">
							{ searchTerm
								? sprintf(
										// translators: %s: the URL search term.
										__(
											'No URLs match "%s"',
											'newspack-event-logger-nodes'
										),
										searchTerm
								  )
								: __(
										'No URLs to display',
										'newspack-event-logger-nodes'
								  ) }
						</div>
					) : (
						visibleUrls.map( ( url ) => (
							<UrlRow
								key={ url.hash }
								url={ url }
								isSelected={ selectedUrl?.hash === url.hash }
								onSelect={ onSelect }
								formatNum={ formatNum }
								maxAvg={ maxAvg }
								metric={ metric }
							/>
						) )
					) }
				</div>
			</div>

			<div className="event-logger-table__pagination">
				<span className="event-logger-table__pagination-info newspack-nodes-status">
					{ total > URLS_PER_PAGE &&
						sprintf(
							// translators: 1: first row number on the page, 2: last row number on the page, 3: total number of rows.
							__(
								'%1$s–%2$s of %3$s rows',
								'newspack-event-logger-nodes'
							),
							( offset + 1 ).toLocaleString(),
							Math.min(
								offset + URLS_PER_PAGE,
								total
							).toLocaleString(),
							total.toLocaleString()
						) }
					{ total > 0 &&
						total <= URLS_PER_PAGE &&
						sprintf(
							// translators: %s: number of rows in the table.
							_n(
								'%s row',
								'%s rows',
								total,
								'newspack-event-logger-nodes'
							),
							total.toLocaleString()
						) }
				</span>
				{ total > URLS_PER_PAGE && (
					<div className="event-logger-table__pagination-controls">
						<button
							type="button"
							className="event-logger-table__pagination-btn button button-small"
							disabled={ page <= 1 }
							onClick={ () => setCurrentPage( page - 1 ) }
						>
							‹ { __( 'Prev', 'newspack-event-logger-nodes' ) }
						</button>
						<span className="event-logger-table__pagination-page">
							{ sprintf(
								// translators: 1: current page number, 2: total number of pages.
								__(
									'Page %1$d of %2$d',
									'newspack-event-logger-nodes'
								),
								page,
								totalPages
							) }
						</span>
						<button
							type="button"
							className="event-logger-table__pagination-btn button button-small"
							disabled={ page >= totalPages }
							onClick={ () => setCurrentPage( page + 1 ) }
						>
							{ __( 'Next', 'newspack-event-logger-nodes' ) } ›
						</button>
					</div>
				) }
			</div>
		</div>
	);
}
