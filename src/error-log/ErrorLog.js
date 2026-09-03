/**
 * Error Log Component — real-time scrolling log of the errors, warnings and
 * substrate stderr lines tailed across the `errors.*` partitions.
 *
 * A THIN wrapper over the shared `LogStreamViewer` chrome (toolbar, filter,
 * counts + rate, pause, step, offset jump, Debug, Clear, banner, body split,
 * virtualized `LogRowList`). The `perferrors:*` node graph (mounted by
 * `useErrorLogGraph`) owns all data: `perferrors:link` (a substrate
 * `RemoteLink`) holds the EventSource and fans its frames through the
 * `perferrors:stream` Tee into `perferrors:view` (a `LogStreamViewNode`
 * subclass), whose ring the list reads straight off the node each frame — row
 * data never becomes React state. This component supplies only the differing
 * pieces: the fixed column set, the grid row/header renderers, the entry-count
 * and rate labels, the filter placeholder, the toolbar partition picker, and
 * the segment rail.
 *
 * Click a request ID for its full trace in the Performance Dashboard; click a
 * URL for that URL's stats.
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

/**
 * Fixed row height, in pixels.
 *
 * `LogRowList` virtualizes on this number and publishes it to the scss as
 * `--log-row-height`, so its scroll arithmetic and the drawn row cannot
 * disagree.
 *
 * @type {number}
 */
const ROW_HEIGHT = 33;

/**
 * The view-model node. The chrome reads its published state; `LogRowList`
 * reads its ring.
 *
 * @type {string}
 */
const VIEW_NODE = 'perferrors:view';

/**
 * What the chrome renders until `perferrors:view` publishes its first `view`
 * state — the two fields it reads, so the pause button and the reconnect
 * banner never render off an undefined.
 *
 * @type {{paused: boolean, connectionError: boolean}}
 */
const EMPTY_VIEW = { paused: false, connectionError: false };

/**
 * The error log's columns, keyed by the row field each draws. Only what
 * differs from the shared set is spelled out; `logColumns()` merges the rest.
 * The set is fixed: this dashboard offers no column picker.
 *
 * @type {Object}
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

/**
 * The column order the rows and the header both render from.
 *
 * @type {string[]}
 */
const DEFAULT_COLUMNS = [ 'time', 'rid', 'url', 'keyword', 'message' ];

/**
 * The `grid-template-columns` value every row draws on, built once because
 * the column set never changes.
 *
 * @type {string}
 */
const GRID_TEMPLATE = gridTemplate( COLUMNS, DEFAULT_COLUMNS );

/**
 * Get keyword severity class.
 *
 * `Request_Builder_Node` routes `error`, `warning`, `stderr` and the
 * `(error)` / `(warning)` suffixes into `errors.*`, and `alert` into
 * `alerts.p0`, which this dashboard does not tail.
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
	if ( keyword === 'alert' ) {
		return 'alert';
	}
	if ( keyword === 'stderr' ) {
		return 'stderr';
	}
	return 'info';
};

/**
 * The toolbar's count label: rows shown over rows held while the filter hides
 * some, the plain total otherwise. Entries, not lines — and both forms are
 * spelled out here because `_n()` extracts only literals.
 *
 * @param {{total: number, visible: number}} stats Ring stats from
 *                                                 `LogRowList`.
 * @return {string} The label.
 */
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

/**
 * The toolbar's rate label, matching that wording: entries per second, to one
 * decimal place.
 *
 * @type {( lps: number ) => string}
 */
const renderRate = rateLabel(
	// translators: %s: entries-per-second rate, formatted to one decimal place.
	__( '%s entries/s', 'newspack-event-logger-nodes' )
);

const ErrorRow = memo(
	/**
	 * One error-log row — the five fixed cells on a grid, memoized on `row`.
	 *
	 * The docblock rides this inner function rather than the `ErrorRow`
	 * const, where `memo()` infers the props as `{}`.
	 *
	 * @param {Object} props     Props.
	 * @param {Object} props.row Row from `perferrors:view`. Its `shapeRow()`
	 *                           supplies `ts`, `rid`, `k` and `m` — plus
	 *                           `method`, `url` and `urlHash` when the entry
	 *                           carried a URL; the base view node stamps `id`
	 *                           and `isEven`.
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

/**
 * One row, for `LogRowList`. Module scope gives it a stable identity, which is
 * what keeps that list's per-row memoization live across renders.
 *
 * @type {( row: Object ) => import('react').ReactElement}
 */
const renderRow = ( row ) => <ErrorRow key={ row.id } row={ row } />;

/**
 * The header row above the list, built once: the column set is fixed, so
 * nothing a render can change reaches it.
 *
 * @type {import('react').ReactElement}
 */
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
	// Mount the graph; it returns the controls and the browse model.
	const { setPaused, clear, step, browse, setFilter } = useErrorLogGraph();

	// The low-frequency model: pause button, empty label, reconnect banner.
	const view = useNodeState( VIEW_NODE, 'view' ) ?? EMPTY_VIEW;
	const { paused: isPaused, connectionError } = view;

	// Read the live node per frame, so a graph rebuild is picked up.
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
