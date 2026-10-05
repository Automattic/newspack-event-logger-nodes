import { __ } from '@wordpress/i18n';
import { formatGroupedCount } from '@newspack-nodes/shared/utils/formatters';

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
		// Only an errors-only reply counts them, and zero is a count.
		shown: ( n ) => 'number' === typeof n,
	},
	timeouts: {
		label: __( 'Timeouts', 'newspack-event-logger-nodes' ),
		short: __( 'timeouts', 'newspack-event-logger-nodes' ),
		format: formatGroupedCount,
	},
	fatals: {
		label: __( 'Fatals', 'newspack-event-logger-nodes' ),
		short: __( 'fatals', 'newspack-event-logger-nodes' ),
		format: formatGroupedCount,
	},
	fatal_avg_ms: {
		label: __( 'Avg Fatal Duration', 'newspack-event-logger-nodes' ),
		short: __( 'fatal avg', 'newspack-event-logger-nodes' ),
		format: wholeMs,
	},
	fatal_max_ms: {
		label: __( 'Max Fatal Duration', 'newspack-event-logger-nodes' ),
		short: __( 'fatal max', 'newspack-event-logger-nodes' ),
		format: wholeMs,
	},
	avg_ms: {
		label: __( 'Avg Response', 'newspack-event-logger-nodes' ),
		short: __( 'avg', 'newspack-event-logger-nodes' ),
		format: wholeMs,
	},
	requests_per_second: {
		label: __( 'Req/s (recent)', 'newspack-event-logger-nodes' ),
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
 * so they come from one payload rather than from whichever namespace happens
 * to hold each figure.
 *
 * @param {Object}      props
 * @param {Object|null} props.totals      The `urls` reply's totals; null until it answers.
 * @param {boolean}     props.provisional The reply's `provisional`: the totals are short of an hour or record the writer has yet to fold or rank, or of an index read that went unanswered.
 * @return {import('react').ReactElement} The stats grid.
 */
export default function HeadlineStats( { totals, provisional } ) {
	return (
		<>
			<div
				className="newspack-nodes-stats-grid event-logger-overview-stats"
				title={ __(
					'The current hour so far and the whole hours before it: the window starts on the hour.',
					'newspack-event-logger-nodes'
				) }
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
						<span className="newspack-nodes-stat-value">
							{ value }
						</span>
						<span className="newspack-nodes-stat-label">
							{ label }
						</span>
					</div>
				) ) }
			</div>
			{ provisional && (
				<p className="newspack-nodes-banner is-info" role="status">
					{ __(
						'Provisional: some hours or records are not yet folded or ranked, or a read went unanswered, so these totals may run short.',
						'newspack-event-logger-nodes'
					) }
				</p>
			) }
		</>
	);
}
