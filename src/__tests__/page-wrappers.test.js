/**
 * Tests for the dashboard "Page" wrappers — GyroscopePage, RequestStreamPage.
 * They're identical thin shells: fixed-position dark div + the heavy dashboard
 * child, with useAdminMenuWidth controlling the left offset.
 *
 * Each child is mocked so we don't pay the SSE / d3 / canvas tax just
 * to assert the wrapper renders. (The Aggregator Status dashboard moved to the
 * newspack-nodes substrate as a DevTools hub tab; the hub supplies its page
 * chrome there, so there is no longer an ELN page wrapper for it.)
 */

jest.mock( '../gyroscope/Inflight', () => ( {
	__esModule: true,
	default: ( { maxRows } ) => `INFLIGHT[${ maxRows }]`,
} ) );
jest.mock( '../requests/RequestStream', () => ( {
	__esModule: true,
	default: ( { maxEntries } ) => `REQUEST_STREAM[${ maxEntries }]`,
} ) );

import * as React from 'react';
import GyroscopePage from '../gyroscope/GyroscopePage';
import RequestStreamPage from '../requests/RequestStreamPage';
import { renderComponent } from '../test-helpers/renderHook';

const TOKEN_ROOT_CLASSES = [
	'topology-app',
	'newspack-nodes-skin-root',
	'newspack-nodes-theme',
	'newspack-nodes-ui',
];

function expectNoTokenRoot( element ) {
	for ( const className of TOKEN_ROOT_CLASSES ) {
		expect( element.classList.contains( className ) ).toBe( false );
	}
}

describe( 'page wrappers', () => {
	it( 'GyroscopePage mounts <Inflight maxRows={100}>', () => {
		const { container, unmount } = renderComponent(
			React.createElement( GyroscopePage )
		);
		expect( container.textContent ).toContain( 'INFLIGHT[100]' );
		unmount();
	} );

	it( 'RequestStreamPage mounts <RequestStream maxEntries={1000}>', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestStreamPage )
		);
		expect( container.textContent ).toContain( 'REQUEST_STREAM[1000]' );
		unmount();
	} );

	it( 'GyroscopePage uses one exact skinned provider without repeating it on the page root', () => {
		const { container, unmount } = renderComponent(
			React.createElement( GyroscopePage )
		);
		const provider = container.firstElementChild;
		expect( provider.className ).toBe(
			'newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui'
		);
		expectNoTokenRoot( provider.firstElementChild );
		unmount();
	} );

	it( 'RequestStreamPage uses one exact skinned provider without repeating it on the page root', () => {
		const { container, unmount } = renderComponent(
			React.createElement( RequestStreamPage )
		);
		const provider = container.firstElementChild;
		expect( provider.className ).toBe(
			'newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui'
		);
		expect( provider.style.display ).toBe( 'contents' );
		expectNoTokenRoot( provider.firstElementChild );
		unmount();
	} );
} );
