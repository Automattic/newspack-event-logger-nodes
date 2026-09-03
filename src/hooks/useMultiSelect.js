/**
 * Pending-selection state for the settings selector modals.
 *
 * The hook picker and the custom-event picker differ only in what they list,
 * so the Set of chosen values, the reset on open and the apply that hands an
 * array back live here; the list, its grouping and the modal chrome stay with
 * each picker.
 */

import { useState, useEffect, useMemo } from '@wordpress/element';

/**
 * Track a modal's pending selection.
 *
 * Reopening resets to what was passed in, so an edit abandoned by closing the
 * modal does not survive into the next open. The controls memoize on the
 * selection, so the returned object takes a new identity whenever the
 * selection changes and a consumer's own `useMemo` may depend on it to
 * recount.
 *
 * @param {Object}  [opts]          Options.
 * @param {Array}   [opts.selected] The selection the modal opens with.
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
		// Depend on isOpen alone; a fresh `selected` wipes the pending edit.
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
