/**
 * The settings tree's slice view.
 */

import { registerSliceViews } from '@newspack-nodes/shared/nodes/slice-view-node';

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = registerSliceViews( {
	/**
	 * `hookcatalog:view` — the registered-hook taxonomy behind the hook picker.
	 *
	 * The modal has no error UI of its own, so a refusal must not be the end of
	 * the story: polled, a refused catalog is one tick that published nothing
	 * and the next tick fills the picker. Returning null keeps the last good
	 * taxonomy.
	 */
	HookCatalogView: {
		empty: { hooksByCategory: null, descriptions: {}, error: null },
		parse: ( payload ) =>
			payload && 'object' === typeof payload.hooks_by_category
				? {
						hooksByCategory: payload.hooks_by_category,
						descriptions: payload.category_descriptions ?? {},
						error: null,
				  }
				: null,
	},
} );
