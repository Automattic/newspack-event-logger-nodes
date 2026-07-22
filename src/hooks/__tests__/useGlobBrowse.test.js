/**
 * useGlobBrowse tests — the shared browse model both the Error Log and Request
 * Log dashboards mount on top of their glob-subscribed RemoteLink. It catalogs
 * the glob's concrete partition dirs (list_logs), a selected partition's segments
 * (log_status), and maps browse actions to `link.setSubscribe(subscribe,
 * positions)` seeks (via the substrate's useLogPositions).
 *
 * The graph is built REAL (mountExospine + a RemoteLink + a settling view node);
 * catalog replies ride a CommandClient double whose postBatch addresses the reply
 * back along FROM so it routes interpreter → router → view.replies.settle.
 */

import { renderHook, act } from '../../test-helpers/renderHook';
import {
	Core,
	Node,
	CommandInterpreterNode,
	mountExospine,
	newMessage,
	TYPE,
	FROM,
	TO,
	ID,
	VALUE,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
	TIMESTAMP,
} from '@newspack-nodes/runtime';
import { PendingReplies } from '@newspack-nodes/shared/pendingReplies';
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

// Minimal settling view: swallows catalog replies, counts clear controls.
class FakeCatalogView extends Node {
	constructor() {
		super();
		this.replies = new PendingReplies();
		this.cleared = 0;
	}
	fill( message ) {
		if ( this.replies.settle( message ) ) {
			return;
		}
		const value = message[ VALUE ];
		if ( value && 'clear' === value.action ) {
			this.cleared += 1;
		}
	}
}

// CommandClient double: postBatch returns replies keyed by verb, addressed FROM.
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
					const reply = newMessage();
					reply[ TYPE ] = errorVerbs.includes( verb )
						? TM_COMMAND | TM_RESPONSE | TM_ERROR
						: TM_COMMAND | TM_RESPONSE;
					reply[ TO ] = m[ FROM ];
					reply[ ID ] = m[ ID ];
					reply[ TIMESTAMP ] = 0;
					reply[ VALUE ] = {
						name: verb,
						payload: payloadByVerb[ verb ] ?? null,
					};
					return reply;
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
	link.client = client;
	const view = interpreter.makeNode( 'FakeCatalogView', VIEW );
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
	return { ...graph, ...hook };
}

beforeEach( () => {
	Core.reset();
	FakeEventSource.last = null;
	global.EventSource = FakeEventSource;
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	mountExospine();
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
			payloadByVerb: { list_logs: [ { key: 'errors.p0' } ] },
		} );
		expect( result.current.selectedPartition ).toBe( '' );
		expect( result.current.segments ).toEqual( [] );
		expect( result.current.mode ).toBe( 'live' );
	} );

	test( 'does not throw when the graph is absent', async () => {
		Core.reset();
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
		expect( result.current.selectedPartition ).toBe( 'errors.p3' );
		expect( result.current.segments ).toEqual( [
			{ id: 2, size: 100 },
			{ id: 1, size: 50 },
		] );
		expect(
			sentCommands( client ).find( ( c ) => 'log_status' === c.name ).args
		).toEqual( [ 'errors.p3' ] );
	} );

	test( 'returning to All partitions clears the segment list', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: {
				list_logs: [ { key: 'errors.p3' } ],
				log_status: { segments: [ { id: 4, size: 9 } ] },
			},
		} );
		await act( async () => result.current.selectPartition( 'errors.p3' ) );
		expect( result.current.segments ).toHaveLength( 1 );
		await act( async () => result.current.selectPartition( '' ) );
		expect( result.current.segments ).toEqual( [] );
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
		} );
		expect( view.cleared ).toBe( 1 );
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
		expect( result.current.mode ).toBe( 'browse' );
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
		expect( result.current.partitions ).toEqual( [] );
	} );

	test( 'a non-array list_logs reply leaves the partition list empty', async () => {
		const { result } = await renderBrowse( {
			payloadByVerb: { list_logs: { not: 'an array' } },
		} );
		expect( result.current.partitions ).toEqual( [] );
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
		expect( result.current.segments ).toEqual( [] );
	} );

	test( 'Live / Replay / segment seeks are no-ops with no partition selected', async () => {
		const { result, link } = await renderBrowse( {
			payloadByVerb: { list_logs: [ { key: 'errors.p3' } ] },
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
		} );
	} );
} );
