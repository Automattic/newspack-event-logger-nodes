/**
 * useRulesGraph tests — the per-URL logging-ruleset editor graph, clipped onto
 * the substrate's I/O boundary node (exospine + `_http`) with ONE receiver Tee
 * (`rules:in`) in front of the `rules:view` model node. Modeled on
 * useVaultGraph: each verb dispatches a TM_COMMAND through the interpreter
 * (the table's list FROM = `rules:in`, each mutation FROM its own Request node,
 * TO = `_http/rules`, verb in VALUE.name); the reply routes
 * TO=FROM back into the Tee, which fans it to the view. Nothing is injected:
 * the seam is `fetch`, so the hook never touches the real network.
 *
 * The wire contract mirrors Rules_CI_Node: `save`/`upsert` take the RAW JSON as
 * the command arguments string (the handler json_decodes the whole arg);
 * `delete` takes the id as a positional token; `list` takes no args.
 */

import { renderHook, act, waitFor } from '../../test-helpers/renderHook';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import {
	ID,
	TO,
	FROM,
	VALUE,
	Core,
	formatCommandArgs,
	forgetSession,
	__setAuthFetch,
} from '@newspack-nodes/runtime';
import { useRulesGraph } from '../useRulesGraph';

const INTERPRETER = '_command_interpreter';
const ROUTER = '_router';
const HTTP = '_http';
const RECV = 'rules:in';
const VIEW = 'rules:view';
const ALL_GRAPH_NAMES = [ HTTP, RECV, VIEW ];

const SAMPLE_RULES = [
	{
		id: 'r1',
		pattern: '/blog',
		action: 'log',
		auto_disable_threshold: 0,
		auto_protect_time_threshold: 0,
		significant_events: [],
		custom_events: [],
		hooks: [ 'init' ],
		hooks_in: 'inline',
	},
	{
		id: 'r2',
		pattern: '/admin',
		action: 'skip',
		auto_disable_threshold: 0,
		auto_protect_time_threshold: 0,
		significant_events: [],
		custom_events: [],
		hooks: [],
		hooks_in: 'inline',
	},
];

// A fake transport (HttpOutNode seam): postBatch echoes TO=FROM replies.
function findVerb( batches, verb ) {
	for ( const batch of batches ) {
		for ( const m of batch ) {
			if ( m[ VALUE ]?.name === verb ) {
				return m;
			}
		}
	}
	return null;
}

function countVerbs( batches, verb ) {
	let count = 0;
	for ( const batch of batches ) {
		for ( const m of batch ) {
			if ( m[ VALUE ]?.name === verb ) {
				count += 1;
			}
		}
	}
	return count;
}

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

describe( 'useRulesGraph — exospine + receiver wiring', () => {
	test( 'mounts the backbone + _http + the receiver Tee + the view, each sinking into the interpreter', async () => {
		installWire();
		renderHook( () => useRulesGraph() );
		await act( async () => {} );
		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		for ( const name of ALL_GRAPH_NAMES ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
	} );

	test( 'the receiver Tee fans to exactly the view', async () => {
		installWire();
		renderHook( () => useRulesGraph() );
		await act( async () => {} );
		expect( Core.node( RECV ).target ).toEqual( [ VIEW ] );
	} );

	test( 'does NOT mount the REPL-only nodes', async () => {
		installWire();
		renderHook( () => useRulesGraph() );
		await act( async () => {} );
		for ( const name of [ '_output', '_completion', '_uptime', '_cwd' ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );

	test( '_http reaches the wire with nothing injected', async () => {
		const wire = installWire();
		renderHook( () => useRulesGraph() );
		await act( async () => {} );
		// HttpOut defaults its own client lazily, at the first post.
		expect( wire.batches.flat() ).not.toHaveLength( 0 );
	} );

	test( 'fires one immediate list() on mount, FROM the receiver, TO _http/rules', async () => {
		const wire = installWire();
		renderHook( () => useRulesGraph() );
		await act( async () => {} );
		expect( wire.batches.length ).toBeGreaterThanOrEqual( 1 );
		const msg = wire.batches[ 0 ][ 0 ];
		expect( msg[ TO ] ).toBe( 'rules' );
		expect( msg[ FROM ] ).toBe( RECV );
		expect( msg[ VALUE ].name ).toBe( 'list' );
	} );

	/**
	 * Mount races /auth: the graph is built synchronously, the session arrives a
	 * round trip later. Firing the list before then mints it UNSIGNED and the
	 * server refuses it — the page then looked "half-working", alive only because
	 * a later user-triggered refresh happened to run after auth landed.
	 */
	test( 'holds the mount-time list until the session is established', async () => {
		forgetSession();
		__setAuthFetch( async () => ( {
			handle: 'cccc3333cccc3333cccc3333cccc3333',
			key: 'key-late-auth',
			expires_in: 3600,
			now: 1771000000,
		} ) );
		const wire = installWire();

		renderHook( () => useRulesGraph() );
		await act( async () => {} );

		expect( wire.batches.length ).toBeGreaterThanOrEqual( 1 );
		expect( wire.batches[ 0 ][ 0 ][ VALUE ].auth ).toBeDefined();
	} );

	/** A click during the /auth round trip must still mint a signed command. */
	test( 'signs a dispatch fired while the session is still in flight', async () => {
		forgetSession();
		let landAuth;
		const inFlight = new Promise( ( resolve ) => {
			landAuth = resolve;
		} );
		__setAuthFetch( () =>
			inFlight.then( () => ( {
				handle: 'aaaa9999aaaa9999aaaa9999aaaa9999',
				key: 'key-rules-in-flight',
				expires_in: 3600,
				now: 1771000000,
			} ) )
		);
		const wire = installWire( { list: { rules: [] } } );
		const { result } = renderHook( () => useRulesGraph() );
		await act( async () => {} );

		await act( async () => {
			const pending = result.current.list();
			landAuth();
			await pending;
		} );

		const listed = wire.batches
			.flat()
			.filter( ( m ) => 'list' === m[ VALUE ]?.name );
		expect( listed.length ).toBeGreaterThanOrEqual( 1 );
		expect( listed[ 0 ][ VALUE ].auth ).toBeDefined();
	} );

	test( 'exposes the model + CRUD callbacks', async () => {
		installWire();
		const { result } = renderHook( () => useRulesGraph() );
		await act( async () => {} );
		expect( Array.isArray( result.current.rules ) ).toBe( true );
		expect( typeof result.current.list ).toBe( 'function' );
		expect( typeof result.current.saveAll ).toBe( 'function' );
		expect( typeof result.current.upsert ).toBe( 'function' );
		expect( typeof result.current.remove ).toBe( 'function' );
		expect( typeof result.current.reset ).toBe( 'function' );
	} );
} );

describe( 'useRulesGraph — list populates rules', () => {
	test( 'an immediate list reply lands in the view model and the hook return', async () => {
		installWire( { list: { rules: SAMPLE_RULES } } );
		const { result } = renderHook( () => useRulesGraph() );
		await act( async () => {} );

		const view = Core.node( VIEW );
		expect( view.setStateCache.view.rules ).toHaveLength( 2 );
		expect( view.setStateCache.view.loading ).toBe( false );
		expect( view.setStateCache.view.error ).toBeNull();
		expect( result.current.rules.map( ( r ) => r.id ) ).toEqual( [
			'r1',
			'r2',
		] );
		expect( result.current.loading ).toBe( false );
	} );
} );

describe( 'useRulesGraph — mutations dispatch the verb then re-list', () => {
	test( 'upsert sends the raw-JSON rule as arguments then re-lists', async () => {
		const wire = installWire( {
			list: { rules: [] },
			upsert: { rule: SAMPLE_RULES[ 0 ] },
		} );
		const { result } = renderHook( () => useRulesGraph() );
		await act( async () => {} );
		const listsBefore = countVerbs( wire.batches, 'list' );

		act( () => result.current.upsert( SAMPLE_RULES[ 0 ] ) );

		await waitFor( () =>
			expect( countVerbs( wire.batches, 'list' ) ).toBeGreaterThan(
				listsBefore
			)
		);
		const up = findVerb( wire.batches, 'upsert' );
		expect( up[ TO ] ).toBe( 'rules' );
		// Each mutation mints from its OWN node, and names the rule it is
		// about in the reply path — the document is the payload, never the
		// address.
		expect( up[ FROM ] ).toBe( 'rules:upsert:in/r1' );
		expect( up[ ID ] ).toBe( '' );
		// The whole raw JSON is one arg token the CI json_decodes ($args[0]).
		expect( up[ VALUE ].arguments ).toEqual( [
			JSON.stringify( SAMPLE_RULES[ 0 ] ),
		] );
		expect( JSON.parse( up[ VALUE ].arguments[ 0 ] ).pattern ).toBe(
			'/blog'
		);
	}, 15000 );

	test( 'saveAll sends the whole-list raw JSON then re-lists', async () => {
		const wire = installWire( {
			list: { rules: SAMPLE_RULES },
			save: { saved: 2 },
		} );
		const { result } = renderHook( () => useRulesGraph() );
		await act( async () => {} );
		const listsBefore = countVerbs( wire.batches, 'list' );

		act( () => result.current.saveAll( SAMPLE_RULES ) );

		// The verb rides the router tick, and the re-list follows its answer.
		await waitFor( () =>
			expect( countVerbs( wire.batches, 'list' ) ).toBeGreaterThan(
				listsBefore
			)
		);
		const save = findVerb( wire.batches, 'save' );
		expect( save[ VALUE ].arguments ).toEqual( [
			JSON.stringify( SAMPLE_RULES ),
		] );
	}, 15000 );

	test( 'remove sends delete with the id as a positional token then re-lists', async () => {
		const wire = installWire( {
			list: { rules: [] },
			delete: { deleted: true },
		} );
		const { result } = renderHook( () => useRulesGraph() );
		await act( async () => {} );
		const listsBefore = countVerbs( wire.batches, 'list' );

		act( () => result.current.remove( 'r1' ) );

		await waitFor( () =>
			expect( countVerbs( wire.batches, 'list' ) ).toBeGreaterThan(
				listsBefore
			)
		);
		const del = findVerb( wire.batches, 'delete' );
		expect( del[ FROM ] ).toBe( 'rules:delete:in/r1' );
		expect( del[ ID ] ).toBe( '' );
		expect( del[ VALUE ].arguments ).toEqual(
			formatCommandArgs( [ 'r1' ] )
		);
	}, 15000 );

	test( 'reset sends the nullary verb from its own node then re-lists', async () => {
		const wire = installWire( {
			list: { rules: SAMPLE_RULES },
			reset: { reset: 3 },
		} );
		const { result } = renderHook( () => useRulesGraph() );
		await act( async () => {} );
		const listsBefore = countVerbs( wire.batches, 'list' );

		act( () => result.current.reset() );

		await waitFor( () =>
			expect( countVerbs( wire.batches, 'list' ) ).toBeGreaterThan(
				listsBefore
			)
		);
		const reset = findVerb( wire.batches, 'reset' );
		expect( reset[ TO ] ).toBe( 'rules' );
		expect( reset[ FROM ] ).toBe( 'rules:reset:in' );
		expect( reset[ VALUE ].arguments ).toEqual( [] );
	}, 15000 );
} );

describe( 'useRulesGraph — errors', () => {
	test( 'an uncorrelated list error surfaces as view.error without throwing', async () => {
		installWire(
			{ list: 'ruleset unavailable' },
			{ errorVerbs: [ 'list' ] }
		);
		const { result } = renderHook( () => useRulesGraph() );
		await act( async () => {} );
		expect( result.current.error ).toBe( 'ruleset unavailable' );
		expect( result.current.loading ).toBe( false );
	} );

	test( 'a refused upsert reaches onMutation, not the table banner', async () => {
		installWire(
			{ list: { rules: [] }, upsert: 'invalid rule' },
			{ errorVerbs: [ 'upsert' ] }
		);
		const onMutation = jest.fn();
		const { result } = renderHook( () => useRulesGraph( { onMutation } ) );
		await act( async () => {} );

		act( () => result.current.upsert( SAMPLE_RULES[ 0 ] ) );

		await waitFor( () => expect( onMutation ).toHaveBeenCalledTimes( 1 ) );
		expect( onMutation.mock.calls[ 0 ][ 0 ].verb ).toBe( 'upsert' );
		expect( onMutation.mock.calls[ 0 ][ 0 ].error ).toContain(
			'invalid rule'
		);
		expect( Core.node( VIEW ).setStateCache.view.error ).toBeNull();
	}, 15000 );
} );

describe( 'useRulesGraph — teardown', () => {
	test( 'unmount unregisters every graph node + the backbone', () => {
		installWire();
		const { unmount } = renderHook( () => useRulesGraph() );
		unmount();
		// The ROUTER is the page's heartbeat and is never torn down.
		for ( const name of [ ...ALL_GRAPH_NAMES, INTERPRETER ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
	} );
} );
