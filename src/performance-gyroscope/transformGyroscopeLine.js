/**
 * Transform a Message envelope from /messages/stream?subscribe=gyroscope
 * into the dispatch shape the Inflight dashboard consumes.
 *
 * `gyroscope.log` carries two interleaved record types, pre-aggregated
 * upstream by RequestFlight (periodic inflight snapshots) and the
 * topology's `completed:tee` (per-request completion fan-out). The
 * legacy `GyroscopeStreamController` used `InflightTracker` server-side
 * to synthesize the same two event types from raw firehose lines — this
 * client-side mapper makes that whole layer redundant; M6.8 deletes it.
 *
 * @param {Array} envelope 7-field Message array.
 * @return {{type: 'inflight', requests: Array}
 *          | {type: 'complete', request: Object}
 *          | null}
 */
const KEY = 5;
const VALUE = 6;

export default function transformGyroscopeLine( envelope ) {
	const key = envelope[ KEY ];
	const value = envelope[ VALUE ];

	// Substrate `connected` envelope — never a gyroscope record.
	if ( key === 'connected' ) {
		return null;
	}

	if ( key === 'inflight' && Array.isArray( value ) ) {
		return { type: 'inflight', requests: value };
	}

	if (
		value &&
		typeof value === 'object' &&
		! Array.isArray( value ) &&
		value.rid
	) {
		return { type: 'complete', request: value };
	}

	return null;
}
