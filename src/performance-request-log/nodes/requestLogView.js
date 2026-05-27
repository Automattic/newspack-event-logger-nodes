import { Node, VALUE } from '@newspack-nodes/runtime';
import fnv1a from '../../shared/utils/fnv1a';

const DEFAULT_MAX_ENTRIES = 1000;
const RPS_WINDOW_MS = 10000;

/**
 * Generate URL hash for linking to URL detail (path only, no query string).
 *
 * @param {string} url URL to hash.
 * @return {string} 12-character FNV-1a hash.
 */
const urlHash = ( url ) => {
	const urlPath = url?.split( '?' )[ 0 ] || '';
	return fnv1a( urlPath );
};

/**
 * `requestlog:view` — owns the Request Log view model.
 *
 * Two cadences, deliberately split for performance (mirrors rawLogsView):
 * - HIGH frequency (the request stream): `_appendRow` pushes each enriched entry
 *   onto `this.entries` and recomputes `this.rps` / `this.lastEventTime`, but does
 *   NOT publish. The React view reads these directly off the node each animation
 *   frame so a high-volume stream never re-renders React per request.
 * - LOW frequency (control): only `_control` publishes the small view model via
 *   `setState('view', { paused, connectionError })` — the pause button, the
 *   empty-state label, and the reconnect banner, consumed by
 *   `useNodeState('requestlog:view','view')`.
 *
 * `fill()` accepts two TM_STRUCT shapes:
 * - a row (`VALUE` = the mapped completed-request from `requestlog:transform`):
 *   enriched + appended newest-first to a capped buffer (unless paused), updating
 *   requests/second + last-event time.
 * - a control (`VALUE = { action, … }`): `pause`, `clear`, `connection`.
 *
 * Buffer + RPS + entry-enrichment logic migrated verbatim from `RequestStream.js`.
 */
class RequestLogViewNode extends Node {
	constructor( maxEntries ) {
		super();
		this.maxEntries = maxEntries;
		this.entries = [];
		this.entryCounter = 0;
		this.completedHistory = [];
		this.rps = 0;
		this.lastEventTime = null;
		this.paused = false;
		this.connectionError = false;
		this._publish();
	}

	fill( message ) {
		const value = message[ VALUE ];
		if ( value && value.action ) {
			// Control changes are the LOW-frequency path — publish so the pause
			// button / empty-state label re-render.
			this._control( value );
			this._publish();
		} else if ( value ) {
			// A request row is the HIGH-frequency path — update node.entries /
			// node.rps only; the rAF reads them directly. Publishing here would
			// re-render React per request and defeat the whole point.
			this._appendRow( value );
		}
	}

	// A mapped completed-request row: enrich into the render entry shape, newest-
	// first, capped. Mirrors RequestStream's handleMessage.
	_appendRow( req ) {
		if ( this.paused ) {
			return;
		}
		this.entryCounter += 1;
		this.entries.unshift( {
			// Monotonic per-mount counter — used as the React list key so two
			// entries with the same rid get distinct DOM nodes (a colliding rid
			// from an aggregated spoke, or a worker's reset-then-rebuild in the
			// same second). Without it the virtualized list reuses one node for
			// both and scrolling jumps.
			seq: this.entryCounter,
			rid: req.rid,
			url: req.url,
			urlHash: urlHash( req.url ),
			method: req.method,
			duration_ms: req.duration_ms,
			status_code: req.status_code,
			timestamp: req.end_time,
			remote_addr: req.remote_addr,
			user_agent: req.user_agent,
			isEven: this.entryCounter % 2 === 0,
		} );
		if ( this.entries.length > this.maxEntries ) {
			this.entries.length = this.maxEntries;
		}
		this.lastEventTime = Date.now();
		this._updateRequestsPerSecond( 1 );
	}

	_control( value ) {
		if ( 'pause' === value.action ) {
			this.paused = value.paused;
		} else if ( 'clear' === value.action ) {
			this._clear();
		} else if ( 'connection' === value.action ) {
			this.connectionError = value.connectionError;
		}
	}

	// Clear buffer + counter + RPS history (matches handleClear in RequestStream).
	_clear() {
		this.entries = [];
		this.entryCounter = 0;
		this.completedHistory = [];
		this.rps = 0;
	}

	// Requests per second from completed requests over a 10s window. Mirrors
	// RequestStream's updateRequestsPerSecond.
	_updateRequestsPerSecond( completedCount ) {
		const now = Date.now();
		if ( completedCount > 0 ) {
			this.completedHistory.push( { time: now, count: completedCount } );
		}
		this.completedHistory = this.completedHistory.filter(
			( entry ) => now - entry.time < RPS_WINDOW_MS
		);
		const totalInWindow = this.completedHistory.reduce(
			( sum, entry ) => sum + entry.count,
			0
		);
		this.rps = totalInWindow / ( RPS_WINDOW_MS / 1000 );
	}

	// Publish ONLY the low-frequency view model. `entries` / `rps` /
	// `lastEventTime` are the high-frequency buffer the rAF reads off the node
	// directly — keeping them out of setState is what stops a busy stream
	// re-rendering React per request. `connectionError` is low-frequency (only
	// flips on connect/disconnect) so it rides setState for the reconnect banner.
	_publish() {
		this.setState( 'view', {
			paused: this.paused,
			connectionError: this.connectionError,
		} );
	}
}

/**
 * Create and register the Request Log view-model node.
 *
 * @param {string} name              Node name.
 * @param {Object} [opts]            Options.
 * @param {number} [opts.maxEntries] Buffer cap (default 1000, matching the page).
 * @return {RequestLogViewNode} The view-model node.
 */
export function createRequestLogView( name, opts = {} ) {
	const node = new RequestLogViewNode(
		opts.maxEntries || DEFAULT_MAX_ENTRIES
	);
	node.setName( name );
	return node;
}
