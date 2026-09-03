/**
 * Declares the current-request overlay tab's one slice view,
 * `currentrequest:view`.
 *
 * `registerSliceViews` builds the class from the declaration and merges it into
 * `CommandInterpreterNode.includeNodes`, the type→class table `resolveClass`
 * reads. The node graph is one per-page singleton every inlined bundle shares,
 * while that table is a static per bundle, so the tab wires the exported CLASS
 * and never the registered name: `mountExospine` reuses whatever
 * `_command_interpreter` is already up, which is often one another bundle
 * built.
 */

import { registerSliceViews } from '@newspack-nodes/shared/nodes/slice-view-node';

/** The view classes; `CurrentRequestTab` hands one to `addSliceFetcher`. */
export const views = registerSliceViews( {
	/**
	 * `currentrequest:view` — THIS request's own stored record.
	 *
	 * The `performance` CI's `request_detail` verb answers a PHP array, so the
	 * payload arrives decoded and this declaration sets no `json`. Each reply
	 * replaces the record whole rather than merging into it: the verb answers
	 * with the complete body every time, and `flame_data` — written after the
	 * record, which is what the tab's extra ticks wait for — comes inside it.
	 *
	 * A worker writes the record moments after the page rendered, so the first
	 * few asks find nothing and `request_detail` throws. That reply is a
	 * TM_ERROR the base folds into `error`, leaving `request` null, which the
	 * tab renders as "still processing". `error` is declared so the failure
	 * lands in the model and the next good reply clears it; the tab prints no
	 * error of its own, because a pending write and a refused session are the
	 * same wait and the poll retries both. The model declares no `loading`
	 * either: the tab's "Loading…" is `undefined === model`, before the
	 * poll mounts this node.
	 *
	 * A payload that is not an object returns null, keeping the record already
	 * on screen instead of blanking a working tab.
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
