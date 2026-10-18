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
import * as d3 from 'd3';
import {
	applyFrameColors,
	createColorMapper,
	framePlaces,
} from '../FlameGraph';
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

describe( 'framePlaces', () => {
	it( 'places a listed category at its rank and an unlisted one after the list, on first sight', () => {
		const placeOf = framePlaces( [ 'kea-7055', 'kea-7058' ] );
		expect( placeOf( 'kea-7058' ) ).toBe( 1 );
		expect( placeOf( 'kea-7057: /detail-7057' ) ).toBe( 2 );
		expect( placeOf( 'kea-7056' ) ).toBe( 3 );
		expect( placeOf( 'kea-7057' ) ).toBe( 2 );
		expect( placeOf( 'kea-7055: /detail-7055' ) ).toBe( 0 );
	} );
} );

describe( 'applyFrameColors', () => {
	/**
	 * Draw frames as d3-flame-graph does: a `g.frame` per node holding its
	 * attribute-filled rect and its label div.
	 *
	 * @param {Array<[string, string]>} frames Name and fill per frame.
	 * @return {HTMLElement} The container.
	 */
	const drawFrames = ( frames ) => {
		const container = document.createElement( 'div' );
		const g = d3
			.select( container )
			.append( 'svg' )
			.selectAll( 'g.frame' )
			.data( frames.map( ( [ name ] ) => ( { data: { name } } ) ) )
			.join( 'g' )
			.attr( 'class', 'frame' );
		g.append( 'rect' ).attr( 'fill', ( _d, i ) => frames[ i ][ 1 ] );
		g.append( 'foreignObject' )
			.append( 'xhtml:div' )
			.attr( 'class', 'd3-flame-graph-label' );
		return container;
	};
	const paints = ( container ) =>
		[ ...container.querySelectorAll( 'g.frame' ) ].map( ( frame ) => [
			frame.querySelector( 'rect' ).style.getPropertyValue( 'fill' ),
			frame
				.querySelector( '.d3-flame-graph-label' )
				.style.getPropertyValue( 'color' ),
		] );

	it( "paints each frame its category's CRT green, its label the matching ink, on every pass", () => {
		const container = drawFrames( [
			[ 'kea-7056: /detail-7056', '#123456' ],
			[ 'kea-7055', '#abcdef' ],
			[ 'spacer-7059', 'transparent' ],
		] );
		const expected = [
			[
				'var(--phosphor-2, #123456)',
				`var(--phosphor-ink-2, ${ pickLabelColor( '#123456' ) })`,
			],
			[
				'var(--phosphor-1, #abcdef)',
				`var(--phosphor-ink-1, ${ pickLabelColor( '#abcdef' ) })`,
			],
			[ '', '' ],
		];

		applyFrameColors( container, [ 'kea-7055' ] );
		expect( paints( container ) ).toEqual( expected );

		applyFrameColors( container, [ 'kea-7055' ] );
		expect( paints( container ) ).toEqual( expected );
	} );

	it( 'paints a fill it cannot parse its green too, and leaves its label ink alone', () => {
		const container = drawFrames( [
			[ 'kea-7079', 'hsl(120, 40%, 40%)' ],
		] );

		applyFrameColors( container, [] );

		const [ [ fill, ink ] ] = paints( container );
		expect( fill ).toMatch( /^var\(--phosphor-1, hsl\(120, 40%, 40%\)\)$/ );
		expect( ink ).toBe( '' );
	} );

	it( 'clears what an earlier paint left when a frame it painted turns spacer or unparseable', () => {
		const container = drawFrames( [
			[ 'kea-7082', '#abcdef' ],
			[ 'kea-7083', '#123456' ],
		] );
		applyFrameColors( container, [] );
		const [ spacer, odd ] = container.querySelectorAll( 'g.frame rect' );
		spacer.setAttribute( 'fill', 'transparent' );
		odd.setAttribute( 'fill', 'hsl(200, 30%, 30%)' );

		applyFrameColors( container, [] );

		expect( paints( container ) ).toEqual( [
			[ '', '' ],
			// The spacer takes no place, so this is the first category seen.
			[ 'var(--phosphor-1, hsl(200, 30%, 30%))', '' ],
		] );
	} );
} );
