import { KEY, VALUE, ID } from '@newspack-nodes/runtime';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import { LogStreamViewNode } from '@newspack-nodes/shared/nodes/log-stream-view-node';

const DEFAULT_MAX_LINES = 5000;
// Defensive bounds for raw envelope VALUEs (view owns the row mapping).
const MAX_M_LENGTH = 1000;
const MAX_URL_LENGTH = 2000;
// Debug-mode raw retention per row (pretty-printable); ~PIPE_BUF x2.
const MAX_RAW_LENGTH = 8192;

// Clip a string at `max`, appending an ellipsis. Non-strings become empty.
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
 * decaying lps, seek tracking, reply settling, and the shared control verbs
 * all live in the shared base. This class adds the Error Log's specifics:
 * - the `select` control (partition switch: reset the tracker, arm
 *   `seekActive` — breadcrumbs only mean anything within ONE dir; a glob
 *   mixes segments) and its `seekTracking()` gate;
 * - `shapeRow()`: validates + enriches a raw errors envelope (KEY=rid,
 *   VALUE={ts, k, m, n, method, url}) into a row — drop empty-rid, the
 *   `connected` sentinel, and non-object VALUEs; clip m@1000 + url@2000;
 *   hash the FULL url for the URL detail link — plus the shared debug trio
 *   (`msgId`, `key`, `raw`, `struct`) and the searchable `content` line.
 *
 * @param {number} [maxLines] Ring cap (defaults to DEFAULT_MAX_LINES).
 */
export class PerfErrorsViewNode extends LogStreamViewNode {
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

	// A raw errors envelope (KEY=rid): validate + enrich into a row.
	shapeRow( message ) {
		const rid = message[ KEY ];
		if ( ! rid ) {
			return null;
		}
		// SseInNode streams a `connected` sentinel too; it's not an error.
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

	static nodeSchema() {
		return {
			...super.nodeSchema(),
			description: 'Owns the Error Log view model.',
		};
	}
}
