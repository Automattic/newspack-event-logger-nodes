/**
 * useMultiSelect — the selection state a selector modal runs on.
 *
 * Both selector modals kept their own copy of it, differing only in what they
 * were selecting. The list, its grouping and its chrome stay with each modal;
 * the state machine is here.
 */

import { useState, useEffect, useMemo } from '@wordpress/element';

/**
 * Track a modal's pending selection.
 *
 * Reopening resets to what was passed in, so an edit abandoned by closing the
 * modal does not survive into the next open.
 *
 * @param {Object}  [opts]          Options.
 * @param {Array}   [opts.selected] The selection the modal opened with.
 * @param {boolean} [opts.isOpen]   Whether the modal is open.
 * @return {{has: (v: string) => boolean, count: number, toggle: (v: string) => void,
 *   addAll: (vs: Array) => void, removeAll: (vs: Array) => void,
 *   replaceWith: (vs: Iterable) => void, clear: () => void,
 *   apply: (onSelect: Function, onClose: Function) => void}} Selection controls.
 */
export function useMultiSelect( { selected = [], isOpen = true } = {} ) {
	const [ chosen, setChosen ] = useState( () => new Set( selected ) );

	useEffect( () => {
		if ( isOpen ) {
			setChosen( new Set( selected ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ isOpen ] );

	return useMemo(
		() => ( {
			has: ( value ) => chosen.has( value ),
			count: chosen.size,
			toggle: ( value ) =>
				setChosen( ( prev ) => {
					const next = new Set( prev );
					if ( ! next.delete( value ) ) {
						next.add( value );
					}
					return next;
				} ),
			addAll: ( values ) =>
				setChosen( ( prev ) => new Set( [ ...prev, ...values ] ) ),
			removeAll: ( values ) =>
				setChosen( ( prev ) => {
					const next = new Set( prev );
					values.forEach( ( value ) => next.delete( value ) );
					return next;
				} ),
			replaceWith: ( values ) => setChosen( new Set( values ) ),
			clear: () => setChosen( new Set() ),
			apply: ( onSelect, onClose ) => {
				onSelect( Array.from( chosen ) );
				onClose();
			},
		} ),
		[ chosen ]
	);
}
