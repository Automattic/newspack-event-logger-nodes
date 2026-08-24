import {
	FROM,
	KEY,
	Node,
	VALUE,
	CommandInterpreterNode,
} from '@newspack-nodes/runtime';

// Averaging window for the requests/second readout, in seconds.
const RPS_WINDOW_SEC = 10;

// Age out an in-flight row unseen this long — a crash/eviction backstop.
const INFLIGHT_STALE_MS = 15 * 60 * 1000;

/**
 * `gyroscope:view` — owns the in-flight request model behind the In-Flight
 * Requests dashboard (`src/gyroscope/Inflight.js`).
 *
 * Two cadences, deliberately split for performance (mirrors requestlog/view):
 * - HIGH frequency (the gyroscope stream): `_inflight` / `_complete` mutate the
 *   `this.requests` map, but do NOT publish. The React view's refresh tick
 *   calls `snapshot()` each interval to read the sorted+capped render list
 *   (which also reaps completed entries and updates RPS), so a high-volume
 *   stream never re-renders React per message.
 * - LOW frequency (control): only `_control` publishes the small view model via
 *   `setState('view', { connectionError })` — the reconnect banner, consumed by
 *   `useNodeState('gyroscope:view','view')`.
 *
 * The producers write to `gyroscope.p0`, which `useGyroscopeGraph` subscribes to
 * over SSE: PHP `Request_Flight_Node` emits ONE record per in-flight request on
 * each Router tick, and `Request_Builder_Node` emits one completion per finished
 * request through `completed:tee`. Both use the Tachikoma shape — KEY is always
 * the request identity (never duplicated in VALUE), and the server-built `state`
 * field alone says what a record IS. This view is therefore written ONCE,
 * correct under BOTH producer modes (full per-tick re-emit / delta) with NO mode
 * awareness. It tests the ORIGIN first — a message from `controlFrom` is a
 * control the React layer filled in locally, whose `action` picks the verb, and
 * it republishes (the low-frequency path; `useGyroscopeGraph` sends `clear`
 * before every (re)connect). A control is never recognised by what its payload
 * looks like: a record carrying an `action` is a record. Everything else is one:
 * - object VALUE with `state` `complete` and a non-empty KEY: a completion — the
 *   source of RETIREMENT under both modes. Merge, derive time_ms/est_ms.
 * - object VALUE with any other `state` and a non-empty KEY: an in-flight upsert
 *   by rid, stamping a freshness time; NEVER overwrite one already marked
 *   complete (a late record may predate a completion already in the map).
 *   Under full re-emit this refreshes every row each tick; delta only advanced.
 * - keyless or state-less shapes are dropped, which is what discards the
 *   substrate `connected` sentinel and any verb reply.
 *
 * `snapshot()` reaps completed entries (shown one tick), ages out in-flight rows
 * past INFLIGHT_STALE_MS, sorts by est_ms descending, and caps — plus the
 * 10s-window RPS.
 *
 * @testonly The class is exported for its suite; production reaches it
 *           through the `views` map registered at the foot of this file.
 */
export class GyroscopeViewNode extends Node {
	/**
	 * Start empty and publish the initial view model, so a React subscriber
	 * mounting before the first control message reads a defined banner state.
	 */
	constructor() {
		super();
		this.requests = new Map(); // All requests keyed by rid.
		// Per-second RPS buckets + running total, bounded to the window.
		this.rpsBuckets = [];
		this.rpsWindowTotal = 0;
		this.rps = 0;
		this.connectionError = false;
		// FROM of controls; unset loses them silently (see LogStreamViewNode).
		this.controlFrom = '';
		this._publish();
	}

	/**
	 * Dispatch one envelope: a control from `controlFrom`, else a gyroscope
	 * record. A control is recognised by WHO SENT IT, never by what its
	 * payload looks like — a record carrying an `action` field is a record.
	 *
	 * Terminal node — nothing is ever forwarded, so no sink is required.
	 *
	 * @param {Array} message The 7-field envelope (KEY=rid, VALUE=record).
	 */
	fill( message ) {
		const value = message[ VALUE ];
		if ( ! value ) {
			return;
		}
		// Local control — LOW-freq path; publish.
		if ( '' !== this.controlFrom && message[ FROM ] === this.controlFrom ) {
			this._control( value );
			this._publish();
			return;
		}
		// Gyroscope record: rid rides KEY; `state` says what the record IS.
		const rid = message[ KEY ] ?? '';
		if (
			typeof value === 'object' &&
			! Array.isArray( value ) &&
			value.state &&
			rid
		) {
			const req = { ...value, rid };
			if ( 'complete' === value.state ) {
				this._complete( req );
			} else {
				this._inflight( req );
			}
		}
	}

	/**
	 * Upsert one in-flight record by rid, stamping the freshness time
	 * `snapshot()` ages rows out against. A record for a request already marked
	 * complete is dropped: it was produced before the completion it lost the
	 * race with, and applying it would resurrect a finished request.
	 *
	 * @param {Object} req The in-flight record, with `rid` restored from KEY.
	 */
	_inflight( req ) {
		const existing = this.requests.get( req.rid );
		if ( ! existing || 'complete' !== existing.state ) {
			this.requests.set( req.rid, { ...req, _seen: Date.now() } );
		}
	}

	/**
	 * Retire a request: merge the completion over whatever the in-flight entry
	 * held, mark it complete, and derive both durations from `duration_ms`. An
	 * unmatched completion still records the request, so a request that finished
	 * before its first snapshot is shown rather than lost.
	 *
	 * @param {Object} req The completion record, with `rid` restored from KEY.
	 */
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

	/**
	 * Dispatch a control message. `clear` empties the model; `connection` sets
	 * the reconnect banner flag the published view model carries.
	 *
	 * @param {Object} value The control VALUE, keyed by `action`.
	 */
	_control( value ) {
		if ( 'clear' === value.action ) {
			this._clear();
		} else if ( 'connection' === value.action ) {
			this.connectionError = value.connectionError;
		}
	}

	/**
	 * Empty the request map and the RPS window.
	 *
	 * `useGyroscopeGraph` sends a `clear` before every (re)connect: a
	 * reconnecting stream re-emits every live request from scratch, so anything
	 * held from the previous connection would linger as a row no producer will
	 * ever complete.
	 */
	_clear() {
		this.requests.clear();
		this.rpsBuckets = [];
		this.rpsWindowTotal = 0;
		this.rps = 0;
	}

	/**
	 * Publish the LOW-frequency view model — the reconnect banner, and nothing
	 * else.
	 *
	 * The request map and the RPS readout deliberately stay off `setState`:
	 * they change on every streamed record, and React reads them by calling
	 * `snapshot()` on its own refresh tick instead. Widening this to carry the
	 * requests would re-render the dashboard once per in-flight record.
	 */
	_publish() {
		this.setState( 'view', { connectionError: this.connectionError } );
	}

	/**
	 * The render list for one refresh tick, and the only reaper of the model.
	 *
	 * A completed request is returned once and deleted in the same pass, so it
	 * flashes for exactly one tick. An in-flight row unrefreshed for
	 * INFLIGHT_STALE_MS is dropped unrendered — the backstop for a producer that
	 * crashed or an entry evicted from its LRU mid-request, which under delta
	 * mode would otherwise pin the row forever. The reaped count feeds RPS.
	 *
	 * Calling this mutates the model; the React view is its one caller.
	 *
	 * @param {number} maxRows Cap on returned rows.
	 * @return {Object[]} Rows sorted by est_ms descending, capped to maxRows.
	 */
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

	/**
	 * Fold this tick's completions into the requests/second readout: one bucket
	 * per second, expired buckets subtracted from a running total, so the cost is
	 * O(1) per tick however busy the stream. An idle stream decays to 0 as its
	 * buckets age out.
	 *
	 * @param {number} completedCount Completions reaped by this snapshot.
	 */
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
	/**
	 * Hidden terminal: `fill()` mutates the model and publishes, never forwards,
	 * so the node declares no target — and it is mounted by the dashboard graph,
	 * not dragged from the palette.
	 *
	 * @return {Object} The node schema.
	 */
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

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = CommandInterpreterNode.registerNodeClasses( {
	GyroscopeView: GyroscopeViewNode,
} );
