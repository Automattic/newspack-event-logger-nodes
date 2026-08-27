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
 * Label text for bright shades: near-black with a faint green cast.
 *
 * Both text colors are fixed, not skin-derived: they only have to clear the
 * contrast threshold against a fill, which either end of the range does.
 *
 * @type {string}
 * @testonly Exported for FlameGraph-colors.test.js.
 */
export const DARK_TEXT = '#0b140d';
/**
 * Label text for deep shades: near-white with a faint green cast.
 *
 * @type {string}
 * @testonly Exported for FlameGraph-colors.test.js.
 */
export const LIGHT_TEXT = '#eafff1';

// Luminance threshold below which a shade needs light text.
const LUMINANCE_THRESHOLD = 0.5;

/**
 * Parse a #rgb / #rrggbb hex or `rgb(r, g, b)` color into an {r, g, b} object.
 *
 * Only those two syntaxes parse. Anything else — a named color, `color-mix()`,
 * the space-separated `rgb(r g b / a)` form, an empty token — yields NaN or
 * undefined channels rather than throwing, which is what `isColorParseable`
 * tests for. Callers taking a color from a CSS custom property must screen it
 * through that guard first, or the NaN silently reaches the fill string.
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
