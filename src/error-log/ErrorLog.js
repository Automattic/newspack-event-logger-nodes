/* global requestAnimationFrame, cancelAnimationFrame */
/**
 * Error Log Component
 *
 * Real-time scrolling log of errors and warnings from errors.log.
 *
 * This is a THIN view over the `perferrors:view` node graph (mounted by
 * `useErrorLogGraph`). The graph owns all data: the substrate's `perferrors:link` holds
 * the EventSource connection and streams envelopes directly into
 * `perferrors:view`, which shapes and filters them before they enter its buffer.
 * This component only renders.
 *
 * Two read paths, matching the view node's two cadences:
 * - LOW frequency: `useNodeState('perferrors:view','view')` for
 *   `{ paused, connectionError }` (the pause button, the reconnect banner, the
 *   empty-state label). The "Xs ago" staleness is read off the RemoteLink.
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
const LINK_NODE = 'perferrors:link';
const EMPTY_VIEW = {
	paused: false,
	connectionError: false,
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
								{ formatTime( entry.ts ) }
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
	const { setPaused, clear, setFilter: setViewFilter } = useErrorLogGraph();

	// Low-frequency view model (pause button + reconnect banner + empty-state).
	const view = useNodeState( VIEW_NODE, 'view' ) ?? EMPTY_VIEW;
	const { paused: isPaused, connectionError } = view;

	const [ filter, setFilter ] = useState( '' );
	// Rendered entry buffer, fed from rAF at frame rate (read off node).
	const [ entries, setEntries ] = useState( [] );

	const visibleColumns = DEFAULT_COLUMNS;

	const listRef = useRef( null );
	const contentRef = useRef( null );
	const offsetRef = useRef( 0 );
	const savedOffsetRef = useRef( 0 );
	const rafRef = useRef( null );
	const isAdjustingScrollRef = useRef( false );
	const [ animOffsetRows, setAnimOffsetRows ] = useState( 0 );

	// Newest buffered seq already smooth-scrolled for (compensate per row).
	const lastCompensatedSeqRef = useRef( null );
	// Last state pushed to React; skip idle frames (view/seq/count change).
	const pushedRef = useRef( {
		viewNode: null,
		topSeq: -1,
		count: -1,
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

			// Push snapshot only when seq/count changed (skip idle).
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
				<div className="newspack-nodes-toolbar">
					<input
						type="text"
						className="newspack-nodes-search-input"
						placeholder={ __(
							'Filter by keyword, message, or request ID…',
							'newspack-event-logger-nodes'
						) }
						value={ filter }
						onChange={ handleFilterChange }
					/>
					<span className="newspack-nodes-toolbar-stats">
						<span className="newspack-nodes-toolbar-stats__count">
							{ sprintf(
								// translators: %d: number of error-log entries shown.
								_n(
									'%d entry',
									'%d entries',
									entries.length,
									'newspack-event-logger-nodes'
								),
								entries.length
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
					{ entries.length === 0 ? (
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
