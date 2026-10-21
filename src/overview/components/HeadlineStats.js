import { __, sprintf } from '@wordpress/i18n';
import { formatGroupedCount } from '@newspack-nodes/shared/utils/formatters';
import { slotSpan } from '../chartSlots';

/**
 * A duration in whole milliseconds.
 *
 * @param {number} n Milliseconds.
 * @return {string} `n` rounded, with its unit.
 */
export const wholeMs = ( n ) => `${ n.toFixed( 0 ) }ms`;

/**
 * Every headline number, in its display format and under its labels: the
 * Overview card's full `label`, and the `short` one the URL modal header uses.
 * `shown`, where a stat declares it, decides from the value whether the stat
 * appears at all.
 *
 * @type {Object<string,{label: string, short?: string, format: (n: number) => string, shown?: (n: *) => boolean}>}
 */
const STATS = {
	urls: {
		label: __( 'Unique URLs', 'newspack-event-logger-nodes' ),
		format: formatGroupedCount,
	},
	requests: {
		label: __( 'Total Requests', 'newspack-event-logger-nodes' ),
		format: formatGroupedCount,
	},
	errors: {
		label: __( 'Total Errors', 'newspack-event-logger-nodes' ),
		short: __( 'errors', 'newspack-event-logger-nodes' ),
		format: formatGroupedCount,
	},
	avg_ms: {
		label: __( 'Avg Response', 'newspack-event-logger-nodes' ),
		short: __( 'avg', 'newspack-event-logger-nodes' ),
		format: wholeMs,
	},
	error_rate: {
		label: __( 'Error Rate', 'newspack-event-logger-nodes' ),
		short: __( 'err', 'newspack-event-logger-nodes' ),
		format: ( n ) => `${ ( n * 100 ).toFixed( 2 ) }%`,
	},
	p50_ms: {
		label: __( 'p50 Response', 'newspack-event-logger-nodes' ),
		short: __( 'p50', 'newspack-event-logger-nodes' ),
		format: wholeMs,
	},
	p95_ms: {
		label: __( 'p95 Response', 'newspack-event-logger-nodes' ),
		short: __( 'p95', 'newspack-event-logger-nodes' ),
		format: wholeMs,
	},
	p99_ms: {
		label: __( 'p99 Response', 'newspack-event-logger-nodes' ),
		short: __( 'p99', 'newspack-event-logger-nodes' ),
		format: wholeMs,
	},
	requests_per_second: {
		label: __( 'Req/s', 'newspack-event-logger-nodes' ),
		short: __( 'req/s', 'newspack-event-logger-nodes' ),
		format: ( n ) => n.toFixed( 2 ),
	},
	avg_peak_mb: {
		label: __( 'Avg Peak Memory', 'newspack-event-logger-nodes' ),
		short: __( 'mem', 'newspack-event-logger-nodes' ),
		format: ( n ) => `${ n.toFixed( 1 ) }MB`,
		// Absent on installs that do not sample peak memory.
		shown: ( n ) => n > 0,
	},
};

/**
 * The named stats of a totals block, formatted, in the order asked.
 *
 * A number that has not arrived renders as absent, because a plausible zero
 * beside a real total reads as a measurement.
 *
 * @param {?Object}  totals Totals keyed as STATS is; null until they answer.
 * @param {string[]} keys   Which stats, in display order.
 * @return {Array<{key: string, label: string, short?: string, value: string}>} One entry per stat shown.
 */
export function headlineStats( totals, keys ) {
	return keys
		.filter(
			( key ) =>
				! STATS[ key ].shown || STATS[ key ].shown( totals?.[ key ] )
		)
		.map( ( key ) => ( {
			key,
			label: STATS[ key ].label,
			short: STATS[ key ].short,
			value:
				'number' === typeof totals?.[ key ]
					? STATS[ key ].format( totals[ key ] )
					: '—',
		} ) );
}

/**
 * The headline numbers for a URL set, as the `urls` verb totals it. They
 * describe the set the filters selected, the same set the table below lists,
 * so they come from one payload rather than from whichever source happens
 * to hold each figure. Every count is exact, the unique URLs among them.
 * The caption names the span the chart's answer read, the slots the
 * table's selection is held to, and is absent until it answers.
 *
 * @param {Object}      props
 * @param {Object|null} props.totals The `urls` reply's totals; null until it answers.
 * @param {Array|null}  props.slots  The chart answer's slots; null before it answers.
 * @return {import('react').ReactElement} The stats grid.
 */
export default function HeadlineStats( { totals, slots } ) {
	return (
		<div
			className="newspack-nodes-stats-grid event-logger-overview-stats"
			title={
				slots
					? sprintf(
							// translators: %s: the span the window covers, e.g. 2 hours.
							__(
								'The last %s in five-minute buckets, the current one so far.',
								'newspack-event-logger-nodes'
							),
							slotSpan( slots )
					  )
					: undefined
			}
		>
			{ headlineStats( totals, [
				'urls',
				'requests',
				'errors',
				'avg_ms',
				'requests_per_second',
				'avg_peak_mb',
			] ).map( ( { key, label, value } ) => (
				<div className="newspack-nodes-stat" key={ key }>
					<span className="newspack-nodes-stat-value">{ value }</span>
					<span className="newspack-nodes-stat-label">{ label }</span>
				</div>
			) ) }
		</div>
	);
}
