/**
 * The rules editor's slice view.
 */

import { registerSliceViews } from '@newspack-nodes/shared/nodes/slice-view-node';

/** The view classes, handed to `makeNode` — a name is per-bundle. */
export const views = registerSliceViews( {
	/**
	 * `rules:view` — the per-URL logging-ruleset editor's table.
	 *
	 * Only `list` replies reach here: every mutation owns its own
	 * `useCommandOnce` node and its answer lands there. The whole list is
	 * replaced rather than merged, because `list` always answers with the
	 * complete ruleset, so a rule deleted on the server has to disappear.
	 */
	RulesView: {
		description: 'Owns the per-URL logging-ruleset editor view model.',
		empty: { rules: [], loading: true, error: null },
		parse: ( payload ) => ( {
			rules:
				payload && Array.isArray( payload.rules ) ? payload.rules : [],
			loading: false,
			error: null,
		} ),
	},
} );
