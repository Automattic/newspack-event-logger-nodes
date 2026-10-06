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

import { CHART_METRIC_OPTIONS } from '../constants';
import AggregateTimeChart from '../AggregateTimeChart';

/**
 * Draws a Server select where the caller offers one, then Metric, Breakdown,
 * the chart, and one line naming what the chart has instead of a series.
 *
 * What differs between the Overview card and the URL modal arrives as a prop —
 * the Server select on one side, the in-flight flag and the refusal on the
 * other, and on both the dimension list that still splits inside that scope —
 * so neither scope carries a second copy of the panel to hang its own extra
 * on. The Metric list is not a prop: every metric reduces the same three
 * per-bucket totals, so no caller has one to withhold.
 *
 * Both callers mount it unconditionally, because the selects are the only way
 * out of a dimension with no rows, a read still in flight or a refused reply.
 * The panel says which of those three it has: a Loading pill beside the
 * selects, the refusal and the empty-dimension line under the chart. The
 * caller's one `breakdownState()` read yields both the state and the table the
 * chart draws, so the blank frame and the line beneath it cannot disagree, and
 * a reply the caller already read is never decoded again here.
 *
 * @param {Object}                                              props                   Component props.
 * @param {Object}                                              props.breakdownRead     The caller's `breakdownState()` read of the dimension's reply: `{ state, series }`.
 * @param {string[]|null}                                       props.slots             The bucket keys the reply drew, which the chart's axis is.
 * @param {string}                                              props.metric            'volume' | 'avg' | 'cumulative' | 'memory'.
 * @param {(value: string) => void}                             props.setMetric         Metric setter.
 * @param {string}                                              props.breakdown         Selected dimension, a value from `breakdownOptions`.
 * @param {(value: string) => void}                             props.setBreakdown      Breakdown dimension setter.
 * @param {Array<Object>}                                       props.breakdownOptions  `{ label, value }` dimension choices: what still splits inside the caller's scope.
 * @param {Array<Object>|null}                                  [props.serverOptions]   `{ label, value }` server choices; null renders no Server select, and `[]` is truthy, so it renders an empty one.
 * @param {string}                                              [props.serverFilter]    Selected server name, or '' for all servers.
 * @param {(value: string) => void}                             [props.setServerFilter] Server filter setter, required alongside `serverOptions`.
 * @param {boolean}                                             [props.loading]         True while a read is out. It is not `pending`: a periodic refresh keeps the previous series drawn and still says the read is out.
 * @param {string|null}                                         [props.error]           Already-translated refusal printed under the chart.
 * @param {string|null}                                         [props.note]            Already-translated caveat printed under the chart.
 * @param {(key: string, click: {additive: boolean}) => void}   [props.onSlotClick]     Receives the bucket key a click on the chart lands on, and `{ additive }`.
 * @param {(keys: string[], drag: {additive: boolean}) => void} [props.onSlotRange]     Receives the bucket keys a drag on the chart spans, and `{ additive }`.
 * @param {string[]}                                            props.selectedBuckets   The selected bucket keys the chart shades.
 * @return {import('react').ReactElement} Rendered panel.
 */
export default function BreakdownControls( {
	breakdownRead,
	slots,
	metric,
	setMetric,
	breakdown,
	setBreakdown,
	breakdownOptions,
	serverOptions = null,
	serverFilter = '',
	setServerFilter,
	loading = false,
	error = null,
	note = null,
	onSlotClick,
	onSlotRange,
	selectedBuckets,
} ) {
	// A refusal is terminal: it is why the dimension never arrived.
	const state = error ? 'error' : breakdownRead.state;
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
				series={ breakdownRead.series }
				slots={ slots }
				metric={ metric }
				breakdown={ breakdown }
				serverFilter={ serverFilter }
				onSlotClick={ onSlotClick }
				onSlotRange={ onSlotRange }
				selectedBuckets={ selectedBuckets }
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
