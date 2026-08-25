/**
 * The aggregate time chart and the selectors that drive it.
 *
 * The chart and its controls are one panel: the Metric and Breakdown values it
 * reads are exactly the ones the dropdowns above it set, so splitting them
 * would hand every caller the same three-prop wiring to redo. The Overview
 * card and the URL modal both draw it.
 */

import { __, sprintf } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';

import { CHART_METRIC_OPTIONS, CHART_BREAKDOWN_OPTIONS } from '../constants';
import AggregateTimeChart, { breakdownState } from '../AggregateTimeChart';

/**
 * Breakdown controls component.
 *
 * What differs between the Overview card and the URL modal arrives as a prop —
 * the Server selector, the note — so neither scope carries a second copy of
 * the panel to hang its one extra on.
 *
 * Both callers mount it unconditionally, because the dropdowns are the only
 * way out of a dimension with no rows, a read still in flight or a refused
 * reply — and it says which of those four it is under the chart's own frame.
 *
 * @param {Object}                  props                    Component props.
 * @param {Object|null}             props.breakdownData      Per-dimension series driving the chart, or null before the reply.
 * @param {string}                  props.metric             Selected metric, e.g. 'volume'.
 * @param {(value: string) => void} props.setMetric          Metric setter.
 * @param {string}                  props.breakdown          Selected breakdown dimension.
 * @param {(value: string) => void} props.setBreakdown       Breakdown dimension setter.
 * @param {Array}                   [props.breakdownOptions] Dimension choices; defaults to all of them.
 * @param {Array|null}              [props.serverOptions]    Server choices; null renders no Server selector.
 * @param {string}                  [props.serverFilter]     Selected server name, or '' for all servers.
 * @param {(value: string) => void} [props.setServerFilter]  Server filter setter.
 * @param {boolean}                 [props.loading]          True while the breakdown series is in flight.
 * @param {string|null}             [props.error]            Already-translated refusal printed under the chart.
 * @param {string|null}             [props.note]             Already-translated caveat printed under the chart.
 * @return {import('react').ReactElement} Rendered panel.
 */
export default function BreakdownControls( {
	breakdownData,
	metric,
	setMetric,
	breakdown,
	setBreakdown,
	breakdownOptions = CHART_BREAKDOWN_OPTIONS,
	serverOptions = null,
	serverFilter = '',
	setServerFilter,
	loading = false,
	error = null,
	note = null,
} ) {
	// A refusal is terminal: it is why the dimension never arrived.
	const state = error ? 'error' : breakdownState( breakdownData );
	const dimension =
		breakdownOptions.find( ( option ) => option.value === breakdown )
			?.label ?? breakdown;
	return (
		<div className="event-logger-aggregate-chart">
			<div
				style={ {
					display: 'flex',
					gap: '16px',
					margin: '12px 0',
					alignItems: 'flex-end',
					flexWrap: 'wrap',
				} }
			>
				{ serverOptions && (
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Server', 'newspack-event-logger-nodes' ) }
						value={ serverFilter }
						options={ serverOptions }
						onChange={ setServerFilter }
						__nextHasNoMarginBottom
						style={ { minWidth: '180px' } }
					/>
				) }
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'Metric', 'newspack-event-logger-nodes' ) }
					value={ metric }
					options={ CHART_METRIC_OPTIONS }
					onChange={ setMetric }
					__nextHasNoMarginBottom
					style={ { minWidth: '180px' } }
				/>
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'Breakdown', 'newspack-event-logger-nodes' ) }
					value={ breakdown }
					options={ breakdownOptions }
					onChange={ setBreakdown }
					__nextHasNoMarginBottom
					style={ { minWidth: '140px' } }
				/>
				{ ( loading || 'pending' === state ) && (
					<span
						className="newspack-nodes-status"
						style={ { fontSize: '12px', paddingBottom: '8px' } }
					>
						{ __( 'Loading…', 'newspack-event-logger-nodes' ) }
					</span>
				) }
			</div>
			<AggregateTimeChart
				breakdownData={ breakdownData }
				metric={ metric }
				breakdown={ breakdown }
				serverFilter={ serverFilter }
			/>
			{ error && (
				<p className="newspack-nodes-status is-error">{ error }</p>
			) }
			{ 'empty' === state && (
				<p className="newspack-nodes-status">
					{ sprintf(
						// translators: %s: the breakdown dimension's label, e.g. User Agent.
						__(
							'No %s data in this window.',
							'newspack-event-logger-nodes'
						),
						dimension
					) }
				</p>
			) }
			{ note && <p className="newspack-nodes-status">{ note }</p> }
		</div>
	);
}
