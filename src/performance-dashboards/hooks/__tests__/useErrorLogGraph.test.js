/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * useErrorLogGraph tests — the Error Log dashboard graph clipped onto the exospine
 * (`mountExospine`: _command_interpreter → _router). The four graph nodes
 * (`perferrors:stream`, `perferrors:route`, `perferrors:transform`,
 * `perferrors:view`) are REAL; only the stream's connector is injected so the hook
 * never touches a real EventSource. EVERY node sinks into the CI and steers via
 * target; the end-to-end tests deliver an envelope/status through the fake
 * connector and assert it actually routes through the real router — data flows
 * stream → route → transform → view, a connection-status control flows stream →
 * route → view (skipping the transform). The hook also owns the page-visibility /
 * pause subscribe/close of the stream + the hook-direct pause/clear callbacks
 * (those dispatch straight to the view node, an external bridge — they are NOT
 * routed through the graph). Mirrors useRequestLogGraph's tests.
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

import { useErrorLogGraph } from '../useErrorLogGraph';

beforeEach( () => {
	Core.reset();
	mockPageVisible = true;
} );

const CI = '_command_interpreter';

// A fake connector matching the stream node's seam (connect/close); records the
// last subscription + status handler + close count for the assertions.
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

// An errors-feed envelope as the wire would deliver it (KEY=rid, VALUE=value).
function errorEnvelope( rid, value ) {
	const m = newMessage();
	m[ KEY ] = rid;
	m[ VALUE ] = value;
	return m;
}

describe( 'useErrorLogGraph — exospine wiring', () => {
	test( 'mounts the backbone + four nodes, each sinking into the CI', () => {
		const fake = makeFakeConnector();
		renderHook( () => useErrorLogGraph( { connector: fake } ) );
		const ci = Core.node( CI );
		expect( ci ).toBeTruthy();
		expect( Core.node( '_router' ) ).toBeTruthy();
		for ( const n of [
			'perferrors:stream',
			'perferrors:route',
			'perferrors:transform',
			'perferrors:view',
		] ) {
			expect( Core.node( n ) ).toBeTruthy();
			expect( Core.node( n ).sink ).toBe( ci );
		}
	} );

	test( 'steers flow with targets, not bespoke sinks (no controlSink)', () => {
		const fake = makeFakeConnector();
		renderHook( () => useErrorLogGraph( { connector: fake } ) );
		expect( Core.node( 'perferrors:stream' ).target ).toBe(
			'perferrors:route'
		);
		expect( Core.node( 'perferrors:route' ).target ).toBe(
			'perferrors:transform'
		);
		expect( Core.node( 'perferrors:transform' ).target ).toBe(
			'perferrors:view'
		);
		expect( Core.node( 'perferrors:stream' ).controlSink ).toBeUndefined();
	} );

	test( 'subscribes the stream to the errors feed on mount when visible', () => {
		const fake = makeFakeConnector();
		renderHook( () => useErrorLogGraph( { connector: fake } ) );
		expect( fake.connectCount ).toBeGreaterThanOrEqual( 1 );
		expect( fake.lastSubscription ).toBe( 'errors' );
	} );

	test( 'does not subscribe on mount when the page is hidden', () => {
		mockPageVisible = false;
		const fake = makeFakeConnector();
		renderHook( () => useErrorLogGraph( { connector: fake } ) );
		expect( fake.connectCount ).toBe( 0 );
	} );
} );

describe( 'useErrorLogGraph — end-to-end routing through the exospine', () => {
	test( 'a delivered errors envelope routes stream → route → transform → view', () => {
		const fake = makeFakeConnector();
		renderHook( () => useErrorLogGraph( { connector: fake } ) );
		act( () => {
			fake.deliverMessage(
				errorEnvelope( 'r-flow', { ts: 1, k: 'error', m: 'boom' } )
			);
		} );
		const view = Core.node( 'perferrors:view' );
		expect( view.entries ).toHaveLength( 1 );
		expect( view.entries[ 0 ].rid ).toBe( 'r-flow' );
	} );

	test( 'a connection-status control routes stream → route → view (skips transform)', () => {
		const fake = makeFakeConnector();
		renderHook( () => useErrorLogGraph( { connector: fake } ) );
		act( () => fake.deliverStatus( { connectionError: true } ) );
		const view = Core.node( 'perferrors:view' );
		expect( view.connectionError ).toBe( true );
		expect( view.setStateCache.view.connectionError ).toBe( true );
		// The status did NOT produce a row (the transform would have dropped it).
		expect( view.entries ).toHaveLength( 0 );
	} );
} );

describe( 'useErrorLogGraph — page visibility', () => {
	test( 'closes the stream when the page becomes hidden, re-subscribes when visible', () => {
		const fake = makeFakeConnector();
		const { rerender } = renderHook( () =>
			useErrorLogGraph( { connector: fake } )
		);
		const afterMount = fake.connectCount;
		expect( afterMount ).toBeGreaterThanOrEqual( 1 );
		mockPageVisible = false;
		act( () => rerender( { n: 1 } ) );
		expect( fake.closeCount ).toBeGreaterThanOrEqual( 1 );
		mockPageVisible = true;
		act( () => rerender( { n: 2 } ) );
		expect( fake.connectCount ).toBeGreaterThan( afterMount );
	} );
} );

describe( 'useErrorLogGraph — control callbacks', () => {
	test( 'setPaused(true) publishes paused in the view and closes the stream', () => {
		const fake = makeFakeConnector();
		const { result } = renderHook( () =>
			useErrorLogGraph( { connector: fake } )
		);
		const closesBefore = fake.closeCount;
		act( () => result.current.setPaused( true ) );
		expect( Core.node( 'perferrors:view' ).setStateCache.view.paused ).toBe(
			true
		);
		expect( fake.closeCount ).toBeGreaterThan( closesBefore );
	} );

	test( 'setPaused(false) re-subscribes the stream', () => {
		const fake = makeFakeConnector();
		const { result } = renderHook( () =>
			useErrorLogGraph( { connector: fake } )
		);
		act( () => result.current.setPaused( true ) );
		const connectsWhilePaused = fake.connectCount;
		act( () => result.current.setPaused( false ) );
		expect( fake.connectCount ).toBeGreaterThan( connectsWhilePaused );
		expect( Core.node( 'perferrors:view' ).setStateCache.view.paused ).toBe(
			false
		);
	} );

	test( 'clear() empties the view buffer', () => {
		const fake = makeFakeConnector();
		const { result } = renderHook( () =>
			useErrorLogGraph( { connector: fake } )
		);
		act( () => {
			fake.deliverMessage(
				errorEnvelope( 'r1', { ts: 1, k: 'error', m: 'x' } )
			);
		} );
		expect( Core.node( 'perferrors:view' ).entries ).toHaveLength( 1 );
		act( () => result.current.clear() );
		expect( Core.node( 'perferrors:view' ).entries ).toHaveLength( 0 );
	} );
} );

describe( 'useErrorLogGraph — teardown', () => {
	test( 'unmount unregisters the graph + the backbone and closes the stream', () => {
		const fake = makeFakeConnector();
		const { unmount } = renderHook( () =>
			useErrorLogGraph( { connector: fake } )
		);
		unmount();
		for ( const n of [
			'perferrors:stream',
			'perferrors:route',
			'perferrors:transform',
			'perferrors:view',
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
			useErrorLogGraph( { connector: fake } )
		);
		unmount();
		expect( () =>
			fake.deliverMessage(
				errorEnvelope( 'late', { ts: 1, k: 'error', m: 'x' } )
			)
		).not.toThrow();
	} );
} );
