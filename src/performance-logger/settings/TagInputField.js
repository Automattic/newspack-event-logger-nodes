/**
 * Tag Input Field Component
 *
 * A custom multi-value input for managing arrays of strings (URLs, events, etc.).
 * Type a value and press Enter to add it as a tag.
 * Tokens stack vertically to accommodate long values.
 *
 * When showHookSelector is true, displays a simplified view with just
 * the hook count and a "Select Hooks" button (modal is source of truth).
 */

import {
	useState,
	useEffect,
	useMemo,
	useCallback,
	useRef,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { closeSmall } from '@wordpress/icons';
import HookSelectorModal from './HookSelectorModal';
import CustomEventSelectorModal from './CustomEventSelectorModal';

/**
 * Tag Input Field component.
 *
 * @param {Object}  props                    Component props.
 * @param {string}  props.fieldName          The field name (used for hidden input ID).
 * @param {Array}   props.initialValues      Initial values array.
 * @param {Array}   props.defaultValues      Default values for reset.
 * @param {boolean} props.horizontal         If true, tags flow horizontally (for short values).
 * @param {boolean} props.showHookSelector   If true, show hook selector button (for events fields).
 * @param {string}  props.hookSelectorMode   'include' or 'exclude' for hook selector.
 * @param {boolean} props.showCustomSelector If true, show custom event selector button.
 * @return {import('react').ReactElement} Rendered component.
 */
export default function TagInputField( {
	fieldName,
	initialValues = [],
	defaultValues = [],
	horizontal = false,
	showHookSelector = false,
	hookSelectorMode = 'exclude',
	showCustomSelector = false,
} ) {
	const [ values, setValues ] = useState( initialValues );
	const [ inputValue, setInputValue ] = useState( '' );
	const [ isModalOpen, setIsModalOpen ] = useState( false );

	// Track which values are defaults (for styling).
	const defaultSet = useMemo(
		() => new Set( defaultValues ),
		[ defaultValues ]
	);

	// Skips the mount run of the value-sync effect so the initial render can't
	// be mistaken for an edit (and drop a pending reset mark).
	const didMountRef = useRef( false );

	// Update the hidden carrier when values change. On a real edit (not the
	// initial mount), also drop any pending per-field reset mark: the shared
	// admin-field-reset module only auto-drops marks for non-hidden controls,
	// and this field's only input is the hidden carrier it ignores — so without
	// this, marking for reset then editing would leave the marker, and
	// Reset_Gate would delete the option on Save, discarding the edit.
	useEffect( () => {
		const hiddenInput = document.getElementById( `${ fieldName }_json` );
		if ( hiddenInput ) {
			hiddenInput.value = JSON.stringify( values );
		}
		if ( ! didMountRef.current ) {
			didMountRef.current = true;
			return;
		}
		const wrapper = hiddenInput?.closest( '[data-nn-reset]' );
		if ( wrapper ) {
			wrapper.classList.remove( 'is-marked' );
			wrapper.querySelector( '[data-nn-reset-marker]' )?.remove();
		}
	}, [ values, fieldName ] );

	/**
	 * Remove a value by index.
	 *
	 * @param {number} index Value index to remove.
	 */
	const removeValue = useCallback(
		( index ) => {
			setValues( ( prev ) => prev.filter( ( _, i ) => i !== index ) );
		},
		[ setValues ]
	);

	/**
	 * Add a new value from the input field.
	 * Trims whitespace and prevents duplicate values.
	 */
	const addValue = useCallback( () => {
		const trimmed = inputValue.trim();
		if ( trimmed ) {
			setValues( ( prev ) => {
				if ( prev.includes( trimmed ) ) {
					return prev;
				}
				return [ ...prev, trimmed ];
			} );
			setInputValue( '' );
		}
	}, [ inputValue, setValues ] );

	/**
	 * Handle key down in input.
	 *
	 * @param {import('react').KeyboardEvent} e Keyboard event.
	 */
	const handleKeyDown = useCallback(
		( e ) => {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				addValue();
			} else if ( e.key === 'Backspace' && inputValue === '' ) {
				// Remove last value on backspace when input is empty.
				setValues( ( prev ) => {
					if ( prev.length > 0 ) {
						return prev.slice( 0, -1 );
					}
					return prev;
				} );
			}
		},
		[ addValue, inputValue, setValues ]
	);

	/**
	 * Handle hook selection from modal - replaces all values.
	 *
	 * @param {Array} selectedHooks Selected hooks from modal.
	 */
	const handleHookSelect = useCallback( ( selectedHooks ) => {
		setValues( selectedHooks );
	}, [] );

	// Container class based on layout mode.
	const containerClass = `event-logger-tag-container ${
		horizontal ? 'horizontal' : 'vertical'
	}`;

	// Hook selector mode: simplified UI with just count and button.
	if ( showHookSelector ) {
		return (
			<div className="event-logger-tag-input">
				<div className="event-logger-selector-row">
					<Button
						variant="secondary"
						onClick={ () => setIsModalOpen( true ) }
					>
						{ __( 'Select Hooks', 'newspack-event-logger-nodes' ) }
					</Button>
					<span className="event-logger-selector-count">
						{ sprintf(
							// translators: %d: number of selected hooks.
							_n(
								'%d hook selected',
								'%d hooks selected',
								values.length,
								'newspack-event-logger-nodes'
							),
							values.length
						) }
					</span>
				</div>
				<HookSelectorModal
					isOpen={ isModalOpen }
					onClose={ () => setIsModalOpen( false ) }
					selected={ values }
					onSelect={ handleHookSelect }
					mode={ hookSelectorMode }
				/>
			</div>
		);
	}

	// Custom event selector mode: simplified UI with just count and button.
	if ( showCustomSelector ) {
		return (
			<div className="event-logger-tag-input">
				<div className="event-logger-selector-row">
					<Button
						variant="secondary"
						onClick={ () => setIsModalOpen( true ) }
					>
						{ __( 'Select Events', 'newspack-event-logger-nodes' ) }
					</Button>
					<span className="event-logger-selector-count">
						{ sprintf(
							// translators: %d: number of selected events.
							_n(
								'%d event selected',
								'%d events selected',
								values.length,
								'newspack-event-logger-nodes'
							),
							values.length
						) }
					</span>
				</div>
				<CustomEventSelectorModal
					isOpen={ isModalOpen }
					onClose={ () => setIsModalOpen( false ) }
					selected={ values }
					onSelect={ handleHookSelect }
				/>
			</div>
		);
	}

	// Standard tag input mode.
	return (
		<div className="event-logger-tag-input">
			{ values.length > 0 && (
				<div className={ containerClass }>
					{ values.map( ( value, index ) => {
						const isDefault = defaultSet.has( value );
						const tokenClass = `event-logger-tag-token ${
							isDefault ? 'is-default' : 'is-custom'
						}`;
						return (
							<div key={ index } className={ tokenClass }>
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
						);
					} ) }
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
