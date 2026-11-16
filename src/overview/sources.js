/**
 * The Performance page's page state, its sources and the blocks its layout
 * is composed of, for both the page bundle and its layout editor, which each
 * import this module, so each bundle declares what it reads.
 *
 * Page state is what several blocks read and some write, each key under the
 * query param it always had: the filters `server`, `chips` and `buckets`,
 * which narrow the charts, the URL table and the totals; the chart's
 * `metric`, `breakdown` and `then`; the URL table's `sort`, `order`, `q`,
 * `workers` and `paged`; the window's subject, `url` or `page_hash`, and its
 * `request`, each a Back entry; the URL window's own narrowing and chart,
 * `url_buckets`, `url_chips`, `url_metric`, `url_breakdown` and `url_then`,
 * each under its `url_` param; and the cadence every poll asks at,
 * `refresh`, which a first visit reads from the choice the browser kept.
 * The list's order, `?url_sort=` and `?url_order=`, is the page's own,
 * which it keeps off the bar while the window is shut.
 *
 * Four polls answer the page, in the `overview` group, at that cadence:
 * `eln/overview`, the site's servers, leaderboard and owners; `eln/urls`,
 * one page of the URL table or one expanded page's shapes, with the set's
 * own totals, which the headline stats show from the table's one fetch
 * under a caption naming the stats window the server localized; `eln/chart`,
 * a chart's series by its dimensions; and `eln/categories`, the category
 * charts' totals. Three polls in the `url` group answer the URL window at the
 * same cadence: `eln/url-detail`, keyed by its subject and its own
 * narrowing, and its two charts, `eln/url-chart` and `eln/url-categories`;
 * each keeps nothing of another subject. A command answers the request
 * window, `eln/request-detail`, keyed by its request. Each window source
 * declares the page state it is read with, so any block on a window's layout
 * reads it as the window's own blocks do. A key naming nothing a command may
 * carry asks nothing. Every stats poll asks once however long
 * its answer takes, a chipped page answering in tens of seconds, and the
 * poll that retires the ask asks again.
 */

import { __, sprintf } from '@wordpress/i18n';
import { formatCommandArgs } from '@newspack-nodes/runtime';
import { definePageState } from '@newspack-nodes/shared/composition/page/pageState';
import { registerSource } from '@newspack-nodes/shared/sources';
import { readStorage } from '@newspack-nodes/shared/utils/storage';
import { formatGroupedCount } from '@newspack-nodes/shared/utils/formatters';
import { serverName } from '../log-table/urlIdentity';
import { chipRefusal, chipsOf } from './components/BreakdownControls';
import { linkedSubject } from './detailSubject';
import { thenUnder, windowSpan } from './chartSlots';
import { ADMITTED_VIEWS } from '../admittedBlocks';
import { composedPage } from '../composedPageData';
import {
	CHART_BREAKDOWN_OPTIONS,
	CHART_METRIC_OPTIONS,
	DASHBOARD_REFRESH_OPTIONS,
	DEFAULT_CHART_BREAKDOWN,
	DEFAULT_CHART_METRIC,
	FILTER_FIELD_OPTIONS,
	GROUPS,
	SCOPED_BREAKDOWN_OPTIONS,
	SORT_ORDERS,
	SUBJECT_CHIP_OPTIONS,
	wholeMs,
} from './constants';
import {
	categoryArgs,
	chartArgs,
	detailArgs,
	isValidRequestId,
	overviewArgs,
	urlsArgs,
} from './questions';
import breakdownBar from './blocks/breakdown-bar';
import aggregateChart from './blocks/aggregate-chart';
import categoryChart from './blocks/category-chart';
import profile from './blocks/profile';
import urlTable from './blocks/url-table';
import { URL_WINDOW_VIEWS } from './blocks/url-window';
import { REQUEST_WINDOW_VIEWS } from './blocks/request-window';
import { WINDOW_PARAMS, windowSubject } from './blocks/windowQuestions';
import { getQueryParam } from '@newspack-nodes/shared/utils/queryParams';
import { SORT_FIELDS } from './UrlTable';

/** The server CI every command this page sends goes to. */
export const SERVER = 'performance';

/** The ruleset CI the window's "Log this URL" editor reads and writes. */
export const RULES_CI = 'rules';

/** The localStorage key the chosen cadence outlives the page under. */
export const REFRESH_KEY = 'event-logger-refresh-interval';

/** The cadence a first visit polls at. */
const DEFAULT_REFRESH_MS = '15000';

/** No filter chips: one array, so an unset chip list never re-asks. */
export const NO_CHIPS = [];

/** The URL table's question until someone moves a control. */
const URL_PARAMS = [ 'sort', 'order', 'q', 'workers', 'paged' ];

/** The filters that narrow the charts, the URL table and the totals. */
const FILTERS = [ 'server', 'chips', 'buckets' ];

/**
 * A value one of `values`, else `fallback`.
 *
 * @param {string[]} values   What the param may hold.
 * @param {string}   fallback What it holds otherwise.
 * @return {(raw: *) => string} The parse.
 */
const oneOf = ( values, fallback ) => ( raw ) =>
	values.includes( raw ) ? raw : fallback;

/** Every dimension the chart offers. */
const BREAKDOWNS = CHART_BREAKDOWN_OPTIONS.map( ( option ) => option.value );

/** Every dimension the URL window's chart offers: a URL names its host. */
const WINDOW_BREAKDOWNS = SCOPED_BREAKDOWN_OPTIONS.map(
	( option ) => option.value
);

/** The URL window chart's first dimension until someone changes it. */
export const WINDOW_BREAKDOWN = 'status_class';

/**
 * A chart's Breakdown and Then by parses over the dimensions it offers: the
 * Breakdown one of them, else `fallback`; the Then by one of them, else
 * none, and none where it repeats the Breakdown the bar names under
 * `breakdownKey`, so a link holding both reads it as none before any chart
 * asks.
 *
 * @param {string[]} values       The dimensions it offers.
 * @param {string}   fallback     Its Breakdown otherwise.
 * @param {string}   breakdownKey The param its Breakdown is linked under.
 * @return {{breakdown: (raw: *) => string, then: (raw: *) => string}} The parses.
 */
const chartParses = ( values, fallback, breakdownKey ) => {
	const breakdown = oneOf( values, fallback );
	const then = oneOf( values, '' );
	return {
		breakdown,
		then: ( raw ) =>
			thenUnder(
				breakdown( getQueryParam( breakdownKey ) ),
				then( raw )
			),
	};
};

/** The page chart's Breakdown and Then by parses. */
const PAGE_CHART = chartParses(
	BREAKDOWNS,
	DEFAULT_CHART_BREAKDOWN,
	'breakdown'
);

/** The URL window chart's Breakdown and Then by parses. */
const WINDOW_CHART = chartParses(
	WINDOW_BREAKDOWNS,
	WINDOW_BREAKDOWN,
	'url_breakdown'
);

/**
 * Chips kept where `chipRefusal()` takes them under `options`, each once.
 *
 * @param {string[]}                              raw     Every chip, each `field=value`.
 * @param {Array<{label: string, value: string}>} options The fields a chip may name.
 * @return {string[]} The chips kept, or `NO_CHIPS` for none.
 */
const keptChips = ( raw, options ) => {
	const kept = chipsOf(
		raw.filter( ( chip ) => null === chipRefusal( chip, options ) )
	);
	return kept.length ? kept : NO_CHIPS;
};

definePageState( {
	server: {
		default: '',
		query: 'server',
		parse: serverName,
	},
	chips: {
		default: NO_CHIPS,
		query: 'chip',
		parse: ( raw ) =>
			keptChips( /** @type {string[]} */ ( raw ), FILTER_FIELD_OPTIONS ),
	},
	buckets: { default: '', query: 'bucket' },
	metric: {
		default: DEFAULT_CHART_METRIC,
		query: 'metric',
		parse: oneOf(
			CHART_METRIC_OPTIONS.map( ( option ) => option.value ),
			DEFAULT_CHART_METRIC
		),
	},
	breakdown: {
		default: DEFAULT_CHART_BREAKDOWN,
		query: 'breakdown',
		parse: PAGE_CHART.breakdown,
	},
	then: { default: '', query: 'then', parse: PAGE_CHART.then },
	sort: {
		default: 'count',
		query: 'sort',
		parse: oneOf( SORT_FIELDS, 'count' ),
	},
	order: {
		default: 'desc',
		query: 'order',
		parse: oneOf( SORT_ORDERS, 'desc' ),
	},
	q: { default: '', query: 'q' },
	workers: {
		default: false,
		query: 'workers',
		parse: ( raw ) => '1' === raw,
		format: () => '1',
	},
	paged: {
		default: 1,
		query: 'paged',
		// Safe, or `1e21` reaches the wire as an exponent the command refuses.
		parse: ( raw ) => {
			const n = Number( raw );
			return Number.isSafeInteger( n ) && n > 0 ? n : 1;
		},
	},
	url: { default: '', query: 'url', push: true },
	page_hash: { default: '', query: 'page_hash', push: true },
	request: {
		default: '',
		query: 'request',
		push: true,
		// Echoed into the bar and sent to the server, so only the id alphabet.
		parse: ( raw ) => ( isValidRequestId( raw ) ? raw : '' ),
	},
	url_buckets: { default: '', query: 'url_bucket' },
	// A linked window's chips are judged by the field its link keys on.
	url_chips: {
		default: NO_CHIPS,
		query: 'url_chip',
		parse: ( raw ) =>
			keptChips(
				/** @type {string[]} */ ( raw ),
				SUBJECT_CHIP_OPTIONS[ linkedSubject()?.field ?? 'url' ]
			),
	},
	url_metric: {
		default: DEFAULT_CHART_METRIC,
		query: 'url_metric',
		parse: oneOf(
			CHART_METRIC_OPTIONS.map( ( option ) => option.value ),
			DEFAULT_CHART_METRIC
		),
	},
	url_breakdown: {
		default: WINDOW_BREAKDOWN,
		query: 'url_breakdown',
		parse: WINDOW_CHART.breakdown,
	},
	url_then: { default: '', query: 'url_then', parse: WINDOW_CHART.then },
	refresh: {
		default:
			DASHBOARD_REFRESH_OPTIONS.find(
				( option ) => option.value === readStorage( REFRESH_KEY )
			)?.value ?? DEFAULT_REFRESH_MS,
	},
} );

/**
 * A figure: a number as `format` shows it, and one that has not arrived as
 * absent, because a plausible zero beside a real total reads as a
 * measurement.
 *
 * @param {string}                label  The tile's caption.
 * @param {(n: number) => string} format How a number shows.
 * @return {Object} The field.
 */
const figure = ( label, format ) => ( {
	label,
	kind: 'value',
	format: ( n ) => ( 'number' === typeof n ? format( n ) : '—' ),
} );

/**
 * A rate in requests a second.
 *
 * @param {number} n The rate.
 * @return {string} It, to two places.
 */
const perSecond = ( n ) => n.toFixed( 2 );

/**
 * A peak in megabytes.
 *
 * @param {number} n The peak.
 * @return {string} It, to one place, with its unit.
 */
const megabytes = ( n ) => `${ n.toFixed( 1 ) }MB`;

/**
 * Peak memory shows only where the install samples it.
 *
 * @param {number} n The peak.
 * @return {boolean} Whether it was sampled.
 */
const sampled = ( n ) => n > 0;

/**
 * Each answer whole, an envelope a block reads its answer from: an answer
 * that is null is still an answer, which a block shows as empty rather than
 * as loading.
 *
 * @param {*} answer The command's answer.
 * @return {{data: *}} The model.
 */
const answered = ( answer ) => ( { data: answer ?? null } );

/**
 * The subject a window's source is read for, so its model never stands in
 * for another subject's.
 *
 * @param {Object} params The params.
 * @return {string} `<field>:<hash>`, or '' for none a command may carry.
 */
const subjectScope = ( params ) => {
	const subject = windowSubject( params );
	return subject?.valid ? `${ subject.field }:${ subject.hash }` : '';
};

/**
 * A window source's question, asking nothing without a subject.
 *
 * @param {(question: Object) => string[]} ask      The question's args.
 * @param {(params: Object) => Object}     question The question, from the params and the subject.
 * @return {(params: Object) => ?string[]} The source's args.
 */
const ofSubject = ( ask, question ) => ( params ) => {
	const subject = windowSubject( params );
	return subject?.valid ? ask( { subject, ...question( params ) } ) : null;
};

/** What every poll on the page declares beside its question. */
const POLL = {
	kind: 'poll',
	ci: SERVER,
	intervalMs: Number( DEFAULT_REFRESH_MS ),
	cadence: 'refresh',
	retry: false,
};

registerSource( 'eln/overview', {
	...POLL,
	label: __( 'Performance overview', 'newspack-event-logger-nodes' ),
	group: GROUPS.overview,
	command: 'overview',
	args: overviewArgs,
	transform: answered,
	params: [ 'server' ],
} );

/**
 * A headline figure: one of the `urls` totals, under its long label.
 *
 * @param {string}                 key     The total.
 * @param {string}                 label   Its label.
 * @param {(n: number) => string}  format  How it shows.
 * @param {(n: number) => boolean} [shown] Whether it shows at all.
 * @return {Object} The field.
 */
const total = ( key, label, format, shown ) => ( {
	...figure( label, format ),
	value: ( { totals } ) => totals?.[ key ],
	...( shown && { shown } ),
} );

registerSource( 'eln/urls', {
	...POLL,
	label: __( 'Pages', 'newspack-event-logger-nodes' ),
	group: GROUPS.overview,
	command: 'urls',
	args: urlsArgs,
	// @longform The envelope less `limit`, which this page's own args set:
	// `rows` counts what the filters left, 0 for an answer counting none,
	// and `totals` is the same set's figures, null rather than zero for a
	// malformed envelope, since a zero there reads as a measurement.
	transform: ( answer ) => ( {
		data: answer?.data ?? [],
		totals: answer?.totals ?? null,
		rows: answer?.rows ?? 0,
		offset: answer?.offset ?? 0,
		slowest: answer?.slowest ?? [],
		filters: answer?.filters ?? null,
	} ),
	params: [ ...FILTERS, ...URL_PARAMS ],
	caption: () => {
		const { window: buckets } = composedPage();
		if ( 'number' !== typeof buckets ) {
			return buckets?.error ?? null;
		}
		return sprintf(
			// translators: %s: the span the window covers, e.g. 2 hours.
			__(
				'The last %s in five-minute buckets, the current one so far.',
				'newspack-event-logger-nodes'
			),
			windowSpan( buckets )
		);
	},
	fields: {
		urls: total(
			'urls',
			__( 'Unique URLs', 'newspack-event-logger-nodes' ),
			formatGroupedCount
		),
		requests: total(
			'requests',
			__( 'Total Requests', 'newspack-event-logger-nodes' ),
			formatGroupedCount
		),
		errors: total(
			'errors',
			__( 'Total Errors', 'newspack-event-logger-nodes' ),
			formatGroupedCount
		),
		avg_ms: total(
			'avg_ms',
			__( 'Avg Response', 'newspack-event-logger-nodes' ),
			wholeMs
		),
		requests_per_second: total(
			'requests_per_second',
			__( 'Req/s', 'newspack-event-logger-nodes' ),
			perSecond
		),
		avg_peak_mb: total(
			'avg_peak_mb',
			__( 'Avg Peak Memory', 'newspack-event-logger-nodes' ),
			megabytes,
			sampled
		),
	},
} );

registerSource( 'eln/chart', {
	...POLL,
	label: __( 'Request chart', 'newspack-event-logger-nodes' ),
	group: GROUPS.overview,
	command: 'query',
	args: chartArgs,
	transform: answered,
} );

registerSource( 'eln/categories', {
	...POLL,
	label: __( 'Category chart', 'newspack-event-logger-nodes' ),
	group: GROUPS.overview,
	command: 'query',
	args: categoryArgs,
	transform: answered,
	params: [ 'server' ],
} );

/**
 * A URL window's header figure: one of `dump_url`'s stats, under the short
 * label the window's header reads.
 *
 * @param {string}                 key     The stat.
 * @param {string}                 label   Its short label.
 * @param {(n: number) => string}  format  How it shows.
 * @param {(n: number) => boolean} [shown] Whether it shows at all.
 * @return {Object} The field.
 */
const windowFigure = ( key, label, format, shown ) => ( {
	...figure( label, format ),
	value: ( { data } ) => data?.stats?.[ key ],
	...( shown && { shown } ),
} );

registerSource( 'eln/url-detail', {
	...POLL,
	label: __( 'URL detail', 'newspack-event-logger-nodes' ),
	group: GROUPS.url,
	command: 'dump_url',
	args: ofSubject( detailArgs, ( params ) => ( {
		server: params.server,
		buckets: params.url_buckets,
		chips: params.url_chips,
	} ) ),
	transform: answered,
	scope: subjectScope,
	params: [ ...WINDOW_PARAMS, 'url_buckets', 'url_chips' ],
	fields: {
		requests_per_second: windowFigure(
			'requests_per_second',
			__( 'req/s', 'newspack-event-logger-nodes' ),
			perSecond
		),
		errors: windowFigure(
			'errors',
			__( 'errors', 'newspack-event-logger-nodes' ),
			formatGroupedCount
		),
		error_rate: windowFigure(
			'error_rate',
			__( 'err', 'newspack-event-logger-nodes' ),
			( n ) => `${ ( n * 100 ).toFixed( 2 ) }%`
		),
		avg_ms: windowFigure(
			'avg_ms',
			__( 'avg', 'newspack-event-logger-nodes' ),
			wholeMs
		),
		p50_ms: windowFigure(
			'p50_ms',
			__( 'p50', 'newspack-event-logger-nodes' ),
			wholeMs
		),
		p95_ms: windowFigure(
			'p95_ms',
			__( 'p95', 'newspack-event-logger-nodes' ),
			wholeMs
		),
		p99_ms: windowFigure(
			'p99_ms',
			__( 'p99', 'newspack-event-logger-nodes' ),
			wholeMs
		),
		avg_peak_mb: windowFigure(
			'avg_peak_mb',
			__( 'mem', 'newspack-event-logger-nodes' ),
			megabytes,
			sampled
		),
	},
} );

registerSource( 'eln/url-chart', {
	...POLL,
	label: __( 'URL request chart', 'newspack-event-logger-nodes' ),
	group: GROUPS.url,
	command: 'query',
	args: ofSubject( chartArgs, ( params ) => ( {
		server: params.server,
		chips: params.url_chips,
		breakdown: params.url_breakdown,
		then: params.url_then,
	} ) ),
	transform: answered,
	scope: subjectScope,
	params: [ ...WINDOW_PARAMS, 'url_chips', 'url_breakdown', 'url_then' ],
} );

registerSource( 'eln/url-categories', {
	...POLL,
	label: __( 'URL category chart', 'newspack-event-logger-nodes' ),
	group: GROUPS.url,
	command: 'query',
	args: ofSubject( categoryArgs, ( params ) => ( {
		server: params.server,
	} ) ),
	transform: answered,
	scope: subjectScope,
	params: WINDOW_PARAMS,
} );

registerSource( 'eln/request-detail', {
	label: __( 'Request detail', 'newspack-event-logger-nodes' ),
	kind: 'command',
	group: GROUPS.request,
	ci: SERVER,
	command: 'dump_request',
	args: ( { request } ) =>
		isValidRequestId( request ) ? formatCommandArgs( [ request ] ) : null,
	// Each request's record stands for its own request alone.
	scope: ( { request = '' } ) => request,
	params: [ 'request' ],
} );

/**
 * Every block the Performance page and its windows may hold, by block name.
 *
 * @type {Object<string,import('@newspack-nodes/shared/composition/CompositionView').CompositionBlockView>}
 */
export const BLOCK_VIEWS = {
	...ADMITTED_VIEWS,
	'newspack-event-logger-nodes/breakdown-bar': breakdownBar,
	'newspack-event-logger-nodes/aggregate-chart': aggregateChart,
	'newspack-event-logger-nodes/category-chart': categoryChart,
	'newspack-event-logger-nodes/profile': profile,
	'newspack-event-logger-nodes/url-table': urlTable,
	...URL_WINDOW_VIEWS,
	...REQUEST_WINDOW_VIEWS,
};
