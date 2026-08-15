/**
 * Tiny renderHook helper — we don't pull in @testing-library/react-hooks
 * since it isn't a devDep. This is enough for our usage: mount a
 * component that invokes the hook into a real DOM node, expose the
 * last return value via a ref, and let the caller trigger re-renders
 * via `rerender()` and unmount via `unmount()`.
 */

/* eslint-env jest */

// Mark a concurrent-act test env to silence the act() unmount warning.
global.IS_REACT_ACT_ENVIRONMENT = true;

// jsdom lacks matchMedia (@wordpress/components needs it); stub a shim.
if ( typeof window !== 'undefined' && ! window.matchMedia ) {
	window.matchMedia = ( query ) => ( {
		matches: false,
		media: query,
		onchange: null,
		addListener: () => {},
		removeListener: () => {},
		addEventListener: () => {},
		removeEventListener: () => {},
		dispatchEvent: () => false,
	} );
}

import * as React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

export { act };

// Every root this module mounts, so a suite can tear them all down.
const mountedRoots = [];

/**
 * Unmount everything `renderHook` mounted. A suite whose components own graph
 * nodes calls this in its own afterEach; a test may still unmount its own.
 *
 * @return {void}
 */
export function cleanupMounts() {
	while ( mountedRoots.length ) {
		const { root, container } = mountedRoots.pop();
		act( () => root.unmount() );
		container.remove();
	}
}

/**
 * Mount a React element into a real DOM container under jsdom. Returns
 * the container so callers can run queries directly (querySelector,
 * textContent, dispatchEvent, etc.). No @testing-library is needed —
 * assertions on rendered output use plain DOM APIs.
 *
 * @param {import('react').ReactElement} element React tree to mount.
 * @return {{ container: HTMLDivElement, rerender: Function, unmount: Function }} Container plus rerender + unmount helpers.
 */
export function renderComponent( element ) {
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );
	act( () => {
		root.render( element );
	} );
	return {
		container,
		rerender: ( next ) => {
			act( () => {
				root.render( next );
			} );
		},
		unmount: () => {
			act( () => {
				root.unmount();
			} );
			container.remove();
		},
	};
}

/**
 * Run a hook inside a real React render and hand back its return value.
 *
 * Mounts a throwaway Wrapper that renders null and does nothing but call
 * `useHook( props )`, so effects, refs, and state behave exactly as they would
 * in a component. Every render reassigns `result.current`, so read it fresh
 * after each `act` — destructuring it once captures the FIRST render's value
 * and silently goes stale. `rerender` replaces the props wholesale rather than
 * merging, and most call sites here ignore props entirely, passing
 * `() => useSomething()`.
 *
 * @param {(props: Object) => *} useHook                Invoked on every render; its
 *                                                      return value becomes
 *                                                      `result.current`.
 * @param {Object}               [options]              Mount options.
 * @param {Object}               [options.initialProps] Props for the first
 *                                                      render.
 * @return {{ result: { current: * }, rerender: (nextProps: Object) => void,
 *   unmount: () => void }} `result` holds the latest return value; `rerender`
 *   re-runs the hook with new props; `unmount` tears down the root and removes
 *   the container from the document. Both are already wrapped in `act`.
 */
export function renderHook( useHook, { initialProps = {} } = {} ) {
	const result = { current: undefined };
	let setProps;
	const container = document.createElement( 'div' );
	document.body.appendChild( container );
	const root = createRoot( container );

	function Wrapper() {
		const [ props, _setProps ] = React.useState( initialProps );
		setProps = _setProps;
		result.current = useHook( props );
		return null;
	}

	act( () => {
		root.render( React.createElement( Wrapper ) );
	} );
	mountedRoots.push( { root, container } );

	return {
		result,
		rerender: ( nextProps ) => {
			act( () => {
				setProps( nextProps );
			} );
		},
		unmount: () => {
			act( () => {
				root.unmount();
			} );
			container.remove();
		},
	};
}

/**
 * Poll an assertion until it passes, flushing React work between attempts.
 *
 * Commands ride the router tick now, so most things a test waits for are a
 * second away rather than a microtask. This is @testing-library's `waitFor`
 * in miniature, which this package has no dependency on.
 *
 * @param {Function} check              Throws until the condition holds.
 * @param {Object}   [options]
 * @param {number}   [options.timeout]  Give up after this many ms (default 6000).
 * @param {number}   [options.interval] Time between attempts (default 50).
 * @return {Promise<void>} Resolves once `check` passes; rejects with its last throw.
 */
export async function waitFor( check, { timeout = 6000, interval = 50 } = {} ) {
	const deadline = Date.now() + timeout;
	let lastError;
	for (;;) {
		try {
			check();
			return;
		} catch ( e ) {
			lastError = e;
		}
		if ( Date.now() >= deadline ) {
			throw lastError;
		}
		// eslint-disable-next-line no-await-in-loop
		await act(
			async () =>
				await new Promise( ( resolve ) =>
					setTimeout( resolve, interval )
				)
		);
	}
}

/**
 * Start an awaited graph verb and settle it.
 *
 * The send rides the router tick, so the promise must be STARTED inside act
 * and awaited outside one: awaiting inside act deadlocks, because act will not
 * let the timers that carry the tick run while it waits. The polling happens
 * through `waitFor`, so the reply's render is absorbed too.
 *
 * @param {Function} start       Returns the promise to settle.
 * @param {Object}   [o]
 * @param {number}   [o.timeout] Give up after this many ms.
 * @return {Promise<*>} The resolved value, or the rejection as a value.
 */
export async function settleCommand( start, { timeout = 8000 } = {} ) {
	let outcome;
	let done = false;
	act( () => {
		start().then(
			( value ) => {
				outcome = value;
				done = true;
			},
			( error ) => {
				outcome = error;
				done = true;
			}
		);
	} );
	await waitFor(
		() => {
			if ( ! done ) {
				throw new Error( 'command has not settled yet' );
			}
		},
		{ timeout }
	);
	return outcome;
}

/**
 * Whether an awaited graph verb settles within a window.
 *
 * After a teardown there is no graph to send on, so the promise stays pending
 * — which is what "the deep-link intent is held" means, and the opposite of
 * resolving to null.
 *
 * @param {Function} start      Returns the promise to watch.
 * @param {Object}   [o]
 * @param {number}   [o.within] How long to watch, in ms.
 * @return {Promise<boolean>} True if it settled inside the window.
 */
export async function settledWithin( start, { within = 2500 } = {} ) {
	let settled = false;
	act( () => {
		start().then(
			() => ( settled = true ),
			() => ( settled = true )
		);
	} );
	await act(
		async () =>
			await new Promise( ( resolve ) => setTimeout( resolve, within ) )
	);
	return settled;
}
