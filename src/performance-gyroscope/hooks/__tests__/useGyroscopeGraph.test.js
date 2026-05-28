/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * useGyroscopeGraph tests — the Gyroscope dashboard graph clipped onto the
 * exospine (`mountExospine`: _command_interpreter → _router). The four graph
 * nodes (`gyroscope:stream`, `gyroscope:route`, `gyroscope:transform`,
 * `gyroscope:view`) are REAL; only the stream's connector is injected so the hook
 * never touches a real EventSource. EVERY node sinks into the CI and steers via
 * target; the end-to-end tests deliver an envelope/status through the fake
 * connector and assert it actually routes through the real router — data flows
 * stream → route → transform → view, a connection-status control flows stream →
 * route → view (skipping the transform). The hook also owns the page-visibility
 * subscribe/close of the stream + the reset-on-(re)connect of the view map.
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

const CI = '_command_interpreter';

// A fake connector matching the stream node's seam (connect/close); records the
// last subscription + status handler + counts so teardown / visibility /
// connection-status assertions can read them.
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

// A gyroscope inflight-snapshot envelope as the wire would deliver it.
function inflightEnvelope( requests ) {
	const m = newMessage();
	m[ KEY ] = 'inflight';
	m[ VALUE ] = requests;
	return m;
}

describe( 'useGyroscopeGraph — exospine wiring', () => {
	test( 'mounts the backbone + four nodes, each sinking into the CI', () => {
		const fake = makeFakeConnector();
		renderHook( () => useGyroscopeGraph( { connector: fake } ) );
		const ci = Core.node( CI );
		expect( ci ).toBeTruthy();
		expect( Core.node( '_router' ) ).toBeTruthy();
		for ( const n of [
			'gyroscope:stream',
			'gyroscope:route',
			'gyroscope:transform',
			'gyroscope:view',
		] ) {
			expect( Core.node( n ) ).toBeTruthy();
			expect( Core.node( n ).sink ).toBe( ci );
		}
	} );

	test( 'steers flow with targets, not bespoke sinks (no controlSink)', () => {
		const fake = makeFakeConnector();
		renderHook( () => useGyroscopeGraph( { connector: fake } ) );
		expect( Core.node( 'gyroscope:stream' ).target ).toBe(
			'gyroscope:route'
		);
		expect( Core.node( 'gyroscope:route' ).target ).toBe(
			'gyroscope:transform'
		);
		expect( Core.node( 'gyroscope:transform' ).target ).toBe(
			'gyroscope:view'
		);
		expect( Core.node( 'gyroscope:stream' ).controlSink ).toBeUndefined();
	} );

	test( 'subscribes the stream to the gyroscope feed on mount when visible', () => {
		const fake = makeFakeConnector();
		renderHook( () => useGyroscopeGraph( { connector: fake } ) );
		expect( fake.connectCount ).toBeGreaterThanOrEqual( 1 );
		expect( fake.lastSubscription ).toBe( 'gyroscope' );
	} );

	test( 'does not subscribe on mount when the page is hidden', () => {
		mockPageVisible = false;
		const fake = makeFakeConnector();
		renderHook( () => useGyroscopeGraph( { connector: fake } ) );
		expect( fake.connectCount ).toBe( 0 );
	} );
} );

describe( 'useGyroscopeGraph — end-to-end routing through the exospine', () => {
	test( 'a delivered inflight envelope routes stream → route → transform → view', () => {
		const fake = makeFakeConnector();
		renderHook( () => useGyroscopeGraph( { connector: fake } ) );
		act( () => {
			fake.deliverMessage(
				inflightEnvelope( [
					{ rid: 'r-flow', url: '/x', state: 'process' },
				] )
			);
		} );
		const view = Core.node( 'gyroscope:view' );
		expect( view.requests.has( 'r-flow' ) ).toBe( true );
	} );

	test( 'a connection-status control routes stream → route → view (skips transform)', () => {
		const fake = makeFakeConnector();
		renderHook( () => useGyroscopeGraph( { connector: fake } ) );
		act( () => fake.deliverStatus( { connectionError: true } ) );
		const view = Core.node( 'gyroscope:view' );
		expect( view.connectionError ).toBe( true );
		expect( view.setStateCache.view.connectionError ).toBe( true );
		// The status did NOT enter the in-flight map (the transform would drop it).
		expect( view.requests.size ).toBe( 0 );
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
		expect( Core.node( 'gyroscope:view' ).requests.size ).toBe( 1 );
		// Hide then show → the re-subscribe path clears the map first.
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( Core.node( 'gyroscope:view' ).requests.size ).toBe( 0 );
	} );
} );

describe( 'useGyroscopeGraph — teardown', () => {
	test( 'unmount unregisters the graph + the backbone and closes the stream', () => {
		const fake = makeFakeConnector();
		const { unmount } = renderHook( () =>
			useGyroscopeGraph( { connector: fake } )
		);
		unmount();
		for ( const n of [
			'gyroscope:stream',
			'gyroscope:route',
			'gyroscope:transform',
			'gyroscope:view',
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
