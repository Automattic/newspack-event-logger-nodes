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
import { Button, TextControl } from '@wordpress/components';
import { STATUS_COLORS } from '../shared/utils/formatUtils';
import useVirtualization from '../shared/hooks/useVirtualization';

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
 * Memoized URL row component.
 *
 * @param {Object}   props            Component props.
 * @param {Object}   props.url        URL data object.
 * @param {boolean}  props.isSelected Whether this row is selected.
 * @param {Function} props.onSelect   Selection callback.
 * @param {Function} props.formatNum  Number formatting function.
 * @return {import('react').ReactElement} Rendered row.
 */
const UrlRow = memo( function UrlRow( {
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
	const handleKeyDown = ( e ) => {
		if ( e.key === 'Enter' || e.key === ' ' ) {
			e.preventDefault();
			onSelect( url );
		}
	};

	return (
		<div
			role="button"
			tabIndex={ 0 }
			className={ `event-logger-table__row${
				isSelected ? ' selected' : ''
			}` }
			onClick={ () => onSelect( url ) }
			onKeyDown={ handleKeyDown }
			style={ { height: ROW_HEIGHT } }
		>
			<div className="event-logger-table__cell event-logger-table__cell--numeric">
				{ formatNum( url.count ) }
			</div>
			<div
				className="event-logger-table__cell"
				style={ {
					background: `linear-gradient(to right, rgba(100, 181, 246, 0.15) ${ barPct }%, transparent ${ barPct }%)`,
				} }
			>
				<code>{ url.url }</code>
			</div>
			<div
				className="event-logger-table__cell event-logger-table__cell--status"
				style={ { color: STATUS_COLORS[ '2xx' ] } }
			>
				{ pct( url.count_2xx, url.count ) }
			</div>
			<div
				className="event-logger-table__cell event-logger-table__cell--status"
				style={ { color: STATUS_COLORS[ '3xx' ] } }
			>
				{ pct( url.count_3xx, url.count ) }
			</div>
			<div
				className="event-logger-table__cell event-logger-table__cell--status"
				style={ { color: STATUS_COLORS[ '4xx' ] } }
			>
				{ pct( url.count_4xx, url.count ) }
			</div>
			<div
				className="event-logger-table__cell event-logger-table__cell--status"
				style={ { color: STATUS_COLORS[ '5xx' ] } }
			>
				{ pct( url.count_5xx, url.count ) }
			</div>
			<div className="event-logger-table__cell event-logger-table__cell--numeric">
				{ formatNum( url.avg_ms, 'ms' ) }
			</div>
			<div className="event-logger-table__cell event-logger-table__cell--numeric">
				{ formatNum( url.min_ms, 'ms' ) }
			</div>
			<div className="event-logger-table__cell event-logger-table__cell--numeric">
				{ formatNum( url.max_ms, 'ms' ) }
			</div>
			<div className="event-logger-table__cell event-logger-table__cell--numeric">
				{ formatNum( url.p95_ms, 'ms' ) }
			</div>
			<div className="event-logger-table__cell event-logger-table__cell--numeric">
				{ url.avg_peak_mb > 0
					? formatNum( url.avg_peak_mb, 'MB' )
					: '-' }
			</div>
		</div>
	);
} );

/**
 * URL Table component.
 *
 * @param {Object}   props                Component props.
 * @param {Array}    props.urls           URL data array.
 * @param {Object}   props.selectedUrl    Currently selected URL.
 * @param {Function} props.onSelect       Selection callback.
 * @param {Function} props.onParamsChange Callback when search/sort/page changes.
 * @param {number}   props.totalUrls      Total URL count from server (for pagination).
 * @param {string}   props.metric         Chart metric for bar backgrounds.
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

	// Notify parent when params change for server-side filtering/sorting/pagination.
	useEffect( () => {
		const offset = ( currentPage - 1 ) * URLS_PER_PAGE;
		onParamsChange?.( {
			search: searchTerm,
			sort: sortField,
			order: sortOrder,
			offset,
		} );
	}, [ searchTerm, sortField, sortOrder, currentPage, onParamsChange ] );

	/**
	 * Get filtered and sorted URLs.
	 */
	const filteredUrls = useMemo( () => {
		// Filter by search term.
		let filtered = urls;
		if ( searchTerm.trim() ) {
			const term = searchTerm.toLowerCase().trim();
			filtered = filtered.filter( ( u ) =>
				u.url?.toLowerCase().includes( term )
			);
		}

		// "Errors" = timeouts (T) and fatals (F) — requests that never
		// emitted a status_code, so they fall outside count_2xx/3xx/4xx/5xx.
		// Matches the per-request definition in UrlDetailView.js
		// (`error_status === 'F' || 'T'`). 5xx is treated as a classified
		// response, not an error, for consistency between aggregate and
		// per-request views.
		if ( errorsOnly ) {
			filtered = filtered.filter( ( u ) => {
				const classified =
					( u.count_2xx || 0 ) +
					( u.count_3xx || 0 ) +
					( u.count_4xx || 0 ) +
					( u.count_5xx || 0 );
				return classified < ( u.count || 0 );
			} );
		}

		// Sort the filtered results.
		return [ ...filtered ].sort( ( a, b ) => {
			const aVal = a[ sortField ];
			const bVal = b[ sortField ];

			// Handle string comparison (for 'url' field).
			if ( sortField === 'url' ) {
				const aStr = aVal || '';
				const bStr = bVal || '';
				if ( sortOrder === 'asc' ) {
					return aStr.localeCompare( bStr );
				}
				return bStr.localeCompare( aStr );
			}

			// Numeric comparison for stats fields.
			const aNum = aVal || 0;
			const bNum = bVal || 0;
			if ( sortOrder === 'asc' ) {
				return aNum - bNum;
			}
			return bNum - aNum;
		} );
	}, [ urls, searchTerm, errorsOnly, sortField, sortOrder ] );

	// Calculate max for bar chart backgrounds using p95 to prevent
	// outliers (workers, long crons) from blowing out the scale.
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
					alignItems: 'flex-end',
				} }
			>
				<div ref={ searchContainerRef } style={ { flex: 1 } }>
					<TextControl
						placeholder="Search URLs..."
						value={ searchTerm }
						onChange={ handleSearchChange }
						__nextHasNoMarginBottom
					/>
				</div>
				<Button
					variant={ errorsOnly ? 'primary' : 'secondary' }
					isSmall
					onClick={ () => setErrorsOnly( ! errorsOnly ) }
				>
					{ errorsOnly ? 'Showing Errors' : 'Errors Only' }
				</Button>
			</div>

			{ /* Scroll container for header + list */ }
			<div className="event-logger-table__scroll">
				<div className="event-logger-table__header" role="row">
					<button
						type="button"
						className="event-logger-table__header-btn event-logger-table__header-btn--numeric"
						onClick={ () => handleSort( 'count' ) }
					>
						Reqs{ sortIndicator( 'count' ) }
					</button>
					<button
						type="button"
						className="event-logger-table__header-btn"
						onClick={ () => handleSort( 'url' ) }
					>
						URL{ sortIndicator( 'url' ) }
					</button>
					<span
						className="event-logger-table__cell event-logger-table__cell--status"
						style={ { color: STATUS_COLORS[ '2xx' ] } }
					>
						2xx
					</span>
					<span
						className="event-logger-table__cell event-logger-table__cell--status"
						style={ { color: STATUS_COLORS[ '3xx' ] } }
					>
						3xx
					</span>
					<span
						className="event-logger-table__cell event-logger-table__cell--status"
						style={ { color: STATUS_COLORS[ '4xx' ] } }
					>
						4xx
					</span>
					<span
						className="event-logger-table__cell event-logger-table__cell--status"
						style={ { color: STATUS_COLORS[ '5xx' ] } }
					>
						5xx
					</span>
					<button
						type="button"
						className="event-logger-table__header-btn event-logger-table__header-btn--numeric"
						onClick={ () => handleSort( 'avg_ms' ) }
					>
						Avg{ sortIndicator( 'avg_ms' ) }
					</button>
					<button
						type="button"
						className="event-logger-table__header-btn event-logger-table__header-btn--numeric"
						onClick={ () => handleSort( 'min_ms' ) }
					>
						Min{ sortIndicator( 'min_ms' ) }
					</button>
					<button
						type="button"
						className="event-logger-table__header-btn event-logger-table__header-btn--numeric"
						onClick={ () => handleSort( 'max_ms' ) }
					>
						Max{ sortIndicator( 'max_ms' ) }
					</button>
					<button
						type="button"
						className="event-logger-table__header-btn event-logger-table__header-btn--numeric"
						onClick={ () => handleSort( 'p95_ms' ) }
					>
						p95{ sortIndicator( 'p95_ms' ) }
					</button>
					<button
						type="button"
						className="event-logger-table__header-btn event-logger-table__header-btn--numeric"
						onClick={ () => handleSort( 'avg_peak_mb' ) }
					>
						Mem{ sortIndicator( 'avg_peak_mb' ) }
					</button>
				</div>

				<div
					ref={ listRef }
					className="event-logger-table__list"
					style={ { paddingTop, paddingBottom } }
				>
					{ filteredUrls.length === 0 ? (
						<div className="event-logger-table__empty">
							{ searchTerm
								? `No URLs match "${ searchTerm }"`
								: 'No URLs to display' }
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
			{ ( () => {
				const total = totalUrls || 0;
				const totalPages = Math.max(
					1,
					Math.ceil( total / URLS_PER_PAGE )
				);
				const offset = ( currentPage - 1 ) * URLS_PER_PAGE;

				if ( total <= URLS_PER_PAGE ) {
					return (
						<div className="event-logger-table__pagination">
							<span className="event-logger-table__pagination-info">
								{ total > 0
									? `${ total.toLocaleString() } URL${
											total !== 1 ? 's' : ''
									  }`
									: '' }
							</span>
						</div>
					);
				}

				return (
					<div className="event-logger-table__pagination">
						<span className="event-logger-table__pagination-info">
							{ `${ ( offset + 1 ).toLocaleString() }–${ Math.min(
								offset + URLS_PER_PAGE,
								total
							).toLocaleString() } of ${ total.toLocaleString() }` }
						</span>
						<div className="event-logger-table__pagination-controls">
							<button
								type="button"
								className="event-logger-table__pagination-btn"
								disabled={ currentPage <= 1 }
								onClick={ () =>
									setCurrentPage( ( p ) => p - 1 )
								}
							>
								‹ Prev
							</button>
							<span className="event-logger-table__pagination-page">
								Page { currentPage } of { totalPages }
							</span>
							<button
								type="button"
								className="event-logger-table__pagination-btn"
								disabled={ currentPage >= totalPages }
								onClick={ () =>
									setCurrentPage( ( p ) => p + 1 )
								}
							>
								Next ›
							</button>
						</div>
					</div>
				);
			} )() }
		</div>
	);
}
