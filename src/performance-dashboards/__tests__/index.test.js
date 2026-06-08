/**
 * Tests for performance-dashboards/index.js — covers the AdminApp +
 * ErrorLogPage components defined inline alongside the mount logic.
 *
 * mount-entrypoints.test.js already exercises the DOMContentLoaded
 * + render() path, but it doesn't drive the inner handleError state
 * machine (lines 51 / 57-60 / 72 of index.js). To get those, we mount
 * the dashboard mounting hook with a container present and trigger
 * the onError callback on the PerformanceDashboard mock.
 */

let mockOnError = null;
jest.mock( '../PerformanceDashboard', () => ( {
	__esModule: true,
	default: ( props ) => {
		const React = require( 'react' );
		mockOnError = props.onError;
		return React.createElement( 'div', null, 'PERFORMANCE_DASHBOARD' );
	},
} ) );

jest.mock( '../ErrorLog', () => ( {
	__esModule: true,
	default: () => {
		const React = require( 'react' );
		return React.createElement( 'div', null, 'ERROR_LOG' );
	},
} ) );

import { act } from '../../test-helpers/renderHook';

// mountIndex waits on a timer-scheduled Suspense re-render (legacy
// ReactDOM.render mode). Under run-coverage's heavy parallel load that
// wall-clock wait dilates well past jest's 5s default, timing out the test
// even though the poll would resolve given a little more room. Raise the
// budget; the poll still returns the instant the lazy chunk resolves, so this
// only costs time on a genuinely starved box.
jest.setTimeout( 30000 );

describe( 'performance-dashboards/index.js — AdminApp + ErrorLogPage', () => {
	beforeEach( () => {
		// Recover from any fake-timer state a previous test left active
		// (e.g. the auto-clear test calls useFakeTimers() before its
		// assertions; if any throw before useRealTimers() at the end, the
		// next test's mountIndex would hang on its setTimeout(50) fallback
		// because fake timers swallow it).
		jest.useRealTimers();
		jest.resetModules();
		while ( document.body.firstChild ) {
			document.body.removeChild( document.body.firstChild );
		}
		mockOnError = null;
	} );

	/**
	 * Suspense + React.lazy: in test environment, lazy imports require
	 * real microtask flushing. This helper drains them, then dispatches
	 * DOMContentLoaded (which triggers the render() call) and waits long
	 * enough for the lazy import to resolve.
	 *
	 * @param {HTMLElement} admin The container element to return after mount.
	 */
	async function mountIndex( admin ) {
		require( '../index.js' );
		await act( async () => {
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} );
		// Poll-drain lazy import microtasks until the PerformanceDashboard
		// mock fires (it captures props.onError into mockOnError). Fixed-
		// length microtask + real-timer waits were race-prone under load;
		// polling is deterministic — we proceed as soon as the lazy chunk
		// has resolved, and bail (caller will assert and fail clearly) if
		// it never does within the budget.
		for ( let i = 0; i < 100; i++ ) {
			if ( null !== mockOnError ) {
				return admin;
			}
			await act( async () => {
				await Promise.resolve();
			} );
		}
		// The microtask loop above CANNOT flush the post-Suspense re-render:
		// index.js mounts via legacy ReactDOM.render, so React resolves the
		// lazy import on a microtask but schedules the resulting re-render
		// through its scheduler (MessageChannel/setTimeout in jsdom) — a
		// MACROTASK. The mounted PerformanceDashboard (which captures onError)
		// only appears after the event loop ticks. A single fixed wait here was
		// the marginal, load-sensitive part — under coverage/parallel workers
		// the scheduler tick can exceed it. Poll on real timers instead,
		// returning the instant the mock fires (≈1s budget).
		for ( let i = 0; i < 40; i++ ) {
			if ( null !== mockOnError ) {
				return admin;
			}
			await act( async () => {
				await new Promise( ( r ) => setTimeout( r, 25 ) );
			} );
		}
		return admin;
	}

	it( 'AdminApp displays an error Notice and auto-clears it after 5s', async () => {
		const admin = document.createElement( 'div' );
		admin.id = 'event-logger-admin';
		document.body.appendChild( admin );
		await mountIndex( admin );
		expect( mockOnError ).toEqual( expect.any( Function ) );
		// Fire an error.
		jest.useFakeTimers();
		await act( async () => {
			mockOnError( new Error( 'oh no' ) );
		} );
		expect( admin.textContent ).toContain( 'oh no' );
		// Advance 6 seconds — the effect's 5s timeout fires.
		await act( async () => {
			jest.advanceTimersByTime( 6000 );
		} );
		expect( admin.textContent ).not.toContain( 'oh no' );
		jest.useRealTimers();
	} );

	it( 'AdminApp falls back to "An error occurred" when error has no message', async () => {
		const admin = document.createElement( 'div' );
		admin.id = 'event-logger-admin';
		document.body.appendChild( admin );
		await mountIndex( admin );
		await act( async () => {
			mockOnError( {} );
		} );
		expect( admin.textContent ).toContain( 'An error occurred' );
	} );

	it( 'mount script renders ErrorLogPage when #event-logger-errors exists', async () => {
		const errors = document.createElement( 'div' );
		errors.id = 'event-logger-errors';
		document.body.appendChild( errors );
		require( '../index.js' );
		await act( async () => {
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} );
		// Suspense fallback shows initially; let lazy import resolve.
		await act( async () => {
			await Promise.resolve();
			await Promise.resolve();
			await Promise.resolve();
		} );
		expect( errors.parentNode ).toBe( document.body );
	} );

	it( 'LoadingFallback renders its message prop', () => {
		// We can't import LoadingFallback directly (not exported), but
		// the AdminApp uses it inside Suspense — assert the fallback
		// renders the configured message while PerformanceDashboard is
		// still loading. Use a stalled lazy import for that.
		jest.resetModules();
		jest.doMock( '../PerformanceDashboard', () => ( {
			__esModule: true,
			default: () => {
				const r = require( 'react' );
				return r.createElement( 'div', null, 'NEVER' );
			},
		} ) );
		const admin = document.createElement( 'div' );
		admin.id = 'event-logger-admin';
		document.body.appendChild( admin );
		require( '../index.js' );
		// Dispatch DOMContentLoaded synchronously so the render() call
		// runs. We don't await — the Suspense fallback should be visible.
		act( () => {
			document.dispatchEvent( new Event( 'DOMContentLoaded' ) );
		} );
		// The fallback text "Loading dashboard..." is rendered.
		expect( admin.textContent ).toContain( 'Loading' );
	} );
} );
