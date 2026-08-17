/**
 * useGlobBrowse tests — the shared browse model both the Error Log and Request
 * Log dashboards mount on top of their glob-subscribed RemoteLink. It catalogs
 * the glob's concrete partition dirs (list_logs), a selected partition's segments
 * (log_status), and maps browse actions to `link.setSubscribe(subscribe,
 * positions)` seeks (via the substrate's useLogPositions).
 *
 * The graph is built REAL (mountExospine + a RemoteLink + a settling view node);
 * catalog replies ride a transport double whose postBatch addresses the reply
 * back along FROM so it routes interpreter → router → the Request node that asked.
 */

import { renderHook, act, waitFor } from '../../test-helpers/renderHook';
import {
	Core,
	Node,
	CommandInterpreterNode,
	mountExospine,
	newMessage,
	FROM,
	VALUE,
	TM_RESPONSE,
	TM_ERROR,
	forgetSession,
	__setAuthFetch,
	GRID_PHASE_MS,
} from '@newspack-nodes/runtime';
import {
	installFakeCommandWire,
	commandReply,
} from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import useGlobBrowse from '../useGlobBrowse';

class FakeEventSource {
	constructor( url ) {
		this.url = url;
		this.listeners = {};
		this.closed = false;
		FakeEventSource.last = this;
	}
	addEventListener( name, cb ) {
		( this.listeners[ name ] ||= [] ).push( cb );
	}
	close() {
		this.closed = true;
	}
}

// Minimal view: records control messages and rows, and can publish a seek
// view model (what the real view node would publish). Catalog replies never
// reach it — each is addressed to the Request node that asked.
class FakeCatalogView extends Node {
	constructor() {
		super();
		this.controls = [];
		this.rows = [];
		// Set from `name` on registration, as the real graph wires it.
		this.controlFrom = '';
	}
	// Routes on ORIGIN, like the node it stands in for. Sniffing the payload
	// here made every viewControl() call site pass with no FROM at all.
	fill( message ) {
		if ( '' !== this.controlFrom && message[ FROM ] === this.controlFrom ) {
			this.controls.push( message[ VALUE ] );
		} else {
			this.rows.push( message );
		}
	}
	// The last control message of a given action (or undefined).
	lastControl( action ) {
		return [ ...this.controls ]
			.reverse()
			.find( ( c ) => action === c.action );
	}
	// Simulate the real view publishing its seek model for useNodeState.
	publishView( model ) {
		this.setState( 'view', model );
	}
}

// Transport double: postBatch returns replies keyed by verb, addressed FROM.
// A verb in `errorVerbs` replies TM_ERROR so the catch paths are exercised.
function makeFakeClient( payloadByVerb = {}, errorVerbs = [] ) {
	const client = {
		batches: [],
		buildMessage: () => newMessage(),
		postBatch( messages ) {
			client.batches.push( ...messages );
			return Promise.resolve(
				messages.map( ( m ) => {
					const verb = m[ VALUE ]?.name;
					return commandReply(
						m,
						payloadByVerb[ verb ] ?? null,
						errorVerbs.includes( verb ) ? TM_ERROR : TM_RESPONSE
					);
				} )
			);
		},
	};
	return client;
}

const LINK = 'test:link';
const VIEW = 'test:view';
const GLOB = 'errors.*';

// Every command the client saw, in send order.
const sentCommands = ( client ) =>
	client.batches.map( ( m ) => ( {
		name: m[ VALUE ]?.name,
		args: m[ VALUE ]?.arguments,
	} ) );

// Build a real backbone + RemoteLink + settling view; return the live handles.
function buildGraph( payloadByVerb, errorVerbs ) {
	CommandInterpreterNode.registerNodeClasses( {
		FakeCatalogView,
	} );
	const interpreter = Core.node( '_command_interpreter' );
	const client = makeFakeClient( payloadByVerb, errorVerbs );
	const link = interpreter.makeNode( 'RemoteLink', LINK, [ GLOB ] );
	link.target = VIEW;
	// The shared `_http` singleton carries every command out — the link's
	// stream and the catalog Request nodes both ride it.
	Core.node( '_http' ).client = client;
	link.client = client;
	const view = interpreter.makeNode( 'FakeCatalogView', VIEW );
	view.controlFrom = VIEW;
	const browseTargetRef = {
		current: { subscribe: [ GLOB ], positions: null },
	};
	return { link, view, client, browseTargetRef };
}

// Render the hook against a freshly-built graph; flush the async catalog fetch.
async function renderBrowse( {
	payloadByVerb = {},
	errorVerbs = [],
	isActive = true,
	glob = GLOB,
} = {} ) {
	const graph = buildGraph( payloadByVerb, errorVerbs );
	let hook;
	await act( async () => {
		hook = renderHook( ( props ) => useGlobBrowse( props ), {
			initialProps: {
				glob,
				linkName: LINK,
				viewName: VIEW,
				isActive,
				browseTargetRef: graph.browseTargetRef,
			},
		} );
	} );
	// The catalog rides the router tick, so it is a wait rather than a flush.
	// A refused or empty catalog never fills, so nothing waits on those.
	const fills =
		isActive &&
		payloadByVerb.list_logs?.length &&
		! errorVerbs.includes( 'list_logs' );
	if ( fills ) {
		await waitFor( () => {
			if ( ! hook.result.current.partitions.length ) {
				throw new Error( 'catalog not in yet' );
			}
		} );
	}
	return { ...graph, ...hook };
}

// Every verb here rides the router tick; jest's 5s default is not enough.
jest.setTimeout( 20000 );

// A test that freezes the substrate clock must not leave it frozen for the
// next one — including when it fails before its own restore.
afterEach( () => {
	jest.restoreAllMocks();
} );

beforeEach( () => {
	Core.reset();
	FakeEventSource.last = null;
	global.EventSource = FakeEventSource;
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	mountExospine();
} );

/**
 * The mount-time list_logs races /auth: the graph builds synchronously, the
 * session lands a round trip later. Firing before then mints an UNSIGNED
 * command the server refuses, and the browser looks alive only because a later
 * user action happens to run after auth.
 */
describe( 'useGlobBrowse — authentication', () => {
	test( 'signs the mount-time catalog fetch', async () => {
		forgetSession();
		__setAuthFetch( async () => ( {
			handle: 'dddd4444dddd4444dddd4444dddd4444',
			key: 'key-glob-late-auth',
			expires_in: 3600,
			now: 1771000000,
		} ) );

		const { client } = await renderBrowse( {
			payloadByVerb: { list_logs: [] },
		} );

		await waitFor( () =>
			expect( client.batches.length ).toBeGreaterThanOrEqual( 1 )
		);
		expect( client.batches[ 0 ][ VALUE ].auth ).toBeDefined();
	} );
} );

describe( 'useGlobBrowse — partition catalog', () => {
	test( 'exposes only the partitions whose key matches the glob prefix', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [
					{ key: 'errors.p0', label: 'errors.p0' },
					{ key: 'errors.p3', label: 'errors.p3' },
					{ key: 'completed.p0', label: 'completed.p0' },
					{ key: 'jobs.log', label: 'jobs.log' },
				],
			},
		} );
		expect( result.current.partitions.map( ( p ) => p.key ) ).toEqual( [
			'errors.p0',
			'errors.p3',
		] );
	} );

	test( 'defaults selectedPartition to "" (All partitions, live) with no segments', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p0' }, { key: 'errors.p1' } ],
			},
		} );
		await waitFor( () =>
			expect( result.current.selectedPartition ).toBe( '' )
		);
		await waitFor( () => expect( result.current.segments ).toEqual( [] ) );
		expect( result.current.mode ).toBe( 'live' );
	} );

	test( 'auto-selects a sole partition so its segments load immediately', async () => {
		// One dir: the "All partitions (live)" hop is pointless — the rail
		// should land on the dir with its segment list already fetched.
		const { result } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p0', label: 'errors.p0' } ],
				log_status: {
					log_id: 'errors.p0',
					segments: [ { id: 4, size: 96 } ],
				},
			},
		} );
		await waitFor( () =>
			expect( result.current.selectedPartition ).toBe( 'errors.p0' )
		);
		await waitFor( () =>
			expect( result.current.segments ).toEqual( [ { id: 4, size: 96 } ] )
		);
	} );

	// The catalog fetch has its own node, which raises a backbone if absent.
	test( 'does not throw when the graph is absent', async () => {
		Core.reset();
		installFakeCommandWire( () => [] );
		let hook;
		await act( async () => {
			hook = renderHook( ( props ) => useGlobBrowse( props ), {
				initialProps: {
					glob: GLOB,
					linkName: 'missing:link',
					viewName: 'missing:view',
					isActive: true,
					browseTargetRef: { current: {} },
				},
			} );
		} );
		expect( hook.result.current.partitions ).toEqual( [] );
		expect( () => hook.result.current.follow() ).not.toThrow();
	} );
} );

describe( 'useGlobBrowse — segment catalog', () => {
	test( 'selectPartition fetches log_status for that dir and exposes its segments', async () => {
		const { result, client } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p3', label: 'errors.p3' } ],
				log_status: {
					log_id: 'errors.p3',
					segments: [
						{ id: 2, size: 100 },
						{ id: 1, size: 50 },
					],
				},
			},
		} );
		await act( async () => {
			result.current.selectPartition( 'errors.p3' );
		} );
		await waitFor( () =>
			expect( result.current.selectedPartition ).toBe( 'errors.p3' )
		);
		await waitFor( () =>
			expect( result.current.segments ).toEqual( [
				{ id: 2, size: 100 },
				{ id: 1, size: 50 },
			] )
		);
		await waitFor( () =>
			expect(
				sentCommands( client ).find( ( c ) => 'log_status' === c.name )
					.args
			).toEqual( [ 'errors.p3' ] )
		);
	} );

	test( 'returning to All partitions clears the segment list', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p3' } ],
				log_status: { segments: [ { id: 4, size: 9 } ] },
			},
		} );
		await act( async () => result.current.selectPartition( 'errors.p3' ) );
		await waitFor( () =>
			expect( result.current.segments ).toHaveLength( 1 )
		);
		await act( async () => result.current.selectPartition( '' ) );
		await waitFor( () => expect( result.current.segments ).toEqual( [] ) );
	} );
} );

describe( 'useGlobBrowse — reposition seeks', () => {
	test( 'selectPartition narrows the stream to that dir (live) + clears the view + records the target', async () => {
		const { result, link, view, browseTargetRef } = await renderBrowse( {
			payloadByVerb: { list_logs: [ { key: 'errors.p3' } ] },
		} );
		const setSubscribe = jest
			.spyOn( link, 'setSubscribe' )
			.mockImplementation( () => {} );
		await act( async () => result.current.selectPartition( 'errors.p3' ) );
		expect( setSubscribe ).toHaveBeenCalledWith( [ 'errors.p3' ], null );
		expect( browseTargetRef.current ).toEqual( {
			subscribe: [ 'errors.p3' ],
			positions: null,
			explicit: false,
		} );
		// The switch arms single-dir seek tracking on the view (dir carried).
		await waitFor( () =>
			expect( view.lastControl( 'select' ) ).toEqual( {
				action: 'select',
				dir: 'errors.p3',
			} )
		);
	} );

	test( 'selecting All partitions resubscribes the glob live', async () => {
		const { result, link, browseTargetRef } = await renderBrowse( {
			payloadByVerb: { list_logs: [ { key: 'errors.p3' } ] },
		} );
		await act( async () => result.current.selectPartition( 'errors.p3' ) );
		const setSubscribe = jest
			.spyOn( link, 'setSubscribe' )
			.mockImplementation( () => {} );
		await act( async () => result.current.selectPartition( '' ) );
		expect( setSubscribe ).toHaveBeenCalledWith( [ 'errors.*' ], null );
		expect( browseTargetRef.current.subscribe ).toEqual( [ 'errors.*' ] );
	} );

	test( 'browseSegment seeks the selected dir to that segment head', async () => {
		const { result, link, browseTargetRef } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p3' } ],
				log_status: { segments: [ { id: 2, size: 100 } ] },
			},
		} );
		await act( async () => result.current.selectPartition( 'errors.p3' ) );
		const setSubscribe = jest
			.spyOn( link, 'setSubscribe' )
			.mockImplementation( () => {} );
		await act( async () =>
			result.current.browseSegment( { id: 2, size: 100 } )
		);
		expect( setSubscribe ).toHaveBeenCalledWith( [ 'errors.p3' ], {
			'errors.p3': { segment: 2, offset: 0 },
		} );
		expect( browseTargetRef.current.positions ).toEqual( {
			'errors.p3': { segment: 2, offset: 0 },
		} );
		expect( result.current.segmentId ).toBe( 2 );
	} );

	test( 'replay seeks the selected dir from the earliest record', async () => {
		const { result, link } = await renderBrowse( {
			payloadByVerb: { list_logs: [ { key: 'errors.p3' } ] },
		} );
		await act( async () => result.current.selectPartition( 'errors.p3' ) );
		const setSubscribe = jest
			.spyOn( link, 'setSubscribe' )
			.mockImplementation( () => {} );
		await act( async () => result.current.replay() );
		expect( setSubscribe ).toHaveBeenCalledWith( [ 'errors.p3' ], {
			'errors.p3': 'start',
		} );
		expect( result.current.segmentId ).toBe( 'start' );
	} );

	test( 'follow returns the selected dir to the live tail (null positions)', async () => {
		const { result, link } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p3' } ],
				log_status: { segments: [ { id: 2, size: 100 } ] },
			},
		} );
		await act( async () => result.current.selectPartition( 'errors.p3' ) );
		await act( async () =>
			result.current.browseSegment( { id: 2, size: 100 } )
		);
		const setSubscribe = jest
			.spyOn( link, 'setSubscribe' )
			.mockImplementation( () => {} );
		await act( async () => result.current.follow() );
		expect( setSubscribe ).toHaveBeenCalledWith( [ 'errors.p3' ], null );
		expect( result.current.mode ).toBe( 'live' );
	} );
} );

describe( 'useGlobBrowse — guards', () => {
	test( 'a glob with no trailing * filters partitions by the whole glob as prefix', async () => {
		const { result } = await renderBrowse( {
			glob: 'errors',
			payloadByVerb: {
				list_logs: [
					{ key: 'errors.p0', label: 'errors.p0' },
					{ key: 'other.p0', label: 'other.p0' },
				],
			},
		} );
		expect( result.current.partitions.map( ( p ) => p.key ) ).toEqual( [
			'errors.p0',
		] );
	} );

	test( 'a rejected list_logs leaves the partition list empty', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: { list_logs: [ { key: 'errors.p0' } ] },
			errorVerbs: [ 'list_logs' ],
		} );
		await waitFor( () =>
			expect( result.current.partitions ).toEqual( [] )
		);
	} );

	test( 'a non-array list_logs reply leaves the partition list empty', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: { list_logs: { not: 'an array' } },
		} );
		await waitFor( () =>
			expect( result.current.partitions ).toEqual( [] )
		);
	} );

	test( 'a failed log_status clears the segment list', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p3' } ],
				log_status: { segments: [ { id: 2, size: 100 } ] },
			},
			errorVerbs: [ 'log_status' ],
		} );
		await act( async () => result.current.selectPartition( 'errors.p3' ) );
		await waitFor( () => expect( result.current.segments ).toEqual( [] ) );
	} );

	test( 'Live / Replay / segment seeks are no-ops with no partition selected', async () => {
		// Two partitions so the sole-partition auto-select stays out of play.
		const { result, link } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p3' }, { key: 'errors.p5' } ],
			},
		} );
		const setSubscribe = jest
			.spyOn( link, 'setSubscribe' )
			.mockImplementation( () => {} );
		await act( async () => {
			result.current.follow();
			result.current.replay();
			result.current.browseSegment( { id: 1, size: 10 } );
		} );
		expect( setSubscribe ).not.toHaveBeenCalled();
	} );
} );

describe( 'useGlobBrowse — seek feedback wiring', () => {
	test( 'replay carries the captured live boundary (newest segment @ its size) into the view', async () => {
		const { result, view } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p3' } ],
				log_status: {
					segments: [
						{ id: 98, size: 700 },
						{ id: 105, size: 1200 },
					],
				},
			},
		} );
		await act( async () => result.current.selectPartition( 'errors.p3' ) );
		// The rail is a tick away, and the boundary comes from it.
		await waitFor( () =>
			expect( result.current.segments.length ).toBeGreaterThan( 0 )
		);
		await act( async () => result.current.replay() );
		// Newest segment 105 @ 1200 bytes is the boundary carried to the view.
		await waitFor( () =>
			expect( view.lastControl( 'browse' ) ).toEqual( {
				action: 'browse',
				endSegment: 105,
				endOffset: 1200,
			} )
		);
	} );

	test( 'browseSegment carries the captured live boundary into the view', async () => {
		const { result, view } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p3' } ],
				log_status: {
					segments: [
						{ id: 98, size: 700 },
						{ id: 105, size: 1200 },
					],
				},
			},
		} );
		await act( async () => result.current.selectPartition( 'errors.p3' ) );
		// The rail is a tick away, and the boundary comes from it.
		await waitFor( () =>
			expect( result.current.segments.length ).toBeGreaterThan( 0 )
		);
		await act( async () =>
			result.current.browseSegment( { id: 98, size: 700 } )
		);
		await waitFor( () =>
			expect( view.lastControl( 'browse' ) ).toEqual( {
				action: 'browse',
				endSegment: 105,
				endOffset: 1200,
			} )
		);
	} );

	test( 'follow sends a follow control into the view', async () => {
		const { result, view } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p3' } ],
				log_status: { segments: [ { id: 105, size: 1200 } ] },
			},
		} );
		await act( async () => result.current.selectPartition( 'errors.p3' ) );
		await act( async () =>
			result.current.browseSegment( { id: 105, size: 1200 } )
		);
		await act( async () => result.current.follow() );
		await waitFor( () =>
			expect( view.lastControl( 'follow' ) ).toEqual( {
				action: 'follow',
			} )
		);
	} );

	test( 'surfaces the view-derived mode + lastReceivedSegment (not the click)', async () => {
		const { result, view } = await renderBrowse( {
			payloadByVerb: { list_logs: [ { key: 'errors.p3' } ] },
		} );
		expect( result.current.mode ).toBe( 'live' );
		expect( result.current.lastReceivedSegment ).toBe( null );
		// The view flips to live at catch-up + tracks the received segment.
		await act( async () => {
			view.publishView( { mode: 'replay', lastReceivedSegment: 98 } );
		} );
		expect( result.current.mode ).toBe( 'replay' );
		expect( result.current.lastReceivedSegment ).toBe( 98 );
	} );
} );

describe( 'useGlobBrowse — inactive (paused / hidden) gating', () => {
	test( 'browse actions record the target but do NOT reopen the stream while inactive', async () => {
		const { result, link, browseTargetRef } = await renderBrowse( {
			payloadByVerb: { list_logs: [ { key: 'errors.p3' } ] },
			isActive: false,
		} );
		const setSubscribe = jest
			.spyOn( link, 'setSubscribe' )
			.mockImplementation( () => {} );
		await act( async () => result.current.selectPartition( 'errors.p3' ) );
		expect( setSubscribe ).not.toHaveBeenCalled();
		expect( browseTargetRef.current ).toEqual( {
			subscribe: [ 'errors.p3' ],
			positions: null,
			explicit: false,
		} );
	} );
} );

describe( 'useGlobBrowse — time travel (pause / step / jump)', () => {
	const PAYLOADS = {
		list_logs: [ { key: 'errors.p2', label: 'errors.p2' } ],
		log_status: { segments: [ { id: 3, size: 4096 } ] },
		read_message: {
			message: [ 1, 'stamped', '', '3:57:41', '', 0, 'stepped-row' ],
			cursor: { segment: 3, offset: 98 },
		},
	};

	async function renderTimeTravel() {
		const setPausedRef = { current: jest.fn() };
		// Paused semantics: browse actions record; nothing repositions live.
		const isActiveNow = () => false;
		const graph = buildGraph( PAYLOADS, [] );
		let hook;
		await act( async () => {
			hook = renderHook( ( props ) => useGlobBrowse( props ), {
				initialProps: {
					glob: GLOB,
					linkName: LINK,
					viewName: VIEW,
					isActive: false,
					browseTargetRef: graph.browseTargetRef,
					setPausedRef,
					isActiveNow,
				},
			} );
		} );
		await act( async () => {
			hook.result.current.selectPartition( 'errors.p2' );
		} );
		return { ...graph, ...hook, setPausedRef };
	}

	it( 'a segment click pauses before it seeks', async () => {
		const { result, setPausedRef } = await renderTimeTravel();
		await act( async () => {
			result.current.browseSegment( { id: 3 } );
		} );
		expect( setPausedRef.current ).toHaveBeenCalledWith( true );
	} );

	/**
	 * Replay seeks the magic 'start' token, so the cursor is a STRING. Step used
	 * to require an object and silently returned — pause → Replay → Step did
	 * nothing until a segment click replaced the token.
	 */
	it( 'step reads from the magic start token a Replay seeks', async () => {
		const { result, client } = await renderTimeTravel();
		await act( async () => {
			result.current.replay();
		} );
		await act( async () => {
			result.current.step();
		} );

		await waitFor( () =>
			expect( sentCommands( client ) ).toContainEqual( {
				name: 'read_message',
				args: [ 'errors.p2', 'start' ],
			} )
		);
	} );

	it( 'step reads ONE message at the cursor and advances it', async () => {
		const { result, client, view, browseTargetRef } =
			await renderTimeTravel();
		await act( async () => {
			result.current.browseSegment( { id: 3 } );
		} );
		await act( async () => {
			result.current.step();
		} );
		await waitFor( () =>
			expect( sentCommands( client ) ).toContainEqual( {
				name: 'read_message',
				args: [ 'errors.p2', '3:0' ],
			} )
		);
		// The record was admitted through the paused belt…
		await waitFor( () =>
			expect( view.lastControl( 'step' ) ).toEqual( {
				action: 'step',
				frames: 1,
			} )
		);
		expect( view.rows.map( ( m ) => m[ VALUE ] ) ).toContain(
			'stepped-row'
		);
		// …and the recorded target advanced to the post-step cursor.
		expect( browseTargetRef.current.positions ).toEqual( {
			'errors.p2': { segment: 3, offset: 98 },
		} );
		expect( browseTargetRef.current.explicit ).toBe( true );
	} );

	it( 'jumpTo pauses, seeks the position, and steps it', async () => {
		const { result, client, setPausedRef } = await renderTimeTravel();
		await act( async () => {
			result.current.jumpTo( { segment: 5, offset: 44 } );
		} );
		expect( setPausedRef.current ).toHaveBeenCalledWith( true );
		await waitFor( () =>
			expect( sentCommands( client ) ).toContainEqual( {
				name: 'read_message',
				args: [ 'errors.p2', '5:44' ],
			} )
		);
	} );

	it( 'reopenStream honors an explicit seek once on reconnect', () => {
		const { reopenStream } = require( '../useGlobBrowse' );
		const link = { setSubscribe: jest.fn(), reconnect: jest.fn() };
		const target = {
			subscribe: [ 'errors.p2' ],
			positions: { 'errors.p2': { segment: 3, offset: 98 } },
			explicit: true,
		};
		reopenStream( link, target, true );
		expect( link.setSubscribe ).toHaveBeenCalledWith( [ 'errors.p2' ], {
			'errors.p2': { segment: 3, offset: 98 },
		} );
		// Single-use: the NEXT reconnect states no seek and resumes itself.
		expect( target.explicit ).toBe( false );
		reopenStream( link, target, true );
		expect( link.reconnect ).toHaveBeenCalledWith( [ 'errors.p2' ] );
		expect( link.setSubscribe ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'useGlobBrowse — segment-rail maintenance', () => {
	const PAYLOADS = {
		list_logs: [ { key: 'errors.p2', label: 'errors.p2' } ],
		log_status: { segments: [ { id: 3, size: 4096 } ] },
	};

	// Count of log_status commands the client has seen so far.
	const statusCalls = ( client ) =>
		sentCommands( client ).filter( ( c ) => 'log_status' === c.name )
			.length;

	it( 'refreshes the segment catalog on a 10s cadence', async () => {
		// The rail rides the Router TIMER, and beforeEach armed that slot under
		// REAL timers — re-mount the backbone so its 1s slot is a fake one.
		jest.useFakeTimers();
		// @longform The rail's 10s refresh rides the substrate's wall-clock
		// GRID, so whether a window contains a boundary depends on the second
		// the suite happens to start in. Pin the clock 10ms past one — forward,
		// never back, which every watchdog would read as a stream gone silent.
		jest.setSystemTime(
			( Math.floor( ( Date.now() - GRID_PHASE_MS ) / 10000 ) + 1 ) *
				10000 +
				GRID_PHASE_MS +
				10
		);
		// Core.reset() only swaps the node map; without this the beforeEach
		// router keeps its real interval and ticks on beside the fake clock.
		Core.node( '_router' )?.stopTimer();
		Core.reset();
		mountExospine();
		const graph = buildGraph( PAYLOADS, [] );
		let hook;
		await act( async () => {
			hook = renderHook( ( props ) => useGlobBrowse( props ), {
				initialProps: {
					glob: GLOB,
					linkName: LINK,
					viewName: VIEW,
					isActive: true,
					browseTargetRef: graph.browseTargetRef,
				},
			} );
		} );
		await act( async () => {
			hook.result.current.selectPartition( 'errors.p2' );
		} );
		// The log_status ask rides the tick the fake timers now drive.
		await act( async () => {
			jest.advanceTimersByTime( 1000 );
		} );
		const before = statusCalls( graph.client );
		expect( before ).toBeGreaterThan( 0 );
		// The rail marks its window started (it just loaded), so the refresh is
		// a full interval out, counted from the next boundary: with the clock
		// pinned just past one, that is 20s exactly.
		await act( async () => {
			jest.advanceTimersByTime( 20100 );
		} );
		expect( statusCalls( graph.client ) ).toBe( before + 1 );
		jest.useRealTimers();
	} );

	it( 'a record from an unknown segment refetches the rail once', async () => {
		const graph = buildGraph( PAYLOADS, [] );
		let hook;
		await act( async () => {
			hook = renderHook( ( props ) => useGlobBrowse( props ), {
				initialProps: {
					glob: GLOB,
					linkName: LINK,
					viewName: VIEW,
					isActive: true,
					browseTargetRef: graph.browseTargetRef,
				},
			} );
		} );
		await act( async () => {
			hook.result.current.selectPartition( 'errors.p2' );
		} );
		await waitFor( () =>
			expect( statusCalls( graph.client ) ).toBeGreaterThan( 0 )
		);
		const before = statusCalls( graph.client );
		// @longform Freeze the substrate clock for the rest of this test. The
		// rail's own 10s refresh rides a wall-clock grid, so whether the 1500ms
		// window below contains a boundary depends on the minute the suite is
		// run in — and a cadence poll would read as the refetch loop this is
		// asserting the absence of.
		jest.spyOn( Core, 'now' ).mockReturnValue( Date.now() / 1000 );
		// A rotation: the view reports a segment the rail doesn't know.
		await act( async () => {
			graph.view.publishView( {
				mode: 'live',
				lastReceivedSegment: 8,
			} );
		} );
		await waitFor( () =>
			expect( statusCalls( graph.client ) ).toBe( before + 1 )
		);
		// The SAME unknown segment must not refetch again (no loop).
		await act( async () => {
			graph.view.publishView( {
				mode: 'live',
				lastReceivedSegment: 8,
			} );
			await new Promise( ( r ) => setTimeout( r, 1500 ) );
		} );
		expect( statusCalls( graph.client ) ).toBe( before + 1 );
	} );
} );
