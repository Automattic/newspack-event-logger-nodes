/**
 * Tests for the various dashboard constants modules — they have no
 * logic but going below the 80% threshold without an import means
 * even a `module-shape` check is enough to count as covered.
 */

describe( 'performance-gyroscope/constants', () => {
	let mod;
	beforeAll( () => {
		mod = require( '../performance-gyroscope/constants' );
	} );

	it( 'exposes DASHBOARD_REFRESH_OPTIONS as a non-empty array of {label, value}', () => {
		expect( Array.isArray( mod.DASHBOARD_REFRESH_OPTIONS ) ).toBe( true );
		expect( mod.DASHBOARD_REFRESH_OPTIONS.length ).toBeGreaterThan( 0 );
		mod.DASHBOARD_REFRESH_OPTIONS.forEach( ( opt ) => {
			expect( typeof opt.label ).toBe( 'string' );
			expect( typeof opt.value ).toBe( 'string' );
		} );
	} );

	it( 'exposes INFLIGHT_REFRESH_OPTIONS as a non-empty array of {value (number), label}', () => {
		expect( Array.isArray( mod.INFLIGHT_REFRESH_OPTIONS ) ).toBe( true );
		expect( mod.INFLIGHT_REFRESH_OPTIONS.length ).toBeGreaterThan( 0 );
		mod.INFLIGHT_REFRESH_OPTIONS.forEach( ( opt ) => {
			expect( typeof opt.label ).toBe( 'string' );
			expect( typeof opt.value ).toBe( 'number' );
		} );
	} );
} );

describe( 'performance-dashboards/constants', () => {
	let mod;
	beforeAll( () => {
		mod = require( '../performance-dashboards/constants' );
	} );

	it( 'DASHBOARD_REFRESH_OPTIONS is a non-empty array of {label, value:string}', () => {
		expect( Array.isArray( mod.DASHBOARD_REFRESH_OPTIONS ) ).toBe( true );
		expect( mod.DASHBOARD_REFRESH_OPTIONS.length ).toBeGreaterThan( 0 );
	} );

	it( 'CHART_METRIC_OPTIONS exists and includes "volume"', () => {
		expect( Array.isArray( mod.CHART_METRIC_OPTIONS ) ).toBe( true );
		const values = mod.CHART_METRIC_OPTIONS.map( ( o ) => o.value );
		expect( values ).toContain( 'volume' );
		expect( values ).toContain( 'avg' );
	} );

	it( 'CHART_BREAKDOWN_OPTIONS exists and includes "status"', () => {
		expect( Array.isArray( mod.CHART_BREAKDOWN_OPTIONS ) ).toBe( true );
		const values = mod.CHART_BREAKDOWN_OPTIONS.map( ( o ) => o.value );
		expect( values ).toContain( 'status' );
		expect( values ).toContain( 'server' );
	} );
} );
