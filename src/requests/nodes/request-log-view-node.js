import { KEY, VALUE, ID } from '@newspack-nodes/runtime';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import { LogStreamViewNode } from '@newspack-nodes/shared/nodes/log-stream-view-node';

const DEFAULT_MAX_LINES = 1000;
// Defensive bounds for raw envelope VALUEs (view owns the row mapping).
const MAX_URL_LENGTH = 2000;
const MAX_UA_LENGTH = 500;
// Debug-mode raw retention per row (pretty-printable); ~PIPE_BUF x2.
const MAX_RAW_LENGTH = 8192;

// Clip a string at `max`, appending an ellipsis. Non-strings pass through.
const clip = ( s, max ) => {
	if ( 'string' !== typeof s ) {
		return s;
	}
	return s.length > max ? s.substring( 0, max ) + '...' : s;
};

/**
 * URL hash for deep-linking to URL detail. Hashes the FULL url — matching PHP
 * `Log_Manager::url_hash`. The real query is already stripped upstream,
 * so the only `?` left is the intentional `?worker_type` marker on nodes/ELN
 * URLs (e.g. `/jobs/x?supervisor`), which MUST be kept or the hash won't match
 * that URL's row.
 *
 * @param {string} url URL to hash.
 * @return {string} 12-character FNV-1a hash.
 */
const urlHash = ( url ) => fnv1a( url || '' );

/**
 * `requestlog:view` — owns the Request Log view model.
 *
 * A `LogStreamViewNode` subclass: the ring, paused belt + step budget,
 * decaying lps, seek tracking, reply settling, and the shared control verbs
 * all live in the shared base. This class adds the Request Log's specifics:
 * - the `select` control (partition switch: reset the tracker, arm
 *   `seekActive` — breadcrumbs only mean anything within ONE dir; a glob
 *   mixes segments) and its `seekTracking()` gate;
 * - `shapeRow()`: defensively shapes a raw completed-request envelope
 *   (VALUE = the summary from `_sse`, KEY = rid) into a row — drop
 *   missing-url, clip url@2000 + user_agent@500, default-fill, urlHash — plus
 *   the shared debug trio (`msgId`, `key`, `raw`, `struct`) and the
 *   searchable `content` line.
 *
 * @param {number} [maxLines] Ring cap (defaults to DEFAULT_MAX_LINES).
 */
export class RequestLogViewNode extends LogStreamViewNode {
	constructor( maxLines ) {
		super( maxLines || DEFAULT_MAX_LINES );
		this.seekActive = false;
		this._publish();
	}

	_control( value ) {
		if ( 'select' === value.action ) {
			// Partition switch: reset the tracker; arm only for a single dir.
			this.seekActive = !! value.dir;
			this.seek.select();
			this._clear();
		} else {
			super._control( value );
		}
	}

	seekTracking() {
		return this.seekActive;
	}

	// Shape a completed-request envelope into a row; rid rides the message KEY.
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
		const url = clip( req.url, MAX_URL_LENGTH );
		const method = req.method || 'GET';
		const statusCode = req.status_code || 0;
		return {
			timestamp: req.end_time || 0,
			rid,
			method,
			url,
			urlHash: urlHash( url ),
			duration_ms: req.duration_ms || 0,
			status_code: statusCode,
			remote_addr: req.remote_addr || '',
			user_agent: clip( req.user_agent || '', MAX_UA_LENGTH ),
			msgId: 'string' === typeof message[ ID ] ? message[ ID ] : '',
			key: rid,
			raw: clip( JSON.stringify( req ), MAX_RAW_LENGTH ),
			struct: true,
			content: `${ method } ${ url } ${ statusCode } ${ rid }`,
		};
	}

	static nodeSchema() {
		return {
			...super.nodeSchema(),
			description: 'Owns the Request Log view model.',
		};
	}
}
