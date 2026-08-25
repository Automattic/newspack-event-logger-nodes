/**
 * Category Time Chart Component
 *
 * D3 overlaid-area chart of profile-category timings across the retention
 * window. The series come from the `category_time_series` payload the
 * `performance` CI merges out of `Stats_Store`'s per-bucket category blobs —
 * site-wide (or per-server) on the overview, per-URL in the URL detail view.
 *
 * Each 5-minute bucket carries `{ t, c }` per category: `t` milliseconds of
 * wall time, `c` events. One payload answers three questions, so the panel
 * draws all three — "time" (seconds of category time per second of clock),
 * "count" (events per second), and "average" (milliseconds per event). Areas
 * overlay rather than stack, so each band reads against the axis instead of
 * against its neighbors.
 *
 * The sibling `AggregateTimeChart` plots request-level metrics on the same
 * `AreaTimeChart` frame; this one breaks the window down by profile category.
 */

import { useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	BUCKET_SECONDS,
	PALETTE,
	buildTimeSlots,
} from '@newspack-nodes/shared/hooks/useTimeChart';
import { hasBuckets } from './AggregateTimeChart';
import AreaTimeChart from './components/AreaTimeChart';
import { RETENTION_SECONDS } from './retention';

const CHART_HEIGHT = 200;

/**
 * The three views the panel takes of one series, in render order.
 */
const CATEGORY_VIEWS = [
	{
		mode: 'time',
		title: __( 'Time by Category', 'newspack-event-logger-nodes' ),
	},
	{
		mode: 'count',
		title: __( 'Events by Category', 'newspack-event-logger-nodes' ),
	},
	{
		mode: 'average',
		title: __( 'Average Time per Event', 'newspack-event-logger-nodes' ),
	},
];

/**
 * Format a Y-axis value in the unit its mode implies.
 *
 * The unit is mode-specific: "time" is a rate (µs/s, ms/s, s/s), "average" a
 * duration (µs, ms, s), and "count" a frequency (/s, K/s). Serves both the
 * axis ticks and the tooltip rows.
 *
 * @param {number} val  Value in the mode's native unit — seconds per second for 'time', milliseconds for 'average', events per second for 'count'.
 * @param {string} mode One of 'time', 'average', or 'count'.
 * @return {string} Formatted label.
 */
const formatYValue = ( val, mode ) => {
	if ( val === 0 ) {
		return '0';
	}
	if ( mode === 'time' ) {
		if ( val < 0.001 ) {
			return `${ ( val * 1000000 ).toFixed( 0 ) }µs/s`;
		}
		if ( val < 1 ) {
			return `${ ( val * 1000 ).toFixed( 0 ) }ms/s`;
		}
		return `${ val.toFixed( 1 ) }s/s`;
	}
	if ( mode === 'average' ) {
		if ( val < 1 ) {
			return `${ ( val * 1000 ).toFixed( 0 ) }µs`;
		}
		if ( val >= 1000 ) {
			return `${ ( val / 1000 ).toFixed( 1 ) }s`;
		}
		return `${ val.toFixed( 0 ) }ms`;
	}
	if ( val >= 1000 ) {
		return `${ ( val / 1000 ).toFixed( 1 ) }K/s`;
	}
	return `${ val.toFixed( val >= 10 ? 0 : 1 ) }/s`;
};

/**
 * Rank categories by their whole-window total, then sample each across it.
 *
 * @param {Object} data Category series keyed by bucket.
 * @param {string} mode 'time' | 'count' | 'average'.
 * @return {Array} Series in rank order, each `{ label, values }`.
 */
const buildSeries = ( data, mode ) => {
	const totals = {};
	Object.values( data ).forEach( ( bucket ) => {
		Object.entries( bucket ).forEach( ( [ cat, stats ] ) => {
			if ( 'total' === cat ) {
				return;
			}
			const val = mode === 'count' ? stats.c || 0 : stats.t || 0;
			totals[ cat ] = ( totals[ cat ] || 0 ) + val;
		} );
	} );
	const categories = Object.keys( totals ).sort(
		( a, b ) => totals[ b ] - totals[ a ]
	);
	const slots = buildTimeSlots( RETENTION_SECONDS );

	return categories.map( ( cat ) => ( {
		label: cat,
		values: slots.map( ( slot ) => {
			const stats = data[ slot.bucketKey ]?.[ cat ];
			if ( ! stats ) {
				return { date: slot.date, value: 0 };
			}
			let value;
			if ( mode === 'average' ) {
				value = stats.c > 0 ? stats.t / stats.c : 0;
			} else if ( mode === 'time' ) {
				value = stats.t / 1000 / BUCKET_SECONDS;
			} else {
				value = stats.c / BUCKET_SECONDS;
			}
			return { date: slot.date, value };
		} ),
	} ) );
};

/**
 * Category time charts — one per view over the same category series.
 *
 * @param {Object}      props      Component props.
 * @param {Object|null} props.data Category series keyed by bucket — `{ bucket: { category: { t, c } } }`, `t` in milliseconds.
 * @return {import('react').ReactElement[]|null} One chart per view, or null when data is empty.
 */
export default function CategoryTimeChart( { data } ) {
	// Ranking sums the whole window, so it drives palette and legend order.
	const series = useMemo(
		() =>
			CATEGORY_VIEWS.map( ( { mode } ) =>
				data ? buildSeries( data, mode ) : []
			),
		[ data ]
	);

	const yFormats = useMemo(
		() =>
			CATEGORY_VIEWS.map(
				( { mode } ) =>
					( val ) =>
						formatYValue( val, mode )
			),
		[]
	);

	// The palette cycles past 20 categories.
	const colorAt = useCallback(
		( _label, index ) => PALETTE[ index % PALETTE.length ],
		[]
	);

	// The empty check sits below every hook; hoisting it breaks hook order.
	if ( ! hasBuckets( data ) ) {
		return null;
	}

	return CATEGORY_VIEWS.map( ( { mode, title }, index ) => (
		<AreaTimeChart
			key={ mode }
			series={ series[ index ] }
			colorAt={ colorAt }
			yFormat={ yFormats[ index ] }
			title={ title }
			height={ CHART_HEIGHT }
		/>
	) );
}
