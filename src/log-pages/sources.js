/**
 * The log pages' page state, their sources and the blocks their layouts are
 * composed of, for both the pages' bundle and its layout editor, which each
 * import this module, so each bundle declares what it reads.
 *
 * Three stream sources answer the pages, each a glob tailed live and drawn by
 * the shared log-stream block, its columns declared here as the `rows`
 * fields `logColumns()` builds from the shared cell vocabulary:
 * `eln/requests` over `completed.*` and `eln/errors` over `errors.*`, each
 * browsed partition by partition, and `eln/gyroscope` over `gyroscope.*`,
 * whose view serves the snapshot it takes at the cadence page state's
 * `refresh` holds, in milliseconds, which a first visit reads from the
 * choice the browser kept.
 */

import { __, _n } from '@wordpress/i18n';
import { SUBSTRATE_VIEWS } from '@newspack-nodes/shared/blocks';
import { definePageState } from '@newspack-nodes/shared/composition/page/pageState';
import { registerSource } from '@newspack-nodes/shared/sources';
import { readStorage } from '@newspack-nodes/shared/utils/storage';
import {
	formatDuration,
	getStateColor,
	getTextColor,
} from '@newspack-nodes/shared/utils/formatUtils';
import {
	Cell,
	countLabel,
	durationCell,
	ipCell,
	logColumns,
	rateLabel,
	ridCell,
	statusCell,
	timeCell,
	uaCell,
	urlCell,
} from '../log-table/logTable';
import { urlHash } from '../log-table/urlIdentity';
import { GROUPS, STREAM_GLOBS } from '../overview/constants';
import { views as requestViews } from './nodes/request-log-view-node';
import { views as errorViews } from './nodes/perf-errors-view-node';
import { views as gyroscopeViews } from './nodes/gyroscope-view-node';

/**
 * The Gyroscope's cadences, each a number of SECONDS, which the picker and
 * the 0–9 keys choose from and the kept choice is read back against.
 */
export const INFLIGHT_REFRESH_OPTIONS = [
	{ value: 0.1, label: '100ms' },
	{ value: 0.5, label: '500ms' },
	{ value: 1, label: '1s' },
	{ value: 2, label: '2s' },
	{ value: 3, label: '3s' },
	{ value: 5, label: '5s' },
	{ value: 10, label: '10s' },
];

/** The localStorage key the Gyroscope's cadence outlives the page under. */
export const REFRESH_KEY = 'event-logger-inflight-refresh';

/** The Gyroscope's cadence on a first visit, in seconds. */
const DEFAULT_REFRESH_S = 2;

definePageState( {
	refresh: {
		default:
			1000 *
			( INFLIGHT_REFRESH_OPTIONS.find(
				( option ) =>
					String( option.value ) === readStorage( REFRESH_KEY )
			)?.value ?? DEFAULT_REFRESH_S ),
	},
} );

/** A request rate, to one decimal place. */
const requestRate = rateLabel(
	// translators: %s: requests-per-second rate, formatted to one decimal place.
	__( '%s req/s', 'newspack-event-logger-nodes' )
);

/**
 * The Request Log's count: requests shown over requests held while the
 * filter hides some, the plain total otherwise.
 *
 * @param {{total: number, visible: number}} stats Ring stats from `LogRowList`.
 * @return {string} The label.
 */
const requestCount = ( stats ) =>
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

registerSource( 'eln/requests', {
	label: __( 'Request log', 'newspack-event-logger-nodes' ),
	kind: 'stream',
	group: GROUPS.requestLog,
	subscribe: () => STREAM_GLOBS.requestLog,
	viewClass: requestViews.RequestLogView,
	mode: 'follow',
	browse: true,
	count: requestCount,
	rate: requestRate,
	placeholder: __( 'Filter by URL…', 'newspack-event-logger-nodes' ),
	columnsKey: 'event-logger-stream-columns',
	fields: logColumns( {
		time: {
			tooltip: __(
				'Request completion time',
				'newspack-event-logger-nodes'
			),
			cell: ( row ) => timeCell( row.timestamp ),
		},
		rid: { cell: ( row ) => ridCell( row.rid ) },
		url: {
			cell: ( row ) => urlCell( row.method, row.url, row.urlHash ),
		},
		status_code: { cell: ( row ) => statusCell( row.status_code ) },
		remote_addr: { cell: ( row ) => ipCell( row.remote_addr ) },
		user_agent: { cell: ( row ) => uaCell( row.user_agent ) },
		duration: {
			label: __( 'Duration', 'newspack-event-logger-nodes' ),
			tooltip: __( 'Request duration', 'newspack-event-logger-nodes' ),
			width: '70px',
			cell: ( row ) => durationCell( row.duration_ms ),
		},
	} ),
} );

/** The keyword suffixes accented as errors: PSR-3's `error` and above. */
const ERROR_SUFFIXES = [ '(error)', '(critical)', '(alert)', '(emergency)' ];

/**
 * A keyword's severity.
 *
 * `Request_Builder_Node` routes `error`, `warning`, `stderr` and the
 * `(error)`, `(warning)`, `(critical)`, `(alert)` and `(emergency)` suffixes
 * into `errors.*`, and the bare `alert` into `alerts.p0`, which this page
 * does not tail. The three suffixes above `(error)` accent as it.
 *
 * @param {string} keyword Log keyword.
 * @return {string} The cell's `data-keyword`, which the shared sheet inks.
 */
const keywordKind = ( keyword ) => {
	if (
		keyword === 'error' ||
		ERROR_SUFFIXES.some( ( suffix ) => keyword.endsWith( suffix ) )
	) {
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
 * An entry's keyword, inked by its severity, and a fleet alert chipped as the
 * shared error badge.
 *
 * @param {{k: string}} row The entry.
 * @return {import('react').ReactElement} The cell.
 */
const keywordCell = ( row ) => {
	const kind = keywordKind( row.k );
	return (
		<Cell mod="entry-keyword" data-keyword={ kind }>
			{ 'alert' === kind ? (
				<span className="newspack-nodes-status-badge is-error">
					{ row.k }
				</span>
			) : (
				row.k
			) }
		</Cell>
	);
};

/**
 * The Error Log's count: entries shown over entries held while the filter
 * hides some, the plain total otherwise.
 *
 * @param {{total: number, visible: number}} stats Ring stats from `LogRowList`.
 * @return {string} The label.
 */
const entryCount = ( stats ) =>
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

registerSource( 'eln/errors', {
	label: __( 'Error log', 'newspack-event-logger-nodes' ),
	kind: 'stream',
	group: GROUPS.errorLog,
	subscribe: () => STREAM_GLOBS.errorLog,
	viewClass: errorViews.PerfErrorsView,
	mode: 'follow',
	browse: true,
	count: entryCount,
	rate: rateLabel(
		// translators: %s: entries-per-second rate, formatted to one decimal place.
		__( '%s entries/s', 'newspack-event-logger-nodes' )
	),
	placeholder: __(
		'Filter by URL, keyword, message, or request ID…',
		'newspack-event-logger-nodes'
	),
	fields: logColumns( {
		time: {
			tooltip: __( 'Error timestamp', 'newspack-event-logger-nodes' ),
			cell: ( row ) => timeCell( row.ts ),
		},
		rid: { cell: ( row ) => ridCell( row.rid ) },
		url: {
			width: 'minmax(0, 2fr)',
			cell: ( row ) => urlCell( row.method, row.url, row.urlHash ),
		},
		keyword: {
			label: __( 'Keyword', 'newspack-event-logger-nodes' ),
			tooltip: __(
				'Error/warning keyword',
				'newspack-event-logger-nodes'
			),
			width: '240px',
			cell: keywordCell,
		},
		message: {
			label: __( 'Message', 'newspack-event-logger-nodes' ),
			tooltip: __( 'Error message', 'newspack-event-logger-nodes' ),
			width: 'minmax(0, 3fr)',
			cell: ( row ) => (
				<Cell mod="entry-message" title={ row.m }>
					{ row.m }
				</Cell>
			),
		},
	} ),
} );

/**
 * Inline style for a colored badge, ink and all. Hook-category colors are
 * operator-editable and many are pale, so the foreground follows the chip.
 *
 * @param {string} background Background hex color.
 * @return {Object} Style object for the badge span.
 */
export const badgeStyle = ( background ) => ( {
	backgroundColor: background,
	color: getTextColor( background ),
} );

/**
 * How far behind real time a row is, in milliseconds: `last_log_ts` is epoch
 * SECONDS, and the floor at zero keeps a browser clock running behind the
 * server's from reading as a negative age.
 *
 * @param {Object} req One in-flight row.
 * @return {number} Milliseconds since its last log line, 0 when it has none.
 */
const ageMs = ( req ) =>
	req.last_log_ts ? Math.max( 0, Date.now() - req.last_log_ts * 1000 ) : 0;

/**
 * A delay cell warning past `warn`, for Age and Lag, which say nothing until
 * they are large.
 *
 * @param {number} ms   The delay, in milliseconds.
 * @param {number} warn Threshold above which the cell warns.
 * @return {import('react').ReactElement} The cell.
 */
const delayCell = ( ms, warn ) => (
	<Cell
		mod={ `entry-duration newspack-nodes-status${
			ms > warn ? ' is-warning' : ''
		}` }
	>
		{ formatDuration( ms ) }
	</Cell>
);

/**
 * A request's state badge: `include template` reads `template`, because the
 * badge clips at 90px, while its color and its title resolve from the whole
 * state.
 *
 * @param {Object} req One in-flight row.
 * @return {import('react').ReactElement} The cell.
 */
const stateCell = ( req ) => (
	<Cell>
		<span
			className="event-logger-state-badge newspack-nodes-badge"
			style={ badgeStyle( getStateColor( req.state ) ) }
			title={ req.state }
		>
			{ req.state === 'include template' ? 'template' : req.state }
		</span>
	</Cell>
);

registerSource( 'eln/gyroscope', {
	label: __( 'In-flight requests', 'newspack-event-logger-nodes' ),
	kind: 'stream',
	subscribe: () => STREAM_GLOBS.gyroscope,
	viewClass: gyroscopeViews.GyroscopeView,
	mode: 'follow',
	// Rows that predate a connection gap are stale.
	clearOnOpen: true,
	cadence: 'refresh',
	count: ( { total } ) =>
		countLabel(
			{ total },
			// translators: %d: number of in-flight requests.
			_n(
				'%d request',
				'%d requests',
				total,
				'newspack-event-logger-nodes'
			)
		),
	rate: requestRate,
	empty: __( 'No active requests.', 'newspack-event-logger-nodes' ),
	columnsKey: 'event-logger-columns',
	fields: logColumns( {
		rid: { cell: ( req ) => ridCell( req.rid ) },
		url: {
			cell: ( req ) => urlCell( req.method, req.url, urlHash( req.url ) ),
		},
		status_code: { cell: ( req ) => statusCell( req.status_code ) },
		state: {
			label: __( 'State', 'newspack-event-logger-nodes' ),
			tooltip: __(
				'What the request is currently doing',
				'newspack-event-logger-nodes'
			),
			width: '160px',
			cell: stateCell,
		},
		what: {
			label: __( 'What', 'newspack-event-logger-nodes' ),
			tooltip: __(
				'Details: query text, template name, hook name, etc.',
				'newspack-event-logger-nodes'
			),
			width: '400px',
			cell: ( req ) => (
				<Cell mod="entry-url" title={ req.what }>
					{ req.what }
				</Cell>
			),
		},
		remote_addr: { cell: ( req ) => ipCell( req.remote_addr ) },
		user_agent: { cell: ( req ) => uaCell( req.user_agent ) },
		est: {
			label: __( 'Est', 'newspack-event-logger-nodes' ),
			tooltip: __(
				'Estimated request duration (accounts for display delay)',
				'newspack-event-logger-nodes'
			),
			width: '70px',
			cell: ( req ) => durationCell( req.est_ms || req.time_ms || 0 ),
		},
		time: {
			tooltip: __(
				'Request duration from server logs only (ignores display delay)',
				'newspack-event-logger-nodes'
			),
			width: '50px',
			cell: ( req ) => durationCell( req.time_ms ),
		},
		age: {
			label: __( 'Age', 'newspack-event-logger-nodes' ),
			tooltip: __(
				'Display delay - how far behind real-time this view is',
				'newspack-event-logger-nodes'
			),
			width: '50px',
			cell: ( req ) => delayCell( ageMs( req ), 5000 ),
		},
		lag: {
			label: __( 'Lag', 'newspack-event-logger-nodes' ),
			tooltip: __(
				'Server processing delay - high values mean the log processor is backed up',
				'newspack-event-logger-nodes'
			),
			width: '50px',
			cell: ( req ) => delayCell( req.lag_ms || 0, 1000 ),
		},
	} ),
} );

/**
 * Block name => its View and inspector Controls, for every block a log
 * page's layout may hold: the substrate's shared blocks.
 *
 * @type {Object<string,import('@newspack-nodes/shared/composition/CompositionView').CompositionBlockView>}
 */
export const BLOCK_VIEWS = SUBSTRATE_VIEWS;
