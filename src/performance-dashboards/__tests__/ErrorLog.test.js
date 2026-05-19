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
} );
