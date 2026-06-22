/* global requestAnimationFrame, cancelAnimationFrame */
/**
 * Error Log Component
 *
 * Real-time scrolling log of errors and warnings from errors.log.
 *
 * This is a THIN view over the `perferrors:view` node graph (mounted by
 * `useErrorLogGraph`). The graph owns all data: the substrate's `_sse` holds
 * the EventSource connection and streams envelopes directly into
 * `perferrors:view`, which shapes them into rows and owns the buffer + view
 * model. This component only renders.
 *
 * Two read paths, matching the view node's two cadences:
 * - LOW frequency: `useNodeState('perferrors:view','view')` for
 *   `{ paused, connectionError, lastEventTime }` (the pause button, the reconnect
 *   banner, the empty-state label, and the "Xs ago" staleness).
 * - HIGH frequency: the rAF reads `Core.node('perferrors:view').entries` directly
 *   each frame — a busy stream never re-renders React per error.
 *
 * Click any request ID to view its full trace in the Performance Dashboard.
 */

import {
	useState,
	useEffect,
	useLayoutEffect,
	useRef,
	useCallback,
	useMemo,
	memo,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Core, useNodeState } from '@newspack-nodes/runtime';
import { useErrorLogGraph } from './hooks/useErrorLogGraph';
import useVirtualization from '@newspack-nodes/shared/hooks/useVirtualization';
import ConnectionBanner from '@newspack-nodes/shared/components/ConnectionBanner';
import './styles/error-log.scss';

const ROW_HEIGHT = 33;
const VIEW_NODE = 'perferrors:view';
const SSE_NODE = '_sse';
const EMPTY_VIEW = {
	paused: false,
	connectionError: false,
	lastEventTime: null,
};

/**
 * Column definitions for the error log.
 */
const COLUMNS = {
	time: {
		label: __( 'Time', 'newspack-event-logger-nodes' ),
		tooltip: __( 'Error timestamp', 'newspack-event-logger-nodes' ),
		width: '100px',
	},
	rid: {
		label: __( 'Request ID', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Click to view request trace',
			'newspack-event-logger-nodes'
		),
		width: '240px',
	},
	keyword: {
		label: __( 'Keyword', 'newspack-event-logger-nodes' ),
		tooltip: __( 'Error/warning keyword', 'newspack-event-logger-nodes' ),
		width: '240px',
	},
	message: {
		label: __( 'Message', 'newspack-event-logger-nodes' ),
		tooltip: __( 'Error message', 'newspack-event-logger-nodes' ),
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
									href={ `admin.php?page=newspack-nodes-performance&request=${ encodeURIComponent(
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
 * @return {import('react').ReactElement} Rendered component.
 */
export default function ErrorLog() {
	// Mount the node graph; it returns the thin control callbacks.
	const { setPaused, clear } = useErrorLogGraph();

	// Low-frequency view model (pause button + reconnect banner + empty-state).
	const view = useNodeState( VIEW_NODE, 'view' ) ?? EMPTY_VIEW;
	const { paused: isPaused, connectionError } = view;

	const [ filter, setFilter ] = useState( '' );
	// The rendered entry buffer, fed from the rAF at frame rate (read straight off
	// the view node). The original re-rendered via a 100ms setInterval; per-frame
	// is visually identical and keeps everything in one push.
	const [ entries, setEntries ] = useState( [] );

	const visibleColumns = DEFAULT_COLUMNS;

	const listRef = useRef( null );
	const contentRef = useRef( null );
	const offsetRef = useRef( 0 );
	const savedOffsetRef = useRef( 0 );
	const rafRef = useRef( null );
	const isAdjustingScrollRef = useRef( false );
	const [ animOffsetRows, setAnimOffsetRows ] = useState( 0 );

	// Newest seq the layout effect has already smooth-scrolled for, and the
	// filter that was active then — so it compensates once per genuinely-new row
	// and re-baselines (no spurious scroll) when the filter changes.
	const lastCompensatedSeqRef = useRef( null );
	const lastCompensatedFilterRef = useRef( filter );
	// Last state we pushed to React — so idle frames (nothing changed) push no
	// new refs and don't re-render. `topSeq` catches cap rotation (length
	// constant, newest seq climbing); `count` catches clear/filter shrink.
	const pushedRef = useRef( { topSeq: -1, count: -1, filter: null } );
	// Filter kept in a ref so the rAF reads the latest without re-subscribing.
	const filterRef = useRef( filter );
	filterRef.current = filter;
	// Last _sse connector lastEventTime the rAF observed — drives "Xs ago".
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

	// A row matches the filter on keyword, message, or request id.
	const matchesFilter = ( e, needle ) =>
		e.k?.toLowerCase().includes( needle ) ||
		e.m?.toLowerCase().includes( needle ) ||
		e.rid?.toLowerCase().includes( needle );

	// Animation/read loop. Reads the high-volume buffer (node.entries) directly
	// every frame, snapshots + filters it, decays the smooth-scroll offset, and
	// pushes the entries snapshot to React only when changed. The new-row offset
	// compensation lives in the layout effect below so it lands in the same
	// commit as the row it compensates for.
	useEffect( () => {
		const animate = () => {
			const node = Core.node( VIEW_NODE );
			const buffer = node?.entries ?? [];
			const filterLower = filterRef.current.toLowerCase();

			// Staleness reflects CONNECTION liveness, owned by the shared _sse
			// connector (it stamps lastEventTime on every frame AND the server's
			// idle heartbeats), so an idle-but-healthy stream resets "Xs ago"
			// instead of climbing; a real drop (no heartbeats) leaves it frozen
			// and "ago" climbs as the intended warning.
			lastEventTimeRef.current =
				Core.node( SSE_NODE )?.lastEventTime ?? null;

			// Snapshot (and filter) the buffer so a mid-frame append can't mutate
			// what we draw / count.
			const snapshot = filterRef.current
				? buffer.filter( ( e ) => matchesFilter( e, filterLower ) )
				: buffer.slice();

			// Newest seq of the rendered (filtered) view drives change detection —
			// robust to the cap, where length is pinned but the seq keeps climbing.
			const topSeq = snapshot.length ? snapshot[ 0 ].seq : 0;

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

			// Push the entries snapshot ONLY when it changed — the newest seq
			// (catches cap rotation at constant length), the count (clear / filter
			// shrink), or the filter. Skipping unchanged frames keeps idle frames
			// from re-rendering React.
			const pushed = pushedRef.current;
			if (
				topSeq !== pushed.topSeq ||
				snapshot.length !== pushed.count ||
				filterRef.current !== pushed.filter
			) {
				setEntries( snapshot );
				pushed.topSeq = topSeq;
				pushed.count = snapshot.length;
				pushed.filter = filterRef.current;
			}
			setAnimOffsetRows( ( prev ) =>
				prev === currentOffsetRows ? prev : currentOffsetRows
			);

			rafRef.current = requestAnimationFrame( animate );
		};

		rafRef.current = requestAnimationFrame( animate );
		return () => cancelAnimationFrame( rafRef.current );
	}, [] );

	// Scroll handler for animation save/restore.
	const wasAtTopRef = useRef( true );
	const handleScroll = useCallback( ( e ) => {
		if ( isAdjustingScrollRef.current ) {
			isAdjustingScrollRef.current = false;
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
				? entries.filter( ( e ) => matchesFilter( e, filterLower ) )
				: entries,
		[ entries, filter, filterLower ]
	);

	// Smooth-scroll compensation, atomic with the row it compensates for. Runs
	// synchronously after React commits the new rows but before paint, so the
	// offset that holds the existing rows in place lands in the SAME paint as the
	// prepended row — no jump-then-correct flicker. Keyed on the newest committed
	// seq (robust to the cap, where length is constant) and re-baselines on a
	// filter change so a filter switch doesn't read as new rows.
	useLayoutEffect( () => {
		const topSeq = filteredEntries.length ? filteredEntries[ 0 ].seq : 0;
		const prevSeq = lastCompensatedSeqRef.current;
		const filterChanged = lastCompensatedFilterRef.current !== filter;
		lastCompensatedFilterRef.current = filter;
		// Don't advance the baseline past an empty list — the first real row is
		// the baseline, so it doesn't slide in.
		if ( topSeq > 0 ) {
			lastCompensatedSeqRef.current = topSeq;
		}

		// First observation (baseline), a filter switch, or no newer row → no
		// smooth scroll.
		if ( null === prevSeq || filterChanged || topSeq <= prevSeq ) {
			return;
		}

		// Newly-prepended rows = the leading run with seq newer than last time.
		const firstOld = filteredEntries.findIndex( ( e ) => e.seq <= prevSeq );
		const newRows = -1 === firstOld ? filteredEntries.length : firstOld;

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
	}, [ filteredEntries, filter ] );

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

	// Clear all entries — clears the node buffer via the graph; the next frame
	// reflects 0 entries.
	const handleClear = () => {
		clear();
		lastCompensatedSeqRef.current = null; // re-baseline: first post-clear row won't slide.
		pushedRef.current = { topSeq: 0, count: 0, filter: filterRef.current };
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
				<h1 className="newspack-dashboard-title">
					{ __( 'Error Log', 'newspack-event-logger-nodes' ) }
				</h1>
				<div className="event-logger-error-log-controls">
					<input
						type="text"
						className="event-logger-error-log-search"
						placeholder={ __(
							'Filter by keyword, message, or request ID…',
							'newspack-event-logger-nodes'
						) }
						value={ filter }
						onChange={ ( e ) => setFilter( e.target.value ) }
					/>
					<span className="event-logger-error-log-stats">
						<span className="event-logger-error-log-count">
							{ sprintf(
								// translators: %d: number of error-log entries shown.
								_n(
									'%d entry',
									'%d entries',
									filteredEntries.length,
									'newspack-event-logger-nodes'
								),
								filteredEntries.length
							) }
						</span>
						{ staleSec !== null && (
							<span
								className="event-logger-error-log-age"
								style={ {
									color:
										staleSec > 10 ? '#dba617' : '#757575',
								} }
							>
								{ sprintf(
									// translators: %d: seconds since the last error was received.
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
						className={ `event-logger-error-log-btn ${
							isPaused ? 'paused' : ''
						}` }
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
						className="event-logger-error-log-btn"
						onClick={ handleClear }
						title={ __(
							'Clear all entries',
							'newspack-event-logger-nodes'
						) }
					>
						{ __( 'Clear', 'newspack-event-logger-nodes' ) }
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
								? __(
										'Paused - click play to resume',
										'newspack-event-logger-nodes'
								  )
								: __(
										'Waiting for errors…',
										'newspack-event-logger-nodes'
								  ) }
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
