/* global KeyboardEvent */
/**
 * Inflight UI-surface tests — the thin view over the gyroscope node graph.
 *
 * The graph is owned by useGyroscopeGraph (tested separately); here we mock it so
 * the view never touches a real EventSource, and we register a fixture
 * `gyroscope:view` node in Core whose `snapshot()` returns the render rows and
 * whose `rps` / `lastEventTime` the refresh tick reads. The view samples the node
 * on its refresh-interval timer (the gyroscope analog of RequestStream's rAF), so
 * tests advance fake timers to drive a render pass.
 */

jest.mock( '../hooks/useGyroscopeGraph', () => ( {
	useGyroscopeGraph: jest.fn(),
} ) );

import * as React from 'react';
import { Core } from '@newspack-nodes/runtime';
import Inflight from '../Inflight';
import { renderComponent, act } from '../../test-helpers/renderHook';

const { useGyroscopeGraph } = require( '../hooks/useGyroscopeGraph' );

// A minimal stand-in for the gyroscope:view node: snapshot() returns the render
// rows (the real node reaps + sorts + caps here), and rps / lastEventTime live on
// the instance — exactly what the refresh tick reads off Core.node('gyroscope:view').
// The register/setState/setStateCache machinery backs useNodeState('gyroscope:view',
// 'view'), which the view reads for the low-frequency { connectionError } model
// (the reconnect banner). setState here notifies subscribers like the real Node.
function registerViewFixture( {
	rows = [],
	rps = 0,
	lastEventTime = null,
	connectionError = false,
} = {} ) {
	const node = {
		rps,
		lastEventTime,
		snapshot: jest.fn( () => rows ),
		registrations: { view: {} },
		setStateCache: {},
		register( event, listener, cb ) {
			this.registrations[ event ][ listener ] = cb;
			if ( event in this.setStateCache ) {
				cb( this.setStateCache[ event ] );
			}
		},
		unregister( event, listener ) {
			delete this.registrations[ event ]?.[ listener ];
		},
		setState( event, payload ) {
			this.setStateCache[ event ] = payload;
			Object.values( this.registrations[ event ] || {} ).forEach(
				( cb ) => cb( payload )
			);
		},
	};
	node.setState( 'view', { connectionError } );
	Core.nodes.set( 'gyroscope:view', node );
	return node;
}

describe( 'Inflight', () => {
	const mounted = [];

	function mount( props = {} ) {
		const r = renderComponent(
			React.createElement( Inflight, { maxRows: 100, ...props } )
		);
		mounted.push( r );
		return r;
	}

	beforeEach( () => {
		Core.reset();
		useGyroscopeGraph.mockClear();
		useGyroscopeGraph.mockReturnValue( {} );
		jest.useFakeTimers();
	} );

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
		jest.useRealTimers();
		// Clear localStorage between tests so persisted state doesn't leak.
		window.localStorage.clear();
	} );

	// Advance the default 2s refresh interval so renderRequests samples the node.
	const tickRefresh = () => {
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
	};

	it( 'renders the In-Flight Requests heading', () => {
		registerViewFixture();
		const { container } = mount();
		expect( container.textContent ).toContain( 'In-Flight Requests' );
	} );

	it( 'mounts the gyroscope graph via useGyroscopeGraph', () => {
		registerViewFixture();
		mount();
		expect( useGyroscopeGraph ).toHaveBeenCalled();
	} );

	it( 'renders an empty message initially', () => {
		registerViewFixture();
		const { container } = mount();
		expect( container.textContent ).toContain( 'No active requests.' );
	} );

	it( 'shows the reconnect banner when the view model reports connectionError', () => {
		registerViewFixture( { connectionError: true } );
		const { container } = mount();
		expect(
			container.querySelector( '.newspack-nodes-connection-banner' )
		).toBeTruthy();
	} );

	it( 'does not show the reconnect banner when connected', () => {
		registerViewFixture( { connectionError: false } );
		const { container } = mount();
		expect(
			container.querySelector( '.newspack-nodes-connection-banner' )
		).toBeNull();
	} );

	it( 'renders rows read from the view node snapshot on a refresh tick', () => {
		const node = registerViewFixture( {
			rows: [
				{
					rid: 'r1',
					url: '/foo',
					method: 'GET',
					state: 'process',
					what: 'foo',
				},
			],
		} );
		const { container } = mount();
		tickRefresh();
		expect( node.snapshot ).toHaveBeenCalledWith( 100 );
		expect( container.textContent ).toContain( 'r1' );
	} );

	it( 'displays the requests/second read from the node on a refresh tick', () => {
		registerViewFixture( {
			rows: [ { rid: 'r-rps', url: '/x', state: 'process' } ],
			rps: 4.2,
		} );
		const { container } = mount();
		tickRefresh();
		const rps = container.querySelector( '.event-logger-inflight-rps' );
		expect( rps ).not.toBeNull();
		expect( rps.textContent ).toMatch( /4\.2 req\/s/ );
	} );

	it( 'renders persisted non-default columns from the sampled row model', () => {
		window.localStorage.setItem( 'event-logger-inflight-refresh', '1' );
		window.localStorage.setItem(
			'event-logger-columns',
			JSON.stringify( [
				'rid',
				'url',
				'status_code',
				'state',
				'what',
				'remote_addr',
				'user_agent',
				'est',
				'time',
				'age',
				'lag',
			] )
		);
		registerViewFixture( {
			rows: [
				{
					rid: 'r-columns',
					url: '/columns?debug=1',
					method: 'POST',
					status_code: 503,
					state: 'include template',
					what: 'Templates/Home.html',
					remote_addr: '203.0.113.10',
					user_agent: 'Jest Browser',
					est_ms: 250,
					time_ms: 125,
					last_log_ts: Date.now() / 1000 - 6,
					lag_ms: 1200,
				},
			],
		} );
		const { container } = mount();
		tickRefresh();
		expect(
			container.querySelector( '.event-logger-refresh-select' ).value
		).toBe( '1' );
		expect( container.textContent ).toContain( 'r-columns' );
		expect( container.textContent ).toContain( 'template' );
		expect( container.textContent ).toContain( '203.0.113.10' );
		expect( container.textContent ).toContain( 'Jest Browser' );
	} );

	it( 'sources "Xs ago" staleness from the link connector, not row arrivals', () => {
		// The connector owns stream liveness (it sees data rows AND heartbeats), so
		// an idle-but-healthy stream — view node with no row arrivals — still shows a
		// fresh "ago" off the connector's lastEventTime.
		registerViewFixture(); // no view-node lastEventTime
		Core.nodes.set( 'gyroscope:link', { lastEventTime: () => Date.now() } );
		const { container } = mount();
		tickRefresh();
		expect( container.textContent ).toMatch( /\d+s ago/ );
	} );

	it( 'hides "Xs ago" when the link stream is closed (lastEventTime null)', () => {
		registerViewFixture( { lastEventTime: Date.now() } ); // view-node time is ignored now
		Core.nodes.set( 'gyroscope:link', { lastEventTime: () => null } );
		const { container } = mount();
		tickRefresh();
		expect( container.textContent ).not.toMatch( /\d+s ago/ );
	} );

	it( 'does not throw when the view node is absent (no fixture registered)', () => {
		const { container } = mount();
		expect( () => tickRefresh() ).not.toThrow();
		expect( container.textContent ).toContain( 'No active requests.' );
	} );

	it( 'persists the refresh interval via localStorage', () => {
		// Press '5' to set interval to 5.
		registerViewFixture();
		mount();
		act( () => {
			window.dispatchEvent(
				new KeyboardEvent( 'keydown', { key: '5', bubbles: true } )
			);
		} );
		expect(
			window.localStorage.getItem( 'event-logger-inflight-refresh' )
		).toBe( '5' );
	} );

	it( 'ignores keyboard shortcuts when focus is in an input', () => {
		registerViewFixture();
		mount();
		const input = document.createElement( 'input' );
		document.body.appendChild( input );
		const original = window.localStorage.getItem(
			'event-logger-inflight-refresh'
		);
		act( () => {
			const event = new KeyboardEvent( 'keydown', {
				key: '8',
				bubbles: true,
			} );
			Object.defineProperty( event, 'target', { value: input } );
			window.dispatchEvent( event );
		} );
		expect(
			window.localStorage.getItem( 'event-logger-inflight-refresh' )
		).toBe( original );
		input.remove();
	} );

	it( 'toggles the column picker on Cols button click', () => {
		registerViewFixture();
		const { container } = mount();
		const colsBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Cols' );
		expect( colsBtn ).toBeTruthy();
		act( () => {
			colsBtn.click();
		} );
		expect(
			container.querySelector( '.event-logger-inflight-column-picker' )
		).toBeTruthy();
		act( () => {
			colsBtn.click();
		} );
		expect(
			container.querySelector( '.event-logger-inflight-column-picker' )
		).toBeNull();
	} );

	it( 'toggles a column checkbox and persists to localStorage', () => {
		registerViewFixture();
		const { container } = mount();
		const colsBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Cols' );
		act( () => {
			colsBtn.click();
		} );
		// Toggle the 'age' column (not in default set) — should add it.
		const ageCheckbox = document.querySelector( '#inflight-col-age' );
		expect( ageCheckbox ).toBeTruthy();
		expect( ageCheckbox.checked ).toBe( false );
		act( () => {
			ageCheckbox.click();
		} );
		const saved = window.localStorage.getItem( 'event-logger-columns' );
		expect( JSON.parse( saved ) ).toEqual(
			expect.arrayContaining( [ 'age' ] )
		);
	} );
} );
