/**
 * Tests for formatUtils — pure helpers used across dashboards.
 *
 * Covers colour mapping (state → hex), HTTP status classification,
 * duration formatting, and hook-pattern look-up driven by the
 * window.eventLoggerHookCategories config blob.
 */

import {
	hexToRgba,
	getStateColor,
	getStatusCategory,
	getStatusColor,
	getDurationColor,
	getDurationClass,
	getStatusClass,
	formatDuration,
	STATUS_COLORS,
} from '../formatUtils';

describe( 'hexToRgba', () => {
	it( 'converts a 6-digit hex with opacity', () => {
		expect( hexToRgba( '#ff8800', 0.5 ) ).toBe( 'rgba(255, 136, 0, 0.5)' );
	} );

	it( 'handles black at full opacity', () => {
		expect( hexToRgba( '#000000', 1 ) ).toBe( 'rgba(0, 0, 0, 1)' );
	} );

	it( 'handles white at zero opacity', () => {
		expect( hexToRgba( '#ffffff', 0 ) ).toBe( 'rgba(255, 255, 255, 0)' );
	} );
} );

describe( 'getStatusCategory', () => {
	it.each( [
		[ 200, '2xx' ],
		[ 299, '2xx' ],
		[ 301, '3xx' ],
		[ 404, '4xx' ],
		[ 500, '5xx' ],
		[ 599, '5xx' ],
	] )( 'classifies %s as %s', ( status, expected ) => {
		expect( getStatusCategory( status ) ).toBe( expected );
	} );

	it( 'returns unknown for status below 200', () => {
		expect( getStatusCategory( 100 ) ).toBe( 'unknown' );
		expect( getStatusCategory( 0 ) ).toBe( 'unknown' );
	} );
} );

describe( 'getStatusColor / getStatusClass', () => {
	it( 'maps 2xx to the success colour', () => {
		expect( getStatusColor( 200 ) ).toBe( STATUS_COLORS[ '2xx' ] );
	} );

	it( 'maps 500 to the 5xx colour', () => {
		expect( getStatusColor( 500 ) ).toBe( STATUS_COLORS[ '5xx' ] );
	} );

	it( 'returns the category as the CSS class', () => {
		expect( getStatusClass( 404 ) ).toBe( '4xx' );
	} );
} );

describe( 'getDurationColor / getDurationClass', () => {
	it( 'flags > 5000ms as critical/red', () => {
		expect( getDurationColor( 5001 ) ).toBe( '#ef5350' );
		expect( getDurationClass( 5001 ) ).toBe( 'critical' );
	} );

	it( 'flags 1001–5000ms as slow/orange', () => {
		expect( getDurationColor( 2000 ) ).toBe( '#ff9800' );
		expect( getDurationClass( 2000 ) ).toBe( 'slow' );
	} );

	it( 'flags <= 1000ms as fast/green', () => {
		expect( getDurationColor( 500 ) ).toBe( '#4caf50' );
		expect( getDurationClass( 500 ) ).toBe( 'fast' );
	} );
} );

describe( 'formatDuration', () => {
	it( 'returns dash for null/undefined', () => {
		expect( formatDuration( null ) ).toBe( '-' );
		expect( formatDuration( undefined ) ).toBe( '-' );
	} );

	it( 'formats sub-100us as microseconds', () => {
		expect( formatDuration( 0.05 ) ).toBe( '50us' );
	} );

	it( 'formats sub-1ms with 2 decimals + ms', () => {
		expect( formatDuration( 0.5 ) ).toBe( '0.50ms' );
	} );

	it( 'formats sub-1s with 1 decimal + ms', () => {
		expect( formatDuration( 123.4 ) ).toBe( '123.4ms' );
	} );

	it( 'formats >= 1s as seconds with 2 decimals', () => {
		expect( formatDuration( 1500 ) ).toBe( '1.50s' );
	} );
} );

describe( 'getStateColor', () => {
	const originalCategories = window.eventLoggerHookCategories;
	const originalCustom = window.eventLoggerCustomColors;

	afterEach( () => {
		window.eventLoggerHookCategories = originalCategories;
		window.eventLoggerCustomColors = originalCustom;
	} );

	it( 'returns the default colour for empty/null names', () => {
		expect( getStateColor( '' ) ).toBe( '#9e9e9e' );
		expect( getStateColor( null ) ).toBe( '#9e9e9e' );
	} );

	it( 'maps system names to system colours', () => {
		expect( getStateColor( 'process' ) ).toBe( '#FF7043' );
		expect( getStateColor( 'complete' ) ).toBe( '#4CAF50' );
	} );

	it( 'strips trailing " (start)" / " (complete)" before lookup', () => {
		expect( getStateColor( 'process (start)' ) ).toBe( '#FF7043' );
		expect( getStateColor( 'process (complete)' ) ).toBe( '#FF7043' );
	} );

	it( 'treats names with a colon as "base: label" and uses base', () => {
		expect( getStateColor( 'process: foo' ) ).toBe( '#FF7043' );
	} );

	it( 'returns the plugin colour for " plugin"-suffixed names', () => {
		expect( getStateColor( 'jetpack plugin' ) ).toBe( '#AB47BC' );
	} );

	it( 'returns the generic hook colour when no pattern matches', () => {
		window.eventLoggerHookCategories = undefined;
		expect( getStateColor( 'init hook' ) ).toBe( '#66BB6A' );
	} );

	it( 'consults custom colour overrides from the window config', () => {
		window.eventLoggerCustomColors = { mycustom: '#abcdef' };
		expect( getStateColor( 'mycustom' ) ).toBe( '#abcdef' );
	} );

	it( 'falls back to the default colour for unknown names', () => {
		expect( getStateColor( 'never-heard-of-it' ) ).toBe( '#9e9e9e' );
	} );
} );
