/**
 * errorStatus — the terminal markers `Request_Builder_Node` stamps on a
 * request record, and how each one reads.
 *
 * A deliberate duplicate of `Request_Builder_Node::ERROR_STATUSES`, which the
 * node writes and every dashboard reads: the status cell, the "Errors Only"
 * filter, the detail badge and the overlay's status note. The two sides must
 * move together, because a code the node stamps and this table omits is
 * writable but unreadable — the row shows its HTTP status instead, the filter
 * drops it, and the badge renders nothing. `errorStatus.test.js` parses the PHP
 * constant and fails when the two lists disagree.
 *
 * `label` serves the badge, the note and the row tooltip alike, so it is short
 * enough to sit in a badge and explicit enough to stand alone as a title.
 */

import { __ } from '@wordpress/i18n';

/**
 * Every non-nominal terminal marker, keyed by the one-character stamped code.
 *
 * `tone` is the shared status modifier a view appends to
 * `newspack-nodes-status`. Only a fatal is an error: a timeout, an abort and a
 * hole in the log all mean the trace is partial, not that the request failed.
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
