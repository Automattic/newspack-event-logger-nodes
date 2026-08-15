import { SliceViewNode } from '@newspack-nodes/shared/nodes/slice-view-node';

/**
 * `hookcatalog:view` — the registered-hook taxonomy behind the hook picker.
 *
 * The modal has no error UI of its own, so a refusal must not be the end of the
 * story: polled, a refused catalog is one tick that published nothing and the
 * next tick fills the picker.
 */
export class HookCatalogViewNode extends SliceViewNode {
	/**
	 * @return {Object} Empty render model.
	 */
	emptySlice() {
		return { hooksByCategory: null, descriptions: {}, error: null };
	}

	/**
	 * Keep the last good taxonomy unless the reply carries one.
	 *
	 * @param {Object} payload The decoded `hooks_registered` body.
	 * @return {?Object} The render model, or null to keep the prior slice.
	 */
	_parse( payload ) {
		if ( ! payload || 'object' !== typeof payload.hooks_by_category ) {
			return null;
		}
		return {
			hooksByCategory: payload.hooks_by_category,
			descriptions: payload.category_descriptions ?? {},
			error: null,
		};
	}
}
