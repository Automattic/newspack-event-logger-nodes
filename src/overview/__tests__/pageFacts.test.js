/**
 * The page-facts block: what the page currently shows, as clean numbers rather
 * than a table something has to scrape. Facts only — it carries no
 * instructions, and it is rendered only for someone who can already see the
 * dashboard.
 */

import { pageFacts, factsJson } from '../pageFacts';

const OVERVIEW = {
	total_urls: 12,
	total_requests: 115,
	global_avg_ms: 285.8,
	global_avg_peak_mb: 7.4,
	slowest_urls: [
		{ hash: 'aaa', url: '/slow', p95_ms: 2600, count: 4 },
		{ hash: 'bbb', url: '/next', p95_ms: 900, count: 40 },
	],
};

test( 'with nothing selected it carries the site-wide totals', () => {
	const facts = pageFacts( { overview: OVERVIEW } );

	expect( facts.surface ).toBe( 'overview' );
	expect( facts.totals.requests ).toBe( 115 );
	expect( facts.slowest[ 0 ] ).toEqual( {
		hash: 'aaa',
		url: '/slow',
		p95_ms: 2600,
		count: 4,
	} );
} );

test( 'a selected URL names itself and what it is', () => {
	const facts = pageFacts( {
		overview: OVERVIEW,
		selectedUrl: { hash: 'aaa', url: '/slow' },
		urlDetail: { stats: { count: 4, avg_ms: 900, p95_ms: 2600 } },
	} );

	expect( facts.surface ).toBe( 'url' );
	expect( facts.url ).toEqual( { hash: 'aaa', url: '/slow' } );
	expect( facts.stats.p95_ms ).toBe( 2600 );
} );

test( 'a selected request wins over its URL, and carries its findings', () => {
	const facts = pageFacts( {
		overview: OVERVIEW,
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

test( 'nothing loaded yet is still a well-formed object', () => {
	const facts = pageFacts( {} );

	expect( facts.surface ).toBe( 'overview' );
	expect( facts.totals ).toEqual( {
		urls: 0,
		requests: 0,
		avg_ms: 0,
		avg_peak_mb: 0,
	} );
	expect( facts.slowest ).toEqual( [] );
} );

test( 'the block never carries anything but facts', () => {
	const facts = pageFacts( {
		overview: OVERVIEW,
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
