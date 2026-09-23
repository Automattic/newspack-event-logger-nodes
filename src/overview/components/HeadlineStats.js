import { __ } from '@wordpress/i18n';

/**
 * Every headline number, in its display format and under its labels: the
 * Overview card's full `label`, and the `short` one the URL modal header uses.
 *
 * @type {Object<string,{label: string, short?: string, format: (n: number) => string, onlyWhenPositive?: boolean}>}
 */
const STATS = {
	urls: {
		label: __( 'Unique URLs', 'newspack-event-logger-nodes' ),
		format: ( n ) => n.toLocaleString(),
	},
	requests: {
		label: __( 'Total Requests', 'newspack-event-logger-nodes' ),
		format: ( n ) => n.toLocaleString(),
	},
	errors: {
		label: __( 'Total Errors', 'newspack-event-logger-nodes' ),
		format: ( n ) => n.toLocaleString(),
		// Only an errors-only reply counts them.
		onlyWhenPositive: true,
	},
	avg_ms: {
		label: __( 'Avg Response', 'newspack-event-logger-nodes' ),
		short: __( 'avg', 'newspack-event-logger-nodes' ),
		format: ( n ) => `${ n.toFixed( 0 ) }ms`,
	},
	requests_per_second: {
		label: __( 'Req/s (last hour)', 'newspack-event-logger-nodes' ),
		short: __( 'req/s', 'newspack-event-logger-nodes' ),
		format: ( n ) => n.toFixed( 2 ),
	},
	avg_peak_mb: {
		label: __( 'Avg Peak Memory', 'newspack-event-logger-nodes' ),
		short: __( 'mem', 'newspack-event-logger-nodes' ),
		format: ( n ) => `${ n.toFixed( 1 ) }MB`,
		// Absent on installs that do not sample peak memory.
		onlyWhenPositive: true,
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
			( key ) => ! STATS[ key ].onlyWhenPositive || totals?.[ key ] > 0
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
 * @param {Object|null} props.totals The `urls` reply's totals; null until it answers.
 * @return {import('react').ReactElement} The stats grid.
 */
export default function HeadlineStats( { totals } ) {
	return (
		<div className="newspack-nodes-stats-grid event-logger-overview-stats">
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
