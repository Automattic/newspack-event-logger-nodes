/**
 * The page-facts block: what the page currently shows, as clean numbers rather
 * than a table something has to scrape. Facts only — it carries no
 * instructions, and it is rendered only for someone who can already see the
 * dashboard.
 */

import { pageFacts, factsJson } from '../pageFacts';

// Both come off the `urls` reply now: it owns the filters, so it owns every
// fact about the set they left.
const SLOWEST = [
	{ hash: 'aaa', url: '/slow', avg_ms: 2600, max_ms: 2600, count: 4 },
	{ hash: 'bbb', url: '/next', avg_ms: 900, max_ms: 900, count: 40 },
];

test( 'with nothing selected it carries the totals the panel is rendering', () => {
	// The brief and the panel describe one page, so they read one set of
	// numbers. Handing the brief a site-wide total beside a filtered panel is
	// how the two came to contradict each other on the same screen.
	const facts = pageFacts( {
		urlTotals: { urls: 313, requests: 9001, avg_ms: 70, avg_peak_mb: 3.3 },
		urlSlowest: SLOWEST,
	} );

	expect( facts.surface ).toBe( 'overview' );
	expect( facts.totals ).toEqual( {
		urls: 313,
		requests: 9001,
		avg_ms: 70,
		avg_peak_mb: 3.3,
	} );
	expect( facts.slowest[ 0 ] ).toEqual( {
		hash: 'aaa',
		url: '/slow',
		avg_ms: 2600,
		count: 4,
	} );
} );

test( 'a selected URL names itself and what it is', () => {
	const facts = pageFacts( {
		selectedUrl: { hash: 'aaa', url: '/slow' },
		urlDetail: { stats: { count: 4, avg_ms: 900, max_ms: 2600 } },
	} );

	expect( facts.surface ).toBe( 'url' );
	expect( facts.url ).toEqual( { hash: 'aaa', url: '/slow' } );
	expect( facts.stats.max_ms ).toBe( 2600 );
} );

test( 'a selected request wins over its URL, and carries its findings', () => {
	const facts = pageFacts( {
		selectedUrl: { hash: 'aaa', url: '/slow' },
		selectedRequest: 'rid-1',
		requestPartition: 2,
		requestDetail: {
			duration_ms: 812,
			status_code: 200,
			findings: [
				{ kind: 'unattributed', title: 'x', severity: 'high' },
			],
			caveat: 'not everything is measured',
		},
	} );

	expect( facts.surface ).toBe( 'request' );
	expect( facts.request ).toEqual( { rid: 'rid-1', partition: 2 } );
	expect( facts.duration_ms ).toBe( 812 );
	expect( facts.findings ).toEqual( [
		{ kind: 'unattributed', title: 'x', severity: 'high' },
	] );
	expect( facts.caveat ).toBe( 'not everything is measured' );
} );

test( 'every surface names what it is scoped to', () => {
	// A filter narrows the URL surface exactly as it narrows the overview, so
	// the provenance cannot live inside one branch — a reader that cannot see
	// the screen has only this to go on.
	const facts = pageFacts( {
		selectedUrl: { hash: 'h1', url: '/foo' },
		urlDetail: { stats: { count: 12 } },
		urlFilters: {
			server: 'edge-01',
			search: '',
			errors_only: false,
			include_workers: false,
		},
	} );

	expect( facts.surface ).toBe( 'url' );
	expect( facts.filters ).toEqual( {
		server: 'edge-01',
		search: '',
		errors_only: false,
		include_workers: false,
	} );
} );

test( 'the slowest URLs come from the set the totals describe', () => {
	// `slowest` used to be built from the unscoped index while `totals` came
	// from the filtered one — one object, one scope statement, two scopes.
	const facts = pageFacts( {
		urlTotals: { urls: 2, requests: 9 },
		urlSlowest: [ { hash: 'zzz', url: '/scoped', avg_ms: 1200, count: 3 } ],
	} );

	expect( facts.slowest ).toEqual( [
		{ hash: 'zzz', url: '/scoped', avg_ms: 1200, count: 3 },
	] );
} );

test( 'it names what the totals are scoped to', () => {
	// The numbers above are one server's, or one search's. Handing a model a
	// narrower total with no scope on it is how it comes to answer for the site.
	const facts = pageFacts( {
		urlTotals: { urls: 313, requests: 9001 },
		urlFilters: {
			server: 'edge-01',
			search: '/wp-json',
			errors_only: false,
			include_workers: false,
		},
	} );

	expect( facts.filters ).toEqual( {
		server: 'edge-01',
		search: '/wp-json',
		errors_only: false,
		include_workers: false,
	} );
} );

test( 'nothing loaded yet reads as absent, not as zero traffic', () => {
	// The header renders an em dash for exactly these fields, because a
	// plausible zero beside a real total is how the last defect hid. A reader
	// of this block during first paint must not be told the site is idle.
	const facts = pageFacts( {} );

	expect( facts.surface ).toBe( 'overview' );
	expect( facts.totals ).toEqual( {
		urls: null,
		requests: null,
		avg_ms: null,
		avg_peak_mb: null,
	} );
	expect( facts.slowest ).toEqual( [] );
} );

test( 'the block never carries anything but facts', () => {
	const facts = pageFacts( {
		requestDetail: { remote_addr: '203.0.113.7', user_agent: 'secret' },
	} );

	const encoded = JSON.stringify( facts );
	expect( encoded ).not.toContain( '203.0.113.7' );
	expect( encoded ).not.toContain( 'secret' );
} );

describe( 'factsJson', () => {
	test( 'a closing script tag in the data cannot end the block', () => {
		const json = factsJson( {
			surface: 'url',
			url: { url: '/</script><img src=x onerror=alert(1)>' },
		} );

		expect( json ).not.toContain( '</script' );
		expect( json ).toContain( '\\u003C' );
		expect( JSON.parse( json ).url.url ).toBe(
			'/</script><img src=x onerror=alert(1)>'
		);
	} );

	test( 'the JS line separators are escaped too', () => {
		const json = factsJson( {
			surface: 'url',
			url: { url: '/a\u2028b\u2029c' },
		} );

		expect( json ).not.toContain( '\u2028' );
		expect( json ).not.toContain( '\u2029' );
		expect( JSON.parse( json ).url.url ).toBe( '/a\u2028b\u2029c' );
	} );
} );

test( 'every filter the reply echoes reaches the facts, include_workers first', () => {
	// `include_workers` defaults to OFF, so it is the one filter whose default
	// takes traffic away. A brief that omits it hands out worker-excluded
	// totals with nothing saying they are narrowed.
	const facts = pageFacts( {
		urlFilters: {
			server: 'edge-07',
			search: '/wp-json',
			errors_only: false,
			include_workers: true,
		},
	} );

	expect( facts.filters ).toStrictEqual( {
		server: 'edge-07',
		search: '/wp-json',
		errors_only: false,
		include_workers: true,
	} );
} );

test( 'before the first reply the filters read as absent, not as defaults', () => {
	// ADR-15: the `urls` verb owns every URL-set fact, and the filters are
	// one — it echoes the set it applied. A client-side default table is a
	// second copy of that contract, and publishing it before the first reply
	// states a filter state nothing has confirmed: the plausible-default twin
	// of the plausible-zero this block already stopped emitting for totals.
	expect( pageFacts( {} ).filters ).toBeNull();
} );
