/**
 * Declares the rules editor's one slice view, `rules:view`.
 *
 * `registerSliceViews` builds the class and merges it into
 * `CommandInterpreterNode.includeNodes`, the flat type→class table
 * `resolveClass` reads, so `make_node RulesView` in the debug console names
 * the same node the editor mounts.
 */

import { registerSliceViews } from '@newspack-nodes/shared/nodes/slice-view-node';

/**
 * The view classes. `useRulesGraph` hands `makeNode` the class itself rather
 * than the name `RulesView`, because every bundle links its own copy of the
 * runtime: `includeNodes` is a per-bundle static, and the name resolves only
 * in a bundle that imported this module.
 */
export const views = registerSliceViews( {
	/**
	 * `rules:view` — the per-URL logging-ruleset editor's table.
	 *
	 * Only `list` replies reach here: every mutation owns its own
	 * `useCommandOnce` node and its answer lands there. The whole list is
	 * replaced rather than merged, because `list` always answers with the
	 * complete ruleset, so a rule deleted on the server has to disappear. The
	 * verb answers a live PHP array, so the payload arrives decoded and this
	 * declaration sets no `json`.
	 *
	 * `loading` starts true because an empty ruleset is a real state rather
	 * than a missing answer: no rule matching means nothing is logged, and
	 * `RulesAdmin` renders "No rules configured." for it, so the table must
	 * read "Loading rules…" until the one `list` the graph fires at mount
	 * lands. A payload carrying no `rules` array publishes an empty table
	 * rather than throwing; a refusal arrives as TM_ERROR instead, and the
	 * base keeps the list already on screen.
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
