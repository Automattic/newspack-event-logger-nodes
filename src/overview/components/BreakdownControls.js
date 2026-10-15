/**
 * The aggregate time chart and the selectors that drive it.
 *
 * The chart and its controls are one panel: the Metric and Breakdown values
 * the chart reads are exactly the ones the selects above it set, so splitting
 * the two would hand every caller the same select-to-chart wiring to redo.
 * The Overview card and the URL modal both draw it.
 */

import { useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';

import { CHART_METRIC_OPTIONS } from '../constants';
import AggregateTimeChart from '../AggregateTimeChart';
import { chartDims, filterValue, topNote, valueName } from '../chartSlots';
import TokenInput from '../../components/TokenInput';
import '../../settings/styles/tag-input.scss';

/**
 * Why a chip is refused, or null for one it takes: a chip is `field=value`,
 * the field one `options` offers and the value not empty. The page judges a
 * linked `?chip=` by it too, so the editor and the address bar take one set.
 *
 * @param {string}                                draft   The trimmed draft.
 * @param {Array<{label: string, value: string}>} options The chip fields.
 * @return {?string} The refusal.
 */
export const chipRefusal = ( draft, options ) => {
	const cut = draft.indexOf( '=' );
	if ( cut < 1 || cut === draft.length - 1 ) {
		return __(
			'Type a filter as field=value.',
			'newspack-event-logger-nodes'
		);
	}
	const field = draft.slice( 0, cut );
	return options.some( ( option ) => option.value === field )
		? null
		: sprintf(
				// translators: %s: the field name typed.
				__(
					'No field %s to filter on.',
					'newspack-event-logger-nodes'
				),
				field
		  );
};

/**
 * The filter chips: each a `field=value` the chart's question matches, shown
 * as `Field = value` — a status class as `5xx` — and the box a typed one is
 * added through.
 *
 * @param {Object}                                props          Component props.
 * @param {string[]}                              props.chips    The chips, each `field=value` as `query` takes a filter.
 * @param {(chips: string[]) => void}             props.onChange Receives the chips an addition or a removal leaves.
 * @param {Array<{label: string, value: string}>} props.options  The fields a chip may name.
 * @return {import('react').ReactElement} The chips and their box.
 */
function FilterChips( { chips, onChange, options } ) {
	const label = ( chip ) => {
		const cut = chip.indexOf( '=' );
		const field = chip.slice( 0, cut );
		const named = options.find( ( option ) => option.value === field );
		return `${ named?.label ?? field } = ${ valueName(
			field,
			chip.slice( cut + 1 )
		) }`;
	};
	return (
		<div className="event-logger-tag-container horizontal event-logger-chip-filter">
			<TokenInput
				tokens={ chips.map( label ) }
				onRemove={ ( index ) =>
					onChange( chips.filter( ( _, at ) => at !== index ) )
				}
				value=""
				validate={ ( draft ) => chipRefusal( draft, options ) }
				refuseWhileTyping={ false }
				onCommit={ ( draft ) => {
					const cut = draft.indexOf( '=' );
					const field = draft.slice( 0, cut );
					onChange( [
						...chips,
						`${ field }=${ filterValue(
							field,
							draft.slice( cut + 1 )
						) }`,
					] );
				} }
				commitOnBlur={ false }
				suggestions={ options.map( ( option ) => ( {
					value: `${ option.value }=`,
					label: option.label,
				} ) ) }
				aria-label={ __( 'Filter', 'newspack-event-logger-nodes' ) }
				placeholder={ __(
					'Filter, e.g. status_class=5xx',
					'newspack-event-logger-nodes'
				) }
			/>
		</div>
	);
}

/**
 * Draws a Server select where the caller offers one, then Metric, Breakdown
 * and Then by, with the filter chips filling the rest of their row, then the
 * chart, and one line naming what the chart has instead of a series.
 *
 * What differs between the Overview card and the URL modal arrives as a prop —
 * the Server select on one side, the in-flight flag and the refusal on the
 * other, and on both the dimension list that still splits inside that scope
 * and the fields a chip may name — so neither scope carries a second copy of
 * the panel to hang its own extra on. The Metric list is not a prop: every
 * metric reduces the same per-bucket sums, so no caller has one to withhold.
 *
 * Both callers mount it unconditionally, because the selects are the only way
 * out of a dimension with no rows, a read still in flight or a refused reply.
 * The panel says which of those three it has: a Loading pill beside the
 * selects, the refusal and the empty-dimension line under the chart. The
 * caller's one `breakdownState()` read yields both the state and the series
 * the chart draws, so the blank frame and the line beneath it cannot
 * disagree, and an answer the caller already read is never decoded again
 * here. Where the answer ranked more series than the chart holds, a line
 * says how many it draws of how many.
 *
 * @param {Object}                                              props                   Component props.
 * @param {Object}                                              props.breakdownRead     The caller's `breakdownState()` read of the dimensions' answer: `{ state, series, groups }`.
 * @param {string[]|null}                                       props.slots             The bucket keys the answer read, which the chart's axis is.
 * @param {string}                                              props.storageKey        Untranslated placement name for the chart; see AggregateTimeChart.
 * @param {string}                                              props.metric            'volume' | 'avg' | 'cumulative' | 'memory'.
 * @param {(value: string) => void}                             props.setMetric         Metric setter.
 * @param {string}                                              props.breakdown         Selected first dimension, a value from `breakdownOptions`.
 * @param {(value: string) => void}                             props.setBreakdown      First dimension setter.
 * @param {string}                                              props.thenBy            Selected second dimension; '' for none.
 * @param {(value: string) => void}                             props.setThenBy         Second dimension setter.
 * @param {Array<Object>}                                       props.breakdownOptions  `{ label, value }` dimension choices: what still splits inside the caller's scope.
 * @param {string[]}                                            props.chips             The filter chips, each `field=value`.
 * @param {(chips: string[]) => void}                           props.setChips          Receives the chips an edit leaves.
 * @param {Array<Object>}                                       props.chipOptions       `{ label, value }` fields a chip may name.
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
	storageKey,
	metric,
	setMetric,
	breakdown,
	setBreakdown,
	thenBy,
	setThenBy,
	breakdownOptions,
	chips,
	setChips,
	chipOptions,
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
	const dims = useMemo(
		() => chartDims( { breakdown, thenBy } ),
		[ breakdown, thenBy ]
	);
	const dimension = dims
		.map(
			( dim ) =>
				breakdownOptions.find( ( option ) => option.value === dim )
					?.label ?? dim
		)
		.join( ' × ' );
	const top = topNote( breakdownRead.series, breakdownRead.groups );
	return (
		<div className="event-logger-aggregate-chart">
			<div
				className="event-logger-chart-controls"
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
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'Then by', 'newspack-event-logger-nodes' ) }
					value={ thenBy }
					options={ [
						{
							label: __( 'None', 'newspack-event-logger-nodes' ),
							value: '',
						},
						...breakdownOptions.filter(
							( option ) => option.value !== breakdown
						),
					] }
					onChange={ setThenBy }
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
				<FilterChips
					chips={ chips }
					onChange={ setChips }
					options={ chipOptions }
				/>
			</div>
			<AggregateTimeChart
				series={ breakdownRead.series }
				slots={ slots }
				storageKey={ storageKey }
				metric={ metric }
				dims={ dims }
				partial={ null !== top }
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
						// translators: %s: the breakdown dimensions' labels, e.g. User Agent.
						__(
							'No %s data in this window.',
							'newspack-event-logger-nodes'
						),
						dimension
					) }
				</p>
			) }
			{ top && <p className="newspack-nodes-status">{ top }</p> }
			{ note && <p className="newspack-nodes-status">{ note }</p> }
		</div>
	);
}
