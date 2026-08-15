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
