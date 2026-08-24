/**
 * logTable — the vocabulary the In-Flight, Request Log and Error Log tables
 * share: the columns more than one declares, the cells they draw, the header
 * capping the table, and the toolbar's count and rate labels. Each
 * dashboard declares only its OWN columns, cases and nouns.
 *
 * The cell helpers take explicit values rather than a row: the three
 * dashboards spell the same quantity differently (`est_ms`/`time_ms` vs
 * `duration_ms`, `ts` vs `timestamp`), and the URL hash is computed
 * client-side on the In-Flight table but stamped by the view node on the other
 * two. The trailing `key` is the caller's — a dashboard mapping a dynamic
 * column list passes the column, the Error Log's fixed JSX siblings pass
 * nothing.
 */

import { __, sprintf } from '@wordpress/i18n';

import LogListHeader from '@newspack-nodes/shared/components/LogListHeader';
import {
	formatDuration,
	formatTime,
	getDurationClass,
} from '@newspack-nodes/shared/utils/formatUtils';
import { gridTemplate } from '@newspack-nodes/shared/hooks/useColumnPicker';

/**
 * The columns more than one dashboard declares, keyed by the log field each
 * draws. A dashboard merges its own `tooltip` and `width` over these.
 *
 * `time` carries no tooltip and no shared meaning beyond its label: the two
 * viewer panes head a wall clock with it, In-Flight a server-side duration.
 */
const SHARED_COLUMNS = {
	time: {
		label: __( 'Time', 'newspack-event-logger-nodes' ),
		width: '100px',
	},
	rid: {
		label: __( 'Request ID', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Unique request identifier - click to view its full trace',
			'newspack-event-logger-nodes'
		),
		width: '240px',
	},
	url: {
		label: __( 'URL', 'newspack-event-logger-nodes' ),
		tooltip: __(
			'Request method and URL - click to view URL stats',
			'newspack-event-logger-nodes'
		),
		width: 'auto',
	},
	status_code: {
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
};

/**
 * Build a dashboard's column map: each entry is the shared declaration with
 * the dashboard's own merged over it, so a dashboard spells out only what
 * differs and its own columns in full. Declaration order carries through,
 * because it is the table order `useColumnPicker` reads back off the result.
 *
 * @param {Object} spec Column key → the dashboard's `{ label, tooltip, width }`.
 * @return {Object} The merged column map.
 */
export const logColumns = ( spec ) =>
	Object.fromEntries(
		Object.entries( spec ).map( ( [ col, own ] ) => [
			col,
			{ ...SHARED_COLUMNS[ col ], ...own },
		] )
	);

/**
 * One table cell — the base class and `role` are the table's a11y contract,
 * spelled once. Anything beyond `mod` lands on the span, `title` most of all.
 *
 * @param {{ mod?: string, children?: * } & Record<string, *>} props Props.
 * @return {import('react').ReactElement} The cell.
 */
export const Cell = ( { mod = '', children, ...rest } ) => (
	<span
		role="cell"
		className={ `newspack-nodes-table__cell ${ mod }`.trimEnd() }
		{ ...rest }
	>
		{ children }
	</span>
);

/**
 * Request id, linked to its trace in the Performance Dashboard.
 *
 * @param {string} rid   Request id.
 * @param {string} [key] React key, when the caller renders from a list.
 * @return {import('react').ReactElement} The cell.
 */
export const ridCell = ( rid, key ) => (
	<Cell key={ key }>
		<a
			className="entry-rid"
			href={ `admin.php?page=event-logger-overview&request=${ encodeURIComponent(
				rid
			) }` }
			title={ __( 'View request trace', 'newspack-event-logger-nodes' ) }
		>
			{ rid }
		</a>
	</Cell>
);

/**
 * Method and URL, the URL linked to its stats by the hash it is given.
 *
 * Draws nothing when the entry carried no URL — an error can be logged
 * outside a request.
 *
 * @param {string}  method  HTTP method.
 * @param {?string} url     Request URL.
 * @param {?string} urlHash FNV-1a hash of the URL, for the deep link.
 * @param {string}  [key]   React key, when the caller renders from a list.
 * @return {import('react').ReactElement} The cell.
 */
export const urlCell = ( method, url, urlHash, key ) => (
	<Cell key={ key } mod="entry-url" title={ url }>
		{ url && (
			<>
				<span className="entry-method">{ method }</span>{ ' ' }
				<a
					href={ `admin.php?page=event-logger-overview&url=${ urlHash }` }
					className="entry-url-link"
					title={ __(
						'View URL stats',
						'newspack-event-logger-nodes'
					) }
				>
					{ url }
				</a>
			</>
		) }
	</Cell>
);

/**
 * HTTP status code; `data-status` carries it for the scss accent.
 *
 * @param {?number} status HTTP status code.
 * @param {string}  [key]  React key, when the caller renders from a list.
 * @return {import('react').ReactElement} The cell.
 */
export const statusCell = ( status, key ) => (
	<Cell key={ key } mod="entry-status" data-status={ status }>
		{ status }
	</Cell>
);

/**
 * Client IP address.
 *
 * @param {?string} remoteAddr Client IP.
 * @param {string}  [key]      React key, when the caller renders from a list.
 * @return {import('react').ReactElement} The cell.
 */
export const ipCell = ( remoteAddr, key ) => (
	<Cell key={ key } mod="entry-ip">
		{ remoteAddr || '-' }
	</Cell>
);

/**
 * User agent, truncated by the column with the full text as its tooltip.
 *
 * @param {?string} userAgent Browser/client identifier.
 * @param {string}  [key]     React key, when the caller renders from a list.
 * @return {import('react').ReactElement} The cell.
 */
export const uaCell = ( userAgent, key ) => (
	<Cell key={ key } mod="entry-ua" title={ userAgent }>
		{ userAgent || '-' }
	</Cell>
);

/**
 * A duration, graded fast/slow/critical by the shared thresholds.
 *
 * @param {?number} ms    Duration in milliseconds.
 * @param {string}  [key] React key, when the caller renders from a list.
 * @return {import('react').ReactElement} The cell.
 */
export const durationCell = ( ms, key ) => (
	<Cell
		key={ key }
		mod={ `entry-duration entry-duration--${ getDurationClass( ms ) }` }
	>
		{ formatDuration( ms ) }
	</Cell>
);

/**
 * A timestamp as a local wall clock.
 *
 * @param {?number} ts    Unix seconds.
 * @param {string}  [key] React key, when the caller renders from a list.
 * @return {import('react').ReactElement} The cell.
 */
export const timeCell = ( ts, key ) => (
	<Cell key={ key } mod="entry-time">
		{ formatTime( ts ) }
	</Cell>
);

/**
 * A row's cell renderer, built from the dashboard's own per-column cases: each
 * case is called with the row arguments and the column as its React key. A
 * declared column with no case draws a placeholder, since a cell that draws
 * nothing shifts every column after it a slot left.
 *
 * @param {Object} cases Column key → `( ...row, col ) => element`.
 * @return {Function} `( col, ...row ) => element`.
 */
export const cellRenderer =
	( cases ) =>
	( col, ...row ) =>
		cases[ col ] ? cases[ col ]( ...row, col ) : <Cell key={ col }>-</Cell>;

/**
 * The header above a log table: the wrapper publishing the grid template as
 * the custom property its scss applies, over the shared `LogListHeader`.
 *
 * @param {Object}   opts           Options.
 * @param {string}   opts.className Wrapper class the dashboard's scss reads.
 * @param {Object}   opts.columns   Column map from `logColumns()`.
 * @param {string[]} opts.order     Column keys, in display order.
 * @return {import('react').ReactElement} The header.
 */
export const logListHeader = ( { className, columns, order } ) => (
	<div
		className={ className }
		style={ { '--stream-grid-template': gridTemplate( columns, order ) } }
	>
		<LogListHeader
			columns={ order.map( ( col ) => ( {
				key: col,
				...columns[ col ],
			} ) ) }
		/>
	</div>
);

// sprintf types its args off a LITERAL format; ours is already translated.
/** @type {( format: string, ...args: * ) => string} */
const interpolate = sprintf;

/**
 * The toolbar's count label: shown over held while a filter hides rows,
 * otherwise the plain total. Both forms arrive already translated, since
 * `_n()` extracts only literals. A table that filters nothing — In-Flight —
 * passes its total alone and names no split form.
 *
 * @param {Object}  stats   Ring stats: `{ total }`, plus `visible` when the
 *                          dashboard filters.
 * @param {string}  plain   `sprintf` format taking the total alone.
 * @param {?string} [split] `sprintf` format taking rows shown, then rows held.
 * @return {string} The label.
 */
export const countLabel = ( stats, plain, split ) =>
	split && stats.visible !== stats.total
		? interpolate( split, stats.visible, stats.total )
		: interpolate( plain, stats.total );

/**
 * The toolbar's rate label, to one decimal place.
 *
 * @param {string} unit Already-translated `sprintf` format taking the rate.
 * @return {Function} `( lps ) => string`.
 */
export const rateLabel = ( unit ) => ( lps ) =>
	interpolate( unit, lps.toFixed( 1 ) );
