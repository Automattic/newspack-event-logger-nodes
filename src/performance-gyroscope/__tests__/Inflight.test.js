/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/* global KeyboardEvent */
/**
 * Inflight UI-surface tests — the thin view over the gyroscope node graph.
 *
 * The graph is owned by useGyroscopeGraph (tested separately); here we mock it so
 * the view never touches a real EventSource, and we register a fixture
 * `gyroscope/view` node in Core whose `snapshot()` returns the render rows and
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
import { renderComponent, act } from '../../shared/hooks/__tests__/renderHook';

const { useGyroscopeGraph } = require( '../hooks/useGyroscopeGraph' );

// A minimal stand-in for the gyroscope/view node: snapshot() returns the render
// rows (the real node reaps + sorts + caps here), and rps / lastEventTime live on
// the instance — exactly what the refresh tick reads off Core.node('gyroscope/view').
function registerViewFixture( {
	rows = [],
	rps = 0,
	lastEventTime = null,
} = {} ) {
	const node = {
		rps,
		lastEventTime,
		snapshot: jest.fn( () => rows ),
	};
	Core.nodes.set( 'gyroscope/view', node );
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
