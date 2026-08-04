/**
 * Tiny renderHook helper — we don't pull in @testing-library/react-hooks
 * since it isn't a devDep. This is enough for our usage: mount a
 * component that invokes the hook into a real DOM node, expose the
 * last return value via a ref, and let the caller trigger re-renders
 * via `rerender()` and unmount via `unmount()`.
 */

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
