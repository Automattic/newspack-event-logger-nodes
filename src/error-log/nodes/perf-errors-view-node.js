import { Node, KEY, VALUE } from '@newspack-nodes/runtime';

const DEFAULT_MAX_ENTRIES = 5000;
const RPS_WINDOW_SEC = 10;
const MAX_M_LENGTH = 1000;

/**
 * `perferrors:view` — owns the Error Log view model.
 *
 * `_sse` targets the view directly. fill() receives raw 7-field envelopes
 * (KEY=rid, VALUE={ts, k, m, n}) and shapes them into rows inline via a tiny
 * dispatch in `_appendEnvelope`.
 *
 * Two cadences, deliberately split for performance (mirrors requestLogView):
 * - HIGH frequency (the error stream): `_appendEnvelope` validates + enriches
 *   each envelope, writes it into a fixed ring buffer (O(1): write at head,
 *   advance, overwrite oldest), and updates `this.rps`, but
 *   does NOT publish. The React view reads the VISIBLE window straight off the
 *   node each animation frame via `entriesCount` + `entryAt(i)` (newest-first) —
 *   O(rows-on-screen), not O(buffer). `entries` materializes the whole buffer
 *   newest-first for the filter path + tests only; it is NOT on the frame path.
 * - LOW frequency (control): only `_control` publishes the small view model via
 *   `setState('view', { paused, connectionError })` — the pause button and the
 *   reconnect banner, consumed by `useNodeState('perferrors:view','view')`.
 *
 * `fill()` distinguishes its two inputs by `VALUE.action`:
 * - control (`VALUE = { action, … }`, KEY empty) comes HOOK-DIRECT from
 *   `useErrorLogGraph` (pause / clear / connection-status).
 * - everything else is treated as a stream envelope and routed through
 *   `_appendEnvelope`, which drops envelopes with no rid, non-object/array
 *   VALUE, or the `connected` sentinel, and clips `m` at 1000 chars.
 *
 * Buffer + entry-enrichment logic migrated verbatim from `ErrorLog.js`.
 */
export class PerfErrorsViewNode extends Node {
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
		// to the window instead of one entry per error.
		this.rpsBuckets = [];
		this.rpsWindowTotal = 0;
		this.rps = 0;
		this.paused = false;
		this.connectionError = false;
		this._publish();
	}

	fill( message ) {
		const value = message[ VALUE ];
		if ( value && typeof value === 'object' && value.action ) {
			// Control changes are the LOW-frequency path — publish so the pause
			// button / banner / empty-state label re-render.
			this._control( value );
			this._publish();
		} else {
			// Otherwise treat as a raw stream envelope: validate, enrich, append.
			// The rAF reads the buffer directly, so this is the HIGH-frequency
			// path and deliberately does NOT publish.
			this._appendEnvelope( message );
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

	// Clear buffer + counter + RPS window (matches handleClear in ErrorLog).
	_clear() {
		this.entries = [];
		this.entryCounter = 0;
		this.rpsBuckets = [];
		this.rpsWindowTotal = 0;
		this.rps = 0;
	}

	// Publish ONLY the low-frequency view model. `entries` / `rps` are the
	// high-frequency buffer the rAF reads off the node directly, kept out of
	// setState so a busy stream never re-renders React per envelope.
	_publish() {
		this.setState( 'view', {
			paused: this.paused,
			connectionError: this.connectionError,
		} );
	}

	// A raw stream envelope: KEY=rid, VALUE={ts, k, m, n}. Validate + enrich +
	// append newest-first, capped.
	_appendEnvelope( message ) {
		const rid = message[ KEY ];
		if ( ! rid ) {
			return;
		}
		// SseInNode streams a `connected` sentinel envelope too; it's not an error.
		if ( 'connected' === rid ) {
			return;
		}
		const value = message[ VALUE ];
		if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
			return;
		}
		if ( this.paused ) {
			return;
		}

		let m = value.m || '';
		if ( typeof m === 'string' && m.length > MAX_M_LENGTH ) {
			m = m.substring( 0, MAX_M_LENGTH ) + '...';
		}

		this.entryCounter += 1;
		this._writeEntry( {
			// Monotonic per-mount counter — used as the React list key (id) so two
			// entries with the same rid get distinct DOM nodes.
			seq: this.entryCounter,
			id: this.entryCounter,
			rid,
			ts: value.ts || 0,
			k: value.k || '',
			m,
			isEven: this.entryCounter % 2 === 0,
		} );
		this._updateRequestsPerSecond( 1 );
	}

	// Errors per second over a 10s window. Counts are aggregated into per-second
	// buckets with a running total, so each error is O(1) (one bucket bump +
	// bounded expiry) — not an O(n) scan of the window.
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
			description: 'Owns the Error Log view model.',
			arguments: [],
			commands: [],
			has_target: false,
		};
	}
}
