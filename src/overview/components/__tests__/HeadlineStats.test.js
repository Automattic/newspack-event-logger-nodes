import { headlineStats } from '../HeadlineStats';

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
} );
