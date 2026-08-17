/**
 * useRequestLogGraph tests — the Request Log dashboard graph now clips onto the
 * substrate's canonical rule-#2 backbone (`_command_interpreter` → `_router`)
 * via a SINGLE `RemoteLink` node plus a single `requestlog:view` node.
 *
 * RemoteLink composes the three I/O children every SSE dashboard used to wire by
 * hand — `requestlog:link:sse-in` (SseIn), `requestlog:link:http` (HttpOut) and
 * `requestlog:link:heartbeat` (Heartbeat) — and wires the `connected → slot`
 * bridge to its own heartbeat. The dead `requestlog:route` / `requestlog:transform`
 * intermediate nodes remain gone (defensive shaping inlined into the view).
 *
 * EventSource is faked via `global.EventSource`; SseInNode's connection logic
 * (already covered by the substrate's `sse_connector.test.js`) is unmocked here
 * — we drive a `msg` event through the fake EventSource and assert it actually
 * routes the composed sse-in → view directly. usePageVisibility is mocked to a
 * controllable value so the visibility effect is deterministic under jsdom.
 */

import {
	renderHook,
	renderComponent,
	act,
	waitFor,
} from '../../../test-helpers/renderHook';
import {
	newMessage,
	pack,
	VALUE,
	KEY,
	TYPE,
	FROM,
	ID,
	TM_INFO,
	TM_STRUCT,
	Core,
	Node,
	useNodeState,
	mountExospine,
} from '@newspack-nodes/runtime';

let mockPageVisible = true;
jest.mock( '@newspack-nodes/shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => mockPageVisible,
} ) );

import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import { useRequestLogGraph } from '../useRequestLogGraph';

// Minimal FakeEventSource: static holds the last instance for tests to drive.
class FakeEventSource {
	constructor( url ) {
		this.url = url;
		this.listeners = {};
		this.closed = false;
		FakeEventSource.last = this;
		FakeEventSource.instances.push( this );
	}
	addEventListener( name, cb ) {
		( this.listeners[ name ] ||= [] ).push( cb );
	}
	close() {
		this.closed = true;
	}
	dispatch( name, data ) {
		( this.listeners[ name ] || [] ).forEach( ( cb ) => cb( { data } ) );
	}
}

// The seam is the WIRE: the graph packs, POSTs and unpacks for real, so
// HttpOut, the router and the interpreter all run.
function installWire( payloadByVerb = {} ) {
	return installFakeCommandWire(
		( m ) => payloadByVerb[ m[ VALUE ]?.name ] ?? null
	);
}

beforeEach( () => {
	Core.reset();
	mockPageVisible = true;
	FakeEventSource.last = null;
	FakeEventSource.instances = [];
	global.EventSource = FakeEventSource;
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	// The mount-time list_logs POST would hit the network under jsdom. The
	// wire takes it and says nothing back, so no reply lands outside act();
	// browse tests inject their own transport and answer for real.
	installFakeCommandWire( () => undefined );
} );

afterEach( () => jest.restoreAllMocks() );

// Transport double keyed by verb, built on the shared HttpOut-seam helper.
const INTERPRETER = '_command_interpreter';
const ROUTER = '_router';
const LINK = 'requestlog:link';
// RemoteLink: a patron-owned `:sse-in` + shared _http/_heartbeat singletons.
const HTTP = '_http';
const HEARTBEAT = '_heartbeat';
const VIEW = 'requestlog:view';
const TEE = 'requestlog:stream';
const COMPOSED_NAMES = [ HTTP, HEARTBEAT ];
const LEASE_OWNER = '9007199254740993';

// Build a `connected` envelope: flat KEY VALUE string; SLOT omitted if null.
function connectedEnvelope( { pid = 4242, slot = 3 } = {} ) {
	const m = newMessage();
	m[ TYPE ] = TM_INFO;
	m[ KEY ] = 'connected';
	const parts = [ `PID ${ pid }` ];
	if ( null !== slot && undefined !== slot ) {
		parts.push( `SLOT ${ slot } OWNER ${ LEASE_OWNER }` );
	}
	parts.push( 'SUBSCRIPTIONS x INTERVAL 2000' );
	m[ VALUE ] = parts.join( ' ' );
	return m;
}

// A completed-request envelope: KEY carries the rid, the summary VALUE
// never duplicates it (the completed-stream wire shape).
function completedEnvelope( req ) {
	const { rid = '', ...value } = req;
	const m = newMessage();
	m[ TYPE ] = TM_STRUCT;
	m[ KEY ] = rid;
	m[ VALUE ] = value;
	return m;
}

describe( 'useRequestLogGraph — exospine + RemoteLink wiring', () => {
	test( 'mounts the backbone + one RemoteLink (sharing the reserved _http/_heartbeat) + the view, each sinking into the interpreter', () => {
		renderHook( () => useRequestLogGraph() );
		const interpreter = Core.node( INTERPRETER );
		expect( interpreter ).toBeTruthy();
		expect( Core.node( ROUTER ) ).toBeTruthy();
		// The view sinks into the interpreter.
		expect( Core.node( VIEW ) ).toBeTruthy();
		expect( Core.node( VIEW ).sink ).toBe( interpreter );
		// Shared _http/_heartbeat sink into the interpreter.
		for ( const name of COMPOSED_NAMES ) {
			const node = Core.node( name );
			expect( node ).toBeTruthy();
			expect( node.sink ).toBe( interpreter );
		}
		// Registered so `trace` reaches it; patron keeps it off the canvas.
		expect( Core.node( 'requestlog:link:sse-in' ) ).toBe(
			Core.node( 'requestlog:link' ).sseIn
		);
	} );

	test( 'steers flow with targets: the `:sse-in` subscribes on `completed` and routes to view (and heartbeat → _http/workers)', () => {
		renderHook( () => useRequestLogGraph() );
		// Unnamed SseIn opened on the `completed` subscribe topic.
		expect( FakeEventSource.last.url ).toContain( 'subscribe=completed.*' );
		expect( Core.node( HEARTBEAT ).target ).toBe( `${ HTTP }/workers` );
	} );

	test( 'does not mount the dropped route or transform intermediate nodes', () => {
		renderHook( () => useRequestLogGraph() );
		expect( Core.node( 'requestlog:route' ) ).toBeNull();
		expect( Core.node( 'requestlog:transform' ) ).toBeNull();
	} );

	test( 'inserts an inspectable Tee on the stream edge: link → tee → view', () => {
		renderHook( () => useRequestLogGraph() );
		const interpreter = Core.node( INTERPRETER );
		const tee = Core.node( TEE );
		expect( tee ).toBeTruthy();
		expect( tee.constructor.name ).toBe( 'TeeNode' );
		expect( tee.sink ).toBe( interpreter );
		// The link re-homes received frames to the Tee, which fans to the view.
		expect( Core.node( LINK ).sseIn.target ).toBe( TEE );
		expect( tee.target ).toEqual( [ VIEW ] );
	} );

	test( 'fans the live stream to a debug-overlay watcher without disturbing the view', () => {
		renderHook( () => useRequestLogGraph() );
		const watcher = new Node();
		watcher.name = 'watcher';
		const seen = [];
		watcher.fill = ( m ) => seen.push( m[ KEY ] );
		Core.node( TEE ).connectNode( 'watcher' );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack( completedEnvelope( { rid: 'r-watch', url: '/x' } ) )
			);
		} );
		// The watcher saw the raw stream AND the view appended the row.
		expect( seen ).toContain( 'r-watch' );
		expect( Core.node( VIEW ).lines ).toHaveLength( 1 );
		expect( Core.node( VIEW ).lines[ 0 ].rid ).toBe( 'r-watch' );
	} );

	test( 'opens an EventSource against /messages/stream?subscribe=completed.* when visible', () => {
		renderHook( () => useRequestLogGraph() );
		expect( FakeEventSource.last ).toBeTruthy();
		expect( FakeEventSource.last.url ).toBe(
			'/wp-json/newspack-nodes/v1/messages/stream?subscribe=completed.*&_wpnonce=NONCE'
		);
	} );

	test( 'does not open an EventSource on mount when the page is hidden', () => {
		mockPageVisible = false;
		renderHook( () => useRequestLogGraph() );
		expect( FakeEventSource.last ).toBeNull();
	} );

	test( 'the composed HttpOut defaults its transport on the first POST', () => {
		renderHook( () => useRequestLogGraph() );
		const http = Core.node( HTTP );
		// HttpOut defaults its transport on the first POST, so nothing is
		// wired until something is sent — which is what makes a palette drop
		// need no nonce threaded through construction.
		installFakeCommandWire( () => undefined );
		http.fill( newMessage() );
		expect( typeof http.client.postBatch ).toBe( 'function' );
	} );
} );

describe( 'useRequestLogGraph — slot keep-alive bridge', () => {
	test( 'a `connected` envelope populates heartbeat.slot', () => {
		renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'connected',
				pack( connectedEnvelope( { pid: 7, slot: 5 } ) )
			);
		} );
		expect( Core.node( HEARTBEAT ).slot ).toBe( 5 );
	} );

	test( 'a `connected` envelope with no slot leaves heartbeat slot null', () => {
		renderHook( () => useRequestLogGraph() );
		expectConsoleWarn(
			'ERROR: SseInNode: connected envelope missing or invalid SLOT'
		);
		act( () => {
			FakeEventSource.last.dispatch(
				'connected',
				pack( connectedEnvelope( { pid: 7, slot: null } ) )
			);
		} );
		expect( Core.node( HEARTBEAT ).slot ).toBeNull();
	} );

	test( 'the Router TIMER drives heartbeat.fire (via notify_timer) so the slot keep-alive actually fires', () => {
		jest.useFakeTimers();
		try {
			renderHook( () => useRequestLogGraph() );
			// Spy on the composed HttpOut's client.postBatch, not fetch().
			const http = Core.node( HTTP );
			const postBatch = jest.fn().mockResolvedValue( [] );
			http.client = { buildMessage: () => newMessage(), postBatch };
			act( () => {
				FakeEventSource.last.dispatch(
					'connected',
					pack( connectedEnvelope( { pid: 7, slot: 5 } ) )
				);
			} );
			// 1s Router TIMER ×5 = past the 5s base-Timer throttle.
			act( () => {
				jest.advanceTimersByTime( 5000 );
			} );
			expect( Core.node( HEARTBEAT ).lastFireTime ).toBeGreaterThan( 0 );
			expect( postBatch ).toHaveBeenCalled();
		} finally {
			jest.useRealTimers();
		}
	} );
} );

describe( 'useRequestLogGraph — end-to-end routing through the exospine', () => {
	test( 'a completed envelope from the EventSource flows into requestlog:view', () => {
		renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					completedEnvelope( {
						rid: 'r-flow',
						url: '/x',
						method: 'GET',
						status_code: 200,
						duration_ms: 5,
						end_time: 1,
					} )
				)
			);
		} );
		const view = Core.node( VIEW );
		expect( view.lines ).toHaveLength( 1 );
		expect( view.lines[ 0 ].rid ).toBe( 'r-flow' );
	} );
} );

describe( 'useRequestLogGraph — page visibility / pause lifecycle', () => {
	test( 'hiding the page closes the EventSource AND clears the heartbeat slot', () => {
		const { rerender } = renderHook( () => useRequestLogGraph() );
		// Acquire a slot first so we can prove clearSlot fires.
		act( () => {
			FakeEventSource.last.dispatch(
				'connected',
				pack( connectedEnvelope( { pid: 7, slot: 5 } ) )
			);
		} );
		expect( Core.node( HEARTBEAT ).slot ).toBe( 5 );
		const beforeHide = FakeEventSource.last;
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		expect( beforeHide.closed ).toBe( true );
		expect( Core.node( HEARTBEAT ).slot ).toBeNull();
	} );

	test( 'showing the page reopens the EventSource', () => {
		const { rerender } = renderHook( () => useRequestLogGraph() );
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		const before = FakeEventSource.instances.length;
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( FakeEventSource.instances.length ).toBeGreaterThan( before );
	} );

	test( 'reopening on refocus RESUMES from the last streamed offset (carries &positions=), not a blind tail', () => {
		const { rerender } = renderHook( () => useRequestLogGraph() );
		// Tailed record: ID holds segment:offset:length; FROM holds partition.
		const rec = completedEnvelope( { rid: 'r1', url: '/a' } );
		rec[ FROM ] = 'completed.p0';
		rec[ ID ] = '2:8800:120';
		act( () => {
			FakeEventSource.last.dispatch( 'msg', pack( rec ) );
		} );
		// Hide → close.
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		// Show → reopen seeks the last offset (fills the gap), not tail.
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		const url = FakeEventSource.last.url;
		expect( url ).toContain( 'positions=' );
		const positions = JSON.parse(
			decodeURIComponent(
				url.split( 'positions=' )[ 1 ].split( '&' )[ 0 ]
			)
		);
		expect( positions ).toEqual( {
			'completed.p0': { segment: 2, offset: 8800 + 120 },
		} );
	} );

	test( 'setPaused(true) closes the EventSource and clears the heartbeat slot', () => {
		const { result } = renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'connected',
				pack( connectedEnvelope( { pid: 7, slot: 5 } ) )
			);
		} );
		const openSource = FakeEventSource.last;
		act( () => result.current.setPaused( true ) );
		expect( openSource.closed ).toBe( true );
		expect( Core.node( HEARTBEAT ).slot ).toBeNull();
		expect( Core.node( VIEW ).setStateCache.view.paused ).toBe( true );
	} );

	test( 'setPaused(false) reopens the EventSource', () => {
		const { result } = renderHook( () => useRequestLogGraph() );
		act( () => result.current.setPaused( true ) );
		const before = FakeEventSource.instances.length;
		act( () => result.current.setPaused( false ) );
		expect( FakeEventSource.instances.length ).toBeGreaterThan( before );
		expect( Core.node( VIEW ).setStateCache.view.paused ).toBe( false );
	} );

	test( 'clear() empties the view buffer', () => {
		const { result } = renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'msg',
				pack(
					completedEnvelope( {
						rid: 'r1',
						url: '/x',
						method: 'GET',
						status_code: 200,
						end_time: 1,
					} )
				)
			);
		} );
		expect( Core.node( VIEW ).lines ).toHaveLength( 1 );
		act( () => result.current.clear() );
		expect( Core.node( VIEW ).lines ).toHaveLength( 0 );
	} );
} );

// The segment rail is the substrate's, handed over ready-made on
// `browse.sidebar`; a browse is a CLICK on it, as the user makes it.
function railItems( browse ) {
	const rail = renderComponent( browse.sidebar );
	const items = Array.from(
		rail.container.querySelectorAll( '.newspack-nodes-log-browser__item' )
	);
	return { items, unmount: () => rail.unmount() };
}

async function clickSegment( browse, id ) {
	const { items, unmount } = railItems( browse );
	const item = items.find( ( el ) =>
		el.textContent.includes( `Segment ${ id }` )
	);
	await act( async () => item.click() );
	unmount();
}

describe( 'useRequestLogGraph — glob browse', () => {
	test( 'exposes a browse model cataloging the `completed.*` partitions', async () => {
		installWire( {
			list_logs: [
				{ key: 'completed.p0', label: 'completed.p0' },
				{ key: 'completed.p2', label: 'completed.p2' },
				{ key: 'errors.p0', label: 'errors.p0' },
			],
		} );
		const { result } = renderHook( () => useRequestLogGraph() );
		await act( async () => {} );
		// The catalog rides the router tick, so it is a wait.
		await waitFor( () =>
			expect(
				result.current.browse.partitions.map( ( p ) => p.key )
			).toEqual( [ 'completed.p0', 'completed.p2' ] )
		);
	} );

	test( 'selecting a partition narrows the live SSE subscription to that dir', async () => {
		installWire( {
			list_logs: [
				{ key: 'completed.p2', label: 'completed.p2' },
				{ key: 'completed.p3', label: 'completed.p3' },
			],
		} );
		const { result } = renderHook( () => useRequestLogGraph() );
		await act( async () => {} );
		expect( FakeEventSource.last.url ).toContain( 'subscribe=completed.*' );
		await act( async () =>
			result.current.browse.selectPartition( 'completed.p2' )
		);
		expect( FakeEventSource.last.url ).toContain(
			'subscribe=completed.p2'
		);
		expect( FakeEventSource.last.url ).not.toContain(
			'subscribe=completed.*'
		);
	} );

	test( 'a segment browse PAUSES (stream closes); Play reopens at the seek', async () => {
		installWire( {
			list_logs: [
				{ key: 'completed.p2', label: 'completed.p2' },
				{ key: 'completed.p3', label: 'completed.p3' },
			],
			log_status: { segments: [ { id: 7, size: 4096 } ] },
		} );
		const { result } = renderHook( () => useRequestLogGraph() );
		await act( async () => {} );
		await act( async () =>
			result.current.browse.selectPartition( 'completed.p2' )
		);
		await waitFor( () =>
			expect(
				railItems( result.current.browse ).items.length
			).toBeGreaterThan( 0 )
		);
		await clickSegment( result.current.browse, 7 );
		// Time-travel: the click pauses; the closed stream never saw the seek.
		expect( FakeEventSource.last.closed ).toBe( true );
		// Play streams from the browsed segment (the recorded explicit seek).
		await act( async () => result.current.setPaused( false ) );
		const url = FakeEventSource.last.url;
		expect( url ).toContain( 'subscribe=completed.p2' );
		const positions = JSON.parse(
			decodeURIComponent(
				url.split( 'positions=' )[ 1 ].split( '&' )[ 0 ]
			)
		);
		expect( positions ).toEqual( {
			'completed.p2': { segment: 7, offset: 0 },
		} );
	} );
} );

describe( 'useRequestLogGraph — pause vs visibility precedence + replay survival', () => {
	// Regression guard: pause and visibility are already combined into ONE
	// isActive gate, so precedence holds by construction. This pins it against a
	// future fork back to a separate visibility path (the genuinely red-first
	// version lives on the substrate viewer hooks, which had a separate gate).
	test( 'a user pause outranks a visibility refocus: pause → hide → refocus stays CLOSED (no auto-resume)', () => {
		const { result, rerender } = renderHook( () => useRequestLogGraph() );
		act( () => {
			FakeEventSource.last.dispatch(
				'connected',
				pack( connectedEnvelope( { pid: 7, slot: 5 } ) )
			);
		} );
		act( () => result.current.setPaused( true ) );
		expect( FakeEventSource.last.closed ).toBe( true );
		const afterPause = FakeEventSource.instances.length;
		// Hiding then refocusing must NOT reopen a user-paused stream.
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( FakeEventSource.instances.length ).toBe( afterPause );
		expect( FakeEventSource.last.closed ).toBe( true );
	} );

	test( 'a paused replay resumes mid-replay and still flips to Live at the boundary', async () => {
		installWire( {
			list_logs: [
				{ key: 'completed.p0', label: 'completed.p0' },
				{ key: 'completed.p1', label: 'completed.p1' },
			],
			// Newest segment 9 is 500 bytes — the replay catch-up boundary.
			log_status: { segments: [ { id: 9, size: 500 } ] },
		} );
		const { result } = renderHook( () => useRequestLogGraph() );
		await act( async () => {} );
		await act( async () =>
			result.current.browse.selectPartition( 'completed.p0' )
		);
		// The rail is a tick away, and the replay boundary comes from it.
		await waitFor( () =>
			expect(
				railItems( result.current.browse ).items.length
			).toBeGreaterThan( 0 )
		);
		await clickSegment( result.current.browse, 9 );
		const view = Core.node( VIEW );
		expect( view.mode ).toBe( 'replay' );

		// The click paused; Play starts the replay (consuming the seek).
		await act( async () => result.current.setPaused( false ) );

		// A replayed record short of the boundary keeps replay + a resume cursor.
		const rec = completedEnvelope( { rid: 'r1', url: '/a' } );
		rec[ FROM ] = 'completed.p0';
		rec[ ID ] = '9:0:100';
		act( () => FakeEventSource.last.dispatch( 'msg', pack( rec ) ) );
		expect( view.mode ).toBe( 'replay' );

		// Pause closes the stream but does NOT tear down the view: mode survives.
		act( () => result.current.setPaused( true ) );
		expect( view.mode ).toBe( 'replay' );

		// Play resumes mid-replay at the exact next record, not a blind tail.
		act( () => result.current.setPaused( false ) );
		const url = FakeEventSource.last.url;
		const positions = JSON.parse(
			decodeURIComponent(
				url.split( 'positions=' )[ 1 ].split( '&' )[ 0 ]
			)
		);
		expect( positions ).toEqual( {
			'completed.p0': { segment: 9, offset: 0 + 100 },
		} );
		expect( view.mode ).toBe( 'replay' );

		// A post-resume record reaching the boundary flips Replay → Live.
		const caughtUp = completedEnvelope( { rid: 'r2', url: '/b' } );
		caughtUp[ FROM ] = 'completed.p0';
		caughtUp[ ID ] = '9:400:150';
		act( () => FakeEventSource.last.dispatch( 'msg', pack( caughtUp ) ) );
		expect( view.mode ).toBe( 'live' );
	} );

	test( 'a GC-stale resume cursor is sent verbatim (server owns validation), never clamped or thrown client-side', () => {
		// A resume whose offset the server has since GC'd past degrades via the
		// existing server-side resume validation (Consumer segment / Tail inode
		// checks). The client has no segment catalog at resume time, so it sends
		// the last-seen cursor unclamped and lets the server degrade — it must
		// not second-guess it or crash the UI.
		const { result } = renderHook( () => useRequestLogGraph() );
		const rec = completedEnvelope( { rid: 'old', url: '/a' } );
		rec[ FROM ] = 'completed.p0';
		// A far-past offset standing in for a since-GC'd cursor.
		rec[ ID ] = '2:999000:120';
		act( () => FakeEventSource.last.dispatch( 'msg', pack( rec ) ) );
		act( () => result.current.setPaused( true ) );
		expect( () =>
			act( () => result.current.setPaused( false ) )
		).not.toThrow();
		const url = FakeEventSource.last.url;
		const positions = JSON.parse(
			decodeURIComponent(
				url.split( 'positions=' )[ 1 ].split( '&' )[ 0 ]
			)
		);
		expect( positions ).toEqual( {
			'completed.p0': { segment: 2, offset: 999000 + 120 },
		} );
	} );
} );

describe( 'useRequestLogGraph — teardown', () => {
	test( 'unmount tears down the RemoteLink children + the backbone and closes the EventSource', () => {
		const { unmount } = renderHook( () => useRequestLogGraph() );
		const sourceAtMount = FakeEventSource.last;
		unmount();
		// The ROUTER is the page's heartbeat and is never torn down.
		for ( const name of [ ...COMPOSED_NAMES, LINK, VIEW, INTERPRETER ] ) {
			expect( Core.node( name ) ).toBeNull();
		}
		expect( sourceAtMount.closed ).toBe( true );
	} );

	test( 'late envelopes after unmount do not throw', () => {
		const { unmount } = renderHook( () => useRequestLogGraph() );
		const source = FakeEventSource.last;
		unmount();
		expect( () =>
			source.dispatch(
				'msg',
				pack( completedEnvelope( { rid: 'late', url: '/x' } ) )
			)
		).not.toThrow();
	} );
} );

describe( 'useRequestLogGraph — graphGeneration Reset Graph', () => {
	// The overlay owns the backbone; this dashboard is a reused mount whose
	// spine.reinit is subscribed to graphGeneration (the real Reset trigger).
	beforeEach( () => {
		mountExospine();
	} );

	test( 'a graphGeneration bump rebuilds the graph nodes fresh (backbone preserved)', () => {
		renderHook( () => useRequestLogGraph() );
		const firstView = Core.node( VIEW );
		const firstHttp = Core.node( HTTP );
		const backbone = Core.node( INTERPRETER );
		expect( firstView ).not.toBeNull();

		act( () => {
			Core.bumpGraphGeneration();
		} );

		// Soft nodes rebuild fresh; the backbone (incl. shared _http) survives.
		expect( Core.node( VIEW ) ).not.toBe( firstView );
		expect( Core.node( HTTP ) ).toBe( firstHttp );
		// The rebuilt link reopened the SseIn on the `completed` topic.
		expect( FakeEventSource.last.url ).toContain( 'subscribe=completed.*' );
		expect( Core.node( VIEW ).sink ).toBe( Core.node( INTERPRETER ) );
		expect( Core.node( INTERPRETER ) ).toBe( backbone );
	} );

	test( 'a graphGeneration bump re-renders the consumer so useNodeState re-subscribes to the fresh view', () => {
		const { result } = renderHook( () => {
			useRequestLogGraph();
			return useNodeState( VIEW, 'view' );
		} );
		const firstView = Core.node( VIEW );

		act( () => {
			Core.bumpGraphGeneration();
		} );
		const freshView = Core.node( VIEW );
		expect( freshView ).not.toBe( firstView );

		// Fresh view publishes state; consumer must observe it (re-subscribed).
		act( () => {
			freshView.setState( 'view', { paused: true } );
		} );
		expect( result.current ).toEqual( { paused: true } );
	} );

	test( 'reinit while paused re-publishes paused:true so the UI matches the closed stream', () => {
		const { result } = renderHook( () => useRequestLogGraph() );
		act( () => result.current.setPaused( true ) );
		expect( Core.node( VIEW ).setStateCache.view.paused ).toBe( true );

		act( () => {
			Core.bumpGraphGeneration();
		} );

		// Rebuilt view defaults paused:false; hook re-applies surviving pause.
		expect( Core.node( VIEW ).setStateCache.view.paused ).toBe( true );
	} );
} );
