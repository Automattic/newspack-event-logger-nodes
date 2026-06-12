import { Node, ID, TYPE, VALUE, TM_ERROR } from '@newspack-nodes/runtime';

/**
 * `servers:view` — owns the Configured-Servers admin view model.
 *
 * Post-migration to substrate-canonical wiring, `fill()` receives the raw reply
 * Messages HttpOutNode feeds back from POST /command: the router peels the reply's
 * TO (= `servers:view`, stamped from the outbound FROM by the server's reply
 * pivot) and delivers them here. VALUE is the `{ name, payload }` envelope.
 *
 * On a `list` reply the node turns the raw `{ server_id:{} }` map into the
 * render model — `servers` (Object.values → array), clears `loading` + `error`.
 * On TM_ERROR it surfaces the error string and clears loading; the prior
 * `servers` are preserved (a transient mutation failure shouldn't blank the
 * table). Non-list verb replies don't update the render model directly — the
 * hook awaits them via the `pending` Map (keyed by `message[ID]`), and the
 * hook chains a re-list on a successful mutation to refresh the table.
 *
 * Every model change publishes via `setState('view', model)`, consumed by
 * `useNodeState('servers:view','view')`. Mirrors aggregator:view.
 */
export class ServersViewNode extends Node {
	constructor() {
		super();
		this.model = {
			servers: null,
			loading: true,
			error: null,
		};
		// Hook-stamped ID → { resolve, reject }; resolved/rejected when the
		// matching reply lands here. Cleared on resolution.
		this.pending = new Map();
		this._publish();
	}

	fill( message ) {
		const value = message[ VALUE ];
		if ( ! value || 'object' !== typeof value ) {
			return;
		}
		const id = message[ ID ];
		const type = message[ TYPE ] || 0;
		const isError = 0 !== ( type & TM_ERROR );
		const name = value.name;
		const payload = value.payload;

		// Resolve / reject any pending promise the hook stashed under this ID.
		// Track whether we handled the message via the pending pivot — if so, the
		// caller is the error surface and we must NOT also paint a table-wide
		// banner (per-row test() probes catch their own failures locally).
		let pendingMatched = false;
		if ( id && this.pending.has( id ) ) {
			const { resolve, reject } = this.pending.get( id );
			this.pending.delete( id );
			pendingMatched = true;
			if ( isError ) {
				reject( new Error( _errorMessage( payload ) ) );
			} else {
				resolve( payload );
			}
		}

		// View-model updates: list replies refresh the table; un-correlated
		// errors (initial list, broadcasts) surface globally. Pending-matched
		// errors are owned by the caller's catch — see comment above.
		if ( isError && ! pendingMatched ) {
			this._applyError( payload );
			this._publish();
			return;
		}
		if ( ! isError && 'list' === name ) {
			this._applyServers( payload );
			this._publish();
		}
	}

	// Store the error + clear loading; keep prior servers.
	_applyError( payload ) {
		this.model = {
			...this.model,
			error: _errorMessage( payload ),
			loading: false,
		};
	}

	_publish() {
		this.setState( 'view', this.model );
	}

	// Turn the raw `{ id:public_shape }` map into the render model.
	_applyServers( servers ) {
		this.model = {
			...this.model,
			servers: Object.values( servers || {} ),
			loading: false,
			error: null,
		};
	}

	// Reject every in-flight pending promise before the node is removed, so a
	// graph teardown / Reset-Graph reinit doesn't strand a caller awaiting a
	// reply that will now never land on this (removed) node — the reply pivots
	// back by name to the freshly-rebuilt view, whose `pending` is empty.
	removeNode() {
		for ( const { reject } of this.pending.values() ) {
			if ( 'function' === typeof reject ) {
				reject( new Error( 'View removed before reply' ) );
			}
		}
		this.pending.clear();
		super.removeNode();
	}
	// Consume-and-publish view-model terminal: fill() mutates state + publishes
	// via setState, never forwards — no output port.
	static nodeSchema() {
		return {
			category: 'Hidden',
			description: 'Owns the Configured-Servers admin view model.',
			arguments: [],
			commands: [],
			has_target: false,
		};
	}
}

// Coerce a TM_ERROR payload (string / { message } / anything else) to a
// human-readable string for the Error / view-model error field.
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
