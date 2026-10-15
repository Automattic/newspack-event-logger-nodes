/**
 * useOnChange — an effect that answers a change of its deps, never the mount
 * that first set them: the Performance graph's pokes, which a mount's first
 * poll already asks.
 */

import { useEffect, useRef } from '@wordpress/element';

/**
 * Run `effect` after each render whose `deps` differ from the render before,
 * skipping the first.
 *
 * @param {() => void} effect What a change does.
 * @param {Array}      deps   What it answers a change of.
 */
export function useOnChange( effect, deps ) {
	const mounted = useRef( false );
	useEffect( () => {
		if ( ! mounted.current ) {
			mounted.current = true;
			return;
		}
		effect();
		// eslint-disable-next-line react-hooks/exhaustive-deps -- the caller's deps are the contract
	}, deps );
}
