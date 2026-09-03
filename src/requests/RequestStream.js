/**
 * Request Stream Component — real-time scrolling log of completed requests.
 *
 * A THIN wrapper over the shared `LogStreamViewer` chrome (toolbar, filter,
 * counts + rate, pause, step, offset jump, Debug, Clear, banner, body split,
 * virtualized `LogRowList`). The `requestlog:*` node graph (mounted by
 * `useRequestLogGraph`) owns all data: `requestlog:link` holds the EventSource
 * and fans its frames through the `requestlog:stream` Tee into
 * `requestlog:view` (a `LogStreamViewNode` subclass), whose ring the list reads
 * straight off the node each frame — row data never becomes React state. This
 * component supplies only the differing pieces: the column set + picker, the
 * grid row/header renderers, the request-count and rate labels, the filter
 * placeholder, the toolbar partition picker, and the segment rail.
 *
 * Click a request ID for its full trace in the Performance Dashboard; click a
 * URL for that URL's stats.
 */

import { useState, useCallback, memo } from '@wordpress/element';
import { __, _n } from '@wordpress/i18n';

import { Core, useNodeState } from '@newspack-nodes/runtime';
import { useRequestLogGraph } from './hooks/useRequestLogGraph';
import LogStreamViewer from '@newspack-nodes/shared/components/LogStreamViewer';
import ColumnPicker from '@newspack-nodes/shared/components/ColumnPicker';
import { useColumnPicker } from '@newspack-nodes/shared/hooks/useColumnPicker';
import {
	cellRenderer,
	countLabel,
	durationCell,
	ipCell,
	logColumns,
	logListHeader,
	rateLabel,
	ridCell,
	statusCell,
	timeCell,
	uaCell,
	urlCell,
} from '../log-table/logTable';
import './styles/request-stream.scss';

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
const VIEW_NODE = 'requestlog:view';

/**
 * What the chrome renders until `requestlog:view` publishes its first `view`
 * state — the two fields it reads, so the pause button and the reconnect
 * banner never render off an undefined.
 *
 * @type {{paused: boolean, connectionError: boolean}}
 */
const EMPTY_VIEW = { paused: false, connectionError: false };

/**
 * localStorage key holding the reader's column selection.
 *
 * @type {string}
 */
const COLUMNS_STORAGE_KEY = 'event-logger-stream-columns';

/**
 * The request log's columns, keyed by the row field each draws. Only what
 * differs from the shared set is spelled out; `logColumns()` merges the rest.
 * Declaration order is the table's column order, because `useColumnPicker`
 * re-inserts a toggled-on column here rather than at the end.
 *
 * @type {Object}
 */
const COLUMNS = logColumns( {
	time: {
		tooltip: __( 'Request completion time', 'newspack-event-logger-nodes' ),
	},
	rid: {},
	url: {},
	status_code: {},
	remote_addr: {},
	user_agent: {},
	duration: {
		label: __( 'Duration', 'newspack-event-logger-nodes' ),
		tooltip: __( 'Request duration', 'newspack-event-logger-nodes' ),
		width: '70px',
	},
} );

/**
 * Retired column key → current key, for selections already in localStorage.
 *
 * A stored selection naming `status` restores as `status_code`. Without the
 * mapping `useColumnPicker` discards the unknown key, Status vanishes from
 * every selection that names it, and the write-back makes the loss permanent.
 *
 * @type {Object<string,string>}
 */
const COLUMN_ALIASES = { status: 'status_code' };

/**
 * The columns visible before the reader chooses — every declared column except
 * `user_agent`, which the "Cols" picker turns on.
 *
 * @type {string[]}
 */
const DEFAULT_COLUMNS = [
	'time',
	'rid',
	'url',
	'status_code',
	'remote_addr',
	'duration',
];

/**
 * The toolbar's rate label: requests per second, to one decimal place.
 *
 * @type {( lps: number ) => string}
 */
const renderRate = rateLabel(
	// translators: %s: requests-per-second rate, formatted to one decimal place.
	__( '%s req/s', 'newspack-event-logger-nodes' )
);

/**
 * The toolbar's count label: rows shown over rows held while the filter hides
 * some, the plain total otherwise. Requests, not lines — and both forms are
 * spelled out here because `_n()` extracts only literals.
 *
 * @param {{total: number, visible: number}} stats Ring stats from `LogRowList`.
 * @return {string} The label.
 */
const renderCount = ( stats ) =>
	countLabel(
		stats,
		// translators: %d: number of rows the ring holds.
		_n(
			'%d request',
			'%d requests',
			stats.total,
			'newspack-event-logger-nodes'
		),
		// translators: 1: rows shown, 2: rows the ring holds.
		_n(
			'%1$d / %2$d request',
			'%1$d / %2$d requests',
			stats.total,
			'newspack-event-logger-nodes'
		)
	);

/**
 * One cell of a `requestlog:view` row, by column.
 *
 * @type {( col: string, row: Object ) => import('react').ReactElement}
 */
const renderCell = cellRenderer( {
	time: ( row, col ) => timeCell( row.timestamp, col ),
	duration: ( row, col ) => durationCell( row.duration_ms, col ),
	status_code: ( row, col ) => statusCell( row.status_code, col ),
	url: ( row, col ) => urlCell( row.method, row.url, row.urlHash, col ),
	rid: ( row, col ) => ridCell( row.rid, col ),
	remote_addr: ( row, col ) => ipCell( row.remote_addr, col ),
	user_agent: ( row, col ) => uaCell( row.user_agent, col ),
} );

const StreamRow = memo(
	/**
	 * One request-log row — the visible cells on a grid, re-rendered only when
	 * the row or the column set changes.
	 *
	 * The docblock rides this inner function rather than the `StreamRow`
	 * const, where `memo()` infers the props as `{}`.
	 *
	 * @param {Object}   props                Component props.
	 * @param {Object}   props.row            Row from `requestlog:view`. Its
	 *                                        `shapeRow()` supplies `timestamp`,
	 *                                        `rid`, `method`, `url`, `urlHash`,
	 *                                        `status_code`, `remote_addr`,
	 *                                        `user_agent` and `duration_ms`;
	 *                                        the base view node stamps `id` and
	 *                                        `isEven`.
	 * @param {string[]} props.visibleColumns Column keys to render, in display
	 *                                        order.
	 * @param {string}   props.gridTemplate   `grid-template-columns` value for
	 *                                        the row.
	 * @return {import('react').ReactElement} Rendered row.
	 */
	function StreamRow( { row, visibleColumns, gridTemplate } ) {
		return (
			<div
				role="row"
				className={ `newspack-nodes-log-row newspack-nodes-table__row ${
					row.isEven ? 'row-even' : 'row-odd'
				}` }
				style={ { gridTemplateColumns: gridTemplate } }
			>
				{ visibleColumns.map( ( col ) => renderCell( col, row ) ) }
			</div>
		);
	}
);

/**
 * The Request Log dashboard: completed requests in the shared viewer chrome.
 *
 * @param {Object} props              Component props.
 * @param {number} [props.maxEntries] Rows the view ring holds; 500 unless the
 *                                    page names its own.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function RequestStream( { maxEntries = 500 } ) {
	// Mount the graph; it returns the controls and the browse model.
	const { setPaused, clear, step, browse, setFilter } = useRequestLogGraph( {
		maxEntries,
	} );

	// The low-frequency model: the pause button and the reconnect banner.
	const view = useNodeState( VIEW_NODE, 'view' ) ?? EMPTY_VIEW;
	const { paused: isPaused, connectionError } = view;

	const [ showColumnPicker, setShowColumnPicker ] = useState( false );
	const { visibleColumns, toggleColumn, isVisible, gridTemplate } =
		useColumnPicker( {
			columns: COLUMNS,
			storageKey: COLUMNS_STORAGE_KEY,
			defaultVisible: DEFAULT_COLUMNS,
			aliases: COLUMN_ALIASES,
		} );

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

	// Read the live node per frame, so a graph rebuild is picked up.
	const getViewNode = useCallback( () => Core.node( VIEW_NODE ), [] );

	const listHeader = logListHeader( {
		className: 'event-logger-request-stream-columns',
		columns: COLUMNS,
		order: visibleColumns,
	} );

	const columnPicker = showColumnPicker && (
		<ColumnPicker
			columns={ COLUMNS }
			isVisible={ isVisible }
			onToggle={ toggleColumn }
		/>
	);

	return (
		<LogStreamViewer
			className="event-logger-request-stream"
			ariaLabel={ __( 'Request log', 'newspack-event-logger-nodes' ) }
			title={ __( 'Request Log', 'newspack-event-logger-nodes' ) }
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
