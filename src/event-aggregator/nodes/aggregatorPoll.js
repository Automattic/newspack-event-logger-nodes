/**
 * `aggregator:poll` — the command-out node that owns the aggregator status
 * traffic, behind an injectable command-client seam.
 *
 * `poll()` sends `{ to:'aggregator', verb:'status' }` (the same command the old
 * AggregatorStatus fetched directly), unwraps the reply, and emits it as a
 * TM_STRUCT `{ action:'status', status, now }` to its sink — the exospine CI —
 * stamped with TO=target (the router peels TO and delivers to `aggregator:view`).
 * `now` is the response Message's TIMESTAMP — the hub's clock when it built the
 * snapshot, which the view drives "ago" off (matching the old `serverNow`).
 * Failures surface as a TM_STRUCT `{ action:'error', error }` control so the view
 * can show them — never swallowed.
 *
 * The interval timer lives in the HOOK, not here — this node is just the
 * transport boundary (mirroring how workerStatusPoll owns the dump_metadata fire
 * while its hook owns the interval). The shared CommandClient is reached ONLY
 * through the `client` seam, lazily defaulted to `getCommandClient()`; tests
 * inject a fake.
 */

import {
	Node,
	TIMESTAMP,
	TO,
	VALUE,
	TYPE,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import { getCommandClient } from '../../shared/utils/commandClient';
import unwrapCommandResponse from '../../shared/utils/unwrapCommandResponse';

class AggregatorPollNode extends Node {
	constructor( client ) {
		super();
		this._client = client;
		this._closed = false;
	}

	// Send the status command, unwrap, and emit the snapshot to the sink. The raw
	// reply's TIMESTAMP rides along as `now` (the hub's serve clock). On failure
	// emit an error control instead (matches AggregatorStatus.fetchStatus' catch).
	async poll() {
		const client = this._client || getCommandClient();
		try {
			const message = await client.send( {
				to: 'aggregator',
				verb: 'status',
			} );
			const status = unwrapCommandResponse( message ) || {};
			const now = Array.isArray( message ) ? message[ TIMESTAMP ] : null;
			this._emit( { action: 'status', status, now } );
		} catch ( err ) {
			this._emit( {
				action: 'error',
				error: err.message || 'Failed to fetch status',
			} );
		}
	}

	// Tear down: a send() resolving/rejecting after this drops its emit so we
	// never fill a detached sink post-unmount. Mirrors workerStatusPoll.close().
	close() {
		this._closed = true;
	}

	_emit( value ) {
		// Checked after the await in poll(): swallow late replies.
		if ( this._closed || ! this.sink ) {
			return;
		}
		const out = newMessage();
		out[ TYPE ] = TM_STRUCT;
		// Rule #2: stamp TO=target so the exospine router routes it (→ view).
		out[ TO ] = this.target;
		out[ VALUE ] = value;
		this.sink.fill( out );
	}
}

/**
 * Create and register the Aggregator Status poll node.
 *
 * @param {string} name                 Node name.
 * @param {Object} [opts]               Options.
 * @param {Object} [opts.commandClient] Injectable command-client seam (send);
 *                                      defaults to the shared CommandClient.
 * @return {AggregatorPollNode} The poll node.
 */
export function createAggregatorPoll( name, opts = {} ) {
	const node = new AggregatorPollNode( opts.commandClient );
	node.setName( name );
	return node;
}
