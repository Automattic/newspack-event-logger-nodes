import * as React from 'react';
import HeadlineStats, { headlineStats } from '../HeadlineStats';
import { renderComponent } from '../../../test-helpers/renderHook';

describe( 'headlineStats', () => {
	it( 'shows a mean nobody measured as a dash, beside the measured ones', () => {
		const shown = headlineStats(
			{ requests: 41, avg_ms: null, requests_per_second: 0.25 },
			[ 'requests', 'avg_ms', 'requests_per_second' ]
		);

		expect( shown.map( ( s ) => [ s.key, s.value ] ) ).toEqual( [
			[ 'requests', '41' ],
			[ 'avg_ms', '—' ],
			[ 'requests_per_second', '0.25' ],
		] );
	} );

	it( 'rounds a measured mean with its unit', () => {
		expect(
			headlineStats( { avg_ms: 61.4 }, [ 'avg_ms' ] )[ 0 ].value
		).toBe( '61ms' );
	} );

	it( 'formats the percentiles in ms and the error rate as a percentage', () => {
		const shown = headlineStats(
			{
				p50_ms: 111.4,
				p95_ms: 987.2,
				p99_ms: 1543.6,
				error_rate: 0.0375,
			},
			[ 'p50_ms', 'p95_ms', 'p99_ms', 'error_rate' ]
		);

		expect( shown.map( ( s ) => [ s.short, s.value ] ) ).toEqual( [
			[ 'p50', '111ms' ],
			[ 'p95', '987ms' ],
			[ 'p99', '1544ms' ],
			[ 'err', '3.75%' ],
		] );
	} );

	it( 'shows a percentile nobody measured as a dash, and a zero error rate as 0.00%', () => {
		const shown = headlineStats( { p99_ms: null, error_rate: 0 }, [
			'p99_ms',
			'error_rate',
		] );

		expect( shown.map( ( s ) => s.value ) ).toEqual( [ '—', '0.00%' ] );
	} );

	it( 'shows an error count of zero, and one not yet answered as absent', () => {
		expect( headlineStats( { errors: 0 }, [ 'errors' ] ) ).toEqual( [
			{
				key: 'errors',
				label: 'Total Errors',
				short: 'errors',
				value: '0',
			},
		] );
		expect(
			headlineStats( { requests: 41 }, [ 'errors' ] ).map(
				( stat ) => stat.value
			)
		).toEqual( [ '—' ] );
	} );
} );

describe( 'HeadlineStats', () => {
	it( 'shows no provisional banner', () => {
		const { container, unmount } = renderComponent(
			React.createElement( HeadlineStats, {
				totals: { urls: 7331, requests: 90210, avg_ms: 212.6 },
				provisional: true,
			} )
		);

		expect(
			container.querySelector( '.newspack-nodes-banner' )
		).toBeNull();
		expect( container.textContent ).not.toContain( 'Provisional' );
		unmount();
	} );

	it( 'counts the unique URLs exactly, as the reply does', () => {
		const { container, unmount } = renderComponent(
			React.createElement( HeadlineStats, {
				totals: { urls: 7331, requests: 90210 },
			} )
		);

		expect( container.textContent ).toContain( '7,331Unique URLs' );
		unmount();
	} );

	it( 'shows a dash for every stat while no totals stand', () => {
		const { container, unmount } = renderComponent(
			React.createElement( HeadlineStats, { totals: null } )
		);

		const values = [
			...container.querySelectorAll( '.newspack-nodes-stat-value' ),
		].map( ( node ) => node.textContent );
		expect( values.length ).toBeGreaterThan( 0 );
		expect( values.every( ( value ) => '—' === value ) ).toBe( true );
		unmount();
	} );

	it( 'says the window is the last 24 hours, the current five minutes so far', () => {
		const { container, unmount } = renderComponent(
			React.createElement( HeadlineStats, { totals: null } )
		);

		expect(
			container
				.querySelector( '.event-logger-overview-stats' )
				.getAttribute( 'title' )
		).toBe(
			'The last 24 hours in five-minute buckets, the current one so far.'
		);
		unmount();
	} );
} );
