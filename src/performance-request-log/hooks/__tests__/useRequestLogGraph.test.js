/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * useRequestLogGraph tests — the Request Log dashboard graph. The three nodes
 * (`requestlog/stream`, `requestlog/transform`, `requestlog/view`) are REAL
 * (their factories register them in Core); only the stream's connector is
 * injected so the hook never touches a real EventSource. The hook owns the
 * page-visibility pause/resume of the stream and the control callbacks. Mirrors
 * useRawLogsGraph's tests (real graph, faked I/O boundary).
 *
 * usePageVisibility is mocked to a controllable value so the visibility effect is
 * deterministic under jsdom.
 */

import { renderHook, act } from '../../../shared/hooks/__tests__/renderHook';
import { newMessage, VALUE, Core } from '@newspack-nodes/runtime';

let mockPageVisible = true;
jest.mock( '../../../shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => mockPageVisible,
} ) );

import { useRequestLogGraph } from '../useRequestLogGraph';

beforeEach( () => {
	Core.reset();
	mockPageVisible = true;
} );

// A fake connector matching the stream node's seam (connect/close); records the
// last subscription + close count for the teardown / pause assertions.
function makeFakeConnector() {
	return {
		closeCount: 0,
		connectCount: 0,
		lastSubscription: null,
		_onEnvelope: null,
		connect( subscription, onEnvelope ) {
			this.connectCount += 1;
			this.lastSubscription = subscription;
			this._onEnvelope = onEnvelope;
		},
		close() {
			this.closeCount += 1;
			this._onEnvelope = null;
		},
		deliverMessage( envelope ) {
			if ( this._onEnvelope ) {
				this._onEnvelope( envelope );
			}
		},
	};
}

// A completed-request envelope as the wire would deliver it.
function completedEnvelope( req ) {
	const m = newMessage();
	m[ VALUE ] = req;
	return m;
}

describe( 'useRequestLogGraph — mount + wiring', () => {
	test( 'mounts the three nodes wired stream→transform→view', () => {
		const fake = makeFakeConnector();
		renderHook( () => useRequestLogGraph( { connector: fake } ) );
		expect( Core.node( 'requestlog/stream' ) ).toBeTruthy();
		expect( Core.node( 'requestlog/transform' ) ).toBeTruthy();
		expect( Core.node( 'requestlog/view' ) ).toBeTruthy();
		expect( Core.node( 'requestlog/stream' ).sink ).toBe(
			Core.node( 'requestlog/transform' )
		);
		expect( Core.node( 'requestlog/transform' ).sink ).toBe(
			Core.node( 'requestlog/view' )
		);
	} );

	test( 'subscribes the stream to the completed feed on mount when visible', () => {
		const fake = makeFakeConnector();
		renderHook( () => useRequestLogGraph( { connector: fake } ) );
		expect( fake.connectCount ).toBeGreaterThanOrEqual( 1 );
		expect( fake.lastSubscription ).toBe( 'completed' );
	} );

	test( 'a delivered completed envelope flows stream→transform→view as an entry', () => {
		const fake = makeFakeConnector();
		renderHook( () => useRequestLogGraph( { connector: fake } ) );
		act( () => {
			fake.deliverMessage(
				completedEnvelope( {
					rid: 'r-flow',
					url: '/x',
					method: 'GET',
					status_code: 200,
					duration_ms: 5,
					end_time: 1,
				} )
			);
		} );
		const view = Core.node( 'requestlog/view' );
		expect( view.entries ).toHaveLength( 1 );
		expect( view.entries[ 0 ].rid ).toBe( 'r-flow' );
	} );

	test( 'does not subscribe on mount when the page is hidden', () => {
		mockPageVisible = false;
		const fake = makeFakeConnector();
		renderHook( () => useRequestLogGraph( { connector: fake } ) );
		expect( fake.connectCount ).toBe( 0 );
	} );
} );

describe( 'useRequestLogGraph — page visibility', () => {
	test( 'closes the stream when the page becomes hidden, re-subscribes when visible', () => {
		const fake = makeFakeConnector();
		// The local renderHook bails on identical props, so pass a fresh nonce
		// each rerender to force the wrapper to re-invoke the (mocked) hook.
		const { rerender } = renderHook( () =>
			useRequestLogGraph( { connector: fake } )
		);
		const afterMount = fake.connectCount;
		expect( afterMount ).toBeGreaterThanOrEqual( 1 );
		// Hide the page and re-render → the visibility effect closes the stream.
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		expect( fake.closeCount ).toBeGreaterThanOrEqual( 1 );
		// Show again → it re-subscribes.
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( fake.connectCount ).toBeGreaterThan( afterMount );
	} );
} );

describe( 'useRequestLogGraph — control callbacks', () => {
	test( 'setPaused(true) publishes paused in the view and closes the stream', () => {
		const fake = makeFakeConnector();
		const { result } = renderHook( () =>
			useRequestLogGraph( { connector: fake } )
		);
		const closesBefore = fake.closeCount;
		act( () => result.current.setPaused( true ) );
		expect( Core.node( 'requestlog/view' ).setStateCache.view.paused ).toBe(
			true
		);
		expect( fake.closeCount ).toBeGreaterThan( closesBefore );
	} );

	test( 'setPaused(false) re-subscribes the stream', () => {
		const fake = makeFakeConnector();
		const { result } = renderHook( () =>
			useRequestLogGraph( { connector: fake } )
		);
		act( () => result.current.setPaused( true ) );
		const connectsWhilePaused = fake.connectCount;
		act( () => result.current.setPaused( false ) );
		expect( fake.connectCount ).toBeGreaterThan( connectsWhilePaused );
		expect( Core.node( 'requestlog/view' ).setStateCache.view.paused ).toBe(
			false
		);
	} );

	test( 'clear() empties the view buffer', () => {
		const fake = makeFakeConnector();
		const { result } = renderHook( () =>
			useRequestLogGraph( { connector: fake } )
		);
		act( () => {
			fake.deliverMessage(
				completedEnvelope( {
					rid: 'r1',
					url: '/x',
					method: 'GET',
					status_code: 200,
					end_time: 1,
				} )
			);
		} );
		expect( Core.node( 'requestlog/view' ).entries ).toHaveLength( 1 );
		act( () => result.current.clear() );
		expect( Core.node( 'requestlog/view' ).entries ).toHaveLength( 0 );
	} );
} );

describe( 'useRequestLogGraph — teardown', () => {
	test( 'unmount unregisters all three and closes the stream', () => {
		const fake = makeFakeConnector();
		const { unmount } = renderHook( () =>
			useRequestLogGraph( { connector: fake } )
		);
		unmount();
		expect( Core.node( 'requestlog/stream' ) ).toBeNull();
		expect( Core.node( 'requestlog/transform' ) ).toBeNull();
		expect( Core.node( 'requestlog/view' ) ).toBeNull();
		expect( fake.closeCount ).toBeGreaterThanOrEqual( 1 );
	} );

	test( 'envelopes delivered after unmount do not throw (stream closed)', () => {
		const fake = makeFakeConnector();
		const { unmount } = renderHook( () =>
			useRequestLogGraph( { connector: fake } )
		);
		unmount();
		expect( () =>
			fake.deliverMessage(
				completedEnvelope( { rid: 'late', url: '/x' } )
			)
		).not.toThrow();
	} );
} );
