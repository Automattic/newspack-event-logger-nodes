/**
 * errorStatus — the terminal markers `Request_Builder_Node` stamps on a
 * request record, and how each one reads.
 *
 * A deliberate duplicate of `Request_Builder_Node::ERROR_STATUSES`, which the
 * node writes and every dashboard reads: the status cell, the detail badge
 * and the overlay's status note. The two sides must move together, because a
 * code the node stamps and this table omits is writable but unreadable — the
 * row shows its HTTP status instead, and the badge renders nothing.
 * `errorStatus.test.js` parses the PHP constant and fails when the two lists
 * disagree.
 *
 * `label` serves the badge, the note and the row tooltip alike, so it is short
 * enough to sit in a badge and explicit enough to stand alone as a title.
 *
 * `errorSummary()` has a PHP twin too, `Ask_Assembler::error_summary()`, and
 * both read the case list in `tests/fixtures/error-summary.json`, so the URL
 * modal's header and the Ask brief summarize one list the same way.
 */

import { __ } from '@wordpress/i18n';

/**
 * Every non-nominal terminal marker, keyed by the one-character stamped code.
 *
 * `tone` is the shared status modifier a view appends to
 * `newspack-nodes-status`: only a fatal paints as an error, since a timeout,
 * an abort and a hole in the log all mean the trace is partial.
 *
 * @testonly Exported for the parity test that reads the PHP constant; every
 * production consumer goes through `errorStatus()`.
 * @type {Object<string,{label: string, tone: string}>}
 */
export const ERROR_STATUSES = {
	F: {
		label: __( 'Fatal error', 'newspack-event-logger-nodes' ),
		tone: 'is-error',
	},
	T: {
		label: __(
			'Timed out (orphaned request)',
			'newspack-event-logger-nodes'
		),
		tone: 'is-warning',
	},
	A: {
		label: __(
			'Aborted (worker stopped mid-request)',
			'newspack-event-logger-nodes'
		),
		tone: 'is-warning',
	},
	I: {
		label: __(
			'Incomplete (gap in the log)',
			'newspack-event-logger-nodes'
		),
		tone: 'is-warning',
	},
};

/**
 * The label and tone for a stamped code.
 *
 * The lookup goes through `hasOwnProperty` rather than a bare index, because a
 * code naming an `Object.prototype` member — `toString`, `constructor` — would
 * otherwise hand a view a function whose `label` and `tone` are undefined.
 *
 * @param {string|null|undefined} code The record's `error_status`.
 * @return {?{label: string, tone: string}} Its entry, or null for a clean
 *                                          finish (`-` or empty) and for any
 *                                          code this build does not know.
 */
export const errorStatus = ( code ) =>
	Object.prototype.hasOwnProperty.call( ERROR_STATUSES, code ?? '' )
		? ERROR_STATUSES[ code ]
		: null;

/**
 * The extreme and the mean of a list of numbers, or nulls for none.
 *
 * @param {number[]} values The numbers.
 * @return {{max: ?number, avg: ?number}} Its largest and its mean.
 */
function extremes( values ) {
	if ( 0 === values.length ) {
		return { max: null, avg: null };
	}
	return {
		max: Math.max( ...values ),
		avg: values.reduce( ( sum, v ) => sum + v, 0 ) / values.length,
	};
}

/**
 * What a list of `dump_url` request rows holds, error by error: how many
 * timed out and how many fataled, how long the fatals with a measured
 * duration took — `dump_url` nulls one nobody measured (decision 24) — the
 * peak memory over every row, when the first and the last of them started,
 * and the status codes they answered.
 *
 * @param {Array<{timestamp: number, duration_ms: ?number, status_code: number, peak_mb: number, error_status: ?string}>} requests The rows.
 * @return {{listed: number, timeouts: number, fatals: number, fatal_avg_ms: ?number, fatal_max_ms: ?number, avg_peak_mb: ?number, max_peak_mb: ?number, first_at: ?number, last_at: ?number, status_codes: Object<string,number>}} The summary.
 */
export function errorSummary( requests ) {
	const fatals = requests.filter( ( r ) => 'F' === r.error_status );
	const fatalMs = extremes(
		fatals
			.map( ( r ) => r.duration_ms )
			.filter( ( ms ) => 'number' === typeof ms )
	);
	const peak = extremes( requests.map( ( r ) => Number( r.peak_mb ) || 0 ) );
	const starts = requests.map( ( r ) => r.timestamp );
	const statusCodes = /** @type {Object<string,number>} */ ( {} );
	for ( const r of requests ) {
		statusCodes[ r.status_code ] =
			( statusCodes[ r.status_code ] ?? 0 ) + 1;
	}
	return {
		listed: requests.length,
		timeouts: requests.filter( ( r ) => 'T' === r.error_status ).length,
		fatals: fatals.length,
		fatal_avg_ms: fatalMs.avg,
		fatal_max_ms: fatalMs.max,
		avg_peak_mb: peak.avg,
		max_peak_mb: peak.max,
		first_at: starts.length ? Math.min( ...starts ) : null,
		last_at: starts.length ? Math.max( ...starts ) : null,
		status_codes: statusCodes,
	};
}
