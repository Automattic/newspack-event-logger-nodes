import { Node, KEY, VALUE } from '@newspack-nodes/runtime';

const DEFAULT_MAX_ENTRIES = 5000;
const RPS_WINDOW_MS = 10000;
const MAX_M_LENGTH = 1000;

/**
 * `perferrors:view` — owns the Error Log view model.
 *
 * The chain collapsed in v0.x: `_sse` now targets the view directly. fill()
 * receives raw 7-field envelopes (KEY=rid, VALUE={ts, k, m, n}) and shapes them
 * into rows inline — what `perferrors:transform` used to do is now a tiny
 * dispatch in `_appendEnvelope`.
 *
 * Two cadences, deliberately split for performance (mirrors requestLogView):
 * - HIGH frequency (the error stream): `_appendEnvelope` validates + enriches
 *   each envelope, pushes onto `this.entries`, and recomputes `this.rps` /
 *   `this.lastEventTime`, but does NOT publish. The React view reads these
 *   directly off the node each animation frame so a high-volume stream never
 *   re-renders React per error.
 * - LOW frequency (control): only `_control` publishes the small view model via
 *   `setState('view', { paused, connectionError, lastEventTime })` — the pause
 *   button, the reconnect banner, and the "Xs ago" staleness label, consumed by
 *   `useNodeState('perferrors:view','view')`.
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

	constructor( maxEntries ) {
		super();
		this.maxEntries = maxEntries || DEFAULT_MAX_ENTRIES;
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

	// A raw stream envelope: KEY=rid, VALUE={ts, k, m, n}. Validate + enrich +
	// append newest-first, capped. Mirrors the dispatch the retired
	// `perferrors:transform` Callback used to do.
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
		this.entries.unshift( {
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
