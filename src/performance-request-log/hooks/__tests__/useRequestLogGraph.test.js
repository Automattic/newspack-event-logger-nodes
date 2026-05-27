/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * useRequestLogGraph tests — the Request Log dashboard graph clipped onto the
 * exospine (`mountExospine`: _command_interpreter → _router). The four graph nodes
 * (`requestlog:stream`, `requestlog:route`, `requestlog:transform`,
 * `requestlog:view`) are REAL; only the stream's connector is injected so the hook
 * never touches a real EventSource. EVERY node sinks into the CI and steers via
 * target; the end-to-end tests deliver an envelope/status through the fake
 * connector and assert it actually routes through the real router — data flows
 * stream → route → transform → view, a connection-status control flows stream →
 * route → view (skipping the transform). The hook also owns the page-visibility /
 * pause subscribe/close of the stream + the hook-direct pause/clear callbacks
 * (those dispatch straight to the view node, an external bridge — they are NOT
 * routed through the graph).
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

const CI = '_command_interpreter';

// A fake connector matching the stream node's seam (connect/close); records the
// last subscription + status handler + counts for the teardown / visibility /
// pause / connection-status assertions.
function makeFakeConnector() {
	return {
		closeCount: 0,
		connectCount: 0,
		lastSubscription: null,
		_onEnvelope: null,
		_onStatus: null,
		connect( subscription, onEnvelope, onStatus ) {
			this.connectCount += 1;
			this.lastSubscription = subscription;
			this._onEnvelope = onEnvelope;
			this._onStatus = onStatus;
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
		deliverStatus( status ) {
			if ( this._onStatus ) {
				this._onStatus( status );
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

describe( 'useRequestLogGraph — exospine wiring', () => {
	test( 'mounts the backbone + four nodes, each sinking into the CI', () => {
		const fake = makeFakeConnector();
		renderHook( () => useRequestLogGraph( { connector: fake } ) );
		const ci = Core.node( CI );
		expect( ci ).toBeTruthy();
		expect( Core.node( '_router' ) ).toBeTruthy();
		for ( const n of [
			'requestlog:stream',
			'requestlog:route',
			'requestlog:transform',
			'requestlog:view',
		] ) {
			expect( Core.node( n ) ).toBeTruthy();
			expect( Core.node( n ).sink ).toBe( ci );
		}
	} );

	test( 'steers flow with targets, not bespoke sinks (no controlSink)', () => {
		const fake = makeFakeConnector();
		renderHook( () => useRequestLogGraph( { connector: fake } ) );
		expect( Core.node( 'requestlog:stream' ).target ).toBe(
			'requestlog:route'
		);
		expect( Core.node( 'requestlog:route' ).target ).toBe(
			'requestlog:transform'
		);
		expect( Core.node( 'requestlog:transform' ).target ).toBe(
			'requestlog:view'
		);
		expect( Core.node( 'requestlog:stream' ).controlSink ).toBeUndefined();
	} );

	test( 'subscribes the stream to the completed feed on mount when visible', () => {
		const fake = makeFakeConnector();
		renderHook( () => useRequestLogGraph( { connector: fake } ) );
		expect( fake.connectCount ).toBeGreaterThanOrEqual( 1 );
		expect( fake.lastSubscription ).toBe( 'completed' );
	} );

	test( 'does not subscribe on mount when the page is hidden', () => {
		mockPageVisible = false;
		const fake = makeFakeConnector();
		renderHook( () => useRequestLogGraph( { connector: fake } ) );
		expect( fake.connectCount ).toBe( 0 );
	} );
} );

describe( 'useRequestLogGraph — end-to-end routing through the exospine', () => {
	test( 'a delivered completed envelope routes stream → route → transform → view', () => {
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
		const view = Core.node( 'requestlog:view' );
		expect( view.entries ).toHaveLength( 1 );
		expect( view.entries[ 0 ].rid ).toBe( 'r-flow' );
	} );

	test( 'a connection-status control routes stream → route → view (skips transform)', () => {
		const fake = makeFakeConnector();
		renderHook( () => useRequestLogGraph( { connector: fake } ) );
		act( () => fake.deliverStatus( { connectionError: true } ) );
		const view = Core.node( 'requestlog:view' );
		expect( view.connectionError ).toBe( true );
		expect( view.setStateCache.view.connectionError ).toBe( true );
		// The status did NOT produce a row (the transform would have dropped it).
		expect( view.entries ).toHaveLength( 0 );
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
		expect( Core.node( 'requestlog:view' ).setStateCache.view.paused ).toBe(
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
		expect( Core.node( 'requestlog:view' ).setStateCache.view.paused ).toBe(
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
		expect( Core.node( 'requestlog:view' ).entries ).toHaveLength( 1 );
		act( () => result.current.clear() );
		expect( Core.node( 'requestlog:view' ).entries ).toHaveLength( 0 );
	} );
} );

describe( 'useRequestLogGraph — teardown', () => {
	test( 'unmount unregisters the graph + the backbone and closes the stream', () => {
		const fake = makeFakeConnector();
		const { unmount } = renderHook( () =>
			useRequestLogGraph( { connector: fake } )
		);
		unmount();
		for ( const n of [
			'requestlog:stream',
			'requestlog:route',
			'requestlog:transform',
			'requestlog:view',
			'_command_interpreter',
			'_router',
		] ) {
			expect( Core.node( n ) ).toBeNull();
		}
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
