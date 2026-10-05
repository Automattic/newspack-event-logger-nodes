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
 * All of it also lives in the address bar — `?sort=`, `?order=`, `?q=`,
 * `?errors=`, `?workers=` and `?paged=` — so a shared link opens on the same
 * view. The page is `?paged=`, WordPress's own name, because `?page=` names
 * the admin screen. It owns neither the server filter nor the five-minute
 * bucket a chart click above sets: the dashboard holds both and hands them in.
 *
 * Rows virtualize against window scroll, and each row's URL cell carries a
 * background bar scaling the active chart metric against the page's p95.
 */

import {
	useMemo,
	useState,
	useRef,
	useCallback,
	useEffect,
	memo,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';
import useVirtualization from '@newspack-nodes/shared/hooks/useVirtualization';
import { PAGE_CONTENT_CLASS } from '../components/DashboardShell';
import SortHeaderButton from './components/SortHeaderButton';
import BucketChip from './components/BucketChip';
import { gridTemplate } from '@newspack-nodes/shared/hooks/useColumnPicker';
import {
	formatAge,
	formatGroupedCount,
} from '@newspack-nodes/shared/utils/formatters';
import {
	useQueryParamState,
	useQueryParamChoice,
	useQueryParamFlag,
} from '@newspack-nodes/shared/hooks/useQueryParamState';

/**
 * Row height in pixels.
 *
 * The virtualizer's arithmetic and each row's inline style read this one
 * constant. Let them disagree and the padding spacers mis-size the runway,
 * drifting the visible window away from the scroll position.
 */
const ROW_HEIGHT = 40;

/**
 * The page a `?paged=` value names: a positive whole number, else the first.
 *
 * @param {?string} raw The param's value; null when absent.
 * @return {number} The 1-based page.
 */
const restorePage = ( raw ) => {
	const n = Number( raw );
	// Safe, or `1e21` reaches the wire as an exponent the verb refuses.
	return Number.isSafeInteger( n ) && n > 0 ? n : 1;
};

/**
 * Page size, in rows.
 *
 * `usePerformanceGraph` fixes the `urls` verb's `limit` at 100 to match, since
 * every `offset` this table sends is derived from this number: the two are one
 * page size split across two files.
 */
const URLS_PER_PAGE = 100;

/**
 * The last offset the `urls` verb serves
 * (`Performance_CI_Node::URLS_MAX_OFFSET`); it clamps a later one to this, so
 * the pager stops at the page that starts here.
 */
const URLS_MAX_OFFSET = 10000;

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
 * `width` is the column's grid track. `errorsLabel` heads the column instead
 * of `label` on an errors-only page, whose count column shows `errors`.
 *
 * A `status` column's `status` is a representative code, not a count of that
 * one status: the shared `.entry-status[data-status^="2"]` rules colour the
 * cell by the first digit, so any 2xx code paints the 2xx column.
 *
 * @type {Array<{field: string, width: string, label: string, errorsLabel?: string, kind: string, status?: string, render?: Function}>}
 */
const COLUMNS = [
	{
		field: 'count',
		// Fits "Errors" and its caret in every monospace skin's face (63px).
		width: '66px',
		label: __( 'Reqs', 'newspack-event-logger-nodes' ),
		errorsLabel: __( 'Errors', 'newspack-event-logger-nodes' ),
		render: ( url, formatNum, now, errorCounts ) =>
			formatNum( errorCounts ? url.errors : url.count ),
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
	{
		field: 'last_updated',
		width: '90px',
		label: __( 'Last seen', 'newspack-event-logger-nodes' ),
		render: ( url, formatNum, now ) =>
			now > 0 ? formatAge( url.last_updated, now ) : '-',
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

/**
 * The fields a header click can sort on, and so the `?sort=` whitelist.
 *
 * Every column but the `status` shares, which are no sort key.
 */
const SORT_FIELDS = COLUMNS.filter( ( col ) => 'status' !== col.kind ).map(
	( col ) => col.field
);

const SORT_ORDERS = [ 'asc', 'desc' ];

/** Per-kind cell modifiers; `code` adds none. */
const CELL_CLASS = {
	code: '',
	numeric: ' event-logger-table__cell--numeric',
	status: ' event-logger-table__cell--status entry-status',
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
 * @param {Object}   col         Column declaration from COLUMNS.
 * @param {Object}   url         One row of the `urls` reply.
 * @param {Function} formatNum   Number formatter, from the table.
 * @param {number}   now         Unix timestamp the page's ages are measured from.
 * @param {boolean}  errorCounts Whether the count cell shows the row's `errors`.
 * @return {string|import('react').ReactElement} Cell content.
 */
const renderCell = ( col, url, formatNum, now, errorCounts ) => {
	if ( 'status' === col.kind ) {
		return pct( url[ col.field ], url.count );
	}
	return col.render
		? col.render( url, formatNum, now, errorCounts )
		: formatNum( url[ col.field ], 'ms' );
};

/**
 * What the table says in place of rows: why it has none it can vouch for.
 *
 * @param {?string} error      The last refusal, which outranks every rows state.
 * @param {boolean} loading    Whether a page is asked for and not yet answered.
 * @param {string}  searchTerm The live search, '' for none.
 * @return {string} The message.
 */
const emptyText = ( error, loading, searchTerm ) => {
	if ( error ) {
		return sprintf(
			// translators: %s: the error message.
			__( 'Could not load URLs: %s', 'newspack-event-logger-nodes' ),
			error
		);
	}
	if ( loading ) {
		return __( 'Loading URLs…', 'newspack-event-logger-nodes' );
	}
	return searchTerm
		? sprintf(
				// translators: %s: the URL search term.
				__( 'No URLs match "%s"', 'newspack-event-logger-nodes' ),
				searchTerm
		  )
		: __( 'No URLs to display', 'newspack-event-logger-nodes' );
};

/**
 * The row field a URL cell's bar measures, the one the table scales by too.
 *
 * `volume` bars what the count column shows — `errors` on an errors-only
 * page — `memory` the peak, and the two time metrics `avg_ms`.
 *
 * @param {string}  metric      The chart metric the bars follow.
 * @param {boolean} errorCounts Whether the count column shows `errors`.
 * @return {string} The row field.
 */
const barFieldFor = ( metric, errorCounts ) => {
	if ( 'memory' === metric ) {
		return 'avg_peak_mb';
	}
	if ( 'volume' === metric ) {
		return errorCounts ? 'errors' : 'count';
	}
	return 'avg_ms';
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
	 * @param {Object}                       props             Component props.
	 * @param {Object}                       props.url         One row of the `urls` reply.
	 * @param {boolean}                      props.isSelected  Whether the detail modal is open on this row.
	 * @param {(url: Object) => void}        props.onSelect    Receives the row on click or Enter/Space.
	 * @param {(n: number, s?: string) => *} props.formatNum   Number formatter, from the table.
	 * @param {number}                       props.maxAvg      The page's p95 of the measured bar metric; 0 draws no bar.
	 * @param {string}                       props.barField    The row field the bar measures, from `barFieldFor()`.
	 * @param {number}                       props.now         Unix timestamp the page's ages are measured from.
	 * @param {boolean}                      props.errorCounts Whether the count cell shows the row's `errors`.
	 * @return {import('react').ReactElement} Rendered row.
	 */
	function UrlRow( {
		url,
		isSelected,
		onSelect,
		formatNum,
		maxAvg,
		barField,
		now,
		errorCounts,
	} ) {
		// A URL no timed request reached is unmeasured, and draws no bar.
		const barValue = url[ barField ];
		let barStyle;
		if ( Number.isFinite( barValue ) ) {
			const barPct = maxAvg > 0 ? ( barValue / maxAvg ) * 100 : 0;
			barStyle = {
				background: `linear-gradient(to right, rgba(100, 181, 246, 0.15) ${ barPct }%, transparent ${ barPct }%)`,
			};
		}
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
						{ renderCell( col, url, formatNum, now, errorCounts ) }
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
 * @param {Object}                   props                 Component props.
 * @param {Array<Object>}            props.urls            The page of rows the `urls` verb returned.
 * @param {?Object}                  props.selectedUrl     The row the detail modal is open on, or null.
 * @param {(url: Object) => void}    props.onSelect        Receives a row on click or Enter/Space, and is forwarded to each row.
 * @param {(params: Object) => void} props.onParamsChange  Receives `search`, `sort`, `order`, `offset`, `errorsOnly`, `includeWorkers` and `bucket` whenever one of them changes.
 * @param {string}                   [props.bucket]        The five-minute bucket key the dashboard narrowed the table to; '' for none.
 * @param {string}                   [props.server]        The server the dashboard scoped the table to; '' for every server.
 * @param {() => void}               [props.onClearBucket] Drops that bucket, from its chip.
 * @param {?number}                  [props.totalUrls]     Rows the server's filters left, the synthetic overflow rows included; the pager counts rows, not distinct URLs. Null until a reply has counted them.
 * @param {string}                   [props.metric]        Chart metric the row bars scale.
 * @param {boolean}                  [props.ranked]        Whether the server answered from its per-bucket ranked lists rather than the whole index.
 * @param {number}                   [props.now]           Unix seconds the page's rows were current at, from the reply's `as_of`; ages are measured from it so browser and server clocks never disagree and a cached page does not tick.
 * @param {boolean}                  [props.errorCounts]   Whether the reply was built under "Errors Only", so the count column shows each row's `errors`.
 * @param {?string}                  [props.error]         The last `urls` refusal; the table shows it in place of rows it cannot vouch for.
 * @param {boolean}                  [props.loading]       Whether a page is asked for and not yet answered.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function UrlTable( {
	urls,
	selectedUrl,
	onSelect,
	onParamsChange,
	bucket = '',
	server = '',
	onClearBucket,
	totalUrls = null,
	metric = 'volume',
	ranked = false,
	now = 0,
	errorCounts = false,
	error = null,
	loading = false,
} ) {
	const [ sortField, setSortField ] = useQueryParamChoice(
		'sort',
		SORT_FIELDS,
		'count'
	);
	const [ sortOrder, setSortOrder ] = useQueryParamChoice(
		'order',
		SORT_ORDERS,
		'desc'
	);
	// `?search=` is the request search's, so the table's is `?q=`.
	const [ searchTerm, setSearchTerm ] = useQueryParamState(
		'q',
		( raw ) => raw ?? '',
		String
	);
	const [ errorsOnly, setErrorsOnly ] = useQueryParamFlag( 'errors' );
	// Opts IN, where Errors opts in to narrow; workers are out by default.
	const [ includeWorkers, setIncludeWorkers ] =
		useQueryParamFlag( 'workers' );
	const [ currentPage, setCurrentPage ] = useQueryParamState(
		'paged',
		restorePage,
		( n ) => ( 1 === n ? null : String( n ) )
	);
	// A new set starts on page 1, in render: no ask takes the old offset.
	const filters = JSON.stringify( [
		searchTerm,
		sortField,
		sortOrder,
		errorsOnly,
		includeWorkers,
		bucket,
		server,
	] );
	const [ pagedFilters, setPagedFilters ] = useState( filters );
	if ( pagedFilters !== filters ) {
		setPagedFilters( filters );
		setCurrentPage( 1 );
	}
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
	 * rows first on the columns that rank them.
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
	};

	// Clamped on READ: a shrinking set strands the page, pager and all.
	const total = totalUrls ?? 0;
	const totalPages = Math.max(
		1,
		Math.min(
			Math.ceil( total / URLS_PER_PAGE ),
			URLS_MAX_OFFSET / URLS_PER_PAGE + 1
		)
	);
	// An unmeasured set clamps nothing, so a linked page survives to its reply.
	const page =
		null === totalUrls ? currentPage : Math.min( currentPage, totalPages );
	const offset = ( page - 1 ) * URLS_PER_PAGE;
	// Move the state too, or a growing set springs the page back.
	useEffect( () => {
		if ( currentPage !== page ) {
			setCurrentPage( page );
		}
	}, [ currentPage, page, setCurrentPage ] );

	useEffect( () => {
		onParamsChange?.( {
			search: searchTerm,
			sort: sortField,
			order: sortOrder,
			offset,
			errorsOnly,
			includeWorkers,
			bucket,
		} );
	}, [
		searchTerm,
		sortField,
		sortOrder,
		page,
		offset,
		errorsOnly,
		includeWorkers,
		bucket,
		onParamsChange,
	] );

	const filteredUrls = urls;

	const barField = barFieldFor( metric, errorCounts );
	// Bar-background max uses p95 so outliers don't blow out the scale.
	const maxAvg = useMemo( () => {
		const values = filteredUrls
			.map( ( u ) => u[ barField ] )
			.filter( Number.isFinite )
			.sort( ( a, b ) => a - b );
		if ( values.length === 0 ) {
			return 0;
		}
		const p95Index = Math.floor( values.length * 0.95 );
		return values[ Math.min( p95Index, values.length - 1 ) ];
	}, [ filteredUrls, barField ] );

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
		return formatGroupedCount( Math.round( num ) ) + suffix;
	}, [] );

	// The page scrolls, not the window; the list scrolls only sideways.
	const { startIndex, endIndex, paddingTop, paddingBottom } =
		useVirtualization(
			listRef,
			ROW_HEIGHT,
			filteredUrls.length,
			`.${ PAGE_CONTENT_CLASS }`
		);
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
							'Search by whole URL word…',
							'newspack-event-logger-nodes'
						) }
						value={ searchTerm }
						onChange={ setSearchTerm }
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
				<BucketChip bucket={ bucket } onClear={ onClearBucket } />
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
							<SortHeaderButton
								key={ col.field }
								field={ col.field }
								label={
									errorCounts && col.errorsLabel
										? col.errorsLabel
										: col.label
								}
								dir={
									sortField === col.field ? sortOrder : null
								}
								variant={
									'numeric' === col.kind ? 'numeric' : ''
								}
								onSort={ handleSort }
							/>
						)
					) }
				</div>

				<div
					ref={ listRef }
					className="event-logger-table__list newspack-nodes-table"
					style={ { paddingTop, paddingBottom } }
				>
					{ error || filteredUrls.length === 0 ? (
						<div
							className={ `event-logger-table__empty newspack-nodes-empty-state${
								error ? ' newspack-nodes-status is-error' : ''
							}` }
						>
							{ emptyText( error, loading, searchTerm ) }
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
								barField={ barField }
								now={ now }
								errorCounts={ errorCounts }
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
							formatGroupedCount( offset + 1 ),
							formatGroupedCount(
								Math.min( offset + URLS_PER_PAGE, total )
							),
							formatGroupedCount( total )
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
							formatGroupedCount( total )
						) }
					{ ranked && (
						<>
							{ total > 0 && ' · ' }
							<span className="event-logger-table__ranked-note">
								{ __(
									'Ranked per bucket; Avg and Mem are means of bucket averages',
									'newspack-event-logger-nodes'
								) }
							</span>
						</>
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
