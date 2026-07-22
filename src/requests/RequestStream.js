/* global requestAnimationFrame, cancelAnimationFrame, localStorage */
/**
 * Request Stream Component
 *
 * Real-time scrolling log of completed requests.
 *
 * This is a THIN view over the `requestlog:*` node graph (mounted by
 * `useRequestLogGraph`). The graph owns all data: `requestlog:link` holds the EventSource
 * and routes envelopes directly to `requestlog:view`, which defensively shapes
 * each completed-request envelope (drop missing-url, clip url + UA, default-fill)
 * and applies the URL filter before holding the buffer + view model. This
 * component only renders.
 *
 * Two read paths, matching the view node's two cadences:
 * - LOW frequency: `useNodeState('requestlog:view','view')` for
 *   `{ paused, connectionError }` (the pause button, empty-state label, and the
 *   reconnect banner).
 * - HIGH frequency: the rAF reads `Core.node('requestlog:view').entries` + `.rps`
 *   directly each frame (and the RemoteLink's `lastEventTime()` for staleness) — a
 *   busy stream never re-renders React per request; only the cheap derived state
 *   (the snapshot + rps) is pushed
 *   when it changes.
 *
 * Click any request to view its full trace in the Performance Dashboard. Entries
 * are newest-first - user scrolls down to see history.
 */

import {
	useState,
	useEffect,
	useLayoutEffect,
	useRef,
	useMemo,
	memo,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Core, useNodeState } from '@newspack-nodes/runtime';
import { useRequestLogGraph } from './hooks/useRequestLogGraph';
import useVirtualization from '@newspack-nodes/shared/hooks/useVirtualization';
import ConnectionBanner from '@newspack-nodes/shared/components/ConnectionBanner';
import SegmentBrowseSidebar from '../components/SegmentBrowseSidebar';
import {
	formatDuration,
	getDurationClass,
	getStatusClass,
} from '@newspack-nodes/shared/utils/formatUtils';
import './styles/request-stream.scss';

const ROW_HEIGHT = 33; // Fixed row height in pixels.
const VIEW_NODE = 'requestlog:view';
const LINK_NODE = 'requestlog:link';
const EMPTY_VIEW = { paused: false, connectionError: false };

/**
 * Column definitions for the request log.
 */
const COLUMNS = {
	time: {
		label: __( 'Time', 'newspack-event-logger-nodes' ),
		tooltip: __( 'Request completion time', 'newspack-event-logger-nodes' ),
		width: '100px',
	},
	rid: {
		label: __( 'Request ID', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Unique request identifier - click to view full trace',
			'newspack-event-logger-nodes'
		),
		width: '240px',
	},
	url: {
		label: __( 'URL', 'newspack-event-logger-nodes' ),
		tooltip: __( 'Request method and URL', 'newspack-event-logger-nodes' ),
		width: 'auto',
	},
	status: {
		label: __( 'Status', 'newspack-event-logger-nodes' ),
		tooltip: __( 'HTTP status code', 'newspack-event-logger-nodes' ),
		width: '50px',
	},
	remote_addr: {
		label: __( 'IP', 'newspack-event-logger-nodes' ),
		tooltip: __( 'Client IP address', 'newspack-event-logger-nodes' ),
		width: '100px',
	},
	user_agent: {
		label: __( 'UA', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Browser/client identifier',
			'newspack-event-logger-nodes'
		),
		width: '200px',
	},
	duration: {
		label: __( 'Duration', 'newspack-event-logger-nodes' ),
		tooltip: __( 'Request duration', 'newspack-event-logger-nodes' ),
		width: '70px',
	},
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
				entry.seq % 2 === 0 ? 'row-even' : 'row-odd'
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
									href={ `admin.php?page=event-logger-overview&url=${ entry.urlHash }` }
									className="entry-url-link"
									title={ __(
										'View URL stats',
										'newspack-event-logger-nodes'
									) }
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
									href={ `admin.php?page=event-logger-overview&request=${ encodeURIComponent(
										entry.rid
									) }` }
									title={ __(
										'View request trace',
										'newspack-event-logger-nodes'
									) }
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
	// Mount the graph; returns the control callbacks + the browse model.
	const {
		setPaused,
		clear,
		setFilter: setViewFilter,
		browse,
	} = useRequestLogGraph( { maxEntries } );

	// Low-freq view model (pause button, empty-state label, reconnect banner).
	const view = useNodeState( VIEW_NODE, 'view' ) ?? EMPTY_VIEW;
	const { paused: isPaused, connectionError } = view;

	const [ filter, setFilter ] = useState( '' );
	// Rendered entry buffer + RPS, fed from rAF at frame rate (read off node).
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
	const isAdjustingScrollRef = useRef( false ); // skip programmatic scroll.
	const [ animOffsetRows, setAnimOffsetRows ] = useState( 0 ); // offset in rows.

	// Newest buffered seq already smooth-scrolled for (compensate per row).
	const lastCompensatedSeqRef = useRef( null );
	// Last state pushed to React; skip idle frames (view/seq/count change).
	const pushedRef = useRef( {
		viewNode: null,
		topSeq: -1,
		count: -1,
		rps: -1,
	} );
	// Last RemoteLink lastEventTime() the rAF observed — drives "Xs ago".
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

	// rAF loop: snapshot pre-filtered entries, decay offset, push on change.
	useEffect( () => {
		const animate = () => {
			const node = Core.node( VIEW_NODE );
			const buffer = node?.entries ?? [];
			const rps = node?.rps ?? 0;

			// Staleness = connection liveness (link lastEventTime passthrough).
			lastEventTimeRef.current =
				Core.node( LINK_NODE )?.lastEventTime() ?? null;

			// Copy admitted rows so a mid-frame append cannot mutate this draw.
			const snapshot = buffer.slice();

			// Newest buffered seq drives change detection (cap-robust).
			const topSeq = snapshot.length ? snapshot[ 0 ].seq : 0;

			// Decay offset toward 0; virtualize on row-boundary crossings.
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

			// Push derived state when seq/count/RPS changed (skip idle).
			const pushed = pushedRef.current;
			if (
				node !== pushed.viewNode ||
				topSeq !== pushed.topSeq ||
				snapshot.length !== pushed.count
			) {
				setEntries( snapshot );
				pushed.viewNode = node;
				pushed.topSeq = topSeq;
				pushed.count = snapshot.length;
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

	// Smooth-scroll compensation lands in same paint as its row (no flicker).
	useLayoutEffect( () => {
		const topSeq = entries.length ? entries[ 0 ].seq : 0;
		const prevSeq = lastCompensatedSeqRef.current;
		// Don't advance baseline past an empty list (first row is baseline).
		if ( topSeq > 0 ) {
			lastCompensatedSeqRef.current = topSeq;
		}

		// Baseline or no newer row → no smooth scroll.
		if ( null === prevSeq || topSeq <= prevSeq ) {
			return;
		}

		// Newly-prepended rows = the leading run with seq newer than last time.
		const firstOld = entries.findIndex( ( e ) => e.seq <= prevSeq );
		const newRows = -1 === firstOld ? entries.length : firstOld;

		const list = listRef.current;
		const isAtTop = ! list || list.scrollTop < ROW_HEIGHT;
		if ( isAtTop ) {
			// Hold the existing rows in place; the rAF decays the offset to 0.
			offsetRef.current -= newRows * ROW_HEIGHT;
			if ( contentRef.current ) {
				contentRef.current.style.transform = `translate3d(0,${ offsetRef.current }px,0)`;
			}
		} else {
			// Maintain scroll position when the user is reading history below.
			isAdjustingScrollRef.current = true;
			list.scrollTop += newRows * ROW_HEIGHT;
		}
	}, [ entries ] );

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
		entries.length,
		'self',
		animOffsetRows * ROW_HEIGHT
	);
	const visibleEntries = entries.slice( startIndex, endIndex );
	// A new admission projection starts at the origin with no inherited motion.
	const rebaseRenderedRows = () => {
		lastCompensatedSeqRef.current = null;
		pushedRef.current.topSeq = -1;
		pushedRef.current.count = -1;
		setEntries( [] );
		offsetRef.current = 0;
		savedOffsetRef.current = 0;
		setAnimOffsetRows( 0 );
		isAdjustingScrollRef.current = false;
		wasAtTopRef.current = true;
		if ( contentRef.current ) {
			contentRef.current.style.transform = '';
		}
		if ( listRef.current ) {
			listRef.current.scrollTop = 0;
		}
	};

	const handleFilterChange = ( event ) => {
		const nextFilter = event.target.value;
		setFilter( nextFilter );
		rebaseRenderedRows();
		setViewFilter( nextFilter );
	};

	// Clear all entries via the graph; the next frame shows 0 entries.
	const handleClear = () => {
		clear();
		rebaseRenderedRows();
		pushedRef.current.rps = -1;
	};

	// Switch partition; the hook clears the view, so rebase our rows.
	const handleSelectPartition = ( key ) => {
		browse?.selectPartition( key );
		rebaseRenderedRows();
		pushedRef.current.rps = -1;
	};

	return (
		<div
			className="event-logger-request-stream"
			role="table"
			aria-label="Request log"
		>
			<div className="event-logger-request-stream-header">
				<h1 className="newspack-dashboard-title">
					{ __( 'Request Log', 'newspack-event-logger-nodes' ) }
				</h1>
				<div className="newspack-nodes-toolbar">
					<input
						type="text"
						className="newspack-nodes-search-input"
						placeholder={ __(
							'Filter by URL…',
							'newspack-event-logger-nodes'
						) }
						value={ filter }
						onChange={ handleFilterChange }
					/>
					<span className="newspack-nodes-toolbar-stats">
						<span className="newspack-nodes-toolbar-stats__count">
							{ sprintf(
								// translators: %d: number of requests shown in the log.
								_n(
									'%d request',
									'%d requests',
									entries.length,
									'newspack-event-logger-nodes'
								),
								entries.length
							) }
						</span>
						<span className="newspack-nodes-toolbar-stats__rps">
							{ requestsPerSecond.toFixed( 1 ) } req/s
						</span>
						{ staleSec !== null && (
							<span
								style={ {
									color:
										staleSec > 10 ? '#dba617' : '#757575',
									fontSize: '11px',
									marginLeft: '8px',
								} }
							>
								{ sprintf(
									// translators: %d: seconds since the last request was received.
									__(
										'%ds ago',
										'newspack-event-logger-nodes'
									),
									staleSec
								) }
							</span>
						) }
					</span>
					<button
						className={ `button ${ isPaused ? 'is-paused' : '' }` }
						onClick={ () => setPaused( ! isPaused ) }
						title={
							isPaused
								? __(
										'Resume streaming',
										'newspack-event-logger-nodes'
								  )
								: __(
										'Pause streaming',
										'newspack-event-logger-nodes'
								  )
						}
					>
						{ isPaused ? '▶' : '⏸' }
					</button>
					<button
						className="button"
						onClick={ handleClear }
						title={ __(
							'Clear all entries',
							'newspack-event-logger-nodes'
						) }
					>
						{ __( 'Clear', 'newspack-event-logger-nodes' ) }
					</button>
					<button
						className={ `button ${
							showColumnPicker ? 'is-active' : ''
						}` }
						onClick={ () =>
							setShowColumnPicker( ! showColumnPicker )
						}
						title={ __(
							'Select columns',
							'newspack-event-logger-nodes'
						) }
					>
						{ __( 'Cols', 'newspack-event-logger-nodes' ) }
					</button>
				</div>
			</div>

			<ConnectionBanner
				connectionError={ connectionError }
				message={ __(
					'Connection lost. Reconnecting…',
					'newspack-event-logger-nodes'
				) }
			/>

			{ showColumnPicker && (
				<div className="newspack-nodes-column-picker">
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

			<div className="event-logger-request-stream-body">
				<SegmentBrowseSidebar
					browse={ browse }
					onSelectPartition={ handleSelectPartition }
				/>
				<div className="event-logger-request-stream-main">
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
							{ entries.length === 0 ? (
								<div className="event-logger-request-stream-empty">
									{ isPaused
										? __(
												'Paused - click play to resume',
												'newspack-event-logger-nodes'
										  )
										: __(
												'Waiting for requests…',
												'newspack-event-logger-nodes'
										  ) }
								</div>
							) : (
								<>
									<div
										style={ {
											height: offsetTop,
											flexShrink: 0,
										} }
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
			</div>
		</div>
	);
}
