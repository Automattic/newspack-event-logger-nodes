/**
 * logTable — the vocabulary the Gyroscope, Request Log and Error Log streams
 * share: the columns more than one declares, the cells they draw, and the
 * toolbar's count and rate labels. Each stream source declares its columns
 * as the `rows` fields the log-stream block draws, through `logColumns()`,
 * spelling only its OWN columns, cells and nouns.
 *
 * The cell helpers take explicit values rather than a row: the three streams
 * spell the same quantity differently (`est_ms`/`time_ms` vs `duration_ms`,
 * `ts` vs `timestamp`), and the URL hash is computed client-side on the
 * Gyroscope but stamped by the view node on the other two.
 */

import { __, sprintf } from '@wordpress/i18n';

import {
	formatDuration,
	formatTime,
	getDurationClass,
} from '@newspack-nodes/shared/utils/formatUtils';
import { requestedUrl } from './urlIdentity';

/**
 * The columns more than one stream declares. A stream merges its own
 * `label`, `tooltip`, `width` or `cell` over these.
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
		width: '320px',
	},
};

/**
 * A stream source's columns, as the `rows` fields the log-stream block
 * draws: each entry is the shared declaration with the stream's own merged
 * over it, so a stream spells out only what differs and its own columns in
 * full. Declaration order carries through, because it is the table order the
 * block's column picker reads back.
 *
 * @param {Object} spec Column key → the stream's `{ label, tooltip, width, cell }`.
 * @return {Object} The fields.
 */
export const logColumns = ( spec ) =>
	Object.fromEntries(
		Object.entries( spec ).map( ( [ col, own ] ) => [
			col,
			{ kind: 'rows', ...SHARED_COLUMNS[ col ], ...own },
		] )
	);

/**
 * One table cell — the base class and `role` are the table's a11y contract,
 * spelled once. Anything beyond `mod` lands on the span, `title` most of all.
 *
 * @param {{ mod?: string, children?: * } & Record<string,*>} props Props.
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
 * @param {string} rid Request id.
 * @return {import('react').ReactElement} The cell.
 */
export const ridCell = ( rid ) => (
	<Cell>
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
 * Method and URL, the URL linked to its stats by the hash it is given and
 * shown as the request asked for it (`requestedUrl()`).
 *
 * Draws nothing when the entry carried no URL — an error can be logged
 * outside a request.
 *
 * @param {string}  method  HTTP method.
 * @param {?string} url     Request URL.
 * @param {?string} urlHash FNV-1a hash of the URL, for the deep link.
 * @return {import('react').ReactElement} The cell.
 */
export const urlCell = ( method, url, urlHash ) => (
	<Cell mod="entry-url" title={ requestedUrl( url ) }>
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
					{ requestedUrl( url ) }
				</a>
			</>
		) }
	</Cell>
);

/**
 * HTTP status code; `data-status` carries it for the scss accent.
 *
 * @param {?number} status HTTP status code.
 * @return {import('react').ReactElement} The cell.
 */
export const statusCell = ( status ) => (
	<Cell mod="entry-status" data-status={ status }>
		{ status }
	</Cell>
);

/**
 * Client IP address, or a dash when the entry carried none.
 *
 * @param {?string} remoteAddr Client IP.
 * @return {import('react').ReactElement} The cell.
 */
export const ipCell = ( remoteAddr ) => (
	<Cell mod="entry-ip">{ remoteAddr || '-' }</Cell>
);

/**
 * User agent, truncated by the column with the full text as its tooltip.
 * Draws a dash when the entry carried none.
 *
 * @param {?string} userAgent Browser/client identifier.
 * @return {import('react').ReactElement} The cell.
 */
export const uaCell = ( userAgent ) => (
	<Cell mod="entry-ua" title={ userAgent }>
		{ userAgent || '-' }
	</Cell>
);

/**
 * A duration, graded fast/slow/critical by the shared thresholds.
 *
 * @param {?number} ms Duration in milliseconds.
 * @return {import('react').ReactElement} The cell.
 */
export const durationCell = ( ms ) => (
	<Cell mod={ `entry-duration entry-duration--${ getDurationClass( ms ) }` }>
		{ formatDuration( ms ) }
	</Cell>
);

/**
 * A timestamp as a local wall clock.
 *
 * @param {?number} ts Unix seconds.
 * @return {import('react').ReactElement} The cell.
 */
export const timeCell = ( ts ) => (
	<Cell mod="entry-time">{ formatTime( ts ) }</Cell>
);

/**
 * `sprintf` under a looser type: it reads its argument types off a LITERAL
 * format, and every format reaching it here is already translated.
 *
 * @type {( format: string, ...args: * ) => string}
 */
const interpolate = sprintf;

/**
 * The toolbar's count label: shown over held while a filter hides rows,
 * otherwise the plain total. Both forms arrive already translated, since
 * `_n()` extracts only literals. A stream that filters nothing — the
 * Gyroscope — passes its total alone and names no split form.
 *
 * @param {Object}  stats   Ring stats: `{ total }`, plus `visible` when the
 *                          stream filters.
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
 * @return {( lps: number ) => string} The label builder.
 */
export const rateLabel = ( unit ) => ( lps ) =>
	interpolate( unit, lps.toFixed( 1 ) );
