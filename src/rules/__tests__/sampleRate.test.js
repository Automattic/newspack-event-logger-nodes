import { samplePercent } from '../sampleRate';

describe( 'samplePercent', () => {
	test( 'reads a rate as its share of requests, to three significant digits', () => {
		expect( samplePercent( 0.125 ) ).toBe( '12.5%' );
		expect( samplePercent( 0.0004 ) ).toBe( '0.04%' );
		expect( samplePercent( 1 ) ).toBe( '100%' );
		expect( samplePercent( 0 ) ).toBe( '0%' );
	} );

	test( 'a rate just under 1 never reads as every request', () => {
		expect( samplePercent( 0.9996 ) ).toBe( '99.96%' );
		expect( samplePercent( 0.99999 ) ).toBe( '99.999%' );
		expect( samplePercent( 0.9999999999999999 ) ).toBe(
			'99.99999999999999%'
		);
	} );
} );
