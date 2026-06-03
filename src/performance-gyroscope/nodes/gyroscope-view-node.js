import { Node, KEY, VALUE } from '@newspack-nodes/runtime';

const RPS_WINDOW_MS = 10000;

/**
 * `gyroscope:view` — owns the in-flight request model.
 *
 * Two cadences, deliberately split for performance (mirrors requestlog/view):
 * - HIGH frequency (the gyroscope stream): `_inflight` / `_complete` mutate the
 *   `this.requests` map and touch `this.lastEventTime`, but do NOT publish. The
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
	// Consume-and-publish view-model terminal: fill() mutates state + publishes
	// via setState, never forwards — no output port.
	static nodeSchema() {
		return {
			category: 'Hidden',
			description: 'Owns the in-flight gyroscope request view model.',
			arguments: [],
			commands: [],
			has_target: false,
		};
	}

	constructor() {
		super();
		this.requests = new Map(); // All requests keyed by rid.
		this.completedHistory = []; // Completed requests with timestamps for rps.
		this.rps = 0;
		this.lastEventTime = null;
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
		// Local control (TM_STRUCT { action, … }) — LOW-frequency path; publish.
		if ( value.action ) {
			this._control( value );
			this._publish();
		}
	}

	// An inflight snapshot: upsert each request by rid; never overwrite a request
	// already marked complete (the inflight snapshot was produced BEFORE the
	// completion that may already be in our map). Ported from handleMessage.
	_inflight( requests ) {
		for ( const req of requests ) {
			const existing = this.requests.get( req.rid );
			if ( ! existing || 'complete' !== existing.state ) {
				this.requests.set( req.rid, req );
			}
		}
		this.lastEventTime = Date.now();
	}

	// A completion: merge into the existing entry, mark state=complete. Ported
	// from handleMessage's `type === 'complete'` branch.
	_complete( req ) {
		const existing = this.requests.get( req.rid );
		this.requests.set( req.rid, {
			...existing,
			...req,
			state: 'complete',
			time_ms: req.duration_ms || 0,
			est_ms: req.duration_ms || 0,
		} );
		this.lastEventTime = Date.now();
	}

	_control( value ) {
		if ( 'clear' === value.action ) {
			this._clear();
		} else if ( 'connection' === value.action ) {
			this.connectionError = value.connectionError;
		}
	}

	// Reset map + rps history (matches handleBeforeConnect in Inflight).
	_clear() {
		this.requests.clear();
		this.completedHistory = [];
		this.rps = 0;
	}

	// Build the render snapshot AND reap completed entries (like Gyroscope.pm
	// fire() / Inflight's renderRequests): collect all requests, delete the ones
	// marked complete (they show for exactly one tick), update RPS from that
	// count, sort by est_ms desc, cap to maxRows. Returns the render array.
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

	// Requests per second from completed requests over a 10s window. Mirrors
	// Inflight's updateRequestsPerSecond.
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

	// Publish ONLY the low-frequency view model. `requests` / `rps` /
	// `lastEventTime` are the high-frequency state the refresh tick reads off the
	// node directly via snapshot() — keeping them out of setState is what stops a
	// busy stream re-rendering React per message. `connectionError` is low-frequency
	// (only flips on connect/disconnect) so it rides setState for the reconnect banner.
	_publish() {
		this.setState( 'view', { connectionError: this.connectionError } );
	}
}
