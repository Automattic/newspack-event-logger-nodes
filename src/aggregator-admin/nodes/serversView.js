import { Node, VALUE } from '@newspack-nodes/runtime';

/**
 * `servers:view` — owns the Configured-Servers admin view model.
 *
 * `fill()` accepts the two TM_STRUCT controls the command node emits:
 * - `{ action:'servers', servers }`: the raw `{ server_id:{} }` map (the
 *   `servers list` verb's return). The node turns it into the render model —
 *   `servers` (Object.values → array), clears `loading` + `error`.
 * - `{ action:'error', error }`: stores the error and clears `loading`; the prior
 *   `servers` are preserved (a transient list/mutation failure shouldn't blank
 *   the table).
 *
 * Every change publishes via `setState('view', model)`, consumed by
 * `useNodeState('servers:view','view')` — admin CRUD is low-frequency, so there's
 * no per-message React concern. Mirrors aggregator:view.
 */
class ServersViewNode extends Node {
	constructor() {
		super();
		this.model = {
			servers: null,
			loading: true,
			error: null,
		};
		this._publish();
	}

	fill( message ) {
		const value = message[ VALUE ];
		if ( ! value || ! value.action ) {
			return;
		}
		if ( 'servers' === value.action ) {
			this._applyServers( value );
			this._publish();
		} else if ( 'error' === value.action ) {
			this._applyError( value );
			this._publish();
		}
	}

	// Turn the raw `{ id:public_shape }` map into the render model.
	_applyServers( { servers } ) {
		this.model = {
			...this.model,
			servers: Object.values( servers || {} ),
			loading: false,
			error: null,
		};
	}

	// Store the error + clear loading; keep prior servers.
	_applyError( { error } ) {
		this.model = {
			...this.model,
			error: error || 'Failed to load servers',
			loading: false,
		};
	}

	_publish() {
		this.setState( 'view', this.model );
	}
}

/**
 * Create and register the Configured-Servers admin view-model node.
 *
 * @param {string} name Node name.
 * @return {ServersViewNode} The view-model node.
 */
export function createServersView( name ) {
	const node = new ServersViewNode();
	node.setName( name );
	return node;
}
