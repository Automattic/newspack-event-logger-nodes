/**
 * The custom-event picker a logging rule's editor opens.
 *
 * A rule records only the custom events it names, and this is where an operator
 * picks them from every event the deployment has registered. Each row carries
 * the swatch the dashboards draw that event in, so one name looks the same in
 * both places.
 */

import { useState, useMemo } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { CheckboxControl } from '@wordpress/components';
import { useMultiSelect } from '../../hooks/useMultiSelect';
import SelectorModal from './SelectorModal';
import '../styles/custom-event-selector.scss';

/**
 * Every registered custom event, mapped to the swatch the dashboards draw it in.
 *
 * `Config::get_custom_colors()` merges the deployment config, whatever the
 * `newspack_event_logger_nodes_custom_colors` filter adds and the events spokes
 * reported to the hub, then sorts the map case-insensitively; the picker lists
 * the events in that order and imposes none of its own. WordPress prints the
 * map as an inline script before this bundle, so it is in hand at module load
 * and cannot change while the page lives.
 *
 * @type {Object<string,string>}
 */
const CUSTOM_COLORS = window.newspackNodesCustomColors || {};

/**
 * Custom Event Selector Modal component.
 *
 * The search narrows the grid and Select All with it, so searching and then
 * selecting adds a whole family of event names at once. Clear All and both
 * footer numbers ignore the search, because the selection Apply hands back is
 * the whole one — a count of what survived the search would understate it.
 *
 * @param {Object}                  props             Component props.
 * @param {boolean}                 props.isOpen      Whether the modal is open.
 * @param {() => void}              props.onClose     Close callback.
 * @param {Array}                   props.selected    Events the modal opens with; reopening resets to these.
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

	const allEvents = useMemo( () => Object.keys( CUSTOM_COLORS ), [] );

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
					// Mirrors the PHP default swatch for a colorless event.
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
