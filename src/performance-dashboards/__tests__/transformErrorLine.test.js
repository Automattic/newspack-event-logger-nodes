/**
 * Pure transform: Message envelope from /messages/stream?subscribe=errors
 * → `{rid, ts, k, m, n}` row shape the Error Log dashboard renders.
 *
 * Mirrors the legacy `ErrorsStreamController::transform_line()`:
 *   * Drop entries with no rid (`KEY` field).
 *   * Pull `ts`/`k`/`m`/`n` out of the application payload (`VALUE`).
 *   * Clip `m` to 1000 chars + ellipsis.
 */

import transformErrorLine from '../transformErrorLine';

const KEY = 5;
const VALUE = 6;

function envelope( { rid = '', value = null } = {} ) {
	const m = [ 0, 0, '', '', '', '', '' ];
	m[ KEY ] = rid;
	m[ VALUE ] = value;
	return m;
}

describe( 'transformErrorLine', () => {
	it( 'returns the full row for a well-formed error envelope', () => {
		const env = envelope( {
			rid: 'abc-rid',
			value: { ts: 1.5, k: 'PHP_NOTICE', m: 'Undefined index', n: 7 },
		} );
		expect( transformErrorLine( env ) ).toEqual( {
			rid: 'abc-rid',
			ts: 1.5,
			k: 'PHP_NOTICE',
			m: 'Undefined index',
			n: 7,
		} );
	} );

	it( 'drops envelopes without rid', () => {
		const env = envelope( {
			rid: '',
			value: { k: 'X', m: 'no rid', ts: 1 },
		} );
		expect( transformErrorLine( env ) ).toBeNull();
	} );

	it( 'drops envelopes with non-object VALUE', () => {
		const env = envelope( { rid: 'rid', value: 'just a string' } );
		expect( transformErrorLine( env ) ).toBeNull();
	} );

	it( 'clips m to 1000 chars + ellipsis', () => {
		const env = envelope( {
			rid: 'rid',
			value: { ts: 1, k: 'K', m: 'x'.repeat( 2000 ), n: 0 },
		} );
		const out = transformErrorLine( env );
		expect( out.m.length ).toBe( 1003 );
		expect( out.m.endsWith( '...' ) ).toBe( true );
	} );

	it( 'defaults missing optional fields', () => {
		const env = envelope( { rid: 'rid', value: {} } );
		expect( transformErrorLine( env ) ).toEqual( {
			rid: 'rid',
			ts: 0,
			k: '',
			m: '',
			n: 0,
		} );
	} );
} );
