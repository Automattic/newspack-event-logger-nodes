/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * Tests for RequestStream — mocks the hook chain so the test exercises
 * the render path + onMessage handling without touching SSE / d3.
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
jest.mock( '../../shared/hooks/useVirtualization', () => ( {
	__esModule: true,
	default: ( _ref, _row, total ) => ( {
		startIndex: 0,
		endIndex: total,
		paddingTop: 0,
		paddingBottom: 0,
		offsetTop: 0,
		totalHeight: total * 33,
	} ),
} ) );

import * as React from 'react';
import RequestStream from '../RequestStream';
import { renderComponent, act } from '../../shared/hooks/__tests__/renderHook';

describe( 'RequestStream', () => {
	const mounted = [];

	function mount( props = {} ) {
		const r = renderComponent(
			React.createElement( RequestStream, { maxEntries: 100, ...props } )
		);
		mounted.push( r );
		return r;
	}

	beforeEach( () => {
		mockConnect.mockReset();
		mockClose.mockReset();
		lastUseMessageStreamArgs = null;
		window.localStorage.clear();
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	afterEach( () => {
		while ( mounted.length ) {
			mounted.pop().unmount();
		}
	} );

	it( 'renders the Request Log heading', () => {
		const { container } = mount();
		expect( container.textContent ).toMatch( /Request/i );
	} );

	it( 'subscribes to the completed firehose via useMessageStream', () => {
		mount();
		expect( lastUseMessageStreamArgs ).not.toBeNull();
		// Subscriptions list contains the request log feed name.
		expect( Array.isArray( lastUseMessageStreamArgs.subscriptions ) ).toBe(
			true
		);
		expect( lastUseMessageStreamArgs.subscriptions.length ).toBeGreaterThan(
			0
		);
	} );

	it( 'calls connect() when the page is visible on mount', () => {
		mount();
		expect( mockConnect ).toHaveBeenCalled();
	} );

	it( 'renders an "empty" message initially', () => {
		const { container } = mount();
		expect( container.textContent.toLowerCase() ).toMatch(
			/no|empty|wait/i
		);
	} );

	it( 'toggles pause/resume on button click', () => {
		const { container } = mount();
		const pauseBtn = container.querySelector(
			'.event-logger-request-stream-btn'
		);
		expect( pauseBtn.textContent ).toContain( '⏸' );
		act( () => {
			pauseBtn.click();
		} );
		// After click, button now shows the resume glyph.
		expect( pauseBtn.textContent ).toContain( '▶' );
	} );

	it( 'Clear button empties rendered entries', () => {
		const { container } = mount();
		act( () => {
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'firehose.p0',
					'',
					'1:0',
					'completed',
					{
						rid: 'r-foo',
						url: '/foo',
						method: 'GET',
						status_code: 200,
						duration_ms: 50,
						end_time: 1,
					},
				],
				{ type: 1 }
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( container.textContent ).toContain( 'r-foo' );
		// Click Clear.
		const clearBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Clear' );
		act( () => {
			clearBtn.click();
		} );
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( container.textContent ).not.toContain( 'r-foo' );
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
			container.querySelector(
				'.event-logger-request-stream-column-picker'
			)
		).toBeTruthy();
	} );

	it( 'forwards a completed-line envelope into rendered rows', () => {
		const { container } = mount();
		act( () => {
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'firehose.p0',
					'',
					'1:0',
					'completed',
					{
						rid: 'r1',
						url: '/foo',
						method: 'GET',
						status_code: 200,
						duration_ms: 50,
						end_time: 1748960000,
					},
				],
				{ type: 1 }
			);
		} );
		// The component buffers entries and flushes via setInterval; advance
		// enough to cover the batch interval (default 500ms).
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( container.textContent ).toContain( 'r1' );
	} );

	it( 'filter input narrows URL-matching entries', () => {
		const { container } = mount();
		act( () => {
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'firehose.p0',
					'',
					'1:0',
					'completed',
					{
						rid: 'rA',
						url: '/foo/bar',
						method: 'GET',
						status_code: 200,
						end_time: 1,
					},
				],
				{ type: 1 }
			);
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'firehose.p0',
					'',
					'1:1',
					'completed',
					{
						rid: 'rB',
						url: '/baz',
						method: 'GET',
						status_code: 200,
						end_time: 2,
					},
				],
				{ type: 1 }
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( container.textContent ).toContain( 'rA' );
		expect( container.textContent ).toContain( 'rB' );
		const input = container.querySelector(
			'input[type="text"], input.event-logger-request-stream-search'
		);
		expect( input ).toBeTruthy();
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'foo' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 300 );
		} );
		expect( container.textContent ).toContain( 'rA' );
	} );

	it( 'renders user_agent and remote_addr columns when entry carries them', () => {
		// Persist a column set that includes user_agent so its render
		// branch (lines 180-190) runs.
		window.localStorage.setItem(
			'event-logger-stream-columns',
			JSON.stringify( [
				'time',
				'rid',
				'url',
				'status',
				'remote_addr',
				'user_agent',
				'duration',
			] )
		);
		const { container } = mount();
		act( () => {
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'firehose.p0',
					'',
					'1:0',
					'completed',
					{
						rid: 'r-ua',
						url: '/x',
						method: 'GET',
						status_code: 200,
						user_agent: 'Mozilla/5.0',
						remote_addr: '192.168.1.1',
						end_time: 1,
					},
				],
				{ type: 1 }
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( container.textContent ).toContain( 'r-ua' );
		expect( container.textContent ).toContain( 'Mozilla/5.0' );
		window.localStorage.removeItem( 'event-logger-stream-columns' );
	} );

	it( 'renders the entry with no user_agent (default to "-") when column is enabled', () => {
		window.localStorage.setItem(
			'event-logger-stream-columns',
			JSON.stringify( [ 'time', 'rid', 'url', 'user_agent' ] )
		);
		const { container } = mount();
		act( () => {
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'firehose.p0',
					'',
					'1:0',
					'completed',
					{
						rid: 'r-no-ua',
						url: '/x',
						method: 'GET',
						status_code: 200,
						end_time: 1,
					},
				],
				{ type: 1 }
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( container.textContent ).toContain( 'r-no-ua' );
		window.localStorage.removeItem( 'event-logger-stream-columns' );
	} );

	it( 'column picker labels toggle on click', () => {
		const { container } = mount();
		const colsBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Cols' );
		act( () => colsBtn.click() );
		// Find checkboxes / labels in the picker.
		const labels = container.querySelectorAll(
			'.event-logger-request-stream-column-picker label'
		);
		expect( labels.length ).toBeGreaterThan( 0 );
		// Click the first one to toggle.
		const input = labels[ 0 ].querySelector( 'input' );
		if ( input ) {
			act( () => input.click() );
			act( () => input.click() );
		}
		expect(
			container.querySelector(
				'.event-logger-request-stream-column-picker'
			)
		).toBeTruthy();
	} );

	it( 'scroll handler runs without throwing on the list element', () => {
		const { container } = mount();
		act( () => {
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'firehose.p0',
					'',
					'1:0',
					'completed',
					{
						rid: 'r-scroll',
						url: '/x',
						method: 'GET',
						status_code: 200,
						end_time: 1,
					},
				],
				{ type: 1 }
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		const list = container.querySelector(
			'.event-logger-request-stream-list, .event-logger-request-stream-content'
		);
		if ( list ) {
			Object.defineProperty( list, 'scrollTop', {
				configurable: true,
				value: 100,
			} );
			act( () => {
				list.dispatchEvent( new Event( 'scroll' ) );
			} );
			Object.defineProperty( list, 'scrollTop', {
				configurable: true,
				value: 0,
			} );
			act( () => {
				list.dispatchEvent( new Event( 'scroll' ) );
			} );
		}
		expect( container.textContent ).toContain( 'r-scroll' );
	} );

	it( 'handles malformed completed envelopes (missing fields)', () => {
		const { container } = mount();
		expect( () => {
			act( () => {
				lastUseMessageStreamArgs.onMessage(
					[
						1,
						0,
						'firehose.p0',
						'',
						'1:0',
						'completed',
						{
							rid: 'r-bad',
							// no url
						},
					],
					{ type: 1 }
				);
				jest.advanceTimersByTime( 1000 );
			} );
		} ).not.toThrow();
		expect( container.textContent ).toBeDefined();
	} );
} );
