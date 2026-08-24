/**
 * Custom Event Selector Modal Component
 *
 * A modal for selecting custom events to log, with color swatches.
 */

import { useState, useMemo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';
import { useMultiSelect } from '../../hooks/useMultiSelect';
import SelectorModal from './SelectorModal';
import '../styles/custom-event-selector.scss';

/**
 * Custom event colors - loaded from PHP config (includes plugin-registered events via filter).
 * Order is preserved from config so each plugin's events stay together.
 */
const CUSTOM_COLORS = window.newspackNodesCustomColors || {};

/**
 * Custom Event Selector Modal component.
 *
 * @param {Object}                  props             Component props.
 * @param {boolean}                 props.isOpen      Whether the modal is open.
 * @param {() => void}              props.onClose     Close callback.
 * @param {Array}                   props.selected    Currently selected events.
 * @param {(events: Array) => void} props.onSelect    Called on Apply with the selected event names.
 * @param {string}                  [props.className] Extra classes for the modal frame (skin theming).
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
	const chosen = useMultiSelect( { selected, isOpen } );

	// Get all available events from config (preserves order).
	const allEvents = useMemo( () => Object.keys( CUSTOM_COLORS ), [] );

	// Filter events by search.
	const filteredEvents = useMemo( () => {
		const searchLower = search.toLowerCase();
		return allEvents.filter( ( event ) =>
			event.toLowerCase().includes( searchLower )
		);
	}, [ search, allEvents ] );

	if ( ! isOpen ) {
		return null;
	}

	const totalSelected = chosen.count;
	const totalEvents = allEvents.length;

	return (
		<SelectorModal
			title={ __(
				'Select Custom Events to Log',
				'newspack-event-logger-nodes'
			) }
			className={ `event-logger-custom-event-modal ${ className }`.trim() }
			onClose={ onClose }
			search={ search }
			onSearch={ setSearch }
			placeholder={ __(
				'Search events…',
				'newspack-event-logger-nodes'
			) }
			actions={
				<>
					<button
						type="button"
						className="button"
						onClick={ () => chosen.addAll( filteredEvents ) }
					>
						{ __( 'Select All', 'newspack-event-logger-nodes' ) }
					</button>
					<button
						type="button"
						className="button"
						onClick={ chosen.clear }
					>
						{ __( 'Clear All', 'newspack-event-logger-nodes' ) }
					</button>
					<button
						type="button"
						className="button button-primary"
						onClick={ () => chosen.apply( onSelect, onClose ) }
					>
						{ sprintf(
							// translators: %d: number of currently selected events.
							__( 'Apply (%d)', 'newspack-event-logger-nodes' ),
							totalSelected
						) }
					</button>
				</>
			}
			footer={
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
			}
		>
			<div className="custom-event-grid">
				{ filteredEvents.map( ( event ) => {
					const color = CUSTOM_COLORS[ event ] || '#ffa726';
					const isSelected = chosen.has( event );
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
								onChange={ () => chosen.toggle( event ) }
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
		</SelectorModal>
	);
}
