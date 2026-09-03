/**
 * The settings bundle's slice views, declared rather than subclassed.
 *
 * `registerSliceViews` builds each class and merges it into
 * `CommandInterpreterNode.includeNodes`, the name table the debug console's
 * `make_node` and `help` read. That table is a static per bundle, so
 * `useHookCatalogGraph` reaches the class through the `views` export rather
 * than by name.
 */

import { registerSliceViews } from '@newspack-nodes/shared/nodes/slice-view-node';

/** The view classes, handed to `addSliceFetcher` as its `viewClass`. */
export const views = registerSliceViews( {
	/**
	 * `hookcatalog:view` — the registered-hook taxonomy behind the hook picker.
	 *
	 * The `performance` CI's `hooks_registered` verb answers a PHP array, so
	 * the payload arrives decoded and this declaration sets no `json`. The
	 * picker needs two of its four fields: the category-to-hooks map it lists,
	 * and the one-liners it prints beside each category name. Those
	 * descriptions fall back to an empty map because the modal subscripts them
	 * per category as it renders.
	 *
	 * `hooksByCategory` starts null, the only mark of a taxonomy that has not
	 * arrived — `useHookCatalogGraph` derives the picker's spinner from it, so
	 * this model declares no `loading` of its own. An empty map is an answer
	 * like any other and settles the spinner.
	 *
	 * `error` serves that same spinner, not a display: the modal has no error
	 * UI, and a refusal leaves `hooksByCategory` null, so the field is what
	 * stops the picker spinning for as long as the refusals last. The poll is
	 * what makes a refusal survivable — one tick publishes nothing and the
	 * next fills the picker — so a payload whose `hooks_by_category` is not an
	 * object returns null and keeps the last good taxonomy on screen.
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
