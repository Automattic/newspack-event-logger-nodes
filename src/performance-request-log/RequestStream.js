/* global requestAnimationFrame, cancelAnimationFrame, localStorage */
/**
 * Request Stream Component
 *
 * Real-time scrolling log of completed requests.
 * Uses SSE (single multiplexed connection) to stream completed requests.
 * Click any request to view its full trace in the Performance Dashboard.
 * Entries are newest-first - user scrolls down to see history.
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
import {
	formatDuration,
	getDurationClass,
	getStatusClass,
} from './shared/utils/formatUtils';
import fnv1a from './shared/utils/fnv1a';
import './styles/request-stream.scss';

/**
 * Generate URL hash for linking to URL detail.
 *
 * @param {string} url URL to hash.
 * @return {string} 12-character FNV-1a hash.
 */
const urlHash = ( url ) => {
	const urlPath = url?.split( '?' )[ 0 ] || '';
	return fnv1a( urlPath );
};

const ROW_HEIGHT = 33; // Fixed row height in pixels.

/**
 * Column definitions for the request log.
 */
const COLUMNS = {
	time: { label: 'Time', tooltip: 'Request completion time', width: '100px' },
	rid: {
		label: 'Request ID',
		tooltip: 'Unique request identifier - click to view full trace',
		width: '240px',
	},
	url: { label: 'URL', tooltip: 'Request method and URL', width: 'auto' },
	status: { label: 'Status', tooltip: 'HTTP status code', width: '50px' },
	remote_addr: { label: 'IP', tooltip: 'Client IP address', width: '100px' },
	user_agent: {
		label: 'UA',
		tooltip: 'Browser/client identifier',
		width: '200px',
	},
	duration: { label: 'Duration', tooltip: 'Request duration', width: '70px' },
};

const DEFAULT_COLUMNS = [
	'time',
	'rid',
	'url',
	'status',
	'remote_addr',
	'duration',
];

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
 * Memoized row component - only re-renders when entry or columns change.
 */
const StreamRow = memo( function StreamRow( {
	entry,
	visibleColumns,
	gridTemplate,
} ) {
	return (
		<div
			role="row"
			className={ `event-logger-request-stream-entry ${
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
								{ formatTime( entry.timestamp ) }
							</span>
						);
					case 'duration':
						return (
							<span
								key={ col }
								role="cell"
								className={ `entry-duration entry-duration--${ getDurationClass(
									entry.duration_ms
								) }` }
							>
								{ formatDuration( entry.duration_ms ) }
							</span>
						);
					case 'status':
						return (
							<span
								key={ col }
								role="cell"
								className={ `entry-status entry-status--${ getStatusClass(
									entry.status_code
								) }` }
							>
								{ entry.status_code }
							</span>
						);
					case 'url':
						return (
							<span key={ col } role="cell" className="entry-url">
								<span className="entry-method">
									{ entry.method }
								</span>{ ' ' }
								<a
									href={ `admin.php?page=newspack-event-logger&url=${ entry.urlHash }` }
									className="entry-url-link"
									title="View URL stats"
								>
									{ entry.url }
								</a>
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
					case 'remote_addr':
						return (
							<span key={ col } role="cell" className="entry-ip">
								{ entry.remote_addr || '-' }
							</span>
						);
					case 'user_agent':
						return (
							<span
								key={ col }
								role="cell"
								className="entry-ua"
								title={ entry.user_agent }
							>
								{ entry.user_agent || '-' }
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
 * Request Stream Component.
 *
 * @param {Object} props            Component props.
 * @param {number} props.maxEntries Maximum entries to keep in buffer.
 * @return {JSX.Element} Rendered component.
 */
export default function RequestStream( { maxEntries = 500 } ) {
	const [ entries, setEntries ] = useState( [] );
	const [ filter, setFilter ] = useState( '' );
	const [ isPaused, setIsPaused ] = useState( false );
	const [ requestsPerSecond, setRequestsPerSecond ] = useState( 0 );
	const [ visibleColumns, setVisibleColumns ] = useState( () => {
		// Load from localStorage with validation.
		const validColumns = Object.keys( COLUMNS );
		try {
			const saved = localStorage.getItem( 'event-logger-stream-columns' );
			const parsed = saved ? JSON.parse( saved ) : null;
			if (
				Array.isArray( parsed ) &&
				parsed.every( ( col ) => validColumns.includes( col ) )
			) {
				return parsed;
			}
		} catch {
			// Fall through to default.
		}
		return DEFAULT_COLUMNS;
	} );
	const [ showColumnPicker, setShowColumnPicker ] = useState( false );

	const entriesBufferRef = useRef( [] );
	const completedHistoryRef = useRef( [] );
	const entryCounterRef = useRef( 0 ); // For stable even/odd alternation.
	const lastProcessedCountRef = useRef( 0 ); // Track last processed entry count.
	const isPageVisible = usePageVisibility();
	const listRef = useRef( null );
	const contentRef = useRef( null );
	const offsetRef = useRef( 0 ); // Smooth scroll offset.
	const savedOffsetRef = useRef( 0 ); // Saved offset for resume.
	const rafRef = useRef( null );
	const isAdjustingScrollRef = useRef( false ); // Flag to skip scroll events during programmatic adjustment.
	const [ animOffsetRows, setAnimOffsetRows ] = useState( 0 ); // Rows worth of animation offset.

	// Calculate requests per second from completed requests over 10-second window.
	const updateRequestsPerSecond = useCallback( ( completedCount ) => {
		const now = Date.now();
		const windowMs = 10000;

		if ( completedCount > 0 ) {
			completedHistoryRef.current.push( {
				time: now,
				count: completedCount,
			} );
		}

		completedHistoryRef.current = completedHistoryRef.current.filter(
			( entry ) => now - entry.time < windowMs
		);

		const totalInWindow = completedHistoryRef.current.reduce(
			( sum, entry ) => sum + entry.count,
			0
		);

		setRequestsPerSecond( totalInWindow / ( windowMs / 1000 ) );
	}, [] );

	// Handle 'complete_batch' events from SSE (single multiplexed stream).
	const handleSource = useCallback(
		( source ) => {
			source.addEventListener( 'complete_batch', ( e ) => {
				try {
					const requests = JSON.parse( e.data );
					for ( const req of requests ) {
						entryCounterRef.current += 1;
						const entry = {
							rid: req.rid,
							url: req.url,
							urlHash: urlHash( req.url ), // Pre-compute hash.
							method: req.method,
							duration_ms: req.duration_ms,
							status_code: req.status_code,
							timestamp: req.end_time,
							remote_addr: req.remote_addr,
							user_agent: req.user_agent,
							isEven: entryCounterRef.current % 2 === 0,
						};

						entriesBufferRef.current.unshift( entry );
					}

					if ( entriesBufferRef.current.length > maxEntries ) {
						entriesBufferRef.current.length = maxEntries;
					}
				} catch ( err ) {
					// Ignore parse errors.
				}
			} );
		},
		[ maxEntries ]
	);

	// Reset RPS counter on reconnect.
	const handleBeforeConnect = useCallback( () => {
		completedHistoryRef.current = [];
	}, [] );

	// Use shared firehose connection hook.
	const {
		error,
		connect,
		close: closeSource,
		lastEventTime,
	} = useFirehoseConnection( {
		endpoint: 'requests',
		intervalMs: 500,
		onSource: handleSource,
		onBeforeConnect: handleBeforeConnect,
	} );

	// Smooth scroll animation - interpolate offset back to 0.
	useEffect( () => {
		let lastOffsetRows = 0;

		const animate = () => {
			const content = contentRef.current;
			if ( content && Math.abs( offsetRef.current ) > 0.5 ) {
				offsetRef.current += ( 0 - offsetRef.current ) * 0.01;
				content.style.transform = `translate3d(0,${ offsetRef.current }px,0)`;

				// Update virtualization when offset crosses row boundaries.
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

	// Batch UI updates - entries are newest-first, normal flex column.
	useEffect( () => {
		const timer = setInterval( () => {
			const currentCount = entryCounterRef.current;
			const newCount = currentCount - lastProcessedCountRef.current;

			if ( newCount > 0 ) {
				const buffer = entriesBufferRef.current;
				// Only check the NEW entries (at front of buffer), not the whole thing.
				const newEntries = buffer.slice( 0, newCount );
				const filterLower = filter.toLowerCase();
				const visibleNewCount = filter
					? newEntries.filter( ( e ) =>
							e.url.toLowerCase().includes( filterLower )
					  ).length
					: newCount;

				const list = listRef.current;
				const isAtTop = ! list || list.scrollTop < ROW_HEIGHT;

				// Only animate when at top.
				if ( visibleNewCount > 0 && isAtTop ) {
					offsetRef.current -= visibleNewCount * ROW_HEIGHT;
				}

				setEntries( [ ...buffer ] );

				// Maintain scroll position when scrolled down.
				if ( visibleNewCount > 0 && ! isAtTop ) {
					isAdjustingScrollRef.current = true;
					list.scrollTop += visibleNewCount * ROW_HEIGHT;
					// Reset flag after browser processes scroll event.
					requestAnimationFrame( () => {
						isAdjustingScrollRef.current = false;
					} );
				}

				updateRequestsPerSecond( newCount );
				lastProcessedCountRef.current = currentCount;
			}
		}, 100 );

		return () => clearInterval( timer );
	}, [ filter, updateRequestsPerSecond ] );

	// Handle page visibility.
	useEffect( () => {
		if ( isPageVisible && ! isPaused ) {
			connect();
		} else {
			closeSource();
		}
		return () => closeSource();
	}, [ isPageVisible, isPaused, connect, closeSource ] );

	// Ticking "Xs ago" display.
	const [ now, setNow ] = useState( Date.now() );
	useEffect( () => {
		const id = setInterval( () => setNow( Date.now() ), 1000 );
		return () => clearInterval( id );
	}, [] );
	const staleSec = lastEventTime
		? Math.max( 0, Math.floor( ( now - lastEventTime ) / 1000 ) )
		: null;

	// Save column selection to localStorage.
	useEffect( () => {
		localStorage.setItem(
			'event-logger-stream-columns',
			JSON.stringify( visibleColumns )
		);
	}, [ visibleColumns ] );

	// Handle scroll for animation save/restore.
	const wasAtTopRef = useRef( true );
	const handleScroll = useCallback( ( e ) => {
		// Skip scroll events triggered by programmatic adjustment.
		if ( isAdjustingScrollRef.current ) {
			return;
		}

		const newScrollTop = e.target.scrollTop;
		const isAtTop = newScrollTop < ROW_HEIGHT;

		// Scrolling away from top - save offset and clear.
		if ( wasAtTopRef.current && ! isAtTop ) {
			savedOffsetRef.current = offsetRef.current;
			offsetRef.current = 0;
			setAnimOffsetRows( 0 );
			if ( contentRef.current ) {
				contentRef.current.style.transform = '';
			}
		}

		// Returning to top - restore saved offset.
		if ( ! wasAtTopRef.current && isAtTop ) {
			offsetRef.current = savedOffsetRef.current;
			const restoredRows = Math.floor(
				Math.abs( savedOffsetRef.current ) / ROW_HEIGHT
			);
			setAnimOffsetRows( restoredRows );
		}

		wasAtTopRef.current = isAtTop;
	}, [] );

	// Toggle column visibility.
	const toggleColumn = ( col ) => {
		setVisibleColumns( ( prev ) => {
			if ( prev.includes( col ) ) {
				return prev.filter( ( c ) => c !== col );
			}
			// Add in original order.
			const allCols = Object.keys( COLUMNS );
			return allCols.filter( ( c ) => prev.includes( c ) || c === col );
		} );
	};

	// Memoize filtered entries.
	const filterLower = filter.toLowerCase();
	const filteredEntries = useMemo(
		() =>
			filter
				? entries.filter( ( e ) =>
						e.url.toLowerCase().includes( filterLower )
				  )
				: entries,
		[ entries, filter, filterLower ]
	);

	// Memoize grid template.
	const gridTemplate = useMemo(
		() =>
			visibleColumns
				.map( ( col ) => COLUMNS[ col ]?.width || 'auto' )
				.join( ' ' ),
		[ visibleColumns ]
	);

	// Virtualization with animation offset.
	const { startIndex, endIndex, offsetTop, totalHeight } = useVirtualization(
		listRef,
		ROW_HEIGHT,
		filteredEntries.length,
		'self',
		animOffsetRows * ROW_HEIGHT
	);
	const visibleEntries = filteredEntries.slice( startIndex, endIndex );

	// Clear all entries.
	const handleClear = () => {
		entriesBufferRef.current = [];
		entryCounterRef.current = 0;
		lastProcessedCountRef.current = 0;
		setEntries( [] );
		offsetRef.current = 0;
	};

	return (
		<div
			className="event-logger-request-stream"
			role="table"
			aria-label="Request log"
		>
			<div className="event-logger-request-stream-header">
				<h3>Request Log</h3>
				<div className="event-logger-request-stream-controls">
					<input
						type="text"
						className="event-logger-request-stream-search"
						placeholder="Filter by URL..."
						value={ filter }
						onChange={ ( e ) => setFilter( e.target.value ) }
					/>
					<span className="event-logger-request-stream-stats">
						<span className="event-logger-request-stream-count">
							{ filteredEntries.length } requests
						</span>
						{ requestsPerSecond > 0 && (
							<span className="event-logger-request-stream-rps">
								{ requestsPerSecond.toFixed( 1 ) } req/s
							</span>
						) }
						{ staleSec !== null && (
							<span
								style={ {
									color:
										staleSec > 10 ? '#dba617' : '#757575',
									fontSize: '11px',
									marginLeft: '8px',
								} }
							>
								{ staleSec }s ago
							</span>
						) }
					</span>
					<button
						className={ `event-logger-request-stream-btn ${
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
						className="event-logger-request-stream-btn"
						onClick={ handleClear }
						title="Clear all entries"
					>
						Clear
					</button>
					<button
						className={ `event-logger-request-stream-btn ${
							showColumnPicker ? 'active' : ''
						}` }
						onClick={ () =>
							setShowColumnPicker( ! showColumnPicker )
						}
						title="Select columns"
					>
						Cols
					</button>
				</div>
			</div>

			{ showColumnPicker && (
				<div className="event-logger-request-stream-column-picker">
					{ Object.entries( COLUMNS ).map( ( [ key, col ] ) => (
						<label
							key={ key }
							htmlFor={ `col-${ key }` }
							style={ { cursor: 'pointer', marginRight: '12px' } }
							title={ col.tooltip }
						>
							<input
								id={ `col-${ key }` }
								type="checkbox"
								checked={ visibleColumns.includes( key ) }
								onChange={ () => toggleColumn( key ) }
							/>{ ' ' }
							{ col.label }
						</label>
					) ) }
				</div>
			) }

			{ error && (
				<div className="event-logger-request-stream-error">
					{ error }
				</div>
			) }

			<div
				role="row"
				className="event-logger-request-stream-header-row"
				style={ { gridTemplateColumns: gridTemplate } }
			>
				{ visibleColumns.map( ( col ) => (
					<span
						key={ col }
						role="columnheader"
						className="event-logger-request-stream-th"
						title={ COLUMNS[ col ]?.tooltip }
					>
						{ COLUMNS[ col ]?.label || col }
					</span>
				) ) }
			</div>
			<div
				role="rowgroup"
				className="event-logger-request-stream-list"
				ref={ listRef }
				onScroll={ handleScroll }
			>
				<div
					className="event-logger-request-stream-content"
					ref={ contentRef }
					style={ { minHeight: totalHeight } }
				>
					{ filteredEntries.length === 0 ? (
						<div className="event-logger-request-stream-empty">
							{ isPaused
								? 'Paused - click play to resume'
								: 'Waiting for requests...' }
						</div>
					) : (
						<>
							<div
								style={ { height: offsetTop, flexShrink: 0 } }
							/>
							{ visibleEntries.map( ( entry ) => (
								<StreamRow
									key={ `${ entry.rid }-${ entry.timestamp }` }
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
