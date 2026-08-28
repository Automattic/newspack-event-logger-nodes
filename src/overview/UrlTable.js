/**
 * URL Table Component
 *
 * Virtualized sortable table of URLs with performance stats.
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

const ROW_HEIGHT = 40;
const URLS_PER_PAGE = 100;

/**
 * Calculate percentage.
 *
 * @param {number} part  Part count.
 * @param {number} total Total count.
 * @return {string} Formatted percentage or '-'.
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
 * `render` overrides the default `formatNum( url[ field ], 'ms' )`.
 *
 * @type {Array<{field: string, label: string, kind: string, status?: string, render?: Function}>}
 */
const COLUMNS = [
	{
		field: 'count',
		label: __( 'Reqs', 'newspack-event-logger-nodes' ),
		render: ( url, formatNum ) => formatNum( url.count ),
	},
	{
		field: 'url',
		label: __( 'URL', 'newspack-event-logger-nodes' ),
		kind: 'code',
		render: ( url ) => (
			<code>
				{ url.aggregate
					? __(
							'other URLs beyond the per-bucket cap',
							'newspack-event-logger-nodes'
					  )
					: url.url }
			</code>
		),
	},
	{ field: 'count_2xx', label: '2xx', kind: 'status', status: '218' },
	{ field: 'count_3xx', label: '3xx', kind: 'status', status: '307' },
	{ field: 'count_4xx', label: '4xx', kind: 'status', status: '418' },
	{ field: 'count_5xx', label: '5xx', kind: 'status', status: '599' },
	{ field: 'avg_ms', label: __( 'Avg', 'newspack-event-logger-nodes' ) },
	{ field: 'min_ms', label: __( 'Min', 'newspack-event-logger-nodes' ) },
	{ field: 'max_ms', label: __( 'Max', 'newspack-event-logger-nodes' ) },
	{
		field: 'avg_peak_mb',
		label: __( 'Mem', 'newspack-event-logger-nodes' ),
		render: ( url, formatNum ) =>
			url.avg_peak_mb > 0 ? formatNum( url.avg_peak_mb, 'MB' ) : '-',
	},
].map( ( col ) => ( { kind: 'numeric', ...col } ) );

// Per-kind class modifiers; `code` adds none to either mark.
const CELL_CLASS = {
	code: '',
	numeric: ' event-logger-table__cell--numeric',
	status: ' event-logger-table__cell--status entry-status',
};
const HEADER_CLASS = {
	code: '',
	numeric: ' event-logger-table__header-btn--numeric',
};
const cellClass = ( col ) =>
	`event-logger-table__cell newspack-nodes-table__cell${
		CELL_CLASS[ col.kind ]
	}`;

/**
 * Render one column's cell for one URL.
 *
 * @param {Object}   col       Column declaration from COLUMNS.
 * @param {Object}   url       URL data object.
 * @param {Function} formatNum Number formatting function.
 * @return {*} Cell content.
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
	 * Memoized URL row component.
	 *
	 * @param {Object}                       props            Component props.
	 * @param {Object}                       props.url        URL data object.
	 * @param {boolean}                      props.isSelected Whether this row is selected.
	 * @param {(url: Object) => void}        props.onSelect   Selection callback.
	 * @param {(n: number, s?: string) => *} props.formatNum  Number formatting function.
	 * @param {number}                       props.maxAvg     Largest bar value, for scaling.
	 * @param {string}                       props.metric     Which metric the bar shows.
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
				style={ { height: ROW_HEIGHT } }
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
 * URL Table component.
 *
 * @param {Object}                props                Component props.
 * @param {Array}                 props.urls           URL data array.
 * @param {Object}                props.selectedUrl    Currently selected URL.
 * @param {(url: Object) => void} props.onSelect       Selection callback, forwarded to each row.
 * @param {Function}              props.onParamsChange Callback when search/sort/page changes.
 * @param {number}                props.totalUrls      Total URL count from server (for pagination).
 * @param {string}                props.metric         Chart metric for bar backgrounds.
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
	 * Handle column header click for sorting.
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
	 * Handle search input change — resets to page 1.
	 *
	 * @param {string} value New search value.
	 */
	const handleSearchChange = ( value ) => {
		setSearchTerm( value );
		setCurrentPage( 1 );
	};

	/**
	 * Report the params; the server filters, sorts and paginates.
	 *
	 * Re-doing any of it here made the footer's `total` describe a different
	 * population than the rows, and `localeCompare` re-ordered a page the
	 * server had already cut with PHP's byte-order `<=>`, so rows could skip
	 * or repeat across pages.
	 */
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
	 * Format a number with units.
	 *
	 * @param {number} num    Number to format.
	 * @param {string} suffix Suffix to append.
	 * @return {string} Formatted string.
	 */
	const formatNum = useCallback( ( num, suffix = '' ) => {
		if ( num === null || num === undefined ) {
			return '-';
		}
		return Math.round( num ).toLocaleString() + suffix;
	}, [] );

	/**
	 * Render sort indicator.
	 *
	 * @param {string} field Field name.
	 * @return {string} Sort indicator character.
	 */
	const sortIndicator = ( field ) => {
		if ( sortField !== field ) {
			return '';
		}
		return sortOrder === 'asc' ? ' ▲' : ' ▼';
	};

	// Virtualize based on window scroll position.
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

			{ /* Scroll container for header + list */ }
			<div className="event-logger-table__scroll">
				<div
					className="event-logger-table__header newspack-nodes-table__header"
					role="row"
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

			{ /* Pagination */ }
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
