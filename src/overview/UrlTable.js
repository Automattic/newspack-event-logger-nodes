/**
 * The Performance dashboard's URL leaderboard, one row per page.
 *
 * A page is a URL's lowercased host and path, and its row folds every query
 * shape the set holds of it. A page of one shape is that URL, and a click
 * opens it; a page of more expands into its shapes, each a URL row beneath it
 * that its own slice answers, at most a page's worth and then how many more.
 *
 * The server owns the set. `PerformanceDashboard` polls the `performance` CI's
 * `urls` verb and this table draws the page it is handed, in the order it
 * arrives: the search box, the sort headers, the Include Workers toggle and
 * the pager are local state that only reports itself upward through
 * `onParamsChange`. Re-applying any of it here makes the footer's total
 * describe a different population than the rows, and `localeCompare` re-orders
 * a page the server already cut with PHP's byte-order `<=>`, so rows skip and
 * repeat across pages.
 *
 * All of it also lives in the address bar — `?sort=`, `?order=`, `?q=`,
 * `?workers=` and `?paged=` — so a shared link opens on the same view. The
 * page is `?paged=`, WordPress's own name, because `?page=` names the admin
 * screen. It owns neither the server filter, nor the chart's chips, nor the
 * bucket selection its Time field and the charts above edit: the dashboard
 * holds all three, and the table reads them only to start a new set on its
 * first page.
 *
 * Which pages are expanded is the dashboard's, which keeps it as `?expand=`.
 *
 * Pages and the shapes beneath them virtualize as one list against window
 * scroll, and each row's URL cell carries a background bar scaling the
 * active chart metric against the page's p95.
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
import { SearchControl, Spinner } from '@wordpress/components';
import { useNodeField } from '@newspack-nodes/runtime';
import useVirtualization from '@newspack-nodes/shared/hooks/useVirtualization';
import { PAGE_CONTENT_CLASS } from '../components/DashboardShell';
import SortHeaderButton from './components/SortHeaderButton';
import BucketField from './components/BucketField';
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
import {
	DEFAULT_CHART_METRIC,
	NO_PAGE_KEYS,
	SHAPES_PER_ASK,
	SORT_ORDERS,
	cellClass,
	pageKey,
	shapesSubject,
} from './constants';

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
 * The last offset the `urls` verb serves (`Stats_Query::MAX_OFFSET`); it
 * clamps a later one to this, so the pager stops at the page that starts here,
 * and an expanded page's shapes at the window that starts here.
 */
const URLS_MAX_OFFSET = 10000;

/**
 * How far apart the offsets an expanded page's shapes are asked from lie:
 * half of what one ask lists, so the rows in view always sit inside one
 * window, however near a step's edge the scroll stops.
 */
const SHAPES_STEP = SHAPES_PER_ASK / 2;

/** The most shapes the verb lists under a page: its last window's end. */
const SHAPES_REACH = URLS_MAX_OFFSET + SHAPES_PER_ASK;

/**
 * The offset an expanded page's shapes are asked from while its shape
 * `index` is the first in view, clamped as the table's pager is.
 *
 * @param {number} index The first shape in view, from 0.
 * @return {number} The offset.
 */
const shapesOffset = ( index ) =>
	Math.min(
		Math.floor( index / SHAPES_STEP ) * SHAPES_STEP,
		URLS_MAX_OFFSET
	);

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
 * share, `code` the sortable URL heading over the bar-backed `<code>` cell
 * holding the row's `url`, the name a page and a shape row alike carry.
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
		width: '66px',
		label: __( 'Reqs', 'newspack-event-logger-nodes' ),
		render: ( url, formatNum ) => formatNum( url.count ),
	},
	{
		field: 'url',
		width: 'minmax(0, 1fr)',
		label: __( 'URL', 'newspack-event-logger-nodes' ),
		kind: 'code',
		render: ( url ) => <code>{ url.url }</code>,
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
		render: ( url ) => lastSeen( url.last_updated ),
	},
].map( ( col ) => ( { kind: 'numeric', ...col } ) );

/**
 * How long ago a URL last completed a request, by the browser's clock in
 * whole seconds; a completion the clock has not reached yet reads as now.
 *
 * @param {?number} finishedAt Epoch seconds of its newest completion.
 * @return {string} Its age, or '-' for none.
 */
function lastSeen( finishedAt ) {
	const now = Math.floor( Date.now() / 1000 );
	return finishedAt
		? formatAge( Math.min( Math.floor( finishedAt ), now ), now )
		: '-';
}

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
 * Every column but the `status` shares, which are not sort keys.
 */
const SORT_FIELDS = COLUMNS.filter( ( col ) => 'status' !== col.kind ).map(
	( col ) => col.field
);

/**
 * Render one column's cell for one URL.
 *
 * A `status` column declares no `render` because its cell divides two fields,
 * the class count over the row's total, which a single field lookup cannot
 * express.
 *
 * @param {Object}   col       Column declaration from COLUMNS.
 * @param {Object}   url       One page or shape row.
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

/**
 * The table's rows in order: each page, and beneath a page of several shapes
 * the reader expanded, one slot per shape, then any shapes past the last the
 * verb lists as a count. A slot is a position, filled when the window of the
 * page's slice holding it answers, so every row is laid out before any shape
 * arrives.
 *
 * @param {Object[]}    pages    The page rows, as the server ordered them.
 * @param {Set<string>} expanded The keys of the pages expanded.
 * @return {Array<{page: Object, open?: boolean, shape?: number, more?: number}>} The rows.
 */
const layOut = ( pages, expanded ) =>
	pages.flatMap( ( page ) => {
		if ( ! ( 1 < page.shapes && expanded.has( pageKey( page ) ) ) ) {
			return [ { page } ];
		}
		const shown = Math.min( page.shapes, SHAPES_REACH );
		return [
			{ page, open: true },
			...Array.from( { length: shown }, ( _, shape ) => ( {
				page,
				shape,
			} ) ),
			...( shown < page.shapes
				? [ { page, more: page.shapes - shown } ]
				: [] ),
		];
	} );

/**
 * The visible rows with each page's run of shape slots folded into one
 * `{ shapesOf, from, to }`, which one component renders off the page's view.
 *
 * @param {Object[]} rows A window of `layOut()` rows.
 * @return {Object[]} The rows, each run of slots one entry.
 */
const shapeRuns = ( rows ) =>
	rows.reduce( ( runs, row ) => {
		const last = runs.at( -1 );
		if ( undefined === row.shape ) {
			runs.push( row );
		} else if ( last?.shapesOf === row.page ) {
			last.to = row.shape + 1;
		} else {
			runs.push( {
				shapesOf: row.page,
				from: row.shape,
				to: row.shape + 1,
			} );
		}
		return runs;
	}, [] );

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
 * `volume` bars the request count, `memory` the peak, and the two time
 * metrics `avg_ms`.
 *
 * @param {string} metric The chart metric the bars follow.
 * @return {string} The row field.
 */
const barFieldFor = ( metric ) => {
	if ( 'memory' === metric ) {
		return 'avg_peak_mb';
	}
	if ( 'volume' === metric ) {
		return 'count';
	}
	return 'avg_ms';
};

// JSDoc rides the inner function: on the const, memo() infers props as `{}`.
const UrlRow = memo(
	/**
	 * One page or shape row, memoized so a scroll re-renders only the rows
	 * that entered the window.
	 *
	 * The URL cell carries a background bar whose width is this row's value as
	 * a fraction of `maxAvg`; a row above that value fills the cell and stops.
	 * A row naming one URL is a button carrying the `?` picker's `url:`
	 * descriptor, so a picker click asks about that URL rather than the page,
	 * and a click opens it. A page of several shapes names none: its click
	 * expands or shuts it, behind the shared disclosure caret.
	 *
	 * @param {Object}                       props             Component props.
	 * @param {Object}                       props.url         A page row, or a shape's URL row.
	 * @param {boolean}                      [props.shape]     Whether it is a shape beneath its page.
	 * @param {?string}                      [props.expandKey] The key a page of several shapes toggles by; null for any other row.
	 * @param {boolean}                      [props.open]      Whether that page is expanded.
	 * @param {boolean}                      props.isSelected  Whether the detail modal is open on this row.
	 * @param {boolean}                      [props.isBusy]    Whether a click on it waits for its one request to open.
	 * @param {(url: Object) => void}        props.onSelect    Receives a row naming one URL on click or Enter/Space.
	 * @param {(key: string) => void}        [props.onToggle]  Receives `expandKey` on click or Enter/Space.
	 * @param {(n: number, s?: string) => *} props.formatNum   Number formatter, from the table.
	 * @param {number}                       props.maxAvg      The page's p95 of the measured bar metric; 0 draws no bar.
	 * @param {string}                       props.barField    The row field the bar measures, from `barFieldFor()`.
	 * @return {import('react').ReactElement} Rendered row.
	 */
	function UrlRow( {
		url,
		shape = false,
		expandKey = null,
		open = false,
		isSelected,
		isBusy = false,
		onSelect,
		onToggle,
		formatNum,
		maxAvg,
		barField,
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
		const expandable = null !== expandKey;
		const activate = () =>
			expandable ? onToggle( expandKey ) : onSelect( url );
		const handleKeyDown = ( e ) => {
			if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				activate();
			}
		};

		return (
			<div
				role="button"
				tabIndex={ 0 }
				data-ask={ url.hash ? `url:${ url.hash }` : undefined }
				aria-expanded={ expandable ? open : undefined }
				aria-busy={ isBusy || undefined }
				onClick={ activate }
				onKeyDown={ handleKeyDown }
				className={ `event-logger-table__row newspack-nodes-table__row${
					shape ? ' is-shape' : ''
				}${ isSelected ? ' is-selected' : '' }` }
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
						{ 'code' === col.kind && ! shape && (
							<span
								className={ `event-logger-table__caret${
									expandable
										? ' newspack-nodes-disclosure'
										: ''
								}` }
								aria-hidden="true"
							>
								{ expandable ? '▾' : '' }
							</span>
						) }
						{ renderCell( col, url, formatNum ) }
						{ 'code' === col.kind && isBusy && <Spinner /> }
					</div>
				) ) }
			</div>
		);
	}
);

/**
 * A row holding only a line of text in the URL column, under a page: why a
 * shape slot is empty, or how many shapes the page holds past those shown.
 *
 * @param {Object} props      Component props.
 * @param {string} props.text The line; '' for a slot still empty.
 * @return {import('react').ReactElement} Rendered row.
 */
function NoteRow( { text } ) {
	return (
		<div
			className="event-logger-table__row newspack-nodes-table__row is-shape is-note"
			style={ { height: ROW_HEIGHT, gridTemplateColumns: GRID_TEMPLATE } }
		>
			{ COLUMNS.map( ( col ) => (
				<div
					key={ col.field }
					data-field={ col.field }
					className={ `${ cellClass( col ) } is-muted` }
				>
					{ 'code' === col.kind ? text : null }
				</div>
			) ) }
		</div>
	);
}

/**
 * One run of an expanded page's shape slots, filled from the window of shapes
 * its slice's view holds, each shape keyed by its hash; a slot outside that
 * window waits for the next. Not memoized: the slice is built
 * after the row that reads it, so it re-reads its view on the table's next
 * render.
 *
 * @param {Object}  props          Component props.
 * @param {Object}  props.page     The page row.
 * @param {number}  props.from     Its first slot in this run.
 * @param {number}  props.to       One past its last slot.
 * @param {Object}  props.rowProps What every row takes from the table.
 * @param {?Object} props.selected The open URL, or null.
 * @param {?string} props.busy     The hash a click waits on, or null.
 * @return {import('react').ReactElement[]} The rows.
 */
function PageShapes( { page, from, to, rowProps, selected, busy } ) {
	const model = useNodeField( `${ shapesSubject( page ) }:view`, 'view' );
	// `rows` stays null until a reply counts the page's shapes, none included.
	const unanswered = null === ( model?.rows ?? null );
	const waiting = model?.error
		? sprintf(
				// translators: %s: the error message.
				__( 'Could not load URLs: %s', 'newspack-event-logger-nodes' ),
				model.error
		  )
		: __( 'Loading URLs…', 'newspack-event-logger-nodes' );
	return Array.from( { length: to - from }, ( _, at ) => {
		const index = from + at;
		const shape = model?.data?.[ index - model.offset ];
		return shape ? (
			<UrlRow
				key={ shape.hash }
				{ ...rowProps }
				url={ shape }
				shape
				isSelected={ selected?.hash === shape.hash }
				isBusy={ busy === shape.hash }
			/>
		) : (
			<NoteRow
				key={ `#${ index }` }
				text={ 0 === index && unanswered ? waiting : '' }
			/>
		);
	} );
}

/**
 * The URL leaderboard: its search and filter controls, the virtualized table,
 * and the pager beneath it.
 *
 * @param {Object}                     props                  Component props.
 * @param {Array<Object>}              props.urls             The page rows the `urls` verb returned.
 * @param {?Object}                    props.selectedUrl      The URL the detail modal is open on, or null.
 * @param {(url: Object) => void}      props.onSelect         Receives a row naming one URL, a one-shape page or a shape, on click or Enter/Space.
 * @param {string[]}                   [props.expanded]       The keys of the expanded pages, as `pageKey()` spells them.
 * @param {?string}                    [props.busy]           The URL hash a click waits on to open its one request; its row shows busy.
 * @param {(key: string) => void}      [props.onToggleExpand] Receives the key of a page of several shapes to expand or shut.
 * @param {(params: Object) => void}   props.onParamsChange   Receives `search`, `sort`, `order`, `offset`, `includeWorkers` and `bucket` whenever one of them changes.
 * @param {string}                     [props.bucket]         The spelling of the bucket selection the dashboard narrowed the table to; '' for none.
 * @param {string[]|null}              [props.slots]          The charted bucket keys, newest first, which the Time field resolves a typed time against.
 * @param {string}                     [props.server]         The server the dashboard scoped the table to; '' for every server.
 * @param {string[]}                   [props.chips]          The chart's chips, which narrow the table's set server-side; a change starts the new set on page 1.
 * @param {(spelling: string) => void} [props.onBucketChange] Receives the selection the Time field leaves.
 * @param {?number}                    [props.totalRows]      The pages the server's filters left, which the pager pages over. Null until a reply has counted them.
 * @param {string}                     [props.metric]         Chart metric the row bars scale.
 * @param {?string}                    [props.error]          The last `urls` refusal; the table shows it in place of rows it cannot vouch for.
 * @param {boolean}                    [props.loading]        Whether a page is asked for and not yet answered.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function UrlTable( {
	urls,
	selectedUrl,
	onSelect,
	expanded = NO_PAGE_KEYS,
	onToggleExpand,
	busy = null,
	onParamsChange,
	bucket = '',
	slots = null,
	server = '',
	chips = [],
	onBucketChange,
	totalRows = null,
	metric = DEFAULT_CHART_METRIC,
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
	// The request search owns `?search=`, so the table searches on `?q=`.
	const [ searchTerm, setSearchTerm ] = useQueryParamState(
		'q',
		( raw ) => raw ?? '',
		String
	);
	// Opts IN: workers are out by default.
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
		includeWorkers,
		bucket,
		server,
		chips,
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
	const total = totalRows ?? 0;
	const totalPages = Math.max(
		1,
		Math.min(
			Math.ceil( total / URLS_PER_PAGE ),
			URLS_MAX_OFFSET / URLS_PER_PAGE + 1
		)
	);
	// An unmeasured set clamps nothing, so a linked page survives to its reply.
	const page =
		null === totalRows ? currentPage : Math.min( currentPage, totalPages );
	const offset = ( page - 1 ) * URLS_PER_PAGE;
	// Move the state too, or a growing set springs the page back.
	useEffect( () => {
		if ( currentPage !== page ) {
			setCurrentPage( page );
		}
	}, [ currentPage, page, setCurrentPage ] );

	const expandedKeys = useMemo( () => new Set( expanded ), [ expanded ] );
	const rows = useMemo(
		() => layOut( urls, expandedKeys ),
		[ urls, expandedKeys ]
	);

	const barField = barFieldFor( metric );
	// Bar-background max uses p95 so outliers don't blow out the scale.
	const maxAvg = useMemo( () => {
		const values = urls
			.map( ( u ) => u[ barField ] )
			.filter( Number.isFinite )
			.sort( ( a, b ) => a - b );
		if ( values.length === 0 ) {
			return 0;
		}
		const p95Index = Math.floor( values.length * 0.95 );
		return values[ Math.min( p95Index, values.length - 1 ) ];
	}, [ urls, barField ] );

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
			rows.length,
			`.${ PAGE_CONTENT_CLASS }`
		);
	const visible = shapeRuns( rows.slice( startIndex, endIndex ) );
	// Each expanded page in view, to the offset its shapes are asked from.
	const windows = JSON.stringify(
		Object.fromEntries(
			visible
				.filter( ( run ) => run.shapesOf )
				.map( ( run ) => [
					pageKey( run.shapesOf ),
					shapesOffset( run.from ),
				] )
		)
	);

	useEffect( () => {
		onParamsChange?.( {
			search: searchTerm,
			sort: sortField,
			order: sortOrder,
			offset,
			includeWorkers,
			bucket,
			shapeOffsets: JSON.parse( windows ),
		} );
	}, [
		searchTerm,
		sortField,
		sortOrder,
		page,
		offset,
		includeWorkers,
		bucket,
		windows,
		onParamsChange,
	] );

	const rowProps = {
		onSelect,
		onToggle: onToggleExpand,
		formatNum,
		maxAvg,
		barField,
	};

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
					<SearchControl
						label={ __(
							'Search by whole URL word',
							'newspack-event-logger-nodes'
						) }
						placeholder={ __(
							'Search by whole URL word…',
							'newspack-event-logger-nodes'
						) }
						value={ searchTerm }
						onChange={ setSearchTerm }
					/>
				</div>
				<BucketField
					value={ bucket }
					slots={ slots }
					onChange={ onBucketChange }
				/>
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
								label={ col.label }
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
					{ error || urls.length === 0 ? (
						<div
							className={ `event-logger-table__empty newspack-nodes-empty-state${
								error ? ' newspack-nodes-status is-error' : ''
							}` }
						>
							{ emptyText( error, loading, searchTerm ) }
						</div>
					) : (
						visible.map( ( row ) => {
							if ( row.shapesOf ) {
								return (
									<PageShapes
										key={ `shapes:${ pageKey(
											row.shapesOf
										) }` }
										page={ row.shapesOf }
										from={ row.from }
										to={ row.to }
										rowProps={ rowProps }
										selected={ selectedUrl }
										busy={ busy }
									/>
								);
							}
							const key = pageKey( row.page );
							if ( row.more ) {
								return (
									<NoteRow
										key={ `more:${ key }` }
										text={ sprintf(
											// translators: 1: how many URLs the page holds past those listed, 2: how many the server lists under one page.
											_n(
												'%1$s more URL, past the first %2$s the server lists',
												'%1$s more URLs, past the first %2$s the server lists',
												row.more,
												'newspack-event-logger-nodes'
											),
											formatGroupedCount( row.more ),
											formatGroupedCount( SHAPES_REACH )
										) }
									/>
								);
							}
							return (
								<UrlRow
									key={ key }
									{ ...rowProps }
									url={ row.page }
									expandKey={
										1 < row.page.shapes ? key : null
									}
									open={ !! row.open }
									isSelected={
										!! row.page.hash &&
										selectedUrl?.hash === row.page.hash
									}
									isBusy={
										!! row.page.hash &&
										busy === row.page.hash
									}
								/>
							);
						} )
					) }
				</div>
			</div>

			<div className="event-logger-table__pagination">
				<span className="event-logger-table__pagination-info newspack-nodes-status">
					{ total > URLS_PER_PAGE &&
						sprintf(
							// translators: 1: first row number on the page, 2: last row number on the page, 3: total number of pages listed.
							__(
								'%1$s–%2$s of %3$s pages',
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
							// translators: %s: number of pages in the table.
							_n(
								'%s page',
								'%s pages',
								total,
								'newspack-event-logger-nodes'
							),
							formatGroupedCount( total )
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
