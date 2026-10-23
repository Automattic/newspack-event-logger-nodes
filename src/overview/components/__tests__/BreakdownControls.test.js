/* global globalThis, KeyboardEvent */
/**
 * Tests for BreakdownControls — the aggregate chart and its selectors, drawn
 * by both the Overview card and the URL modal.
 *
 * The D3 chart is mocked at the module boundary; what matters here is which
 * selectors mount, what the chart is handed, and what the chips hand back.
 */

// The chart is mocked; its `breakdownState` resolver is NOT — the panel and
// the chart read the same one.
jest.mock( '../../AggregateTimeChart', () => ( {
	...jest.requireActual( '../../AggregateTimeChart' ),
	__esModule: true,
	default: ( {
		metric,
		dims,
		partial,
		serverFilter,
		slots,
		series,
		onSlotClick,
		onSlotRange,
	} ) => {
		globalThis.__aggregateSlotClick = onSlotClick;
		globalThis.__aggregateSlotRange = onSlotRange;
		return `AGGREGATE[metric=${ metric },dims=${ dims.join(
			'+'
		) },server=${ serverFilter || '' }] slots:${
			slots?.[ 0 ] ?? 'none'
		} names:${ series?.names.length ?? 'none' } partial:${ partial }`;
	},
} ) );

import * as React from 'react';
import BreakdownControls from '../BreakdownControls';
import { breakdownState } from '../../AggregateTimeChart';
import * as chartSlots from '../../chartSlots';
import { chartDims } from '../../chartSlots';
import {
	CHART_BREAKDOWN_OPTIONS,
	FILTER_FIELD_OPTIONS,
	SERIES_MEASURES,
} from '../../constants';
import { act, renderComponent } from '../../../test-helpers/renderHook';
import { rowsWire, slotsEndingAt } from '../../../test-helpers/chartWire';
import { compileLocal, resolveCascade } from '../../../test-helpers/cascade';

/**
 * A `query` answer for the chart: one row per bucket and value of `dim`.
 *
 * @param {string}                   dim    The dimension grouped by.
 * @param {Array<[string, *, ...*]>} rows   `[ bucket, value, ...measures ]`.
 * @param {Object}                   [more] Answer fields to set, as `groups`.
 * @return {Object} The answer.
 */
const answer = ( dim, rows, more = {} ) =>
	rowsWire( [ 'bucket', dim, ...SERIES_MEASURES ], rows, more );

/**
 * Mount the panel on the read its caller would take of `breakdownData`.
 *
 * @param {Object}      [props]               The panel's props, but for the read.
 * @param {Object|null} [props.breakdownData] The raw answer the caller reads.
 * @return {Object} The mounted panel.
 */
function mountBreakdown( {
	breakdownData = answer( 'method', [
		[ '2026-09-29-14-35', 'PATCH', 7, 70, 3, 7 ],
	] ),
	...overrides
} = {} ) {
	const props = {
		metric: 'memory',
		setMetric: jest.fn(),
		breakdown: 'method',
		setBreakdown: jest.fn(),
		thenBy: '',
		setThenBy: jest.fn(),
		breakdownOptions: CHART_BREAKDOWN_OPTIONS,
		chips: [],
		setChips: jest.fn(),
		chipOptions: FILTER_FIELD_OPTIONS,
		selectedBuckets: [],
		...overrides,
	};
	const dims = chartDims( props );
	return {
		props,
		...renderComponent(
			React.createElement( BreakdownControls, {
				breakdownRead: breakdownState( breakdownData, dims ),
				...props,
			} )
		),
	};
}

/**
 * Type into an input as a person would.
 *
 * @param {HTMLInputElement} input The box.
 * @param {string}           value What lands in it.
 */
function type( input, value ) {
	const setter = Object.getOwnPropertyDescriptor(
		window.HTMLInputElement.prototype,
		'value'
	).set;
	act( () => {
		setter.call( input, value );
		input.dispatchEvent(
			new window.InputEvent( 'input', {
				bubbles: true,
				inputType: 'insertText',
			} )
		);
	} );
}

/**
 * Press a key on a target.
 *
 * @param {Element} target What holds focus.
 * @param {string}  key    The key.
 */
function press( target, key ) {
	act( () => {
		target.dispatchEvent(
			new KeyboardEvent( 'keydown', {
				key,
				bubbles: true,
				cancelable: true,
			} )
		);
	} );
}

/**
 * The labels of the selects, in order.
 *
 * @param {Element} container The panel.
 * @return {string[]} Each label's text.
 */
const labels = ( container ) =>
	Array.from( container.querySelectorAll( 'label' ) ).map(
		( label ) => label.textContent
	);

/**
 * The chip box.
 *
 * @param {Element} container The panel.
 * @return {HTMLInputElement} The box.
 */
const chipBox = ( container ) =>
	container.querySelector( 'input[aria-label="Filter"]' );

/**
 * Whether a message reads before the chart.
 *
 * @param {Element} container The panel.
 * @param {string}  text      The message.
 * @return {boolean} Whether it precedes the chart's text.
 */
const above = ( container, text ) =>
	container.textContent.indexOf( text ) >= 0 &&
	container.textContent.indexOf( text ) <
		container.textContent.indexOf( 'AGGREGATE[' );

describe( 'BreakdownControls', () => {
	it( 'hands the chart the slots its answer read', () => {
		const { container, unmount } = mountBreakdown( {
			slots: slotsEndingAt( '2026-09-29-14-35', 24 ),
		} );
		expect( container.textContent ).toContain( 'slots:2026-09-29-14-35' );
		unmount();
	} );

	it( 'draws from the read it is handed, decoding nothing itself', () => {
		const read = breakdownState(
			answer( 'ua', [
				[ '2026-09-29-14-35', 'kea-ua/7', 41, 820, 3, 41 ],
				[ '2026-09-29-14-35', 'weka-ua/9', 43, 4300, 4, 43 ],
				[ '2026-09-29-14-35', 'tui-ua/2', 37, 370, 2, 37 ],
			] ),
			[ 'ua' ]
		);
		const decode = jest.spyOn( chartSlots, 'decodeRows' );
		const { container, unmount } = renderComponent(
			React.createElement( BreakdownControls, {
				breakdownRead: read,
				metric: 'volume',
				setMetric: jest.fn(),
				breakdown: 'ua',
				setBreakdown: jest.fn(),
				thenBy: '',
				setThenBy: jest.fn(),
				breakdownOptions: CHART_BREAKDOWN_OPTIONS,
				chips: [],
				setChips: jest.fn(),
				chipOptions: FILTER_FIELD_OPTIONS,
				selectedBuckets: [],
			} )
		);
		expect( container.textContent ).toContain( 'names:3' );
		expect( decode ).not.toHaveBeenCalled();
		decode.mockRestore();
		unmount();
	} );

	it( 'says the dimension is empty when its answer is malformed', () => {
		const { container, unmount } = mountBreakdown( {
			breakdown: 'ua',
			breakdownData: answer( 'ua', [
				[ '2026-09-29-14-35', 'kea-ua/7', '41', 820, 3, 41 ],
			] ),
		} );
		expect( container.textContent ).toContain(
			'No User Agent data in this window.'
		);
		unmount();
	} );

	it( 'names the dimension the answer came back empty for, above the chart', () => {
		const { container, unmount } = mountBreakdown( {
			breakdown: 'ua',
			breakdownData: answer( 'ua', [] ),
		} );
		expect( above( container, 'No User Agent data in this window.' ) ).toBe(
			true
		);
		expect( labels( container ) ).toEqual( [
			'Metric',
			'Breakdown',
			'Then by',
		] );
		unmount();
	} );

	it( 'reads stats rows that did not open as no data, never as an error', () => {
		const { container, unmount } = mountBreakdown( {
			breakdown: 'country',
			breakdownData: answer( 'country', [], { available: false } ),
		} );
		expect( container.textContent ).toContain(
			'No Country data in this window.'
		);
		expect( container.querySelector( '.is-error' ) ).toBeNull();
		unmount();
	} );

	it( 'names both dimensions an empty breakdown was asked by', () => {
		const { container, unmount } = mountBreakdown( {
			breakdown: 'ua',
			thenBy: 'server',
			breakdownData: rowsWire(
				[ 'bucket', 'ua', 'server', ...SERIES_MEASURES ],
				[]
			),
		} );
		expect( container.textContent ).toContain(
			'No User Agent × Server data in this window.'
		);
		unmount();
	} );

	it( 'says the read is still out while its answer is for other dimensions', () => {
		// The answer in hand predates the switch: "No User Agent data" there
		// is a lie that flickers.
		const { container, unmount } = mountBreakdown( {
			breakdown: 'ua',
			breakdownData: answer( 'method', [
				[ '2026-09-29-14-35', 'PATCH', 7, 70, 3, 7 ],
			] ),
		} );
		expect( container.textContent ).toContain( 'Loading…' );
		expect( container.textContent ).not.toContain( 'No User Agent' );
		expect( container.textContent ).toContain( 'names:none' );
		unmount();
	} );

	it( 'prints the refusal it was handed above the chart', () => {
		const { container, unmount } = mountBreakdown( {
			breakdownData: null,
			error: 'unknown requests field plumage',
		} );
		const shown = container.querySelector(
			'.newspack-nodes-status.is-error'
		);
		expect( shown.textContent ).toBe( 'unknown requests field plumage' );
		expect( above( container, 'unknown requests field plumage' ) ).toBe(
			true
		);
		expect( container.textContent ).not.toContain( 'Loading…' );
		unmount();
	} );

	it( 'drives the chart from the Metric and Breakdown selectors', () => {
		const { container, unmount } = mountBreakdown();
		expect( container.textContent ).toContain(
			'AGGREGATE[metric=memory,dims=method,server=]'
		);
		unmount();
	} );

	it( 'offers a second dimension and sends both in the query group', () => {
		const { container, props, unmount } = mountBreakdown( {
			breakdown: 'status_class',
			thenBy: 'country',
			breakdownData: rowsWire(
				[ 'bucket', 'status_class', 'country', ...SERIES_MEASURES ],
				[ [ '2026-09-29-14-35', 5, 'NZ', 4, 40, 2, 4 ] ]
			),
		} );
		const thenBy = container.querySelectorAll( 'select' )[ 2 ];
		expect( Array.from( thenBy.options ).map( ( o ) => o.value ) ).toEqual(
			[
				'',
				...CHART_BREAKDOWN_OPTIONS.map( ( o ) => o.value ).filter(
					( v ) => 'status_class' !== v
				),
			]
		);
		expect( container.textContent ).toContain(
			'dims=status_class+country'
		);
		act( () => {
			thenBy.value = 'ja4';
			thenBy.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		expect( props.setThenBy.mock.calls[ 0 ][ 0 ] ).toBe( 'ja4' );
		unmount();
	} );

	it( 'offers exactly the dimensions its caller hands it', () => {
		const { container, unmount } = mountBreakdown( {
			breakdown: 'ja4',
			breakdownOptions: [
				{ label: 'Country', value: 'country' },
				{ label: 'JA4 Hash', value: 'ja4' },
			],
		} );
		const [ , breakdown, thenBy ] = container.querySelectorAll( 'select' );
		expect(
			Array.from( breakdown.options ).map( ( o ) => o.value )
		).toEqual( [ 'country', 'ja4' ] );
		expect( Array.from( thenBy.options ).map( ( o ) => o.value ) ).toEqual(
			[ '', 'country' ]
		);
		unmount();
	} );

	it( 'adds the Server selector and its note only when asked', () => {
		const { container, unmount } = mountBreakdown( {
			serverOptions: [
				{ label: 'All Servers', value: '' },
				{ label: 'edge-77', value: 'edge-77' },
			],
			serverFilter: 'edge-77',
			setServerFilter: jest.fn(),
			note: 'Workers are counted above but not charted here.',
		} );
		expect( labels( container ) ).toEqual( [
			'Server',
			'Metric',
			'Breakdown',
			'Then by',
		] );
		expect( container.textContent ).toContain(
			'AGGREGATE[metric=memory,dims=method,server=edge-77]'
		);
		expect(
			above(
				container,
				'Workers are counted above but not charted here.'
			)
		).toBe( true );
		unmount();
	} );

	it( 'stays up while the read is in flight', () => {
		const { container, unmount } = mountBreakdown( { loading: true } );
		expect( container.textContent ).toContain( 'Loading…' );
		expect( labels( container ) ).toEqual( [
			'Metric',
			'Breakdown',
			'Then by',
		] );
		unmount();
	} );

	it( 'tells the chart its total is of the shown series where the top leaves some out', () => {
		const shown = mountBreakdown( {
			breakdownData: answer(
				'method',
				[ [ '2026-09-29-14-35', 'PATCH', 7, 70, 3, 7 ] ],
				{ groups: 9 }
			),
		} );
		expect( shown.container.textContent ).toContain( 'partial:true' );
		shown.unmount();

		const whole = mountBreakdown( {
			breakdownData: answer(
				'method',
				[ [ '2026-09-29-14-35', 'PATCH', 7, 70, 3, 7 ] ],
				{ groups: 1 }
			),
		} );
		expect( whole.container.textContent ).toContain( 'partial:false' );
		whole.unmount();
	} );

	it( 'charts one dimension where Then by repeats Breakdown', () => {
		const { container, unmount } = mountBreakdown( {
			breakdown: 'method',
			thenBy: 'method',
		} );
		expect( container.textContent ).toContain(
			'AGGREGATE[metric=memory,dims=method,'
		);
		expect( container.textContent ).toContain( 'names:1' );
		unmount();
	} );

	describe( 'filter chips', () => {
		it( 'lays the filter box on the row with the selects', () => {
			const { container, unmount } = mountBreakdown( {
				chips: [ 'country=Kiribati' ],
			} );
			const row = container.querySelector(
				'.event-logger-chart-controls'
			);
			expect( row ).not.toBeNull();
			expect( row.querySelector( 'select' ) ).not.toBeNull();
			expect(
				row.querySelector( 'input[aria-label="Filter"]' )
			).not.toBeNull();
			expect( row.textContent ).toContain( 'Country = Kiribati' );
			unmount();
		} );

		it( 'grows the filter box into what the selects leave of the row', () => {
			const { container, unmount } = mountBreakdown();
			const sheets = [
				compileLocal( 'settings/styles/tag-input.scss' ),
				compileLocal( 'overview/styles/base.scss' ),
			];
			const box = container.querySelector( '.event-logger-chip-filter' );
			const input = box.querySelector( 'input' );
			expect( resolveCascade( box, sheets ).flex ).toBe( '1 1 22em' );
			expect( resolveCascade( input, sheets )[ 'min-width' ] ).toBe(
				'14em'
			);
			unmount();
		} );

		it( 'shows each chip as its field and value', () => {
			const { container, unmount } = mountBreakdown( {
				chips: [ 'status_class=5', 'country=Unknown' ],
			} );
			expect( container.textContent ).toContain( 'Status Class = 5xx' );
			expect( container.textContent ).toContain( 'Country = Unknown' );
			unmount();
		} );

		it( 'adds a typed field=value, a status class read as the answer spells it', () => {
			const { container, props, unmount } = mountBreakdown( {
				chips: [ 'country=NZ' ],
			} );
			const box = chipBox( container );
			type( box, 'status_class=5xx' );
			press( box, 'Enter' );
			expect( props.setChips ).toHaveBeenCalledWith( [
				'country=NZ',
				'status_class=5',
			] );
			unmount();
		} );

		it( 'adds a field typed in any case as the field spells it', () => {
			const { container, props, unmount } = mountBreakdown( {
				chips: [ 'country=NZ' ],
			} );
			const box = chipBox( container );
			type( box, 'Country=SG' );
			press( box, 'Enter' );
			expect( props.setChips ).toHaveBeenCalledWith( [
				'country=NZ',
				'country=SG',
			] );
			unmount();
		} );

		it( 'adds a negated chip, its value read as the answer spells it', () => {
			const { container, props, unmount } = mountBreakdown( {
				chips: [ 'country!=NZ' ],
			} );
			const box = chipBox( container );
			type( box, 'Status_Class!=5xx' );
			press( box, 'Enter' );
			expect( props.setChips ).toHaveBeenCalledWith( [
				'country!=NZ',
				'status_class!=5',
			] );
			unmount();
		} );

		it( 'shows a negated chip as its field, not equal to its value', () => {
			const { container, unmount } = mountBreakdown( {
				chips: [ 'status_class!=5' ],
			} );
			expect( container.textContent ).toContain( 'Status Class ≠ 5xx' );
			unmount();
		} );

		it( 'refuses a negated chip with no field', () => {
			const { container, props, unmount } = mountBreakdown();
			const box = chipBox( container );
			type( box, '!=NZ' );
			press( box, 'Enter' );
			expect( props.setChips ).not.toHaveBeenCalled();
			expect( container.textContent ).toContain( 'field!=value' );
			unmount();
		} );

		it( 'ignores a chip it already holds', () => {
			const { container, props, unmount } = mountBreakdown( {
				chips: [ 'country=US' ],
			} );
			const box = chipBox( container );
			type( box, 'country=US' );
			press( box, 'Enter' );
			expect( props.setChips ).not.toHaveBeenCalled();
			unmount();
		} );

		it( 'keeps a value holding an equals sign whole', () => {
			const { container, props, unmount } = mountBreakdown();
			const box = chipBox( container );
			type( box, 'from=bot=kea@example.org' );
			press( box, 'Enter' );
			expect( props.setChips ).toHaveBeenCalledWith( [
				'from=bot=kea@example.org',
			] );
			unmount();
		} );

		it( 'suggests every chip field but the URL hash, which only a typed chip names', () => {
			const { container, unmount } = mountBreakdown();
			const suggested = [
				...container.querySelectorAll( 'datalist option' ),
			].map( ( option ) => option.getAttribute( 'value' ) );
			expect( suggested ).toContain( 'status_class=' );
			expect( suggested ).not.toContain( 'url=' );
			unmount();
		} );

		it( 'takes a typed url chip all the same', () => {
			const { container, props, unmount } = mountBreakdown();
			const box = chipBox( container );
			type( box, 'url=4be3cc0a7084' );
			press( box, 'Enter' );
			expect( props.setChips ).toHaveBeenCalledWith( [
				'url=4be3cc0a7084',
			] );
			unmount();
		} );

		it( 'refuses a field the chips do not offer, naming it', () => {
			const { container, props, unmount } = mountBreakdown( {
				chipOptions: FILTER_FIELD_OPTIONS.filter(
					( o ) => 'url' !== o.value
				),
			} );
			const box = chipBox( container );
			type( box, 'url=4be3cc0a1f2e' );
			press( box, 'Enter' );
			expect( props.setChips ).not.toHaveBeenCalled();
			expect( container.textContent ).toContain( 'No field url' );
			unmount();
		} );

		it( 'refuses a chip with no value', () => {
			const { container, props, unmount } = mountBreakdown();
			const box = chipBox( container );
			type( box, 'method' );
			press( box, 'Enter' );
			expect( props.setChips ).not.toHaveBeenCalled();
			expect( container.textContent ).toContain( 'field=value' );
			unmount();
		} );

		it( 'removes the chip whose × is pressed', () => {
			const { container, props, unmount } = mountBreakdown( {
				chips: [ 'status_class=5', 'method=PURGE' ],
			} );
			act( () => {
				container
					.querySelector(
						'button[title="Remove Status Class = 5xx"]'
					)
					.click();
			} );
			expect( props.setChips ).toHaveBeenCalledWith( [ 'method=PURGE' ] );
			unmount();
		} );
	} );

	it( 'hands the chart the click that narrows to a bucket', () => {
		const onSlotClick = jest.fn();
		const { unmount } = mountBreakdown( { onSlotClick } );
		expect( globalThis.__aggregateSlotClick ).toBe( onSlotClick );
		unmount();
	} );

	it( 'hands the chart the drag that selects a span of buckets', () => {
		const onSlotRange = jest.fn();
		const { unmount } = mountBreakdown( { onSlotRange } );
		expect( globalThis.__aggregateSlotRange ).toBe( onSlotRange );
		unmount();
	} );
} );
