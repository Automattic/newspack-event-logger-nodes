/* global requestAnimationFrame, cancelAnimationFrame */
/**
 * Error Log Component
 *
 * Real-time scrolling log of errors and warnings from errors.log.
 * Modeled after RequestStream — same smooth scroll, virtualization, and layout.
 * Click any request ID to view its full trace in the Performance Dashboard.
 */

import {
	useState,
	useEffect,
	useRef,
	useCallback,
	useMemo,
	memo,
} from '@wordpress/element';

import usePageVisibility from './shared/hooks/usePageVisibility';
import useFirehoseConnection from './shared/hooks/useFirehoseConnection';
import useVirtualization from './shared/hooks/useVirtualization';
import './styles/error-log.scss';

const ROW_HEIGHT = 33;
const MAX_ENTRIES = 5000;

/**
 * Column definitions for the error log.
 */
const COLUMNS = {
	time: { label: 'Time', tooltip: 'Error timestamp', width: '100px' },
	rid: {
		label: 'Request ID',
		tooltip: 'Click to view request trace',
		width: '240px',
	},
	keyword: {
		label: 'Keyword',
		tooltip: 'Error/warning keyword',
		width: '240px',
	},
	message: {
		label: 'Message',
		tooltip: 'Error message',
		width: 'auto',
	},
};

const DEFAULT_COLUMNS = [ 'time', 'rid', 'keyword', 'message' ];

/**
 * Format timestamp to HH:MM:SS.mmm
 *
 * @param {number} ts Unix timestamp (seconds with decimals).
 * @return {string} Formatted time string.
 */
const formatTime = ( ts ) => {
	if ( ! ts ) {
		return '--:--:--.---';
	}
	const date = new Date( ts * 1000 );
	const h = String( date.getHours() ).padStart( 2, '0' );
	const m = String( date.getMinutes() ).padStart( 2, '0' );
	const s = String( date.getSeconds() ).padStart( 2, '0' );
	const ms = String( date.getMilliseconds() ).padStart( 3, '0' );
	return `${ h }:${ m }:${ s }.${ ms }`;
};

/**
 * Get keyword severity class.
 *
 * @param {string} keyword Log keyword.
 * @return {string} CSS class suffix.
 */
const getKeywordClass = ( keyword ) => {
	if ( keyword === 'error' || keyword.endsWith( '(error)' ) ) {
		return 'error';
	}
	if ( keyword === 'warning' || keyword.endsWith( '(warning)' ) ) {
		return 'warning';
	}
	return 'info';
};

/**
 * Memoized row component.
 */
const ErrorRow = memo( function ErrorRow( {
	entry,
	visibleColumns,
	gridTemplate,
} ) {
	return (
		<div
			role="row"
			className={ `event-logger-error-log-entry ${
				entry.isEven ? 'row-even' : 'row-odd'
			}` }
			style={ { gridTemplateColumns: gridTemplate } }
		>
			{ visibleColumns.map( ( col ) => {
				switch ( col ) {
					case 'time':
						return (
							<span
								key={ col }
								role="cell"
								className="entry-time"
							>
								{ formatTime( entry.ts ) }
							</span>
						);
					case 'rid':
						return (
							<span key={ col } role="cell">
								<a
									className="entry-rid"
									href={ `admin.php?page=newspack-event-logger&search=${ encodeURIComponent(
										entry.rid
									) }` }
									title="View request trace"
								>
									{ entry.rid }
								</a>
							</span>
						);
					case 'keyword':
						return (
							<span
								key={ col }
								role="cell"
								className={ `entry-keyword entry-keyword--${ getKeywordClass(
									entry.k
								) }` }
							>
								{ entry.k }
							</span>
						);
					case 'message':
						return (
							<span
								key={ col }
								role="cell"
								className="entry-message"
								title={ entry.m }
							>
								{ entry.m }
							</span>
						);
					default:
						return null;
				}
			} ) }
		</div>
	);
} );

/**
 * Error Log Component.
 *
 * @return {JSX.Element} Rendered component.
 */
export default function ErrorLog() {
	const [ entries, setEntries ] = useState( [] );
	const [ filter, setFilter ] = useState( '' );
	const [ isPaused, setIsPaused ] = useState( false );

	const entriesBufferRef = useRef( [] );
	const entryCounterRef = useRef( 0 );
	const lastProcessedCountRef = useRef( 0 );
	const isPageVisible = usePageVisibility();
	const listRef = useRef( null );
	const contentRef = useRef( null );
	const offsetRef = useRef( 0 );
	const savedOffsetRef = useRef( 0 );
	const rafRef = useRef( null );
	const isAdjustingScrollRef = useRef( false );
	const [ animOffsetRows, setAnimOffsetRows ] = useState( 0 );

	const visibleColumns = DEFAULT_COLUMNS;

	// Handle 'errors' events from SSE.
	const handleSource = useCallback( ( source ) => {
		source.addEventListener( 'errors', ( e ) => {
			try {
				const batch = JSON.parse( e.data );
				for ( const item of batch ) {
					entryCounterRef.current += 1;
					entriesBufferRef.current.unshift( {
						id: entryCounterRef.current,
						rid: item.rid,
						ts: item.ts,
						k: item.k,
						m: item.m,
						isEven: entryCounterRef.current % 2 === 0,
					} );
				}
				if ( entriesBufferRef.current.length > MAX_ENTRIES ) {
					entriesBufferRef.current.length = MAX_ENTRIES;
				}
			} catch ( err ) {
				// Ignore parse errors.
			}
		} );
	}, [] );

	const {
		error,
		connect,
		close: closeSource,
		lastEventTime,
	} = useFirehoseConnection( {
		endpoint: 'errors',
		intervalMs: 1000,
		onSource: handleSource,
	} );

	// Ticking "Xs ago" display.
	const [ now, setNow ] = useState( Date.now() );
	useEffect( () => {
		const id = setInterval( () => setNow( Date.now() ), 1000 );
		return () => clearInterval( id );
	}, [] );
	const staleSec = lastEventTime
		? Math.max( 0, Math.floor( ( now - lastEventTime ) / 1000 ) )
		: null;

	// Smooth scroll animation.
	useEffect( () => {
		let lastOffsetRows = 0;

		const animate = () => {
			const content = contentRef.current;
			if ( content && Math.abs( offsetRef.current ) > 0.5 ) {
				offsetRef.current += ( 0 - offsetRef.current ) * 0.01;
				content.style.transform = `translate3d(0,${ offsetRef.current }px,0)`;

				const currentOffsetRows = Math.floor(
					Math.abs( offsetRef.current ) / ROW_HEIGHT
				);
				if ( currentOffsetRows !== lastOffsetRows ) {
					lastOffsetRows = currentOffsetRows;
					setAnimOffsetRows( currentOffsetRows );
				}
			} else if ( content && offsetRef.current !== 0 ) {
				offsetRef.current = 0;
				content.style.transform = '';
				if ( lastOffsetRows !== 0 ) {
					lastOffsetRows = 0;
					setAnimOffsetRows( 0 );
				}
			}
			rafRef.current = requestAnimationFrame( animate );
		};

		rafRef.current = requestAnimationFrame( animate );
		return () => cancelAnimationFrame( rafRef.current );
	}, [] );

	// Batch UI updates.
	useEffect( () => {
		const timer = setInterval( () => {
			const currentCount = entryCounterRef.current;
			const newCount = currentCount - lastProcessedCountRef.current;

			if ( newCount > 0 ) {
				const buffer = entriesBufferRef.current;
				const newEntries = buffer.slice( 0, newCount );
				const filterLower = filter.toLowerCase();
				const visibleNewCount = filter
					? newEntries.filter(
							( e ) =>
								e.k?.toLowerCase().includes( filterLower ) ||
								e.m?.toLowerCase().includes( filterLower ) ||
								e.rid?.toLowerCase().includes( filterLower )
					  ).length
					: newCount;

				const list = listRef.current;
				const isAtTop = ! list || list.scrollTop < ROW_HEIGHT;

				if ( visibleNewCount > 0 && isAtTop ) {
					offsetRef.current -= visibleNewCount * ROW_HEIGHT;
				}

				setEntries( [ ...buffer ] );

				if ( visibleNewCount > 0 && ! isAtTop ) {
					isAdjustingScrollRef.current = true;
					list.scrollTop += visibleNewCount * ROW_HEIGHT;
					requestAnimationFrame( () => {
						isAdjustingScrollRef.current = false;
					} );
				}

				lastProcessedCountRef.current = currentCount;
			}
		}, 100 );

		return () => clearInterval( timer );
	}, [ filter ] );

	// Page visibility.
	useEffect( () => {
		if ( isPageVisible && ! isPaused ) {
			connect();
		} else {
			closeSource();
		}
		return () => closeSource();
	}, [ isPageVisible, isPaused, connect, closeSource ] );

	// Scroll handler for animation save/restore.
	const wasAtTopRef = useRef( true );
	const handleScroll = useCallback( ( e ) => {
		if ( isAdjustingScrollRef.current ) {
			return;
		}

		const newScrollTop = e.target.scrollTop;
		const isAtTop = newScrollTop < ROW_HEIGHT;

		if ( wasAtTopRef.current && ! isAtTop ) {
			savedOffsetRef.current = offsetRef.current;
			offsetRef.current = 0;
			setAnimOffsetRows( 0 );
			if ( contentRef.current ) {
				contentRef.current.style.transform = '';
			}
		}

		if ( ! wasAtTopRef.current && isAtTop ) {
			offsetRef.current = savedOffsetRef.current;
			const restoredRows = Math.floor(
				Math.abs( savedOffsetRef.current ) / ROW_HEIGHT
			);
			setAnimOffsetRows( restoredRows );
		}

		wasAtTopRef.current = isAtTop;
	}, [] );

	// Filtered entries.
	const filterLower = filter.toLowerCase();
	const filteredEntries = useMemo(
		() =>
			filter
				? entries.filter(
						( e ) =>
							e.k?.toLowerCase().includes( filterLower ) ||
							e.m?.toLowerCase().includes( filterLower ) ||
							e.rid?.toLowerCase().includes( filterLower )
				  )
				: entries,
		[ entries, filter, filterLower ]
	);

	// Grid template.
	const gridTemplate = useMemo(
		() =>
			visibleColumns
				.map( ( col ) => COLUMNS[ col ]?.width || 'auto' )
				.join( ' ' ),
		[ visibleColumns ]
	);

	// Virtualization.
	const { startIndex, endIndex, offsetTop, totalHeight } = useVirtualization(
		listRef,
		ROW_HEIGHT,
		filteredEntries.length,
		'self',
		animOffsetRows * ROW_HEIGHT
	);
	const visibleEntries = filteredEntries.slice( startIndex, endIndex );

	const handleClear = () => {
		entriesBufferRef.current = [];
		entryCounterRef.current = 0;
		lastProcessedCountRef.current = 0;
		setEntries( [] );
		offsetRef.current = 0;
	};

	return (
		<div
			className="event-logger-error-log"
			role="table"
			aria-label="Error log"
		>
			<div className="event-logger-error-log-header">
				<h3>Error Log</h3>
				<div className="event-logger-error-log-controls">
					<input
						type="text"
						className="event-logger-error-log-search"
						placeholder="Filter by keyword, message, or request ID..."
						value={ filter }
						onChange={ ( e ) => setFilter( e.target.value ) }
					/>
					<span className="event-logger-error-log-stats">
						<span className="event-logger-error-log-count">
							{ filteredEntries.length } entries
						</span>
						{ staleSec !== null && (
							<span
								className="event-logger-error-log-age"
								style={ {
									color:
										staleSec > 10 ? '#dba617' : '#757575',
								} }
							>
								{ staleSec }s ago
							</span>
						) }
					</span>
					<button
						className={ `event-logger-error-log-btn ${
							isPaused ? 'paused' : ''
						}` }
						onClick={ () => setIsPaused( ! isPaused ) }
						title={
							isPaused ? 'Resume streaming' : 'Pause streaming'
						}
					>
						{ isPaused ? '▶' : '⏸' }
					</button>
					<button
						className="event-logger-error-log-btn"
						onClick={ handleClear }
						title="Clear all entries"
					>
						Clear
					</button>
				</div>
			</div>

			{ error && (
				<div className="event-logger-error-log-error">{ error }</div>
			) }

			<div
				role="row"
				className="event-logger-error-log-header-row"
				style={ { gridTemplateColumns: gridTemplate } }
			>
				{ visibleColumns.map( ( col ) => (
					<span
						key={ col }
						role="columnheader"
						className="event-logger-error-log-th"
						title={ COLUMNS[ col ]?.tooltip }
					>
						{ COLUMNS[ col ]?.label || col }
					</span>
				) ) }
			</div>
			<div
				role="rowgroup"
				className="event-logger-error-log-list"
				ref={ listRef }
				onScroll={ handleScroll }
			>
				<div
					className="event-logger-error-log-content"
					ref={ contentRef }
					style={ { minHeight: totalHeight } }
				>
					{ filteredEntries.length === 0 ? (
						<div className="event-logger-error-log-empty">
							{ isPaused
								? 'Paused - click play to resume'
								: 'Waiting for errors...' }
						</div>
					) : (
						<>
							<div
								style={ { height: offsetTop, flexShrink: 0 } }
							/>
							{ visibleEntries.map( ( entry ) => (
								<ErrorRow
									key={ entry.id }
									entry={ entry }
									visibleColumns={ visibleColumns }
									gridTemplate={ gridTemplate }
								/>
							) ) }
						</>
					) }
				</div>
			</div>
		</div>
	);
}
