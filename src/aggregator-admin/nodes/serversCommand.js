/**
 * `servers/command` — the command-out node that owns the Configured-Servers admin
 * traffic, behind an injectable command-client seam.
 *
 * `list()` sends `{ to:'servers', verb:'list' }` (the `servers list` verb's
 * `{ id:public_shape }` map), unwraps the reply, and emits it as a TM_STRUCT
 * `{ action:'servers', servers }` to its sink (→ `servers/view`). Failures surface
 * as a TM_STRUCT `{ action:'error', error }` control so the view can show them —
 * never swallowed.
 *
 * The mutation methods `add(fields)/update(id,partial)/remove(id)/test(id)` delegate
 * to the api.js wrappers with the node's client and RETURN their result (they do
 * NOT emit). The hook awaits the mutation then re-`list()`s to refresh the table —
 * this is what replaces the old jQuery `window.location.reload()`.
 *
 * The shared CommandClient is reached ONLY through the `client` seam, lazily
 * defaulted to `getCommandClient()`; tests inject a fake. Mirrors aggregator/poll +
 * workerstatus/poll (transport boundary + close() in-flight cancel).
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
import { addServer, updateServer, removeServer, testServer } from '../api';

class ServersCommandNode extends Node {
	constructor( client ) {
		super();
		this._client = client;
		this._closed = false;
	}

	// The resolved command client — the injected seam or the shared singleton.
	_resolveClient() {
		return this._client || getCommandClient();
	}

	// Send the list command, unwrap the `{ id:public_shape }` map, and emit the
	// servers control to the sink. On failure emit an error control instead.
	async list() {
		try {
			const message = await this._resolveClient().send( {
				to: 'servers',
				verb: 'list',
			} );
			const servers = unwrapCommandResponse( message ) || {};
			this._emit( { action: 'servers', servers } );
		} catch ( err ) {
			this._emit( {
				action: 'error',
				error: err.message || 'Failed to load servers',
			} );
		}
	}

	// Delegate the four CRUD verbs to the api.js wrappers with the node's client.
	// They return the unwrapped result (or reject); the hook re-lists on success
	// and surfaces a rejection into the view model.
	add( fields ) {
		return addServer( this._resolveClient(), fields );
	}

	update( id, partial ) {
		return updateServer( this._resolveClient(), id, partial );
	}

	remove( id ) {
		return removeServer( this._resolveClient(), id );
	}

	test( id ) {
		return testServer( this._resolveClient(), id );
	}

	// Tear down: a list() resolving/rejecting after this drops its emit so we
	// never fill a detached sink post-unmount. Mirrors aggregator/poll.close().
	close() {
		this._closed = true;
	}

	_emit( value ) {
		// Checked after the await in list(): swallow late replies.
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
 * Create and register the Configured-Servers command-out node.
 *
 * @param {string} name                 Node name.
 * @param {Object} [opts]               Options.
 * @param {Object} [opts.commandClient] Injectable command-client seam (send);
 *                                      defaults to the shared CommandClient.
 * @return {ServersCommandNode} The command node.
 */
export function createServersCommand( name, opts = {} ) {
	const node = new ServersCommandNode( opts.commandClient );
	node.setName( name );
	return node;
}
