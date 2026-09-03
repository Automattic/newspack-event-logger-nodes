/**
 * Label-contrast helpers for the flame graph.
 *
 * Frame FILLS come from the shared hook / plugin / custom-event palette
 * (`getStateColor`), so a span reads the same color here as in the Time
 * Breakdown, the Inflight badges and the log rows. This module only measures
 * those fills and picks a label color that stays readable on each one; it
 * holds no palette and no theme knowledge of its own.
 */

/**
 * Label text for bright fills: near-black with a faint green cast.
 *
 * Two fixed inks, one per side of `LUMINANCE_THRESHOLD`, rather than a color
 * derived from the fill: each has to read against half the luminance range, not
 * against one particular color, and deriving one would put palette knowledge
 * back into a module that holds none.
 *
 * @type {string}
 * @testonly Exported for FlameGraph-colors.test.js.
 */
export const DARK_TEXT = '#0b140d';
/**
 * Label text for deep fills: near-white with a faint green cast.
 *
 * @type {string}
 * @testonly Exported for FlameGraph-colors.test.js.
 */
export const LIGHT_TEXT = '#eafff1';

/**
 * The fill luminance dividing the two inks, at the midpoint of the range
 * `relativeLuminance` reports. Above it a label takes `DARK_TEXT`; at or below
 * it, `LIGHT_TEXT`.
 *
 * @type {number}
 */
const LUMINANCE_THRESHOLD = 0.5;

/**
 * Parse a #rgb / #rrggbb hex or `rgb(r, g, b)` color into an {r, g, b} object.
 *
 * Only those two syntaxes parse. Anything else — `transparent`, a named color,
 * `color-mix()`, the space-separated `rgb(r g b / a)` form, an empty token —
 * yields NaN or undefined channels rather than throwing, which is what
 * `isColorParseable` tests for. Screen a fill through that guard before
 * measuring it: NaN compares false against the threshold, so an unparseable
 * color quietly takes `LIGHT_TEXT` rather than failing.
 *
 * @param {string} color Hex (with or without leading #) or `rgb(...)` string.
 * @return {{r: number, g: number, b: number}} RGB channels (0-255).
 * @testonly Exported for FlameGraph-colors.test.js; callers use
 *           isColorParseable() and pickLabelColor().
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
 * Whether a string parses to finite RGB channels (a usable #hex or rgb color).
 *
 * `FlameGraph.js` screens every fill it reads off a `<rect>` through this
 * before measuring it, and leaves the label alone when it fails. An
 * unmeasurable fill is routine rather than a fault: a spacer frame is filled
 * `transparent`, and a `custom_colors` entry is whatever the deployment config
 * wrote, which nothing between there and the palette checks the format of.
 *
 * @param {string} color Candidate color string.
 * @return {boolean} True when `parseColor` yields finite channels.
 */
export const isColorParseable = ( color ) => {
	const { r, g, b } = parseColor( color );
	return Number.isFinite( r ) && Number.isFinite( g ) && Number.isFinite( b );
};

/**
 * Perceived brightness (0-1) of an {r, g, b} color.
 *
 * The sRGB coefficients apply to the gamma-encoded channels directly, skipping
 * the linearization WCAG's relative luminance specifies. That approximation is
 * cheap and monotonic, which is all the label-contrast threshold needs; do not
 * reuse this number for a WCAG contrast-ratio claim.
 *
 * @param {{r: number, g: number, b: number}} rgb RGB channels (0-255).
 * @return {number} Brightness, 0 (black) to 1 (white).
 * @testonly Exported for FlameGraph-colors.test.js; callers use
 *           pickLabelColor().
 */
export const relativeLuminance = ( { r, g, b } ) =>
	( 0.2126 * r + 0.7152 * g + 0.0722 * b ) / 255;

/**
 * Pick a readable label color for a frame fill.
 *
 * `FlameGraph.js` feeds this the `fill` attribute it reads back off each
 * rendered `<rect>`, so the argument is whatever the palette produced.
 *
 * @param {string} color CSS color string (`rgb(...)` or hex).
 * @return {string} DARK_TEXT for bright fills, LIGHT_TEXT for dark fills.
 */
export const pickLabelColor = ( color ) =>
	relativeLuminance( parseColor( color ) ) > LUMINANCE_THRESHOLD
		? DARK_TEXT
		: LIGHT_TEXT;
