/**
 * Tests that the overview page roots (AdminApp + ErrorLogPage)
 * carry the `.newspack-nodes-theme` class so their var(--np-*) token
 * references resolve from the shared newspack-nodes-theme stylesheet.
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
jest.mock( '../overview/ErrorLog', () => ( {
	__esModule: true,
	default: () => 'ERROR_LOG',
} ) );

import * as React from 'react';
import { AdminApp, ErrorLogPage } from '../overview';
import { renderComponent, act } from '../test-helpers/renderHook';

const flushLazy = async () =>
	act( async () => {
		await Promise.resolve();
	} );

describe( 'overview theme root', () => {
	it( 'AdminApp root carries .newspack-nodes-theme', async () => {
		const { container, unmount } = renderComponent(
			React.createElement( AdminApp )
		);
		expect(
			container.querySelector(
				'.event-logger-admin-wrap.newspack-nodes-theme'
			)
		).not.toBeNull();
		await flushLazy();
		unmount();
	} );

	it( 'ErrorLogPage root carries .newspack-nodes-theme', async () => {
		const { container, unmount } = renderComponent(
			React.createElement( ErrorLogPage )
		);
		expect(
			container.querySelector( '.newspack-nodes-theme' )
		).not.toBeNull();
		await flushLazy();
		unmount();
	} );
} );
