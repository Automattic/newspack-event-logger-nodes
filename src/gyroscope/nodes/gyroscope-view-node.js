import { Node, VALUE } from '@newspack-nodes/runtime';

const RPS_WINDOW_SEC = 10;

// Age out an in-flight row unseen this long — a crash/eviction backstop.
const INFLIGHT_STALE_MS = 15 * 60 * 1000;

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
 * The producer emits ONE record per in-flight request, KEY=rid — the Tachikoma
 * shape: the key is always the request identity, and the server-built `state`
 * field alone says what a record IS. This view is written ONCE, correct under
 * BOTH producer modes (full per-tick re-emit / delta) with NO mode awareness:
 * - object VALUE with `rid`, state `complete`: a completion — the source of
 *   RETIREMENT under both modes. Merge, derive time/est_ms.
 *   `RequestBuilder`'s `completed:tee` fans these out at request-complete.
 * - object VALUE with `rid`, any other state: an in-flight upsert by rid,
 *   stamping a freshness time; NEVER overwrite one already marked complete (a
 *   late record may predate a completion already in the map). Under full
 *   re-emit this refreshes every row each tick; under delta only advanced rows.
 * - rid-less shapes (the substrate `connected` sentinel included) are dropped.
 * - A local TM_STRUCT control message (VALUE.action: `clear` / `connection`):
 *   dispatched + published (low-frequency path).
 *
 * `snapshot()` reaps completed entries (shown one tick), ages out in-flight rows
 * past INFLIGHT_STALE_MS, sorts by est_ms desc, and caps — plus the 10s-window RPS.
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
		const value = message[ VALUE ];
		if ( ! value ) {
			return;
		}
		// Gyroscope record: it's in-flight if it's not complete.
		if (
			typeof value === 'object' &&
			! Array.isArray( value ) &&
			value.rid
		) {
			if ( 'complete' === value.state ) {
				this._complete( value );
			} else {
				this._inflight( value );
			}
			return;
		}
		// Local control (TM_STRUCT { action, … }) — LOW-freq path; publish.
		if ( value.action ) {
			this._control( value );
			this._publish();
		}
	}

	// Upsert one record by rid + stamp freshness; skip if already complete.
	_inflight( req ) {
		const existing = this.requests.get( req.rid );
		if ( ! existing || 'complete' !== existing.state ) {
			this.requests.set( req.rid, { ...req, _seen: Date.now() } );
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

	// Render snapshot: reap completed (one tick), age out stale in-flight rows.
	snapshot( maxRows ) {
		const allRequests = [];
		let completedCount = 0;
		const now = Date.now();
		for ( const [ rid, req ] of this.requests ) {
			if ( 'complete' === req.state ) {
				completedCount += 1;
				this.requests.delete( rid );
				allRequests.push( req ); // shown one tick, then reaped
				continue;
			}
			if ( req._seen && now - req._seen > INFLIGHT_STALE_MS ) {
				this.requests.delete( rid ); // stale straggler — drop, don't render
				continue;
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
