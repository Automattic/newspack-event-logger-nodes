/**
 * `performance/command` — the multi-verb command-out node for the Performance
 * Dashboard, behind an injectable command-client seam.
 *
 * One method per data slice (`fetchOverview`, `fetchUrls`, `fetchUrlDetail`,
 * `fetchRequestDetail`): each emits a synchronous `{ action:'loading', slice }`
 * control, then awaits `client.send({ to:'performance', verb, payload })`, then
 * emits `{ action:'result', slice, data }` — or `{ action:'error', slice, error }`
 * on a throw OR an invalid argument (in which case nothing is sent). The
 * slice-tagged controls go to the sink (→ `performance/view`), which keys its
 * model off `slice`. The verbs/args/validators migrated verbatim from
 * `usePerformanceApi`.
 *
 * `resolveRequest` is the exception: it RETURNS the unwrapped reply (or null on
 * throw) and does NOT emit — it drives navigation (URL → request selection), not
 * a display slice.
 *
 * Mirrors aggregatorPoll/hookCatalogCommand: the shared CommandClient is reached
 * ONLY through the `client` seam, lazily defaulted to `getCommandClient()`; a
 * `close()` cancel guard drops any reply that resolves after unmount so we never
 * fill a detached sink. The interval/timing lives in the HOOK, not here — this
 * node is just the command boundary.
 */

import {
	Node,
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import { getCommandClient } from '../../shared/utils/commandClient';
import unwrapCommandResponse from '../../shared/utils/unwrapCommandResponse';

// URL hash: lowercase hex (moved from usePerformanceApi).
const isValidHash = ( h ) => typeof h === 'string' && /^[a-f0-9]+$/.test( h );
// Request id: alphanumeric plus dash/underscore.
const isValidRequestId = ( r ) =>
	typeof r === 'string' && /^[a-zA-Z0-9_-]+$/.test( r );
// Partition: non-negative integer.
const isValidPartition = ( p ) => Number.isInteger( p ) && p >= 0;

class PerformanceCommandNode extends Node {
	constructor( client ) {
		super();
		this._client = client;
		this._closed = false;
	}

	// Overview (always with category time series); optional server + breakdowns.
	async fetchOverview( server = '', dims = [] ) {
		this._emit( { action: 'loading', slice: 'overview' } );
		const payload = { categories: true };
		if ( server ) {
			payload.server = server;
		}
		if ( Array.isArray( dims ) && dims.length > 0 ) {
			payload.breakdown = dims.join( ',' );
		}
		try {
			const data = await this._send( 'overview', payload );
			this._emit( { action: 'result', slice: 'overview', data } );
		} catch ( err ) {
			this._error(
				'overview',
				err.message || 'Failed to fetch overview'
			);
		}
	}

	// URL leaderboard; forwards only the present query params + the limit. The
	// result carries the full { data, total, limit, offset } reply.
	async fetchUrls( params = {} ) {
		this._emit( { action: 'loading', slice: 'urls' } );
		const payload = { limit: 100 };
		for ( const key of [ 'search', 'sort', 'order', 'offset', 'server' ] ) {
			if ( params[ key ] ) {
				payload[ key ] = params[ key ];
			}
		}
		try {
			const data = await this._send( 'urls', payload );
			this._emit( { action: 'result', slice: 'urls', data } );
		} catch ( err ) {
			this._error( 'urls', err.message || 'Failed to fetch URLs' );
		}
	}

	// URL detail. `opts.initial` threads through to the result so the view knows
	// replace-vs-merge (orchestrator sets it on selection, omits on auto-refresh).
	async fetchUrlDetail( hash, opts = {} ) {
		this._emit( { action: 'loading', slice: 'urlDetail' } );
		if ( ! isValidHash( hash ) ) {
			this._error( 'urlDetail', 'Invalid URL hash format' );
			return;
		}
		const payload = { hash };
		if ( opts.categories ) {
			payload.categories = true;
		}
		if ( opts.breakdown ) {
			payload.breakdown = opts.breakdown;
		}
		try {
			const data = await this._send( 'url_detail', payload );
			this._emit( {
				action: 'result',
				slice: 'urlDetail',
				data,
				initial: !! opts.initial,
			} );
		} catch ( err ) {
			this._error(
				'urlDetail',
				err.message || 'Failed to fetch URL detail'
			);
		}
	}

	// Request detail. Validates rid then partition before sending.
	async fetchRequestDetail( rid, partition ) {
		this._emit( { action: 'loading', slice: 'requestDetail' } );
		if ( ! isValidRequestId( rid ) ) {
			this._error( 'requestDetail', 'Invalid request ID format' );
			return;
		}
		if ( ! isValidPartition( partition ) ) {
			this._error( 'requestDetail', 'Invalid partition number' );
			return;
		}
		try {
			const data = await this._send( 'request_detail', {
				rid,
				partition,
			} );
			this._emit( { action: 'result', slice: 'requestDetail', data } );
		} catch ( err ) {
			this._error(
				'requestDetail',
				err.message || 'Failed to fetch request detail'
			);
		}
	}

	// Resolve a request id to its URL/partition for navigation. RETURNS the
	// unwrapped reply (null on throw); does NOT emit — not a display slice.
	async resolveRequest( rid ) {
		try {
			return await this._send( 'request_search', { rid } );
		} catch ( err ) {
			return null;
		}
	}

	// Tear down: a send() resolving/rejecting after this drops its emit so we
	// never fill a detached sink post-unmount.
	close() {
		this._closed = true;
	}

	// Send a Performance_CI verb and unwrap the reply.
	async _send( verb, payload ) {
		const client = this._client || getCommandClient();
		const message = await client.send( {
			to: 'performance',
			verb,
			payload,
		} );
		return unwrapCommandResponse( message );
	}

	_error( slice, error ) {
		this._emit( { action: 'error', slice, error } );
	}

	_emit( value ) {
		// Checked after the await: swallow late replies on a detached sink.
		if ( this._closed || ! this.sink ) {
			return;
		}
		const out = newMessage();
		out[ TYPE ] = TM_STRUCT;
		out[ VALUE ] = value;
		this.sink.fill( out );
	}
}

/**
 * Create and register the Performance Dashboard command-out node.
 *
 * @param {string} name                 Node name.
 * @param {Object} [opts]               Options.
 * @param {Object} [opts.commandClient] Injectable command-client seam (send);
 *                                      defaults to the shared CommandClient.
 * @return {PerformanceCommandNode} The command node.
 */
export function createPerformanceCommand( name, opts = {} ) {
	const node = new PerformanceCommandNode( opts.commandClient );
	node.setName( name );
	return node;
}
