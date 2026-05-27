import { Node, VALUE } from '@newspack-nodes/runtime';

/**
 * `hookcatalog:view` — owns the Performance Logger hook-catalog view model.
 *
 * `fill()` accepts the two TM_STRUCT controls the command node emits:
 * - `{ action:'loading' }`: flips `loading` true, preserving the prior
 *   `hooksByCategory` (so a re-open keeps the last map under the spinner).
 * - `{ action:'catalog', hooksByCategory }`: stores the map and clears `loading`.
 *
 * Every change publishes via `setState('view', model)`, consumed by
 * `useNodeState('hookcatalog:view','view')` (read on the modal's behalf inside
 * useHookCatalogGraph). This is a fire-on-open one-shot, not a stream, so there's
 * no per-message React concern. View terminal (sink=ci, setState); matches
 * servers:view. No timers → no close().
 */
class HookCatalogViewNode extends Node {
	constructor() {
		super();
		this.model = { hooksByCategory: {}, loading: false };
		this._publish();
	}

	fill( message ) {
		const value = message[ VALUE ];
		if ( ! value || ! value.action ) {
			return;
		}
		if ( 'loading' === value.action ) {
			this.model = { ...this.model, loading: true };
			this._publish();
		} else if ( 'catalog' === value.action ) {
			this.model = {
				...this.model,
				hooksByCategory: value.hooksByCategory,
				loading: false,
			};
			this._publish();
		}
	}

	_publish() {
		this.setState( 'view', { ...this.model } );
	}
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
