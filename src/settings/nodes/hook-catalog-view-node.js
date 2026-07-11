import { Node, TYPE, VALUE, TM_ERROR } from '@newspack-nodes/runtime';
import {
	errorMessage,
	PendingReplies,
} from '@newspack-nodes/shared/pendingReplies';

/**
 * `hookcatalog:view` — owns the Performance Logger hook-catalog view model.
 *
 * Post-migration to substrate-canonical wiring, `fill()` receives the raw reply
 * Messages HttpOutNode feeds back from POST /command: the router peels the reply's
 * TO (= `hookcatalog:view`, stamped from the outbound FROM by the server's
 * TO=FROM reply) and delivers them here. VALUE is the `{ name, payload }`
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
	constructor() {
		super();
		this.model = {
			hooksByCategory: {},
			loading: true,
			error: null,
		};
		// Hook-stamped ID → resolver; settled when the matching reply lands.
		this.replies = new PendingReplies();
		this._publish();
	}

	fill( message ) {
		const value = message[ VALUE ];
		if ( ! value || 'object' !== typeof value ) {
			return;
		}
		const type = message[ TYPE ] || 0;
		const isError = 0 !== ( type & TM_ERROR );
		const name = value.name;
		const payload = value.payload;

		// Settle pending promise for this ID; if matched, caller owns error.
		const pendingMatched = this.replies.settle( message );

		// hooks_registered refreshes catalog; uncorrelated errors go global.
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

	// Store the error + clear loading; keep prior hooksByCategory.
	_applyError( payload ) {
		this.model = {
			...this.model,
			error: errorMessage( payload ),
			loading: false,
		};
	}

	_publish() {
		this.setState( 'view', this.model );
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
	// View-model terminal: fill() mutates state + publishes; never forwards.
	static nodeSchema() {
		return {
			category: 'Hidden',
			description: 'Owns the Performance Logger hook-catalog view model.',
			arguments: [],
			commands: [],
			has_target: false,
		};
	}
}
