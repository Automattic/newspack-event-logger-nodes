/**
 * Tests for the theme-aware depth-shaded flame graph palette helpers.
 *
 * These are the pure, unit-testable surface; the DOM label-contrast wiring is
 * verified in-browser. shadeForDepth mixes the active theme's accent toward the
 * theme background by stack depth; pickLabelColor keeps frame labels readable on
 * every resulting shade.
 */

import {
	relativeLuminance,
	pickLabelColor,
	parseColor,
	isColorParseable,
	DARK_TEXT,
	LIGHT_TEXT,
} from '../flameColors';
import { createColorMapper } from '../FlameGraph';
import { getStateColor } from '@newspack-nodes/shared/utils/formatUtils';

const BRIGHT = '#41e07a';
const DEEP = '#1b3a5c';

describe( 'relativeLuminance', () => {
	it( 'is ~1 for white and ~0 for black', () => {
		expect( relativeLuminance( parseColor( '#ffffff' ) ) ).toBeCloseTo(
			1,
			2
		);
		expect( relativeLuminance( parseColor( '#000000' ) ) ).toBeCloseTo(
			0,
			2
		);
	} );

	it( 'increases with brightness', () => {
		expect( relativeLuminance( parseColor( BRIGHT ) ) ).toBeGreaterThan(
			relativeLuminance( parseColor( DEEP ) )
		);
	} );
} );

/**
 * The fills are palette colors now, so these read real ones: a bright hook
 * green and a deep database blue, either end of what getStateColor returns.
 */
describe( 'pickLabelColor', () => {
	it( 'picks dark text on a bright fill', () => {
		expect( pickLabelColor( BRIGHT ) ).toBe( DARK_TEXT );
	} );

	it( 'picks light text on a deep fill', () => {
		expect( pickLabelColor( DEEP ) ).toBe( LIGHT_TEXT );
	} );
} );

describe( 'isColorParseable', () => {
	it( 'accepts #hex and rgb() colors', () => {
		expect( isColorParseable( '#41e07a' ) ).toBe( true );
		expect( isColorParseable( '#fff' ) ).toBe( true );
		expect( isColorParseable( 'rgb(1, 2, 3)' ) ).toBe( true );
	} );

	it( 'rejects absent or non-color tokens so the fallback chain continues', () => {
		expect( isColorParseable( '' ) ).toBe( false );
		expect( isColorParseable( 'rebeccapurple' ) ).toBe( false );
		expect( isColorParseable( 'color-mix(in srgb, red, blue)' ) ).toBe(
			false
		);
	} );
} );

describe( 'createColorMapper', () => {
	afterEach( () => {
		delete window.eventLoggerCustomColors;
	} );

	it( 'colors a hook frame with the shared hook palette, not a depth shade', () => {
		const map = createColorMapper();
		const shallow = map( { depth: 0, data: { name: 'init hook' } } );
		const deep = map( { depth: 7, data: { name: 'init hook' } } );

		expect( shallow ).toBe( getStateColor( 'init hook' ) );
		// Depth changed nothing: the same span reads the same everywhere.
		expect( deep ).toBe( shallow );
	} );

	it( 'honors a custom event color from the config', () => {
		window.eventLoggerCustomColors = { validation: '#ff00aa' };
		expect(
			createColorMapper()( { depth: 3, data: { name: 'validation' } } )
		).toBe( '#ff00aa' );
	} );

	it( 'leaves spacer frames transparent', () => {
		expect(
			createColorMapper()( { depth: 1, data: { spacer: true } } )
		).toBe( 'transparent' );
	} );
} );
