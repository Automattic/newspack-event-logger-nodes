/**
 * Custom Event Selector Modal Component
 *
 * A modal for selecting custom events to log, with color swatches.
 */

import { useState, useMemo, useEffect } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Modal, SearchControl, CheckboxControl } from '@wordpress/components';
import '../styles/custom-event-selector.scss';

/**
 * Custom event colors - loaded from PHP config (includes plugin-registered events via filter).
 * Order is preserved from config so each plugin's events stay together.
 */
const CUSTOM_COLORS = window.newspackNodesCustomColors || {};

/**
 * Custom Event Selector Modal component.
 *
 * @param {Object}   props             Component props.
 * @param {boolean}  props.isOpen      Whether the modal is open.
 * @param {Function} props.onClose     Close callback.
 * @param {Array}    props.selected    Currently selected events.
 * @param {Function} props.onSelect    Callback when events are selected.
 * @param {string}   [props.className] Extra classes for the modal frame (skin theming).
 * @return {import('react').ReactElement|null} Rendered component.
 */
export default function CustomEventSelectorModal( {
	isOpen,
	onClose,
	selected = [],
	onSelect,
	className = '',
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ localSelected, setLocalSelected ] = useState( new Set( selected ) );

	// Get all available events from config (preserves order).
	const allEvents = useMemo( () => Object.keys( CUSTOM_COLORS ), [] );

	// Reset local state when modal opens.
	useEffect( () => {
		if ( isOpen ) {
			setLocalSelected( new Set( selected ) );
		}
	}, [ isOpen, selected ] );

	// Filter events by search.
	const filteredEvents = useMemo( () => {
		const searchLower = search.toLowerCase();
		return allEvents.filter( ( event ) =>
			event.toLowerCase().includes( searchLower )
		);
	}, [ search, allEvents ] );

	/**
	 * Toggle an event selection.
	 *
	 * @param {string} event Event name.
	 */
	const toggleEvent = ( event ) => {
		const newSelected = new Set( localSelected );
		if ( newSelected.has( event ) ) {
			newSelected.delete( event );
		} else {
			newSelected.add( event );
		}
		setLocalSelected( newSelected );
	};

	/**
	 * Apply selection and close.
	 */
	const handleApply = () => {
		onSelect( Array.from( localSelected ) );
		onClose();
	};

	/**
	 * Select all visible events.
	 */
	const selectAll = () => {
		const newSelected = new Set( localSelected );
		filteredEvents.forEach( ( event ) => newSelected.add( event ) );
		setLocalSelected( newSelected );
	};

	/**
	 * Clear all selections.
	 */
	const clearAll = () => {
		setLocalSelected( new Set() );
	};

	if ( ! isOpen ) {
		return null;
	}

	const totalSelected = localSelected.size;
	const totalEvents = allEvents.length;

	return (
		<Modal
			title={ __(
				'Select Custom Events to Log',
				'newspack-event-logger-nodes'
			) }
			onRequestClose={ onClose }
			className={ `event-logger-custom-event-modal newspack-nodes-modal newspack-nodes-theme newspack-nodes-ui ${ className }`.trim() }
		>
			<div className="custom-event-header">
				<SearchControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					value={ search }
					onChange={ setSearch }
					placeholder={ __(
						'Search events…',
						'newspack-event-logger-nodes'
					) }
					className="custom-event-search"
				/>
				<div className="custom-event-actions">
					<button
						type="button"
						className="button"
						onClick={ selectAll }
					>
						{ __( 'Select All', 'newspack-event-logger-nodes' ) }
					</button>
					<button
						type="button"
						className="button"
						onClick={ clearAll }
					>
						{ __( 'Clear All', 'newspack-event-logger-nodes' ) }
					</button>
					<button
						type="button"
						className="button button-primary"
						onClick={ handleApply }
					>
						{ sprintf(
							// translators: %d: number of currently selected events.
							__( 'Apply (%d)', 'newspack-event-logger-nodes' ),
							totalSelected
						) }
					</button>
				</div>
			</div>

			<div className="custom-event-grid">
				{ filteredEvents.map( ( event ) => {
					const color = CUSTOM_COLORS[ event ] || '#ffa726';
					const isSelected = localSelected.has( event );
					const eventId = `event-${ event.replace(
						/[^a-z0-9]/gi,
						'-'
					) }`;
					return (
						<label
							key={ event }
							htmlFor={ eventId }
							className={ `custom-event-item newspack-nodes-interactive-row${
								isSelected ? ' is-selected' : ''
							}` }
						>
							<CheckboxControl
								id={ eventId }
								__nextHasNoMarginBottom
								checked={ isSelected }
								onChange={ () => toggleEvent( event ) }
							/>
							<span
								className="custom-event-swatch"
								style={ { '--event-color': color } }
							/>
							<span className="custom-event-name">{ event }</span>
						</label>
					);
				} ) }
			</div>

			<div className="custom-event-footer">
				<span className="custom-event-count newspack-nodes-status">
					{ sprintf(
						// translators: 1: number of selected events, 2: total number of events.
						_n(
							'%1$d of %2$d event selected',
							'%1$d of %2$d events selected',
							totalEvents,
							'newspack-event-logger-nodes'
						),
						totalSelected,
						totalEvents
					) }
				</span>
			</div>
		</Modal>
	);
}
