/**
 * `hookcatalog:command` — the command-out node that owns the Performance Logger
 * hook-catalog traffic, behind an injectable command-client seam.
 *
 * `fetch()` is the entire networked surface of the Performance Logger settings
 * tree: it emits a synchronous TM_STRUCT `{ action:'loading' }` (so the spinner
 * shows immediately, like the old modal setting `loading=true` before the send),
 * sends `{ to:'performance', verb:'hooks_registered' }`, unwraps the reply, and
 * emits a TM_STRUCT `{ action:'catalog', hooksByCategory }` to its sink — the
 * exospine CI — stamped with TO=target (the router peels TO and delivers to
 * `hookcatalog:view`). A rejected send falls back to a `catalog` with an empty
 * map (the old modal `.catch(() => setHookCategories({}))` — there is no distinct
 * error state, so the view has one path and loading always clears).
 *
 * The fire-on-modal-open trigger lives in the HOOK, not here — this node is just
 * the transport boundary (mirroring how serversCommand owns the list fire while
 * its hook owns the trigger). The shared CommandClient is reached ONLY through
 * the `client` seam, lazily defaulted to `getCommandClient()`; tests inject a fake.
 */

import {
	Node,
	VALUE,
	TYPE,
	TO,
	TM_STRUCT,
	newMessage,
} from '@newspack-nodes/runtime';
import { getCommandClient } from '../../shared/utils/commandClient';
import unwrapCommandResponse from '../../shared/utils/unwrapCommandResponse';

class HookCatalogCommandNode extends Node {
	constructor( client ) {
		super();
		this._client = client;
		this._closed = false;
	}

	// Emit loading synchronously (before any await), then send the catalog command,
	// unwrap, and emit the map. On failure emit an empty catalog (matches the old
	// modal's .catch(() => setHookCategories({})), which still clears loading).
	async fetch() {
		this._emit( { action: 'loading' } );
		const client = this._client || getCommandClient();
		try {
			const message = await client.send( {
				to: 'performance',
				verb: 'hooks_registered',
			} );
			const data = unwrapCommandResponse( message );
			this._emit( {
				action: 'catalog',
				hooksByCategory: data?.hooks_by_category || {},
			} );
		} catch ( err ) {
			this._emit( { action: 'catalog', hooksByCategory: {} } );
		}
	}

	// Tear down: a send() resolving after this drops its emit so we never fill a
	// detached sink post-unmount. Mirrors serversCommand.close().
	close() {
		this._closed = true;
	}

	_emit( value ) {
		// Checked after the await in fetch(): swallow late replies.
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
 * Create and register the hook-catalog command node. Names use a colon, not a
 * slash: the exospine router peels TO on '/', so a '/' in a node name misroutes.
 *
 * @param {string} name                 Node name.
 * @param {Object} [opts]               Options.
 * @param {Object} [opts.commandClient] Injectable command-client seam (send);
 *                                      defaults to the shared CommandClient.
 * @return {HookCatalogCommandNode} The command node.
 */
export function createHookCatalogCommand( name, opts = {} ) {
	const node = new HookCatalogCommandNode( opts.commandClient );
	node.setName( name );
	return node;
}
