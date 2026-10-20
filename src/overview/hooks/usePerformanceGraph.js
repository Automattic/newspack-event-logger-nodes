/**
 * usePerformanceGraph — the Performance Dashboard's data layer, expressed as a
 * node graph on the substrate batched-poll toolkit (`useBatchedPoll` +
 * `addSliceFetcher`). This hook owns the dashboard's slices;
 * `PerformanceDashboard` reads each one back through `useNodeField` rather than
 * fetching it.
 *
 * POLLED slices. `useBatchedPoll` owns the Timer, the Tee, the group Taps,
 * `_http` and the page-visibility gate. It brackets nothing itself: the Router
 * owns the lock and flush around a tick, and that is what puts one tick into
 * one POST:
 *
 *   performance:timer (Timer) → performance:tee (Tee)
 *     → overview:fetch, urls:fetch, chart:fetch, categories:fetch (Fetchers)
 *     → overview:shell/_http/performance
 *   <slice>:in (Tee) → <slice>:in:current (Current) → <slice>:view
 *
 * `overview` and `urls` answer the headline and the table; `chart` and
 * `categories` each ask `query` for one chart's series, the top `CHART_TOP`
 * tuples by request count or by milliseconds, so a chart is bounded however
 * many values its dimensions hold. Each Fetcher carries an `argsFn` fire-time
 * getter that reads the CURRENT React UI state, so a filter, sort, or page
 * change rides the very next tick without re-wiring the graph. A
 * `serverFilter` change asks all four afresh, superseding the asks in flight,
 * and fires the batched tick at once rather than waiting out the cadence; a
 * chart dimension change asks the chart alone, and a chip change the chart
 * and `urls`, whose rows and header the chips narrow too.
 *
 * An expanded page adds one more polled slice, `shapes:<page key>`, asking
 * `urls --filter=page=<page hash>` on the page's own server under the
 * table's question, so its shape rows describe the traffic its page row
 * folds, `SHAPES_PER_ASK` at a time from the offset the table reports for the
 * shapes in view. It hangs off `performance:tee` while the page is expanded
 * and listed, and is asked afresh when the table's question or that offset
 * moves.
 *
 * ON-DEMAND slices. Opening a modal fetches; neither modal hangs off
 * `performance:timer`, and the overview poll pauses while either is open:
 *
 *   url-detail:timer (Timer) → url-detail:tee (Tee)
 *     → url-detail:fetch (dump_url), url-chart:fetch, url-categories:fetch
 *     → url:shell/_http/performance
 *   request-detail:timer (Timer) → request-detail:fetch → request:shell/_http/performance
 *
 * Every `url-detail:timer` tick asks `dump_url` and the URL's two charts
 * again, and each answer replaces its slice whole. Opening the modal or
 * rescoping it asks all three through `askNow()`, superseding every older
 * ask; editing its buckets asks the list alone, since the charts describe
 * every request to the URL; a modal chart's dimension change asks that chart
 * alone, and its chip change that chart and `dump_url`, whose header and list
 * the chips narrow too. Every slice's `Current` gate, the
 * substrate's, drops the answer to a superseded question before it reaches a
 * view, and sends a refusal to the view itself. `url-detail:timer` is armed
 * only while URL detail is the visible modal and the tab is visible, and
 * polls at once each time it is armed from stopped (`armTimer()`). A request
 * selection asks `dump_request` through `request-detail:fetch` the same way,
 * so the late answer about the request the operator left never lands.
 *
 * ON-DEMAND verbs are NOT here. The deep-link reads, the search box, the rules
 * writes and the grep each live beside the state their reply sets, in
 * `PerformanceDashboard`, because a command held one layer above the state it
 * feeds has to hand its answer back down, and that hand-back is what a
 * correlation table is made of.
 *
 * This hook returns `handleUrlParamsChange` and nothing else; data reaches
 * React through each slice's own `useNodeField( '<slice>:view', 'view' )`.
 */

import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import {
	Core,
	formatCommandArgs,
	useGraphGeneration,
} from '@newspack-nodes/runtime';

import { controlMsg } from '@newspack-nodes/shared/helpers/controlMsg';
import { useBatchedPoll } from '@newspack-nodes/shared/hooks/useBatchedPoll';
import { addSliceFetcher } from '@newspack-nodes/shared/helpers/addSliceFetcher';
import {
	armTimer,
	keepUnsignedDue,
} from '@newspack-nodes/shared/helpers/armTimer';
import usePageVisibility from '@newspack-nodes/shared/hooks/usePageVisibility';
import { views } from '../nodes/register';
import { chartDims, chipParts, filterTokens } from '../chartSlots';
import { useOnChange } from './useOnChange';
import { egressPath } from '@newspack-nodes/shared/helpers/egressPath';
import {
	CATEGORY_MEASURES,
	CHART_TOP,
	GROUPS,
	SERIES_MEASURES,
	SHAPES_PER_ASK,
	pageKey,
	shapesSubject,
} from '../constants';

/** @typedef {import('@newspack-nodes/runtime').TimerNode} TimerNode */
/** @typedef {typeof import('@newspack-nodes/runtime').Node} NodeClass */

/**
 * The server CI mount that owns every verb this dashboard sends.
 *
 * Exported because `PerformanceDashboard` and `UrlDetailView` send their own
 * one-shot commands to the same CI through `useCommandOnce`.
 */
export const SERVER = 'performance';

/**
 * The ruleset CI reached by the inline rule editor's `dump`, `upsert` and
 * `delete`. Exported for the same reason `SERVER` is.
 */
export const RULES_CI = 'rules';

/** The egress the two polled slices share: `overview` and `urls`. */
const OVERVIEW_TARGET = egressPath( GROUPS.overview, SERVER );

/**
 * The cadence a caller passing no `refreshInterval` polls at, and the fallback
 * for a setting `parseInt` cannot read.
 */
const DEFAULT_REFRESH_INTERVAL_MS = 15000;

/**
 * The `retry_after_s` of a stats slice: 0, so an unanswered ask is never sent
 * again, and a question answering live in tens of seconds, a search or a
 * chipped page, runs once rather than once a poll. The ask lapses at the
 * Fetcher's 120 s ask expiry, and the poll that retires it asks again. A POST
 * that fails settles as an error, since a slice that never re-asks has no ask
 * to re-arm, and the next poll asks again.
 */
const STATS_NO_REASK = 0;

/**
 * Matched-request cap `PerformanceDashboard` sends with `grep_requests`. It
 * matches the verb's own default, and the server clamps anything larger to 50.
 */
export const GREP_RESULT_LIMIT = 20;

/**
 * One slice as `addSliceFetcher` takes it, less its tick and its egress: its
 * node names, each `<subject>:<role>`, the origin its view takes controls
 * from — its own name — and the verb its question asks.
 *
 * @param {string}          subject   The slice, as `chart`.
 * @param {string}          command   The verb it sends.
 * @param {NodeClass}       viewClass Its view's class.
 * @param {() => ?string[]} argsFn    Its question, read at fire time.
 * @return {{fetcher: string, receiver: string, view: string, controlFrom: string, command: string, viewClass: NodeClass, argsFn: () => ?string[]}} The slice.
 */
const slice = ( subject, command, viewClass, argsFn ) => ( {
	fetcher: `${ subject }:fetch`,
	receiver: `${ subject }:in`,
	view: `${ subject }:view`,
	controlFrom: `${ subject }:view`,
	command,
	viewClass,
	argsFn,
} );

/** No filter chips: one array, so an unset chip list never re-asks. */
export const NO_CHIPS = [];

/** No expanded page: one array, so an unset list never re-syncs. */
const NO_PAGES = [];

/** The Tee the page's polled slices, an expanded page's among them, hang off. */
const PERFORMANCE_TEE = 'performance:tee';

/** The overview chart's slice: the site's series by its dimensions. */
const CHART = 'chart';
/** The overview category chart's slice. */
const CATEGORIES = 'categories';
/** View node for the on-demand URL detail modal. */
const URLDETAIL_VIEW = 'url-detail:view';
/** The URL modal's OWN Timer, armed only while that modal is visible. */
const URLDETAIL_TIMER = 'url-detail:timer';
/** The Tee the URL modal's Timer fans to its three Fetchers through. */
const URLDETAIL_TEE = 'url-detail:tee';
/** The URL modal chart's slice: the URL's series by its dimensions. */
const URL_CHART = 'url-chart';
/** The URL modal's category chart's slice. */
const URL_CATEGORIES = 'url-categories';
/** View node for the on-demand request detail modal. */
const REQUESTDETAIL_VIEW = 'request-detail:view';
/** Reply-address Tee for `dump_request`, and its Fetcher's FROM. */
const REQUESTDETAIL_RECV = 'request-detail:in';
/** Fetcher a selection asks `dump_request` through. */
const REQUESTDETAIL_FETCHER = 'request-detail:fetch';
/** The request modal's OWN Timer, armed only while that modal is visible. */
const REQUESTDETAIL_TIMER = 'request-detail:timer';
/**
 * Every Router tick: an ask parked before a session existed goes out on the
 * first tick after one does, and an unanswered one is asked again.
 */
const REQUESTDETAIL_TICK_MS = 1000;

/**
 * The URL table's controls until someone moves one: the busiest URLs
 * first, from the first page, unfiltered. The hook's default, and the
 * question the overview-prefetch job answers.
 */
const DEFAULT_URL_PARAMS = {
	search: '',
	sort: 'count',
	order: 'desc',
	offset: 0,
	includeWorkers: false,
	bucket: '',
	shapeOffsets: {},
};

/** The rows one `urls` ask for the table lists: `UrlTable`'s page size. */
const URLS_PER_ASK = 100;

/**
 * The URL modal's bucket selection while nothing narrows it: the whole
 * window. The hook's default.
 */
const NO_DETAIL_FILTERS = { bucket: '' };

/**
 * The URL modal chart's question until someone changes it: its status
 * classes, no second dimension and no chip. The hook's default, and what
 * the dashboard opens a URL with.
 */
export const NO_DETAIL_CHART = {
	breakdown: 'status_class',
	thenBy: '',
	chips: NO_CHIPS,
};

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
 * A request chart's question: its dimensions' series by request count.
 *
 * @param {Object}                  chart           The chart's state.
 * @param {string}                  chart.breakdown Its first dimension.
 * @param {string}                  chart.thenBy    Its second; '' for none.
 * @param {string[]}                chart.chips     Its chips.
 * @param {Object<string,string[]>} scope           The filters its scope asks.
 * @return {string[]} The command token array.
 */
const seriesArgs = ( chart, scope ) =>
	queryArgs(
		'requests',
		chartDims( chart ),
		filtersOf( scope, chart.chips ),
		SERIES_MEASURES,
		'count'
	);

/**
 * A category chart's question: each category's own total row by bucket —
 * the row whose `entry` is empty — the dearest first.
 *
 * @param {Object<string,string[]>} scope The filters its scope asks.
 * @return {string[]} The command token array.
 */
const categoryArgs = ( scope ) =>
	queryArgs(
		'timings',
		[ 'category' ],
		filtersOf( { ...scope, entry: [ '' ] }, NO_CHIPS ),
		CATEGORY_MEASURES,
		'ms'
	);

/**
 * The scope a site chart asks under: no worker request, and the server's
 * alone under a server filter.
 *
 * @param {string} serverFilter Server scope; '' means every server.
 * @return {Object<string,string[]>} Field => values.
 */
const siteScope = ( serverFilter ) => ( {
	worker: [ '0' ],
	...( serverFilter ? { server: [ serverFilter ] } : {} ),
} );

/**
 * The scope a URL chart asks under: the URL, worker traffic counted as
 * `dump_url` counts it, and only that server's traffic under a server filter.
 *
 * @param {string} hash         The URL hash.
 * @param {string} serverFilter Server scope; '' means every server.
 * @return {Object<string,string[]>} Field => values.
 */
const urlScope = ( hash, serverFilter ) => ( {
	url: [ hash ],
	...( serverFilter ? { server: [ serverFilter ] } : {} ),
} );

/**
 * `dump_url` args for the open modal: the hash, the server filter its row was
 * scoped by, and the modal's own narrowing. The open ask and every refresh
 * tick send the same question.
 *
 * @param {Object}   arg              Named arguments.
 * @param {string}   arg.hash         The URL hash.
 * @param {string}   arg.serverFilter Server scope; '' means every server.
 * @param {Object}   arg.filters      The modal's `{ bucket }`: the spelling of
 *                                    the bucket selection the header and list
 *                                    narrow to, '' for none.
 * @param {string[]} arg.chips        The modal chart's chips, each
 *                                    `field=value`, which narrow the header
 *                                    and list as they narrow its chart.
 * @return {string[]} The command token array.
 */
function urlDetailArgs( { hash, serverFilter, filters, chips } ) {
	const options = {};
	if ( serverFilter ) {
		options.server = serverFilter;
	}
	if ( filters.bucket ) {
		options.bucket = filters.bucket;
	}
	return [
		...formatCommandArgs( [ hash ], options ),
		...filterTokens( chips ),
	];
}

/**
 * Whether a URL hash may go out as a `dump_url` token. A selection arrives
 * from a deep link as readily as from a clicked row, so it is user input, and
 * one that fails here drives the modal's error control instead of a command.
 *
 * @param {*} h The candidate hash.
 * @return {boolean} Whether it is a hex string.
 */
const isValidHash = ( h ) => 'string' === typeof h && /^[a-f0-9]+$/.test( h );

/**
 * Whether a request id may go out as a `dump_request` token, on the same
 * reasoning as `isValidHash`.
 *
 * @param {*} r The candidate rid.
 * @return {boolean} Whether it is alphanumeric with `_` and `-`.
 */
const isValidRequestId = ( r ) =>
	'string' === typeof r && /^[a-zA-Z0-9_-]+$/.test( r );

/**
 * The `overview` args: the server the totals and the leaderboard scope to.
 *
 * @param {string} serverFilter Server scope; '' means every server.
 * @return {string[]} The command token array.
 */
const overviewArgs = ( serverFilter ) =>
	formatCommandArgs( [], serverFilter ? { server: serverFilter } : {} );

/**
 * Build the `urls` args from the table's own controls and the page's server
 * scope. One verb answers for the table and for the header totals summed from
 * it, so the scope reaches both or the two contradict each other.
 *
 * The table's `limit` is `URLS_PER_ASK` because `UrlTable` derives every
 * `offset` it sends from its own `URLS_PER_PAGE`; the two numbers are one page
 * size, split across two files. An expanded page's shapes ask
 * `SHAPES_PER_ASK`.
 *
 * `include_workers` widens a set the verb excludes by default, because a
 * long-running job would otherwise dominate every average on the page.
 * `bucket` narrows to a selection of five-minute buckets, by its spelling,
 * each row then carrying their summed numbers. The chart's chips narrow it
 * too, one `--filter=` each, so the table and its header describe the
 * traffic the chart draws; no chip sends no filter, which keeps the default
 * question the one the overview-prefetch job answers.
 *
 * @param {Object}   arg              Named arguments.
 * @param {Object}   arg.urlParams    The table's live search, sort, page and
 *                                    filter state.
 * @param {string}   arg.serverFilter Server scope; '' means every server.
 * @param {string[]} arg.chips        The Overview chart's chips, each
 *                                    `field=value`.
 * @param {number}   arg.limit        The most rows to list.
 * @return {string[]} The command token array.
 */
function urlsArgs( { urlParams, serverFilter, chips, limit } ) {
	const options = {};
	if ( urlParams.sort ) {
		options.sort = urlParams.sort;
	}
	if ( urlParams.order ) {
		options.order = urlParams.order;
	}
	options.limit = limit;
	if ( urlParams.offset ) {
		options.offset = urlParams.offset;
	}
	if ( urlParams.search ) {
		options.search = urlParams.search;
	}
	if ( serverFilter ) {
		options.server = serverFilter;
	}
	if ( urlParams.includeWorkers ) {
		options.include_workers = '1';
	}
	if ( urlParams.bucket ) {
		options.bucket = urlParams.bucket;
	}
	return [ ...formatCommandArgs( [], options ), ...filterTokens( chips ) ];
}

/**
 * Mount the graph the module overview describes, and hold it in step with the
 * page: a cadence change re-arms both Timers, and the two selections arm or
 * disarm the on-demand slices.
 *
 * Every option is live UI state. The Fetchers read the filter, sort and
 * chart refs at FIRE time, so a change rides the next tick without
 * re-wiring the graph.
 *
 * @param {Object}   [opts]                 Live dashboard state.
 * @param {string}   [opts.serverFilter]    Server scope; '' means every
 *                                          server.
 * @param {string}   [opts.chartBreakdown]  The chart's first dimension.
 * @param {string}   [opts.chartThenBy]     Its second dimension; '' for none.
 * @param {string[]} [opts.chartChips]      Its filter chips, each
 *                                          `field=value`, which narrow the
 *                                          URL table too. A change re-asks
 *                                          both, so hold one array per value.
 * @param {boolean}  [opts.askActive]       The `?` picker is armed, which
 *                                          holds the poll exactly as an open
 *                                          modal does: the reader is aiming
 *                                          at a row, and a repaint moves it.
 * @param {string}   [opts.refreshInterval] Poll cadence in ms, as a STRING —
 *                                          `SelectControl` compares its
 *                                          option values as strings. A value
 *                                          `parseInt` cannot read takes
 *                                          `DEFAULT_REFRESH_INTERVAL_MS`; the
 *                                          dropdown's floor of 1000 fires on
 *                                          every router tick, and anything
 *                                          below it throws in
 *                                          `useBatchedPoll`, where only a
 *                                          Timer riding the router sits
 *                                          inside the batch bracket.
 * @param {?Object}  [opts.selectedUrl]     `{ hash, url }` of the open URL
 *                                          detail modal; null closes and
 *                                          clears its slices.
 * @param {Object}   [opts.detailFilters]   The modal's `{ bucket }`: the
 *                                          bucket selection its header and
 *                                          list narrow to.
 * @param {Object}   [opts.detailChart]     The modal chart's `{ breakdown,
 *                                          thenBy, chips }`; its chips narrow
 *                                          the header and list as well. The
 *                                          modal re-asks a slice when a value
 *                                          its question reads changes, so a
 *                                          fresh object asks nothing.
 * @param {?string}  [opts.selectedRequest] Rid of the open request detail
 *                                          modal; null closes and clears it.
 * @param {Object[]} [opts.expandedPages]   The `urls` page rows whose shapes
 *                                          the table shows, each asking them
 *                                          through a slice of its own.
 * @return {{ handleUrlParamsChange: (params: Object) => void }} The URL table's params
 *         callback, and nothing else.
 */
export function usePerformanceGraph( opts = {} ) {
	const {
		serverFilter = '',
		chartBreakdown = 'status_class',
		chartThenBy = '',
		chartChips = NO_CHIPS,
		refreshInterval = String( DEFAULT_REFRESH_INTERVAL_MS ),
		askActive = false,
		selectedUrl = null,
		selectedRequest = null,
		detailFilters = NO_DETAIL_FILTERS,
		detailChart = NO_DETAIL_CHART,
		expandedPages = NO_PAGES,
	} = opts;

	// The whole opts object, for the modal getters' fire-time selection.
	const optsRef = useRef( opts );
	optsRef.current = opts;

	// Live UI state read at fire time by the getters and on-demand fetches.
	const urlParamsRef = useRef( DEFAULT_URL_PARAMS );
	const serverFilterRef = useRef( serverFilter );
	const chipsRef = useRef( chartChips );
	// @longform A new server or chip set is a new set, asked from its first
	// page by the pokes below. The table's own report of offset 0 then
	// matches and asks nothing, so the change puts one urls ask on the wire.
	if (
		serverFilterRef.current !== serverFilter ||
		chipsRef.current !== chartChips
	) {
		urlParamsRef.current = { ...urlParamsRef.current, offset: 0 };
	}
	serverFilterRef.current = serverFilter;
	chipsRef.current = chartChips;
	const detailFiltersRef = useRef( detailFilters );
	detailFiltersRef.current = detailFilters;
	const detailChartRef = useRef( detailChart );
	detailChartRef.current = detailChart;
	const chartRef = useRef( null );
	chartRef.current = {
		breakdown: chartBreakdown,
		thenBy: chartThenBy,
		chips: chartChips,
	};
	// Search-debounce handle; the build's cleanup clears it on teardown.
	const urlFetchTimerRef = useRef( null );
	const expandedRef = useRef( expandedPages );
	expandedRef.current = expandedPages;
	// Each expanded page's slice, by subject.
	const shapesRef = useRef( new Map() );
	// Re-renders the page, so the table reads a shapes view built since.
	const [ , bumpShapes ] = useState( 0 );

	const isPageVisible = usePageVisibility();
	// A Reset Graph rebuilds both detail Timers stopped; this re-arms them.
	const graphGeneration = useGraphGeneration();

	/** The overview question the page asks now. */
	const overviewNow = useCallback(
		() => overviewArgs( serverFilterRef.current ),
		[]
	);
	/** The URL-table question the page asks now. */
	const urlsNow = useCallback(
		() =>
			urlsArgs( {
				urlParams: urlParamsRef.current,
				serverFilter: serverFilterRef.current,
				chips: chipsRef.current,
				limit: URLS_PER_ASK,
			} ),
		[]
	);
	/** The chart question the page asks now. */
	const chartNow = useCallback(
		() =>
			seriesArgs(
				chartRef.current,
				siteScope( serverFilterRef.current )
			),
		[]
	);
	/** The category-chart question the page asks now. */
	const categoriesNow = useCallback(
		() => categoryArgs( siteScope( serverFilterRef.current ) ),
		[]
	);
	/**
	 * A question about the open URL, or null with none to ask about, which
	 * sends nothing at all.
	 *
	 * @param {(hash: string) => string[]} ask The question, given the hash.
	 * @return {() => ?string[]} The fire-time getter.
	 */
	const aboutUrl = useCallback(
		( ask ) => () => {
			const hash = optsRef.current.selectedUrl?.hash;
			return isValidHash( hash ) ? ask( hash ) : null;
		},
		[]
	);
	const urlDetailNow = useMemo(
		() =>
			aboutUrl( ( hash ) =>
				urlDetailArgs( {
					hash,
					serverFilter: serverFilterRef.current,
					filters: detailFiltersRef.current,
					chips: detailChartRef.current.chips,
				} )
			),
		[ aboutUrl ]
	);
	const urlChartNow = useMemo(
		() =>
			aboutUrl( ( hash ) =>
				seriesArgs(
					detailChartRef.current,
					urlScope( hash, serverFilterRef.current )
				)
			),
		[ aboutUrl ]
	);
	const urlCategoriesNow = useMemo(
		() =>
			aboutUrl( ( hash ) =>
				categoryArgs( urlScope( hash, serverFilterRef.current ) )
			),
		[ aboutUrl ]
	);

	// @longform The slices each tick source fans to, which the build, the
	// pokes and the clears all read, so a slice is named in one place.
	const pageSlices = useMemo(
		() => ( {
			overview: slice(
				'overview',
				'overview',
				views.OverviewView,
				overviewNow
			),
			urls: slice( 'urls', 'urls', views.UrlsView, urlsNow ),
			chart: slice( CHART, 'query', views.QueryView, chartNow ),
			categories: slice(
				CATEGORIES,
				'query',
				views.QueryView,
				categoriesNow
			),
		} ),
		[ overviewNow, urlsNow, chartNow, categoriesNow ]
	);
	const urlSlices = useMemo(
		() => ( {
			detail: slice(
				'url-detail',
				'dump_url',
				views.UrlDetailView,
				urlDetailNow
			),
			chart: slice( URL_CHART, 'query', views.QueryView, urlChartNow ),
			categories: slice(
				URL_CATEGORIES,
				'query',
				views.QueryView,
				urlCategoriesNow
			),
		} ),
		[ urlDetailNow, urlChartNow, urlCategoriesNow ]
	);

	/**
	 * Hang one expanded page's slice off the polled Tee: the table's question
	 * from the offset the table reports for the page's shapes in view, on the
	 * page's server and filtered to the page.
	 *
	 * @param {Object} interpreter The graph's interpreter.
	 * @param {Object} tee         The Tee the page's slices hang off.
	 * @param {Object} page        The page row.
	 * @return {Object} The slice.
	 */
	const addShapes = useCallback( ( interpreter, tee, page ) => {
		const one = slice( shapesSubject( page ), 'urls', views.UrlsView, () =>
			urlsArgs( {
				urlParams: {
					...urlParamsRef.current,
					offset:
						urlParamsRef.current.shapeOffsets[ pageKey( page ) ] ??
						0,
				},
				serverFilter: page.server,
				chips: [ ...chipsRef.current, `page=${ page.page_hash }` ],
				limit: SHAPES_PER_ASK,
			} )
		);
		addSliceFetcher( interpreter, {
			...one,
			tee,
			target: OVERVIEW_TARGET,
		} );
		Core.node( one.fetcher ).retry_after_s = STATS_NO_REASK;
		shapesRef.current.set( pageKey( page ), one );
		return one;
	}, [] );

	/**
	 * Remove one expanded page's slice through the interpreter's own
	 * `remove_node`: the four nodes `addSliceFetcher` names after it, the
	 * gate being `<receiver>:current`.
	 *
	 * @param {Object} interpreter The graph's interpreter.
	 * @param {string} key         The page's `pageKey()`.
	 */
	const dropShapes = useCallback( ( interpreter, key ) => {
		const { fetcher, receiver, view } = shapesRef.current.get( key );
		interpreter.dispatch( 'remove_node', [
			fetcher,
			receiver,
			`${ receiver }:current`,
			view,
		] );
		shapesRef.current.delete( key );
	}, [] );

	// Poll cadence (ms); an unparseable setting takes the declared default.
	const intervalMs =
		parseInt( refreshInterval, 10 ) || DEFAULT_REFRESH_INTERVAL_MS;

	// The graph: the page's four slices poll; the modal slices are on demand.
	const { interpreterRef, pollNow } = useBatchedPoll( {
		build: ( { interpreter, tee } ) => {
			for ( const each of Object.values( pageSlices ) ) {
				addSliceFetcher( interpreter, {
					...each,
					tee,
					target: OVERVIEW_TARGET,
				} );
			}

			// @longform The URL modal's three slices ride their OWN Timer
			// rather than the shared tick — the modal arms it by selection —
			// fanned through a Tee. A Timer is born armed at the Router's
			// cadence, so it starts stopped and the arm effect owns it.
			const urlDetailTimer = /** @type {TimerNode} */ (
				interpreter.makeNode( 'Timer', URLDETAIL_TIMER )
			);
			urlDetailTimer.stopTimer();
			keepUnsignedDue( urlDetailTimer );
			const urlTee = interpreter.makeNode( 'Tee', URLDETAIL_TEE );
			urlDetailTimer.connectNode( URLDETAIL_TEE );
			for ( const each of Object.values( urlSlices ) ) {
				addSliceFetcher( interpreter, {
					...each,
					tee: urlTee,
					target: egressPath( GROUPS.url, SERVER ),
				} );
			}
			for ( const each of [
				...Object.values( pageSlices ),
				...Object.values( urlSlices ),
			] ) {
				Core.node( each.fetcher ).retry_after_s = STATS_NO_REASK;
			}
			shapesRef.current = new Map();
			for ( const page of expandedRef.current ) {
				addShapes( interpreter, tee, page );
			}

			// @longform On-demand dump_request: a selection asks through
			// `askNow()`, and the slice's own Timer sends what that could not
			// and re-asks what went unanswered. A record never changes, so
			// the getter mints nothing: the Timer only drives the asks.
			const requestDetailTimer = /** @type {TimerNode} */ (
				interpreter.makeNode( 'Timer', REQUESTDETAIL_TIMER )
			);
			requestDetailTimer.stopTimer();
			keepUnsignedDue( requestDetailTimer );
			addSliceFetcher( interpreter, {
				fetcher: REQUESTDETAIL_FETCHER,
				receiver: REQUESTDETAIL_RECV,
				command: 'dump_request',
				view: REQUESTDETAIL_VIEW,
				viewClass: views.RequestDetailView,
				controlFrom: REQUESTDETAIL_VIEW,
				tee: requestDetailTimer,
				target: egressPath( GROUPS.request, SERVER ),
				argsFn: () => null,
			} );

			return () => {
				if ( urlFetchTimerRef.current ) {
					clearTimeout( urlFetchTimerRef.current );
				}
				for ( const key of [ ...shapesRef.current.keys() ] ) {
					dropShapes( interpreter, key );
				}
			};
		},
		timerName: 'performance:timer',
		teeName: PERFORMANCE_TEE,
		// @longform Suspend the offscreen overview poll while a detail modal
		// is open, and while the `?` picker is armed: a page repainting under
		// a stationary pointer swaps the row being aimed at.
		paused: !! ( selectedUrl || selectedRequest || askActive ),
		intervalMs,
	} );

	/**
	 * Fire a control straight into a view's `fill` through the shared
	 * minter, which stamps the origin that view trusts and throws for a view
	 * declaring none. A name no node answers to is a no-op, since every caller
	 * here names a node this hook built: the graph is torn down, and so is the
	 * slice.
	 *
	 * @param {string} viewName The view or transform node to control.
	 * @param {Object} value    The control, such as `{ action: 'loading' }`.
	 */
	const sendControl = useCallback( ( viewName, value ) => {
		const view = Core.node( viewName );
		if ( view ) {
			view.fill( controlMsg( view, value ) );
		}
	}, [] );

	/**
	 * Show slices as loading and ask each its question afresh, superseding
	 * the asks in flight so the gates drop their late answers; then fire the
	 * batched tick, which sends them in one POST. A held poll keeps them
	 * parked until its release pokes again.
	 */
	const pokePage = useCallback( () => {
		for ( const { view, fetcher, argsFn } of Object.values( pageSlices ) ) {
			sendControl( view, { action: 'loading' } );
			Core.node( fetcher )?.send( argsFn(), null, true );
		}
		pollNow();
	}, [ sendControl, pollNow, pageSlices ] );

	/**
	 * Ask one slice its question now, alone, superseding every older ask,
	 * and show it loading meanwhile.
	 *
	 * @param {{view: string, fetcher: string, argsFn: () => ?string[]}} one The slice.
	 */
	const ask = useCallback(
		( { view, fetcher, argsFn } ) => {
			sendControl( view, { action: 'loading' } );
			Core.node( fetcher )?.askNow( argsFn() );
		},
		[ sendControl ]
	);

	/** Ask every expanded page's shapes afresh. */
	const askShapes = useCallback(
		() => shapesRef.current.forEach( ( one ) => ask( one ) ),
		[ ask ]
	);

	// @longform A new server is a new page; a new chart question asks the
	// chart, and new chips the table they narrow too, its shapes included.
	useOnChange( pokePage, [ serverFilter ] );
	useOnChange(
		() => ask( pageSlices.chart ),
		[ chartBreakdown, chartThenBy, chartChips ]
	);
	useOnChange( () => {
		ask( pageSlices.urls );
		askShapes();
	}, [ chartChips ] );

	// A page expanded asks its shapes at once; a page shut drops its slice.
	const expandedKey = JSON.stringify( expandedPages.map( pageKey ) );
	useEffect( () => {
		const interpreter = interpreterRef.current;
		const tee = Core.node( PERFORMANCE_TEE );
		if ( ! interpreter || ! tee ) {
			return;
		}
		const wanted = new Map(
			expandedRef.current.map( ( page ) => [ pageKey( page ), page ] )
		);
		const shut = [ ...shapesRef.current.keys() ].filter(
			( key ) => ! wanted.has( key )
		);
		const opened = [ ...wanted ].filter(
			( [ key ] ) => ! shapesRef.current.has( key )
		);
		shut.forEach( ( key ) => dropShapes( interpreter, key ) );
		for ( const [ , page ] of opened ) {
			ask( addShapes( interpreter, tee, page ) );
		}
		if ( shut.length || opened.length ) {
			bumpShapes( ( n ) => n + 1 );
		}
	}, [ expandedKey, interpreterRef, ask, addShapes, dropShapes ] );

	// Every hold leaves the page stale, so each refreshes on release.
	const wasHeld = useRef( false );
	useEffect( () => {
		const held = !! ( selectedUrl || selectedRequest || askActive );
		if ( wasHeld.current && ! held && isPageVisible ) {
			pokePage();
		}
		wasHeld.current = held;
	}, [ selectedUrl, selectedRequest, askActive, isPageVisible, pokePage ] );

	// The question each modal slice was last asked, by the parts that move it.
	const detailAskedRef = useRef( null );

	// @longform All three on open and on a rescope; else each slice whose own
	// question moved: the chips move the list and the chart, the bucket the
	// list alone, the dimensions the chart alone.
	const { breakdown: detailBreakdown, thenBy: detailThenBy } = detailChart;
	const detailChips = detailChart.chips;
	const detailBucket = detailFilters.bucket;
	useEffect( () => {
		const clear = () =>
			Object.values( urlSlices ).forEach( ( { view } ) =>
				sendControl( view, { action: 'clear' } )
			);
		if ( ! selectedUrl ) {
			detailAskedRef.current = null;
			clear();
			return;
		}
		if ( ! isValidHash( selectedUrl.hash ) ) {
			sendControl( URLDETAIL_VIEW, {
				action: 'error',
				error: 'Invalid URL hash format',
			} );
			return;
		}
		const asked = {
			hash: selectedUrl.hash,
			server: serverFilter,
			bucket: detailBucket,
			chips: JSON.stringify( detailChips ),
			breakdown: detailBreakdown,
			thenBy: detailThenBy,
		};
		const prev = detailAskedRef.current;
		detailAskedRef.current = asked;
		const moved = ( ...parts ) =>
			parts.some( ( part ) => prev[ part ] !== asked[ part ] );
		if ( ! prev || moved( 'hash', 'server' ) ) {
			// Another URL's numbers must not stand under this one's title.
			clear();
			Object.values( urlSlices ).forEach( ask );
			return;
		}
		const chipsMoved = moved( 'chips' );
		if ( chipsMoved || moved( 'bucket' ) ) {
			// The whole-URL slices stay up; the list waits loading.
			ask( urlSlices.detail );
		}
		if ( chipsMoved || moved( 'breakdown', 'thenBy' ) ) {
			ask( urlSlices.chart );
		}
	}, [
		selectedUrl,
		serverFilter,
		detailBucket,
		detailChips,
		detailBreakdown,
		detailThenBy,
		sendControl,
		ask,
		urlSlices,
	] );

	// @longform Arm the URL modal's Timer only while URL detail is the visible
	// view. No cleanup stops it: a re-arm from stopped polls at once, so a
	// cadence change must re-arm the armed Timer; teardown removes the node.
	// A rebuild hands it a fresh, stopped Timer, hence the generation dep.
	useEffect( () => {
		const timer = Core.node( URLDETAIL_TIMER );
		if ( ! timer ) {
			return;
		}
		if (
			selectedUrl &&
			isValidHash( selectedUrl.hash ) &&
			! selectedRequest &&
			isPageVisible
		) {
			armTimer( timer, intervalMs );
		} else {
			timer.stopTimer();
		}
	}, [
		selectedUrl,
		selectedRequest,
		isPageVisible,
		intervalMs,
		graphGeneration,
	] );

	// Arm dump_request's Timer only while a request is the visible modal.
	useEffect( () => {
		const timer = Core.node( REQUESTDETAIL_TIMER );
		if ( ! timer ) {
			return;
		}
		if ( selectedRequest && isPageVisible ) {
			armTimer( timer, REQUESTDETAIL_TICK_MS );
		} else {
			timer.stopTimer();
		}
	}, [ selectedRequest, isPageVisible, graphGeneration ] );

	// Selection-driven dump_request.
	useEffect( () => {
		if ( ! selectedRequest ) {
			sendControl( REQUESTDETAIL_VIEW, { action: 'clear' } );
			return;
		}
		if ( ! isValidRequestId( selectedRequest ) ) {
			sendControl( REQUESTDETAIL_VIEW, {
				action: 'error',
				error: 'Invalid request ID format',
			} );
			return;
		}
		// The held record answers the old rid, not the one now loading.
		sendControl( REQUESTDETAIL_VIEW, { action: 'clear' } );
		sendControl( REQUESTDETAIL_VIEW, { action: 'loading' } );
		// Supersedes the ask about the request the operator left.
		Core.node( REQUESTDETAIL_FETCHER )?.askNow(
			formatCommandArgs( [ selectedRequest ] )
		);
	}, [ selectedRequest, sendControl ] );

	/**
	 * Fetch the page of the URL table these params describe. Params matching
	 * the ones in hand fetch nothing, a changed search waits out 300ms, and
	 * every other change fetches at once: a sort or a page turn is one
	 * deliberate click, while a search is a keystroke per character.
	 *
	 * @param {Object}                params                The table's live controls.
	 * @param {string}                params.search         Substring filter; '' matches all.
	 * @param {string}                params.sort           Sort field.
	 * @param {string}                params.order          Sort direction.
	 * @param {number}                params.offset         First row of the page.
	 * @param {boolean}               params.includeWorkers Include worker traffic.
	 * @param {string}                params.bucket         Bucket selection's spelling; '' for none.
	 * @param {Object<string,number>} [params.shapeOffsets] Each expanded
	 *                                                      page in view, by `pageKey()`, to the offset its shapes are asked
	 *                                                      from. A page it leaves out keeps the offset it last had.
	 */
	const handleUrlParamsChange = useCallback(
		( params ) => {
			const prev = urlParamsRef.current;
			// A page turn moves the table alone; the rest moves its shapes.
			const sameQuestion =
				prev.search === params.search &&
				prev.sort === params.sort &&
				prev.order === params.order &&
				!! prev.includeWorkers === !! params.includeWorkers &&
				prev.bucket === params.bucket;
			const shapeOffsets = {
				...prev.shapeOffsets,
				...params.shapeOffsets,
			};
			const scrolled = Object.keys( shapeOffsets ).filter(
				( key ) =>
					( prev.shapeOffsets[ key ] ?? 0 ) !== shapeOffsets[ key ]
			);
			const turned = prev.offset !== params.offset;
			if ( sameQuestion && ! turned && ! scrolled.length ) {
				return;
			}
			const searchChanged = prev.search !== params.search;
			urlParamsRef.current = { ...params, shapeOffsets };
			if ( urlFetchTimerRef.current ) {
				clearTimeout( urlFetchTimerRef.current );
			}
			// `urls:in:current` drops the answer to every ask this supersedes.
			const doFetch = () => {
				if ( ! sameQuestion || turned ) {
					ask( pageSlices.urls );
				}
				if ( ! sameQuestion ) {
					askShapes();
					return;
				}
				for ( const key of scrolled ) {
					const one = shapesRef.current.get( key );
					if ( one ) {
						ask( one );
					}
				}
			};
			if ( searchChanged ) {
				urlFetchTimerRef.current = setTimeout( doFetch, 300 );
			} else {
				doFetch();
			}
		},
		[ ask, askShapes, pageSlices ]
	);

	return { handleUrlParamsChange };
}
