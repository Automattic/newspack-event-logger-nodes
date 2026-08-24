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
 * row/header renderers, the multi-field ingest gate, the entry-count and rate
 * labels, the toolbar partition picker, and the segment rail.
 *
 * Click any request ID to view its full trace in the Performance Dashboard.
 */

import { useCallback, memo } from '@wordpress/element';
import { __, _n } from '@wordpress/i18n';

import { Core, useNodeState } from '@newspack-nodes/runtime';
import { useErrorLogGraph } from './hooks/useErrorLogGraph';
import LogStreamViewer from '@newspack-nodes/shared/components/LogStreamViewer';
import {
	Cell,
	countLabel,
	logColumns,
	logListHeader,
	rateLabel,
	ridCell,
	timeCell,
	urlCell,
} from '../log-table/logTable';
import { gridTemplate } from '@newspack-nodes/shared/hooks/useColumnPicker';
import './styles/error-log.scss';

const ROW_HEIGHT = 33;
const VIEW_NODE = 'perferrors:view';
// View-model fallback until the view node publishes its first `view` state.
const EMPTY_VIEW = { paused: false, connectionError: false };

/**
 * Column definitions for the error log (fixed — no picker); the rest come from
 * the shared set.
 */
const COLUMNS = logColumns( {
	time: {
		tooltip: __( 'Error timestamp', 'newspack-event-logger-nodes' ),
	},
	rid: {},
	url: { width: 'minmax(0, 2fr)' },
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
} );

// The fixed column ORDER; the rows and the header both render from it.
const DEFAULT_COLUMNS = [ 'time', 'rid', 'url', 'keyword', 'message' ];

const GRID_TEMPLATE = gridTemplate( COLUMNS, DEFAULT_COLUMNS );

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

// Entries, not lines; spelled out because `_n()` extracts only literals.
const renderCount = ( stats ) =>
	countLabel(
		stats,
		// translators: %d: number of rows the ring holds.
		_n(
			'%d entry',
			'%d entries',
			stats.total,
			'newspack-event-logger-nodes'
		),
		// translators: 1: rows shown, 2: rows the ring holds.
		_n(
			'%1$d / %2$d entry',
			'%1$d / %2$d entries',
			stats.total,
			'newspack-event-logger-nodes'
		)
	);

// Rate label matching that wording: entries/s, one decimal place.
const renderRate = rateLabel(
	// translators: %s: entries-per-second rate, formatted to one decimal place.
	__( '%s entries/s', 'newspack-event-logger-nodes' )
);

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
				{ timeCell( row.ts ) }
				{ ridCell( row.rid ) }
				{ urlCell( row.method, row.url, row.urlHash ) }
				<Cell
					mod={ `entry-keyword entry-keyword--${ getKeywordClass(
						row.k
					) }` }
				>
					{ row.k }
				</Cell>
				<Cell mod="entry-message" title={ row.m }>
					{ row.m }
				</Cell>
			</div>
		);
	}
);

// Module-scope: a stable identity keeps LogRowList's row memoization live.
const renderRow = ( row ) => <ErrorRow key={ row.id } row={ row } />;

const listHeader = logListHeader( {
	className: 'event-logger-error-log-columns',
	columns: COLUMNS,
	order: DEFAULT_COLUMNS,
} );

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
	const { setPaused, clear, step, browse, setFilter } = useErrorLogGraph();

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
			pickerOptions={ browse.pickerOptions }
			selectedKey={ browse.selectedPartition }
			onPick={ browse.selectPartition }
			pickerLabel={ browse.pickerLabel }
			isPaused={ isPaused }
			connectionError={ connectionError }
			onTogglePause={ () => setPaused( ! isPaused ) }
			onStep={ step }
			onJump={ browse.jump }
			getViewNode={ getViewNode }
			onClear={ clear }
			onFilter={ setFilter }
			sidebar={ browse.sidebar }
			renderRow={ renderRow }
			rowHeight={ ROW_HEIGHT }
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
