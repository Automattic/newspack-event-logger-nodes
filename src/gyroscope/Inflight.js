/**
 * In-Flight Requests — the Gyroscope dashboard's live request table, modeled on
 * Tachikoma's Gyroscope.
 *
 * A THIN view over the `gyroscope:*` node graph `useGyroscopeGraph` mounts. The
 * graph owns the data: `gyroscope:link`, a substrate `RemoteLink`, holds the SSE
 * connection and targets the `gyroscope:stream` Tee, which copies every frame to
 * `gyroscope:view`. That view node dispatches the in-flight and completion
 * envelopes itself and owns the model — the rid-keyed map, the snapshot that
 * reaps and orders it, and the requests-per-second readout. This component only
 * renders.
 *
 * The refresh interval, which the dropdown and the 0-9 keys both set, drives the
 * render cadence. Each tick calls `snapshot( maxRows )` on the view node, which
 * returns completed entries once before dropping them, discards in-flight rows
 * unseen for fifteen minutes, sorts by `est_ms` descending and caps the result;
 * the tick then reads `.rps` off the node. A busy stream therefore never
 * re-renders React per message: only the snapshot arrives, at the operator's
 * cadence.
 *
 * The low-frequency `{ connectionError }` model behind the reconnect banner is
 * read separately, through `useNodeState( VIEW_NODE, 'view' )`.
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { __, _n } from '@wordpress/i18n';

import { Core, useNodeState } from '@newspack-nodes/runtime';
import useRouterTick from '@newspack-nodes/shared/hooks/useRouterTick';
import { useGyroscopeGraph } from './hooks/useGyroscopeGraph';
import { INFLIGHT_REFRESH_OPTIONS } from './constants';
import {
	formatDuration,
	getStateColor,
	getTextColor,
} from '@newspack-nodes/shared/utils/formatUtils';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import ConnectionBanner from '@newspack-nodes/shared/components/ConnectionBanner';
import ColumnPicker from '@newspack-nodes/shared/components/ColumnPicker';
import { useColumnPicker } from '@newspack-nodes/shared/hooks/useColumnPicker';
import { usePersistedChoice } from '@newspack-nodes/shared/hooks/usePersistedState';
import {
	Cell,
	cellRenderer,
	countLabel,
	durationCell,
	ipCell,
	logColumns,
	logListHeader,
	rateLabel,
	ridCell,
	statusCell,
	uaCell,
	urlCell,
} from '../log-table/logTable';
import './styles/inflight.scss';

/**
 * The view node the refresh tick reads the in-flight snapshot and rps off of.
 *
 * `useGyroscopeGraph` mounts it under this name, and nothing else addresses
 * it, so the name is spelled once for the two hooks that need it.
 */
const VIEW_NODE = 'gyroscope:view';

/**
 * Banner state for the render before the graph's mount effect has run.
 *
 * `useNodeState` returns undefined until the node exists, and destructuring
 * that throws, so the first render reads its banner flag from here.
 */
const EMPTY_VIEW = { connectionError: false };

/**
 * Inline style for a colored badge, ink and all.
 *
 * Hook-category colors are operator-editable and many are pale, so the
 * foreground follows the chip rather than assuming white reads on it.
 *
 * @param {string} background Background hex color.
 * @return {Object} Style object for the badge span.
 */
const badgeStyle = ( background ) => ( {
	backgroundColor: background,
	color: getTextColor( background ),
} );

/**
 * The In-Flight table's own columns; the rest come from the shared set.
 *
 * This key set is what the persisted `event-logger-columns` selection is
 * restored against, and the column picker's contents, so a key added here
 * becomes selectable at once and a key removed drops out of a saved selection
 * without resetting it. Only completion records carry `status_code`, so that
 * column stays blank while a request is still running.
 *
 * `time` overrides the tooltip and nothing else: it keeps the shared 'Time'
 * header, but the value under it here is a server-side duration rather than a
 * wall clock.
 */
const COLUMNS = logColumns( {
	rid: {},
	url: {},
	status_code: {},
	state: {
		label: __( 'State', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'What the request is currently doing',
			'newspack-event-logger-nodes'
		),
		width: '100px',
	},
	what: {
		label: __( 'What', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Details: query text, template name, hook name, etc.',
			'newspack-event-logger-nodes'
		),
		width: '200px',
	},
	remote_addr: {},
	user_agent: {},
	est: {
		label: __( 'Est', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Estimated request duration (accounts for display delay)',
			'newspack-event-logger-nodes'
		),
		width: '70px',
	},
	time: {
		tooltip: __(
			'Request duration from server logs only (ignores display delay)',
			'newspack-event-logger-nodes'
		),
		width: '50px',
	},
	age: {
		label: __( 'Age', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Display delay - how far behind real-time this view is',
			'newspack-event-logger-nodes'
		),
		width: '50px',
	},
	lag: {
		label: __( 'Lag', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Server processing delay - high values mean the log processor is backed up',
			'newspack-event-logger-nodes'
		),
		width: '50px',
	},
} );

/**
 * URL hash for deep-linking to URL detail — must match PHP
 * `Log_Manager::url_hash`, which hashes the FULL url. The real query is
 * already stripped upstream, so the only `?` left is the intentional
 * `?worker_type` marker on nodes/ELN URLs (e.g. `/jobs/x?reconcile`); keeping
 * it is what makes those URLs deep-link correctly.
 *
 * @param {string} url URL to hash.
 * @return {string} FNV-1a hash.
 */
const urlHash = ( url ) => fnv1a( url || '' );

/**
 * Columns shown until the operator picks otherwise, and whenever the persisted
 * selection is absent or fails validation.
 */
const DEFAULT_COLUMNS = [ 'rid', 'url', 'status_code', 'state', 'what', 'est' ];

/**
 * How far behind real-time a row is, in milliseconds.
 *
 * `last_log_ts` is epoch SECONDS, hence the thousand. The floor at zero keeps a
 * browser clock running behind the server's from reading as a negative age.
 *
 * @param {Object} req One in-flight row.
 * @return {number} Milliseconds since the row's last log line, 0 when it has none.
 */
const ageMs = ( req ) =>
	req.last_log_ts ? Math.max( 0, Date.now() - req.last_log_ts * 1000 ) : 0;

/**
 * A duration cell carrying a warning class past `warn`, for the two delay
 * columns: an Age or a Lag says nothing until it is large.
 *
 * @param {number} ms   The delay, in milliseconds.
 * @param {number} warn Threshold above which the cell warns.
 * @param {string} col  Column key, which is also the React key.
 * @return {import('react').ReactElement} The cell.
 */
const delayCell = ( ms, warn, col ) => (
	<Cell
		key={ col }
		mod={ `entry-duration newspack-nodes-status${
			ms > warn ? ' is-warning' : ''
		}` }
	>
		{ formatDuration( ms ) }
	</Cell>
);

/**
 * The toolbar's request count.
 *
 * The table filters nothing, so it passes the total alone and names no
 * shown-over-held form. The number counts the rows `snapshot()` returned, which
 * `maxRows` has already capped.
 *
 * @param {number} total Rows in the snapshot.
 * @return {string} The label.
 */
const renderCount = ( total ) =>
	countLabel(
		{ total },
		// translators: %d: number of in-flight requests.
		_n( '%d request', '%d requests', total, 'newspack-event-logger-nodes' )
	);

/**
 * The toolbar's rate readout: completions per second, averaged by the view node
 * over its own ten-second window.
 *
 * @type {(rps: number) => string}
 */
const renderRate = rateLabel(
	// translators: %s: requests-per-second rate, formatted to one decimal place.
	__( '%s req/s', 'newspack-event-logger-nodes' )
);

/**
 * One cell of an in-flight row. State, What, Age and Lag are this table's own;
 * the rest draw through the shared log-table cells.
 *
 * `est` falls back to `time_ms` and then to zero, so a row the producer has not
 * estimated yet shows what its logs do account for rather than a dash. The
 * State badge shortens `include template` to `template` — the badge clips at
 * 90px, so the full name would render as an ellipsis — while its color still
 * resolves from the unshortened state.
 *
 * @type {(col: string, req: Object) => import('react').ReactElement}
 */
const renderCell = cellRenderer( {
	rid: ( req, col ) => ridCell( req.rid, col ),
	time: ( req, col ) => durationCell( req.time_ms, col ),
	est: ( req, col ) => durationCell( req.est_ms || req.time_ms || 0, col ),
	age: ( req, col ) => delayCell( ageMs( req ), 5000, col ),
	lag: ( req, col ) => delayCell( req.lag_ms || 0, 1000, col ),
	state: ( req, col ) => (
		<Cell key={ col }>
			<span
				className="event-logger-state-badge newspack-nodes-badge"
				style={ badgeStyle( getStateColor( req.state ) ) }
			>
				{ req.state === 'include template' ? 'template' : req.state }
			</span>
		</Cell>
	),
	what: ( req, col ) => (
		<Cell key={ col } mod="entry-url" title={ req.what }>
			{ req.what }
		</Cell>
	),
	status_code: ( req, col ) => statusCell( req.status_code, col ),
	url: ( req, col ) =>
		urlCell( req.method, req.url, urlHash( req.url ), col ),
	remote_addr: ( req, col ) => ipCell( req.remote_addr, col ),
	user_agent: ( req, col ) => uaCell( req.user_agent, col ),
} );

/**
 * In-flight requests table.
 *
 * Renders the snapshot the `gyroscope:view` node hands over each refresh tick.
 * Column selection and refresh interval persist in localStorage under
 * `event-logger-columns` and `event-logger-inflight-refresh`; both are
 * revalidated on load, since a stale or hand-edited value would otherwise
 * render a header the picker cannot reach or a cadence the dropdown cannot
 * show. The column selection keeps whatever keys still exist rather than
 * discarding the whole layout over one it does not recognize.
 *
 * The legend names six of the many categories `Hook_Categorizer` ships, colored
 * from the `window.eventLoggerHookCategories` global the plugin prints; a
 * category that global does not carry draws grey.
 *
 * @param {Object} props         Component props.
 * @param {number} props.maxRows Maximum rows to display; the cap `snapshot()` applies.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function Inflight( { maxRows = 20 } ) {
	// Mount the node graph; it owns the data, this only renders the snapshot.
	useGyroscopeGraph();

	// The banner's low-frequency model; rows and rps come off the node.
	const { connectionError } = useNodeState( VIEW_NODE, 'view' ) ?? EMPTY_VIEW;

	const [ requests, setRequests ] = useState( [] );
	const [ refreshInterval, setRefreshInterval ] = usePersistedChoice(
		'event-logger-inflight-refresh',
		INFLIGHT_REFRESH_OPTIONS,
		2
	);
	const { visibleColumns, toggleColumn, isVisible, gridTemplate } =
		useColumnPicker( {
			columns: COLUMNS,
			storageKey: 'event-logger-columns',
			defaultVisible: DEFAULT_COLUMNS,
		} );
	const [ showColumnPicker, setShowColumnPicker ] = useState( false );
	const [ requestsPerSecond, setRequestsPerSecond ] = useState( 0 );

	const totalCount = requests.length;

	// Sample the view node's snapshot() (reaps, sorts, caps) each refresh.
	const renderRequests = useCallback( () => {
		const node = Core.node( VIEW_NODE );
		if ( ! node ) {
			return;
		}
		setRequests( node.snapshot( maxRows ) );
		setRequestsPerSecond( node.rps );
	}, [ maxRows ] );

	// The 0-9 keys set the interval; every value must exist in the dropdown.
	useEffect( () => {
		const keyMap = {
			0: 0.1,
			1: 1,
			2: 2,
			3: 3,
			4: 3,
			5: 5,
			6: 5,
			7: 5,
			8: 10,
			9: 10,
		};
		const handleKeyDown = ( e ) => {
			if (
				e.target.tagName === 'INPUT' ||
				e.target.tagName === 'TEXTAREA'
			) {
				return;
			}
			const interval = keyMap[ e.key ];
			if ( interval !== undefined ) {
				setRefreshInterval( interval );
			}
		};
		window.addEventListener( 'keydown', handleKeyDown );
		return () => window.removeEventListener( 'keydown', handleKeyDown );
	}, [ setRefreshInterval ] );

	// A sub-second cadence takes its own slot; a slower one rides the Router.
	useRouterTick( {
		name: 'gyroscope:display',
		onTick: renderRequests,
		intervalMs: refreshInterval * 1000,
	} );

	return (
		<div
			className="event-logger-inflight"
			role="table"
			aria-label="In-flight requests"
		>
			<div className="event-logger-inflight-header newspack-nodes-inflight-header">
				<h1 className="newspack-dashboard-title">
					{ __(
						'In-Flight Requests',
						'newspack-event-logger-nodes'
					) }
				</h1>
				<div className="event-logger-inflight-legend">
					{ [
						'Lifecycle',
						'Query & Posts',
						'Content Rendering',
						'Theme',
						'Scripts & Styles',
						'REST API',
					].map( ( category ) => (
						<span
							key={ category }
							className="event-logger-state-badge newspack-nodes-badge"
							style={ badgeStyle(
								window.eventLoggerHookCategories?._colors?.[
									category
								] || '#9e9e9e'
							) }
						>
							{ category }
						</span>
					) ) }
					{ [ 'process', 'complete' ].map( ( state ) => (
						<span
							key={ state }
							className="event-logger-state-badge newspack-nodes-badge"
							style={ badgeStyle( getStateColor( state ) ) }
						>
							{ state }
						</span>
					) ) }
				</div>
				<span className="newspack-nodes-toolbar">
					<span className="newspack-nodes-toolbar-stats">
						<span className="newspack-nodes-toolbar-stats__count">
							{ renderCount( totalCount ) }
						</span>
						<span className="newspack-nodes-toolbar-stats__rps">
							{ renderRate( requestsPerSecond ) }
						</span>
					</span>
					<select
						className="newspack-nodes-select"
						value={ refreshInterval }
						onChange={ ( e ) =>
							setRefreshInterval( parseFloat( e.target.value ) )
						}
						aria-label={ __(
							'Refresh interval',
							'newspack-event-logger-nodes'
						) }
						title={ __(
							'Refresh interval (also press 0–9 keys)',
							'newspack-event-logger-nodes'
						) }
					>
						{ INFLIGHT_REFRESH_OPTIONS.map( ( opt ) => (
							<option key={ opt.value } value={ opt.value }>
								{ opt.label }
							</option>
						) ) }
					</select>
					<button
						className={ `button${
							showColumnPicker ? ' is-active' : ''
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
				</span>
			</div>

			<ConnectionBanner
				connectionError={ connectionError }
				message={ __(
					'Connection lost. Reconnecting…',
					'newspack-event-logger-nodes'
				) }
			/>

			{ showColumnPicker && (
				<ColumnPicker
					columns={ COLUMNS }
					isVisible={ isVisible }
					onToggle={ toggleColumn }
					idPrefix="inflight-col"
				/>
			) }

			{ logListHeader( {
				className: 'event-logger-inflight-columns',
				columns: COLUMNS,
				order: visibleColumns,
			} ) }
			<div
				role="rowgroup"
				className="event-logger-request-stream-list newspack-nodes-table"
			>
				<div className="event-logger-request-stream-content">
					{ requests.length === 0 ? (
						<div className="event-logger-request-stream-empty is-quiet newspack-nodes-empty-state">
							{ __(
								'No active requests.',
								'newspack-event-logger-nodes'
							) }
						</div>
					) : (
						requests.map( ( req, index ) => (
							<div
								key={ req.rid }
								role="row"
								className={ `event-logger-request-stream-entry newspack-nodes-table__row ${
									index % 2 === 0 ? 'row-even' : 'row-odd'
								}` }
								style={ { gridTemplateColumns: gridTemplate } }
							>
								{ visibleColumns.map( ( col ) =>
									renderCell( col, req )
								) }
							</div>
						) )
					) }
				</div>
			</div>
		</div>
	);
}
