/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/* global KeyboardEvent */
/**
 * Tests for Inflight — heavy hook chain (usePageVisibility,
 * useMessageStream) is mocked so we can drive renderRequests
 * deterministically and assert rendering behaviour.
 */

const mockConnect = jest.fn();
const mockClose = jest.fn();
let lastUseMessageStreamArgs = null;

jest.mock( '../../shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => true,
} ) );
jest.mock( '../../shared/hooks/useMessageStream', () => ( {
	__esModule: true,
	default: ( args ) => {
		lastUseMessageStreamArgs = args;
		return {
			error: null,
			connect: mockConnect,
			close: mockClose,
			lastEventTime: null,
		};
	},
} ) );

// transformGyroscopeLine is a tiny pure helper — keep it real (already
// covered) and let the test drive the onMessage handler from above.

import * as React from 'react';
import Inflight from '../Inflight';
import { renderComponent, act } from '../../shared/hooks/__tests__/renderHook';

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
		mockConnect.mockReset();
		mockClose.mockReset();
		lastUseMessageStreamArgs = null;
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

	it( 'renders the In-Flight Requests heading', () => {
		const { container } = mount();
		expect( container.textContent ).toContain( 'In-Flight Requests' );
	} );

	it( 'subscribes to gyroscope.log via useMessageStream', () => {
		mount();
		expect( lastUseMessageStreamArgs ).not.toBeNull();
		expect( lastUseMessageStreamArgs.subscriptions ).toEqual( [
			'gyroscope',
		] );
		expect( lastUseMessageStreamArgs.intervalMs ).toBe( 100 );
	} );

	it( 'calls connect() when the page is visible', () => {
		mount();
		expect( mockConnect ).toHaveBeenCalled();
	} );

	it( 'persists the refresh interval via localStorage', () => {
		// Press '5' to set interval to 5.
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

	it( 'renders rows after the onMessage handler delivers an inflight snapshot', () => {
		const { container } = mount();
		// Drive the registered onMessage handler with a synthesised
		// gyroscope.log line. transformGyroscopeLine reads from the
		// Message envelope [TYPE,_,FROM,_,_,KEY,VALUE] where VALUE is a
		// JSON-encoded `{ inflight: [...] }`.
		act( () => {
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'gyroscope.p0',
					'',
					'1:0',
					'inflight',
					[
						{
							rid: 'r1',
							url: '/foo',
							method: 'GET',
							state: 'process',
							what: 'foo',
						},
					],
				],
				{ type: 1 }
			);
		} );
		// Advance one tick of the display refresh interval (default 2s).
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( container.textContent ).toContain( 'r1' );
	} );
} );
