/**
 * The current-request tab's slice view, declared rather than subclassed.
 */

import { registerSliceViews } from '@newspack-nodes/shared/nodes/slice-view-node';

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = registerSliceViews( {
	/**
	 * `currentrequest:view` — THIS request's own stored record.
	 *
	 * A worker writes the record moments after the page rendered, so the first
	 * few asks legitimately answer "nothing yet". That is not a failure and
	 * must not blank the tab: returning null keeps whatever is on screen until
	 * a record arrives.
	 */
	CurrentRequestView: {
		description:
			"Owns this request's own stored record for the overlay tab.",
		empty: { request: null, error: null },
		parse: ( payload ) =>
			payload && 'object' === typeof payload
				? { request: payload }
				: null,
	},
} );
