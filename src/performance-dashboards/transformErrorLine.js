/**
 * Transform a Message envelope from /messages/stream?subscribe=errors
 * into the `{rid, ts, k, m, n}` row shape the Error Log dashboard
 * renders. Mirror of the legacy `ErrorsStreamController::transform_line()`
 * — moved client-side now that the unified endpoint streams parsed
 * Message envelopes instead of pre-transformed batches.
 *
 * @param {Array} envelope 7-field Message array.
 * @return {Object|null} Row, or `null` if the envelope has no rid or
 *                       the VALUE shape isn't an object.
 */
const KEY = 5;
const VALUE = 6;

const MAX_M_LENGTH = 1000;

export default function transformErrorLine( envelope ) {
	const rid = envelope[ KEY ];
	if ( ! rid ) {
		return null;
	}
	const value = envelope[ VALUE ];
	if ( ! value || typeof value !== 'object' || Array.isArray( value ) ) {
		return null;
	}

	let m = value.m || '';
	if ( typeof m === 'string' && m.length > MAX_M_LENGTH ) {
		m = m.substring( 0, MAX_M_LENGTH ) + '...';
	}

	return {
		rid,
		ts: value.ts || 0,
		k: value.k || '',
		m,
		n: value.n || 0,
	};
}
