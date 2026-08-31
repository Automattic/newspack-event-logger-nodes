/**
 * Shared rules constants — the single BLANK_RULE definition consumed by both the
 * settings editor (RulesAdmin) and the perf-dashboard "Log this URL" affordance.
 */

import { BLANK_RULE } from '../constants';

describe( 'rules constants', () => {
	test( 'BLANK_RULE is a blank log rule with an empty id and inline hooks tier', () => {
		expect( BLANK_RULE ).toEqual( {
			id: '',
			pattern: '',
			action: 'log',
			auto_disable_threshold: 0,
			auto_protect_time_threshold: 0,
			significant_events: [],
			custom_events: [],
			hooks: [],
			hooks_in: 'inline',
			// Query spans are expensive; a blank rule asks for none.
			log_queries: false,
			trace_hooks: false,
			trace_callers: 0,
		} );
	} );
} );
