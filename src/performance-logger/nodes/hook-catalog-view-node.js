import { Node, ID, TYPE, VALUE, TM_ERROR } from '@newspack-nodes/runtime';

/**
 * `hookcatalog:view` — owns the Performance Logger hook-catalog view model.
 *
 * Post-migration to substrate-canonical wiring, `fill()` receives the raw reply
 * Messages HttpOutNode feeds back from POST /command: the router peels the reply's
 * TO (= `hookcatalog:view`, stamped from the outbound FROM by the server's
 * reply pivot) and delivers them here. VALUE is the `{ name, payload }`
 * envelope.
 *
 * On a `hooks_registered` reply the node extracts `hooks_by_category` from the
 * raw payload, clears loading + error. On TM_ERROR it surfaces the error
 * string and clears loading; the prior `hooksByCategory` is preserved (a
 * transient mutation failure shouldn't blank the modal). The node also matches
 * `message[ID]` against `pending` so the hook's Promise resolves / rejects.
 * Pending-matched errors do NOT pollute global view.error — the caller's catch
 * is the error surface for correlated failures.
 *
 * Every model change publishes via `setState('view', model)`, consumed by
 * `useNodeState('hookcatalog:view','view')`. Mirrors servers:view.
 */
export class HookCatalogViewNode extends Node {
	// Consume-and-publish view-model terminal: fill() mutates state + publishes
	// via setState, never forwards — no output port.
	static nodeSchema() {
		return {
			category: 'Hidden',
			description: 'Owns the Performance Logger hook-catalog view model.',
			arguments: [],
			commands: [],
			has_target: false,
		};
	}

	constructor() {
		super();
		this.model = {
			hooksByCategory: {},
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
		// Track whether we handled the message via the pending pivot — if so,
		// the caller is the error surface and we must NOT also paint a
		// view-level error banner (mirrors servers:view).
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

		// View-model updates: hooks_registered replies refresh the catalog;
		// un-correlated errors (broadcasts) surface globally. Pending-matched
		// errors are owned by the caller's catch — see comment above.
		if ( isError && ! pendingMatched ) {
			this._applyError( payload );
			this._publish();
			return;
		}
		if ( ! isError && 'hooks_registered' === name ) {
			this._applyCatalog( payload );
			this._publish();
		}
	}

	// Extract hooks_by_category from the raw payload into the render model.
	_applyCatalog( payload ) {
		const hooks =
			payload && 'object' === typeof payload && payload.hooks_by_category
				? payload.hooks_by_category
				: {};
		this.model = {
			...this.model,
			hooksByCategory: hooks,
			loading: false,
			error: null,
		};
	}

	// Store the error + clear loading; keep prior hooksByCategory.
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

/**
 * Create and register the hook-catalog view-model node.
 *
 * @param {string} name Node name.
 * @return {HookCatalogViewNode} The view-model node.
 */
export function createHookCatalogView( name ) {
	const node = new HookCatalogViewNode();
	node.setName( name );
	return node;
}
