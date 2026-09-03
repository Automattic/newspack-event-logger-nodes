/**
 * The editor for a rule field holding an array of strings. Type a value and
 * press Enter, or blur the input, to add it as a tag; click a tag's remove
 * button, or press Backspace on an empty input, to take one away. Blank and
 * duplicate values are refused, and the whole array is reported through
 * `onChange` after every change.
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { closeSmall } from '@wordpress/icons';
import '../styles/tag-input.scss';

/**
 * Tag Input Field component.
 *
 * A token carries `newspack-nodes-badge` beside its own class: the shared badge
 * paints it — background, radius and the inset ring — while `tag-input.scss`
 * contributes only geometry. Without that class a tag reads as bare text.
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
	const [ inputValue, setInputValue ] = useState( '' );

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
	 * Add the input's trimmed value as a tag. A blank leaves the input alone;
	 * a duplicate clears it without adding a second tag.
	 */
	const addValue = useCallback( () => {
		const trimmed = inputValue.trim();
		if ( ! trimmed ) {
			return;
		}
		setValues( ( prev ) =>
			prev.includes( trimmed ) ? prev : [ ...prev, trimmed ]
		);
		setInputValue( '' );
	}, [ inputValue ] );

	/**
	 * Add the typed value on Enter; on Backspace with the input already empty,
	 * drop the last tag. Backspace with text in the input edits that text.
	 *
	 * @param {import('react').KeyboardEvent} e Keyboard event.
	 */
	const handleKeyDown = useCallback(
		( e ) => {
			if ( 'Enter' === e.key ) {
				e.preventDefault();
				addValue();
				return;
			}
			if ( 'Backspace' === e.key && '' === inputValue ) {
				setValues( ( prev ) =>
					prev.length > 0 ? prev.slice( 0, -1 ) : prev
				);
			}
		},
		[ addValue, inputValue ]
	);

	const containerClass = `event-logger-tag-container ${
		horizontal ? 'horizontal' : 'vertical'
	}`;

	return (
		<div className="event-logger-tag-input">
			{ values.length > 0 && (
				<div className={ containerClass }>
					{ values.map( ( value, index ) => (
						<div
							key={ index }
							className="event-logger-tag-token newspack-nodes-badge"
						>
							<span className="event-logger-tag-text">
								{ value }
							</span>
							<Button
								icon={ closeSmall }
								iconSize={ 16 }
								onClick={ () => removeValue( index ) }
								label={ __(
									'Remove',
									'newspack-event-logger-nodes'
								) }
								className="event-logger-tag-remove"
							/>
						</div>
					) ) }
				</div>
			) }
			<div className="event-logger-tag-input-row">
				<input
					type="text"
					value={ inputValue }
					onChange={ ( e ) => setInputValue( e.target.value ) }
					onKeyDown={ handleKeyDown }
					onBlur={ addValue }
					placeholder={ __(
						'Type a value and press Enter…',
						'newspack-event-logger-nodes'
					) }
					className="regular-text"
				/>
			</div>
		</div>
	);
}
