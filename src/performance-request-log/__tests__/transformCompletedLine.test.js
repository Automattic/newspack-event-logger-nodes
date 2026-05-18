/**
 * Pure transform: Message envelope from /messages/stream?subscribe=completed
 * → `{rid, url, method, duration_ms, status_code, end_time, start_time,
 *    remote_addr, user_agent, error_status, state}` shape the Request Stream
 * dashboard renders. Mirror of the legacy
 * `RequestsStreamController::transform_line()`.
 *
 * Source change vs legacy: legacy tailed `requests.log` (live + completed
 * mixed) and inferred completion from message presence. The new subscription
 * is `completed.log` (already filtered upstream by the `completed:tee` node).
 * Every line is a completion, so the only filter is "drop entries with no
 * `url` (defensive against malformed payloads)".
 */

import transformCompletedLine from '../transformCompletedLine';

const VALUE = 6;

function envelope( req = null ) {
	const m = [ 0, 0, '', '', '', '', '' ];
	m[ VALUE ] = req;
	return m;
}

describe( 'transformCompletedLine', () => {
	it( 'maps a completed-request envelope to the dashboard row shape', () => {
		const env = envelope( {
			rid: 'abc',
			method: 'POST',
			url: 'https://example.com/x',
			start_time: 100,
			end_time: 101,
			duration_ms: 1234,
			status_code: 200,
			state: 'complete',
			error_status: '-',
			remote_addr: '10.0.0.1',
			user_agent: 'curl/7.0',
		} );
		expect( transformCompletedLine( env ) ).toEqual( {
			rid: 'abc',
			method: 'POST',
			url: 'https://example.com/x',
			start_time: 100,
			end_time: 101,
			duration_ms: 1234,
			status_code: 200,
			state: 'complete',
			error_status: '-',
			remote_addr: '10.0.0.1',
			user_agent: 'curl/7.0',
		} );
	} );

	it( 'returns null when VALUE has no url (defensive)', () => {
		expect(
			transformCompletedLine( envelope( { rid: 'no-url' } ) )
		).toBeNull();
	} );

	it( 'returns null when VALUE is not an object', () => {
		expect( transformCompletedLine( envelope( 'string' ) ) ).toBeNull();
		expect( transformCompletedLine( envelope( null ) ) ).toBeNull();
	} );

	it( 'clips url to 2000 chars + ellipsis', () => {
		const longUrl = 'https://x/' + 'a'.repeat( 5000 );
		const env = envelope( {
			rid: 'r',
			method: 'GET',
			url: longUrl,
			duration_ms: 1,
		} );
		const out = transformCompletedLine( env );
		expect( out.url.length ).toBe( 2003 );
		expect( out.url.endsWith( '...' ) ).toBe( true );
	} );

	it( 'clips user_agent to 500 chars + ellipsis', () => {
		const longUA = 'a'.repeat( 1000 );
		const env = envelope( {
			rid: 'r',
			method: 'GET',
			url: 'https://x',
			user_agent: longUA,
		} );
		const out = transformCompletedLine( env );
		expect( out.user_agent.length ).toBe( 503 );
		expect( out.user_agent.endsWith( '...' ) ).toBe( true );
	} );

	it( 'defaults missing fields', () => {
		const env = envelope( { url: 'https://x' } );
		const out = transformCompletedLine( env );
		expect( out.method ).toBe( 'GET' );
		expect( out.duration_ms ).toBe( 0 );
		expect( out.status_code ).toBe( 0 );
		expect( out.error_status ).toBe( '-' );
		expect( out.user_agent ).toBe( '' );
		expect( out.remote_addr ).toBe( '' );
	} );
} );
