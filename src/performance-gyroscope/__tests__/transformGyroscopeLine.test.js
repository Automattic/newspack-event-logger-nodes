/**
 * Pure transform: Message envelope from /messages/stream?subscribe=gyroscope
 * → `{type: 'inflight' | 'complete', requests | request}` dispatch shape
 * the Inflight dashboard consumes.
 *
 * The `gyroscope.log` source carries two interleaved record types
 * (pre-aggregated upstream by `RequestFlight` + `completed:tee`):
 *   * `KEY = 'inflight'` + VALUE = array of in-flight request snapshots
 *     (`RequestFlight` emits these on its periodic fire).
 *   * `KEY = <rid>` + VALUE = single completed request object
 *     (RequestBuilder's `completed:tee` fans this out at request-complete).
 *
 * The legacy `GyroscopeStreamController` synthesized these two event
 * types server-side via `InflightTracker`. M6.8 deletes that class —
 * gyroscope.log already carries the dispatch.
 */

import transformGyroscopeLine from '../transformGyroscopeLine';

const KEY = 5;
const VALUE = 6;

function envelope( { key = '', value = '' } = {} ) {
	const m = [ 0, 0, '', '', '', '', '' ];
	m[ KEY ] = key;
	m[ VALUE ] = value;
	return m;
}

describe( 'transformGyroscopeLine', () => {
	it( 'identifies inflight envelopes by KEY="inflight" + array VALUE', () => {
		const env = envelope( {
			key: 'inflight',
			value: [
				{ rid: 'a', method: 'GET', url: '/x', state: 'init' },
				{ rid: 'b', method: 'POST', url: '/y', state: 'mid' },
			],
		} );
		const out = transformGyroscopeLine( env );
		expect( out.type ).toBe( 'inflight' );
		expect( out.requests ).toHaveLength( 2 );
		expect( out.requests[ 0 ].rid ).toBe( 'a' );
	} );

	it( 'identifies completion envelopes by single-object VALUE with rid', () => {
		const env = envelope( {
			key: 'rid-abc',
			value: {
				rid: 'rid-abc',
				method: 'POST',
				url: '/done',
				duration_ms: 42,
				status_code: 200,
				state: 'complete',
			},
		} );
		const out = transformGyroscopeLine( env );
		expect( out.type ).toBe( 'complete' );
		expect( out.request.rid ).toBe( 'rid-abc' );
		expect( out.request.duration_ms ).toBe( 42 );
	} );

	it( 'skips the substrate connected envelope', () => {
		const env = envelope( {
			key: 'connected',
			value: { pid: 1, slot: 0 },
		} );
		expect( transformGyroscopeLine( env ) ).toBeNull();
	} );

	it( 'returns null for an unrecognized shape', () => {
		expect(
			transformGyroscopeLine( envelope( { key: 'x', value: 'string' } ) )
		).toBeNull();
		expect(
			transformGyroscopeLine( envelope( { key: 'x', value: null } ) )
		).toBeNull();
	} );

	it( 'returns null for object VALUE missing rid (defensive)', () => {
		const env = envelope( {
			key: 'x',
			value: { method: 'GET', url: '/y' },
		} );
		expect( transformGyroscopeLine( env ) ).toBeNull();
	} );
} );
