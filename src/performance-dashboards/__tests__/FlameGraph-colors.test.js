/**
 * Tests for the theme-aware depth-shaded flame graph palette helpers.
 *
 * These are the pure, unit-testable surface; the DOM label-contrast wiring is
 * verified in-browser. shadeForDepth mixes the active theme's accent toward the
 * theme background by stack depth; pickLabelColor keeps frame labels readable on
 * every resulting shade.
 */

import {
	shadeForDepth,
	relativeLuminance,
	pickLabelColor,
	parseColor,
	isColorParseable,
	DARK_TEXT,
	LIGHT_TEXT,
} from '../flameColors';

const ACCENT = '#41e07a';
const BG = '#020a05';

describe( 'shadeForDepth', () => {
	it( 'returns the accent unchanged at depth 0 (fraction 0)', () => {
		const accentRgb = parseColor( ACCENT );
		expect( parseColor( shadeForDepth( 0, ACCENT, BG ) ) ).toEqual(
			accentRgb
		);
	} );

	it( 'shades deeper frames toward the background', () => {
		const accentRgb = parseColor( ACCENT );
		const bgRgb = parseColor( BG );
		const deep = parseColor( shadeForDepth( 3, ACCENT, BG ) );

		// Green is the dominant accent channel and bg is near-black, so the
		// dominant channel must drop as depth increases (moving toward bg).
		expect( deep.g ).toBeLessThan( accentRgb.g );

		// And the deep shade's luminance sits between bg and accent.
		const accentLum = relativeLuminance( accentRgb );
		const bgLum = relativeLuminance( bgRgb );
		const deepLum = relativeLuminance( deep );
		expect( deepLum ).toBeLessThan( accentLum );
		expect( deepLum ).toBeGreaterThan( bgLum );
	} );

	it( 'caps the mix fraction at 0.65 (depth 5 and depth 20 are identical)', () => {
		// 0.65 / 0.13 = 5, so depth 5 already hits the cap.
		expect( shadeForDepth( 5, ACCENT, BG ) ).toBe(
			shadeForDepth( 20, ACCENT, BG )
		);
	} );

	it( 'never reaches the background even at the cap', () => {
		expect( parseColor( shadeForDepth( 20, ACCENT, BG ) ) ).not.toEqual(
			parseColor( BG )
		);
	} );

	it( 'supports 3-digit shorthand hex', () => {
		expect( parseColor( shadeForDepth( 0, '#fff', BG ) ) ).toEqual( {
			r: 255,
			g: 255,
			b: 255,
		} );
	} );
} );

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
		const dim = relativeLuminance(
			parseColor( shadeForDepth( 5, ACCENT, BG ) )
		);
		const bright = relativeLuminance( parseColor( ACCENT ) );
		expect( bright ).toBeGreaterThan( dim );
	} );
} );

describe( 'pickLabelColor', () => {
	it( 'picks dark text on the bright accent shade', () => {
		expect( pickLabelColor( shadeForDepth( 0, ACCENT, BG ) ) ).toBe(
			DARK_TEXT
		);
	} );

	it( 'picks light text on a deep, dark shade', () => {
		expect( pickLabelColor( shadeForDepth( 5, ACCENT, BG ) ) ).toBe(
			LIGHT_TEXT
		);
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
