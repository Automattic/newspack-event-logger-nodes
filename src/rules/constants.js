/**
 * Shared logging-rule constants.
 *
 * BLANK_RULE is the brand-new draft the Add flow (RulesAdmin) and the perf
 * dashboard's "Log this URL" affordance seed RuleEditModal with — an empty id
 * means the upsert appends. Kept here so both trees share ONE definition.
 */

// Mirrors Rule::TRACE_CALLERS_DEFAULT, what `true` decodes to server-side.
export const TRACE_CALLERS_DEFAULT = 20;

// A brand-new draft the Add flow seeds RuleEditModal with (empty id = append).
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
	trace_callers: 0,
};
