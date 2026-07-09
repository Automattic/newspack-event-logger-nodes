import { Node, VALUE } from '@newspack-nodes/runtime';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';

const DEFAULT_MAX_ENTRIES = 1000;
const RPS_WINDOW_SEC = 10;
// Defensive bounds for raw envelope VALUEs. Mirrors the legacy
// `transformCompletedLine` clip behavior — kept here so the view is the single
// place that knows envelope → render-entry mapping.
const MAX_URL_LENGTH = 2000;
const MAX_UA_LENGTH = 500;

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
 * Two cadences, deliberately split for performance (mirrors rawLogsView):
 * - HIGH frequency (the request stream): `_appendRow` writes each enriched entry
 *   into a fixed ring buffer (O(1): write at head, advance, overwrite oldest) and
 *   updates `this.rps`, but does NOT publish. The React
 *   view reads the VISIBLE window straight off the node each animation frame via
 *   `entriesCount` + `entryAt(i)` (newest-first) — O(rows-on-screen), not
 *   O(buffer) — so a high-volume stream never re-renders or re-copies per frame.
 *   `entries` materializes the whole buffer newest-first for the filter path +
 *   tests only; it is NOT on the frame path.
 * - LOW frequency (control): only `_control` publishes the small view model via
 *   `setState('view', { paused, connectionError })` — the pause button, the
 *   empty-state label, and the reconnect banner, consumed by
 *   `useNodeState('requestlog:view','view')`.
 *
 * `fill()` accepts two TM_STRUCT shapes:
 * - a row (`VALUE` = the raw completed-request envelope from `_sse`): defensively
 *   shaped (drop missing-url, clip url@2000, clip user_agent@500, default-fill)
 *   then enriched + appended newest-first to a capped buffer (unless paused),
 *   updating requests/second + last-event time.
 * - a control (`VALUE = { action, … }`): `pause`, `clear`, `connection`.
 *
 * Buffer + RPS + entry-enrichment logic migrated verbatim from `RequestStream.js`.
 * The defensive shaping was inlined from the (now-deleted) `requestlog:transform`
 * node when the chain collapsed to `_sse → requestlog:view`.
 */
export class RequestLogViewNode extends Node {
	constructor( maxEntries ) {
		super();
		this.maxEntries = maxEntries || DEFAULT_MAX_ENTRIES;
		// Ring buffer: rows written at `_head` (mod maxEntries), oldest overwritten
		// once full. `_count` is how many slots hold a live row. Append and
		// cap-drop are both O(1) — no shift, concat, or truncation.
		this._ring = [];
		this._head = 0;
		this._count = 0;
		this.entryCounter = 0;
		// Per-second RPS buckets ({ sec, count }) + their running total — bounded
		// to the window instead of one entry per request.
		this.rpsBuckets = [];
		this.rpsWindowTotal = 0;
		this.rps = 0;
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

	_control( value ) {
		if ( 'pause' === value.action ) {
			this.paused = value.paused;
		} else if ( 'clear' === value.action ) {
			this._clear();
		} else if ( 'connection' === value.action ) {
			this.connectionError = value.connectionError;
		}
	}

	// Clear buffer + counter + RPS window (matches handleClear in RequestStream).
	_clear() {
		this.entries = [];
		this.entryCounter = 0;
		this.rpsBuckets = [];
		this.rpsWindowTotal = 0;
		this.rps = 0;
	}

	// Publish ONLY the low-frequency view model. `entries` / `rps` are the
	// high-frequency buffer the rAF reads off the node directly — keeping them
	// out of setState is what stops a busy stream
	// re-rendering React per request. `connectionError` is low-frequency (only
	// flips on connect/disconnect) so it rides setState for the reconnect banner.
	_publish() {
		this.setState( 'view', {
			paused: this.paused,
			connectionError: this.connectionError,
		} );
	}

	// A raw completed-request envelope's VALUE: defensively shape (drop
	// missing-url, clip url + UA, default-fill) then enrich into the render
	// entry shape, newest-first, capped. The defensive shaping is inlined from
	// the dropped `requestlog:transform` node — the view is the single place
	// that knows envelope → render-entry mapping.
	_appendRow( req ) {
		if ( this.paused ) {
			return;
		}
		// Defensive: VALUE must be a plain object with a url.
		if ( ! req || 'object' !== typeof req || Array.isArray( req ) ) {
			return;
		}
		if ( ! req.url ) {
			return;
		}
		const url = clip( req.url, MAX_URL_LENGTH );
		this.entryCounter += 1;
		this._writeEntry( {
			// Monotonic per-mount counter — used as the React list key so two
			// entries with the same rid get distinct DOM nodes (a colliding rid
			// from an aggregated spoke, or a worker's reset-then-rebuild in the
			// same second). Without it the virtualized list reuses one node for
			// both and scrolling jumps.
			seq: this.entryCounter,
			rid: req.rid || '',
			url,
			urlHash: urlHash( url ),
			method: req.method || 'GET',
			duration_ms: req.duration_ms || 0,
			status_code: req.status_code || 0,
			timestamp: req.end_time || 0,
			remote_addr: req.remote_addr || '',
			user_agent: clip( req.user_agent || '', MAX_UA_LENGTH ),
			isEven: this.entryCounter % 2 === 0,
		} );
		this._updateRequestsPerSecond( 1 );
	}

	// Requests per second over a 10s window. Counts are aggregated into
	// per-second buckets with a running total, so each request is O(1) (one
	// bucket bump + bounded expiry) — not an O(n) scan of the window.
	_updateRequestsPerSecond( completedCount ) {
		if ( completedCount <= 0 ) {
			return;
		}
		const sec = Math.floor( Date.now() / 1000 );
		const last = this.rpsBuckets[ this.rpsBuckets.length - 1 ];
		if ( last && last.sec === sec ) {
			last.count += completedCount;
		} else {
			this.rpsBuckets.push( { sec, count: completedCount } );
		}
		this.rpsWindowTotal += completedCount;
		const oldest = sec - RPS_WINDOW_SEC;
		while (
			this.rpsBuckets.length > 0 &&
			this.rpsBuckets[ 0 ].sec <= oldest
		) {
			this.rpsWindowTotal -= this.rpsBuckets[ 0 ].count;
			this.rpsBuckets.shift();
		}
		this.rps = this.rpsWindowTotal / RPS_WINDOW_SEC;
	}

	// The whole buffer materialized newest-first — O(n), for the filter path and
	// tests only, NOT the per-frame path. Assigning (`node.entries = []` from the
	// graph clear) reseeds the ring from the given newest-first array.
	get entries() {
		const out = new Array( this._count );
		for ( let i = 0; i < this._count; i++ ) {
			out[ i ] = this.entryAt( i );
		}
		return out;
	}

	set entries( value ) {
		this._ring = [];
		this._head = 0;
		this._count = 0;
		if ( Array.isArray( value ) ) {
			// Seed oldest-first so the newest entry lands last (at head-1).
			for ( let i = value.length - 1; i >= 0; i-- ) {
				this._writeEntry( value[ i ] );
			}
		}
	}

	// Write one entry into the ring at the head and advance, capping at maxEntries.
	_writeEntry( entry ) {
		this._ring[ this._head ] = entry;
		this._head = ( this._head + 1 ) % this.maxEntries;
		this._count = Math.min( this._count + 1, this.maxEntries );
	}

	// The i-th entry newest-first (i=0 is newest), O(1); undefined out of range.
	// The virtual list reads only its on-screen window through this — never the
	// whole buffer — so the frame cost is O(rows-on-screen) regardless of size.
	entryAt( i ) {
		if ( i < 0 || i >= this._count ) {
			return undefined;
		}
		const idx = ( this._head - 1 - i + this.maxEntries ) % this.maxEntries;
		return this._ring[ idx ];
	}

	// Number of live entries in the ring (O(1)).
	get entriesCount() {
		return this._count;
	}
	// Consume-and-publish view-model terminal: fill() mutates state + publishes
	// via setState, never forwards — no output port.
	static nodeSchema() {
		return {
			category: 'Hidden',
			description: 'Owns the Request Log view model.',
			arguments: [],
			commands: [],
			has_target: false,
		};
	}
}
