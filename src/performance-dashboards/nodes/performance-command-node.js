/**
 * `performance:command` — the slice-tagging command-builder Node for the
 * Performance Dashboard.
 *
 * Post-migration to substrate-canonical wiring this Node does NOT own the
 * network — `_http` (HttpOutNode) is the transport boundary. Each fetch* method:
 *
 *  - Emits a `{action:'loading', slice}` TM_STRUCT control through `sink`
 *    (the exospine interpreter), stamped `TO = target` (→ `performance:view`) so the
 *    router peels the head and delivers it to the view's `fill()`. The view
 *    uses the slice tag to flip its loading flag for that slice.
 *  - Registers a pending entry `{slice, initial?}` on `performance:view`'s
 *    `pending` Map keyed by `message[ID]` so when the server's reply pivots
 *    back via TO=FROM the view can apply the data to the registered slice.
 *  - Builds a TM_COMMAND (FROM=`performance:view`, TO=`_http/performance`,
 *    ID, VALUE={name,arguments,payload}) and fills it into `sink`. The router
 *    peels `_http`; HttpOutNode POSTs; the server pivots the reply TO=FROM (=
 *    `performance:view`); the router peels `performance:view`; the view's
 *    `fill()` matches the ID against `pending` and applies the result.
 *
 * `resolveRequest` and `fetchUrlBreakdown` return Promises (they don't update
 * a view slice — `resolveRequest` drives navigation; `fetchUrlBreakdown`
 * returns a single dimensional series). They register a `resolveOnly` pending
 * entry that the view's reply path resolves (transforming the payload first,
 * for breakdown) or rejects on TM_ERROR.
 *
 * Validation failures (invalid hash / partition / request id) emit an error
 * control and skip BOTH sending and the pending registration — no network
 * call, no pending entry to dangle. `onError` fires for the validation Error
 * so the global error-toast surface still sees it.
 *
 * `close()` is a cancel guard: it flips a flag so any post-unmount call is a
 * no-op (no emit, no command, no pending).
 */

import {
	Node,
	VALUE,
	TO,
	FROM,
	ID,
	TYPE,
	TM_STRUCT,
	TM_COMMAND,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';

// URL hash: lowercase hex.
const isValidHash = ( h ) => 'string' === typeof h && /^[a-f0-9]+$/.test( h );
// Request id: alphanumeric plus dash/underscore.
const isValidRequestId = ( r ) =>
	'string' === typeof r && /^[a-zA-Z0-9_-]+$/.test( r );
// Partition: non-negative integer.
const isValidPartition = ( p ) => Number.isInteger( p ) && p >= 0;

// Monotonic per-process ID counter — message[ID] correlates a reply to a
// pending entry on the view.
let nextOpId = 0;
function makeOpId() {
	nextOpId += 1;
	return `performance-op-${ Date.now() }-${ nextOpId }`;
}

// `_http/performance` — the verb routes via the substrate's HTTP boundary to
// the request-scope `performance` service CI.
const HTTP_TO = '_http/performance';

class PerformanceCommandNode extends Node {
	// Command-builder source: its fetch* methods are called directly by the hook
	// to mint control messages; it has no fill() entry — no input port.
	static nodeSchema() {
		return {
			category: 'Hidden',
			description:
				'Builds Performance Dashboard commands from hook calls.',
			arguments: [],
			commands: [],
			accepts_fill: false,
		};
	}

	constructor( onError, viewName ) {
		super();
		this._onError = onError;
		this._viewName = viewName;
		this._closed = false;
	}

	// The hook sets `viewName` BEFORE wiring the target so it's already populated
	// when the first fetch fires. Also accept it as a setter for symmetry with
	// other programmatic-deps Nodes.
	set viewName( name ) {
		this._viewName = name;
	}
	get viewName() {
		return this._viewName;
	}

	// Overview (always with category time series); optional server + breakdowns.
	fetchOverview( server = '', dims = [] ) {
		if ( this._closed ) {
			return;
		}
		this._emitControl( { action: 'loading', slice: 'overview' } );
		const payload = { categories: true };
		if ( server ) {
			payload.server = server;
		}
		if ( Array.isArray( dims ) && dims.length > 0 ) {
			payload.breakdown = dims.join( ',' );
		}
		this._sendVerb( 'overview', payload, { slice: 'overview' } );
	}

	// URL leaderboard; forwards only the present query params + the limit.
	fetchUrls( params = {} ) {
		if ( this._closed ) {
			return;
		}
		this._emitControl( { action: 'loading', slice: 'urls' } );
		const payload = { limit: 100 };
		for ( const key of [ 'search', 'sort', 'order', 'offset', 'server' ] ) {
			if ( params[ key ] ) {
				payload[ key ] = params[ key ];
			}
		}
		this._sendVerb( 'urls', payload, { slice: 'urls' } );
	}

	// URL detail. `opts.initial` controls (a) whether to emit a loading control
	// (selection passes initial:true; auto-refresh omits it and stays SILENT so
	// an unchanged no-op never sticks loading:true), and (b) is recorded in the
	// pending entry so the view knows replace-vs-merge.
	fetchUrlDetail( hash, opts = {} ) {
		if ( this._closed ) {
			return;
		}
		if ( opts.initial ) {
			this._emitControl( { action: 'loading', slice: 'urlDetail' } );
		}
		if ( ! isValidHash( hash ) ) {
			this._emitControl( {
				action: 'error',
				slice: 'urlDetail',
				error: 'Invalid URL hash format',
			} );
			this._onError?.( new Error( 'Invalid URL hash format' ) );
			return;
		}
		const payload = { hash };
		if ( opts.categories ) {
			payload.categories = true;
		}
		if ( opts.breakdown ) {
			payload.breakdown = opts.breakdown;
		}
		this._sendVerb( 'url_detail', payload, {
			slice: 'urlDetail',
			initial: !! opts.initial,
		} );
	}

	// Request detail. Validates rid then partition before sending.
	fetchRequestDetail( rid, partition ) {
		if ( this._closed ) {
			return;
		}
		this._emitControl( { action: 'loading', slice: 'requestDetail' } );
		if ( ! isValidRequestId( rid ) ) {
			this._emitControl( {
				action: 'error',
				slice: 'requestDetail',
				error: 'Invalid request ID format',
			} );
			this._onError?.( new Error( 'Invalid request ID format' ) );
			return;
		}
		if ( ! isValidPartition( partition ) ) {
			this._emitControl( {
				action: 'error',
				slice: 'requestDetail',
				error: 'Invalid partition number',
			} );
			this._onError?.( new Error( 'Invalid partition number' ) );
			return;
		}
		this._sendVerb(
			'request_detail',
			{ rid, partition },
			{ slice: 'requestDetail' }
		);
	}

	// Resolve a request id to its URL/partition for navigation. Returns a Promise
	// the view's reply path resolves; null on rejection (resolveOnly entries
	// don't update view-model slices). Does NOT emit a loading control —
	// navigation is silent.
	resolveRequest( rid ) {
		if ( this._closed ) {
			return Promise.resolve( null );
		}
		return new Promise( ( resolve, reject ) => {
			const opId = makeOpId();
			if (
				! this._registerPending( opId, {
					resolveOnly: true,
					resolve,
					reject,
				} )
			) {
				resolve( null );
				return;
			}
			this._sendCommand( 'request_search', { rid }, opId );
		} ).catch( () => null );
	}

	// Per-URL dimensional breakdown. Returns breakdown_time_series (null on
	// invalid hash / throw / absent); does NOT emit. The view's reply path
	// applies the transform.
	fetchUrlBreakdown( hash, breakdown ) {
		if ( this._closed ) {
			return Promise.resolve( null );
		}
		if ( ! isValidHash( hash ) ) {
			return Promise.resolve( null );
		}
		return new Promise( ( resolve, reject ) => {
			const opId = makeOpId();
			const transform = ( data ) =>
				( data && data.breakdown_time_series ) || null;
			if (
				! this._registerPending( opId, {
					resolveOnly: true,
					resolve,
					reject,
					transform,
				} )
			) {
				resolve( null );
				return;
			}
			this._sendCommand( 'url_detail', { hash, breakdown }, opId );
		} ).catch( ( err ) => {
			this._onError?.( err );
			return null;
		} );
	}

	// Tear down: a fetch after this drops every emission so a post-unmount call
	// neither emits nor leaks a pending entry on a stale view.
	close() {
		this._closed = true;
	}

	// Send a verb + register slice-tagged pending in one step.
	_sendVerb( verb, payload, pendingEntry ) {
		const opId = makeOpId();
		if ( ! this._registerPending( opId, pendingEntry ) ) {
			return;
		}
		this._sendCommand( verb, payload, opId );
	}

	// Register a pending entry on the view's `pending` Map. Returns false if the
	// view isn't reachable (mid-unmount); the caller skips the send.
	_registerPending( opId, entry ) {
		const view = this._viewNode();
		if ( ! view || ! view.pending ) {
			return false;
		}
		view.pending.set( opId, entry );
		return true;
	}

	// Resolve the view node by name. Looking it up each call (instead of caching
	// a reference) means a Core.reset() between tests doesn't leave a stale ref.
	_viewNode() {
		if ( ! this._viewName ) {
			return null;
		}
		return Core.node( this._viewName );
	}

	// Build a TM_COMMAND addressed at the `performance` CI through `_http`.
	// FROM=view so the server's reply pivot lands on the view (the pending Map
	// owner). Send through `sink` (the interpreter) so the router peels TO.
	_sendCommand( verb, payload, opId ) {
		if ( this._closed || ! this.sink ) {
			return;
		}
		const m = newMessage();
		m[ TYPE ] = TM_COMMAND;
		m[ FROM ] = this._viewName;
		m[ TO ] = HTTP_TO;
		m[ ID ] = opId;
		m[ VALUE ] = { name: verb, arguments: '', payload };
		this.sink.fill( m );
	}

	// Stamp TO=target (the view) and forward through sink (the interpreter).
	_emitControl( value ) {
		if ( this._closed || ! this.sink ) {
			return;
		}
		const out = newMessage();
		out[ TYPE ] = TM_STRUCT;
		out[ TO ] = this.target;
		out[ VALUE ] = value;
		this.sink.fill( out );
	}
}

/**
 * Create and register the Performance Dashboard command-builder node.
 *
 * @param {string}   name            Node name.
 * @param {Object}   [opts]          Options.
 * @param {Function} [opts.onError]  Global error-toast seam; called on validation
 *                                   failures (same contract as legacy onError).
 * @param {string}   [opts.viewName] View node name where pending entries are
 *                                   registered. Defaults to `performance:view`;
 *                                   tests override.
 * @return {PerformanceCommandNode} The command-builder node.
 */
export function createPerformanceCommand( name, opts = {} ) {
	const node = new PerformanceCommandNode(
		opts.onError,
		opts.viewName || 'performance:view'
	);
	node.setName( name );
	return node;
}
