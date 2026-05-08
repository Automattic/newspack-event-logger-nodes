/**
 * Performance Logger Settings Entry Point
 *
 * Settings page UI components for tag input fields.
 */

import { render } from '@wordpress/element';

import TagInputField from './settings/TagInputField';
import './styles/base.scss';
import './styles/settings.scss';
import './styles/hook-selector.scss';
import './styles/custom-event-selector.scss';

// Mount settings components when DOM is ready.
document.addEventListener( 'DOMContentLoaded', () => {
	initTagInputFields();
	initResetButtons();
} );

/**
 * Safely parse JSON array from DOM data attribute.
 * Validates result is an array of strings to prevent prototype pollution.
 *
 * @param {string} jsonStr JSON string to parse.
 * @return {string[]} Validated array of strings.
 */
function parseStringArray( jsonStr ) {
	try {
		const parsed = JSON.parse( jsonStr || '[]' );
		if ( Array.isArray( parsed ) ) {
			return parsed.filter( ( item ) => typeof item === 'string' );
		}
	} catch {
		// Fall through to default
	}
	return [];
}

/**
 * Initialize tag input fields on the settings page.
 */
function initTagInputFields() {
	// Fields with their layout mode and selector support.
	const tagInputFields = [
		{ name: 'log_urls', horizontal: false },
		{ name: 'skip_urls', horizontal: false },
		{
			name: 'log_events',
			horizontal: true,
			showHookSelector: true,
			hookSelectorMode: 'include',
		},
		{
			name: 'custom_events',
			horizontal: true,
			showCustomSelector: true,
		},
		{ name: 'significant_events', horizontal: true },
	];

	tagInputFields.forEach(
		( {
			name: fieldName,
			horizontal,
			showHookSelector,
			hookSelectorMode,
			showCustomSelector,
		} ) => {
			const container = document.getElementById(
				`event-logger-${ fieldName }`
			);
			if ( container ) {
				const initialValues = parseStringArray(
					container.dataset.values
				);
				const defaultValues = parseStringArray(
					container.dataset.default
				);
				render(
					<TagInputField
						fieldName={ fieldName }
						initialValues={ initialValues }
						defaultValues={ defaultValues }
						horizontal={ horizontal }
						showHookSelector={ showHookSelector }
						hookSelectorMode={ hookSelectorMode }
						showCustomSelector={ showCustomSelector }
					/>,
					container
				);
			}
		}
	);
}

/**
 * Initialize reset buttons for all field types.
 */
function initResetButtons() {
	document.querySelectorAll( '[data-field]' ).forEach( ( btn ) => {
		if (
			! btn.classList.contains( 'event-logger-reset-field' ) &&
			! btn.classList.contains( 'event-logger-reset-number' ) &&
			! btn.classList.contains( 'event-logger-reset-text' )
		) {
			return;
		}

		btn.addEventListener( 'click', () => {
			const fieldName = btn.dataset.field;
			const defaultVal = btn.dataset.default;

			// Try React component first (array fields).
			const container = document.getElementById(
				`event-logger-${ fieldName }`
			);
			if ( container ) {
				container.dispatchEvent(
					new CustomEvent( 'event-logger-reset', {
						detail: {
							field: fieldName,
							defaultValues: parseStringArray( defaultVal ),
						},
					} )
				);
				return;
			}

			// Fall back to direct input (number/text fields).
			// Clear to empty - placeholder shows default.
			const input = document.getElementById( fieldName );
			if ( input ) {
				input.value = '';
			}
		} );
	} );
}
