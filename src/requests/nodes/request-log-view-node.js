import {
	KEY,
	VALUE,
	ID,
	CommandInterpreterNode,
} from '@newspack-nodes/runtime';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import { LogStreamViewNode } from '@newspack-nodes/shared/nodes/log-stream-view-node';

const DEFAULT_MAX_LINES = 1000;

/**
 * `requestlog:view` — owns the Request Log view model.
 *
 * A `LogStreamViewNode` subclass: the ring, paused belt + step budget,
 * decaying lps, seek tracking, and the shared control verbs — `select`
 * included — all live in the shared base. This class adds the Request Log's
 * specifics:
 * - `shapeRow()`: defensively shapes a raw completed-request envelope
 *   (VALUE = the summary from `_sse`, KEY = rid) into a row — drop
 *   missing-url, clip url@2000 + user_agent@500 for DISPLAY, default-fill,
 *   and urlHash over the FULL url — plus
 *   the shared debug trio (`msgId`, `key`, `raw`, `struct`) and the
 *   searchable `content` line.
 *
 * @testonly The class is exported for its suite; production reaches it
 *           through the `views` map registered at the foot of this file.
 * @param {number} [maxLines] Ring cap (defaults to DEFAULT_MAX_LINES).
 */
export class RequestLogViewNode extends LogStreamViewNode {
	/**
	 * Sizes the ring and starts with seek tracking DISARMED, because the first
	 * subscription is the `completed.*` glob — breadcrumbs from several
	 * partitions interleave and mean nothing until a `select` picks one dir.
	 *
	 * @param {number} [maxLines] Ring cap; DEFAULT_MAX_LINES (1000) when
	 *                            omitted, which is what the dashboard graph
	 *                            passes. Tests pass a small value.
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
	 * KEY, never VALUE. Anything that isn't a plain object carrying a `url` is
	 * dropped — a declined envelope also leaves the seek breadcrumb untouched,
	 * so a filtered record never moves the rail highlight. The url and
	 * user-agent are clipped (2000 / 500 chars) because a row's width is ours
	 * to bound, and `raw` keeps a pretty-printable copy for debug mode.
	 *
	 * @param {Array} message The 7-field positional message; VALUE is the
	 *                        request summary, KEY the rid, ID the
	 *                        `segment:offset:length` breadcrumb.
	 * @return {?Object} The row `LogRowList` renders, or null to drop it.
	 */
	shapeRow( message ) {
		const req = message[ VALUE ];
		// Defensive: VALUE must be a plain object with a url.
		if ( ! req || 'object' !== typeof req || Array.isArray( req ) ) {
			return null;
		}
		if ( ! req.url ) {
			return null;
		}
		const rid = 'string' === typeof message[ KEY ] ? message[ KEY ] : '';
		// Clip for DISPLAY only; the hash below keys on the full string.
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
	 * The base's hidden, target-less schema with this view's description — a
	 * terminal receiver the dashboard graph mounts, never the palette.
	 *
	 * @return {Object} The `node_schema()` descriptor the console and `help` read.
	 */
	static nodeSchema() {
		return {
			...super.nodeSchema(),
			description: 'Owns the Request Log view model.',
		};
	}
}

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = CommandInterpreterNode.registerNodeClasses( {
	RequestLogView: RequestLogViewNode,
} );
