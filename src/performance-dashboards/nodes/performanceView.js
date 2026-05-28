/* eslint-disable no-bitwise -- TYPE field uses bitmask flags (Tachikoma convention). */
import {
	Node,
	ID,
	TYPE,
	VALUE,
	TM_ERROR,
	TM_STRUCT,
} from '@newspack-nodes/runtime';

/**
 * `performance:view` — owns the Performance Dashboard view model.
 *
 * Holds four data slices — `overview`, `urls`, `urlDetail`, `requestDetail` —
 * each a `{ data, loading, error }` (urls also carries `total`), plus a
 * `lastRefresh` browser-clock stamp.
 *
 * Post-migration to substrate-canonical wiring `fill()` accepts TWO kinds of
 * messages:
 *
 *  1. Slice-tagged TM_STRUCT controls from `performance:command`:
 *     - `loading` → that slice's `loading:true`, `error:null`; others untouched.
 *     - `error`  → set that slice's `error` + clear `loading`; prior data and
 *       the OTHER slices are preserved (per-slice error isolation).
 *     - `clear`  → reset `urlDetail` / `requestDetail` to its empty shape.
 *
 *  2. Raw TM_COMMAND|TM_RESPONSE replies pivoted via TO=FROM by HttpOut. The
 *     view matches `message[ID]` against `pending` and applies the result to
 *     the registered slice (or resolves a `resolveOnly` Promise, optionally
 *     piping the payload through a `transform` first). On TM_ERROR the slice
 *     gets the error string (or the Promise rejects). Pending-matched results
 *     own the slice update; un-correlated replies are ignored.
 *
 * It also owns the stateful `urlDetail` incremental merge + `last_modified`
 * dedup (moved verbatim from the orchestrator's `mergeUrlDetail`): an `initial`
 * result replaces; a non-initial result with an unchanged `last_modified` is a
 * no-op (skip republish), otherwise its new requests are deduped by rid,
 * merged newest-first, and capped at 500.
 *
 * Every change publishes via `setState('view', model)`, consumed by
 * `useNodeState('performance:view','view')`. Low-frequency poll/selection
 * model — no per-message React concern like the request stream.
 */
class PerformanceViewNode extends Node {
	constructor() {
		super();
		this.model = {
			overview: { data: null, loading: false, error: null },
			urls: { data: [], total: 0, loading: false, error: null },
			urlDetail: { data: null, loading: false, error: null },
			requestDetail: { data: null, loading: false, error: null },
			lastRefresh: null,
		};
		// Tracks the last urlDetail payload's last_modified for the auto-refresh
		// dedup; reset on clear so the next payload is treated as fresh.
		this._urlDetailLastModified = null;
		// command-stamped ID → { slice, initial? } | { resolveOnly:true,
		// resolve, reject, transform? }. Resolved/rejected when the matching
		// reply lands here.
		this.pending = new Map();
		this._publish();
	}

	fill( message ) {
		const value = message[ VALUE ];
		const type = message[ TYPE ] || 0;

		// Pending-matched reply path: a TM_COMMAND|TM_RESPONSE (possibly with
		// TM_ERROR) pivoted back via TO=FROM. The view owns the pending Map.
		const msgId = message[ ID ];
		if ( msgId && this.pending.has( msgId ) ) {
			const entry = this.pending.get( msgId );
			this.pending.delete( msgId );
			this._applyReply( entry, value, type );
			return;
		}

		// Slice-tagged TM_STRUCT control path. A control without an action is a
		// malformed message — ignore it.
		if ( TM_STRUCT === ( type & TM_STRUCT ) || ! type ) {
			if ( ! value || ! value.action ) {
				return;
			}
			if ( 'loading' === value.action ) {
				this.model[ value.slice ] = {
					...this.model[ value.slice ],
					loading: true,
					error: null,
				};
				this._publish();
			} else if ( 'result' === value.action ) {
				this._applyResult( value );
			} else if ( 'error' === value.action ) {
				this.model[ value.slice ] = {
					...this.model[ value.slice ],
					loading: false,
					error: value.error,
				};
				this._publish();
			} else if ( 'clear' === value.action ) {
				this._clear( value.slice );
				this._publish();
			}
		}
	}

	// Apply a pending-matched reply: resolveOnly entries resolve/reject the
	// Promise (optionally piping through a transform); slice-tagged entries
	// apply the result/error to the named slice.
	_applyReply( entry, value, type ) {
		const isError = 0 !== ( type & TM_ERROR );
		const payload =
			value && 'object' === typeof value ? value.payload : null;

		if ( entry.resolveOnly ) {
			if ( isError ) {
				entry.reject( new Error( _errorMessage( payload ) ) );
				return;
			}
			const data = entry.transform ? entry.transform( payload ) : payload;
			entry.resolve( data );
			return;
		}

		// Slice-tagged: apply error → slice.error, or result → slice.data.
		if ( isError ) {
			this.model[ entry.slice ] = {
				...this.model[ entry.slice ],
				loading: false,
				error: _errorMessage( payload ),
			};
			this._publish();
			return;
		}
		this._applyResult( {
			action: 'result',
			slice: entry.slice,
			data: payload,
			initial: entry.initial,
		} );
	}

	// Store a slice result. urlDetail goes through the incremental merge (which
	// may skip republishing); every other slice stamps lastRefresh + publishes.
	_applyResult( v ) {
		if ( 'urlDetail' === v.slice ) {
			if ( ! this._mergeUrlDetail( v.data, v.initial ) ) {
				// Unchanged last_modified → no-op, no republish.
				return;
			}
		} else if ( 'overview' === v.slice ) {
			this.model.overview = { data: v.data, loading: false, error: null };
		} else if ( 'urls' === v.slice ) {
			this.model.urls = {
				data: ( v.data && v.data.data ) || [],
				total: ( v.data && v.data.total ) || 0,
				loading: false,
				error: null,
			};
		} else if ( 'requestDetail' === v.slice ) {
			this.model.requestDetail = {
				data: v.data,
				loading: false,
				error: null,
			};
		}
		this.model.lastRefresh = Date.now();
		this._publish();
	}

	// Merge new requests incrementally (moved verbatim from mergeUrlDetail).
	// Returns false when the result is an unchanged-last_modified no-op so the
	// caller skips republishing; true otherwise.
	_mergeUrlDetail( data, isInitial ) {
		// Empty payload (unwrapCommandResponse → null): no-op, skip republish.
		if ( ! data ) {
			return false;
		}
		if ( isInitial ) {
			this.model.urlDetail = { data, loading: false, error: null };
			this._urlDetailLastModified = data.last_modified;
			return true;
		}
		if ( data.last_modified === this._urlDetailLastModified ) {
			return false;
		}
		this._urlDetailLastModified = data.last_modified;
		const prev = this.model.urlDetail.data;
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
		this.model.urlDetail = { data: merged, loading: false, error: null };
		return true;
	}

	// Reset a slice to its empty shape (and clear the urlDetail dedup state).
	_clear( slice ) {
		if ( 'urlDetail' === slice ) {
			this.model.urlDetail = { data: null, loading: false, error: null };
			this._urlDetailLastModified = null;
		} else if ( 'requestDetail' === slice ) {
			this.model.requestDetail = {
				data: null,
				loading: false,
				error: null,
			};
		}
	}

	_publish() {
		this.setState( 'view', { ...this.model } );
	}
}

// Coerce a TM_ERROR payload (string / { message } / anything else) to a
// human-readable string for the error / view-model error field.
function _errorMessage( payload ) {
	if ( 'string' === typeof payload && payload.length > 0 ) {
		return payload;
	}
	if (
		payload &&
		'object' === typeof payload &&
		'string' === typeof payload.message &&
		payload.message.length > 0
	) {
		return payload.message;
	}
	return 'Operation failed';
}

/**
 * Create and register the Performance Dashboard view-model node.
 *
 * @param {string} name Node name.
 * @return {PerformanceViewNode} The view-model node.
 */
export function createPerformanceView( name ) {
	const node = new PerformanceViewNode();
	node.setName( name );
	return node;
}
