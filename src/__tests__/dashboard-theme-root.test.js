/**
 * Tests that the dashboard page roots (overview's AdminApp + the error log's
 * ErrorLogPage) sit below one exact skinned non-graph provider. Child roots
 * must not repeat token-provider classes.
 *
 * Both roots render a lazy-loaded child; the assertion is on the
 * synchronously-rendered wrapper, then we flush the pending lazy resolution
 * inside act() (before unmount) so its Suspense settle doesn't trip the
 * "not wrapped in act" warning the shared jest.setup escalates to a failure.
 */

jest.mock( '../overview/PerformanceDashboard', () => ( {
	__esModule: true,
	default: () => 'PERFORMANCE_DASHBOARD',
} ) );
jest.mock( '../error-log/ErrorLog', () => ( {
	__esModule: true,
	default: () => 'ERROR_LOG',
} ) );

import * as React from 'react';
import { AdminApp } from '../overview';
import { ErrorLogPage } from '../error-log';
import { renderComponent, act } from '../test-helpers/renderHook';

const flushLazy = async () =>
	act( async () => {
		await Promise.resolve();
	} );

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

describe( 'overview theme root', () => {
	it( 'AdminApp has one exact skinned provider and no repeated child provider', async () => {
		const { container, unmount } = renderComponent(
			React.createElement( AdminApp )
		);
		const provider = container.firstElementChild;
		const page = provider.querySelector( '.event-logger-admin-wrap' );
		expect( provider.className ).toBe(
			'newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui'
		);
		expect( page ).not.toBeNull();
		expect( page.className ).toBe(
			'event-logger-admin-wrap newspack-nodes-admin-wrap'
		);
		expect(
			page.querySelector( '.event-logger-admin-app' ).className
		).toBe( 'event-logger-admin-app newspack-nodes-admin-app' );
		expectNoTokenRoot( page );
		await flushLazy();
		unmount();
	} );

	it( 'ErrorLogPage has one exact skinned provider and no repeated child provider', async () => {
		const { container, unmount } = renderComponent(
			React.createElement( ErrorLogPage )
		);
		const provider = container.firstElementChild;
		expect( provider.className ).toBe(
			'newspack-nodes-skin-root newspack-nodes-theme newspack-nodes-ui'
		);
		expect( provider.style.display ).toBe( 'contents' );
		expectNoTokenRoot( provider.firstElementChild );
		await flushLazy();
		unmount();
	} );
} );
