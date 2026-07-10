import { Node, VALUE, TYPE, TM_STRUCT } from '@newspack-nodes/runtime';

/**
 * `urlDetail:merge` — the url_detail incremental-merge + last_modified dedup as a
 * transform Node on the receiver-Tee → view graph EDGE (the addSliceFetcher
 * `transform` slot), so this stateful merge lives on the graph instead of inside
 * the view (D1b de-god).
 *
 * It receives the raw command reply (VALUE = { name, payload } where payload is
 * the url_detail object the server returned), merges it against the payload it
 * last forwarded, and forwards a message whose VALUE.payload is the MERGED object
 * — or DROPS the message when last_modified is unchanged (no republish, so an
 * unchanged auto-refresh tick never re-renders the modal).
 *
 * The merge logic:
 *   - empty/null payload                → drop (no forward);
 *   - first reply (no retained state)   → forward as-is, record last_modified;
 *   - unchanged last_modified           → drop;
 *   - changed last_modified             → dedup new requests by rid, prepend
 *                                         newest-first, cap 500, forward merged.
 *
 * A TM_STRUCT `{ action:'clear' }` control resets the retained state so the next
 * reply is treated as fresh (modal close → reopen selects a new URL).
 *
 * Pure pass-through forwarder: it rewrites VALUE.payload in place and forwards
 * via the base `fill()` (which stamps TO from `target` → the view). It does NOT
 * stamp FROM — transforms are internal edges, not I/O boundaries.
 */
export class UrlDetailMergeNode extends Node {
	constructor() {
		super();
		// Last forwarded url_detail payload + last_modified (reset on clear).
		this._merged = null;
		this._lastModified = null;
	}

	fill( message ) {
		const value = message[ VALUE ];

		// Clear control: reset retained state, do not forward.
		if (
			TM_STRUCT === ( ( message[ TYPE ] || 0 ) & TM_STRUCT ) &&
			value &&
			'clear' === value.action
		) {
			this._merged = null;
			this._lastModified = null;
			return;
		}

		const payload =
			value && 'object' === typeof value ? value.payload : null;
		const next = this._merge( payload );
		if ( null === next ) {
			// No-op (empty payload or unchanged last_modified) — drop, no republish.
			return;
		}
		// Forward the merged payload to the view via sink (TO stamped from target).
		message[ VALUE ] = { ...value, payload: next };
		super.fill( message );
	}

	// Merge data into the retained payload; null drops (empty/unchanged).
	_merge( data ) {
		// Empty payload: no-op, skip forward.
		if ( ! data ) {
			return null;
		}
		// First reply (no retained state): forward as-is.
		if ( null === this._merged ) {
			this._merged = data;
			this._lastModified = data.last_modified;
			return data;
		}
		// Unchanged last_modified → no-op, skip forward.
		if ( data.last_modified === this._lastModified ) {
			return null;
		}
		this._lastModified = data.last_modified;
		const prev = this._merged;
		let merged;
		if ( ! prev || ! prev.requests || ! prev.requests.length ) {
			merged = data;
		} else {
			const existingRids = new Set( prev.requests.map( ( r ) => r.rid ) );
			const newRequests =
				( data.requests || [] ).filter(
					( r ) => ! existingRids.has( r.rid )
				) || [];
			if ( 0 === newRequests.length ) {
				merged = { ...data, requests: prev.requests };
			} else {
				merged = {
					...data,
					requests: [ ...newRequests, ...prev.requests ]
						.sort(
							( a, b ) =>
								( b.timestamp || 0 ) - ( a.timestamp || 0 )
						)
						.slice( 0, 500 ),
				};
			}
		}
		this._merged = merged;
		return merged;
	}

	// Edge transform: rewrites VALUE.payload + forwards; target wired externally.
	static nodeSchema() {
		return {
			category: 'Hidden',
			description:
				'Merges url_detail replies incrementally on the receiver→view edge.',
			arguments: [],
			commands: [],
		};
	}
}
