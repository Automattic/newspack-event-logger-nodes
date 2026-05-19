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
} );
