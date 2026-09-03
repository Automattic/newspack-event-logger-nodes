/**
 * The aggregate time chart and the selectors that drive it.
 *
 * The chart and its controls are one panel: the Metric and Breakdown values
 * the chart reads are exactly the ones the selects above it set, so splitting
 * the two would hand every caller the same select-to-chart wiring to redo.
 * The Overview card and the URL modal both draw it.
 */

import { __, sprintf } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';

import { CHART_METRIC_OPTIONS, CHART_BREAKDOWN_OPTIONS } from '../constants';
import AggregateTimeChart, { breakdownState } from '../AggregateTimeChart';

/**
 * Draws a Server select where the caller offers one, then Metric, Breakdown,
 * the chart, and one line naming what the chart has instead of a series.
 *
 * What differs between the Overview card and the URL modal arrives as a prop —
 * the Server select and a narrowed dimension list on one side, the in-flight
 * flag and the refusal on the other — so neither scope carries a second copy
 * of the panel to hang its own extra on. The Metric list is not a prop: every
 * metric reduces the same three per-bucket totals, so no caller has one to
 * withhold.
 *
 * Both callers mount it unconditionally, because the selects are the only way
 * out of a dimension with no rows, a read still in flight or a refused reply.
 * The panel says which of those three it has: a Loading pill beside the
 * selects, the refusal and the empty-dimension line under the chart. The state
 * comes from `breakdownState`, which the chart reads too, so the blank frame
 * and the line beneath it cannot disagree.
 *
 * @param {Object}                  props                    Component props.
 * @param {Object|null}             props.breakdownData      Bucket key => dimension value => `{ c, s, m }` — count, summed ms, summed peak MB — or null before the reply.
 * @param {string}                  props.metric             'volume' | 'avg' | 'cumulative' | 'memory'.
 * @param {(value: string) => void} props.setMetric          Metric setter.
 * @param {string}                  props.breakdown          Selected dimension, a value from `breakdownOptions`.
 * @param {(value: string) => void} props.setBreakdown       Breakdown dimension setter.
 * @param {Array<Object>}           [props.breakdownOptions] `{ label, value }` dimension choices; defaults to all of them.
 * @param {Array<Object>|null}      [props.serverOptions]    `{ label, value }` server choices; null renders no Server select, and `[]` is truthy, so it renders an empty one.
 * @param {string}                  [props.serverFilter]     Selected server name, or '' for all servers.
 * @param {(value: string) => void} [props.setServerFilter]  Server filter setter, required alongside `serverOptions`.
 * @param {boolean}                 [props.loading]          True while a read is out. It is not `pending`: a periodic refresh keeps the previous series drawn and still says the read is out.
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
