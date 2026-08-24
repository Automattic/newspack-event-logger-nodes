/**
 * The Error Log dashboard's view node.
 *
 * The `errors.*` partitions carry one record per error, warning, or stderr
 * line that `Request_Builder_Node` split off the firehose. This file maps
 * those raw envelopes to the rows `ErrorLog.js` renders, and does nothing
 * else: the stream plumbing lives in `useGlobStreamGraph`, the browse controls
 * in `useGlobBrowse`, and every generic log-stream behavior in the shared
 * `LogStreamViewNode` base.
 */

import {
	KEY,
	VALUE,
	ID,
	CommandInterpreterNode,
} from '@newspack-nodes/runtime';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import { LogStreamViewNode } from '@newspack-nodes/shared/nodes/log-stream-view-node';

const DEFAULT_MAX_LINES = 5000;
// Defensive bounds for raw envelope VALUEs (view owns the row mapping).
const MAX_M_LENGTH = 1000;
const MAX_URL_LENGTH = 2000;
// Debug-mode raw retention per row (pretty-printable); ~PIPE_BUF x2.
const MAX_RAW_LENGTH = 8192;

/**
 * Clip a string at `max`, appending an ellipsis.
 *
 * @param {*}      value Candidate string; anything else yields ''.
 * @param {number} max   Longest string kept before the ellipsis.
 * @return {string} The clipped string, or '' for a non-string.
 */
const clip = ( value, max ) => {
	if ( 'string' !== typeof value ) {
		return '';
	}
	return value.length > max ? value.substring( 0, max ) + '...' : value;
};

/**
 * `perferrors:view` — owns the Error Log view model.
 *
 * A `LogStreamViewNode` subclass: the ring, paused belt + step budget,
 * decaying lps, seek tracking, and the shared control verbs — `select`
 * included — all live in the shared base. This class adds the Error Log's
 * specifics: `shapeRow()`, which validates and enriches a raw errors envelope
 * (KEY=rid, VALUE={ts, k, m, n, method, url}) into a row, and the ingest
 * filter over the four fields the placeholder names.
 *
 * @testonly The class is exported for its suite; production reaches it
 *           through the `views` map registered at the foot of this file.
 */
export class PerfErrorsViewNode extends LogStreamViewNode {
	/**
	 * Start disarmed on the glob and publish the initial view model, so a React
	 * subscriber mounting before the first row reads a defined model rather
	 * than undefined. Breadcrumb tracking arms only once `select` names a dir:
	 * across a glob the interleaved segment ids mean nothing.
	 *
	 * @param {number} [maxLines] Ring cap (defaults to DEFAULT_MAX_LINES).
	 */
	constructor( maxLines ) {
		super( maxLines || DEFAULT_MAX_LINES );
		this.seekActive = false;
		// Publish a model up front so the view renders before the first row.
		this._publish();
	}

	/**
	 * Ingest gate over the four fields the placeholder names — request id,
	 * keyword, message, url — and not the base's `content`.
	 *
	 * @param {Object} fields      Shaped row fields.
	 * @param {string} filterLower The active filter, already lowercased.
	 * @return {boolean} True to admit the row.
	 */
	matchesFilter( fields, filterLower ) {
		return [ fields.rid, fields.k, fields.m, fields.url ].some(
			( field ) =>
				'string' === typeof field &&
				field.toLowerCase().includes( filterLower )
		);
	}

	/**
	 * Validate and enrich one raw errors envelope into a row.
	 *
	 * Drops an empty rid, the `connected` sentinel, and any VALUE that is not
	 * a plain object. Clips the message at 1000 characters and the displayed
	 * URL at 2000, but hashes the FULL URL, so `urlHash` keys the same
	 * Overview URL detail the server's `Log_Manager::url_hash()` does. The
	 * `n` line number survives only inside `raw`, the debug-mode JSON of the
	 * whole VALUE clipped at 8192, which rides along with `msgId`, `key`, and
	 * the `struct` flag; `content` is the line the viewer's filter matches.
	 *
	 * @param {Array} message The 7-field envelope (KEY=rid, VALUE=entry).
	 * @return {Object|null} Row fields, or null to drop the envelope.
	 */
	shapeRow( message ) {
		const rid = message[ KEY ];
		if ( ! rid ) {
			return null;
		}
		// SseIn snoops the `connected` handshake off; drop it if it lands.
		if ( 'connected' === rid ) {
			return null;
		}
		const value = message[ VALUE ];
		if ( ! value || 'object' !== typeof value || Array.isArray( value ) ) {
			return null;
		}

		let m = value.m || '';
		if ( 'string' === typeof m && m.length > MAX_M_LENGTH ) {
			m = m.substring( 0, MAX_M_LENGTH ) + '...';
		}
		const k = value.k || '';
		const method = 'string' === typeof value.method ? value.method : '';
		const rawUrl = 'string' === typeof value.url ? value.url : '';
		const url = clip( rawUrl, MAX_URL_LENGTH );
		const row = {
			rid,
			ts: value.ts || 0,
			k,
			m,
			msgId: 'string' === typeof message[ ID ] ? message[ ID ] : '',
			key: 'string' === typeof rid ? rid : '',
			raw: clip( JSON.stringify( value ), MAX_RAW_LENGTH ),
			struct: true,
			content: `${ rid } ${ k } ${ m }${ url ? ' ' + url : '' }`,
		};
		if ( url ) {
			row.method = method;
			row.url = url;
			row.urlHash = fnv1a( rawUrl );
		}
		return row;
	}

	/**
	 * The base's Hidden, target-less schema under this node's description.
	 *
	 * @return {Object} Schema the console palette and `help` render.
	 */
	static nodeSchema() {
		return {
			...super.nodeSchema(),
			description: 'Owns the Error Log view model.',
		};
	}
}

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = CommandInterpreterNode.registerNodeClasses( {
	PerfErrorsView: PerfErrorsViewNode,
} );
