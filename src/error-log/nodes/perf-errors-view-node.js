import { Node, KEY, VALUE, ID } from '@newspack-nodes/runtime';
import fnv1a from '@newspack-nodes/shared/utils/fnv1a';
import { PendingReplies } from '@newspack-nodes/shared/pendingReplies';
import { SeekTracker } from '@newspack-nodes/shared/nodes/seekTracker';

const DEFAULT_MAX_ENTRIES = 5000;
const RPS_WINDOW_SEC = 10;
const MAX_M_LENGTH = 1000;
const MAX_URL_LENGTH = 2000;

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
 * `_sse` targets the view directly. fill() receives raw 7-field envelopes
 * (KEY=rid, VALUE={ts, k, m, n, method, url}) and shapes them into rows
 * inline via a tiny dispatch in `_appendEnvelope`.
 *
 * Two cadences, deliberately split for performance (mirrors requestLogView):
 * - HIGH frequency (the error stream): `_appendEnvelope` validates + enriches
 *   each envelope, writes it into a fixed ring buffer (O(1): write at head,
 *   advance, overwrite oldest), and updates `this.rps`, but
 *   does NOT publish. The React view reads the VISIBLE window straight off the
 *   node each animation frame via `entriesCount` + `entryAt(i)` (newest-first) —
 *   O(rows-on-screen), not O(buffer). `entries` materializes the whole buffer
 *   newest-first for the React snapshot and tests.
 * - LOW frequency (control): only `_control` publishes the small view model via
 *   `setState('view', { paused, connectionError })` — the pause button and the
 *   reconnect banner, consumed by `useNodeState('perferrors:view','view')`.
 *
 * `fill()` distinguishes its two inputs by `VALUE.action`:
 * - control (`VALUE = { action, … }`, KEY empty) comes HOOK-DIRECT from
 *   `useErrorLogGraph` (pause / clear / filter / connection-status).
 * - everything else is treated as a stream envelope and routed through
 *   `_appendEnvelope`, which drops envelopes with no rid, non-object/array
 *   VALUE, the `connected` sentinel, or entries outside the active filter, and
 *   clips `m` at 1000 chars.
 *
 * Buffer + entry-enrichment logic migrated verbatim from `ErrorLog.js`.
 */
export class PerfErrorsViewNode extends Node {
	constructor( maxEntries ) {
		super();
		this.maxEntries = maxEntries || DEFAULT_MAX_ENTRIES;
		// Ring buffer: write at _head mod maxEntries, oldest overwritten; O(1).
		this._ring = [];
		this._head = 0;
		this._count = 0;
		this.entryCounter = 0;
		// Per-second RPS buckets + running total, bounded to the window.
		this.rpsBuckets = [];
		this.rpsWindowTotal = 0;
		this.rps = 0;
		this.paused = false;
		this.connectionError = false;
		this.filter = '';
		// Seek feedback; armed for a single dir only (a glob mixes segments).
		this.seek = new SeekTracker();
		this.seekActive = false;
		// Hook-stamped ID → { resolve, reject }; settled when its reply lands.
		this.replies = new PendingReplies();
		this._publish();
	}

	// Seek feedback surfaced for the published model (and view-node tests).
	get mode() {
		return this.seek.mode;
	}
	get lastReceivedSegment() {
		return this.seek.lastReceivedSegment;
	}

	fill( message ) {
		const value = message[ VALUE ];
		// A raw-logs catalog reply (VALUE.name); raw envelopes can't match it.
		if (
			value &&
			'object' === typeof value &&
			'name' in value &&
			this.replies.settle( message )
		) {
			return;
		}
		if ( value && typeof value === 'object' && value.action ) {
			// LOW-freq control change — publish (button/banner re-render).
			this._control( value );
			this._publish();
		} else {
			// Raw envelope: validate, enrich, append. HIGH-freq — no publish.
			if ( this.seekActive ) {
				this._trackPosition( message );
			}
			this._appendEnvelope( message );
		}
	}

	_control( value ) {
		if ( 'pause' === value.action ) {
			this.paused = value.paused;
		} else if ( 'clear' === value.action ) {
			this._clear();
		} else if ( 'filter' === value.action ) {
			this._setFilter( value.filter );
		} else if ( 'connection' === value.action ) {
			this.connectionError = value.connectionError;
		} else if ( 'select' === value.action ) {
			// Partition switch: reset the tracker; arm only for a single dir.
			this.seekActive = !! value.dir;
			this.seek.select();
			this._clear();
		} else if ( 'browse' === value.action ) {
			this.seek.browse( value.endSegment ?? null, value.endOffset ?? 0 );
		} else if ( 'follow' === value.action ) {
			this.seek.follow();
		}
	}

	// Track the record's breadcrumb; publish on segment/catch-up change only.
	_trackPosition( message ) {
		if ( this.seek.track( message[ ID ] ) ) {
			this._publish();
		}
	}

	_setFilter( filter ) {
		if ( 'string' !== typeof filter ) {
			throw new TypeError( 'error log filter must be a string' );
		}
		const normalized = filter.toLowerCase();
		if ( normalized === this.filter ) {
			return;
		}
		this.filter = normalized;
		this.entries = [];
		this.entryCounter = 0;
	}

	// Clear buffer + counter + RPS window (matches handleClear in ErrorLog).
	_clear() {
		this.entries = [];
		this.entryCounter = 0;
		this.rpsBuckets = [];
		this.rpsWindowTotal = 0;
		this.rps = 0;
	}

	// Publish only the low-freq view model; entries/rps stay off setState.
	_publish() {
		this.setState( 'view', {
			paused: this.paused,
			connectionError: this.connectionError,
			mode: this.seek.mode,
			lastReceivedSegment: this.seek.lastReceivedSegment,
		} );
	}

	// A raw stream envelope (KEY=rid): validate, enrich, append newest-first.
	_appendEnvelope( message ) {
		const rid = message[ KEY ];
		if ( ! rid ) {
			return;
		}
		// SseInNode streams a `connected` sentinel too; it's not an error.
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
		const k = value.k || '';
		const method = 'string' === typeof value.method ? value.method : '';
		const rawUrl = 'string' === typeof value.url ? value.url : '';
		const url = clip( rawUrl, MAX_URL_LENGTH );
		this._updateRequestsPerSecond( 1 );
		if (
			this.filter &&
			! [ rid, k, m, url ].some(
				( field ) =>
					'string' === typeof field &&
					field.toLowerCase().includes( this.filter )
			)
		) {
			return;
		}

		this.entryCounter += 1;
		const row = {
			// Monotonic per-mount React key (distinct DOM per dup rid).
			seq: this.entryCounter,
			id: this.entryCounter,
			rid,
			ts: value.ts || 0,
			k,
			m,
		};
		if ( url ) {
			row.method = method;
			row.url = url;
			row.urlHash = fnv1a( rawUrl );
		}
		this._writeEntry( row );
	}

	// Errors/sec over a 10s window: per-second buckets, O(1) per error.
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

	// Whole buffer newest-first — O(n), filter/tests only (not per-frame).
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

	// Write one entry at the ring head and advance, capping at maxEntries.
	_writeEntry( entry ) {
		this._ring[ this._head ] = entry;
		this._head = ( this._head + 1 ) % this.maxEntries;
		this._count = Math.min( this._count + 1, this.maxEntries );
	}

	// The i-th entry newest-first (i=0 newest), O(1); undefined out of range.
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
	// View-model terminal: fill() mutates state + publishes; never forwards.
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
