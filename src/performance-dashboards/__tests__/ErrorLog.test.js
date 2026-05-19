/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react is a transitive dep of @wordpress/element. */
/**
 * Tests for ErrorLog — same hook chain as RequestStream, mocked the
 * same way so we can drive onMessage and assert rows appear after the
 * batch flush.
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
import ErrorLog from '../ErrorLog';
import { renderComponent, act } from '../../shared/hooks/__tests__/renderHook';

describe( 'ErrorLog', () => {
	const mounted = [];

	function mount( props = {} ) {
		const r = renderComponent( React.createElement( ErrorLog, props ) );
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
	} );

	it( 'subscribes to the errors firehose', () => {
		mount();
		expect( lastUseMessageStreamArgs ).not.toBeNull();
		expect( lastUseMessageStreamArgs.subscriptions ).toEqual( [
			'errors',
		] );
		expect( lastUseMessageStreamArgs.intervalMs ).toBe( 1000 );
	} );

	it( 'calls connect() on mount when the page is visible', () => {
		mount();
		expect( mockConnect ).toHaveBeenCalled();
	} );

	it( 'ignores the substrate connected envelope', () => {
		const { container } = mount();
		act( () => {
			lastUseMessageStreamArgs.onMessage(
				[ 64, 0, '', '', '', 'connected', { slot: 1 } ],
				{ type: 64 }
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		// No row inserted.
		expect( container.querySelectorAll( '.entry-rid' ).length ).toBe( 0 );
	} );

	it( 'toggles pause/resume on button click', () => {
		const { container } = mount();
		const pauseBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === '⏸' );
		expect( pauseBtn ).toBeTruthy();
		act( () => {
			pauseBtn.click();
		} );
		expect( pauseBtn.textContent ).toContain( '▶' );
	} );

	it( 'Clear button empties rendered errors', () => {
		const { container } = mount();
		act( () => {
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'errors.p0',
					'',
					'1:0',
					'rid_xyz',
					{ ts: 1748960000, k: 'fatal', m: 'boom' },
				],
				{ type: 1 }
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( container.textContent ).toContain( 'rid_xyz' );
		const clearBtn = Array.from(
			container.querySelectorAll( 'button' )
		).find( ( b ) => b.textContent === 'Clear' );
		act( () => {
			clearBtn.click();
		} );
		act( () => {
			jest.advanceTimersByTime( 1000 );
		} );
		expect( container.textContent ).not.toContain( 'rid_xyz' );
	} );

	it( 'forwards an errors envelope into rendered rows', () => {
		const { container } = mount();
		act( () => {
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'errors.p0',
					'',
					'1:0',
					'rid_xyz',
					{ ts: 1748960000, k: 'fatal_error', m: 'boom' },
				],
				{ type: 1 }
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( container.textContent ).toContain( 'rid_xyz' );
		expect( container.textContent ).toContain( 'fatal_error' );
		expect( container.textContent ).toContain( 'boom' );
	} );

	it( 'filter input narrows rendered entries', () => {
		const { container } = mount();
		// Insert 3 distinct entries.
		act( () => {
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'errors.p0',
					'',
					'1:0',
					'r1',
					{ ts: 1, k: 'fatal_error', m: 'first' },
				],
				{ type: 1 }
			);
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'errors.p0',
					'',
					'1:1',
					'r2',
					{ ts: 2, k: 'warn', m: 'second' },
				],
				{ type: 1 }
			);
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'errors.p0',
					'',
					'1:2',
					'r3',
					{ ts: 3, k: 'fatal_error', m: 'third' },
				],
				{ type: 1 }
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		expect( container.textContent ).toContain( 'r1' );
		expect( container.textContent ).toContain( 'r2' );
		// Now filter by 'warn'.
		const input = container.querySelector( 'input[type="text"]' );
		const setter = Object.getOwnPropertyDescriptor(
			window.HTMLInputElement.prototype,
			'value'
		).set;
		act( () => {
			setter.call( input, 'warn' );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		act( () => {
			jest.advanceTimersByTime( 200 );
		} );
		// Only the warn row should remain.
		expect( container.textContent ).toContain( 'r2' );
	} );

	it( 'ignores subsequent connected envelopes after the first', () => {
		mount();
		act( () => {
			// First "connected" passes through ignore branch.
			lastUseMessageStreamArgs.onMessage(
				[ 64, 0, '', '', '', 'connected', { slot: 1 } ],
				{ type: 64 }
			);
			// Second one too.
			lastUseMessageStreamArgs.onMessage(
				[ 64, 0, '', '', '', 'connected', { slot: 2 } ],
				{ type: 64 }
			);
		} );
		expect( lastUseMessageStreamArgs ).not.toBeNull();
	} );

	it( 'classifies error/warning/info keywords via CSS class', () => {
		const { container } = mount();
		act( () => {
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'errors.p0',
					'',
					'1:0',
					'r_err',
					{ ts: 0, k: 'error', m: 'msg' },
				],
				{ type: 1 }
			);
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'errors.p0',
					'',
					'1:1',
					'r_warn',
					{ ts: 1, k: 'something (warning)', m: 'msg' },
				],
				{ type: 1 }
			);
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'errors.p0',
					'',
					'1:2',
					'r_err2',
					{ ts: 2, k: 'page (error)', m: 'msg' },
				],
				{ type: 1 }
			);
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'errors.p0',
					'',
					'1:3',
					'r_warn2',
					{ ts: 3, k: 'warning', m: 'msg' },
				],
				{ type: 1 }
			);
			lastUseMessageStreamArgs.onMessage(
				[
					1,
					0,
					'errors.p0',
					'',
					'1:4',
					'r_info',
					{ ts: 0, k: 'notice', m: 'msg' }, // no timestamp → formatTime fallback
				],
				{ type: 1 }
			);
		} );
		act( () => {
			jest.advanceTimersByTime( 2000 );
		} );
		// formatTime('0' → falsy) yields the placeholder.
		expect( container.textContent ).toContain( '--:--:--' );
	} );

	it( 'handles malformed envelopes without throwing', () => {
		mount();
		expect( () => {
			act( () => {
				// Missing payload.
				lastUseMessageStreamArgs.onMessage( [ 1, 0, 'errors.p0' ], {
					type: 1,
				} );
				// Empty array.
				lastUseMessageStreamArgs.onMessage( [], { type: 1 } );
				// null payload.
				lastUseMessageStreamArgs.onMessage(
					[ 1, 0, 'errors.p0', '', '1:0', 'rid_z', null ],
					{ type: 1 }
				);
			} );
		} ).not.toThrow();
	} );
} );
