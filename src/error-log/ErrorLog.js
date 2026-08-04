/**
 * Error Log Component — real-time scrolling log of the errors, warnings, fleet
 * alerts, and substrate stderr tailed across the `errors.*` partitions.
 *
 * A THIN wrapper over the shared `LogStreamViewer` chrome (toolbar, filter,
 * counts + rate, pause, step, offset jump, Debug, Clear, banner, body split,
 * virtualized `LogRowList`). The `perferrors:*` node graph (mounted by
 * `useErrorLogGraph`) owns all data: `perferrors:link` (a substrate
 * `RemoteLink`) holds the EventSource and fans its frames through the
 * `perferrors:stream` Tee into `perferrors:view` (a `LogStreamViewNode`
 * subclass), whose ring the list reads straight off the node each frame. This
 * component only supplies the differing pieces: the fixed column set, the grid
 * row/header renderers, the multi-field matchRow, the entry-count and rate
 * labels, and the `SegmentBrowseSidebar` browse rail.
 *
 * Click any request ID to view its full trace in the Performance Dashboard.
 */

import { useCallback, memo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Core, useNodeState } from '@newspack-nodes/runtime';
import { useErrorLogGraph } from './hooks/useErrorLogGraph';
import LogStreamViewer from '@newspack-nodes/shared/components/LogStreamViewer';
import LogListHeader from '@newspack-nodes/shared/components/LogListHeader';
import SegmentBrowseSidebar from '../components/SegmentBrowseSidebar';
import parseOffsetJump from '@newspack-nodes/shared/utils/parseOffsetJump';
import './styles/error-log.scss';

const ROW_HEIGHT = 33;
const VIEW_NODE = 'perferrors:view';
// View-model fallback until the view node publishes its first `view` state.
const EMPTY_VIEW = { paused: false, connectionError: false };

/**
 * Column definitions for the error log (fixed — no picker).
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
	url: {
		label: __( 'URL', 'newspack-event-logger-nodes' ),
		tooltip: __( 'Request method and URL', 'newspack-event-logger-nodes' ),
		width: 'minmax(0, 2fr)',
	},
	keyword: {
		label: __( 'Keyword', 'newspack-event-logger-nodes' ),
		tooltip: __( 'Error/warning keyword', 'newspack-event-logger-nodes' ),
		width: '240px',
	},
	message: {
		label: __( 'Message', 'newspack-event-logger-nodes' ),
		tooltip: __( 'Error message', 'newspack-event-logger-nodes' ),
		width: 'minmax(0, 3fr)',
	},
};

// The fixed column ORDER; the rows and the header both render from it.
const DEFAULT_COLUMNS = [ 'time', 'rid', 'url', 'keyword', 'message' ];

const GRID_TEMPLATE = DEFAULT_COLUMNS.map(
	( col ) => COLUMNS[ col ].width
).join( ' ' );

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
 * @return {string} Suffix of the `entry-keyword--` accent class in
 *                  `styles/error-log.scss`.
 */
const getKeywordClass = ( keyword ) => {
	if ( keyword === 'error' || keyword.endsWith( '(error)' ) ) {
		return 'error';
	}
	if ( keyword === 'warning' || keyword.endsWith( '(warning)' ) ) {
		return 'warning';
	}
	// Fleet alerts get a louder accent; substrate stderr a muted one.
	if ( keyword === 'alert' ) {
		return 'alert';
	}
	if ( keyword === 'stderr' ) {
		return 'stderr';
	}
	return 'info';
};

// Filter matches url, keyword, message, or request ID (per the placeholder).
const matchRow = ( row, filterLower ) =>
	[ row.rid, row.k, row.m, row.url ].some(
		( field ) =>
			'string' === typeof field &&
			field.toLowerCase().includes( filterLower )
	);

// Count label for the toolbar stats: entries, where the default says lines.
const renderCount = ( stats ) =>
	stats.visible !== stats.total
		? sprintf(
				// translators: 1: number of entries shown, 2: total entries.
				_n(
					'%1$d / %2$d entry',
					'%1$d / %2$d entries',
					stats.total,
					'newspack-event-logger-nodes'
				),
				stats.visible,
				stats.total
		  )
		: sprintf(
				// translators: %d: number of error-log entries shown.
				_n(
					'%d entry',
					'%d entries',
					stats.visible,
					'newspack-event-logger-nodes'
				),
				stats.visible
		  );

// Rate label matching that wording: entries/s, one decimal place.
const renderRate = ( lps ) => `${ lps.toFixed( 1 ) } entries/s`;

// JSDoc rides the inner function: on the const, memo() infers props as `{}`.
const ErrorRow = memo(
	/**
	 * One error-log row — the five fixed cells on a grid, memoized on `row`.
	 *
	 * @param {Object} props     Props.
	 * @param {Object} props.row Row from `perferrors:view`. Its `shapeRow()`
	 *                           supplies `ts`, `rid`, `k`, `m` — plus `method`,
	 *                           `url`, and `urlHash` when the entry carried a
	 *                           URL; the base view node stamps `id` and
	 *                           `isEven`.
	 * @return {import('react').ReactElement} The rendered row.
	 */
	function ErrorRow( { row } ) {
		return (
			<div
				role="row"
				className={ `newspack-nodes-log-row newspack-nodes-table__row ${
					row.isEven ? 'row-even' : 'row-odd'
				}` }
				style={ { gridTemplateColumns: GRID_TEMPLATE } }
			>
				<span
					role="cell"
					className="newspack-nodes-table__cell entry-time"
				>
					{ formatTime( row.ts ) }
				</span>
				<span role="cell" className="newspack-nodes-table__cell">
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
				<span
					role="cell"
					className="newspack-nodes-table__cell entry-url"
				>
					{ row.url && (
						<>
							<span className="entry-method">{ row.method }</span>{ ' ' }
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
						</>
					) }
				</span>
				<span
					role="cell"
					className={ `newspack-nodes-table__cell entry-keyword entry-keyword--${ getKeywordClass(
						row.k
					) }` }
				>
					{ row.k }
				</span>
				<span
					role="cell"
					className="newspack-nodes-table__cell entry-message"
					title={ row.m }
				>
					{ row.m }
				</span>
			</div>
		);
	}
);

// Module-scope: a stable identity keeps LogRowList's row memoization live.
const renderRow = ( row ) => <ErrorRow key={ row.id } row={ row } />;

// The wrapper publishes the grid template; scss applies it to the header.
const listHeader = (
	<div
		className="event-logger-error-log-columns"
		style={ { '--stream-grid-template': GRID_TEMPLATE } }
	>
		<LogListHeader
			columns={ DEFAULT_COLUMNS.map( ( col ) => ( {
				key: col,
				label: COLUMNS[ col ].label,
				tooltip: COLUMNS[ col ].tooltip,
			} ) ) }
		/>
	</div>
);

/**
 * Error Log Component.
 *
 * Mounts the `perferrors:*` graph, reads the low-frequency view model off
 * `perferrors:view`, and hands `LogStreamViewer` the Error Log's own pieces.
 *
 * Step and the offset input appear only while one partition is selected: a
 * seek addresses a segment within ONE directory, so it means nothing against
 * the whole `errors.*` glob. A bare offset resolves against the last segment
 * the view received, falling back to the browsed one.
 *
 * @return {import('react').ReactElement} Rendered component.
 */
export default function ErrorLog() {
	// Mount the graph; returns the control callbacks + the browse model.
	const { setPaused, browse } = useErrorLogGraph();

	// Low-frequency view model (pause button + reconnect banner + empty-state).
	const view = useNodeState( VIEW_NODE, 'view' ) ?? EMPTY_VIEW;
	const { paused: isPaused, connectionError } = view;

	// Re-read the live node each frame so a graph reinit is picked up.
	const getViewNode = useCallback( () => Core.node( VIEW_NODE ), [] );

	return (
		<LogStreamViewer
			className="event-logger-error-log"
			ariaLabel={ __( 'Error log', 'newspack-event-logger-nodes' ) }
			title={ __( 'Error Log', 'newspack-event-logger-nodes' ) }
			pickerOptions={ null }
			isPaused={ isPaused }
			connectionError={ connectionError }
			onTogglePause={ () => setPaused( ! isPaused ) }
			onStep={ browse?.selectedPartition ? browse.step : undefined }
			onJump={
				browse?.selectedPartition
					? ( text ) => {
							const position = parseOffsetJump(
								text,
								browse.lastReceivedSegment ??
									( 'number' === typeof browse.segmentId
										? browse.segmentId
										: null )
							);
							if ( position ) {
								browse.jumpTo( position );
							}
					  }
					: undefined
			}
			getViewNode={ getViewNode }
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
				'Filter by URL, keyword, message, or request ID…',
				'newspack-event-logger-nodes'
			) }
			renderCount={ renderCount }
			renderRate={ renderRate }
			listHeader={ listHeader }
		/>
	);
}
