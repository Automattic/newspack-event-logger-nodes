/**
 * Theme-aware, depth-shaded flame graph palette.
 *
 * Each frame's fill is a shade of the active hub theme's accent, graduated by
 * stack depth: depth 0 (the request root at the base) is the pure accent, and
 * deeper frames mix toward the theme background without ever reaching it. Frame
 * labels get a contrasting color so they stay readable on every shade.
 */

// Mix grows with depth, capped so deep frames keep accent vs background.
const FRACTION_PER_DEPTH = 0.13;
const MAX_FRACTION = 0.65;

// Label text colors: near-black on bright shades, near-white on deep.
/** @testonly Exported for FlameGraph-colors.test.js. */
export const DARK_TEXT = '#0b140d';
/** @testonly Exported for FlameGraph-colors.test.js. */
export const LIGHT_TEXT = '#eafff1';

// Luminance threshold below which a shade needs light text.
const LUMINANCE_THRESHOLD = 0.5;

/**
 * Parse a #rgb / #rrggbb hex or `rgb(r, g, b)` color into an {r, g, b} object.
 *
 * @param {string} color Hex (with or without leading #) or `rgb(...)` string.
 * @return {{r: number, g: number, b: number}} RGB channels (0-255).
 * @testonly Exported for FlameGraph-colors.test.js; callers use textOn().
 */
export const parseColor = ( color ) => {
	const str = String( color ).trim();
	if ( str.startsWith( 'rgb' ) ) {
		const [ r, g, b ] = str
			.replace( /[^\d,]/g, '' )
			.split( ',' )
			.map( Number );
		return { r, g, b };
	}
	let body = str.replace( /^#/, '' );
	if ( 3 === body.length ) {
		body = body
			.split( '' )
			.map( ( ch ) => ch + ch )
			.join( '' );
	}
	return {
		r: parseInt( body.slice( 0, 2 ), 16 ),
		g: parseInt( body.slice( 2, 4 ), 16 ),
		b: parseInt( body.slice( 4, 6 ), 16 ),
	};
};

/**
 * Whether a string parses to finite RGB channels (a usable #hex or rgb() color).
 * Lets the token fallback chain skip a theme value that isn't a plain color
 * (a named color or `color-mix(...)` would otherwise yield NaN fills).
 *
 * @param {string} color Candidate color string.
 * @return {boolean} True when `parseColor` yields finite channels.
 */
export const isColorParseable = ( color ) => {
	const { r, g, b } = parseColor( color );
	return Number.isFinite( r ) && Number.isFinite( g ) && Number.isFinite( b );
};

/**
 * Mix the accent toward the background by a depth-graduated fraction.
 *
 * @param {number} depth  Stack depth (0 = root at the base, brightest).
 * @param {string} accent Theme accent hex.
 * @param {string} bg     Theme background hex.
 * @return {string} CSS `rgb(r, g, b)` color string.
 */
export const shadeForDepth = ( depth, accent, bg ) => {
	const fraction = Math.min( MAX_FRACTION, depth * FRACTION_PER_DEPTH );
	const a = parseColor( accent );
	const b = parseColor( bg );
	const lerp = ( from, to ) => Math.round( from + ( to - from ) * fraction );
	return `rgb(${ lerp( a.r, b.r ) }, ${ lerp( a.g, b.g ) }, ${ lerp(
		a.b,
		b.b
	) })`;
};

/**
 * Relative luminance (0-1) of an {r, g, b} color via the sRGB coefficients.
 *
 * @param {{r: number, g: number, b: number}} rgb RGB channels (0-255).
 * @return {number} Relative luminance, 0 (black) to 1 (white).
 * @testonly Exported for FlameGraph-colors.test.js; callers use textOn().
 */
export const relativeLuminance = ( { r, g, b } ) =>
	( 0.2126 * r + 0.7152 * g + 0.0722 * b ) / 255;

/**
 * Pick a readable label color for a frame fill.
 *
 * @param {string} color CSS color string (`rgb(...)` or hex).
 * @return {string} DARK_TEXT for bright fills, LIGHT_TEXT for dark fills.
 */
export const pickLabelColor = ( color ) =>
	relativeLuminance( parseColor( color ) ) > LUMINANCE_THRESHOLD
		? DARK_TEXT
		: LIGHT_TEXT;
