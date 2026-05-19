/* eslint-disable import/no-extraneous-dependencies, import/no-unresolved -- react/react-dom are transitive deps of @wordpress/element; we import them directly here only for the test renderer (createRoot, act). Linting can't see them because they're not listed in package.json, but they're guaranteed present alongside @wordpress/element. */
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

import * as React from 'react';
import { createRoot } from 'react-dom/client';
import { act } from 'react';

export { act };

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
