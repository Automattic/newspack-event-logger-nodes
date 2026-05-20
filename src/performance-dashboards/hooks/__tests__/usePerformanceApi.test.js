/**
 * Tests for usePerformanceApi — wraps the substrate CommandClient with
 * input validation and a stable surface for the dashboards.
 *
 * The hook mostly orchestrates: build args, call CommandClient, unwrap
 * response, hand validation/transport errors to the onError callback.
 * We mock the CommandClient module and unwrap module so we can assert
 * the exact wire-level args each verb sees.
 */

jest.mock( '../../../shared/utils/commandClient', () => {
	const send = jest.fn();
	return {
		__esModule: true,
		getCommandClient: jest.fn( () => ( { send } ) ),
		__send: send,
	};
} );

jest.mock( '../../../shared/utils/unwrapCommandResponse', () => ( {
	__esModule: true,
	default: jest.fn( ( msg ) => msg ),
} ) );

import usePerformanceApi from '../usePerformanceApi';
import unwrap from '../../../shared/utils/unwrapCommandResponse';
import { renderHook } from '../../../shared/hooks/__tests__/renderHook';

// `__send` is a spy the commandClient mock exposes (not a real export); pull it
// via requireMock so eslint's import/named doesn't flag it against the real module.
const { __send: mockSend } = jest.requireMock(
	'../../../shared/utils/commandClient'
);

describe( 'usePerformanceApi', () => {
	let onError;
	let api;

	beforeEach( () => {
		mockSend.mockReset();
		unwrap.mockClear();
		unwrap.mockImplementation( ( msg ) => msg );
		onError = jest.fn();
		const hook = renderHook( () => usePerformanceApi( onError ) );
		api = hook.result.current;
	} );

	describe( 'fetchOverview', () => {
		it( 'always sends categories=true and omits server/breakdown when empty', async () => {
			mockSend.mockResolvedValueOnce( { ok: 1 } );
			const out = await api.fetchOverview();
			expect( mockSend ).toHaveBeenCalledWith( {
				to: 'performance',
				verb: 'overview',
				payload: { categories: true },
			} );
			expect( out ).toEqual( { ok: 1 } );
		} );

		it( 'includes server when provided', async () => {
			mockSend.mockResolvedValueOnce( {} );
			await api.fetchOverview( 'web01' );
			expect( mockSend.mock.calls[ 0 ][ 0 ].payload ).toEqual( {
				categories: true,
				server: 'web01',
			} );
		} );

		it( 'joins multiple breakdowns with commas', async () => {
			mockSend.mockResolvedValueOnce( {} );
			await api.fetchOverview( '', [ 'method', 'server' ] );
			expect( mockSend.mock.calls[ 0 ][ 0 ].payload.breakdown ).toBe(
				'method,server'
			);
		} );

		it( 'forwards CommandClient errors to onError and returns null', async () => {
			mockSend.mockRejectedValueOnce( new Error( 'boom' ) );
			const out = await api.fetchOverview();
			expect( out ).toBeNull();
			expect( onError ).toHaveBeenCalledWith( expect.any( Error ) );
		} );
	} );

	describe( 'fetchUrls', () => {
		it( 'defaults to limit=100 with no other params', async () => {
			mockSend.mockResolvedValueOnce( { data: [] } );
			await api.fetchUrls();
			expect( mockSend.mock.calls[ 0 ][ 0 ].payload ).toEqual( {
				limit: 100,
			} );
		} );

		it( 'passes through allowed params', async () => {
			mockSend.mockResolvedValueOnce( {} );
			await api.fetchUrls( {
				search: 'wp-cron',
				sort: 'avg_ms',
				order: 'desc',
				offset: 200,
				server: 'web01',
				disallowed: 'nope', // should be filtered.
			} );
			const args = mockSend.mock.calls[ 0 ][ 0 ].payload;
			expect( args ).toEqual( {
				limit: 100,
				search: 'wp-cron',
				sort: 'avg_ms',
				order: 'desc',
				offset: 200,
				server: 'web01',
			} );
		} );

		it( 'returns null and calls onError on rejection', async () => {
			mockSend.mockRejectedValueOnce( new Error( 'x' ) );
			expect( await api.fetchUrls() ).toBeNull();
			expect( onError ).toHaveBeenCalled();
		} );
	} );

	describe( 'fetchUrlDetail', () => {
		it( 'rejects an invalid hash via onError without sending', async () => {
			const out = await api.fetchUrlDetail( 'NOT-HEX' );
			expect( out ).toBeNull();
			expect( mockSend ).not.toHaveBeenCalled();
			expect( onError ).toHaveBeenCalledWith( expect.any( Error ) );
		} );

		it( 'sends with categories=true on a valid hash', async () => {
			mockSend.mockResolvedValueOnce( {} );
			await api.fetchUrlDetail( 'deadbeef' );
			expect( mockSend.mock.calls[ 0 ][ 0 ].payload ).toEqual( {
				hash: 'deadbeef',
				categories: true,
			} );
		} );

		it( 'forwards rejection to onError', async () => {
			mockSend.mockRejectedValueOnce( new Error( 'down' ) );
			expect( await api.fetchUrlDetail( 'aa' ) ).toBeNull();
			expect( onError ).toHaveBeenCalled();
		} );
	} );

	describe( 'fetchRequestDetail', () => {
		it( 'rejects an invalid rid', async () => {
			expect( await api.fetchRequestDetail( 'has space', 0 ) ).toBeNull();
			expect( onError ).toHaveBeenCalledWith(
				expect.objectContaining( {
					message: 'Invalid request ID format',
				} )
			);
			expect( mockSend ).not.toHaveBeenCalled();
		} );

		it( 'rejects a non-integer partition', async () => {
			expect( await api.fetchRequestDetail( 'valid_id', -1 ) ).toBeNull();
			expect( onError ).toHaveBeenCalledWith(
				expect.objectContaining( {
					message: 'Invalid partition number',
				} )
			);
		} );

		it( 'sends rid+partition on success', async () => {
			mockSend.mockResolvedValueOnce( {} );
			await api.fetchRequestDetail( 'abc123', 5 );
			expect( mockSend.mock.calls[ 0 ][ 0 ].payload ).toEqual( {
				rid: 'abc123',
				partition: 5,
			} );
		} );

		it( 'forwards rejection to onError', async () => {
			mockSend.mockRejectedValueOnce( new Error( 'no' ) );
			expect( await api.fetchRequestDetail( 'abc', 0 ) ).toBeNull();
			expect( onError ).toHaveBeenCalled();
		} );
	} );

	describe( 'fetchBreakdown', () => {
		it( 'returns breakdown_time_series unwrapped from overview', async () => {
			mockSend.mockResolvedValueOnce( {
				breakdown_time_series: { method: [] },
			} );
			const out = await api.fetchBreakdown( 'method' );
			expect( out ).toEqual( { method: [] } );
		} );

		it( 'returns null when overview returns no breakdown', async () => {
			mockSend.mockResolvedValueOnce( {} );
			expect( await api.fetchBreakdown( 'server' ) ).toBeNull();
		} );

		it( 'attaches server filter when provided', async () => {
			mockSend.mockResolvedValueOnce( {} );
			await api.fetchBreakdown( 'method', 'web02' );
			expect( mockSend.mock.calls[ 0 ][ 0 ].payload ).toEqual( {
				breakdown: 'method',
				server: 'web02',
			} );
		} );

		it( 'forwards rejection to onError', async () => {
			mockSend.mockRejectedValueOnce( new Error( 'crash' ) );
			expect( await api.fetchBreakdown( 'method' ) ).toBeNull();
			expect( onError ).toHaveBeenCalled();
		} );
	} );

	describe( 'fetchUrlBreakdown', () => {
		it( 'returns null silently for invalid hash (no onError)', async () => {
			expect( await api.fetchUrlBreakdown( 'NO', 'method' ) ).toBeNull();
			expect( mockSend ).not.toHaveBeenCalled();
			expect( onError ).not.toHaveBeenCalled();
		} );

		it( 'returns breakdown_time_series on success', async () => {
			mockSend.mockResolvedValueOnce( {
				breakdown_time_series: { x: 1 },
			} );
			expect( await api.fetchUrlBreakdown( 'aa', 'method' ) ).toEqual( {
				x: 1,
			} );
		} );

		it( 'returns null when payload has no breakdown_time_series', async () => {
			mockSend.mockResolvedValueOnce( {} );
			expect( await api.fetchUrlBreakdown( 'aa', 'method' ) ).toBeNull();
		} );

		it( 'forwards rejection to onError and returns null', async () => {
			mockSend.mockRejectedValueOnce( new Error( 'oops' ) );
			expect( await api.fetchUrlBreakdown( 'aa', 'method' ) ).toBeNull();
			expect( onError ).toHaveBeenCalled();
		} );
	} );

	describe( 'fetchUrlCategories', () => {
		it( 'returns null silently for invalid hash', async () => {
			expect( await api.fetchUrlCategories( 'NO' ) ).toBeNull();
			expect( onError ).not.toHaveBeenCalled();
		} );

		it( 'returns category_time_series on success', async () => {
			mockSend.mockResolvedValueOnce( {
				category_time_series: { hooks: [] },
			} );
			expect( await api.fetchUrlCategories( 'aa' ) ).toEqual( {
				hooks: [],
			} );
		} );

		it( 'forwards rejection to onError', async () => {
			mockSend.mockRejectedValueOnce( new Error( 'x' ) );
			expect( await api.fetchUrlCategories( 'aa' ) ).toBeNull();
			expect( onError ).toHaveBeenCalled();
		} );
	} );
} );
