/**
 * The Request Log dashboard's view node.
 *
 * The `completed.*` partitions carry one summary per finished request, written
 * by `Request_Builder_Node` and delivered over SSE. This file maps those raw
 * envelopes to the rows `RequestStream.js` renders, and does nothing else: the
 * stream plumbing lives in `useGlobStreamGraph`, the browse controls in
 * `useGlobBrowse`, and every generic log-stream behavior in the shared
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

/** Rows the ring holds until a caller assigns `maxLines` of its own. */
const DEFAULT_MAX_LINES = 1000;

/**
 * `requestlog:view` — owns the Request Log view model.
 *
 * A `LogStreamViewNode` subclass: the ring, the paused belt and step budget,
 * the decaying lps, seek tracking, and the shared control verbs — `select`
 * included — all live in the shared base. This class adds the Request Log's
 * specifics: `shapeRow()`, which validates and enriches a raw
 * completed-request envelope (KEY=rid, VALUE=the summary from `_sse`) into a
 * row, and the ingest filter over the URL, the one field the placeholder names.
 *
 * @testonly The class is exported for its suite; production reaches it
 *           through the `views` map registered at the foot of this file.
 */
export class RequestLogViewNode extends LogStreamViewNode {
	/**
	 * Size the ring and publish the initial view model, so a React subscriber
	 * mounting before the first row reads a defined model rather than
	 * undefined. Seek tracking starts DISARMED because the first subscription
	 * is the `completed.*` glob: breadcrumbs from several partitions interleave
	 * and mean nothing until a `select` picks one dir.
	 *
	 * @param {number} [maxLines] Ring cap; DEFAULT_MAX_LINES when omitted.
	 *                            Only tests pass it: `makeNode` constructs
	 *                            every node with no arguments, so the
	 *                            dashboard graph assigns `maxLines` from its
	 *                            own `maxEntries` after construction.
	 */
	constructor( maxLines ) {
		super( maxLines || DEFAULT_MAX_LINES );
		this.seekActive = false;
		this._publish();
	}

	/**
	 * Ingest gate: the toolbar promises "Filter by URL…", so only the URL is
	 * searched — never the base's `content`, which would widen the scope the
	 * placeholder advertises.
	 *
	 * @param {Object} fields      Shaped row fields.
	 * @param {string} filterLower The active filter, already lowercased.
	 * @return {boolean} True to admit the row.
	 */
	matchesFilter( fields, filterLower ) {
		return String( fields.url ?? '' )
			.toLowerCase()
			.includes( filterLower );
	}

	/**
	 * Shape one completed-request envelope into a table row, or decline it.
	 *
	 * VALUE is the request summary `Request_Builder_Node` wrote to
	 * `completed.p{N}` and `_sse` delivered; the request id rides the message
	 * KEY, never VALUE. Anything that is not a plain object carrying a `url` is
	 * dropped, and a declined envelope leaves the seek breadcrumb untouched, so
	 * a record this view never held cannot move the rail highlight.
	 *
	 * Every field is carried whole. `Request_Builder_Node` fits the url and the
	 * user agent under PIPE_BUF through `Line_Fitter::fit()` before it emits, so
	 * a character cap here would be a second, invented bound on a string the
	 * producer already fits — and `urlHash` keys the FULL url, matching
	 * the server's `Log_Manager::url_hash()`, so even a long URL deep-links to a
	 * detail view the Overview has stored. `raw` holds the summary's whole JSON
	 * and `struct` marks it parseable, which is what makes debug mode
	 * pretty-print the record rather than print it as one line.
	 *
	 * @param {Array} message The 7-field positional message; VALUE is the
	 *                        request summary, KEY the rid, ID the
	 *                        `segment:offset:length` breadcrumb.
	 * @return {?Object} The row `LogRowList` renders, or null to drop it.
	 */
	shapeRow( message ) {
		const req = message[ VALUE ];
		if ( ! req || 'object' !== typeof req || Array.isArray( req ) ) {
			return null;
		}
		if ( ! req.url ) {
			return null;
		}
		const rid = 'string' === typeof message[ KEY ] ? message[ KEY ] : '';
		const url = req.url;
		const method = req.method || 'GET';
		const statusCode = req.status_code || 0;
		return {
			timestamp: req.end_time || 0,
			rid,
			method,
			url,
			urlHash: fnv1a( req.url ),
			duration_ms: req.duration_ms || 0,
			status_code: statusCode,
			remote_addr: req.remote_addr || '',
			user_agent: req.user_agent || '',
			msgId: 'string' === typeof message[ ID ] ? message[ ID ] : '',
			key: rid,
			raw: JSON.stringify( req ),
			struct: true,
			content: `${ method } ${ url } ${ statusCode } ${ rid }`,
		};
	}

	/**
	 * The base's Hidden, target-less schema under this view's description — a
	 * terminal receiver the dashboard graph mounts by class, not a palette
	 * entry, and one that settles replies rather than forwarding them.
	 *
	 * @return {Object} Schema the console palette and `help` render.
	 */
	static nodeSchema() {
		return {
			...super.nodeSchema(),
			description: 'Owns the Request Log view model.',
		};
	}
}

/**
 * The view class under the name TSL and the console palette resolve, exported
 * so `useRequestLogGraph` hands `makeNode` the class itself: that name table is
 * a per-bundle static, and a hub tab building its graph through another
 * bundle's interpreter cannot resolve a name this bundle registered (ADR-16).
 */
export const views = CommandInterpreterNode.registerNodeClasses( {
	RequestLogView: RequestLogViewNode,
} );
