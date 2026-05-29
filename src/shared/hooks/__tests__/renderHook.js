/**
 * Tiny renderHook helper — we don't pull in @testing-library/react-hooks
 * since it isn't a devDep. This is enough for our usage: mount a
 * component that invokes the hook into a real DOM node, expose the
 * last return value via a ref, and let the caller trigger re-renders
 * via `rerender()` and unmount via `unmount()`.
 */

// Tell React this is a concurrent-act test env to silence the
// "current testing environment is not configured to support act(...)"
// warning that fires from inside ReactDOMRoot.unmount otherwise.
global.IS_REACT_ACT_ENVIRONMENT = true;

// jsdom doesn't ship matchMedia; @wordpress/components uses it for
// responsive value selection. Stub a minimal MediaQueryList shim.
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
