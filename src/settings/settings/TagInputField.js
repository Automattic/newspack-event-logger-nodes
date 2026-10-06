/**
 * The editor for a rule field holding an array of strings. Type a value and
 * press Enter, or blur the input, to add it as a tag; click a tag's remove
 * button, or press Backspace on an empty input, to take one away. Escape
 * drops what was typed. Blank and duplicate values add nothing, and the whole
 * array is reported through `onChange` after every change.
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import TokenInput from '../../components/TokenInput';
import '../styles/tag-input.scss';

/**
 * Tag Input Field component, one token per value.
 *
 * @param {Object}                     props                 Component props.
 * @param {string[]}                   [props.initialValues] Seeds the tag list at mount; later renders ignore it, so a caller showing a different list must remount the field.
 * @param {boolean}                    [props.horizontal]    If true, tags flow horizontally (for short values).
 * @param {(values: string[]) => void} [props.onChange]      Fired with the values array after every change; an unstable identity re-fires it on every parent render.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function TagInputField( {
	initialValues = [],
	horizontal = false,
	onChange,
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

	const containerClass = `event-logger-tag-container ${
		horizontal ? 'horizontal' : 'vertical'
	}`;

	return (
		<div className="event-logger-tag-input">
			<TokenInput
				tokens={ values }
				onRemove={ removeValue }
				rowClassName={ containerClass }
				value=""
				onCommit={ addValue }
				placeholder={ __(
					'Type a value and press Enter…',
					'newspack-event-logger-nodes'
				) }
				className="regular-text"
			/>
		</div>
	);
}
