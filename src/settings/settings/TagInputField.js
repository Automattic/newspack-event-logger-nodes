/**
 * The editor for a rule field holding an array of strings, drawn as one token
 * field: the tags and the box they are typed into share the substrate's
 * `newspack-nodes-token-field` frame, and pressing anywhere in it puts the box
 * in focus. Type a value and press Enter, or blur the box, to add it as a tag;
 * click a tag's remove button, or press Backspace on an empty box, to take one
 * away; Clear all, at the row's end, takes every tag away. Escape drops what
 * was typed. Blank and duplicate values add nothing, and the whole array is
 * reported through `onChange` after every change.
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import TokenInput from '../../components/TokenInput';
import '../styles/tag-input.scss';

/**
 * Tag Input Field component, one token per value. Any other prop, such as the
 * `id` a label names or `aria-describedby`, goes to the box.
 *
 * @param {Object}                     props                 Component props.
 * @param {string[]}                   [props.initialValues] Seeds the tag list at mount; later renders ignore it, so a caller showing a different list must remount the field.
 * @param {(values: string[]) => void} [props.onChange]      Fired with the values array after every change; an unstable identity re-fires it on every parent render.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function TagInputField( {
	initialValues = [],
	onChange,
	...boxProps
} ) {
	const [ values, setValues ] = useState( initialValues );

	// Skip the mount run so the initial render isn't reported as an edit.
	const didMountRef = useRef( false );

	useEffect( () => {
		if ( ! didMountRef.current ) {
			didMountRef.current = true;
			return;
		}
		if ( onChange ) {
			onChange( values );
		}
	}, [ values, onChange ] );

	/**
	 * Remove a value by index.
	 *
	 * @param {number} index Value index to remove.
	 */
	const removeValue = useCallback( ( index ) => {
		setValues( ( prev ) => prev.filter( ( _, i ) => i !== index ) );
	}, [] );

	/**
	 * Add a typed value as a tag; a duplicate adds nothing.
	 *
	 * @param {string} draft The trimmed typed value.
	 */
	const addValue = useCallback( ( draft ) => {
		setValues( ( prev ) =>
			prev.includes( draft ) ? prev : [ ...prev, draft ]
		);
	}, [] );

	const clearAll = useCallback( () => setValues( [] ), [] );

	/**
	 * Press on the frame's own space, not a tag or a button: the box takes
	 * focus, held so the press does not take it straight back.
	 *
	 * @param {import('react').MouseEvent<HTMLDivElement>} event The press.
	 */
	const focusBox = ( event ) => {
		if ( event.target === event.currentTarget ) {
			event.preventDefault();
			event.currentTarget.querySelector( 'input' )?.focus();
		}
	};

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions -- the box inside is the control; the frame only widens where a press lands.
		<div
			className="event-logger-tag-input newspack-nodes-token-field"
			onMouseDown={ focusBox }
		>
			<TokenInput
				tokens={ values }
				onRemove={ removeValue }
				value=""
				onCommit={ addValue }
				placeholder={ __(
					'Add a hook or event',
					'newspack-event-logger-nodes'
				) }
				{ ...boxProps }
				className="newspack-nodes-token-field__entry"
				onClear={ clearAll }
			/>
		</div>
	);
}
