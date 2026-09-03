/**
 * The one BLANK_RULE definition, shared by the two trees that create rules.
 *
 * The settings editor's Add flow (RulesAdmin) and the performance dashboard's
 * "Log this URL" affordance both seed RuleEditModal with a fresh draft. A
 * second copy is how a field added to `Rule` reaches one entry point and not
 * the other.
 */

/**
 * A brand-new rule draft, in `Rule::to_array()`'s wire shape.
 *
 * Two fields are placeholders the draft cannot fill. The modal refuses to save
 * an empty pattern, and `Rules_CI_Node`'s `upsert` mints the id from whatever
 * pattern the operator types — so a draft on a new pattern appends, and one
 * repeating an existing pattern replaces that rule in place.
 *
 * `action` is `log` because both entry points exist to start logging. A blank
 * draft could as easily have defaulted to the quieter verdict, and then every
 * new rule would open on a form whose payload fields the modal empties on save.
 *
 * `hooks_in` is inline because `Rule`'s constructor pairs a null hook list with
 * the `mc` tier and a real list with `inline`. Which tier a saved rule lands in
 * is `Rule_Set`'s call, re-derived from the hook count on every save.
 *
 * The instrumentation flags carry `Rule`'s own defaults. HTTP spans cost two
 * entries per outbound call and stay on; query spans cost two per query and
 * need SAVEQUERIES, and the provenance knobs buy a backtrace at hook firings,
 * so a draft asks for none of the three.
 *
 * @type {Object}
 */
export const BLANK_RULE = {
	id: '',
	pattern: '',
	action: 'log',
	auto_disable_threshold: 0,
	auto_protect_time_threshold: 0,
	significant_events: [],
	custom_events: [],
	hooks: [],
	hooks_in: 'inline',
	log_queries: false,
	log_http: true,
	trace_hooks: false,
	trace_callers: 0,
};
