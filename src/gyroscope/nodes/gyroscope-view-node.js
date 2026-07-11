import { Node, KEY, VALUE } from '@newspack-nodes/runtime';

const RPS_WINDOW_SEC = 10;

/**
 * `gyroscope:view` — owns the in-flight request model.
 *
 * Two cadences, deliberately split for performance (mirrors requestlog/view):
 * - HIGH frequency (the gyroscope stream): `_inflight` / `_complete` mutate the
 *   `this.requests` map, but do NOT publish. The
 *   React view's refresh tick calls `snapshot()` each interval to read the
 *   sorted+capped render list (which also reaps completed entries + updates RPS)
 *   so a high-volume stream never re-renders React per message.
 * - LOW frequency (control): only `_control` publishes the small view model via
 *   `setState('view', { connectionError })` — the reconnect banner, consumed by
 *   `useNodeState('gyroscope:view','view')`.
 *
 * `fill()` dispatches directly on the wire envelope shape (no upstream
 * transform — _sse delivers raw envelopes):
 * - `KEY === 'inflight'` + array VALUE: upsert each request by rid, NEVER
 *   overwriting one already marked complete (the snapshot predates a completion
 *   that may already be in the map). `RequestFlight` emits these periodically.
 * - VALUE is an object with `rid`: a completion. Merge into the existing entry,
 *   mark state=complete, derive time_ms/est_ms from duration_ms.
 *   `RequestBuilder`'s `completed:tee` fans these out at request-complete.
 * - `KEY === 'connected'` (substrate sentinel) and any unrecognized shape are
 *   dropped.
 * - A local TM_STRUCT control message (VALUE.action: `clear` / `connection`):
 *   dispatched + published (low-frequency path).
 *
 * The map accumulation, the `snapshot()` reaper (delete-completed-after-one-tick,
 * sort by est_ms desc, cap), and the 10s-window RPS are migrated verbatim from
 * `Inflight.js` (`requestsRef` + `handleMessage` + `renderRequests` +
 * `updateRequestsPerSecond` + `handleBeforeConnect`).
 */
export class GyroscopeViewNode extends Node {
	constructor() {
		super();
		this.requests = new Map(); // All requests keyed by rid.
		// Per-second RPS buckets + running total, bounded to the window.
		this.rpsBuckets = [];
		this.rpsWindowTotal = 0;
		this.rps = 0;
		this.connectionError = false;
		this._publish();
	}

	fill( message ) {
		const key = message[ KEY ];
		const value = message[ VALUE ];
		if ( ! value ) {
			return;
		}
		// Wire envelope: inflight snapshot.
		if ( 'inflight' === key && Array.isArray( value ) ) {
			this._inflight( value );
			return;
		}
		// Substrate sentinel — never a gyroscope record.
		if ( 'connected' === key ) {
			return;
		}
		// Wire envelope: completion (single object with rid).
		if (
			typeof value === 'object' &&
			! Array.isArray( value ) &&
			value.rid
		) {
			this._complete( value );
			return;
		}
		// Local control (TM_STRUCT { action, … }) — LOW-freq path; publish.
		if ( value.action ) {
			this._control( value );
			this._publish();
		}
	}

	// Inflight snapshot: upsert by rid; never overwrite a completed entry.
	_inflight( requests ) {
		for ( const req of requests ) {
			const existing = this.requests.get( req.rid );
			if ( ! existing || 'complete' !== existing.state ) {
				this.requests.set( req.rid, req );
			}
		}
	}

	// A completion: merge into the existing entry, mark state=complete.
	_complete( req ) {
		const existing = this.requests.get( req.rid );
		this.requests.set( req.rid, {
			...existing,
			...req,
			state: 'complete',
			time_ms: req.duration_ms || 0,
			est_ms: req.duration_ms || 0,
		} );
	}

	_control( value ) {
		if ( 'clear' === value.action ) {
			this._clear();
		} else if ( 'connection' === value.action ) {
			this.connectionError = value.connectionError;
		}
	}

	// Reset map + rps window (matches handleBeforeConnect in Inflight).
	_clear() {
		this.requests.clear();
		this.rpsBuckets = [];
		this.rpsWindowTotal = 0;
		this.rps = 0;
	}

	// Publish only the low-freq view model; requests/rps stay off setState.
	_publish() {
		this.setState( 'view', { connectionError: this.connectionError } );
	}

	// Build the render snapshot and reap completed entries (one-tick display).
	snapshot( maxRows ) {
		const allRequests = [];
		let completedCount = 0;
		for ( const [ rid, req ] of this.requests ) {
			if ( 'complete' === req.state ) {
				completedCount += 1;
				this.requests.delete( rid );
			}
			allRequests.push( req );
		}
		this._updateRequestsPerSecond( completedCount );
		return allRequests
			.sort( ( a, b ) => ( b.est_ms || 0 ) - ( a.est_ms || 0 ) )
			.slice( 0, maxRows );
	}

	// RPS over a 10s window: per-second buckets, O(1); idle ticks decay to 0.
	_updateRequestsPerSecond( completedCount ) {
		const sec = Math.floor( Date.now() / 1000 );
		if ( completedCount > 0 ) {
			const last = this.rpsBuckets[ this.rpsBuckets.length - 1 ];
			if ( last && last.sec === sec ) {
				last.count += completedCount;
			} else {
				this.rpsBuckets.push( { sec, count: completedCount } );
			}
			this.rpsWindowTotal += completedCount;
		}
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
	// View-model terminal: fill() mutates state + publishes; never forwards.
	static nodeSchema() {
		return {
			category: 'Hidden',
			description: 'Owns the in-flight gyroscope request view model.',
			arguments: [],
			commands: [],
			has_target: false,
		};
	}
}
