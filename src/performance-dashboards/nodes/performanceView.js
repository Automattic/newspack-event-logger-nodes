import { Node, VALUE } from '@newspack-nodes/runtime';

/**
 * `performance/view` — owns the Performance Dashboard view model.
 *
 * Holds four data slices — `overview`, `urls`, `urlDetail`, `requestDetail` —
 * each a `{ data, loading, error }` (urls also carries `total`), plus a
 * `lastRefresh` browser-clock stamp. `fill()` routes the command node's
 * slice-tagged controls by `slice`:
 * - `loading` → that slice's `loading:true`, `error:null`; others untouched.
 * - `result` → store the slice's data, clear loading/error, stamp `lastRefresh`.
 * - `error`  → set that slice's `error` + clear `loading`; prior data and the
 *   OTHER slices are preserved (per-slice error isolation).
 * - `clear`  → reset `urlDetail`/`requestDetail` to its empty shape.
 *
 * It also owns the stateful `urlDetail` incremental merge + `last_modified`
 * dedup — moved verbatim from the orchestrator's `mergeUrlDetail`
 * (PerformanceDashboard.js): an `initial` result replaces; a non-initial result
 * with an unchanged `last_modified` is a no-op (skip republish), otherwise its
 * new requests are deduped by rid, merged newest-first, and capped at 500.
 *
 * Every change publishes via `setState('view', model)`, consumed by
 * `useNodeState('performance/view','view')`. This is a low-frequency
 * poll/selection model — no per-message React concern like the request stream.
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
		this._publish();
	}

	fill( message ) {
		const v = message[ VALUE ];
		if ( ! v || ! v.action ) {
			return;
		}
		if ( 'loading' === v.action ) {
			this.model[ v.slice ] = {
				...this.model[ v.slice ],
				loading: true,
				error: null,
			};
			this._publish();
		} else if ( 'result' === v.action ) {
			this._applyResult( v );
		} else if ( 'error' === v.action ) {
			this.model[ v.slice ] = {
				...this.model[ v.slice ],
				loading: false,
				error: v.error,
			};
			this._publish();
		} else if ( 'clear' === v.action ) {
			this._clear( v.slice );
			this._publish();
		}
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
