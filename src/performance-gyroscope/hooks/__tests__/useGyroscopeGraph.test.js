/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * useGyroscopeGraph tests — the Gyroscope dashboard graph. The three nodes
 * (`gyroscope/stream`, `gyroscope/transform`, `gyroscope/view`) are REAL (their
 * factories register them in Core); only the stream's connector is injected so
 * the hook never touches a real EventSource. The hook owns the page-visibility
 * subscribe/close of the stream + the reset-on-(re)connect of the view map.
 * Mirrors useRequestLogGraph's tests (real graph, faked I/O boundary).
 *
 * usePageVisibility is mocked to a controllable value so the visibility effect is
 * deterministic under jsdom.
 */

import { renderHook, act } from '../../../shared/hooks/__tests__/renderHook';
import { newMessage, KEY, VALUE, Core } from '@newspack-nodes/runtime';

let mockPageVisible = true;
jest.mock( '../../../shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => mockPageVisible,
} ) );

import { useGyroscopeGraph } from '../useGyroscopeGraph';

beforeEach( () => {
	Core.reset();
	mockPageVisible = true;
} );

// A fake connector matching the stream node's seam (connect/close); records the
// last subscription + counts so teardown / visibility assertions can read them.
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

// A gyroscope inflight-snapshot envelope as the wire would deliver it.
function inflightEnvelope( requests ) {
	const m = newMessage();
	m[ KEY ] = 'inflight';
	m[ VALUE ] = requests;
	return m;
}

describe( 'useGyroscopeGraph — mount + wiring', () => {
	test( 'mounts the three nodes wired stream→transform→view', () => {
		const fake = makeFakeConnector();
		renderHook( () => useGyroscopeGraph( { connector: fake } ) );
		expect( Core.node( 'gyroscope/stream' ) ).toBeTruthy();
		expect( Core.node( 'gyroscope/transform' ) ).toBeTruthy();
		expect( Core.node( 'gyroscope/view' ) ).toBeTruthy();
		expect( Core.node( 'gyroscope/stream' ).sink ).toBe(
			Core.node( 'gyroscope/transform' )
		);
		expect( Core.node( 'gyroscope/transform' ).sink ).toBe(
			Core.node( 'gyroscope/view' )
		);
	} );

	test( 'subscribes the stream to the gyroscope feed on mount when visible', () => {
		const fake = makeFakeConnector();
		renderHook( () => useGyroscopeGraph( { connector: fake } ) );
		expect( fake.connectCount ).toBeGreaterThanOrEqual( 1 );
		expect( fake.lastSubscription ).toBe( 'gyroscope' );
	} );

	test( 'a delivered inflight envelope flows stream→transform→view into the map', () => {
		const fake = makeFakeConnector();
		renderHook( () => useGyroscopeGraph( { connector: fake } ) );
		act( () => {
			fake.deliverMessage(
				inflightEnvelope( [
					{ rid: 'r-flow', url: '/x', state: 'process' },
				] )
			);
		} );
		const view = Core.node( 'gyroscope/view' );
		expect( view.requests.has( 'r-flow' ) ).toBe( true );
	} );

	test( 'does not subscribe on mount when the page is hidden', () => {
		mockPageVisible = false;
		const fake = makeFakeConnector();
		renderHook( () => useGyroscopeGraph( { connector: fake } ) );
		expect( fake.connectCount ).toBe( 0 );
	} );
} );

describe( 'useGyroscopeGraph — page visibility', () => {
	test( 'closes the stream when the page becomes hidden, re-subscribes when visible', () => {
		const fake = makeFakeConnector();
		// The local renderHook bails on identical props, so pass a fresh nonce
		// each rerender to force the wrapper to re-invoke the (mocked) hook.
		const { rerender } = renderHook( () =>
			useGyroscopeGraph( { connector: fake } )
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

	test( 'resets the view map on (re)connect', () => {
		const fake = makeFakeConnector();
		const { rerender } = renderHook( () =>
			useGyroscopeGraph( { connector: fake } )
		);
		// Seed the map with an in-flight request.
		act( () => {
			fake.deliverMessage(
				inflightEnvelope( [
					{ rid: 'old', url: '/x', state: 'process' },
				] )
			);
		} );
		expect( Core.node( 'gyroscope/view' ).requests.size ).toBe( 1 );
		// Hide then show → the re-subscribe path clears the map first.
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( Core.node( 'gyroscope/view' ).requests.size ).toBe( 0 );
	} );
} );

describe( 'useGyroscopeGraph — teardown', () => {
	test( 'unmount unregisters all three and closes the stream', () => {
		const fake = makeFakeConnector();
		const { unmount } = renderHook( () =>
			useGyroscopeGraph( { connector: fake } )
		);
		unmount();
		expect( Core.node( 'gyroscope/stream' ) ).toBeNull();
		expect( Core.node( 'gyroscope/transform' ) ).toBeNull();
		expect( Core.node( 'gyroscope/view' ) ).toBeNull();
		expect( fake.closeCount ).toBeGreaterThanOrEqual( 1 );
	} );

	test( 'envelopes delivered after unmount do not throw (stream closed)', () => {
		const fake = makeFakeConnector();
		const { unmount } = renderHook( () =>
			useGyroscopeGraph( { connector: fake } )
		);
		unmount();
		expect( () =>
			fake.deliverMessage(
				inflightEnvelope( [
					{ rid: 'late', url: '/x', state: 'process' },
				] )
			)
		).not.toThrow();
	} );
} );
