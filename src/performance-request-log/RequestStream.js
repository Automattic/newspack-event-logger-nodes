/* global requestAnimationFrame, cancelAnimationFrame, localStorage */
/**
 * Request Stream Component
 *
 * Real-time scrolling log of completed requests.
 *
 * This is a THIN view over the `requestlog/*` node graph (mounted by
 * `useRequestLogGraph`). The graph owns all data: `requestlog/stream` holds the
 * SSE connection, `requestlog/transform` turns envelopes into rows, and
 * `requestlog/view` holds the buffer + view model. This component only renders.
 *
 * Two read paths, matching the view node's two cadences:
 * - LOW frequency: `useNodeState('requestlog/view','view')` for `{ paused }` (the
 *   pause button + empty-state label).
 * - HIGH frequency: the rAF reads `Core.node('requestlog/view').entries`, `.rps`
 *   and `.lastEventTime` directly each frame — a busy stream never re-renders
 *   React per request; only the cheap derived state (the snapshot + rps) is pushed
 *   when it changes.
 *
 * Click any request to view its full trace in the Performance Dashboard. Entries
 * are newest-first - user scrolls down to see history.
 */

import { useState, useEffect, useRef, useMemo, memo } from '@wordpress/element';

import { Core, useNodeState } from '@newspack-nodes/runtime';
import { useRequestLogGraph } from './hooks/useRequestLogGraph';
import useVirtualization from '../shared/hooks/useVirtualization';
import {
	formatDuration,
	getDurationClass,
	getStatusClass,
} from '../shared/utils/formatUtils';
import './styles/request-stream.scss';

const ROW_HEIGHT = 33; // Fixed row height in pixels.
const VIEW_NODE = 'requestlog/view';
const EMPTY_VIEW = { paused: false };

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
 * @return {import('react').ReactElement} {string} Formatted time string.
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
									href={ `admin.php?page=newspack-nodes-performance&url=${ entry.urlHash }` }
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
									href={ `admin.php?page=newspack-nodes-performance&request=${ encodeURIComponent(
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
 * @return {import('react').ReactElement} Rendered component.
 */
export default function RequestStream( { maxEntries = 500 } ) {
	// Mount the node graph; it returns the thin control callbacks.
	const { setPaused, clear } = useRequestLogGraph( { maxEntries } );

	// Low-frequency view model (pause button + empty-state label).
	const view = useNodeState( VIEW_NODE, 'view' ) ?? EMPTY_VIEW;
	const { paused: isPaused } = view;

	const [ filter, setFilter ] = useState( '' );
	// The rendered entry buffer + RPS, both fed from the rAF at frame rate (read
	// straight off the view node). The original re-rendered via a 100ms setInterval;
	// per-frame for both is visually identical and keeps everything in one push.
	const [ entries, setEntries ] = useState( [] );
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

	const listRef = useRef( null );
	const contentRef = useRef( null );
	const offsetRef = useRef( 0 ); // Smooth scroll offset.
	const savedOffsetRef = useRef( 0 ); // Saved offset for resume.
	const rafRef = useRef( null );
	const isAdjustingScrollRef = useRef( false ); // Skip scroll events during programmatic adjustment.
	const [ animOffsetRows, setAnimOffsetRows ] = useState( 0 ); // Rows worth of animation offset.

	// Last rendered buffer length — drives the smooth/virtual scroll math each
	// frame (replaces the old per-batch newCount the SSE handler tracked).
	const lastRenderedCountRef = useRef( 0 );
	// Last filtered length seen by the rAF — for scroll compensation.
	const filteredCountRef = useRef( 0 );
	// Last state we pushed to React — so idle frames (nothing changed) push no
	// new refs and don't re-render.
	const pushedRef = useRef( { count: -1, filter: null, rps: -1 } );
	// Filter kept in a ref so the rAF reads the latest without re-subscribing.
	const filterRef = useRef( filter );
	filterRef.current = filter;
	// Last node lastEventTime the rAF observed — drives the "Xs ago" staleness.
	const lastEventTimeRef = useRef( null );

	// Ticking "Xs ago" display.
	const [ now, setNow ] = useState( Date.now() );
	useEffect( () => {
		const id = setInterval( () => setNow( Date.now() ), 1000 );
		return () => clearInterval( id );
	}, [] );
	const staleSec = lastEventTimeRef.current
		? Math.max( 0, Math.floor( ( now - lastEventTimeRef.current ) / 1000 ) )
		: null;

	// Animation/read loop. Reads the high-volume buffer (node.entries) directly
	// every frame, snapshots + filters it, drives the smooth scroll, and pushes
	// the cheap derived state (entries snapshot + RPS) to React only when changed.
	useEffect( () => {
		const animate = () => {
			const node = Core.node( VIEW_NODE );
			const buffer = node?.entries ?? [];
			const rps = node?.rps ?? 0;
			const filterLower = filterRef.current.toLowerCase();

			// New rows since last frame → drive scroll + staleness.
			const newCount = Math.max(
				0,
				buffer.length - lastRenderedCountRef.current
			);
			if ( newCount > 0 && node?.lastEventTime ) {
				lastEventTimeRef.current = node.lastEventTime;
			}

			// Snapshot (and filter) the buffer so a mid-frame append can't mutate
			// what we draw / count.
			const snapshot = filterRef.current
				? buffer.filter( ( e ) =>
						e.url.toLowerCase().includes( filterLower )
				  )
				: buffer.slice();

			// Visible-count delta in the filtered view, for scroll compensation.
			const visibleNewCount = snapshot.length - filteredCountRef.current;
			const list = listRef.current;
			const isAtTop = ! list || list.scrollTop < ROW_HEIGHT;

			filteredCountRef.current = snapshot.length;
			lastRenderedCountRef.current = buffer.length;

			if ( visibleNewCount > 0 ) {
				if ( isAtTop ) {
					// Compensate offset — decay will smooth-scroll to 0.
					offsetRef.current -= visibleNewCount * ROW_HEIGHT;
				} else if ( list ) {
					// Maintain scroll position when scrolled down.
					isAdjustingScrollRef.current = true;
					list.scrollTop += visibleNewCount * ROW_HEIGHT;
				}
			}

			// Decay offset toward 0 (smooth scroll), updating virtualization when
			// the offset crosses row boundaries.
			const content = contentRef.current;
			if ( content && Math.abs( offsetRef.current ) > 0.5 ) {
				offsetRef.current += ( 0 - offsetRef.current ) * 0.01;
				content.style.transform = `translate3d(0,${ offsetRef.current }px,0)`;
			} else if ( content && offsetRef.current !== 0 ) {
				offsetRef.current = 0;
				content.style.transform = '';
			}
			const currentOffsetRows = Math.floor(
				Math.abs( offsetRef.current ) / ROW_HEIGHT
			);

			// Push the cheap derived state ONLY when it changed — the snapshot
			// (count rides the array identity), the filter, and RPS. Skipping
			// unchanged frames keeps idle frames from re-rendering React.
			const pushed = pushedRef.current;
			if (
				snapshot.length !== pushed.count ||
				filterRef.current !== pushed.filter
			) {
				setEntries( snapshot );
				pushed.count = snapshot.length;
				pushed.filter = filterRef.current;
			}
			if ( rps !== pushed.rps ) {
				setRequestsPerSecond( rps );
				pushed.rps = rps;
			}
			setAnimOffsetRows( ( prev ) =>
				prev === currentOffsetRows ? prev : currentOffsetRows
			);

			rafRef.current = requestAnimationFrame( animate );
		};

		rafRef.current = requestAnimationFrame( animate );
		return () => cancelAnimationFrame( rafRef.current );
	}, [] );

	// Save column selection to localStorage.
	useEffect( () => {
		localStorage.setItem(
			'event-logger-stream-columns',
			JSON.stringify( visibleColumns )
		);
	}, [ visibleColumns ] );

	// Handle scroll for animation save/restore.
	const wasAtTopRef = useRef( true );
	const handleScroll = ( e ) => {
		// Skip scroll events triggered by programmatic adjustment.
		if ( isAdjustingScrollRef.current ) {
			isAdjustingScrollRef.current = false;
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
	};

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

	// Clear all entries — clears the node buffer via the graph; the next frame
	// reflects 0 entries.
	const handleClear = () => {
		clear();
		filteredCountRef.current = 0;
		lastRenderedCountRef.current = 0;
		pushedRef.current = { count: 0, filter: filterRef.current, rps: 0 };
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
						onClick={ () => setPaused( ! isPaused ) }
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
									key={ entry.seq }
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
