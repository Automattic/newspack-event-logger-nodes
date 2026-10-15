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

	it( 'shows an error count of zero wherever the reply carries one', () => {
		expect( headlineStats( { errors: 0 }, [ 'errors' ] ) ).toEqual( [
			{
				key: 'errors',
				label: 'Total Errors',
				short: 'errors',
				value: '0',
			},
		] );
		expect( headlineStats( { requests: 41 }, [ 'errors' ] ) ).toEqual( [] );
	} );

	it( 'formats the error summary under its short labels', () => {
		const shown = headlineStats(
			{
				timeouts: 1207,
				fatals: 3,
				fatal_avg_ms: 2417.4,
				fatal_max_ms: null,
			},
			[ 'timeouts', 'fatals', 'fatal_avg_ms', 'fatal_max_ms' ]
		);

		expect( shown.map( ( s ) => [ s.short, s.value ] ) ).toEqual( [
			[ 'timeouts', '1,207' ],
			[ 'fatals', '3' ],
			[ 'fatal avg', '2417ms' ],
			[ 'fatal max', '—' ],
		] );
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
