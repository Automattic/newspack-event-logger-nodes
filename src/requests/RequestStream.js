/**
 * Request Stream Component — real-time scrolling log of completed requests.
 *
 * A THIN wrapper over the shared `LogStreamViewer` chrome (toolbar, filter,
 * counts + rate, pause, Debug, Clear, banner, body split, virtualized
 * `LogRowList`). The `requestlog:*` node graph (mounted by `useRequestLogGraph`)
 * owns all data: `requestlog:link` holds the EventSource and routes envelopes
 * to `requestlog:view` (a `LogStreamViewNode` subclass), whose ring the list
 * reads straight off the node each frame. This component only supplies the
 * differing pieces: the column set + picker, the grid row/header renderers,
 * the URL ingest gate, the toolbar partition picker, and the segment rail.
 *
 * Click any request to view its full trace in the Performance Dashboard.
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

const ROW_HEIGHT = 33;
const VIEW_NODE = 'requestlog:view';
const EMPTY_VIEW = { paused: false, connectionError: false };
const COLUMNS_STORAGE_KEY = 'event-logger-stream-columns';

/**
 * Column definitions for the request log; the rest come from the shared set.
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

// Shipped as `status`; the shared column set spells it `status_code`.
const COLUMN_ALIASES = { status: 'status_code' };

const DEFAULT_COLUMNS = [
	'time',
	'rid',
	'url',
	'status_code',
	'remote_addr',
	'duration',
];

// Count + rate labels for the shared toolbar stats.
const renderRate = rateLabel(
	// translators: %s: requests-per-second rate, formatted to one decimal place.
	__( '%s req/s', 'newspack-event-logger-nodes' )
);

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

// One cell of a `requestlog:view` row, by column.
const renderCell = cellRenderer( {
	time: ( row, col ) => timeCell( row.timestamp, col ),
	duration: ( row, col ) => durationCell( row.duration_ms, col ),
	status_code: ( row, col ) => statusCell( row.status_code, col ),
	url: ( row, col ) => urlCell( row.method, row.url, row.urlHash, col ),
	rid: ( row, col ) => ridCell( row.rid, col ),
	remote_addr: ( row, col ) => ipCell( row.remote_addr, col ),
	user_agent: ( row, col ) => uaCell( row.user_agent, col ),
} );

// JSDoc rides the inner function: on the const, memo() infers props as `{}`.
const StreamRow = memo(
	/**
	 * Memoized row component - only re-renders when the row or columns change.
	 *
	 * @param {Object}   props                Component props.
	 * @param {Object}   props.row            Row from `requestlog:view`: timestamp, rid, method, url, urlHash, status_code, remote_addr, user_agent, duration_ms, plus the base view node's `id` and `isEven`.
	 * @param {string[]} props.visibleColumns Column keys to render, in display order.
	 * @param {string}   props.gridTemplate   `grid-template-columns` value for the row.
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
 * Request Stream Component.
 *
 * @param {Object} props            Component props.
 * @param {number} props.maxEntries Maximum rows to keep in the view ring.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function RequestStream( { maxEntries = 500 } ) {
	// Mount the graph; returns the control callbacks + the browse model.
	const { setPaused, clear, step, browse, setFilter } = useRequestLogGraph( {
		maxEntries,
	} );

	// Low-freq view model (pause button, empty-state label, reconnect banner).
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

	// Re-read the live nodes each frame so a graph reinit is picked up.
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
