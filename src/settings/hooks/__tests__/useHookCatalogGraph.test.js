/**
 * useHookCatalogGraph tests — the Performance Logger hook-catalog slice clipped
 * onto the substrate's batched-poll toolkit (exospine + `_http` + a tick).
 *
 * The hook emits `hooks_registered` as a TM_COMMAND through the interpreter
 * (FROM=`hookcatalog:in`, TO=`_shell/_http/performance`); the reply routes via
 * TO=FROM back into `hookcatalog:view`, which extracts hooks_by_category.
 *
 * Every node sinks into the interpreter (rule #2); flow is steered ONLY by each
 * node's `target`. Nothing is injected: the seam is `fetch`, so the hook never
 * touches the network. `isOpen` is the gate: a picker that is never opened asks
 * for nothing, and an opened one asks on the tick until it is closed again.
 */

import { renderHook, act, waitFor } from '../../../test-helpers/renderHook';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import {
	newMessage,
	ID,
	TO,
	FROM,
	VALUE,
	TYPE,
	TM_COMMAND,
	TM_RESPONSE,
	Core,
	forgetSession,
	__setAuthFetch,
} from '@newspack-nodes/runtime';
import { useHookCatalogGraph } from '../useHookCatalogGraph';

const INTERPRETER = '_command_interpreter';
const ROUTER = '_router';
const HTTP = '_http';
const RECEIVER = 'hookcatalog:in';
const FETCHER = 'hookcatalog:fetch';
const VIEW = 'hookcatalog:view';
const ALL_GRAPH_NAMES = [ HTTP, RECEIVER, VIEW ];

// The ask rides the router tick, so a dispatch is a wait, not a flush.
const waitForBatch = ( wire, atLeast = 1 ) =>
	waitFor(
		() => expect( wire.batches.length ).toBeGreaterThanOrEqual( atLeast ),
		{
			timeout: 6000,
		}
	);

// A fake transport (HttpOutNode seam): postBatch returns TO=FROM replies.
// The seam is the WIRE: the graph packs, POSTs and unpacks for real, so
// HttpOut, the router and the interpreter all run. `wire.batches` is what was
// posted; a verb in `errorVerbs` answers TM_ERROR carrying its payload.
function installWire( payloadByVerb = {}, opts = {} ) {
	return installFakeCommandWire( ( m ) => {
		const name = m[ VALUE ]?.name;
		const payload = payloadByVerb[ name ] ?? payloadByVerb._default ?? null;
		if ( ! opts.errorVerbs?.includes( name ) ) {
			return payload;
		}
		// answerBatch ships an Error as its `.message`, so unwrap first.
		return new Error( payload?.message ?? payload ?? name );
	} );
}

beforeEach( () => {
	Core.reset();
} );

describe( 'useHookCatalogGraph — exospine + I/O boundary wiring', () => {
	test( 'mounts the backbone + _http + the slice', () => {
		installWire();
		renderHook( () => useHookCatalogGraph( { isOpen: false } ) );
		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		expect( Core.node( HTTP ).sink ).toBe( interpreter );
		// The Fetcher reaches `_shell` — the Tap every command passes — as a
		// TARGET hop; it sinks into the interpreter.
		expect( Core.node( RECEIVER ).sink ).toBe( interpreter );
		expect( Core.node( FETCHER ).target ).toBe(
			`_shell/${ HTTP }/performance`
		);
	} );

	/** Opening the modal must not mint before /auth has landed. */
	test( 'signs the catalog fetch fired on open', async () => {
		forgetSession();
		__setAuthFetch( async () => ( {
			handle: 'eeee5555eeee5555eeee5555eeee5555',
			key: 'key-hookcatalog-late-auth',
			expires_in: 3600,
			now: 1771000000,
		} ) );
		const wire = installWire();

		renderHook( () => useHookCatalogGraph( { isOpen: true } ) );

		await waitForBatch( wire );
		expect( wire.batches[ 0 ][ 0 ][ VALUE ].auth ).toBeDefined();
	}, 15000 );

	test( 'does NOT mount _output / _completion / _uptime / _cwd (dashboards are not REPLs)', () => {
		installWire();
		renderHook( () => useHookCatalogGraph( { isOpen: false } ) );
		for ( const name of [ '_output', '_completion', '_uptime', '_cwd' ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );

	test( 'mounts _http without injecting anything into it', () => {
		installWire();
		renderHook( () => useHookCatalogGraph( { isOpen: false } ) );
		// Closed, so nothing has posted yet — HttpOut defaults at first post.
		expect( Core.node( HTTP ) ).toBeTruthy();
	} );

	test( 'does NOT fire any command while isOpen is false', () => {
		const wire = installWire();
		renderHook( () => useHookCatalogGraph( { isOpen: false } ) );
		expect( wire.batches.length ).toBe( 0 );
	} );

	// Closed means nothing is being asked for, so nothing is loading.
	test( 'returns the default render model before any fetch', () => {
		installWire();
		const { result } = renderHook( () =>
			useHookCatalogGraph( { isOpen: false } )
		);
		expect( result.current.hooksByCategory ).toEqual( {} );
		expect( result.current.loading ).toBe( false );
	} );
} );

describe( 'useHookCatalogGraph — fire on open routes through the exospine', () => {
	test( 'flipping isOpen true dispatches a hooks_registered command via _http', async () => {
		const wire = installWire( {
			hooks_registered: { hooks_by_category: {} },
		} );
		const { rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false } }
		);
		expect( wire.batches.length ).toBe( 0 );
		await act( async () => {
			rerender( { isOpen: true } );
		} );
		await waitForBatch( wire );
		const msg = wire.batches[ 0 ][ 0 ];
		expect( msg[ TO ] ).toBe( 'performance' );
		expect( msg[ FROM ] ).toBe( RECEIVER );
		// Addressed, not correlated.
		expect( msg[ ID ] ).toBe( '' );
		expect( msg[ VALUE ].name ).toBe( 'hooks_registered' );
		// hooks_registered takes no args; empty token array, no payload.
		expect( msg[ VALUE ].arguments ).toEqual( [] );
		expect( msg[ VALUE ].payload ).toBeUndefined();
	}, 15000 );

	test( 'the resolved catalog routes back to the node that asked, into the model', async () => {
		const hooks = {
			Lifecycle: [ 'init' ],
			'REST API': [ 'rest_api_init' ],
		};
		installWire( {
			hooks_registered: { hooks_by_category: hooks, total_hooks: 2 },
		} );
		const { result, rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false } }
		);
		act( () => {
			rerender( { isOpen: true } );
		} );
		await waitFor(
			() => expect( result.current.hooksByCategory ).toEqual( hooks ),
			{ timeout: 6000 }
		);
		expect( result.current.loading ).toBe( false );
	}, 15000 );

	test( 'loading is true between dispatch and resolve', async () => {
		// A wire that never answers: loading stays true.
		installFakeCommandWire( () => new Promise( () => {} ) );
		const { result, rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false } }
		);
		await act( async () => {
			rerender( { isOpen: true } );
		} );
		expect( result.current.loading ).toBe( true );
	} );

	// An open picker keeps asking; the taxonomy tracks a plugin activated
	// while it is on screen, and a refusal recovers without a re-open.
	test( 'keeps asking while the picker stays open', async () => {
		const wire = installWire( {
			hooks_registered: { hooks_by_category: {} },
		} );
		renderHook( () => useHookCatalogGraph( { isOpen: true } ) );

		await waitForBatch( wire );
		const afterFirst = wire.batches.length;
		await waitFor(
			() => expect( wire.batches.length ).toBeGreaterThan( afterFirst ),
			{ timeout: 15000 }
		);
	}, 25000 );
} );

describe( 'useHookCatalogGraph — fetch errors fall back to an empty map (mirrors old modal)', () => {
	// A fetch failure clears loading + empties the catalog (no error UI).
	test( 'an error reply clears loading without throwing', async () => {
		installWire(
			{ hooks_registered: 'capability check failed' },
			{ errorVerbs: [ 'hooks_registered' ] }
		);
		const { result, rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false } }
		);
		await act( async () => {
			rerender( { isOpen: true } );
		} );
		// The refusal publishes as the slice's error, clearing the spinner.
		await waitFor( () => expect( result.current.loading ).toBe( false ), {
			timeout: 6000,
		} );
		expect( result.current.hooksByCategory ).toEqual( {} );
	}, 15000 );
} );

describe( 'useHookCatalogGraph — teardown', () => {
	test( 'unmount unregisters every graph node + the backbone', () => {
		installWire();
		const { unmount } = renderHook( () =>
			useHookCatalogGraph( { isOpen: false } )
		);
		unmount();
		// The ROUTER is the page's heartbeat and is never torn down.
		for ( const name of [ ...ALL_GRAPH_NAMES, INTERPRETER ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );

	test( 'a reply resolving after unmount does not throw (sink may be gone)', async () => {
		let resolveReply;
		// replyFor may return a promise, and answerBatch awaits it — so the
		// reply lands whenever this resolves, which here is after unmount.
		installFakeCommandWire(
			() => new Promise( ( res ) => ( resolveReply = res ) )
		);
		const { unmount, rerender } = renderHook(
			( props ) => useHookCatalogGraph( props ),
			{ initialProps: { isOpen: false } }
		);
		await act( async () => {
			rerender( { isOpen: true } );
		} );
		unmount();
		expect( () => {
			const reply = newMessage();
			reply[ TYPE ] = TM_COMMAND | TM_RESPONSE;
			reply[ VALUE ] = {
				name: 'hooks_registered',
				payload: { hooks_by_category: {} },
			};
			resolveReply( [ reply ] );
		} ).not.toThrow();
		await Promise.resolve();
	} );
} );
