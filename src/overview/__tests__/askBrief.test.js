/**
 * The brief a human copies. It has to carry the numbers, the findings, the
 * proposals AND the caveat — a model handed a profiled/duration ratio with no
 * caveat will invent a cause for the difference.
 */

import { briefToMarkdown, askClaudeUrl } from '../askBrief';

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

test( 'a ratio below a tenth keeps its digits', () => {
	// `toFixed( 1 )` rendered self_share 0.095 as `0.1` and 0.04 as `0.0` —
	// flatly contradicting the finding's own "spends 4% in its own body", and
	// flattening a real 0.0113ms-per-call to `0.0` besides.
	const brief = {
		...REQUEST_BRIEF,
		findings: [
			{
				...REQUEST_BRIEF.findings[ 0 ],
				metric: { self_share: 0.0409, each_ms: 0.0113, share: 0.9998 },
			},
		],
	};

	const md = briefToMarkdown( brief );

	expect( md ).toContain( 'self_share=0.041' );
	expect( md ).toContain( 'each_ms=0.011' );
	expect( md ).toContain( 'share=1.000' );
} );

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
		stats: { count: 12, avg_ms: 4000 },
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
		stats: { count: 31, avg_ms: 812.25, max_peak_mb: 96.5 },
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
	expect( md ).toContain( '**avg_ms:** 812.3' );
} );

test( 'a URL brief marks worst-recent when the index scan stopped early', () => {
	const md = briefToMarkdown( {
		subject: 'url',
		url: '/quiet/page',
		stats: { count: 7, avg_ms: 331.5 },
		worst_requests: [
			{ rid: 'part1al', duration_ms: 2200.5, status_code: 404 },
		],
		scan_stopped_early: true,
		rule: null,
		findings: [],
		caveat: 'c',
	} );

	expect( md ).toContain( '**worst recent:** part1al 2200.5ms 404' );
	expect( md ).toContain( '**scan:** stopped early — this list is partial' );
} );

test( 'a URL brief names the window its recent rows were drawn from', () => {
	// An empty list is empty OF a window; without naming it, the reader takes
	// it for the URL's whole record.
	const md = briefToMarkdown( {
		subject: 'url',
		url: '/quiet/page',
		stats: { count: 3, avg_ms: 55.25 },
		worst_requests: [],
		scan_stopped_early: false,
		requests_window_start: 1741000800,
		rule: null,
		findings: [],
		caveat: 'c',
	} );

	expect( md ).toContain( '**requests since:** 2025-03-03T11:20:00Z' );
} );

test( 'a URL brief with no rows at all drops the label the scan note hung off', () => {
	// The empty list is exactly the case the note exists for, and a label
	// whose whole value is a parenthetical is not a sentence.
	const md = briefToMarkdown( {
		subject: 'url',
		url: '/never/reached',
		stats: { count: 3, avg_ms: 55.25 },
		worst_requests: [],
		scan_stopped_early: true,
		rule: null,
		findings: [],
		caveat: 'c',
	} );

	expect( md ).not.toContain( 'worst recent' );
	expect( md ).toContain( '**scan:** stopped early — this list is partial' );
} );

test( 'a URL brief whose scan finished says nothing about the five-row slice', () => {
	const md = briefToMarkdown( {
		subject: 'url',
		url: '/busy/page',
		stats: { count: 4210, avg_ms: 812 },
		worst_requests: [
			{ rid: 'wh0le1', duration_ms: 3300.5, status_code: 500 },
		],
		scan_stopped_early: false,
		rule: null,
		findings: [],
		caveat: 'c',
	} );

	expect( md ).toContain( 'wh0le1 3300.5ms 500' );
	expect( md ).not.toContain( 'scan stopped early' );
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

/**
 * The brief is trimmed on purpose — the series, the rosters and the deep spans
 * stay on the server. What replaces them is the address of the rest, so an
 * agent handed this paste can go and get it.
 */
test( 'a brief carries the tool call that fetches it again', () => {
	const md = briefToMarkdown(
		{
			subject: 'span',
			name: 'wp_loaded hook',
			ms: 791.5,
			fetch: [
				{
					tool: 'performance_ask',
					arguments: {
						descriptor: 'span:wp_loaded hook',
						context: 'request:c6x0zgr:3',
					},
				},
			],
			caveat: 'c',
		},
		'https://example.test/wp-json/newspack-event-logger-nodes/v1/mcp'
	);

	expect( md ).toContain(
		'performance_ask descriptor="span:wp_loaded hook" context="request:c6x0zgr:3"'
	);
	expect( md ).toContain(
		'https://example.test/wp-json/newspack-event-logger-nodes/v1/mcp'
	);
} );

// The endpoint is a property of the site, not of each thing asked about.
test( 'several briefs name the endpoint once', () => {
	const md = briefToMarkdown(
		[
			{
				subject: 'url',
				fetch: [ { tool: 'a', arguments: {} } ],
				caveat: 'c',
			},
			{
				subject: 'span',
				fetch: [ { tool: 'b', arguments: {} } ],
				caveat: 'c',
			},
		],
		'https://example.test/mcp'
	);

	expect( md.split( 'https://example.test/mcp' ) ).toHaveLength( 2 );
} );

test( 'a rule rides as counts, not rosters', () => {
	const md = briefToMarkdown( {
		subject: 'url',
		url: '/calendar',
		rule: {
			id: 'a1b2c3',
			pattern: '/calendar',
			action: 'log',
			hook_count: 64,
			custom_event_count: 68,
			significant_events: [ 'init' ],
		},
		caveat: 'c',
	} );

	expect( md ).toContain( '**hooks:** 64' );
	expect( md ).toContain( '**custom events:** 68' );
} );

test( 'nothing to say is an empty string, not "undefined"', () => {
	expect( briefToMarkdown( null ) ).toBe( '' );
	expect( briefToMarkdown( [] ) ).toBe( '' );
} );

/**
 * A brief is only useful where it lands. This opens a chat with the brief
 * already in it — worth having wherever the site is publicly reachable, since
 * an agent there can also call the `fetch` verbs itself.
 */
test( 'the Claude link carries the brief as a prefilled prompt', () => {
	const url = new URL( askClaudeUrl( '## span\n\n- **ms:** 791.5' ) );

	expect( url.origin + url.pathname ).toBe( 'https://claude.ai/new' );
	expect( url.searchParams.get( 'q' ) ).toContain( '- **ms:** 791.5' );
} );

// A URL is not a document store: past the cap the brief is dropped and the
// link carries the ask, so a too-long brief still opens something usable.
test( 'an oversized brief falls back to a short prompt', () => {
	const huge = '## span\n\n' + 'x'.repeat( 12000 );

	const url = askClaudeUrl( huge );

	expect( url.length ).toBeLessThan( 8000 );
	expect( decodeURIComponent( url ) ).toContain( 'paste' );
} );
