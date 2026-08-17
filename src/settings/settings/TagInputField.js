/**
 * Tag Input Field Component
 *
 * A controlled multi-value input for arrays of strings. Type a value and press
 * Enter (or blur) to add it as a tag; Backspace on an empty input removes the
 * last one. Duplicates and blank values are refused.
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { closeSmall } from '@wordpress/icons';
import '../styles/tag-input.scss';

/**
 * Tag Input Field component.
 *
 * @param {Object}                     props                 Component props.
 * @param {Array}                      [props.initialValues] Initial values array; defaults to empty.
 * @param {boolean}                    [props.horizontal]    If true, tags flow horizontally (for short values).
 * @param {(values: string[]) => void} [props.onChange]      Fired with the values array on change.
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
	 * Add the input's value as a tag, trimmed; a blank or duplicate is a no-op.
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
	 * Handle key down in input.
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
