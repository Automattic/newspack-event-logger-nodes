/**
 * The questions the Performance page's sources ask the `performance` CI, each
 * a pure function from a source's params to its command tokens: the
 * overview, a URL-table page or an expanded page's shapes, a chart's series,
 * a category chart's totals, the URL window's `dump_url` and a request's
 * `dump_request`.
 */

import { formatCommandArgs } from '@newspack-nodes/runtime';
import { chartDims, chipParts, filterTokens } from './chartSlots';
import { subjectArgs } from './detailSubject';
import {
	CATEGORY_MEASURES,
	CHART_TOP,
	SERIES_MEASURES,
	SHAPES_PER_ASK,
} from './constants';

/** The rows one `urls` ask for the table lists: `UrlTable`'s page size. */
export const URLS_PER_ASK = 100;

/**
 * The `--filter=` values a chart asks under: its scope's, unless a chip names
 * the same field, then its chips'. A chip on `worker`, negated or not,
 * therefore replaces the Overview's `worker=0` rather than widening it.
 *
 * @param {Object<string,string[]>} scope Field => the values the scope asks.
 * @param {string[]}                chips The chips, each `field=value` or `field!=value`.
 * @return {string[]} Each filter as `field=value`.
 */
const filtersOf = ( scope, chips ) => {
	const chipped = new Set( chips.map( ( chip ) => chipParts( chip ).field ) );
	return [
		...Object.entries( scope )
			.filter( ( [ field ] ) => ! chipped.has( field ) )
			.flatMap( ( [ field, values ] ) =>
				values.map( ( value ) => `${ field }=${ value }` )
			),
		...chips,
	];
};

/**
 * A chart's `query` tokens: its source, the bucket axis and its dimensions
 * as the group, each filter and measure as its own token, and the top
 * `CHART_TOP` tuples by `topBy`.
 *
 * @param {string}   source   `requests` or `timings`.
 * @param {string[]} dims     The non-time fields it groups by.
 * @param {string[]} filters  Each filter as `field=value`.
 * @param {string[]} measures What each row measures.
 * @param {string}   topBy    The measure the tuples rank by.
 * @return {string[]} The command token array.
 */
const queryArgs = ( source, dims, filters, measures, topBy ) => [
	...formatCommandArgs( [ source, 'bucket', ...dims ], {
		top: CHART_TOP,
		top_by: topBy,
	} ),
	...filterTokens( filters ),
	...measures.map( ( measure ) => `--measure=${ measure }` ),
];

/**
 * The scope a chart asks under. A site chart asks no worker request, a
 * subject's chart its URL or page with worker traffic counted as `dump_url`
 * counts it, and either the server's alone under a server filter.
 *
 * @param {Object}  params           The source's params.
 * @param {string}  params.server    Server scope; '' means every server.
 * @param {?Object} [params.subject] The URL window's `{ field, hash }`.
 * @return {Object<string,string[]>} Field => values.
 */
const scopeOf = ( { server, subject = null } ) => ( {
	...( subject
		? { [ subject.field ]: [ subject.hash ] }
		: { worker: [ '0' ] } ),
	...( server ? { server: [ server ] } : {} ),
} );

/**
 * A request chart's question: its dimensions' series by request count.
 *
 * @param {Object}   params           The source's params.
 * @param {string}   params.server    Server scope; '' means every server.
 * @param {?Object}  [params.subject] The URL window's `{ field, hash }`.
 * @param {string}   params.breakdown Its first dimension.
 * @param {string}   params.then      Its second; '' for none.
 * @param {string[]} params.chips     Its chips.
 * @return {string[]} The command token array.
 */
export const chartArgs = ( params ) =>
	queryArgs(
		'requests',
		chartDims( { breakdown: params.breakdown, thenBy: params.then } ),
		filtersOf( scopeOf( params ), params.chips ),
		SERIES_MEASURES,
		'count'
	);

/**
 * A category chart's question: each category's own total row by bucket —
 * the row whose `entry` is empty — the dearest first.
 *
 * @param {Object} params The source's params: its `server` and `subject`.
 * @return {string[]} The command token array.
 */
export const categoryArgs = ( params ) =>
	queryArgs(
		'timings',
		[ 'category' ],
		filtersOf( { ...scopeOf( params ), entry: [ '' ] }, [] ),
		CATEGORY_MEASURES,
		'ms'
	);

/**
 * The `overview` args: the server the totals and the leaderboard scope to.
 *
 * @param {{server: string}} params The source's params.
 * @return {string[]} The command token array.
 */
export const overviewArgs = ( { server } ) =>
	formatCommandArgs( [], server ? { server } : {} );

/**
 * The `urls` args. One command answers for the table and for the headline
 * totals the stats read from it, so the scope reaches both or the two
 * contradict each other. A table page asks `URLS_PER_ASK` rows from its
 * `paged`; an expanded page's shapes name the page by `--page` and ask
 * `SHAPES_PER_ASK` from the `offset` its window stands at.
 *
 * `include_workers` widens a set the command excludes by default, because a
 * long-running job would otherwise dominate every average on the page;
 * `bucket` narrows to a selection of five-minute buckets by its spelling;
 * the chips narrow it too, one `--filter=` each, so the table and its
 * totals describe the traffic the chart draws.
 *
 * @param {Object}   params          The source's params.
 * @param {string}   params.server   Server scope; '' means every server.
 * @param {string[]} params.chips    The chart's chips, each `field=value`.
 * @param {string}   params.buckets  The bucket selection's spelling; '' for none.
 * @param {string}   params.sort     Sort field.
 * @param {string}   params.order    Sort direction.
 * @param {string}   params.q        Whole-word URL search; '' for none.
 * @param {boolean}  params.workers  Whether worker traffic counts.
 * @param {number}   params.paged    The 1-based table page.
 * @param {string}   [params.page]   The page whose shapes to list, by hash.
 * @param {number}   [params.offset] The first of those shapes.
 * @return {string[]} The command token array.
 */
export function urlsArgs( params ) {
	const shapes = undefined !== params.page;
	const offset = shapes ? params.offset : ( params.paged - 1 ) * URLS_PER_ASK;
	const options = {};
	if ( shapes ) {
		options.page = params.page;
	}
	options.sort = params.sort;
	options.order = params.order;
	options.limit = shapes ? SHAPES_PER_ASK : URLS_PER_ASK;
	if ( offset ) {
		options.offset = offset;
	}
	if ( params.q ) {
		options.search = params.q;
	}
	if ( params.server ) {
		options.server = params.server;
	}
	if ( params.workers ) {
		options.include_workers = '1';
	}
	if ( params.buckets ) {
		options.bucket = params.buckets;
	}
	return [
		...formatCommandArgs( [], options ),
		...filterTokens( params.chips ),
	];
}

/**
 * `dump_url` args for the open window: its subject, the server filter its row
 * was scoped by, and the window's own bucket selection and chips.
 *
 * @param {Object}   params         The source's params.
 * @param {Object}   params.subject The window's `{ field, hash }`.
 * @param {string}   params.server  Server scope; '' means every server.
 * @param {string}   params.buckets The window's bucket selection; '' for none.
 * @param {string[]} params.chips   The window chart's chips.
 * @return {string[]} The command token array.
 */
export function detailArgs( { subject, server, buckets, chips } ) {
	const options = {};
	if ( server ) {
		options.server = server;
	}
	if ( buckets ) {
		options.bucket = buckets;
	}
	return [ ...subjectArgs( subject, options ), ...filterTokens( chips ) ];
}

/**
 * Whether a URL or page hash may go out as a `dump_url` token. A selection
 * arrives from a deep link as readily as from a clicked row, so it is user
 * input, and one that fails here shows the window's error instead.
 *
 * @param {*} h The candidate hash.
 * @return {boolean} Whether it is a hex string.
 */
export const isValidHash = ( h ) =>
	'string' === typeof h && /^[a-f0-9]+$/.test( h );

/**
 * Whether a request id may go out as a `dump_request` token, on the same
 * reasoning as `isValidHash`.
 *
 * @param {*} r The candidate rid.
 * @return {boolean} Whether it is alphanumeric with `_` and `-`.
 */
export const isValidRequestId = ( r ) =>
	'string' === typeof r && /^[a-zA-Z0-9_-]+$/.test( r );
