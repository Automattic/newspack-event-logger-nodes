import { Node, VALUE } from '@newspack-nodes/runtime';

const DEFAULT_MAX_ENTRIES = 5000;
const RPS_WINDOW_MS = 10000;

/**
 * `perferrors/view` — owns the Error Log view model.
 *
 * Two cadences, deliberately split for performance (mirrors requestLogView):
 * - HIGH frequency (the error stream): `_appendRow` pushes each enriched entry
 *   onto `this.entries` and recomputes `this.rps` / `this.lastEventTime`, but does
 *   NOT publish. The React view reads these directly off the node each animation
 *   frame so a high-volume stream never re-renders React per error.
 * - LOW frequency (control): only `_control` publishes the small view model via
 *   `setState('view', { paused, connectionError, lastEventTime })` — the pause
 *   button, the reconnect banner, and the "Xs ago" staleness label, consumed by
 *   `useNodeState('perferrors/view','view')`.
 *
 * `fill()` accepts two TM_STRUCT shapes:
 * - a row (`VALUE` = the mapped error from `perferrors/transform`): enriched +
 *   appended newest-first to a capped buffer (unless paused), updating
 *   errors/second + last-event time.
 * - a control (`VALUE = { action, … }`): `pause`, `clear`, `connection`.
 *
 * Buffer + entry-enrichment logic migrated verbatim from `ErrorLog.js`.
 */
class PerfErrorsViewNode extends Node {
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
			// button / banner / empty-state label re-render.
			this._control( value );
			this._publish();
		} else if ( value ) {
			// An error row is the HIGH-frequency path — update node.entries only;
			// the rAF reads them directly. Publishing here would re-render React
			// per error and defeat the whole point.
			this._appendRow( value );
		}
	}

	// A mapped error row: enrich into the render entry shape, newest-first, capped.
	// Mirrors ErrorLog's handleMessage.
	_appendRow( row ) {
		if ( this.paused ) {
			return;
		}
		this.entryCounter += 1;
		this.entries.unshift( {
			// Monotonic per-mount counter — used as the React list key (id) so two
			// entries with the same rid get distinct DOM nodes.
			seq: this.entryCounter,
			id: this.entryCounter,
			rid: row.rid,
			ts: row.ts,
			k: row.k,
			m: row.m,
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

	// Clear buffer + counter + RPS history (matches handleClear in ErrorLog).
	_clear() {
		this.entries = [];
		this.entryCounter = 0;
		this.completedHistory = [];
		this.rps = 0;
	}

	// Errors per second over a 10s window. Mirrors RequestStream's
	// updateRequestsPerSecond; harmless here (ErrorLog may ignore rps).
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

	// Publish ONLY the low-frequency view model. `entries` / `rps` are the
	// high-frequency buffer the rAF reads off the node directly. `lastEventTime`
	// is also rAF-read off the node; publishing it lets the banner / empty-state /
	// "Xs ago" re-render at low frequency.
	_publish() {
		this.setState( 'view', {
			paused: this.paused,
			connectionError: this.connectionError,
			lastEventTime: this.lastEventTime,
		} );
	}
}

/**
 * Create and register the Error Log view-model node.
 *
 * @param {string} name              Node name.
 * @param {Object} [opts]            Options.
 * @param {number} [opts.maxEntries] Buffer cap (default 5000, matching the page).
 * @return {PerfErrorsViewNode} The view-model node.
 */
export function createPerfErrorsView( name, opts = {} ) {
	const node = new PerfErrorsViewNode(
		opts.maxEntries || DEFAULT_MAX_ENTRIES
	);
	node.setName( name );
	return node;
}
