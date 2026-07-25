/* global localStorage */
/**
 * Request Stream Component — real-time scrolling log of completed requests.
 *
 * A THIN wrapper over the shared `LogStreamViewer` chrome (toolbar, filter,
 * counts + staleness, pause, Debug, Clear, banner, body split, virtualized
 * `LogRowList`). The `requestlog:*` node graph (mounted by `useRequestLogGraph`)
 * owns all data: `requestlog:link` holds the EventSource and routes envelopes
 * to `requestlog:view` (a `LogStreamViewNode` subclass), whose ring the list
 * reads straight off the node each frame. This component only supplies the
 * differing pieces: the column set + picker, the grid row/header renderers,
 * the URL matchRow, and the `SegmentBrowseSidebar` browse rail.
 *
 * Click any request to view its full trace in the Performance Dashboard.
 */

import {
	useState,
	useEffect,
	useMemo,
	useCallback,
	memo,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Core, useNodeState } from '@newspack-nodes/runtime';
import { useRequestLogGraph } from './hooks/useRequestLogGraph';
import LogStreamViewer from '@newspack-nodes/shared/components/LogStreamViewer';
import LogListHeader from '@newspack-nodes/shared/components/LogListHeader';
import SegmentBrowseSidebar from '../components/SegmentBrowseSidebar';
import {
	formatDuration,
	getDurationClass,
	getStatusClass,
} from '@newspack-nodes/shared/utils/formatUtils';
import './styles/request-stream.scss';

const ROW_HEIGHT = 33;
const VIEW_NODE = 'requestlog:view';
// SSE connector owns liveness; "Xs ago" reads its lastEventTime, not the view.
const LINK_NODE = 'requestlog:link';
const EMPTY_VIEW = { paused: false, connectionError: false };
const COLUMNS_STORAGE_KEY = 'event-logger-stream-columns';

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

// Filter matches the row URL only (the toolbar promises "Filter by URL…").
const matchRow = ( row, filterLower ) =>
	String( row.url ?? '' )
		.toLowerCase()
		.includes( filterLower );

// Count + rate labels for the shared toolbar stats.
const renderCount = ( stats ) =>
	stats.visible !== stats.total
		? sprintf(
				// translators: 1: number of requests shown, 2: total requests.
				_n(
					'%1$d / %2$d request',
					'%1$d / %2$d requests',
					stats.total,
					'newspack-event-logger-nodes'
				),
				stats.visible,
				stats.total
		  )
		: sprintf(
				// translators: %d: number of requests shown in the log.
				_n(
					'%d request',
					'%d requests',
					stats.visible,
					'newspack-event-logger-nodes'
				),
				stats.visible
		  );
const renderRate = ( lps ) => `${ lps.toFixed( 1 ) } req/s`;

/**
 * Memoized row component - only re-renders when the row or columns change.
 */
const StreamRow = memo( function StreamRow( {
	row,
	visibleColumns,
	gridTemplate,
} ) {
	return (
		<div
			role="row"
			className={ `newspack-nodes-log-row ${
				row.isEven ? 'row-even' : 'row-odd'
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
								{ formatTime( row.timestamp ) }
							</span>
						);
					case 'duration':
						return (
							<span
								key={ col }
								role="cell"
								className={ `entry-duration entry-duration--${ getDurationClass(
									row.duration_ms
								) }` }
							>
								{ formatDuration( row.duration_ms ) }
							</span>
						);
					case 'status':
						return (
							<span
								key={ col }
								role="cell"
								className={ `entry-status entry-status--${ getStatusClass(
									row.status_code
								) }` }
							>
								{ row.status_code }
							</span>
						);
					case 'url':
						return (
							<span key={ col } role="cell" className="entry-url">
								<span className="entry-method">
									{ row.method }
								</span>{ ' ' }
								<a
									href={ `admin.php?page=event-logger-overview&url=${ row.urlHash }` }
									className="entry-url-link"
									title={ __(
										'View URL stats',
										'newspack-event-logger-nodes'
									) }
								>
									{ row.url }
								</a>
							</span>
						);
					case 'rid':
						return (
							<span key={ col } role="cell">
								<a
									className="entry-rid"
									href={ `admin.php?page=event-logger-overview&request=${ encodeURIComponent(
										row.rid
									) }` }
									title={ __(
										'View request trace',
										'newspack-event-logger-nodes'
									) }
								>
									{ row.rid }
								</a>
							</span>
						);
					case 'remote_addr':
						return (
							<span key={ col } role="cell" className="entry-ip">
								{ row.remote_addr || '-' }
							</span>
						);
					case 'user_agent':
						return (
							<span
								key={ col }
								role="cell"
								className="entry-ua"
								title={ row.user_agent }
							>
								{ row.user_agent || '-' }
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
 * @param {number} props.maxEntries Maximum rows to keep in the view ring.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function RequestStream( { maxEntries = 500 } ) {
	// Mount the graph; returns the control callbacks + the browse model.
	const { setPaused, browse } = useRequestLogGraph( { maxEntries } );

	// Low-freq view model (pause button, empty-state label, reconnect banner).
	const view = useNodeState( VIEW_NODE, 'view' ) ?? EMPTY_VIEW;
	const { paused: isPaused, connectionError } = view;

	const [ visibleColumns, setVisibleColumns ] = useState( () => {
		// Load from localStorage with validation.
		const validColumns = Object.keys( COLUMNS );
		try {
			const saved = localStorage.getItem( COLUMNS_STORAGE_KEY );
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

	// Save column selection to localStorage.
	useEffect( () => {
		localStorage.setItem(
			COLUMNS_STORAGE_KEY,
			JSON.stringify( visibleColumns )
		);
	}, [ visibleColumns ] );

	// Toggle column visibility, re-inserting in canonical COLUMNS order.
	const toggleColumn = ( col ) => {
		setVisibleColumns( ( prev ) => {
			if ( prev.includes( col ) ) {
				return prev.filter( ( c ) => c !== col );
			}
			const allCols = Object.keys( COLUMNS );
			return allCols.filter( ( c ) => prev.includes( c ) || c === col );
		} );
	};

	const gridTemplate = useMemo(
		() =>
			visibleColumns
				.map( ( col ) => COLUMNS[ col ]?.width || 'auto' )
				.join( ' ' ),
		[ visibleColumns ]
	);

	// Stable identity per column set keeps LogRowList's row memoization live.
	const renderRow = useCallback(
		( row ) => (
			<StreamRow
				key={ row.id }
				row={ row }
				visibleColumns={ visibleColumns }
				gridTemplate={ gridTemplate }
			/>
		),
		[ visibleColumns, gridTemplate ]
	);

	// Re-read the live nodes each frame so a graph reinit is picked up.
	const getViewNode = useCallback( () => Core.node( VIEW_NODE ), [] );
	const getLastEventTime = useCallback(
		() => Core.node( LINK_NODE )?.lastEventTime() ?? null,
		[]
	);

	// The wrapper publishes the grid template; scss applies it to the header.
	const listHeader = (
		<div
			className="event-logger-request-stream-columns"
			style={ { '--stream-grid-template': gridTemplate } }
		>
			<LogListHeader
				columns={ visibleColumns.map( ( col ) => ( {
					key: col,
					label: COLUMNS[ col ]?.label || col,
					tooltip: COLUMNS[ col ]?.tooltip,
				} ) ) }
			/>
		</div>
	);

	const columnPicker = showColumnPicker && (
		<div className="newspack-nodes-column-picker">
			{ Object.entries( COLUMNS ).map( ( [ key, col ] ) => (
				<label
					key={ key }
					htmlFor={ `col-${ key }` }
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
	);

	return (
		<LogStreamViewer
			className="event-logger-request-stream"
			ariaLabel={ __( 'Request log', 'newspack-event-logger-nodes' ) }
			title={ __( 'Request Log', 'newspack-event-logger-nodes' ) }
			pickerOptions={ null }
			isPaused={ isPaused }
			connectionError={ connectionError }
			onTogglePause={ () => setPaused( ! isPaused ) }
			getViewNode={ getViewNode }
			getLastEventTime={ getLastEventTime }
			sidebar={
				<SegmentBrowseSidebar
					browse={ browse }
					onSelectPartition={ ( key ) =>
						browse?.selectPartition( key )
					}
				/>
			}
			renderRow={ renderRow }
			rowHeight={ ROW_HEIGHT }
			matchRow={ matchRow }
			filterPlaceholder={ __(
				'Filter by URL…',
				'newspack-event-logger-nodes'
			) }
			renderCount={ renderCount }
			renderRate={ renderRate }
			toolbarExtras={
				<button
					className={ `button ${
						showColumnPicker ? 'is-active' : ''
					}` }
					onClick={ () => setShowColumnPicker( ! showColumnPicker ) }
					title={ __(
						'Select columns',
						'newspack-event-logger-nodes'
					) }
				>
					{ __( 'Cols', 'newspack-event-logger-nodes' ) }
				</button>
			}
			belowToolbar={ columnPicker }
			listHeader={ listHeader }
		/>
	);
}
