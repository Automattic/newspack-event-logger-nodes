/* global globalThis */
/**
 * Tests for the time charts' plain click: the substrate's `AreaTimeChart`
 * reports the clicked slot's index into its first series, and each chart maps
 * that index to a bucket key through the axis it drew the series on.
 *
 * `AreaTimeChart` is mocked to record its props, so these tests call the
 * `onSlotClick` it was handed exactly as the frame would.
 */

jest.mock( '@newspack-nodes/shared/components/AreaTimeChart', () => ( {
	__esModule: true,
	default: ( props ) => {
		( globalThis.__areaCharts ??= [] ).push( props );
		return null;
	},
} ) );

import * as React from 'react';
import AggregateTimeChart from '../AggregateTimeChart';
import CategoryTimeChart from '../CategoryTimeChart';
import { renderComponent } from '../../test-helpers/renderHook';
import {
	dimTable,
	nameTable,
	slotsEndingAt,
} from '../../test-helpers/chartWire';

// Four slots, newest first, as the reply names them.
const SLOTS = slotsEndingAt( '2026-10-04-13-35', 4 );

const charts = () => globalThis.__areaCharts ?? [];

beforeEach( () => {
	globalThis.__areaCharts = [];
} );

describe( 'AggregateTimeChart onSlotClick', () => {
	const series = dimTable( {
		'2026-10-04-13-30': { '5xx': [ 7, 70, 3, 7 ] },
	} );

	it( 'hands back the bucket key of the slot clicked', () => {
		const onSlotClick = jest.fn();
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, {
				series,
				slots: SLOTS,
				onSlotClick,
			} )
		);
		charts().at( -1 ).onSlotClick( 3 );
		charts().at( -1 ).onSlotClick( 0 );
		expect( onSlotClick.mock.calls ).toEqual( [
			[ '2026-10-04-13-35' ],
			[ '2026-10-04-13-20' ],
		] );
		unmount();
	} );

	it( 'gives the frame no click to report without a callback', () => {
		const { unmount } = renderComponent(
			React.createElement( AggregateTimeChart, { series, slots: SLOTS } )
		);
		expect( charts().at( -1 ).onSlotClick ).toBeUndefined();
		unmount();
	} );
} );

describe( 'CategoryTimeChart onSlotClick', () => {
	const data = nameTable( {
		'2026-10-04-13-25': { plugins: [ 120, 9, 3 ] },
	} );

	it( 'hands every view the same slot-to-bucket mapping', () => {
		const onSlotClick = jest.fn();
		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, {
				data,
				slots: SLOTS,
				onSlotClick,
			} )
		);
		expect( charts() ).toHaveLength( 3 );
		charts().forEach( ( chart ) => chart.onSlotClick( 1 ) );
		expect( onSlotClick.mock.calls ).toEqual( [
			[ '2026-10-04-13-25' ],
			[ '2026-10-04-13-25' ],
			[ '2026-10-04-13-25' ],
		] );
		unmount();
	} );

	it( 'gives the frames no click to report without a callback', () => {
		const { unmount } = renderComponent(
			React.createElement( CategoryTimeChart, { data, slots: SLOTS } )
		);
		charts().forEach( ( chart ) =>
			expect( chart.onSlotClick ).toBeUndefined()
		);
		unmount();
	} );
} );
