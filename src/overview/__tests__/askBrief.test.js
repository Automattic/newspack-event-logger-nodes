/**
 * The brief a human copies. It has to carry the numbers, the findings, the
 * proposals AND the caveat — a model handed a profiled/duration ratio with no
 * caveat will invent a cause for the difference.
 */

import { briefToMarkdown } from '../askBrief';

const REQUEST_BRIEF = {
	subject: 'request',
	url: '/calendar/today',
	duration_ms: 420000,
	status_code: 200,
	env: { worker_type: 'flame-builder' },
	flame: {
		profiled_ms: 175.6,
		top_level: [ { name: 'init', ms: 175.6, count: 1 } ],
	},
	rule: { id: 'a1b2c3', pattern: '/calendar', action: 'log', hook_count: 2 },
	findings: [
		{
			kind: 'unattributed',
			severity: 'high',
			title: '419.8s of 420.0s went unmeasured',
			detail: 'because reasons',
			measured: 'subtraction',
			metric: { missing_ms: 419824.4 },
			proposal: {
				action: 'add_hooks',
				direction: 'more',
				hooks: [ 'init', 'wp_loaded' ],
				why: 'the time is somewhere nothing is watching',
				undo: 'remove them after',
			},
		},
	],
	caveat: 'It does not see SQL or outbound HTTP.',
	entries: [ { n: 1, ts: 1000, k: 'process (start)', m: '' } ],
	entries_truncated: false,
};

test( 'a request brief leads with what it is and what it took', () => {
	const md = briefToMarkdown( REQUEST_BRIEF );

	expect( md ).toContain( '## request' );
	expect( md ).toContain( '/calendar/today' );
	expect( md ).toContain( '420000' );
	expect( md ).toContain( '200' );
} );

test( 'findings carry their number, where it was measured, and the proposal', () => {
	const md = briefToMarkdown( REQUEST_BRIEF );

	expect( md ).toContain( '419.8s of 420.0s went unmeasured' );
	expect( md ).toContain( '**measured:** subtraction' );
	expect( md ).toContain( 'add_hooks' );
	expect( md ).toContain( 'init, wp_loaded' );
	expect( md ).toContain( 'remove them after' );
} );

test( 'the caveat is part of the payload, never a footnote we might drop', () => {
	expect( briefToMarkdown( REQUEST_BRIEF ) ).toContain(
		'It does not see SQL or outbound HTTP.'
	);
} );

test( 'a truncated entry list says so', () => {
	const md = briefToMarkdown( { ...REQUEST_BRIEF, entries_truncated: true } );
	expect( md.toLowerCase() ).toContain( 'truncated' );
} );

test( 'the rule an edit would land on rides along', () => {
	const md = briefToMarkdown( REQUEST_BRIEF );
	expect( md ).toContain( 'a1b2c3' );
	expect( md ).toContain( '/calendar' );
} );

test( 'a URL with no rule says so rather than omitting the line', () => {
	const md = briefToMarkdown( {
		subject: 'url',
		url: '/uncovered',
		stats: { count: 12, p95_ms: 4000 },
		rule: null,
		findings: [],
		caveat: 'c',
	} );

	expect( md ).toContain( 'no rule governs this URL' );
} );

test( 'a URL brief names the worst recent requests by rid', () => {
	const md = briefToMarkdown( {
		subject: 'url',
		url: '/calendar/today',
		stats: { count: 31, avg_ms: 812.25, p95_ms: 4100, max_peak_mb: 96.5 },
		worst_requests: [
			{ rid: 'w0rst1', duration_ms: 9100.4, status_code: 500 },
			{ rid: 'w0rst2', duration_ms: 8200, status_code: 200 },
		],
		rule: {
			id: 'a1b2c3',
			pattern: '/calendar',
			action: 'log',
			hook_count: 2,
		},
		findings: [],
		caveat: 'c',
	} );

	expect( md ).toContain( 'w0rst1 9100.4ms 500' );
	expect( md ).toContain( 'w0rst2 8200ms 200' );
	expect( md ).toContain( '**p95_ms:** 4100' );
} );

test( 'a span brief carries its parent, its siblings and what is inside it', () => {
	const md = briefToMarkdown( {
		subject: 'span',
		name: 'wp_loaded hook',
		ms: 791.5,
		count: 3,
		parent: 'init hook',
		parent_ms: 812,
		siblings: [
			{ name: 'admin_init hook', ms: 44.25 },
			{ name: 'shutdown hook', ms: 12 },
		],
		subtree: [ { name: 'query', ms: 610.5, count: 17 } ],
		url: '/calendar/today',
		rule: null,
		caveat: 'c',
	} );

	expect( md ).toContain( '**parent:** init hook 812ms' );
	expect( md ).toContain( 'admin_init hook 44.3ms, shutdown hook 12ms' );
	expect( md ).toContain( 'query 610.5ms×17' );
} );

test( 'an entry brief says where the silence around it starts and ends', () => {
	const md = briefToMarkdown( {
		subject: 'entry',
		entry: { n: 41, k: 'query', m: 'SELECT 1' },
		neighbours: [
			{ n: 40, k: 'process (start)' },
			{ n: 42, k: 'template' },
		],
		gap_before_ms: 1904.75,
		gap_after_ms: null,
		url: '/calendar/today',
		caveat: 'c',
	} );

	expect( md ).toContain( '**entry:** #41 query' );
	expect( md ).toContain( '**gap before:** 1904.8ms' );
	expect( md ).toContain( '**gap after:** end of request' );
	expect( md ).toContain( '#40 process (start), #42 template' );
} );

test( 'a category brief shows its share and what it competes with', () => {
	const md = briefToMarkdown( {
		subject: 'category',
		name: 'database',
		avg_time_ms: 611.25,
		avg_count: 173,
		share: 0.734,
		others: [
			{ name: 'hooks', avg_time_ms: 122.5 },
			{ name: 'template', avg_time_ms: 41 },
		],
		caveat: 'c',
	} );

	expect( md ).toContain( '**share:** 73%' );
	expect( md ).toContain( 'hooks 122.5ms, template 41ms' );
} );

// A subject this renderer has never heard of still gets its heading and caveat,
// rather than throwing on the way to the clipboard.
test( 'an unknown subject renders a heading and the caveat, nothing invented', () => {
	const md = briefToMarkdown( {
		subject: 'constellation',
		caveat: 'It does not see SQL.',
	} );

	expect( md ).toContain( '## constellation' );
	expect( md ).toContain( 'It does not see SQL.' );
} );

test( 'several briefs concatenate under one heading each', () => {
	const md = briefToMarkdown( [
		REQUEST_BRIEF,
		{
			subject: 'span',
			name: 'wp_loaded',
			ms: 790,
			count: 3,
			parent_ms: 812,
			caveat: 'c',
		},
	] );

	expect( md ).toContain( '## request' );
	expect( md ).toContain( '## span' );
	expect( md ).toContain( 'wp_loaded' );
} );

test( 'nothing to say is an empty string, not "undefined"', () => {
	expect( briefToMarkdown( null ) ).toBe( '' );
	expect( briefToMarkdown( [] ) ).toBe( '' );
} );
