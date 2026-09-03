/**
 * The Performance dashboard's profile-category panel.
 *
 * D3 area charts of profile-category timings across the retention window, from
 * the `category_time_series` payload the `performance` CI merges out of
 * `Stats_Store`'s per-bucket category blobs — site-wide (or per-server) on
 * the overview, per-URL in the URL detail view. `AggregateTimeChart` plots
 * request-level metrics on the same `AreaTimeChart` frame; this one breaks the
 * window down by profile category.
 *
 * Each 5-minute bucket carries `{ t, c, n }` per category: `t` milliseconds of
 * wall time, `c` events fired, `n` requests the category appeared in. The panel
 * reads `t` and `c`, and one payload answers three questions, so it draws all
 * three — "time" (seconds of category time per second of clock), "count"
 * (events per second) and "average" (milliseconds per event).
 *
 * Areas overlay rather than stack, and the tooltip carries no total row,
 * because category times overlap: a callback's time counts inside its hook's,
 * so summing the bands double-counts, and an average never adds up at all.
 * Each band reads against the axis instead of against its neighbors.
 */

import { useCallback, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import {
	BUCKET_SECONDS,
	PALETTE,
	buildTimeSlots,
} from '@newspack-nodes/shared/hooks/useTimeChart';
import { compactFixed } from '@newspack-nodes/shared/utils/formatters';
import { hasBuckets } from './AggregateTimeChart';
import AreaTimeChart from './components/AreaTimeChart';
import { RETENTION_SECONDS } from './retention';

/**
 * Height of one chart frame, in pixels. Three of them stack in one panel, so
 * each is shorter than the single breakdown chart above them.
 */
const CHART_HEIGHT = 200;

/**
 * The three views the panel takes of one series, in render order. `mode` picks
 * both the sampler in `buildSeries` and the unit in `formatYValue`; `title` is
 * the heading, translated here because `AreaTimeChart` holds no wording of its
 * own.
 *
 * @type {Array<{mode: string, title: string}>}
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
 * duration (µs, ms, s), and "count" a frequency (/s, K/s). A zero prints bare,
 * because a unit on it says nothing. Serves both the axis ticks and the
 * tooltip rows.
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
			return `${ compactFixed( val * 1000000 ) }µs/s`;
		}
		if ( val < 1 ) {
			return `${ compactFixed( val * 1000 ) }ms/s`;
		}
		return `${ compactFixed( val ) }s/s`;
	}
	if ( mode === 'average' ) {
		if ( val < 1 ) {
			return `${ compactFixed( val * 1000 ) }µs`;
		}
		if ( val >= 1000 ) {
			return `${ compactFixed( val / 1000 ) }s`;
		}
		return `${ compactFixed( val ) }ms`;
	}
	if ( val >= 1000 ) {
		return `${ compactFixed( val / 1000 ) }K/s`;
	}
	return `${ compactFixed( val ) }/s`;
};

/**
 * Rank categories by their whole-window total, then sample each across it.
 *
 * The rank fixes palette index and legend order, and it runs over the mode's
 * own field — `c` for "count", `t` otherwise — so "average" ranks by total
 * time rather than by the mean it plots, and one slow outlier cannot take the
 * top band. That also makes the "count" ranking its own, so a category may
 * wear a different color there than in the other two views.
 *
 * The `total` pseudo-category is dropped: it carries the request's own wall
 * time rather than any category's, so as a band it would swamp every other one.
 *
 * Every series gets a point in every slot, zero where the bucket holds nothing,
 * because `AreaTimeChart` takes its x-domain from the first series alone and
 * reads the rest by that index.
 *
 * @param {Object} data Category series keyed by bucket — `{ bucket: { category: { t, c, n } } }`.
 * @param {string} mode One of 'time', 'count', or 'average'.
 * @return {Array<{label:string,values:Array<{date:Date,value:number}>}>} Series in rank order.
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
 * The three memos buy identity, not arithmetic: `AreaTimeChart` redraws
 * whenever `series`, `yFormat` or `colorAt` changes, and the URL modal
 * re-renders on every scroll event.
 *
 * @param {Object}      props      Component props.
 * @param {Object|null} props.data Category series keyed by bucket — `{ bucket: { category: { t, c, n } } }`, `t` in milliseconds.
 * @return {import('react').ReactElement[]|null} One chart per view, or null when data is empty.
 */
export default function CategoryTimeChart( { data } ) {
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
