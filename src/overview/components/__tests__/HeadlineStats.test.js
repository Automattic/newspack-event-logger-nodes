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
