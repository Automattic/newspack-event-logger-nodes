/* global KeyboardEvent */
/**
 * Inflight UI-surface tests — the thin view over the gyroscope node graph.
 *
 * The graph is owned by useGyroscopeGraph (tested separately); here we mock it so
 * the view never touches a real EventSource, and we register a fixture
 * `gyroscope:view` node in Core whose `snapshot()` returns the render rows and
 * whose `rps` the refresh tick reads. The view samples the node
 * on its refresh-interval timer (the gyroscope analog of RequestStream's rAF), so
 * tests advance fake timers to drive a render pass.
 */

jest.mock( '../hooks/useGyroscopeGraph', () => ( {
	useGyroscopeGraph: jest.fn(),
} ) );

import * as React from 'react';
import { Core, mountExospine } from '@newspack-nodes/runtime';
import Inflight from '../Inflight';
import { renderComponent, act } from '../../test-helpers/renderHook';

const { useGyroscopeGraph } = require( '../hooks/useGyroscopeGraph' );

// Stand-in for gyroscope:view: snapshot() returns render rows, rps on instance.
function registerViewFixture( {
	rows = [],
	rps = 0,
	connectionError = false,
} = {} ) {
	const node = {
		rps,
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

	let host;

	beforeEach( () => {
		Core.reset();
		useGyroscopeGraph.mockClear();
		useGyroscopeGraph.mockReturnValue( {} );
		jest.useFakeTimers();
		// The display refresh rides the Router TIMER; useGyroscopeGraph, which
		// brings that backbone up in production, is mocked out here.
		host = mountExospine( () => {} );
	} );

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
		host?.teardown();
		host = null;
		delete window.eventLoggerHookCategories;
		jest.useRealTimers();
		// Clear localStorage between tests so persisted state doesn't leak.
		window.localStorage.clear();
	} );

	// Advance the default 2s refresh so renderRequests samples the node. The
	// tick rides the substrate's wall-clock grid and the hook marks its window
	// started (the caller loads once itself), so the first sample is a full
	// interval out, counted from the next boundary — up to two of them.
	const tickRefresh = () => {
		act( () => {
			jest.advanceTimersByTime( 4000 );
		} );
	};

	it( 'renders the In-Flight Requests heading', () => {
		registerViewFixture();
		const { container } = mount();
		expect( container.textContent ).toContain( 'In-Flight Requests' );
		expect(
			container.querySelector( '.event-logger-inflight-header' ).className
		).toBe( 'event-logger-inflight-header newspack-nodes-inflight-header' );
	} );

	it( 'keeps the toolbar outside the canonical bordered rowgroup', () => {
		registerViewFixture();
		const { container } = mount();
		const root = container.querySelector( '.event-logger-inflight' );
		const toolbar = root.querySelector( '.newspack-nodes-toolbar' );
		const header = root.querySelector( '.newspack-nodes-table__header' );
		const rowgroup = root.querySelector(
			'.event-logger-request-stream-list'
		);

		expect( root.classList.contains( 'newspack-nodes-table' ) ).toBe(
			false
		);
		expect( toolbar.closest( '.newspack-nodes-table' ) ).toBeNull();
		expect( rowgroup.getAttribute( 'role' ) ).toBe( 'rowgroup' );
		expect( rowgroup.classList.contains( 'newspack-nodes-table' ) ).toBe(
			true
		);
		expect( rowgroup.previousElementSibling ).toBe( header );
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

	it( 'inks a pale state badge dark so the label stays legible', () => {
		window.eventLoggerHookCategories = {
			_colors: { Settings: '#CDDC39' },
			_patterns: { Settings: [ '^option_' ] },
		};
		registerViewFixture( {
			rows: [
				{
					rid: 'r-pale',
					url: '/x',
					state: 'option_home hook',
					what: 'x',
				},
			],
		} );
		const { container } = mount();
		tickRefresh();
		const badge = [
			...container.querySelectorAll( '.event-logger-state-badge' ),
		].find( ( el ) => 'option_home hook' === el.textContent );
		expect( badge.style.backgroundColor ).toBe( 'rgb(205, 220, 57)' );
		expect( badge.style.color ).toBe( 'rgb(30, 30, 30)' );
	} );

	it( 'displays the requests/second read from the node on a refresh tick', () => {
		registerViewFixture( {
			rows: [ { rid: 'r-rps', url: '/x', state: 'process' } ],
			rps: 4.2,
		} );
		const { container } = mount();
		tickRefresh();
		const rps = container.querySelector(
			'.newspack-nodes-toolbar-stats__rps'
		);
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
					status_code: 599,
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
			container.querySelector( '.newspack-nodes-select' ).value
		).toBe( '1' );
		expect( container.textContent ).toContain( 'r-columns' );
		expect( container.textContent ).toContain( 'template' );
		expect( container.textContent ).toContain( '203.0.113.10' );
		expect( container.textContent ).toContain( 'Jest Browser' );
		const status = container.querySelector( '.entry-status' );
		expect( status.textContent ).toBe( '599' );
		expect( status.dataset.status ).toBe( '599' );
		expect( status.className ).not.toContain( 'entry-status--' );
		expect( status.style.color ).toBe( '' );
	} );

	it( 'renders age and lag through the shared neutral and warning status tiers', () => {
		window.localStorage.setItem(
			'event-logger-columns',
			JSON.stringify( [ 'age', 'lag' ] )
		);
		const now = Date.now() / 1000;
		registerViewFixture( {
			rows: [
				{
					rid: 'warning-timing',
					last_log_ts: now - 6.41,
					lag_ms: 1283,
				},
				{
					rid: 'neutral-timing',
					last_log_ts: now - 0.64,
					lag_ms: 283,
				},
			],
		} );
		const { container } = mount();
		tickRefresh();
		const rows = container.querySelectorAll(
			'.event-logger-request-stream-entry'
		);
		const warningCells = rows[ 0 ].querySelectorAll( '[role="cell"]' );
		const neutralCells = rows[ 1 ].querySelectorAll( '[role="cell"]' );

		for ( const cell of warningCells ) {
			expect( cell.classList.contains( 'newspack-nodes-status' ) ).toBe(
				true
			);
			expect( cell.classList.contains( 'is-warning' ) ).toBe( true );
			expect( cell.className ).not.toContain( 'entry-timing--' );
		}
		for ( const cell of neutralCells ) {
			expect( cell.classList.contains( 'newspack-nodes-status' ) ).toBe(
				true
			);
			expect( cell.classList.contains( 'is-warning' ) ).toBe( false );
			expect( cell.className ).not.toContain( 'entry-timing--' );
		}
	} );

	it( 'does not render stream staleness in the toolbar', () => {
		registerViewFixture( { rps: 7.3 } );
		Core.nodes.set( 'gyroscope:link', {
			lastEventTime: () => Date.now() - 37_000,
		} );
		const { container } = mount();
		tickRefresh();
		const toolbar = container.querySelector( '.newspack-nodes-toolbar' );
		expect( toolbar.textContent ).toMatch( /7\.3 req\/s/ );
		expect( toolbar.textContent ).not.toMatch( /\d+s ago/ );
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
			container.querySelector( '.newspack-nodes-column-picker' )
		).toBeTruthy();
		act( () => {
			colsBtn.click();
		} );
		expect(
			container.querySelector( '.newspack-nodes-column-picker' )
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
