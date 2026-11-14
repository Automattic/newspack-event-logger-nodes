/* eslint-env jest */
/**
 * The Performance page's server, as the fake command wire answers it: one
 * `overview`, one `urls` and the two `query` charts, each seeded with values
 * no fallback holds, so a derived total reads non-zero and every chart draws
 * points. `replyFor()` answers a command by its name and, for `query`, by the
 * source its first token names.
 */

import { VALUE } from '@newspack-nodes/runtime';
import { seriesWire } from './chartWire';
import { CATEGORY_MEASURES, SERIES_MEASURES } from '../overview/constants';

/** The page's clock: two minutes past the newest charted bucket. */
export const NOW_MS = Date.UTC( 2026, 8, 29, 14, 42 );

/** The two servers the overview names. */
export const SERVERS = [ 'kea-7911.example', 'tui-7912.example' ];

/** What `overview` answers: its servers, leaderboard and owners. */
export const OVERVIEW = {
	servers: SERVERS,
	leaderboard: {
		categories: {
			db: { time: 95.1, count: 3 },
			hooks: { time: 40.5, count: 7 },
		},
		total_time: 135.6,
		count: 41,
		avg_ms: 317,
	},
	owners: [
		{
			owner: 'plugin:heron-7913',
			ms_per_request: 187.5,
			share: 0.424,
			calls_per_request: 41,
		},
		{
			owner: 'theme:kea-7914',
			ms_per_request: 12.3,
			share: 0.028,
			calls_per_request: 3,
		},
	],
};

/**
 * One page row of one shape, as `urls` lists it.
 *
 * @param {{hash: string, path: string, server?: string} & Object<string, *>} row Its hash, its path under its server, its server, the first named unless given, and its measures.
 * @return {Object} The row.
 */
const page = ( { hash, path, server = SERVERS[ 0 ], ...measures } ) => ( {
	page_hash: `${ hash }0`,
	page: `${ server }${ path }`,
	server,
	shapes: 1,
	hash,
	url: `https://${ server }${ path }`,
	last_updated: NOW_MS / 1000 - 75,
	...measures,
} );

/** The rows `urls` lists. */
export const URL_ROWS = [
	page( {
		hash: 'a17e',
		path: '/piwakawaka-7915/',
		count: 137,
		count_2xx: 120,
		count_4xx: 17,
		avg_ms: 212,
		min_ms: 31,
		max_ms: 904,
		avg_peak_mb: 6.5,
	} ),
	page( {
		hash: 'b28f',
		path: '/ruru-7916/',
		server: SERVERS[ 1 ],
		count: 1,
		count_2xx: 1,
		avg_ms: 88,
		min_ms: 88,
		max_ms: 88,
		avg_peak_mb: 3.5,
	} ),
];

/** What `urls` answers: the rows and the set's own totals. */
export const URLS = {
	data: URL_ROWS,
	rows: 2,
	totals: {
		urls: 2,
		requests: 138,
		errors: 17,
		avg_ms: 211.4,
		error_rate: 0.1232,
		p50_ms: 140,
		p95_ms: 700,
		p99_ms: 880,
		requests_per_second: 0.46,
		avg_peak_mb: 6.4,
	},
	slowest: [ URL_ROWS[ 0 ] ],
	filters: { bucket: '', filter: [], search: '', server: '' },
	limit: 100,
	offset: 0,
};

/** The aggregate chart's series, by server. */
export const CHART = seriesWire( 'server', SERIES_MEASURES, {
	'2026-09-29-14-30': {
		[ SERVERS[ 0 ] ]: [ 61, 12200, 390, 61 ],
		[ SERVERS[ 1 ] ]: [ 9, 790, 31, 9 ],
	},
	'2026-09-29-14-35': {
		[ SERVERS[ 0 ] ]: [ 76, 16800, 480, 76 ],
		[ SERVERS[ 1 ] ]: [ 4, 360, 14, 4 ],
	},
} );

/** The category chart's series. */
export const CATEGORIES = {
	...seriesWire( 'category', CATEGORY_MEASURES, {
		'2026-09-29-14-30': { db: [ 2900, 61 ], hooks: [ 1240, 300 ] },
		'2026-09-29-14-35': { db: [ 3300, 76 ], hooks: [ 1520, 380 ] },
	} ),
	source: 'timings',
	table: 'timings',
};

/**
 * The value of a `--name=` token among a command's arguments.
 *
 * @param {string[]} args The arguments.
 * @param {string}   name The option.
 * @return {?string} Its value; null when absent.
 */
export const option = ( args, name ) => {
	const token = ( args ?? [] ).find( ( one ) =>
		one.startsWith( `--${ name }=` )
	);
	return token ? token.slice( name.length + 3 ) : null;
};

/**
 * Every `--filter=` value among a command's arguments.
 *
 * @param {string[]} args The arguments.
 * @return {string[]} The filters.
 */
export const filtersIn = ( args ) =>
	( args ?? [] )
		.filter( ( one ) => one.startsWith( '--filter=' ) )
		.map( ( one ) => one.slice( 9 ) );

/**
 * The seeded answer to one command, by its name and, for `query`, its
 * source; undefined for a command this server does not answer.
 *
 * @param {Array} m A posted command Message.
 * @return {*} The answer.
 */
export function replyFor( m ) {
	const { name, arguments: args = [] } = m[ VALUE ] ?? {};
	if ( 'query' === name ) {
		return 'timings' === args[ 0 ] ? CATEGORIES : CHART;
	}
	return { overview: OVERVIEW, urls: URLS }[ name ];
}

/**
 * The commands a wire carried, as `{ name, args }`.
 *
 * @param {{batches: Array<Array<Array>>}} wire The fake wire.
 * @param {string}                         name The command.
 * @return {Array<string[]>} Each one's arguments, oldest first.
 */
export const sentOn = ( wire, name ) =>
	wire.batches
		.flat()
		.filter( ( m ) => name === m[ VALUE ]?.name )
		.map( ( m ) => m[ VALUE ].arguments ?? [] );
